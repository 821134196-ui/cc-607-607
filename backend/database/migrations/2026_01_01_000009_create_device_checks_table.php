<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspector_id')->constrained('users');
            $table->string('phase'); // issue 发放核对 | return 散场复检
            $table->unsignedTinyInteger('battery_percent');
            $table->boolean('physical_ok');             // 实物编号/外观正常
            $table->boolean('language_confirmed');      // 字幕语言与预约一致
            $table->string('result'); // pass | fail
            $table->text('note')->nullable();
            $table->dateTime('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_checks');
    }
};
