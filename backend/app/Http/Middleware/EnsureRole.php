<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole(...$roles)) {
            return response()->json([
                'message' => '无权访问：需要 '.implode(' 或 ', $roles).' 角色',
                'your_role' => $user?->role,
            ], 403);
        }

        return $next($request);
    }
}
