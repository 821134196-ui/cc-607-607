<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 每场 + 每种语言的设备配额
        Schema::create('screening_language_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screening_id')->constrained()->cascadeOnDelete();
            $table->string('language'); // zh | en | ja ...
            $table->unsignedInteger('quota');
            $table->timestamps();
            $table->unique(['screening_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_language_pools');
    }
};
