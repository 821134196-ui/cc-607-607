<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Screening;
use App\Models\SubtitleVersion;
use App\Services\SubtitleException;
use App\Services\SubtitleService;
use Illuminate\Http\Request;

class SubtitleController extends Controller
{
    public function __construct(private readonly SubtitleService $subtitles)
    {
    }

    /** 某场次某语言的版本列表 + 当前稿 */
    public function versions(int $screeningId, string $language)
    {
        $screening = Screening::findOrFail($screeningId);
        $current = $this->subtitles->currentVersion($screeningId, $language);

        return response()->json([
            'data' => [
                'screening' => [
                    'id' => $screening->id,
                    'starts_at' => $screening->starts_at->toDateTimeString(),
                    'ends_at' => $screening->ends_at->toDateTimeString(),
                    'state' => $screening->hasEnded() ? 'ended' : ($screening->hasStarted() ? 'live' : 'scheduled'),
                ],
                'language' => $language,
                'current_version_id' => $current?->id,
                'versions' => $this->subtitles->history($screeningId, $language),
            ],
        ]);
    }

    /** 下载/查看某个历史版本的文件内容（旧文件保留可查） */
    public function content(int $versionId)
    {
        $version = SubtitleVersion::findOrFail($versionId);

        return response()->json([
            'data' => [
                'id' => $version->id,
                'title' => $version->title,
                'file_path' => $version->file_path,
                'file_hash' => $version->file_hash,
                'content' => $version->content,
            ],
        ]);
    }

    /** 技术员发布新版本（演出前发布即成为当前稿；演出中发布不自动切换，需审批） */
    public function publish(Request $request, int $screeningId)
    {
        $data = $request->validate([
            'language' => ['required', 'string', 'max:10'],
            'title' => ['nullable', 'string', 'max:100'],
            'content' => ['required', 'string', 'min:1'],
        ]);

        $screening = Screening::findOrFail($screeningId);

        try {
            $version = $this->subtitles->publish(
                $screening,
                $data['language'],
                $data['title'] ?? '',
                $data['content'],
                $request->attributes->get('acting_user')
            );
        } catch (SubtitleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $live = $screening->hasStarted() && !$screening->hasEnded();

        return response()->json([
            'message' => $live
                ? "新版本 v{$version->version_no} 已保存为历史版本；演出中切换必须经负责人批准"
                : "字幕 v{$version->version_no} 已发布并设为当前稿",
            'data' => ['version_id' => $version->id, 'version_no' => $version->version_no],
        ], 201);
    }

    /** 演出中技术员发起换稿申请 */
    public function requestSwitch(Request $request, int $screeningId)
    {
        $data = $request->validate([
            'language' => ['required', 'string', 'max:10'],
            'to_version_id' => ['required', 'integer', 'exists:subtitle_versions,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $screening = Screening::findOrFail($screeningId);
        $to = SubtitleVersion::findOrFail($data['to_version_id']);

        try {
            $req = $this->subtitles->requestSwitch(
                $screening,
                $data['language'],
                $to,
                $request->attributes->get('acting_user'),
                $data['reason'] ?? null
            );
        } catch (SubtitleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => '换稿申请已提交，等待负责人批准',
            'data' => ['request_id' => $req->id, 'status' => $req->status],
        ], 201);
    }

    /** 换稿申请列表（技术员看进度 / 负责人审批队列） */
    public function switchRequests(Request $request, ?int $screeningId = null)
    {
        $query = \App\Models\SubtitleSwitchRequest::with([
            'screening.show', 'fromVersion', 'toVersion', 'requester', 'decider',
        ])->orderByDesc('created_at');

        if ($screeningId) {
            $query->where('screening_id', $screeningId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(['data' => $query->get()->map(fn ($r) => [
            'id' => $r->id,
            'status' => $r->status,
            'language' => $r->language,
            'reason' => $r->reason,
            'decision_note' => $r->decision_note,
            'switched_at' => $r->switched_at?->toDateTimeString(),
            'created_at' => $r->created_at?->toDateTimeString(),
            'screening_id' => $r->screening_id,
            'show_title' => $r->screening?->show?->title,
            'starts_at' => $r->screening?->starts_at?->toDateTimeString(),
            'from_version' => ['version_no' => $r->fromVersion?->version_no, 'title' => $r->fromVersion?->title],
            'to_version' => ['version_no' => $r->toVersion?->version_no, 'title' => $r->toVersion?->title],
            'requested_by' => $r->requester?->name,
            'decided_by' => $r->decider?->name,
        ])]);
    }
}
