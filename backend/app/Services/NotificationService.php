<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\User;

/**
 * 本地模拟通知服务：真实环境应调用短信/PUSH 网关，
 * 这里把通知落库到 notifications_log，供后台页面展示。
 */
class NotificationService
{
    public function send(?User $user, string $channel, string $to, string $subject, string $body, array $context = []): NotificationLog
    {
        return NotificationLog::create([
            'user_id' => $user?->id,
            'channel' => $channel,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'context' => $context,
            'sent_at' => now(),
        ]);
    }

    public function sms(?User $user, string $to, string $subject, string $body, array $context = []): NotificationLog
    {
        return $this->send($user, 'sms', $to, $subject, $body, $context);
    }

    public function push(?User $user, string $to, string $subject, string $body, array $context = []): NotificationLog
    {
        return $this->send($user, 'push', $to, $subject, $body, $context);
    }
}
