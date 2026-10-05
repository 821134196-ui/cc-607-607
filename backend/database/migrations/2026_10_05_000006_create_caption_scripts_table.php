<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 字幕版本：技术员为某场演出某语言发布。版本永不删除（保留旧文件）。
        // draft 草稿 / active 当前生效 / superseded 被新版替换 / rejected 审批未通过
        Schema::create('caption_scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_id')->constrained()->cascadeOnDelete();
            $table->string('language', 16);
            $table->unsignedInteger('version_no');
            $table->string('title');
            $table->longText('content'); // 字幕稿正文（行/时间轴），旧版原样保留
            $table->string('file_path')->nullable(); // 如有上传文件，存储路径
            $table->string('checksum', 64); // sha256，证明旧文件内容未被篡改
            $table->string('status')->default('active');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('activated_at')->nullable(); // 实际切换（生效）时间
            $table->timestamps();
            $table->index(['performance_id', 'language']);
            $table->unique(['performance_id', 'language', 'version_no']);
        });

        // 每场每语言同时最多一个 active（当前生效）版本
        DB::statement(
            "CREATE UNIQUE INDEX caption_scripts_active_unique
             ON caption_scripts (performance_id, language)
             WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('caption_scripts');
    }
};
