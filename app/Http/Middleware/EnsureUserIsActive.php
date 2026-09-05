<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Akun tidak aktif.',
            ], 403);
        }

        return $next($request);
    }
}
