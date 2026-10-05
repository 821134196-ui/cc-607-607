<?php

namespace App\Services;

use App\Models\Performance;
use App\Models\PerformanceLanguage;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly NotificationService $notifier,
    ) {}

    /**
     * 某场某语言的可预约数量。
     */
    public function availability(Performance $performance, string $language): array
    {
        $pl = PerformanceLanguage::where('performance_id', $performance->id)
            ->where('language', $language)
            ->first();

        if (! $pl) {
            return ['available' => false, 'language' => $language, 'capacity' => 0, 'remaining' => 0, 'open' => 0];
        }

        $open = $this->countOpen($performance->id, $language);

        return [
            'available' => true,
            'language' => $language,
            'capacity' => $pl->capacity,
            'open' => $open,
            'remaining' => max(0, $pl->capacity - $open),
        ];
    }

    /**
     * 观众预约。容量校验在事务+行锁内完成，防止并发超售。
     */
    public function book(User $user, int $performanceId, string $language, string $ticketCode, ?string $phone = null, ?string $contactName = null): Reservation
    {
        return DB::transaction(function () use ($user, $performanceId, $language, $ticketCode, $phone, $contactName) {
            $performance = Performance::where('id', $performanceId)->lockForUpdate()->firstOrFail();
            $pl = $this->lockLanguageRow($performanceId, $language);

            $this->assertPerformanceOpen($performance);

            // 模拟票务接口验票
            $ticket = $this->tickets->verify($ticketCode, $performance);
            if (! $ticket['valid']) {
                throw ValidationException::withMessages(['ticket_code' => $ticket['message']]);
            }

            // 同一场次一人只能有一条未完结预约
            $existing = Reservation::where('user_id', $user->id)
                ->where('performance_id', $performanceId)
                ->whereIn('status', Reservation::OPEN_STATUSES)
                ->first();
            if ($existing) {
                throw ValidationException::withMessages(['performance_id' => '您已预约本场演出，请勿重复预约']);
            }

            $open = $this->countOpen($performanceId, $language);
            if ($open >= $pl->capacity) {
                throw ValidationException::withMessages(['language' => '该语言设备已约满']);
            }

            $reservation = $this->createReservation($user, $performance, $language, $phone, $contactName, 1);

            $this->notifier->sms(
                $phone ?: 'guest-'.$user->id,
                "预约成功：《{$performance->title}》{$language} 字幕设备，凭票号 {$ticketCode}（{$ticket['seat']}）到场领取。",
                Reservation::class,
                $reservation->id,
            );

            return $reservation;
        });
    }

    /**
     * 改签：在同一个数据库事务里把原预约标记 cancelled（释放占用），再建立新预约。
     * 要么全部成功，要么全部回滚——绝不会出现旧占用未释放、新预约已建立的情况。
     *
     * @param  array{performance_id?: int, language?: string, ticket_code?: string, phone?: string, contact_name?: string}  $changes
     */
    public function rebook(Reservation $reservation, array $changes): Reservation
    {
        $statusLabels = [
            Reservation::STATUS_ACTIVE => '待领取',
            Reservation::STATUS_ISSUED => '已发放',
            Reservation::STATUS_RETURNED => '已归还',
            Reservation::STATUS_CANCELLED => '已取消',
            Reservation::STATUS_NOSHOW => '未归还',
        ];
        if ($reservation->status !== Reservation::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'reservation' => '当前预约状态（'.$statusLabels[$reservation->status].'）不可改签；已发放设备请先到前台归还。',
            ]);
        }

        $newPerformanceId = (int) ($changes['performance_id'] ?? $reservation->performance_id);
        $newLanguage = $changes['language'] ?? $reservation->language;
        $ticketCode = $changes['ticket_code'] ?? null;
        $phone = $changes['phone'] ?? $reservation->contact_phone;
        $contactName = $changes['contact_name'] ?? $reservation->contact_name;

        // 目标场次/语言与原预约完全一致则无意义
        if ($newPerformanceId === (int) $reservation->performance_id && $newLanguage === $reservation->language) {
            throw ValidationException::withMessages(['language' => '改签目标与当前预约相同']);
        }

        return DB::transaction(function () use ($reservation, $newPerformanceId, $newLanguage, $ticketCode, $phone, $contactName) {
            // 1) 锁定并校验新场次容量
            $newPerformance = Performance::where('id', $newPerformanceId)->lockForUpdate()->firstOrFail();
            $pl = $this->lockLanguageRow($newPerformanceId, $newLanguage);
            $this->assertPerformanceOpen($newPerformance);

            if ($ticketCode) {
                $ticket = $this->tickets->verify($ticketCode, $newPerformance);
                if (! $ticket['valid']) {
                    throw ValidationException::withMessages(['ticket_code' => $ticket['message']]);
                }
            }

            $open = $this->countOpen($newPerformanceId, $newLanguage);
            if ($open >= $pl->capacity) {
                throw ValidationException::withMessages(['language' => '目标场次该语言设备已约满']);
            }

            // 2) 锁定原预约行，确认仍可改签，然后释放原占用
            $old = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();
            if ($old->status !== Reservation::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['reservation' => '预约状态已变化，请刷新后重试']);
            }

            $old->update([
                'status' => Reservation::STATUS_CANCELLED,
                'cancel_reason' => "改签至 场次#{$newPerformanceId} / {$newLanguage}",
                'cancelled_at' => now(),
            ]);

            // 3) 同事务建立新预约（序号递增，保留改签轨迹）
            $newSeq = (int) Reservation::where('user_id', $old->user_id)
                ->where('performance_id', $newPerformanceId)
                ->max('seq') + 1;

            try {
                $newReservation = $this->createReservation(
                    $old->user, $newPerformance, $newLanguage, $phone, $contactName, $newSeq,
                );
            } catch (QueryException $e) {
                // 部分唯一索引兜底：并发下重复占用 → 回滚整个事务，旧预约也不会丢
                throw ValidationException::withMessages(['reservation' => '改签失败，请重试（'.$e->getCode().'）']);
            }

            $this->notifier->sms(
                $phone ?: 'guest-'.$old->user_id,
                "改签成功：原预约已释放，新预约为《{$newPerformance->title}》{$newLanguage} 字幕设备。",
                Reservation::class,
                $newReservation->id,
            );

            return $newReservation;
        });
    }

    public function cancel(Reservation $reservation, ?string $reason = null): void
    {
        if ($reservation->status !== Reservation::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['reservation' => '仅待领取的预约可以取消']);
        }

        DB::transaction(function () use ($reservation, $reason) {
            $locked = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'status' => Reservation::STATUS_CANCELLED,
                'cancel_reason' => $reason ?? '观众取消',
                'cancelled_at' => now(),
            ]);
        });
    }

    private function createReservation(User $user, Performance $performance, string $language, ?string $phone, ?string $contactName, int $seq): Reservation
    {
        try {
            return Reservation::create([
                'user_id' => $user->id,
                'performance_id' => $performance->id,
                'language' => $language,
                'status' => Reservation::STATUS_ACTIVE,
                'contact_name' => $contactName ?? $user->name,
                'contact_phone' => $phone,
                'seq' => $seq,
            ]);
        } catch (QueryException $e) {
            // 命中部分唯一索引 reservations_open_unique = 重复占用
            throw ValidationException::withMessages(['reservation' => '您已有该场演出的未完结预约，不能重复占用']);
        }
    }

    private function lockLanguageRow(int $performanceId, string $language): PerformanceLanguage
    {
        $pl = PerformanceLanguage::where('performance_id', $performanceId)
            ->where('language', $language)
            ->lockForUpdate()
            ->first();

        if (! $pl) {
            throw ValidationException::withMessages(['language' => '本场演出不提供该字幕语言']);
        }

        return $pl;
    }

    private function countOpen(int $performanceId, string $language): int
    {
        return Reservation::where('performance_id', $performanceId)
            ->where('language', $language)
            ->whereIn('status', Reservation::OPEN_STATUSES)
            ->count();
    }

    private function assertPerformanceOpen(Performance $performance): void
    {
        if ($performance->hasStarted()) {
            throw ValidationException::withMessages(['performance_id' => '该场演出已开演，无法预约或改签']);
        }
    }
}
