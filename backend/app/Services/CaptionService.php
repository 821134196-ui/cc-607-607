<?php

namespace App\Services;

use App\Models\CaptionChangeRequest;
use App\Models\CaptionScript;
use App\Models\Performance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CaptionService
{
    /**
     * 技术员发布字幕版本。必须指定场次，且语言属于该场次。
     * 开演前：可直接发布为当前版本（旧版保留为 superseded）。
     */
    public function publish(
        Performance $performance,
        string $language,
        string $title,
        string $content,
        User $technician,
    ): CaptionScript {
        $this->assertLanguageOffered($performance, $language);

        // 开演后不允许直接发布换稿，必须走申请-批准流程
        if ($performance->hasStarted()) {
            throw ValidationException::withMessages([
                'performance_id' => '演出已开演，不能直接更换字幕稿；请发起换稿申请并由负责人批准。',
            ]);
        }

        return DB::transaction(function () use ($performance, $language, $title, $content, $technician) {
            $versionNo = ((int) CaptionScript::where('performance_id', $performance->id)
                ->where('language', $language)
                ->lockForUpdate()
                ->max('version_no')) + 1;

            // 保存不可变快照文件（旧文件保留），并记录校验和
            $path = "captions/performance-{$performance->id}/{$language}/v{$versionNo}.txt";
            Storage::disk('local')->put($path, $content);

            $script = CaptionScript::create([
                'performance_id' => $performance->id,
                'language' => $language,
                'version_no' => $versionNo,
                'title' => $title,
                'content' => $content,
                'file_path' => $path,
                'checksum' => CaptionScript::checksumFor($content),
                'status' => CaptionScript::STATUS_ACTIVE,
                'published_by' => $technician->id,
                'published_at' => now(),
                'activated_at' => now(),
            ]);

            // 旧当前版本：保留内容与文件，仅状态降级为 superseded
            CaptionScript::where('performance_id', $performance->id)
                ->where('language', $language)
                ->where('status', CaptionScript::STATUS_ACTIVE)
                ->where('id', '!=', $script->id)
                ->update(['status' => CaptionScript::STATUS_SUPERSEDED]);

            return $script;
        });
    }

    /**
     * 开演后技术员发起更换字幕稿申请（不立即生效）。
     */
    public function requestChange(
        Performance $performance,
        string $language,
        string $title,
        string $content,
        string $reason,
        User $technician,
    ): CaptionChangeRequest {
        $this->assertLanguageOffered($performance, $language);

        // 开演后的换稿才走审批；开演前技术员可直接发布
        if (! $performance->hasStarted()) {
            throw ValidationException::withMessages([
                'performance_id' => '演出尚未开演，无需审批，请直接发布新版本。',
            ]);
        }

        return DB::transaction(function () use ($performance, $language, $title, $content, $reason, $technician) {
            // 先以 draft 形式保存待审新版本（同样落快照文件保留）
            $versionNo = ((int) CaptionScript::where('performance_id', $performance->id)
                ->where('language', $language)
                ->lockForUpdate()
                ->max('version_no')) + 1;

            $path = "captions/performance-{$performance->id}/{$language}/v{$versionNo}.txt";
            Storage::disk('local')->put($path, $content);

            $draft = CaptionScript::create([
                'performance_id' => $performance->id,
                'language' => $language,
                'version_no' => $versionNo,
                'title' => $title,
                'content' => $content,
                'file_path' => $path,
                'checksum' => CaptionScript::checksumFor($content),
                'status' => CaptionScript::STATUS_DRAFT,
                'published_by' => $technician->id,
                'published_at' => now(),
                'activated_at' => null,
            ]);

            $current = CaptionScript::where('performance_id', $performance->id)
                ->where('language', $language)
                ->where('status', CaptionScript::STATUS_ACTIVE)
                ->first();

            return CaptionChangeRequest::create([
                'performance_id' => $performance->id,
                'language' => $language,
                'new_script_id' => $draft->id,
                'current_script_id' => $current?->id,
                'status' => CaptionChangeRequest::STATUS_PENDING,
                'reason' => $reason,
                'requested_by' => $technician->id,
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * 负责人审批。批准 → 立即切换当前版本，记录切换时间，旧版保留；驳回 → 草稿标记 rejected。
     */
    public function review(CaptionChangeRequest $request, bool $approve, User $manager, ?string $note = null): CaptionChangeRequest
    {
        return DB::transaction(function () use ($request, $approve, $manager, $note) {
            $req = CaptionChangeRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();
            if ($req->status !== CaptionChangeRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['request' => '该申请已审批，不能重复操作']);
            }

            $req->update([
                'reviewed_by' => $manager->id,
                'review_note' => $note,
                'reviewed_at' => now(),
            ]);

            if (! $approve) {
                $req->update(['status' => CaptionChangeRequest::STATUS_REJECTED]);
                CaptionScript::where('id', $req->new_script_id)->update(['status' => CaptionScript::STATUS_REJECTED]);

                return $req;
            }

            // 批准：执行版本切换
            $newScript = CaptionScript::where('id', $req->new_script_id)->lockForUpdate()->firstOrFail();

            CaptionScript::where('performance_id', $req->performance_id)
                ->where('language', $req->language)
                ->where('status', CaptionScript::STATUS_ACTIVE)
                ->update(['status' => CaptionScript::STATUS_SUPERSEDED]);

            $switchTime = now();
            $newScript->update([
                'status' => CaptionScript::STATUS_ACTIVE,
                'activated_at' => $switchTime,
            ]);

            $req->update([
                'status' => CaptionChangeRequest::STATUS_APPROVED,
                'switched_at' => $switchTime,
            ]);

            return $req;
        });
    }

    public function history(Performance $performance, string $language)
    {
        return CaptionScript::where('performance_id', $performance->id)
            ->where('language', $language)
            ->orderBy('version_no')
            ->get();
    }

    private function assertLanguageOffered(Performance $performance, string $language): void
    {
        if (! $performance->languages()->where('language', $language)->exists()) {
            throw ValidationException::withMessages(['language' => '该场演出不提供此字幕语言']);
        }
    }
}
