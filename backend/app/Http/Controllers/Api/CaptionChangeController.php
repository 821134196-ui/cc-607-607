<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptionChangeRequest;
use App\Models\Performance;
use App\Services\CaptionService;
use Illuminate\Http\Request;

class CaptionChangeController extends Controller
{
    /** 技术员在演出中发起换稿申请 */
    public function store(Request $request, Performance $performance, CaptionService $service)
    {
        $data = $request->validate([
            'language' => ['required', 'string', 'max:16'],
            'title' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'min:1'],
            'reason' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $change = $service->requestChange(
            $performance,
            $data['language'],
            $data['title'],
            $data['content'],
            $data['reason'],
            $request->user(),
        );

        return response()->json([
            'message' => '换稿申请已提交，等待负责人批准',
            'request' => $this->present($change->load(['newScript', 'currentScript'])),
        ], 201);
    }

    /** 负责人批准 / 驳回 */
    public function review(Request $request, CaptionChangeRequest $changeRequest, CaptionService $service)
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $service->review(
            $changeRequest,
            (bool) $data['approve'],
            $request->user(),
            $data['review_note'] ?? null,
        );

        return response()->json([
            'message' => $data['approve'] ? '已批准并切换字幕版本' : '已驳回换稿申请',
            'request' => $this->present($updated->load(['newScript', 'currentScript', 'reviewer', 'requester'])),
        ]);
    }

    /** 审批列表（负责人）/ 申请列表（技术员） */
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'performance_id' => ['nullable', 'integer'],
        ]);

        $q = CaptionChangeRequest::with(['performance', 'newScript', 'currentScript', 'requester', 'reviewer'])
            ->orderByDesc('requested_at');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('performance_id')) {
            $q->where('performance_id', $request->integer('performance_id'));
        }

        return response()->json($q->get()->map(fn (CaptionChangeRequest $c) => $this->present($c)));
    }

    private function present(CaptionChangeRequest $c): array
    {
        return [
            'id' => $c->id,
            'performance_id' => $c->performance_id,
            'performance_title' => $c->performance?->title,
            'language' => $c->language,
            'status' => $c->status,
            'reason' => $c->reason,
            'requested_by' => $c->requester?->name,
            'requested_at' => $c->requested_at?->toIso8601String(),
            'reviewed_by' => $c->reviewer?->name,
            'review_note' => $c->review_note,
            'reviewed_at' => $c->reviewed_at?->toIso8601String(),
            'switched_at' => $c->switched_at?->toIso8601String(),
            'current_version' => $c->currentScript?->version_no,
            'new_version' => $c->newScript?->version_no,
            'new_script_id' => $c->new_script_id,
        ];
    }
}
