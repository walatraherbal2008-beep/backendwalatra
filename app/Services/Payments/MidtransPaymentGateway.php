<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayUnavailableException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class MidtransPaymentGateway implements PaymentGateway
{
    public function baseUrl(): string
    {
        return config('services.midtrans.is_production') ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }

    private function request(): PendingRequest
    {
        if (blank(config('services.midtrans.server_key'))) {
            throw new PaymentGatewayUnavailableException('Server Key Midtrans belum dikonfigurasi.');
        }

        return Http::baseUrl($this->baseUrl())->withBasicAuth((string) config('services.midtrans.server_key'), '')
            ->acceptJson()->asJson()->withoutRedirecting()
            ->connectTimeout(3)->timeout((int) config('services.midtrans.timeout', 10));
    }

    private function send(string $method, string $path, array $payload = [], bool $allowNotFound = false): ?array
    {
        try {
            $options = $method === 'GET' ? [] : ($payload === [] ? ['body' => ''] : ['json' => $payload]);
            $response = $this->request()->send($method, $path, $options);
        } catch (Throwable) {
            // Do not propagate request exceptions: they can contain payment credentials or customer data.
            throw new PaymentGatewayUnavailableException('Midtrans tidak dapat dihubungi. Status transaksi perlu disinkronkan.');
        }
        $body = $response->json();
        if ($allowNotFound && ($response->status() === 404 || (is_array($body) && (string) ($body['status_code'] ?? '') === '404'))) {
            return null;
        }
        if ($response->failed() || $response->redirect() || ! is_array($body)
            || ! in_array((string) ($body['status_code'] ?? ''), ['200', '201', '202', '407'], true)) {
            throw new PaymentGatewayUnavailableException('Midtrans belum mengonfirmasi transaksi. Silakan sinkronkan status pembayaran.');
        }

        return $body;
    }

    public function charge(array $payload): array
    {
        return $this->send('POST', '/v2/charge', $payload);
    }

    public function status(string $providerOrderId): ?array
    {
        return $this->send('GET', '/v2/'.rawurlencode($providerOrderId).'/status', allowNotFound: true);
    }

    public function cancel(string $providerOrderId): array
    {
        return $this->send('POST', '/v2/'.rawurlencode($providerOrderId).'/cancel');
    }

    public function qrImage(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array($parts['host'] ?? '', [parse_url($this->baseUrl(), PHP_URL_HOST), config('services.midtrans.is_production') ? 'merchants-app.midtrans.com' : 'merchants-app.sbx.midtrans.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! preg_match('~^/v(?:2/qris/[^/]+|4/qris/(?:gopay/|shopeepay/)?[^/]+)/qr-code$~D', $parts['path'] ?? '')) {
            throw new PaymentGatewayUnavailableException('URL QR Midtrans tidak valid.');
        }
        try {
            $response = Http::accept('image/png')->withoutRedirecting()->connectTimeout(3)->timeout(10)->get($url);
        } catch (Throwable) {
            throw new PaymentGatewayUnavailableException('Gambar QR belum dapat dimuat.');
        }
        if (! $response->successful() || ! str_starts_with($response->body(), "\x89PNG\r\n\x1a\n")) {
            throw new PaymentGatewayUnavailableException('Gambar QR Midtrans tidak valid.');
        }

        return $response->body();
    }
}
