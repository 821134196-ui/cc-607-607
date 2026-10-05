<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubtitleSwitchRequest;
use App\Services\SubtitleException;
use App\Services\SubtitleService;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(private readonly SubtitleService $subtitles)
    {
    }

    public function approve(Request $request, int $requestId)
    {
        $data = $request->validate(['decision_note' => ['nullable', 'string', 'max:500']]);

        try {
            $req = $this->subtitles->approve(
                SubtitleSwitchRequest::findOrFail($requestId),
                $request->attributes->get('acting_user'),
                $data['decision_note'] ?? null
            );
        } catch (SubtitleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "已批准，字幕已于 {$req->switched_at->toDateTimeString()} 切换至 v{$req->toVersion->version_no}；旧版本文件保留可查",
            'data' => ['switched_at' => $req->switched_at->toDateTimeString()],
        ]);
    }

    public function reject(Request $request, int $requestId)
    {
        $data = $request->validate(['decision_note' => ['nullable', 'string', 'max:500']]);

        try {
            $req = $this->subtitles->reject(
                SubtitleSwitchRequest::findOrFail($requestId),
                $request->attributes->get('acting_user'),
                $data['decision_note'] ?? null
            );
        } catch (SubtitleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => '已驳回，现场继续使用原字幕稿']);
    }
}
