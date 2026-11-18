<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitPaymentWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $maximum = max(1024, (int) config('security.webhook_max_bytes', 16384));
        if ((int) $request->header('Content-Length', 0) > $maximum || strlen($request->getContent()) > $maximum) {
            return response()->json(['success' => false, 'code' => 'PAYLOAD_TOO_LARGE', 'message' => 'Payload webhook terlalu besar.'], 413);
        }

        return $next($request);
    }
}
