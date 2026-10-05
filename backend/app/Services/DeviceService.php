<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeviceService
{
    public function __construct(private readonly NotificationService $notifier) {}

    /**
     * 前台发放设备。核对三件事：实物编号、电量、字幕语言。
     * 设备必须 available，预约必须是待领取且语言匹配。全程事务 + 行锁 + 部分唯一索引三重防重。
     */
    public function issue(int $reservationId, string $serial, int $battery, string $language, User $staff): DeviceAssignment
    {
        return DB::transaction(function () use ($reservationId, $serial, $battery, $language, $staff) {
            $reservation = Reservation::with(['performance'])
                ->where('id', $reservationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->status !== Reservation::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['reservation_id' => '该预约不是待领取状态，无法发放']);
            }

            if (strcasecmp($language, $reservation->language) !== 0) {
                throw ValidationException::withMessages([
                    'language' => "字幕语言不匹配：预约为 {$reservation->language}，提交为 {$language}",
                ]);
            }

            $device = Device::where('serial', $serial)->lockForUpdate()->first();
            if (! $device) {
                throw ValidationException::withMessages(['serial' => '实物编号不存在，请核对设备']);
            }

            // 未归还(assigned)或故障维修(maintenance)的设备不能分配
            if (! $device->isAllocatable()) {
                throw ValidationException::withMessages([
                    'serial' => "设备 {$serial} 当前状态为 {$device->status}，不可分配（未归还或故障中）",
                ]);
            }

            if ($device->battery_level < DeviceCheckPolicy::MIN_ISSUE_BATTERY) {
                throw ValidationException::withMessages([
                    'battery' => "电量 {$device->battery_level}% 低于发放下限（".DeviceCheckPolicy::MIN_ISSUE_BATTERY.'%）',
                ]);
            }
            if ($battery < DeviceCheckPolicy::MIN_ISSUE_BATTERY) {
                throw ValidationException::withMessages([
                    'battery' => "实测电量 {$battery}% 低于发放下限（".DeviceCheckPolicy::MIN_ISSUE_BATTERY.'%）',
                ]);
            }

            $assignment = null;
            try {
                $assignment = DeviceAssignment::create([
                    'device_id' => $device->id,
                    'reservation_id' => $reservation->id,
                    'performance_id' => $reservation->performance_id,
                    'language' => $reservation->language,
                    'status' => DeviceAssignment::STATUS_ISSUED,
                    'battery_at_issue' => $battery,
                    'language_at_issue' => $language,
                    'serial_confirmed' => $device->serial,
                    'issued_by' => $staff->id,
                    'issued_at' => now(),
                ]);
            } catch (QueryException) {
                // device_assignments_open_unique 兜底：该设备已有未归还发放
                throw ValidationException::withMessages(['serial' => '该设备已被分配且未归还，不能重复发放']);
            }

            $device->update(['status' => Device::STATUS_ASSIGNED, 'battery_level' => $battery]);
            $reservation->update(['status' => Reservation::STATUS_ISSUED]);

            return $assignment->load('device');
        });
    }

    /**
     * 散场收回并复检。
     * check_result=ok：设备回到 available，可分配下一场；
     * check_result=fault：设备进入 maintenance，绝不分配下一场，直到技术员复检放行。
     */
    public function returnDevice(int $assignmentId, int $battery, string $checkResult, ?string $faultNotes, User $staff): DeviceAssignment
    {
        if (! in_array($checkResult, ['ok', 'fault'], true)) {
            throw ValidationException::withMessages(['check_result' => '复检结果必须是 ok 或 fault']);
        }

        return DB::transaction(function () use ($assignmentId, $battery, $checkResult, $faultNotes, $staff) {
            $assignment = DeviceAssignment::with(['device', 'reservation'])
                ->where('id', $assignmentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($assignment->status !== DeviceAssignment::STATUS_ISSUED) {
                throw ValidationException::withMessages(['assignment_id' => '该设备不在已发放状态，无法收回']);
            }

            $assignment->update([
                'status' => $checkResult === 'ok' ? DeviceAssignment::STATUS_RETURNED : DeviceAssignment::STATUS_FAULTY,
                'battery_at_return' => $battery,
                'check_result' => $checkResult,
                'fault_notes' => $checkResult === 'fault' ? $faultNotes : null,
                'returned_by' => $staff->id,
                'returned_at' => now(),
            ]);

            if ($checkResult === 'ok') {
                $assignment->device->markHealthy($battery);
            } else {
                $assignment->device->markFaulty($faultNotes, $battery);
                if ($assignment->reservation->contact_phone) {
                    $this->notifier->sms(
                        $assignment->reservation->contact_phone,
                        "您归还的字幕设备（{$assignment->device->serial}）复检发现故障，已记录，无需赔偿（非人为损坏）。",
                        Device::class,
                        $assignment->device_id,
                    );
                }
            }

            $assignment->reservation->update(['status' => Reservation::STATUS_RETURNED]);

            return $assignment->load('device');
        });
    }

    /**
     * 演出结束后仍未归还的设备：标记 missing + maintenance，预约标记 noshow。
     * 设备在找回前不会进入下一场分配池。
     */
    public function markMissing(int $assignmentId, User $staff): DeviceAssignment
    {
        return DB::transaction(function () use ($assignmentId, $staff) {
            $assignment = DeviceAssignment::with(['device', 'reservation'])
                ->where('id', $assignmentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($assignment->status !== DeviceAssignment::STATUS_ISSUED) {
                throw ValidationException::withMessages(['assignment_id' => '仅已发放未归还的记录可标记为未归还']);
            }

            $assignment->update([
                'status' => DeviceAssignment::STATUS_MISSING,
                'check_result' => 'missing',
                'fault_notes' => '演出结束未归还',
                'returned_by' => $staff->id,
                'returned_at' => now(),
            ]);
            $assignment->device->update([
                'status' => Device::STATUS_MAINTENANCE,
                'last_check_result' => 'missing',
                'fault_notes' => '未归还，找回前停用',
                'checked_at' => now(),
            ]);
            $assignment->reservation->update(['status' => Reservation::STATUS_NOSHOW]);

            if ($assignment->reservation->contact_phone) {
                $this->notifier->sms(
                    $assignment->reservation->contact_phone,
                    "您预约的字幕设备（{$assignment->device->serial}）尚未归还，请尽快联系剧场前台。",
                    Device::class,
                    $assignment->device_id,
                );
            }

            return $assignment->load('device');
        });
    }

    /** 技术员对维修/找回设备复检放行，回到可分配池 */
    public function releaseFromMaintenance(int $deviceId, int $battery, User $technician): Device
    {
        return DB::transaction(function () use ($deviceId, $battery, $technician) {
            $device = Device::where('id', $deviceId)->lockForUpdate()->firstOrFail();
            if ($device->status !== Device::STATUS_MAINTENANCE) {
                throw ValidationException::withMessages(['device_id' => '仅维修中的设备可以复检放行']);
            }
            $device->markHealthy($battery);

            return $device;
        });
    }
}
