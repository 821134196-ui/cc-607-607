<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 演出中更换字幕稿申请：技术员发起 → 负责人批准/驳回 → 才允许切换
        // pending 待审批 / approved 已批准(已切换) / rejected 已驳回
        Schema::create('caption_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_id')->constrained()->cascadeOnDelete();
            $table->string('language', 16);
            $table->foreignId('new_script_id')->constrained('caption_scripts')->cascadeOnDelete();
            $table->foreignId('current_script_id')->nullable()->constrained('caption_scripts')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->text('reason'); // 技术员说明换稿原因
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamp('switched_at')->nullable(); // 批准后真正切换的时间
            $table->timestamps();
            $table->index(['performance_id', 'status']);
        });

        // 本地模拟的通知发送记录（票务/通知接口模拟）
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->default('sms'); // sms | email | push
            $table->string('to_address');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('related_type')->nullable(); // reservation | device ...
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamps();
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('caption_change_requests');
    }
};
