<?php

namespace App\Services;

use App\Models\ApiRequestAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApiRequestAuditResponseFinalizer
{
    public function __construct(private readonly ApiRequestAuditPayloadSanitizer $sanitizer) {}

    public function finalize(Request $request, Response $response): void
    {
        $requestId = $request->attributes->get('api_request_id');
        if (! is_string($requestId)) {
            return;
        }

        $response->headers->set('X-Request-ID', $requestId);

        try {
            ApiRequestAuditLog::query()->where('request_id', $requestId)->update([
                'status_code' => $response->getStatusCode(),
                'response_content_type' => mb_substr((string) $response->headers->get('Content-Type'), 0, 120) ?: null,
                'response_bytes' => strlen((string) $response->getContent()),
                'response_payload' => $this->sanitizer->responsePayload($response),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Status akhir audit request API gagal diperbarui.', [
                'request_id' => $requestId,
                'exception' => $exception::class,
            ]);
        }
    }
}
