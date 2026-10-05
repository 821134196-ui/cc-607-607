<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCheck;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeviceException extends \RuntimeException
{
}

class DeviceService
{
    /**
     * 发放设备（前台）。
     * 必须核对：实物编号对应设备可用、电量达标、设备支持预约语言。
     * 数据库层用“每设备至多一条未归还发放记录”的部分唯一索引兜底，杜绝重复分配。
     *
     * @param array{
     *   battery_percent:int, physical_ok:bool, language_confirmed:bool,
     *   note?:?string, min_battery?:int
     * } $check
     */
    public function issue(Reservation $reservation, Device $device, User $frontdesk, array $check): DeviceAssignment
    {
        return DB::transaction(function () use ($reservation, $device, $frontdesk, $check) {
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);
            $device = Device::lockForUpdate()->findOrFail($device->id);

            if ($reservation->status !== Reservation::STATUS_BOOKED) {
                throw new DeviceException('该预约不是“已预约”状态，不能发放设备');
            }
            if ($reservation->activeAssignment()->exists()) {
                throw new DeviceException('该预约已有未归还设备，不能重复发放');
            }
            // 模拟票务：入场前再次确认票未被其他预约核销（一票一设备）
            if ($reservation->ticket && $reservation->ticket->status !== \App\Models\Ticket::STATUS_VALID) {
                throw new DeviceException("票 {$reservation->ticket->code} 已不可用（{$reservation->ticket->status}），不能发放设备");
            }

            // —— 前台核对实物编号 / 设备状态 ——
            if ($device->status === Device::STATUS_FAULTY) {
                throw new DeviceException("设备 {$device->serial} 存在故障未排除，不能发放");
            }
            if ($device->status === Device::STATUS_ASSIGNED) {
                throw new DeviceException("设备 {$device->serial} 尚未归还，不能重复分配");
            }
            if (DeviceAssignment::where('device_id', $device->id)->whereNull('returned_at')->exists()) {
                // 状态与流水不一致时以未归还流水为准（双重保险）
                throw new DeviceException("设备 {$device->serial} 存在未归还记录，不能分配给下一场");
            }

            // —— 字幕语言核对 ——
            if (!$device->supportsLanguage($reservation->language)) {
                throw new DeviceException("设备 {$device->serial} 不支持 {$reservation->language} 字幕语言");
            }

            $minBattery = $check['min_battery'] ?? 20;
            if ($check['battery_percent'] < $minBattery) {
                throw new DeviceException("电量 {$check['battery_percent']}% 低于 {$minBattery}%，请更换设备");
            }
            if (empty($check['physical_ok'])) {
                throw new DeviceException('实物核对未通过（编号/外观异常），不能发放');
            }
            if (empty($check['language_confirmed'])) {
                throw new DeviceException('字幕语言尚未确认一致，不能发放');
            }

            $assignment = DeviceAssignment::create([
                'reservation_id' => $reservation->id,
                'device_id' => $device->id,
                'issued_by' => $frontdesk->id,
                'issued_at' => now(),
            ]);

            DeviceCheck::create([
                'device_assignment_id' => $assignment->id,
                'device_id' => $device->id,
                'inspector_id' => $frontdesk->id,
                'phase' => DeviceCheck::PHASE_ISSUE,
                'battery_percent' => $check['battery_percent'],
                'physical_ok' => (bool) $check['physical_ok'],
                'language_confirmed' => (bool) $check['language_confirmed'],
                'result' => DeviceCheck::RESULT_PASS,
                'note' => $check['note'] ?? null,
                'checked_at' => now(),
            ]);

            $device->update([
                'status' => Device::STATUS_ASSIGNED,
                'battery_percent' => $check['battery_percent'],
            ]);
            $reservation->update(['status' => Reservation::STATUS_CHECKED_IN]);

            // 模拟票务：领取设备即视为入场，票号置为已使用（不可重复使用）
            if ($reservation->ticket) {
                app(TicketService::class)->markUsed($reservation->ticket);
            }

            return $assignment->load('device');
        }, 3);
    }

    /**
     * 散场收回并复检。
     *
     * @param array{
     *   battery_percent:int, physical_ok:bool, language_confirmed:bool,
     *   faulty?:bool, note?:?string
     * } $check
     */
    public function returnDevice(DeviceAssignment $assignment, User $frontdesk, array $check): DeviceAssignment
    {
        return DB::transaction(function () use ($assignment, $frontdesk, $check) {
            $assignment = DeviceAssignment::with('device', 'reservation')
                ->lockForUpdate()->findOrFail($assignment->id);

            if (!$assignment->isActive()) {
                throw new DeviceException('该设备已完成归还，不能重复归还');
            }

            $device = Device::lockForUpdate()->find($assignment->device_id);
            $faulty = !empty($check['faulty']) || empty($check['physical_ok']);
            $result = $faulty ? DeviceCheck::RESULT_FAIL : DeviceCheck::RESULT_PASS;

            DeviceCheck::create([
                'device_assignment_id' => $assignment->id,
                'device_id' => $device->id,
                'inspector_id' => $frontdesk->id,
                'phase' => DeviceCheck::PHASE_RETURN,
                'battery_percent' => $check['battery_percent'],
                'physical_ok' => (bool) ($check['physical_ok'] ?? false),
                'language_confirmed' => (bool) ($check['language_confirmed'] ?? false),
                'result' => $result,
                'note' => $check['note'] ?? null,
                'checked_at' => now(),
            ]);

            $assignment->update([
                'returned_by' => $frontdesk->id,
                'returned_at' => now(),
            ]);

            // 复检结果决定设备能否进入下一场
            $device->update([
                'status' => $faulty ? Device::STATUS_FAULTY : Device::STATUS_AVAILABLE,
                'battery_percent' => $check['battery_percent'],
                'fault_note' => $faulty ? ($check['note'] ?? '散场复检发现故障') : null,
            ]);

            $reservation = $assignment->reservation;
            if ($reservation->status === Reservation::STATUS_CHECKED_IN) {
                $reservation->update(['status' => Reservation::STATUS_COMPLETED]);
            }

            return $assignment->load('device', 'checks');
        }, 3);
    }

    /** 技术员/管理员维修后解除故障状态 */
    public function repair(Device $device, string $note = '维修完成，复检通过'): Device
    {
        if ($device->status !== Device::STATUS_FAULTY) {
            throw new DeviceException('只有故障设备需要维修登记');
        }
        $device->update([
            'status' => Device::STATUS_AVAILABLE,
            'fault_note' => null,
            'battery_percent' => 100,
        ]);
        return $device;
    }
}
