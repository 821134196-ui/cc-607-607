<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performances', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('venue')->default('主剧场');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index('starts_at');
        });

        // 每场演出每种字幕语言的可预约设备容量
        Schema::create('performance_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_id')->constrained()->cascadeOnDelete();
            $table->string('language', 16); // zh, en, ja ...
            $table->unsignedInteger('capacity');
            $table->timestamps();
            $table->unique(['performance_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_languages');
        Schema::dropIfExists('performances');
    }
};
