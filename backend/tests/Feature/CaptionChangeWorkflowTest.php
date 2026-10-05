<?php

namespace Tests\Feature;

use App\Models\CaptionChangeRequest;
use App\Models\CaptionScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTheater;
use Tests\TestCase;

class CaptionChangeWorkflowTest extends TestCase
{
    use CreatesTheater, RefreshDatabase;

    public function test_during_show_direct_publish_is_blocked(): void
    {
        ['tech' => $tech] = $this->theaterUsers();
        $p = $this->livePerformance(['zh' => 2]);

        $this->tok('token-tech')->postJson("/api/performances/{$p->id}/captions", [
            'language' => 'zh', 'title' => 'v2', 'content' => '新稿',
        ])->assertStatus(422)->assertJsonValidationErrors('performance_id');

        // 被拦截，不得产生任何新版本
        $this->assertSame(0, CaptionScript::where('performance_id', $p->id)->count());
    }

    public function test_change_during_show_requires_approval_before_switching(): void
    {
        ['tech' => $tech, 'manager' => $mgr] = $this->theaterUsers();
        $p = $this->livePerformance(['zh' => 2]);
        $this->seedV1($p->id, 'zh', $tech->id);

        // 技术员发起申请
        $crId = $this->tok('token-tech')->postJson("/api/performances/{$p->id}/caption-change-requests", [
            'language' => 'zh',
            'title' => '中文 v2',
            'content' => '修订后的字幕',
            'reason' => '台词调整',
        ])->assertCreated()->json('request.id');

        // 未批准前：v1 仍 active，v2 为 draft，申请 pending
        $this->assertSame(CaptionScript::STATUS_ACTIVE, CaptionScript::where('version_no', 1)->value('status'));
        $this->assertSame(CaptionScript::STATUS_DRAFT, CaptionScript::where('version_no', 2)->value('status'));
        $this->assertSame(CaptionChangeRequest::STATUS_PENDING, CaptionChangeRequest::find($crId)->status);

        // 技术员无权审批
        $this->tok('token-tech')->postJson("/api/caption-change-requests/{$crId}/review", [
            'approve' => true,
        ])->assertForbidden();

        // 负责人批准
        $this->tok('token-manager')->postJson("/api/caption-change-requests/{$crId}/review", [
            'approve' => true, 'review_note' => '同意',
        ])->assertOk();

        // 批准后：v1 superseded、v2 active、记录切换时间
        $this->assertSame(CaptionScript::STATUS_SUPERSEDED, CaptionScript::where('version_no', 1)->value('status'));
        $this->assertSame(CaptionScript::STATUS_ACTIVE, CaptionScript::where('version_no', 2)->value('status'));
        $cr = CaptionChangeRequest::find($crId);
        $this->assertSame(CaptionChangeRequest::STATUS_APPROVED, $cr->status);
        $this->assertNotNull($cr->switched_at, '必须记录切换时间');
        $this->assertSame($mgr->id, $cr->reviewed_by);
    }

    public function test_rejected_request_keeps_current_version(): void
    {
        ['tech' => $tech, 'manager' => $mgr] = $this->theaterUsers();
        $p = $this->livePerformance(['zh' => 2]);
        $this->seedV1($p->id, 'zh', $tech->id);

        $crId = $this->tok('token-tech')->postJson("/api/performances/{$p->id}/caption-change-requests", [
            'language' => 'zh', 'title' => 'v2', 'content' => '新稿', 'reason' => '测试驳回',
        ])->json('request.id');

        $this->tok('token-manager')->postJson("/api/caption-change-requests/{$crId}/review", [
            'approve' => false, 'review_note' => '时机不合适',
        ])->assertOk();

        $this->assertSame(CaptionScript::STATUS_ACTIVE, CaptionScript::where('version_no', 1)->value('status'));
        $this->assertSame(CaptionScript::STATUS_REJECTED, CaptionScript::where('version_no', 2)->value('status'));
        $this->assertSame(CaptionChangeRequest::STATUS_REJECTED, CaptionChangeRequest::find($crId)->status);
        $this->assertNull(CaptionChangeRequest::find($crId)->switched_at);
    }

    public function test_history_keeps_old_versions_and_files(): void
    {
        ['tech' => $tech, 'manager' => $mgr] = $this->theaterUsers();
        $p = $this->livePerformance(['zh' => 2]);
        $this->seedV1($p->id, 'zh', $tech->id);

        $crId = $this->tok('token-tech')->postJson("/api/performances/{$p->id}/caption-change-requests", [
            'language' => 'zh', 'title' => 'v2', 'content' => '第二版字幕内容', 'reason' => '更新',
        ])->json('request.id');
        $this->tok('token-manager')->postJson("/api/caption-change-requests/{$crId}/review", ['approve' => true])->assertOk();

        // 两个版本都可查
        $history = $this->tok('token-tech')->getJson("/api/performances/{$p->id}/captions/zh/history")
            ->assertOk()->json('scripts');
        $this->assertCount(2, $history);

        // 旧版正文与校验和仍可取
        $v1 = CaptionScript::where('version_no', 1)->first();
        $v2 = CaptionScript::where('version_no', 2)->first();
        $this->assertTrue($v1->storedFileStillIntact(), '旧版文件必须保留且校验一致');
        $this->assertTrue($v2->storedFileStillIntact());
        $this->assertNotSame($v1->checksum, $v2->checksum);

        // 通过 API 也能读到旧版内容
        $this->tok('token-tech')->getJson("/api/captions/scripts/{$v1->id}")
            ->assertOk()
            ->assertJsonPath('version_no', 1)
            ->assertJsonPath('file_intact', true);
    }

    public function test_before_show_technician_can_publish_directly(): void
    {
        ['tech' => $tech] = $this->theaterUsers();
        $p = $this->upcomingPerformance(['zh' => 2]);

        $this->tok('token-tech')->postJson("/api/performances/{$p->id}/captions", [
            'language' => 'zh', 'title' => 'v1', 'content' => '开演前直接发布',
        ])->assertCreated();

        $this->assertSame(CaptionScript::STATUS_ACTIVE, CaptionScript::where('performance_id', $p->id)->value('status'));
    }

    private function seedV1(int $performanceId, string $language, int $techId): CaptionScript
    {
        $content = '初始版本字幕';
        $path = "captions/performance-{$performanceId}/{$language}/v1.txt";
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $content);

        return CaptionScript::create([
            'performance_id' => $performanceId,
            'language' => $language,
            'version_no' => 1,
            'title' => 'v1',
            'content' => $content,
            'file_path' => $path,
            'checksum' => CaptionScript::checksumFor($content),
            'status' => CaptionScript::STATUS_ACTIVE,
            'published_by' => $techId,
            'published_at' => now(),
            'activated_at' => now(),
        ]);
    }

    private function tok(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
