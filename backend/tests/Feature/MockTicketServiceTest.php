<?php

namespace Tests\Feature;

use App\Models\Screening;
use App\Models\ScreeningLanguagePool;
use App\Models\Show;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ReservationException;
use App\Services\ReservationService;
use App\Services\TicketException;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 本地模拟票务接口：票号必须存在、属于该场次、未退未用；
 * 设备发放后票标记为已使用，不能凭同一张票再次预约。
 */
class MockTicketServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeScreeningWithTicket(): array
    {
        $show = Show::create(['title' => '票务测试剧']);
        $s = Screening::create([
            'show_id' => $show->id,
            'starts_at' => now()->addDay()->setTime(19, 30),
            'ends_at' => now()->addDay()->setTime(21, 30),
            'venue' => '剧场',
        ]);
        ScreeningLanguagePool::create(['screening_id' => $s->id, 'language' => 'zh', 'quota' => 5]);
        $t = Ticket::create(['code' => 'TT-1', 'screening_id' => $s->id, 'seat' => '1排1座', 'status' => 'valid']);
        return [$s, $t];
    }

    public function test_valid_ticket_can_book(): void
    {
        [$s, $t] = $this->makeScreeningWithTicket();
        $r = app(ReservationService::class)->book([
            'screening_id' => $s->id,
            'language' => 'zh',
            'audience_name' => '持票观众',
            'ticket_code' => 'TT-1',
        ]);
        $this->assertSame($t->id, $r->ticket_id);
    }

    public function test_unknown_and_wrong_screening_and_used_tickets_rejected(): void
    {
        [$s, $t] = $this->makeScreeningWithTicket();
        $service = app(ReservationService::class);

        try {
            $service->book(['screening_id' => $s->id, 'language' => 'zh', 'audience_name' => 'a', 'ticket_code' => 'NOPE']);
            $this->fail();
        } catch (TicketException $e) {
            $this->assertStringContainsString('查无此票', $e->getMessage());
        }

        // 其他场次
        $show2 = Show::create(['title' => '另一部剧']);
        $s2 = Screening::create([
            'show_id' => $show2->id,
            'starts_at' => now()->addDays(2)->setTime(19, 30),
            'ends_at' => now()->addDays(2)->setTime(21, 0),
            'venue' => 'B',
        ]);
        ScreeningLanguagePool::create(['screening_id' => $s2->id, 'language' => 'zh', 'quota' => 5]);

        try {
            $service->book(['screening_id' => $s2->id, 'language' => 'zh', 'audience_name' => 'a', 'ticket_code' => 'TT-1']);
            $this->fail();
        } catch (TicketException $e) {
            $this->assertStringContainsString('不属于所选场次', $e->getMessage());
        }

        // 标记已使用后不能再约
        app(TicketService::class)->markUsed($t);
        $this->expectException(TicketException::class);
        $this->expectExceptionMessage('已被使用');
        $service->book(['screening_id' => $s->id, 'language' => 'zh', 'audience_name' => 'b', 'ticket_code' => 'TT-1']);
    }

    public function test_refunded_ticket_rejected(): void
    {
        [$s, $t] = $this->makeScreeningWithTicket();
        $t->update(['status' => Ticket::STATUS_REFUNDED]);
        $this->expectException(TicketException::class);
        $this->expectExceptionMessage('已退票');
        app(ReservationService::class)->book([
            'screening_id' => $s->id, 'language' => 'zh', 'audience_name' => 'a', 'ticket_code' => 'TT-1',
        ]);
    }
}
