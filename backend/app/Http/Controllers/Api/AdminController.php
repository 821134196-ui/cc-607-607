<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCheck;
use App\Models\NotificationLog;
use App\Models\Screening;
use App\Models\User;
use App\Services\DeviceException;
use App\Services\DeviceService;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct(private readonly DeviceService $deviceService)
    {
    }

    /** 员工名单（前台/技术员/负责人），供登录身份选择 */
    public function staff()
    {
        return response()->json(['data' => User::whereIn('role', [
            User::ROLE_FRONTDESK, User::ROLE_TECHNICIAN, User::ROLE_MANAGER,
        ])->orderBy('role')->orderBy('id')->get(['id', 'name', 'role'])]);
    }

    /** 后台：设备占用与检查结果总览 */
    public function devices(Request $request)
    {
        $status = $request->query('status');
        $devices = Device::with(['activeAssignment.reservation.screening.show'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('serial')
            ->get();

        return response()->json([
            'data' => $devices->map(function (Device $d) {
                $a = $d->activeAssignment;
                return [
                    'id' => $d->id,
                    'serial' => $d->serial,
                    'label' => $d->label,
                    'status' => $d->status,
                    'battery_percent' => $d->battery_percent,
                    'fault_note' => $d->fault_note,
                    'supported_languages' => $d->supported_languages,
                    'occupied_by' => $a ? [
                        'assignment_id' => $a->id,
                        'reservation_code' => $a->reservation?->code,
                        'audience_name' => $a->reservation?->audience_name,
                        'language' => $a->reservation?->language,
                        'show_title' => $a->reservation?->screening?->show?->title,
                        'starts_at' => $a->reservation?->screening?->starts_at?->toDateTimeString(),
                        'issued_at' => $a->issued_at?->toDateTimeString(),
                    ] : null,
                ];
            }),
            'summary' => [
                'total' => $devices->count(),
                'available' => $devices->where('status', Device::STATUS_AVAILABLE)->count(),
                'assigned' => $devices->where('status', Device::STATUS_ASSIGNED)->count(),
                'faulty' => $devices->where('status', Device::STATUS_FAULTY)->count(),
            ],
        ]);
    }

    /** 检查记录（发放核对 + 散场复检） */
    public function checks(Request $request)
    {
        $checks = DeviceCheck::with(['device', 'inspector', 'deviceAssignment.reservation.screening.show'])
            ->orderByDesc('checked_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $checks->map(fn (DeviceCheck $c) => [
            'id' => $c->id,
            'phase' => $c->phase,
            'result' => $c->result,
            'device_serial' => $c->device?->serial,
            'battery_percent' => $c->battery_percent,
            'physical_ok' => $c->physical_ok,
            'language_confirmed' => $c->language_confirmed,
            'note' => $c->note,
            'inspector' => $c->inspector?->name,
            'checked_at' => $c->checked_at?->toDateTimeString(),
            'show_title' => $c->deviceAssignment?->reservation?->screening?->show?->title,
            'reservation_code' => $c->deviceAssignment?->reservation?->code,
        ])]);
    }

    /** 未归还设备（跨场次）——这些设备不能分配给下一场 */
    public function outstanding()
    {
        $items = DeviceAssignment::with(['device', 'reservation.screening.show'])
            ->whereNull('returned_at')
            ->orderBy('issued_at')
            ->get();

        return response()->json(['data' => $items->map(fn ($a) => [
            'assignment_id' => $a->id,
            'device_serial' => $a->device->serial,
            'reservation_code' => $a->reservation->code,
            'audience_name' => $a->reservation->audience_name,
            'language' => $a->reservation->language,
            'show_title' => $a->reservation->screening?->show?->title,
            'starts_at' => $a->reservation->screening?->starts_at?->toDateTimeString(),
            'issued_at' => $a->issued_at?->toDateTimeString(),
        ])]);
    }

    public function notifications()
    {
        return response()->json(['data' => NotificationLog::with('user')
            ->orderByDesc('sent_at')->limit(100)->get()->map(fn (NotificationLog $n) => [
                'id' => $n->id,
                'channel' => $n->channel,
                'to' => $n->to,
                'subject' => $n->subject,
                'body' => $n->body,
                'sent_at' => $n->sent_at?->toDateTimeString(),
            ])]);
    }

    public function repairDevice(Request $request, int $deviceId)
    {
        $device = Device::findOrFail($deviceId);
        try {
            $this->deviceService->repair($device, $request->input('note', '维修完成'));
        } catch (DeviceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => "设备 {$device->serial} 已恢复为可用"]);
    }
}
