<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 演示系统的轻量身份解析：前端在员工页面通过 X-Staff-User-Id
 * 头携带当前操作员工。真实部署应替换为会话/JWT 鉴权。
 */
class ActingStaff
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $id = $request->header('X-Staff-User-Id');
        if (!$id) {
            return response()->json(['message' => '未选择操作员工身份'], 401);
        }
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => '员工身份不存在'], 401);
        }
        if ($roles && !in_array($user->role, $roles, true)) {
            return response()->json([
                'message' => '无权操作：需要 '.implode(' 或 ', $roles).' 身份（当前：'.$user->role.'）',
            ], 403);
        }
        $request->attributes->set('acting_user', $user);

        return $next($request);
    }
}
