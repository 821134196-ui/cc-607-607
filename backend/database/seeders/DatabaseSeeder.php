<?php

namespace Database\Seeders;

use App\Models\CaptionScript;
use App\Models\Device;
use App\Models\Performance;
use App\Models\PerformanceLanguage;
use App\Models\User;
use App\Services\CaptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 固定演示令牌，便于前端登录与接口调试
        $users = [
            ['name' => '观众·林晓', 'email' => 'audience@example.com', 'role' => User::ROLE_AUDIENCE, 'token' => 'audience-demo-token-0001'],
            ['name' => '前台·王敏', 'email' => 'desk@example.com', 'role' => User::ROLE_FRONT_DESK, 'token' => 'desk-demo-token-0001'],
            ['name' => '技术员·陈工', 'email' => 'tech@example.com', 'role' => User::ROLE_TECHNICIAN, 'token' => 'tech-demo-token-0001'],
            ['name' => '负责人·赵总监', 'email' => 'manager@example.com', 'role' => User::ROLE_MANAGER, 'token' => 'manager-demo-token-0001'],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'role' => $u['role'],
                    'password' => Hash::make('password'),
                    'api_token' => $u['token'],
                    'email_verified_at' => now(),
                ],
            );
        }

        $tech = User::where('role', User::ROLE_TECHNICIAN)->first();

        // 三场演出：一场未开演、一场进行中、一场已结束
        Performance::truncate();
        PerformanceLanguage::truncate();
        CaptionScript::truncate();
        Device::truncate();

        $upcoming = Performance::create([
            'title' => '话剧《夜色温柔》',
            'venue' => '主剧场',
            'starts_at' => now()->addDays(2)->setHour(19)->setMinute(30),
            'ends_at' => now()->addDays(2)->setHour(21)->setMinute(40),
            'description' => '提供中/英/日三语无障碍字幕设备。',
        ]);
        foreach ([['zh', 6], ['en', 4], ['ja', 2]] as [$lang, $cap]) {
            PerformanceLanguage::create(['performance_id' => $upcoming->id, 'language' => $lang, 'capacity' => $cap]);
        }

        $live = Performance::create([
            'title' => '音乐会《河流》',
            'venue' => '音乐厅',
            'starts_at' => now()->subMinutes(25),
            'ends_at' => now()->addMinutes(70),
            'description' => '本场正在演出，字幕换稿需负责人批准。',
        ]);
        foreach ([['zh', 5], ['en', 3]] as [$lang, $cap]) {
            PerformanceLanguage::create(['performance_id' => $live->id, 'language' => $lang, 'capacity' => $cap]);
        }

        $past = Performance::create([
            'title' => '舞剧《回声》',
            'venue' => '主剧场',
            'starts_at' => now()->subDay()->setHour(19)->setMinute(30),
            'ends_at' => now()->subDay()->setHour(21)->setMinute(20),
            'description' => '昨日场。',
        ]);
        foreach ([['zh', 4], ['en', 2]] as [$lang, $cap]) {
            PerformanceLanguage::create(['performance_id' => $past->id, 'language' => $lang, 'capacity' => $cap]);
        }

        // 字幕：给三场发布初始版本（直接写库，避免时间窗口守卫）
        $this->seedScript($upcoming, 'zh', '夜色温柔·中文 v1', "1\n00:00:02 --> 00:00:06\n夜晚降临，城市灯火渐起。\n", $tech);
        $this->seedScript($upcoming, 'en', 'Tender Is the Night · EN v1', "1\n00:00:02 --> 00:00:06\nNight falls, and the city lights come on.\n", $tech);
        $this->seedScript($upcoming, 'ja', '夜はやさしく·JA v1', "1\n00:00:02 --> 00:00:06\n夜が訪れ、街の明かりがともります。\n", $tech);
        $this->seedScript($live, 'zh', '河流·中文 v1', "1\n00:00:01 --> 00:00:05\n河流穿过山谷。\n", $tech);
        $this->seedScript($live, 'en', 'The River · EN v1', "1\n00:00:01 --> 00:00:05\nThe river runs through the valley.\n", $tech);
        $this->seedScript($past, 'zh', '回声·中文 v1', "1\n00:00:01 --> 00:00:05\n回声在剧场里荡漾。\n", $tech);

        // 12 台设备：10 可用，1 已分配(演示)，1 故障维修中
        for ($i = 1; $i <= 10; $i++) {
            Device::create([
                'serial' => sprintf('CAP-%03d', $i),
                'model' => 'CAP-100',
                'status' => Device::STATUS_AVAILABLE,
                'battery_level' => 100,
                'last_check_result' => 'ok',
                'checked_at' => now(),
            ]);
        }
        Device::create([
            'serial' => 'CAP-011',
            'model' => 'CAP-100',
            'status' => Device::STATUS_AVAILABLE,
            'battery_level' => 32,
            'last_check_result' => 'ok',
            'checked_at' => now(),
        ]); // 电量不足，发放应被拦截
        Device::create([
            'serial' => 'CAP-012',
            'model' => 'CAP-100',
            'status' => Device::STATUS_MAINTENANCE,
            'battery_level' => 80,
            'last_check_result' => 'fault',
            'fault_notes' => '屏幕花屏，待维修',
            'checked_at' => now(),
        ]);
    }

    private function seedScript(Performance $performance, string $language, string $title, string $content, User $tech): void
    {
        $path = "captions/performance-{$performance->id}/{$language}/v1.txt";
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $content);

        CaptionScript::create([
            'performance_id' => $performance->id,
            'language' => $language,
            'version_no' => 1,
            'title' => $title,
            'content' => $content,
            'file_path' => $path,
            'checksum' => CaptionScript::checksumFor($content),
            'status' => CaptionScript::STATUS_ACTIVE,
            'published_by' => $tech->id,
            'published_at' => now(),
            'activated_at' => now(),
        ]);
    }
}
