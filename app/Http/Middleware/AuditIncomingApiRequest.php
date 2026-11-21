<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestAuditLog;
use App\Services\ApiRequestAuditPayloadSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditIncomingApiRequest
{
    public function __construct(private readonly ApiRequestAuditPayloadSanitizer $sanitizer) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (! config('security.api_request_audit_enabled', true) || ! $request->is('api/*')) {
            return $next($request);
        }

        $requestId = (string) Str::uuid();
        $request->attributes->set('api_request_id', $requestId);
        $startedAt = hrtime(true);
        $startedAtWall = now();
        $response = null;
        $exception = null;

        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } catch (Throwable $throwable) {
            $exception = $throwable;

            throw $throwable;
        } finally {
            $this->writeAudit($request, $response, $exception, $requestId, $startedAt, $startedAtWall);
        }
    }

    private function writeAudit(Request $request, ?Response $response, ?Throwable $exception, string $requestId, int $startedAt, mixed $startedAtWall): void
    {
        try {
            $route = $request->route();
            $actor = $request->user();
            $endpoint = $route?->uri() ?? $this->sanitizeUnmatchedPath($request->path());
            $contentType = $response?->headers->get('Content-Type');
            $responseBody = $response?->getContent();
            $requestBytes = max(0, (int) $request->header('Content-Length', 0));

            ApiRequestAuditLog::create([
                'request_id' => $requestId,
                'http_method' => strtoupper($request->method()),
                'endpoint' => mb_substr($endpoint, 0, 512),
                'route_name' => $route?->getName(),
                'status_code' => $response?->getStatusCode() ?? $this->statusForException($exception),
                'duration_ms' => max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000)),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000) ?: null,
                'actor_type' => $actor ? $actor::class : null,
                'actor_id' => $actor?->getAuthIdentifier(),
                'authentication_status' => $actor ? 'authenticated' : 'guest',
                'request_content_type' => mb_substr((string) $request->header('Content-Type'), 0, 120) ?: null,
                'response_content_type' => $contentType ? mb_substr($contentType, 0, 120) : null,
                'request_bytes' => $requestBytes,
                'response_bytes' => is_string($responseBody) ? strlen($responseBody) : 0,
                'query_keys' => array_values(array_map('strval', array_keys($request->query->all()))),
                'request_payload' => $this->sanitizer->requestPayload($request),
                'response_payload' => $this->sanitizer->responsePayload($response),
                'exception_type' => $exception ? mb_substr($exception::class, 0, 255) : null,
                'started_at' => $startedAtWall,
            ]);
        } catch (Throwable $loggingException) {
            Log::warning('Audit request API gagal disimpan.', [
                'request_id' => $requestId,
                'exception' => $loggingException::class,
            ]);
        }
    }

    private function sanitizeUnmatchedPath(string $path): string
    {
        $path = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $path) ?? $path;
        $path = preg_replace('/(?<![a-z0-9])[a-f0-9]{32,}(?![a-z0-9])/i', '[redacted]', $path) ?? $path;
        $path = preg_replace('#/(?:[A-Za-z0-9_-]{32,})(?=/|$)#', '/[redacted]', $path) ?? $path;

        return $path;
    }

    private function statusForException(?Throwable $exception): ?int
    {
        if ($exception === null) {
            return null;
        }

        return $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
    }
}
