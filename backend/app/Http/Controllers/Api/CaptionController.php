<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptionScript;
use App\Models\Performance;
use App\Services\CaptionService;
use Illuminate\Http\Request;

class CaptionController extends Controller
{
    /** 某场某语言的字幕历史版本（含当前 active），任何登录用户可查 */
    public function history(Request $request, Performance $performance, string $language, CaptionService $service)
    {
        $scripts = $service->history($performance, $language)->map(fn (CaptionScript $s) => [
            'id' => $s->id,
            'version_no' => $s->version_no,
            'title' => $s->title,
            'status' => $s->status,
            'checksum' => $s->checksum,
            'file_path' => $s->file_path,
            'publisher' => $s->publisher?->name,
            'published_at' => $s->published_at?->toIso8601String(),
            'activated_at' => $s->activated_at?->toIso8601String(),
        ]);

        return response()->json([
            'performance_id' => $performance->id,
            'title' => $performance->title,
            'language' => $language,
            'has_started' => $performance->hasStarted(),
            'scripts' => $scripts,
        ]);
    }

    /** 取某版本正文（旧文件内容可查） */
    public function show(CaptionScript $script)
    {
        return response()->json([
            'id' => $script->id,
            'version_no' => $script->version_no,
            'title' => $script->title,
            'status' => $script->status,
            'language' => $script->language,
            'performance_id' => $script->performance_id,
            'content' => $script->content,
            'checksum' => $script->checksum,
            'activated_at' => $script->activated_at?->toIso8601String(),
            'file_intact' => $script->file_path ? $script->storedFileStillIntact() : null,
        ]);
    }

    /** 技术员发布字幕版本（开演前直接生效；开演后会被拒绝，需走申请） */
    public function publish(Request $request, Performance $performance, CaptionService $service)
    {
        $data = $request->validate([
            'language' => ['required', 'string', 'max:16'],
            'title' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'min:1'],
        ]);

        $script = $service->publish(
            $performance,
            $data['language'],
            $data['title'],
            $data['content'],
            $request->user(),
        );

        return response()->json(['message' => '字幕版本已发布', 'script' => [
            'id' => $script->id,
            'version_no' => $script->version_no,
            'status' => $script->status,
            'activated_at' => $script->activated_at?->toIso8601String(),
        ]], 201);
    }
}
