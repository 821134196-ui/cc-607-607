<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 观众预约。改签 = 旧记录 cancelled + 新记录 active，同一时刻一人一场仅一条 active。
        // active 已预约待领取 / issued 已发放设备 / returned 已归还 / cancelled 已取消(含改签释放) / noshow 未归还
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_id')->constrained()->cascadeOnDelete();
            $table->string('language', 16);
            $table->string('status')->default('active');
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->unsignedInteger('seq')->default(1); // 该场第几次预约(改签序号)
            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['performance_id', 'language', 'status']);
            $table->index(['user_id', 'status']);
        });

        // 关键约束(部分唯一索引)：同一观众对同一场演出，
        // 只要存在未完结(active/issued)预约就不能再占用；改签/取消释放旧行后才可新建。
        DB::statement(
            "CREATE UNIQUE INDEX reservations_open_unique
             ON reservations (user_id, performance_id)
             WHERE status IN ('active', 'issued')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
