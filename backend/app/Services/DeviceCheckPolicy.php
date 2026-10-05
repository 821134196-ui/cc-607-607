<?php

namespace App\Services;

/**
 * 设备检查阈值策略。
 */
class DeviceCheckPolicy
{
    /** 发放设备允许的最低电量（百分比） */
    public const MIN_ISSUE_BATTERY = 40;
}
