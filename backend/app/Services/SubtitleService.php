<?php

namespace App\Services;

use App\Models\Screening;
use App\Models\ScreeningSubtitleCurrent;
use App\Models\SubtitleSwitchRequest;
use App\Models\SubtitleVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SubtitleException extends \RuntimeException
{
}

class SubtitleService
{
    /**
     * 技术员发布字幕版本。字幕必须明确对应场次与语言；
     * 新版本落盘为独立文件，旧版本文件永不覆盖、永不删除。
     */
    public function publish(
        Screening $screening,
        string $language,
        string $title,
        string $content,
        User $technician,
        bool $setCurrent = true
    ): SubtitleVersion {
        $content = trim($content);
        if ($content === '') {
            throw new SubtitleException('字幕内容不能为空');
        }

        return DB::transaction(function () use ($screening, $language, $title, $content, $technician, $setCurrent) {
            $screening = Screening::lockForUpdate()->find($screening->id);

            $versionNo = (int) SubtitleVersion::where('screening_id', $screening->id)
                ->where('language', $language)
                ->max('version_no') + 1;

            // 独立文件：screening-<id>/<lang>/v<n>-<hash前8位>.srt —— 旧文件天然保留
            $hash = hash('sha256', $content);
            $path = "subtitles/screening-{$screening->id}/{$language}/v{$versionNo}-".substr($hash, 0, 8).'.srt';
            Storage::put($path, $content);

            $version = SubtitleVersion::create([
                'screening_id' => $screening->id,
                'language' => $language,
                'version_no' => $versionNo,
                'title' => $title ?: "字幕 v{$versionNo}",
                'file_path' => $path,
                'file_size' => strlen($content),
                'file_hash' => $hash,
                'published_by' => $technician->id,
                'published_at' => now(),
            ]);

            // 演出前：首个版本直接成为当前稿；演出中：必须走审批，不允许发布即切换
            if ($setCurrent && !$screening->hasStarted()) {
                $this->setCurrent($screening->id, $language, $version->id);
            }

            return $version->load('publisher');
        }, 3);
    }

    private function setCurrent(int $screeningId, string $language, int $versionId): void
    {
        ScreeningSubtitleCurrent::updateOrCreate(
            ['screening_id' => $screeningId, 'language' => $language],
            ['subtitle_version_id' => $versionId]
        );
    }

    public function currentVersion(int $screeningId, string $language): ?SubtitleVersion
    {
        $cur = ScreeningSubtitleCurrent::with('version')
            ->where('screening_id', $screeningId)
            ->where('language', $language)
            ->first();
        return $cur?->version;
    }

    /**
     * 开演后技术员发起换稿申请。
     */
    public function requestSwitch(
        Screening $screening,
        string $language,
        SubtitleVersion $to,
        User $technician,
        ?string $reason
    ): SubtitleSwitchRequest {
        return DB::transaction(function () use ($screening, $language, $to, $technician, $reason) {
            $screening = Screening::lockForUpdate()->find($screening->id);

            if (!$screening->hasStarted()) {
                throw new SubtitleException('演出尚未开始，直接发布当前稿即可，无需审批换稿');
            }
            if ($screening->hasEnded()) {
                throw new SubtitleException('演出已经结束，不能再更换字幕稿');
            }
            if ($to->screening_id !== $screening->id || $to->language !== $language) {
                throw new SubtitleException('目标字幕版本与场次/语言不对应');
            }

            $from = $this->currentVersion($screening->id, $language);
            if (!$from) {
                throw new SubtitleException('该场次该语言尚无当前字幕稿');
            }
            if ($from->id === $to->id) {
                throw new SubtitleException('目标版本与当前版本相同，无需切换');
            }

            $pending = SubtitleSwitchRequest::where('screening_id', $screening->id)
                ->where('language', $language)
                ->where('status', SubtitleSwitchRequest::STATUS_PENDING)
                ->exists();
            if ($pending) {
                throw new SubtitleException('已有一个待批准的换稿申请，请等待负责人处理');
            }

            return SubtitleSwitchRequest::create([
                'screening_id' => $screening->id,
                'language' => $language,
                'from_version_id' => $from->id,
                'to_version_id' => $to->id,
                'requested_by' => $technician->id,
                'reason' => $reason,
                'status' => SubtitleSwitchRequest::STATUS_PENDING,
            ])->load(['fromVersion', 'toVersion', 'requester']);
        }, 3);
    }

    /**
     * 负责人批准：只有批准后才真正切换当前稿，并记录切换时间。
     */
    public function approve(SubtitleSwitchRequest $req, User $manager, ?string $note = null): SubtitleSwitchRequest
    {
        return DB::transaction(function () use ($req, $manager, $note) {
            $req = SubtitleSwitchRequest::lockForUpdate()->findOrFail($req->id);
            if ($req->status !== SubtitleSwitchRequest::STATUS_PENDING) {
                throw new SubtitleException('该申请已处理，不能重复审批');
            }

            $screening = $req->screening;
            // 校验待切版本仍与场次对应
            if ($req->toVersion->screening_id !== $req->screening_id) {
                throw new SubtitleException('目标字幕版本与场次不对应');
            }

            // 原子切换当前稿；旧版本文件原样保留在 subtitles/ 下
            $this->setCurrent($screening->id, $req->language, $req->to_version_id);

            $switchedAt = now();
            $req->update([
                'status' => SubtitleSwitchRequest::STATUS_APPROVED,
                'decided_by' => $manager->id,
                'decision_note' => $note,
                'switched_at' => $switchedAt,
            ]);

            return $req->load(['fromVersion', 'toVersion', 'requester', 'decider']);
        }, 3);
    }

    public function reject(SubtitleSwitchRequest $req, User $manager, ?string $note = null): SubtitleSwitchRequest
    {
        return DB::transaction(function () use ($req, $manager, $note) {
            $req = SubtitleSwitchRequest::lockForUpdate()->findOrFail($req->id);
            if ($req->status !== SubtitleSwitchRequest::STATUS_PENDING) {
                throw new SubtitleException('该申请已处理，不能重复审批');
            }
            $req->update([
                'status' => SubtitleSwitchRequest::STATUS_REJECTED,
                'decided_by' => $manager->id,
                'decision_note' => $note,
            ]);
            return $req->load(['fromVersion', 'toVersion', 'requester', 'decider']);
        }, 3);
    }

    /** 某场次某语言的版本历史（含每版发布人与是否当前在用） */
    public function history(int $screeningId, string $language): array
    {
        $current = $this->currentVersion($screeningId, $language);
        return SubtitleVersion::with('publisher')
            ->where('screening_id', $screeningId)
            ->where('language', $language)
            ->orderBy('version_no')
            ->get()
            ->map(fn (SubtitleVersion $v) => [
                'id' => $v->id,
                'version_no' => $v->version_no,
                'title' => $v->title,
                'file_path' => $v->file_path,
                'file_size' => $v->file_size,
                'file_hash' => $v->file_hash,
                'published_by' => $v->publisher?->name,
                'published_at' => $v->published_at->toDateTimeString(),
                'is_current' => $current && $current->id === $v->id,
            ])
            ->all();
    }
}
