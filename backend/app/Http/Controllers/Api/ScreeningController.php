<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Screening;
use App\Services\ReservationService;

class ScreeningController extends Controller
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function index()
    {
        $screenings = Screening::with(['show', 'languagePools'])
            ->orderBy('starts_at')
            ->get()
            ->map(function (Screening $s) {
                return [
                    'id' => $s->id,
                    'show_title' => $s->show?->title,
                    'venue' => $s->venue,
                    'starts_at' => $s->starts_at->toDateTimeString(),
                    'ends_at' => $s->ends_at->toDateTimeString(),
                    'state' => $this->state($s),
                    'languages' => $s->languagePools->map(fn ($p) =>
                        $this->reservations->availability($s, $p->language)
                    )->values(),
                ];
            });

        return response()->json(['data' => $screenings, 'now' => now()->toDateTimeString()]);
    }

    public function show(int $id)
    {
        $s = Screening::with(['show', 'languagePools'])->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $s->id,
                'show_title' => $s->show?->title,
                'venue' => $s->venue,
                'starts_at' => $s->starts_at->toDateTimeString(),
                'ends_at' => $s->ends_at->toDateTimeString(),
                'state' => $this->state($s),
                'languages' => $s->languagePools->map(fn ($p) =>
                    $this->reservations->availability($s, $p->language)
                )->values(),
                // 演示用：列出本场次可填的模拟票号
                'sample_ticket_codes' => $s->tickets()->where('status', 'valid')->pluck('code'),
            ],
        ]);
    }

    private function state(Screening $s): string
    {
        if ($s->hasEnded()) {
            return 'ended';
        }
        return $s->hasStarted() ? 'live' : 'scheduled';
    }
}
