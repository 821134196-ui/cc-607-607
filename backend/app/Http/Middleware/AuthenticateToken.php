<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->query('api_token');

        if (! $token) {
            return response()->json(['message' => '未提供访问令牌'], 401);
        }

        $user = User::where('api_token', hash('sha256', $token))->first();

        // 也兼容直接存储的哈希形态比较（种子中我们存哈希）
        if (! $user) {
            $user = User::where('api_token', $token)->first();
        }

        if (! $user) {
            return response()->json(['message' => '令牌无效'], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
