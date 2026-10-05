<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTheater;
use Tests\TestCase;

class RebookReleasesCapacityTest extends TestCase
{
    use CreatesTheater, RefreshDatabase;

    public function test_rebook_marks_old_reservation_cancelled_and_frees_capacity(): void
    {
        ['audience' => $aud] = $this->theaterUsers();
        $performance = $this->upcomingPerformance(['zh' => 1, 'en' => 1]);

        // 容量只有 1：先占掉 zh
        $first = $this->bearer($aud->api_token)->postJson('/api/reservations', [
            'performance_id' => $performance->id,
            'language' => 'zh',
            'ticket_code' => $this->ticketCode($performance->id),
        ])->assertCreated()->json('id');

        // zh 已满
        $before = $this->bearer($aud->api_token)->getJson("/api/performances/{$performance->id}")->json();
        $zhBefore = collect($before['languages'])->firstWhere('language', 'zh');
        $this->assertSame(0, $zhBefore['remaining']);

        // 改签 zh -> en：旧预约释放
        $newId = $this->bearer($aud->api_token)->postJson("/api/reservations/{$first}/rebook", [
            'language' => 'en',
            'ticket_code' => $this->ticketCode($performance->id, 'BBBB'),
        ])->assertCreated()->json('id');

        static::assertNotSame($first, $newId);

        $this->assertDatabaseHas('reservations', [
            'id' => $first,
            'status' => Reservation::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $newId,
            'status' => Reservation::STATUS_ACTIVE,
            'language' => 'en',
        ]);

        // 关键：原语言 zh 的容量被释放，剩余重新变为 1
        $perf = $this->bearer($aud->api_token)->getJson("/api/performances/{$performance->id}")->json();
        $zh = collect($perf['languages'])->firstWhere('language', 'zh');
        $en = collect($perf['languages'])->firstWhere('language', 'en');
        $this->assertSame(1, $zh['remaining']);
        $this->assertSame(0, $en['remaining']);
    }

    public function test_rebook_is_atomic_when_target_full(): void
    {
        ['audience' => $aud, 'audience2' => $aud2] = $this->theaterUsers();
        // en 容量仅 1，已被观众2占满
        $performance = $this->upcomingPerformance(['zh' => 2, 'en' => 1]);
        $this->bearer($aud2->api_token)->postJson('/api/reservations', [
            'performance_id' => $performance->id,
            'language' => 'en',
            'ticket_code' => $this->ticketCode($performance->id, 'FFFF'),
        ])->assertCreated();

        $mine = $this->bearer($aud->api_token)->postJson('/api/reservations', [
            'performance_id' => $performance->id,
            'language' => 'zh',
            'ticket_code' => $this->ticketCode($performance->id, 'AAAA'),
        ])->assertCreated()->json('id');

        // 改签至已满的 en：必须失败
        $this->bearer($aud->api_token)->postJson("/api/reservations/{$mine}/rebook", [
            'language' => 'en',
            'ticket_code' => $this->ticketCode($performance->id, 'BBBB'),
        ])->assertStatus(422)->assertJsonValidationErrors('language');

        // 关键断言：改签失败后旧预约仍然有效（没有被错误地释放掉）
        $this->assertDatabaseHas('reservations', [
            'id' => $mine,
            'status' => Reservation::STATUS_ACTIVE,
            'language' => 'zh',
        ]);
    }

    public function test_cancel_frees_capacity_and_allows_rebooking_same_performance(): void
    {
        ['audience' => $aud] = $this->theaterUsers();
        $performance = $this->upcomingPerformance(['zh' => 1]);

        $id = $this->bearer($aud->api_token)->postJson('/api/reservations', [
            'performance_id' => $performance->id,
            'language' => 'zh',
            'ticket_code' => $this->ticketCode($performance->id),
        ])->assertCreated()->json('id');

        $this->bearer($aud->api_token)->postJson("/api/reservations/{$id}/cancel")->assertOk();
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => Reservation::STATUS_CANCELLED]);

        // 释放后可以重新预约同一场
        $this->bearer($aud->api_token)->postJson('/api/reservations', [
            'performance_id' => $performance->id,
            'language' => 'zh',
            'ticket_code' => $this->ticketCode($performance->id, 'CCCC'),
        ])->assertCreated();
    }

    public function test_capacity_cannot_be_oversubscribed(): void
    {
        ['audience' => $a1, 'audience2' => $a2] = $this->theaterUsers();
        $performance = $this->upcomingPerformance(['zh' => 1]);

        $this->bearer($a1->api_token)->postJson('/api/reservations', [
            'performance_id' => $performance->id,
            'language' => 'zh',
            'ticket_code' => $this->ticketCode($performance->id, 'AAAA'),
        ])->assertCreated();

        $this->bearer($a2->api_token)->postJson('/api/reservations', [
            'performance_id' => $performance->id,
            'language' => 'zh',
            'ticket_code' => $this->ticketCode($performance->id, 'BBBB'),
        ])->assertStatus(422)->assertJsonValidationErrors('language');
    }

    private function bearer(?string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
