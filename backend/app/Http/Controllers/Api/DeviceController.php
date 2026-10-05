<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\Performance;
use App\Models\Reservation;
use App\Services\DeviceService;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * 后台设备看板：全部设备状态 + 每场发放占用情况 + 检查结果。
     */
    public function index(Request $request)
    {
        $devices = Device::orderBy('serial')->get()->map(fn (Device $d) => [
            'id' => $d->id,
            'serial' => $d->serial,
            'model' => $d->model,
            'status' => $d->status,
            'battery_level' => $d->battery_level,
            'last_check_result' => $d->last_check_result,
            'fault_notes' => $d->fault_notes,
            'checked_at' => $d->checked_at?->toIso8601String(),
        ]);

        $summary = [
            'total' => $devices->count(),
            'available' => $devices->where('status', Device::STATUS_AVAILABLE)->count(),
            'assigned' => $devices->where('status', Device::STATUS_ASSIGNED)->count(),
            'maintenance' => $devices->where('status', Device::STATUS_MAINTENANCE)->count(),
            'retired' => $devices->where('status', Device::STATUS_RETIRED)->count(),
        ];

        return response()->json(['summary' => $summary, 'devices' => $devices]);
    }

    /** 某场演出的发放/占用明细（含预约与复检结果） */
    public function performanceAssignments(Request $request, Performance $performance)
    {
        $assignments = DeviceAssignment::with(['device', 'reservation.user', 'issuer', 'receiver'])
            ->where('performance_id', $performance->id)
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (DeviceAssignment $a) => $this->presentAssignment($a));

        $reservations = Reservation::with(['user', 'assignment.device'])
            ->where('performance_id', $performance->id)
            ->orderBy('language')
            ->get()
            ->map(fn (Reservation $r) => [
                'id' => $r->id,
                'audience' => $r->user?->name,
                'language' => $r->language,
                'status' => $r->status,
                'contact_phone' => $r->contact_phone,
                'device_serial' => $r->assignment?->device?->serial,
                'check_result' => $r->assignment?->check_result,
            ]);

        return response()->json([
            'performance_id' => $performance->id,
            'title' => $performance->title,
            'starts_at' => $performance->starts_at->toIso8601String(),
            'assignments' => $assignments,
            'reservations' => $reservations,
        ]);
    }

    /**
     * 前台发放设备：核对实物编号、电量、字幕语言。
     */
    public function issue(Request $request, DeviceService $service)
    {
        $data = $request->validate([
            'reservation_id' => ['required', 'integer', 'exists:reservations,id'],
            'serial' => ['required', 'string', 'max:64'],
            'battery' => ['required', 'integer', 'min:0', 'max:100'],
            'language' => ['required', 'string', 'max:16'],
        ]);

        $assignment = $service->issue(
            (int) $data['reservation_id'],
            $data['serial'],
            (int) $data['battery'],
            $data['language'],
            $request->user(),
        );

        return response()->json($this->presentAssignment($assignment), 201);
    }

    /** 散场收回复检 */
    public function returnDevice(Request $request, DeviceAssignment $assignment, DeviceService $service)
    {
        $data = $request->validate([
            'battery' => ['required', 'integer', 'min:0', 'max:100'],
            'check_result' => ['required', 'in:ok,fault'],
            'fault_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $service->returnDevice(
            $assignment->id,
            (int) $data['battery'],
            $data['check_result'],
            $data['fault_notes'] ?? null,
            $request->user(),
        );

        return response()->json($this->presentAssignment($updated));
    }

    /** 标记未归还 */
    public function markMissing(Request $request, DeviceAssignment $assignment, DeviceService $service)
    {
        $updated = $service->markMissing($assignment->id, $request->user());

        return response()->json($this->presentAssignment($updated));
    }

    /** 技术员对维修/找回设备复检后放行，回到可发放池 */
    public function release(Request $request, Device $device, DeviceService $service)
    {
        $data = $request->validate([
            'battery' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $device = $service->releaseFromMaintenance($device->id, (int) $data['battery'], $request->user());

        return response()->json([
            'message' => "设备 {$device->serial} 复检通过，已放回可发放池",
            'device' => [
                'id' => $device->id,
                'serial' => $device->serial,
                'status' => $device->status,
                'battery_level' => $device->battery_level,
                'last_check_result' => $device->last_check_result,
                'checked_at' => $device->checked_at?->toIso8601String(),
            ],
        ]);
    }

    /** 待领取预约队列（前台领设备时查找） */
    public function pickups(Request $request)
    {
        $request->validate([
            'performance_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string'],
        ]);

        $query = Reservation::with(['user', 'performance', 'assignment.device'])
            ->where('status', Reservation::STATUS_ACTIVE);

        if ($request->filled('performance_id')) {
            $query->where('performance_id', $request->integer('performance_id'));
        }
        if ($request->filled('q')) {
            $q = $request->string('q')->toString();
            $query->where(function ($w) use ($q) {
                $w->where('contact_name', 'like', "%{$q}%")
                    ->orWhere('contact_phone', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"));
            });
        }

        return response()->json($query->orderBy('created_at')->limit(100)->get()->map(fn (Reservation $r) => [
            'reservation_id' => $r->id,
            'audience' => $r->user?->name,
            'email' => $r->user?->email,
            'contact_phone' => $r->contact_phone,
            'performance_id' => $r->performance_id,
            'performance_title' => $r->performance?->title,
            'starts_at' => $r->performance?->starts_at?->toIso8601String(),
            'language' => $r->language,
        ]));
    }

    private function presentAssignment(DeviceAssignment $a): array
    {
        return [
            'id' => $a->id,
            'device_id' => $a->device_id,
            'serial' => $a->device?->serial ?? $a->serial_confirmed,
            'device_status' => $a->device?->status,
            'reservation_id' => $a->reservation_id,
            'performance_id' => $a->performance_id,
            'language' => $a->language,
            'status' => $a->status,
            'battery_at_issue' => $a->battery_at_issue,
            'language_at_issue' => $a->language_at_issue,
            'battery_at_return' => $a->battery_at_return,
            'check_result' => $a->check_result,
            'fault_notes' => $a->fault_notes,
            'issued_at' => $a->issued_at?->toIso8601String(),
            'returned_at' => $a->returned_at?->toIso8601String(),
            'audience' => $a->reservation?->user?->name,
        ];
    }
}
