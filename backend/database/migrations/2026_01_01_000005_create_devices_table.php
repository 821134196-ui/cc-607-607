<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial')->unique();        // 实物编号
            $table->string('label')->nullable();
            $table->json('supported_languages');       // 该设备支持的字幕语言
            $table->string('status')->default('available'); // available | assigned | faulty
            $table->unsignedTinyInteger('battery_percent')->default(100);
            $table->text('fault_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
