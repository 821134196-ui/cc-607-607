<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTheater;
use Tests\TestCase;

class DeviceAllocationTest extends TestCase
{
    use CreatesTheater, RefreshDatabase;

    private function reserve($audToken, int $performanceId, string $language, string $tail = 'AAAA'): int
    {
        return $this->withHeader('Authorization', 'Bearer '.$audToken)
            ->postJson('/api/reservations', [
                'performance_id' => $performanceId,
                'language' => $language,
                'ticket_code' => "T-{$performanceId}-{$tail}",
            ])->assertCreated()->json('id');
    }

    public function test_same_device_cannot_be_issued_twice_while_unreturned(): void
    {
        ['audience' => $a1, 'audience2' => $a2, 'desk' => $desk] = $this->theaterUsers();
        $p1 = $this->upcomingPerformance(['en' => 5]);
        $p2 = $this->upcomingPerformance(['en' => 5]); // 下一场
        $this->device('CAP-001', Device::STATUS_AVAILABLE, 100);

        $r1 = $this->reserve($a1->api_token, $p1->id, 'en', 'AAAA');
        $r2 = $this->reserve($a2->api_token, $p2->id, 'en', 'BBBB');

        // 第一次发放成功
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r1, 'serial' => 'CAP-001', 'battery' => 90, 'language' => 'en',
        ])->assertCreated();

        $this->assertSame(Device::STATUS_ASSIGNED, Device::where('serial', 'CAP-001')->value('status'));

        // 设备未归还，试图发给下一场另一观众 → 必须拒绝
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r2, 'serial' => 'CAP-001', 'battery' => 80, 'language' => 'en',
        ])->assertStatus(422)->assertJsonValidationErrors('serial');

        // 只能存在一条 issued 记录
        $this->assertSame(1, DeviceAssignment::where('status', DeviceAssignment::STATUS_ISSUED)->count());
    }

    public function test_faulty_device_is_quarantined_and_not_allocatable_next_performance(): void
    {
        ['audience' => $a1, 'audience2' => $a2, 'desk' => $desk] = $this->theaterUsers();
        $p1 = $this->upcomingPerformance(['en' => 5]);
        $p2 = $this->upcomingPerformance(['en' => 5]);
        $this->device('CAP-009', Device::STATUS_AVAILABLE, 95);

        $r1 = $this->reserve($a1->api_token, $p1->id, 'en', 'AAAA');
        $r2 = $this->reserve($a2->api_token, $p2->id, 'en', 'BBBB');

        $assignmentId = $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r1, 'serial' => 'CAP-009', 'battery' => 90, 'language' => 'en',
        ])->json('id');

        // 散场复检发现故障
        $this->tok('token-desk')->postJson("/api/desk/assignments/{$assignmentId}/return", [
            'battery' => 20, 'check_result' => 'fault', 'fault_notes' => '屏幕闪烁',
        ])->assertOk();

        $this->assertSame(Device::STATUS_MAINTENANCE, Device::where('serial', 'CAP-009')->value('status'));

        // 故障设备不能分配给下一场
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r2, 'serial' => 'CAP-009', 'battery' => 90, 'language' => 'en',
        ])->assertStatus(422)->assertJsonValidationErrors('serial');
    }

    public function test_healthy_return_makes_device_available_again(): void
    {
        ['audience' => $a1, 'audience2' => $a2, 'desk' => $desk] = $this->theaterUsers();
        $p1 = $this->upcomingPerformance(['en' => 5]);
        $p2 = $this->upcomingPerformance(['en' => 5]);
        $this->device('CAP-010', Device::STATUS_AVAILABLE, 95);

        $r1 = $this->reserve($a1->api_token, $p1->id, 'en', 'AAAA');
        $r2 = $this->reserve($a2->api_token, $p2->id, 'en', 'BBBB');

        $assignmentId = $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r1, 'serial' => 'CAP-010', 'battery' => 90, 'language' => 'en',
        ])->json('id');

        // 复检正常 → 回到 available
        $this->tok('token-desk')->postJson("/api/desk/assignments/{$assignmentId}/return", [
            'battery' => 85, 'check_result' => 'ok',
        ])->assertOk();

        $this->assertSame(Device::STATUS_AVAILABLE, Device::where('serial', 'CAP-010')->value('status'));

        // 可以发给下一场
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r2, 'serial' => 'CAP-010', 'battery' => 85, 'language' => 'en',
        ])->assertCreated();
    }

    public function test_issue_validates_serial_battery_and_language(): void
    {
        ['audience' => $a1, 'desk' => $desk] = $this->theaterUsers();
        $p = $this->upcomingPerformance(['zh' => 3, 'en' => 3]);
        $this->device('CAP-100', Device::STATUS_AVAILABLE, 30); // 低电量
        $this->device('CAP-101', Device::STATUS_MAINTENANCE, 90, 'fault');

        $r = $this->reserve($a1->api_token, $p->id, 'en', 'AAAA');

        // 语言不匹配
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r, 'serial' => 'CAP-200', 'battery' => 90, 'language' => 'zh',
        ])->assertStatus(422)->assertJsonValidationErrors('language');

        // 编号不存在
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r, 'serial' => 'NOPE', 'battery' => 90, 'language' => 'en',
        ])->assertStatus(422)->assertJsonValidationErrors('serial');

        // 电量不足
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r, 'serial' => 'CAP-100', 'battery' => 30, 'language' => 'en',
        ])->assertStatus(422)->assertJsonValidationErrors('battery');

        // 维修中设备
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r, 'serial' => 'CAP-101', 'battery' => 90, 'language' => 'en',
        ])->assertStatus(422)->assertJsonValidationErrors('serial');

        $this->assertSame(0, DeviceAssignment::count());
    }

    public function test_missing_device_is_locked_out_until_released(): void
    {
        ['audience' => $a1, 'audience2' => $a2, 'desk' => $desk] = $this->theaterUsers();
        $p1 = $this->upcomingPerformance(['en' => 5]);
        $p2 = $this->upcomingPerformance(['en' => 5]);
        $this->device('CAP-020', Device::STATUS_AVAILABLE, 95);

        $r1 = $this->reserve($a1->api_token, $p1->id, 'en', 'AAAA');
        $r2 = $this->reserve($a2->api_token, $p2->id, 'en', 'BBBB');

        $assignmentId = $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r1, 'serial' => 'CAP-020', 'battery' => 90, 'language' => 'en',
        ])->json('id');

        $this->tok('token-desk')->postJson("/api/desk/assignments/{$assignmentId}/missing")->assertOk();
        $this->assertSame(Reservation::STATUS_NOSHOW, Reservation::find($r1)->status);
        $this->assertSame(Device::STATUS_MAINTENANCE, Device::where('serial', 'CAP-020')->value('status'));

        // 未归还设备不能发给下一场
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r2, 'serial' => 'CAP-020', 'battery' => 90, 'language' => 'en',
        ])->assertStatus(422);
    }

    public function test_technician_can_release_maintenance_device_back_to_pool(): void
    {
        ['audience2' => $a2, 'desk' => $desk, 'tech' => $tech] = $this->theaterUsers();
        $p = $this->upcomingPerformance(['en' => 5]);
        $device = $this->device('CAP-030', Device::STATUS_MAINTENANCE, 10, 'fault');
        $device->update(['fault_notes' => '屏幕花屏']);

        $r = $this->reserve($a2->api_token, $p->id, 'en', 'BBBB');

        // 维修中不能发放
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r, 'serial' => 'CAP-030', 'battery' => 90, 'language' => 'en',
        ])->assertStatus(422);

        // 前台无权放行；技术员复检通过后放行
        $this->tok('token-desk')->postJson('/api/desk/devices/'.$device->id.'/release', ['battery' => 96])
            ->assertForbidden();

        $this->tok('token-tech')->postJson('/api/desk/devices/'.$device->id.'/release', ['battery' => 96])
            ->assertOk();

        $this->assertSame(Device::STATUS_AVAILABLE, Device::where('serial', 'CAP-030')->value('status'));

        // 放行后可以正常发放
        $this->tok('token-desk')->postJson('/api/desk/assignments', [
            'reservation_id' => $r, 'serial' => 'CAP-030', 'battery' => 96, 'language' => 'en',
        ])->assertCreated();
    }

    private function tok(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
