<?php

namespace App\Services;

use App\Models\Performance;

/**
 * 票务接口的本地模拟实现。
 *
 * 生产环境这里会对接剧场票务系统；当前为本地模拟：
 * 约定票号规则 "T-<演出ID>-<任意4位>" 即视为该场有效票，返回座位号。
 */
class TicketService
{
    /**
     * @return array{valid: bool, seat: ?string, message: string}
     */
    public function verify(string $ticketCode, Performance $performance): array
    {
        $code = trim(strtoupper($ticketCode));
        $prefix = 'T-'.$performance->id.'-';

        if (! str_starts_with($code, $prefix)) {
            return ['valid' => false, 'seat' => null, 'message' => '票号与所选场次不匹配'];
        }

        $tail = substr($code, strlen($prefix));
        if (! preg_match('/^[A-Z0-9]{4}$/', $tail)) {
            return ['valid' => false, 'seat' => null, 'message' => '票号格式无效'];
        }

        // 模拟票务系统返回座位
        $seat = 'A'.hexdec(substr($tail, 0, 2)).'排'.((hexdec(substr($tail, 2, 2)) % 20) + 1).'座';

        return ['valid' => true, 'seat' => $seat, 'message' => '验票通过'];
    }
}
