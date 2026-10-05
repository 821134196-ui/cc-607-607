<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\ReservationException;
use App\Services\TicketException;
use App\Services\ReservationService;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function store(Request $request)
    {
        $data = $this->validateReservation($request);

        try {
            $reservation = $this->reservations->book($data);
        } catch (ReservationException|TicketException $e) {
            return $this->fail($e->getMessage());
        }

        return response()->json([
            'message' => '预约成功',
            'data' => $this->present($reservation),
        ], 201);
    }

    public function show(string $code)
    {
        $reservation = $this->findByCode($code);
        return response()->json(['data' => $this->present($reservation)]);
    }

    /**
     * 改签：释放原预约占用，建立新预约。
     */
    public function reschedule(Request $request, string $code)
    {
        $old = $this->findByCode($code);
        $data = $this->validateReservation($request);

        try {
            $new = $this->reservations->reschedule(
                $old,
                [
                    'screening_id' => $data['screening_id'],
                    'language' => $data['language'],
                    'audience_name' => $data['audience_name'],
                    'audience_phone' => $data['audience_phone'] ?? null,
                    'ticket_code' => $data['ticket_code'] ?? null,
                ]
            );
        } catch (ReservationException|TicketException $e) {
            return $this->fail($e->getMessage());
        }

        return response()->json([
            'message' => "改签成功，原凭证 {$old->code} 已释放，新凭证 {$new->code}",
            'data' => [
                'old' => $this->present($old->fresh()),
                'new' => $this->present($new),
            ],
        ]);
    }

    public function cancel(string $code)
    {
        $reservation = $this->findByCode($code);
        try {
            $this->reservations->cancel($reservation);
        } catch (ReservationException|TicketException $e) {
            return $this->fail($e->getMessage());
        }
        return response()->json(['message' => '预约已取消，设备配额已释放']);
    }

    private function findByCode(string $code): Reservation
    {
        return Reservation::with(['screening.show', 'activeAssignment.device'])
            ->where('code', $code)
            ->firstOrFail();
    }

    private function validateReservation(Request $request): array
    {
        return $request->validate([
            'screening_id' => ['required', 'integer', 'exists:screenings,id'],
            'language' => ['required', 'string', 'max:10'],
            'audience_name' => ['required', 'string', 'max:50'],
            'audience_phone' => ['nullable', 'string', 'max:30'],
            'ticket_code' => ['nullable', 'string', 'max:50'],
        ]);
    }

    private function present(Reservation $r): array
    {
        $r->loadMissing(['screening.show', 'activeAssignment.device']);

        return [
            'code' => $r->code,
            'status' => $r->status,
            'screening_id' => $r->screening_id,
            'show_title' => $r->screening?->show?->title,
            'venue' => $r->screening?->venue,
            'starts_at' => $r->screening?->starts_at?->toDateTimeString(),
            'language' => $r->language,
            'audience_name' => $r->audience_name,
            'release_reason' => $r->release_reason,
            'parent_id' => $r->parent_id,
            'device' => $r->activeAssignment
                ? ['serial' => $r->activeAssignment->device?->serial]
                : null,
        ];
    }

    private function fail(string $message, int $status = 422)
    {
        return response()->json(['message' => $message], $status);
    }
}
