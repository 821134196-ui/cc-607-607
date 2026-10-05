<?php

namespace App\Services;

use App\Models\Ticket;

/**
 * 本地模拟票务系统接口。
 * 真实环境通过 HTTP 调用票务方；这里直接查 tickets 表。
 */
class TicketService
{
    /**
     * 校验票是否有效且属于该场次。
     *
     * @throws TicketException
     */
    public function verify(string $code, int $screeningId): Ticket
    {
        $ticket = Ticket::where('code', $code)->first();

        if (!$ticket) {
            throw new TicketException('票务系统查无此票：'.$code);
        }
        if ($ticket->screening_id !== $screeningId) {
            throw new TicketException("票 {$code} 不属于所选场次");
        }
        if ($ticket->status === Ticket::STATUS_REFUNDED) {
            throw new TicketException("票 {$code} 已退票");
        }
        if ($ticket->status === Ticket::STATUS_USED) {
            throw new TicketException("票 {$code} 已被使用");
        }

        return $ticket;
    }

    public function markUsed(Ticket $ticket): void
    {
        $ticket->update(['status' => Ticket::STATUS_USED]);
    }
}
