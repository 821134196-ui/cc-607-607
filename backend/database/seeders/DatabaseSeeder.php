<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\Screening;
use App\Models\ScreeningLanguagePool;
use App\Models\Show;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // —— 员工 ——
        $frontdeskA = User::create(['name' => '前台·小周', 'role' => User::ROLE_FRONTDESK, 'phone' => '13800000001']);
        $frontdeskB = User::create(['name' => '前台·小陈', 'role' => User::ROLE_FRONTDESK, 'phone' => '13800000002']);
        $techA = User::create(['name' => '字幕技术员·林工', 'role' => User::ROLE_TECHNICIAN, 'phone' => '13800000003']);
        $techB = User::create(['name' => '字幕技术员·顾工', 'role' => User::ROLE_TECHNICIAN, 'phone' => '13800000004']);
        User::create(['name' => '演出负责人·方经理', 'role' => User::ROLE_MANAGER, 'phone' => '13800000005']);

        // —— 剧目与场次 ——
        $hamlet = Show::create([
            'title' => '《哈姆雷特》',
            'description' => '莎士比亚悲剧，时长约 120 分钟',
        ]);
        $midsummer = Show::create([
            'title' => '《仲夏夜之梦》',
            'description' => '莎士比亚喜剧，时长约 100 分钟',
        ]);

        $now = Carbon::now();

        // 场次1：今晚未开演（观众预约主场景）
        $s1 = Screening::create([
            'show_id' => $hamlet->id,
            'starts_at' => $now->copy()->setTime(19, 30),
            'ends_at' => $now->copy()->setTime(21, 30),
            'venue' => '大剧场',
        ]);
        // 若 19:30 已过（深夜演示），顺延到明天
        if ($s1->starts_at->lessThan($now)) {
            $s1->update([
                'starts_at' => $now->copy()->addDay()->setTime(19, 30),
                'ends_at' => $now->copy()->addDay()->setTime(21, 30),
            ]);
        }

        // 场次2：明天下午
        $s2 = Screening::create([
            'show_id' => $hamlet->id,
            'starts_at' => $now->copy()->addDay()->setTime(14, 0),
            'ends_at' => $now->copy()->addDay()->setTime(16, 0),
            'venue' => '大剧场',
        ]);

        // 场次3：后天晚上
        $s3 = Screening::create([
            'show_id' => $midsummer->id,
            'starts_at' => $now->copy()->addDays(2)->setTime(19, 30),
            'ends_at' => $now->copy()->addDays(2)->setTime(21, 10),
            'venue' => '小剧场',
        ]);

        foreach ([$s1, $s2, $s3] as $s) {
            ScreeningLanguagePool::create(['screening_id' => $s->id, 'language' => 'zh', 'quota' => 6]);
            ScreeningLanguagePool::create(['screening_id' => $s->id, 'language' => 'en', 'quota' => 4]);
            ScreeningLanguagePool::create(['screening_id' => $s->id, 'language' => 'ja', 'quota' => 2]);
        }

        // —— 设备（编号 D-01…D-10）——
        $devices = [
            ['D-01', ['zh', 'en'], 95],
            ['D-02', ['zh', 'en'], 88],
            ['D-03', ['zh', 'en'], 76],
            ['D-04', ['zh', 'en', 'ja'], 91],
            ['D-05', ['zh', 'en', 'ja'], 84],
            ['D-06', ['zh', 'ja'], 70],
            ['D-07', ['zh'], 62],
            ['D-08', ['zh', 'en'], 55],
            ['D-09', ['en', 'ja'], 90],
            ['D-10', ['zh', 'en', 'ja'], 100],
        ];
        foreach ($devices as [$serial, $langs, $battery]) {
            Device::create([
                'serial' => $serial,
                'label' => '字幕接收器',
                'supported_languages' => $langs,
                'status' => Device::STATUS_AVAILABLE,
                'battery_percent' => $battery,
            ]);
        }

        // —— 模拟票务：每场 8 张有效票 ——
        foreach ([$s1, $s2, $s3] as $s) {
            for ($i = 1; $i <= 8; $i++) {
                Ticket::create([
                    'code' => sprintf('T%03d-S%d', $i, $s->id),
                    'screening_id' => $s->id,
                    'seat' => sprintf('%d排%d座', ceil($i / 2), ($i % 2) ? 1 : 2),
                    'status' => Ticket::STATUS_VALID,
                ]);
            }
        }

        // —— 场次4：正在演出中（20 分钟前开演，100 分钟后散场），用于演示开演后换稿审批 ——
        $live = Screening::create([
            'show_id' => $hamlet->id,
            'starts_at' => $now->copy()->subMinutes(20),
            'ends_at' => $now->copy()->addMinutes(100),
            'venue' => '实验剧场',
        ]);
        ScreeningLanguagePool::create(['screening_id' => $live->id, 'language' => 'zh', 'quota' => 3]);
        ScreeningLanguagePool::create(['screening_id' => $live->id, 'language' => 'en', 'quota' => 2]);
        for ($i = 1; $i <= 5; $i++) {
            Ticket::create([
                'code' => sprintf('T%03d-S%d', $i, $live->id),
                'screening_id' => $live->id,
                'seat' => sprintf('实验区%d号', $i),
                'status' => Ticket::STATUS_VALID,
            ]);
        }

        // 预置演出前已发布的当前字幕稿（演出已开始，种子直接指定当前稿以模拟演出前发布结果）
        $subtitle = app(\App\Services\SubtitleService::class);
        $zhV1 = $subtitle->publish(
            $live, 'zh', '哈姆雷特·中文字幕（正式稿）',
            "1\n00:00:01,000 --> 00:00:04,000\n生存还是毁灭，这是一个问题。\n\n".
            "2\n00:00:05,000 --> 00:00:09,000\n究竟哪样更高贵，去忍受那狂暴的命运无情的摧残。\n",
            $techA
        );
        // 手动标记为更早的发布时间，体现“演出前发布”
        $zhV1->update(['published_at' => $now->copy()->subDays(2)]);
        $enV1 = $subtitle->publish(
            $live, 'en', 'Hamlet English Subtitles (Official)',
            "1\n00:00:01,000 --> 00:00:04,000\nTo be, or not to be, that is the question.\n\n".
            "2\n00:00:05,000 --> 00:00:09,000\nWhether 'tis nobler in the mind to suffer the slings and arrows of outrageous fortune.\n",
            $techB
        );
        $enV1->update(['published_at' => $now->copy()->subDays(2)]);
        \App\Models\ScreeningSubtitleCurrent::create(['screening_id' => $live->id, 'language' => 'zh', 'subtitle_version_id' => $zhV1->id]);
        \App\Models\ScreeningSubtitleCurrent::create(['screening_id' => $live->id, 'language' => 'en', 'subtitle_version_id' => $enV1->id]);
    }
}
