<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 一次设备发放/归还流转，对应一条已发放预约
        // issued 已发放 / returned 已归还复检通过 / faulty 归还时检出故障 / missing 未归还
        Schema::create('device_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('performance_id')->constrained()->cascadeOnDelete();
            $table->string('language', 16);
            $table->string('status')->default('issued');

            // 发放核对（前台）
            $table->unsignedTinyInteger('battery_at_issue');
            $table->string('language_at_issue', 16);
            $table->string('serial_confirmed', 64);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');

            // 散场复检（前台）
            $table->unsignedTinyInteger('battery_at_return')->nullable();
            $table->string('check_result')->nullable(); // ok | fault
            $table->text('fault_notes')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();

            $table->timestamps();
            $table->index(['device_id', 'status']);
            $table->index(['performance_id']);
        });

        // 数据库层兜底：同一设备同时只能有一条未完结(issued)发放记录，杜绝重复分配
        DB::statement(
            "CREATE UNIQUE INDEX device_assignments_open_unique
             ON device_assignments (device_id)
             WHERE status = 'issued'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('device_assignments');
    }
};
