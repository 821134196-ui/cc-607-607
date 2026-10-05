<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('screening_id')->constrained()->restrictOnDelete();
            $table->foreignId('audience_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience_name');
            $table->string('audience_phone')->nullable();
            $table->string('language');
            // booked 已预约（占用配额） | checked_in 已发放设备 | completed 已归还
            // | released 改签释放 | cancelled 观众取消 | no_show 未到场
            $table->string('status')->default('booked');
            $table->string('release_reason')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['screening_id', 'language', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
