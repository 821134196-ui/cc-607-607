<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Screening;
use App\Models\ScreeningLanguagePool;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationException extends \RuntimeException
{
}

class ReservationService
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly NotificationService $notifier,
    ) {}

    /**
     * 场次 × 语言的可预约数量。
     */
    public function availability(Screening $screening, string $language): array
    {
        $pool = ScreeningLanguagePool::where('screening_id', $screening->id)
            ->where('language', $language)
            ->first();

        if (!$pool) {
            return [
                'language' => $language,
                'available' => false,
                'quota' => 0,
                'reserved' => 0,
                'remaining' => 0,
            ];
        }

        $reserved = $this->countOccupying($screening->id, $language);

        return [
            'language' => $language,
            'available' => true,
            'quota' => $pool->quota,
            'reserved' => $reserved,
            'remaining' => max(0, $pool->quota - $reserved),
        ];
    }

    public function availabilityForAllLanguages(Screening $screening): array
    {
        return $screening->languagePools
            ->map(fn (ScreeningLanguagePool $p) => $this->availability($screening, $p->language))
            ->values()
            ->all();
    }

    private function countOccupying(int $screeningId, string $language): int
    {
        return Reservation::where('screening_id', $screeningId)
            ->where('language', $language)
            ->whereIn('status', Reservation::OCCUPYING_STATUSES)
            ->count();
    }

    /**
     * 预约（或改签后新建）。在单个写事务内检查容量，避免超额。
     *
     * @param array{screening_id:int,language:string,audience_name:string,audience_phone?:?string,ticket_code?:?string,parent_id?:?int} $data
     */
    public function book(array $data, ?User $audience = null): Reservation
    {
        return DB::transaction(function () use ($data, $audience) {
            $screening = Screening::with('languagePools')->lockForUpdate()->find($data['screening_id']);
            if (!$screening) {
                throw new ReservationException('所选场次不存在');
            }
            if ($screening->hasEnded()) {
                throw new ReservationException('该场次已经结束，无法预约');
            }

            $info = $this->availability($screening, $data['language']);
            if (!$info['available']) {
                throw new ReservationException("该场次不提供 {$data['language']} 语言字幕");
            }
            // 事务内重新统计占用（SQLite 写事务天然串行化，防止并发超额）
            $reserved = $this->countOccupying($screening->id, $data['language']);
            if ($reserved >= $info['quota']) {
                throw new ReservationException('该语言设备已约满，请选择其他场次或语言');
            }

            $ticketId = null;
            if (!empty($data['ticket_code'])) {
                $ticket = $this->tickets->verify($data['ticket_code'], $screening->id);
                // 同一有效票不能同时挂在两个占用中的预约上（一票一设备）
                $alreadyHeld = Reservation::where('ticket_id', $ticket->id)
                    ->whereIn('status', Reservation::OCCUPYING_STATUSES)
                    ->exists();
                if ($alreadyHeld) {
                    throw new ReservationException("票 {$ticket->code} 已有进行中的预约，不能重复使用");
                }
                $ticketId = $ticket->id;
            }

            $reservation = Reservation::create([
                'code' => 'R'.strtoupper(Str::random(9)),
                'screening_id' => $screening->id,
                'audience_id' => $audience?->id,
                'audience_name' => $data['audience_name'],
                'audience_phone' => $data['audience_phone'] ?? null,
                'language' => $data['language'],
                'status' => Reservation::STATUS_BOOKED,
                'parent_id' => $data['parent_id'] ?? null,
                'ticket_id' => $ticketId,
            ]);

            $this->notifier->sms(
                $audience,
                $data['audience_phone'] ?? '现场观众',
                '预约成功',
                "{$screening->show?->title} 场次（{$screening->starts_at->format('n月j日 H:i')}）".
                " {$data['language']} 字幕设备预约成功，凭证号 {$reservation->code}",
                ['reservation_code' => $reservation->code]
            );

            return $reservation;
        }, 3);
    }

    /**
     * 改签：先释放原预约占用，再按新场次/语言建立预约。
     * 新旧在同一事务内完成：释放成功才新建，新场次满员时原预约仍然有效（整体回滚）。
     *
     * @param array{screening_id:int,language:string,audience_name?:string,audience_phone?:?string,ticket_code?:?string} $data
     */
    public function reschedule(Reservation $old, array $data, ?User $audience = null): Reservation
    {
        return DB::transaction(function () use ($old, $data, $audience) {
            $old = Reservation::lockForUpdate()->find($old->id);

            if (!$old->occupiesQuota()) {
                throw new ReservationException('当前预约状态不可改签（状态：'.$old->status.'）');
            }
            if ($old->activeAssignment()->exists()) {
                throw new ReservationException('设备已发放，不能在线改签，请到前台办理');
            }

            // —— 释放原预约占用 ——
            $old->update([
                'status' => Reservation::STATUS_RELEASED,
                'release_reason' => '改签：转至新场次预约',
            ]);

            // —— 新建预约（容量检查在 book() 的事务内，失败则整个回滚，旧占用恢复）——
            $new = $this->book([
                'screening_id' => $data['screening_id'],
                'language' => $data['language'],
                'audience_name' => $data['audience_name'] ?? $old->audience_name,
                'audience_phone' => $data['audience_phone'] ?? $old->audience_phone,
                'ticket_code' => $data['ticket_code'] ?? null,
                'parent_id' => $old->id,
            ], $audience);

            $old->update(['release_reason' => "改签：新凭证 {$new->code}"]);

            return $new;
        }, 3);
    }

    public function cancel(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $reservation = Reservation::lockForUpdate()->find($reservation->id);
            if ($reservation->status === Reservation::STATUS_CHECKED_IN) {
                throw new ReservationException('设备已发放，请先归还再取消');
            }
            if (!$reservation->occupiesQuota()) {
                throw new ReservationException('该预约无需取消（状态：'.$reservation->status.'）');
            }
            $reservation->update(['status' => Reservation::STATUS_CANCELLED]);
        }, 3);
    }
}
