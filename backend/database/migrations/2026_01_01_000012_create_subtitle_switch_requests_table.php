<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 开演后更换字幕稿：技术员发起，负责人批准后才切换
        Schema::create('subtitle_switch_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screening_id')->constrained()->cascadeOnDelete();
            $table->string('language');
            $table->foreignId('from_version_id')->constrained('subtitle_versions')->restrictOnDelete();
            $table->foreignId('to_version_id')->constrained('subtitle_versions')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            // pending | approved | rejected
            $table->string('status')->default('pending');
            $table->dateTime('switched_at')->nullable(); // 实际切换时间
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['screening_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_switch_requests');
    }
};
