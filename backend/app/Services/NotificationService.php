<?php

namespace App\Services;

use App\Models\NotificationLog;

/**
 * 通知接口的本地模拟实现（短信/邮件/推送）。
 * 不真正外发，只落库 notification_logs，便于后台核查与测试断言。
 */
class NotificationService
{
    public function send(string $channel, string $to, string $body, ?string $subject = null, ?string $relatedType = null, ?int $relatedId = null): NotificationLog
    {
        return NotificationLog::create([
            'channel' => $channel,
            'to_address' => $to,
            'subject' => $subject,
            'body' => $body,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
        ]);
    }

    public function sms(string $to, string $body, ?string $relatedType = null, ?int $relatedId = null): NotificationLog
    {
        return $this->send('sms', $to, $body, null, $relatedType, $relatedId);
    }

    public function email(string $to, string $subject, string $body, ?string $relatedType = null, ?int $relatedId = null): NotificationLog
    {
        return $this->send('email', $to, $body, $subject, $relatedType, $relatedId);
    }
}
