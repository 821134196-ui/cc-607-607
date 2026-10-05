<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Performance;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    /** 演出列表，附各语言剩余可预约数量 */
    public function index(Request $request)
    {
        $performances = Performance::with('languages')
            ->orderBy('starts_at')
            ->get();

        return response()->json($performances->map(fn (Performance $p) => $this->present($p)));
    }

    public function show(Request $request, Performance $performance, ReservationService $service)
    {
        return response()->json($this->present($performance->load('languages'), $service));
    }

    private function present(Performance $p, ?ReservationService $service = null): array
    {
        $service ??= app(ReservationService::class);

        return [
            'id' => $p->id,
            'title' => $p->title,
            'venue' => $p->venue,
            'starts_at' => $p->starts_at?->toIso8601String(),
            'ends_at' => $p->ends_at?->toIso8601String(),
            'has_started' => $p->hasStarted(),
            'has_finished' => $p->isFinished(),
            'description' => $p->description,
            'languages' => $p->languages->map(fn ($pl) =>
                $service->availability($p, $pl->language)
            )->values(),
        ];
    }
}
