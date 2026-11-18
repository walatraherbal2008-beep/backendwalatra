<?php

namespace App\Services;

use App\Models\ExternalApiActivityLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ExternalApiActivityLogger
{
    public function start(string $service, string $operation, string $method, string $endpoint, ?array $payload = null): ?ExternalApiActivityLog
    {
        try {
            return ExternalApiActivityLog::create([
                'service' => $service,
                'operation' => $operation,
                'http_method' => strtoupper($method),
                'endpoint' => $this->sanitizeText($endpoint),
                'correlation_id' => (string) Str::uuid(),
                'status' => 'running',
                'request_payload' => $this->sanitizePayload($payload),
                'started_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Log aktivitas API eksternal gagal dimulai.', [
                'service' => $service,
                'operation' => $operation,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    public function finish(
        ?ExternalApiActivityLog $log,
        int $startedAt,
        string $status,
        ?int $httpStatus = null,
        ?array $responsePayload = null,
        ?Throwable $exception = null,
        ?string $errorMessage = null,
    ): void {
        if ($log === null) {
            return;
        }

        try {
            $log->update([
                'status' => in_array($status, ['succeeded', 'failed', 'timeout'], true) ? $status : 'failed',
                'http_status' => $httpStatus,
                'duration_ms' => max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000)),
                'response_payload' => $this->sanitizePayload($responsePayload),
                'error_type' => $exception ? Str::limit($exception::class, 255, '') : null,
                'error_message' => $this->sanitizeText($errorMessage ?? $exception?->getMessage()),
                'finished_at' => now(),
            ]);
        } catch (Throwable $logException) {
            Log::warning('Hasil aktivitas API eksternal gagal disimpan.', [
                'service' => $log->service,
                'operation' => $log->operation,
                'correlation_id' => $log->correlation_id,
                'exception' => $logException::class,
            ]);
        }
    }

    public function exceptionStatus(Throwable $exception): string
    {
        return preg_match('/timeout|timed out|cURL error 28/i', $exception->getMessage()) === 1
            ? 'timeout'
            : 'failed';
    }

    public function sanitizeResponseBody(string $body): string
    {
        $body = $this->sanitizeText($body) ?? '';
        $body = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[REDACTED_EMAIL]', $body) ?? $body;
        $body = preg_replace('/(?<!\w)(?:\+?62|0)\d{8,13}(?!\w)/', '[REDACTED_PHONE]', $body) ?? $body;

        return Str::limit($body, 4096, '…');
    }

    public function sanitizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $sanitized = $this->sanitizeValue($payload);
        $encoded = json_encode($sanitized, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded) || strlen($encoded) <= 65536) {
            return is_array($sanitized) ? $sanitized : ['value' => $sanitized];
        }

        return [
            '_truncated' => true,
            'preview' => substr($encoded, 0, 64000),
        ];
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/authorization|token|secret|password|signature|(?:api|client|server)[_-]?key|otp|one.?time.?password/i', $key) === 1) {
            return '[REDACTED]';
        }

        if ($key !== null && in_array(strtolower($key), ['phone', 'mobile', 'email', 'address', 'address_note', 'street', 'postal_code', 'fulladdr', 'to', 'name', 'realname', 'recipient_name', 'customer_name', 'full_name', 'first_name', 'last_name', 'text', 'message', 'latitude', 'longitude', 'lat', 'lng', 'lon', 'coordinates'], true)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitizeValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return $this->sanitizeText($value);
        }

        return is_scalar($value) || $value === null ? $value : '[UNSUPPORTED VALUE]';
    }

    public function sanitizeText(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        foreach (['maxchat.api_token', 'everpro.client_key', 'everpro.client_secret', 'midtrans.server_key', 'midtrans.client_key', 'warehouse.token', 'shipping.token'] as $key) {
            $secret = config('services.'.$key);
            if (is_string($secret) && strlen($secret) >= 8) {
                $value = str_replace([$secret, base64_encode($secret.':')], '[REDACTED]', $value);
            }
        }

        $value = preg_replace('/(bearer\s+)[^\s,;]+/i', '$1[REDACTED]', $value) ?? $value;
        $value = preg_replace('/(token|secret|password|authorization|signature|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $value) ?? $value;
        $value = preg_replace('/([?&](?:to|text|phone|mobile|email|address|token|secret|password)=)[^&\s]*/i', '$1[REDACTED]', $value) ?? $value;

        return Str::limit($value, 1000, '…');
    }
}
