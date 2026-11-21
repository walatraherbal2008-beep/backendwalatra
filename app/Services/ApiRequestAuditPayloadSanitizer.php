<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiRequestAuditPayloadSanitizer
{
    private const SENSITIVE_KEY_PATTERN = '/authorization|cookie|token|secret|password|signature|otp|one.?time.?password|api[_-]?key|client[_-]?key|server[_-]?key/i';

    private const PRIVATE_KEY_PATTERN = '/name|phone|mobile|email|address|street|postal|latitude|longitude|coordinates?|recipient|customer|receiver|contact/i';

    public function requestPayload(Request $request): ?array
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || str_contains((string) $request->header('Content-Type'), 'multipart/form-data')) {
            return null;
        }

        $requestBytes = (int) $request->header('Content-Length', 0);
        $limit = max(1024, (int) config('security.api_request_audit_payload_max_bytes', 32768));
        if ($requestBytes > $limit) {
            return ['_omitted' => 'payload_too_large', '_bytes' => $requestBytes];
        }

        $payload = $request->isJson() ? $request->json()->all() : $request->request->all();

        return $payload === [] ? null : $this->sanitize($payload);
    }

    public function responsePayload(?Response $response): ?array
    {
        if ($response === null || ! str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            return null;
        }

        $body = $response->getContent();
        $limit = max(1024, (int) config('security.api_request_audit_payload_max_bytes', 32768));

        if (! is_string($body) || $body === '') {
            return null;
        }

        if (strlen($body) > $limit) {
            return ['_omitted' => 'payload_too_large', '_bytes' => strlen($body)];
        }

        $payload = json_decode($body, true);

        return is_array($payload) ? $this->sanitize($payload) : null;
    }

    public function sanitize(array $payload): array
    {
        $sanitized = $this->sanitizeValue($payload);
        $json = json_encode($sanitized, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $limit = max(1024, (int) config('security.api_request_audit_payload_max_bytes', 32768));

        if (! is_string($json) || strlen($json) > $limit) {
            return ['_omitted' => 'payload_too_large', '_bytes' => is_string($json) ? strlen($json) : null];
        }

        return is_array($sanitized) ? $sanitized : ['value' => $sanitized];
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && (preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1 || preg_match(self::PRIVATE_KEY_PATTERN, $key) === 1)) {
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
            $value = preg_replace('/(bearer\s+)[^\s,;]+/i', '$1[REDACTED]', $value) ?? $value;
            $value = preg_replace('/([?&](?:token|secret|password|signature|authorization|api[_-]?key)\s*=)[^&\s]*/i', '$1[REDACTED]', $value) ?? $value;
            $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[REDACTED_EMAIL]', $value) ?? $value;
            $value = preg_replace('/(?<!\w)(?:\+?62|0)\d{8,13}(?!\w)/', '[REDACTED_PHONE]', $value) ?? $value;

            return mb_substr($value, 0, 1000);
        }

        return is_scalar($value) || $value === null ? $value : '[UNSUPPORTED VALUE]';
    }
}
