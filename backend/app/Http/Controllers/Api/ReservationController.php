<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Performance;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $reservations = Reservation::with(['performance', 'assignment.device'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($reservations->map(fn (Reservation $r) => $this->present($r)));
    }

    public function store(Request $request, ReservationService $service)
    {
        $data = $request->validate([
            'performance_id' => ['required', 'integer', 'exists:performances,id'],
            'language' => ['required', 'string', 'max:16'],
            'ticket_code' => ['required', 'string', 'max:64'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'contact_name' => ['nullable', 'string', 'max:64'],
        ]);

        $reservation = $service->book(
            $request->user(),
            (int) $data['performance_id'],
            $data['language'],
            $data['ticket_code'],
            $data['contact_phone'] ?? null,
            $data['contact_name'] ?? null,
        );

        return response()->json($this->present($reservation->load('performance')), 201);
    }

    /**
     * 改签：释放原预约，建立新预约。
     */
    public function rebook(Request $request, Reservation $reservation, ReservationService $service)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403, '不能改签他人的预约');

        $data = $request->validate([
            'performance_id' => ['sometimes', 'integer', 'exists:performances,id'],
            'language' => ['sometimes', 'string', 'max:16'],
            'ticket_code' => ['nullable', 'string', 'max:64'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'contact_name' => ['nullable', 'string', 'max:64'],
        ]);

        $new = $service->rebook($reservation, $data);

        return response()->json($this->present($new->load('performance')), 201);
    }

    public function cancel(Request $request, Reservation $reservation, ReservationService $service)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403, '不能取消他人的预约');

        $service->cancel($reservation, $request->input('reason'));

        return response()->json(['message' => '预约已取消，占用已释放']);
    }

    public function show(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        return response()->json($this->present($reservation->load(['performance', 'assignment.device'])));
    }

    private function present(Reservation $r): array
    {
        return [
            'id' => $r->id,
            'performance_id' => $r->performance_id,
            'performance_title' => $r->performance?->title,
            'starts_at' => $r->performance?->starts_at?->toIso8601String(),
            'language' => $r->language,
            'status' => $r->status,
            'contact_name' => $r->contact_name,
            'contact_phone' => $r->contact_phone,
            'seq' => $r->seq,
            'cancel_reason' => $r->cancel_reason,
            'cancelled_at' => $r->cancelled_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            'device' => $r->assignment?->device ? [
                'serial' => $r->assignment->device->serial,
                'battery_at_issue' => $r->assignment->battery_at_issue,
                'return_check' => $r->assignment->check_result,
            ] : null,
        ];
    }
}
