<?php

namespace App\Support;

final class PaymentPayloadSanitizer
{
    public static function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $name = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
                $sensitive = in_array($name, ['authorization', 'proxyauthorization', 'serverkey', 'clientkey', 'signature', 'signaturekey', 'apikey', 'password'], true)
                    || str_contains($name, 'secret') || str_ends_with($name, 'token');
                $safe[$key] = $sensitive ? '[REDACTED]' : self::sanitize($item);
            }

            return $safe;
        }
        if (is_string($value)) {
            foreach ([config('services.midtrans.server_key'), config('services.midtrans.client_key')] as $secret) {
                if (is_string($secret) && $secret !== '') {
                    $value = str_replace([$secret, base64_encode($secret.':')], '[REDACTED]', $value);
                }
            }

            return preg_replace('/\b(?:Basic|Bearer)\s+[A-Za-z0-9+\/=._~-]+/i', '[REDACTED]', $value);
        }

        return $value;
    }
}
