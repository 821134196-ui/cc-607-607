<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 字幕版本：技术员发布，必须对应场次；旧版本文件永久保留
        Schema::create('subtitle_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screening_id')->constrained()->cascadeOnDelete();
            $table->string('language');
            $table->unsignedInteger('version_no');
            $table->string('title');
            $table->string('file_path'); // storage/app/subtitles/... 旧文件不删除
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 64);
            $table->foreignId('published_by')->constrained('users');
            $table->dateTime('published_at');
            $table->timestamps();
            $table->unique(['screening_id', 'language', 'version_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_versions');
    }
};
