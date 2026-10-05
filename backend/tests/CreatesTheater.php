<?php

namespace Tests;

use App\Models\Device;
use App\Models\Performance;
use App\Models\PerformanceLanguage;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

trait CreatesTheater
{
    protected function theaterUsers(): array
    {
        $users = [
            'audience' => User::create(['name' => '观众甲', 'email' => 'a@test.com', 'password' => Hash::make('x'), 'role' => User::ROLE_AUDIENCE, 'api_token' => 'token-audience']),
            'audience2' => User::create(['name' => '观众乙', 'email' => 'b@test.com', 'password' => Hash::make('x'), 'role' => User::ROLE_AUDIENCE, 'api_token' => 'token-audience2']),
            'desk' => User::create(['name' => '前台', 'email' => 'd@test.com', 'password' => Hash::make('x'), 'role' => User::ROLE_FRONT_DESK, 'api_token' => 'token-desk']),
            'tech' => User::create(['name' => '技术员', 'email' => 't@test.com', 'password' => Hash::make('x'), 'role' => User::ROLE_TECHNICIAN, 'api_token' => 'token-tech']),
            'manager' => User::create(['name' => '负责人', 'email' => 'm@test.com', 'password' => Hash::make('x'), 'role' => User::ROLE_MANAGER, 'api_token' => 'token-manager']),
        ];

        return $users;
    }

    protected function upcomingPerformance(array $capacities = ['zh' => 2, 'en' => 1]): Performance
    {
        $p = Performance::create([
            'title' => '测试话剧',
            'venue' => '主剧场',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);
        foreach ($capacities as $lang => $cap) {
            PerformanceLanguage::create(['performance_id' => $p->id, 'language' => $lang, 'capacity' => $cap]);
        }

        return $p;
    }

    protected function livePerformance(array $capacities = ['zh' => 2]): Performance
    {
        $p = Performance::create([
            'title' => '进行中音乐会',
            'venue' => '音乐厅',
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addMinutes(60),
        ]);
        foreach ($capacities as $lang => $cap) {
            PerformanceLanguage::create(['performance_id' => $p->id, 'language' => $lang, 'capacity' => $cap]);
        }

        return $p;
    }

    protected function device(string $serial, string $status = Device::STATUS_AVAILABLE, int $battery = 100, ?string $result = 'ok'): Device
    {
        return Device::create([
            'serial' => $serial,
            'status' => $status,
            'battery_level' => $battery,
            'last_check_result' => $result,
            'checked_at' => now(),
        ]);
    }

    protected function ticketCode(int $performanceId, string $tail = 'AAAA'): string
    {
        return "T-{$performanceId}-{$tail}";
    }
}
