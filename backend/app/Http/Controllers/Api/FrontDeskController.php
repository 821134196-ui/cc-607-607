<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Reservation;
use App\Services\DeviceException;
use App\Services\DeviceService;
use Illuminate\Http\Request;

class FrontDeskController extends Controller
{
    public function __construct(private readonly DeviceService $devices)
    {
    }

    /** 前台按凭证号查预约 */
    public function lookup(Request $request, string $code)
    {
        $reservation = Reservation::with(['screening.show', 'activeAssignment.device'])
            ->where('code', $code)
            ->firstOrFail();

        $candidateDevices = Device::where('status', Device::STATUS_AVAILABLE)
            ->orderBy('serial')
            ->get()
            ->filter(fn (Device $d) => $d->supportsLanguage($reservation->language))
            ->map(fn (Device $d) => [
                'id' => $d->id,
                'serial' => $d->serial,
                'label' => $d->label,
                'battery_percent' => $d->battery_percent,
                'supported_languages' => $d->supported_languages,
            ])
            ->values();

        return response()->json([
            'data' => [
                'code' => $reservation->code,
                'status' => $reservation->status,
                'audience_name' => $reservation->audience_name,
                'language' => $reservation->language,
                'show_title' => $reservation->screening?->show?->title,
                'venue' => $reservation->screening?->venue,
                'starts_at' => $reservation->screening?->starts_at?->toDateTimeString(),
                'active_device' => $reservation->activeAssignment ? [
                    'assignment_id' => $reservation->activeAssignment->id,
                    'serial' => $reservation->activeAssignment->device?->serial,
                ] : null,
                'available_devices' => $candidateDevices,
            ],
        ]);
    }

    /** 发放设备：核对实物编号、电量、字幕语言 */
    public function issue(Request $request, string $code)
    {
        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'battery_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'physical_ok' => ['required', 'boolean'],
            'language_confirmed' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $reservation = Reservation::where('code', $code)->firstOrFail();
        $device = Device::findOrFail($data['device_id']);

        try {
            $assignment = $this->devices->issue($reservation, $device, $request->attributes->get('acting_user'), [
                'battery_percent' => (int) $data['battery_percent'],
                'physical_ok' => (bool) $data['physical_ok'],
                'language_confirmed' => (bool) $data['language_confirmed'],
                'note' => $data['note'] ?? null,
            ]);
        } catch (DeviceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "设备 {$assignment->device->serial} 已发放（编号/电量/语言核对通过）",
            'data' => ['assignment_id' => $assignment->id, 'device_serial' => $assignment->device->serial],
        ], 201);
    }

    /** 散场收回并复检 */
    public function returnDevice(Request $request)
    {
        $data = $request->validate([
            'assignment_id' => ['required', 'integer', 'exists:device_assignments,id'],
            'battery_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'physical_ok' => ['required', 'boolean'],
            'language_confirmed' => ['required', 'boolean'],
            'faulty' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $assignment = \App\Models\DeviceAssignment::findOrFail($data['assignment_id']);

        try {
            $updated = $this->devices->returnDevice($assignment, $request->attributes->get('acting_user'), [
                'battery_percent' => (int) $data['battery_percent'],
                'physical_ok' => (bool) $data['physical_ok'],
                'language_confirmed' => (bool) $data['language_confirmed'],
                'faulty' => (bool) ($data['faulty'] ?? false),
                'note' => $data['note'] ?? null,
            ]);
        } catch (DeviceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $faulty = $updated->device->status === Device::STATUS_FAULTY;

        return response()->json([
            'message' => $faulty
                ? "设备已收回，复检未通过，已锁定为故障设备，不能分配给下一场"
                : '设备已收回，复检通过，可以继续使用',
            'data' => [
                'device_serial' => $updated->device->serial,
                'device_status' => $updated->device->status,
            ],
        ]);
    }

    /** 待归还（未收回）设备列表，散场时前台使用 */
    public function outstanding(Request $request)
    {
        $screeningId = $request->integer('screening_id') ?: null;

        $query = \App\Models\DeviceAssignment::with([
            'device', 'reservation.screening.show', 'issuer',
        ])->whereNull('returned_at');

        if ($screeningId) {
            $query->whereHas('reservation', fn ($q) => $q->where('screening_id', $screeningId));
        }

        return response()->json(['data' => $query->orderBy('issued_at')->get()->map(fn ($a) => [
            'assignment_id' => $a->id,
            'device_serial' => $a->device->serial,
            'battery_percent' => $a->device->battery_percent,
            'reservation_code' => $a->reservation->code,
            'audience_name' => $a->reservation->audience_name,
            'language' => $a->reservation->language,
            'show_title' => $a->reservation->screening?->show?->title,
            'starts_at' => $a->reservation->screening?->starts_at?->toDateTimeString(),
            'issued_at' => $a->issued_at?->toDateTimeString(),
        ])]);
    }
}
