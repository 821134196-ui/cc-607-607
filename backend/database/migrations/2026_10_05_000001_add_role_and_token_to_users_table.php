<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // audience 观众 / front_desk 前台 / technician 技术员 / manager 负责人
            $table->string('role')->default('audience')->after('email');
            $table->string('api_token', 80)->nullable()->unique()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'api_token']);
        });
    }
};
