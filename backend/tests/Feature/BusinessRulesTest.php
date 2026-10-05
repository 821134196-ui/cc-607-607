<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Reservation;
use App\Models\Screening;
use App\Models\ScreeningLanguagePool;
use App\Models\Show;
use App\Models\SubtitleSwitchRequest;
use App\Models\SubtitleVersion;
use App\Models\User;
use App\Services\DeviceException;
use App\Services\DeviceService;
use App\Services\ReservationService;
use App\Services\SubtitleException;
use App\Services\SubtitleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 关键业务规则验证：
 *  1. 改签会释放旧占用
 *  2. 设备不会被重复分配（未归还/故障不能分配给下一场）
 *  3. 演出中更换字幕必须经过批准，且历史版本可查
 */
class BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $frontdesk;
    private User $tech;
    private User $manager;
    private DeviceService $deviceService;
    private SubtitleService $subtitleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->frontdesk = User::create(['name' => '前台甲', 'role' => User::ROLE_FRONTDESK]);
        $this->tech = User::create(['name' => '技术员甲', 'role' => User::ROLE_TECHNICIAN]);
        $this->manager = User::create(['name' => '负责人甲', 'role' => User::ROLE_MANAGER]);
        $this->deviceService = app(DeviceService::class);
        $this->subtitleService = app(SubtitleService::class);
    }

    private function makeScreening(array $opts = []): Screening
    {
        static $seq = 0;
        $show = Show::create(['title' => '测试剧目 '.($seq + 1)]);
        $base = Carbon::create(2026, 10, 10, 19, 30)->addDays($seq++);
        $s = Screening::create([
            'show_id' => $show->id,
            'starts_at' => $opts['start'] ?? $base,
            'ends_at' => $opts['end'] ?? $base->copy()->addHours(2),
            'venue' => '测试剧场',
        ]);
        foreach ($opts['languages'] ?? ['zh'] as $lang) {
            ScreeningLanguagePool::create([
                'screening_id' => $s->id,
                'language' => $lang,
                'quota' => $opts['quota'] ?? 2,
            ]);
        }
        return $s->fresh();
    }

    private function makeDevice(string $serial = 'D-01', array $langs = ['zh']): Device
    {
        return Device::create([
            'serial' => $serial,
            'supported_languages' => $langs,
            'status' => Device::STATUS_AVAILABLE,
            'battery_percent' => 90,
        ]);
    }

    private function check(): array
    {
        return [
            'battery_percent' => 85,
            'physical_ok' => true,
            'language_confirmed' => true,
        ];
    }

    // ============ 规则 1：改签释放旧占用 ============

    public function test_booking_respects_quota(): void
    {
        $s1 = $this->makeScreening(['quota' => 1]);
        $service = app(ReservationService::class);

        $service->book([
            'screening_id' => $s1->id, 'language' => 'zh', 'audience_name' => '张三',
        ]);

        // 旧场配额已满（1/1），无法再约
        $this->expectException(\App\Services\ReservationException::class);
        $this->expectExceptionMessage('已约满');
        $service->book([
            'screening_id' => $s1->id, 'language' => 'zh', 'audience_name' => '李四',
        ]);
    }

    public function test_reschedule_frees_then_rebooks(): void
    {
        $s1 = $this->makeScreening(['quota' => 1]);
        $s2 = $this->makeScreening(['quota' => 1]);
        $service = app(ReservationService::class);

        $old = $service->book([
            'screening_id' => $s1->id, 'language' => 'zh', 'audience_name' => '张三',
        ]);
        $this->assertSame(1, $service->availability($s1, 'zh')['reserved']);

        // 改签：旧预约释放，新预约建立
        $new = $service->reschedule($old, [
            'screening_id' => $s2->id, 'language' => 'zh', 'audience_name' => '张三',
        ]);

        // 旧预约状态为 released 且不再占用配额
        $this->assertSame(Reservation::STATUS_RELEASED, $old->fresh()->status);
        $this->assertSame(0, $service->availability($s1, 'zh')['reserved']);
        $this->assertSame(1, $service->availability($s1, 'zh')['remaining']);
        // 新场占用 +1
        $this->assertSame(1, $service->availability($s2, 'zh')['reserved']);
        // 新预约指向旧预约，状态为 booked
        $this->assertSame(Reservation::STATUS_BOOKED, $new->status);
        $this->assertSame($old->id, $new->parent_id);
        $this->assertNotSame($old->code, $new->code);
    }

    public function test_reschedule_rolls_back_when_target_full(): void
    {
        $s1 = $this->makeScreening(['quota' => 1]);
        $s2 = $this->makeScreening(['quota' => 1]);
        $service = app(ReservationService::class);

        $old = $service->book([
            'screening_id' => $s1->id, 'language' => 'zh', 'audience_name' => '张三',
        ]);
        // 先约满目标场
        $service->book([
            'screening_id' => $s2->id, 'language' => 'zh', 'audience_name' => '李四',
        ]);

        try {
            $service->reschedule($old, [
                'screening_id' => $s2->id, 'language' => 'zh', 'audience_name' => '张三',
            ]);
            $this->fail('目标场已满，改签应失败');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('已约满', $e->getMessage());
        }

        // 关键：事务整体回滚，旧预约仍有效、仍占用原场配额
        $this->assertSame(Reservation::STATUS_BOOKED, $old->fresh()->status);
        $this->assertSame(1, $service->availability($s1, 'zh')['reserved']);
        $this->assertSame(1, $service->availability($s2, 'zh')['reserved']);
    }

    public function test_cancel_releases_quota(): void
    {
        $s = $this->makeScreening(['quota' => 2]);
        $service = app(ReservationService::class);
        $r = $service->book([
            'screening_id' => $s->id, 'language' => 'zh', 'audience_name' => '张三',
        ]);
        $service->cancel($r);
        $this->assertSame(Reservation::STATUS_CANCELLED, $r->fresh()->status);
        $this->assertSame(0, $service->availability($s, 'zh')['reserved']);
    }

    // ============ 规则 2：设备不会被重复分配 ============

    public function test_device_cannot_be_double_assigned(): void
    {
        $s = $this->makeScreening(['quota' => 3]);
        $device = $this->makeDevice();

        $r1 = app(ReservationService::class)->book([
            'screening_id' => $s->id, 'language' => 'zh', 'audience_name' => '张三',
        ]);
        $r2 = app(ReservationService::class)->book([
            'screening_id' => $s->id, 'language' => 'zh', 'audience_name' => '李四',
        ]);

        // 第一次发放成功
        $this->deviceService->issue($r1, $device, $this->frontdesk, $this->check());
        $this->assertSame(Device::STATUS_ASSIGNED, $device->fresh()->status);

        // 同一台设备不能发给第二个预约（服务层拦截）
        try {
            $this->deviceService->issue($r2, $device, $this->frontdesk, $this->check());
            $this->fail('未归还设备不应能再次发放');
        } catch (DeviceException $e) {
            $this->assertStringContainsString('不能重复分配', $e->getMessage());
        }
    }

    public function test_database_unique_index_blocks_duplicate_active_assignment(): void
    {
        // 数据库层兜底：即便绕过服务，部分唯一索引也阻止重复未归还记录
        $s = $this->makeScreening(['quota' => 3]);
        $device = $this->makeDevice();
        $r1 = app(ReservationService::class)->book([
            'screening_id' => $s->id, 'language' => 'zh', 'audience_name' => '张三',
        ]);
        $r2 = app(ReservationService::class)->book([
            'screening_id' => $s->id, 'language' => 'zh', 'audience_name' => '李四',
        ]);
        $this->deviceService->issue($r1, $device, $this->frontdesk, $this->check());

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('device_assignments')->insert([
            'reservation_id' => $r2->id,
            'device_id' => $device->id,
            'issued_by' => $this->frontdesk->id,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unreturned_device_cannot_go_to_next_screening(): void
    {
        $s1 = $this->makeScreening(['quota' => 2]);
        $s2 = $this->makeScreening(['quota' => 2]);
        $device = $this->makeDevice();

        $r1 = app(ReservationService::class)->book([
            'screening_id' => $s1->id, 'language' => 'zh', 'audience_name' => '张三',
        ]);
        $assignment = $this->deviceService->issue($r1, $device, $this->frontdesk, $this->check());

        // 下一场的新预约
        $r2 = app(ReservationService::class)->book([
            'screening_id' => $s2->id, 'language' => 'zh', 'audience_name' => '李四',
        ]);

        // 未归还 → 不能分配给下一场
        try {
            $this->deviceService->issue($r2, $device, $this->frontdesk, $this->check());
            $this->fail('未归还设备不能分配给下一场');
        } catch (DeviceException $e) {
            $this->assertStringContainsString('尚未归还', $e->getMessage());
        }
    }

    public function test_faulty_device_is_locked_until_repair(): void
    {
        $s1 = $this->makeScreening(['quota' => 2]);
        $s2 = $this->makeScreening(['quota' => 2]);
        $device = $this->makeDevice();

        $r1 = app(ReservationService::class)->book([
            'screening_id' => $s1->id, 'language' => 'zh', 'audience_name' => '张三',
        ]);
        $assignment = $this->deviceService->issue($r1, $device, $this->frontdesk, $this->check());

        // 散场复检发现故障
        $this->deviceService->returnDevice($assignment, $this->frontdesk, [
            'battery_percent' => 12,
            'physical_ok' => false,
            'language_confirmed' => true,
            'faulty' => true,
            'note' => '屏幕花屏',
        ]);
        $this->assertSame(Device::STATUS_FAULTY, $device->fresh()->status);

        // 下一场不能发放故障机
        $r2 = app(ReservationService::class)->book([
            'screening_id' => $s2->id, 'language' => 'zh', 'audience_name' => '李四',
        ]);
        try {
            $this->deviceService->issue($r2, $device, $this->frontdesk, $this->check());
            $this->fail('故障设备必须被锁定');
        } catch (DeviceException $e) {
            $this->assertStringContainsString('故障', $e->getMessage());
        }

        // 维修登记后恢复可发放
        $this->deviceService->repair($device->fresh());
        $this->assertSame(Device::STATUS_AVAILABLE, $device->fresh()->status);
        $a2 = $this->deviceService->issue($r2, $device->fresh(), $this->frontdesk, $this->check());
        $this->assertNotNull($a2->id);
    }

    public function test_good_return_makes_device_reassignable(): void
    {
        $s1 = $this->makeScreening(['quota' => 2]);
        $s2 = $this->makeScreening(['quota' => 2]);
        $device = $this->makeDevice();

        $r1 = app(ReservationService::class)->book([
            'screening_id' => $s1->id, 'language' => 'zh', 'audience_name' => '张三',
        ]);
        $a1 = $this->deviceService->issue($r1, $device, $this->frontdesk, $this->check());
        $this->deviceService->returnDevice($a1, $this->frontdesk, $this->check() + ['faulty' => false]);

        $this->assertSame(Device::STATUS_AVAILABLE, $device->fresh()->status);
        $r2 = app(ReservationService::class)->book([
            'screening_id' => $s2->id, 'language' => 'zh', 'audience_name' => '李四',
        ]);
        $a2 = $this->deviceService->issue($r2, $device->fresh(), $this->frontdesk, $this->check());
        $this->assertNull($a2->returned_at);
    }

    public function test_issue_rejects_language_mismatch_and_low_battery_and_unconfirmed_checks(): void
    {
        $s = $this->makeScreening(['languages' => ['zh', 'en'], 'quota' => 3]);
        $zhOnly = $this->makeDevice('D-ZH', ['zh']);
        $r = app(ReservationService::class)->book([
            'screening_id' => $s->id, 'language' => 'en', 'audience_name' => 'Tom',
        ]);

        // 语言不支持
        try {
            $this->deviceService->issue($r, $zhOnly, $this->frontdesk, $this->check());
            $this->fail();
        } catch (DeviceException $e) {
            $this->assertStringContainsString('不支持', $e->getMessage());
        }

        $enDevice = $this->makeDevice('D-EN', ['en']);
        // 低电量
        try {
            $this->deviceService->issue($r, $enDevice, $this->frontdesk,
                ['battery_percent' => 10] + $this->check());
            $this->fail();
        } catch (DeviceException $e) {
            $this->assertStringContainsString('电量', $e->getMessage());
        }
        // 未确认语言一致
        try {
            $this->deviceService->issue($r, $enDevice, $this->frontdesk,
                ['language_confirmed' => false] + $this->check());
            $this->fail();
        } catch (DeviceException $e) {
            $this->assertStringContainsString('字幕语言', $e->getMessage());
        }
    }

    // ============ 规则 3：演出中换稿必须批准 + 历史版本可查 ============

    public function test_live_publish_does_not_switch_without_approval(): void
    {
        Carbon::setTestNow('2026-10-10 20:00:00');
        $s = $this->makeScreening([
            'start' => Carbon::create(2026, 10, 10, 19, 30),
            'end' => Carbon::create(2026, 10, 10, 21, 30),
        ]);

        // 演出前发布 v1（用更早的时间模拟）
        Carbon::setTestNow('2026-10-8 10:00:00');
        $v1 = $this->subtitleService->publish($s, 'zh', '正式稿', "1\n00:00:01,000 --> 00:00:02,000\n甲\n", $this->tech);
        Carbon::setTestNow('2026-10-10 20:00:00');

        $this->assertSame($v1->id, $this->subtitleService->currentVersion($s->id, 'zh')?->id);

        // 演出中发布 v2：保存但不切换
        $v2 = $this->subtitleService->publish($s, 'zh', '修订稿', "1\n00:00:01,000 --> 00:00:02,000\n乙\n", $this->tech);
        $this->assertSame($v1->id, $this->subtitleService->currentVersion($s->id, 'zh')?->id);
        $this->assertNotSame($v1->id, $v2->id);
        Carbon::setTestNow();
    }

    public function test_live_switch_requires_approval_and_records_time(): void
    {
        Carbon::setTestNow('2026-10-10 20:00:00');
        $s = $this->makeScreening([
            'start' => Carbon::create(2026, 10, 10, 19, 30),
            'end' => Carbon::create(2026, 10, 10, 21, 30),
        ]);
        Carbon::setTestNow('2026-10-8 10:00:00');
        $v1 = $this->subtitleService->publish($s, 'zh', '正式稿', "甲\n", $this->tech);
        Carbon::setTestNow('2026-10-10 20:00:00');
        $v2 = $this->subtitleService->publish($s, 'zh', '修订稿', "乙\n", $this->tech);

        // 技术员发起申请
        $req = $this->subtitleService->requestSwitch($s, 'zh', $v2, $this->tech, '时间轴修正');
        $this->assertSame(SubtitleSwitchRequest::STATUS_PENDING, $req->status);
        // 申请期间当前稿仍是 v1
        $this->assertSame($v1->id, $this->subtitleService->currentVersion($s->id, 'zh')?->id);

        // 技术员自己不能批准（路由层有角色限制；服务层测试负责人批准才切换）
        Carbon::setTestNow('2026-10-10 20:05:30');
        $approved = $this->subtitleService->approve($req, $this->manager, '同意');
        $this->assertSame(SubtitleSwitchRequest::STATUS_APPROVED, $approved->status);
        $this->assertSame($v2->id, $this->subtitleService->currentVersion($s->id, 'zh')?->id);
        $this->assertSame('2026-10-10 20:05:30', $approved->switched_at->toDateTimeString());
        $this->assertSame($this->manager->id, $approved->decided_by);

        // 不能重复审批
        try {
            $this->subtitleService->approve($approved, $this->manager);
            $this->fail();
        } catch (SubtitleException $e) {
            $this->assertStringContainsString('已处理', $e->getMessage());
        }
        Carbon::setTestNow();
    }

    public function test_rejected_request_keeps_old_version(): void
    {
        Carbon::setTestNow('2026-10-10 20:00:00');
        $s = $this->makeScreening([
            'start' => Carbon::create(2026, 10, 10, 19, 30),
            'end' => Carbon::create(2026, 10, 10, 21, 30),
        ]);
        Carbon::setTestNow('2026-10-8 10:00:00');
        $v1 = $this->subtitleService->publish($s, 'zh', '正式稿', "甲\n", $this->tech);
        Carbon::setTestNow('2026-10-10 20:00:00');
        $v2 = $this->subtitleService->publish($s, 'zh', '修订稿', "乙\n", $this->tech);

        $req = $this->subtitleService->requestSwitch($s, 'zh', $v2, $this->tech, null);
        $this->subtitleService->reject($req, $this->manager, '改动风险大');
        $this->assertSame($v1->id, $this->subtitleService->currentVersion($s->id, 'zh')?->id);
        $this->assertNull($req->fresh()->switched_at);
        Carbon::setTestNow();
    }

    public function test_subtitle_history_is_complete_and_old_files_kept(): void
    {
        Carbon::setTestNow('2026-10-8 10:00:00');
        $s = $this->makeScreening([
            'start' => Carbon::create(2026, 10, 10, 19, 30),
            'end' => Carbon::create(2026, 10, 10, 21, 30),
        ]);
        $v1 = $this->subtitleService->publish($s, 'zh', 'v1 稿', "内容一\n", $this->tech);
        Carbon::setTestNow('2026-10-9 10:00:00');
        $v2 = $this->subtitleService->publish($s, 'zh', 'v2 稿', "内容二\n", $this->tech);
        Carbon::setTestNow('2026-10-10 20:00:00');
        $v3 = $this->subtitleService->publish($s, 'zh', 'v3 稿', "内容三\n", $this->tech);

        $history = $this->subtitleService->history($s->id, 'zh');
        $this->assertCount(3, $history);
        $this->assertSame([1, 2, 3], array_column($history, 'version_no'));
        // 演出前发布的最新稿 v2 为当前（v3 演出中发布未切换）
        $this->assertSame($v2->id, $this->subtitleService->currentVersion($s->id, 'zh')?->id);
        $currentFlags = array_column($history, 'is_current', 'version_no');
        $this->assertTrue($currentFlags[2]);
        $this->assertFalse($currentFlags[1]);
        $this->assertFalse($currentFlags[3]);

        // 三个历史文件都在磁盘上，内容与哈希可查
        foreach ([$v1, $v2, $v3] as $v) {
            $this->assertTrue(\Illuminate\Support\Facades\Storage::exists($v->file_path), "旧文件丢失: {$v->file_path}");
            $this->assertSame(hash('sha256', $v->content), $v->file_hash);
        }
        // 文件路径互不相同（旧文件未被覆盖）
        $paths = [$v1->file_path, $v2->file_path, $v3->file_path];
        $this->assertCount(3, array_unique($paths));
        Carbon::setTestNow();
    }

    public function test_technician_cannot_approve_via_api_and_approve_endpoint_enforces_manager(): void
    {
        // HTTP 层角色校验
        Carbon::setTestNow('2026-10-10 20:00:00');
        $s = $this->makeScreening([
            'start' => Carbon::create(2026, 10, 10, 19, 30),
            'end' => Carbon::create(2026, 10, 10, 21, 30),
        ]);
        Carbon::setTestNow('2026-10-8 10:00:00');
        $v1 = $this->subtitleService->publish($s, 'zh', '正式稿', "甲\n", $this->tech);
        Carbon::setTestNow('2026-10-10 20:00:00');
        $v2 = $this->subtitleService->publish($s, 'zh', '修订稿', "乙\n", $this->tech);
        $req = $this->subtitleService->requestSwitch($s, 'zh', $v2, $this->tech, null);

        // 技术员调审批接口 → 403
        $this->postJson("/api/subtitle-switch/{$req->id}/approve", [], [
            'X-Staff-User-Id' => $this->tech->id,
        ])->assertStatus(403);
        // 未批准前当前稿不变
        $this->assertSame($v1->id, $this->subtitleService->currentVersion($s->id, 'zh')?->id);

        // 负责人调 → 200 且切换
        $this->postJson("/api/subtitle-switch/{$req->id}/approve", [], [
            'X-Staff-User-Id' => $this->manager->id,
        ])->assertStatus(200);
        $this->assertSame($v2->id, $this->subtitleService->currentVersion($s->id, 'zh')?->id);
        Carbon::setTestNow();
    }
}
