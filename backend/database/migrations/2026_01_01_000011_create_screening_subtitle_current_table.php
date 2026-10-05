<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 每场 × 每语言当前使用的字幕版本（含演出前发布的“当前稿”）
        Schema::create('screening_subtitle_current', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screening_id')->constrained()->cascadeOnDelete();
            $table->string('language');
            $table->foreignId('subtitle_version_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['screening_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_subtitle_current');
    }
};
