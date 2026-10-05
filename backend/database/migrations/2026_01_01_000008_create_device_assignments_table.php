<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('issued_by')->constrained('users');
            $table->dateTime('issued_at');
            $table->foreignId('returned_by')->nullable()->constrained('users');
            $table->dateTime('returned_at')->nullable();
            $table->timestamps();
        });

        // 关键约束：同一台设备同时只能有一个未归还的发放记录 —— 设备不可重复分配
        DB::statement(
            'CREATE UNIQUE INDEX device_assignments_active_unique
             ON device_assignments (device_id) WHERE returned_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('device_assignments');
    }
};
