<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 实物字幕设备。available 可发放 / assigned 已发放未归还 / maintenance 故障维修中 / retired 停用
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial')->unique();          // 实物编号
            $table->string('model')->default('CAP-100');
            $table->string('status')->default('available');
            $table->unsignedTinyInteger('battery_level')->default(100); // 最近一次电量百分比
            $table->string('last_check_result')->nullable(); // ok | fault
            $table->text('fault_notes')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
