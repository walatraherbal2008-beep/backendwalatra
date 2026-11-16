<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Services\ExternalApiActivityLogger;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class MidtransPaymentGateway implements PaymentGateway
{
    public function baseUrl(): string
    {
        return (string) config('services.midtrans.base_url');
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

    private function send(string $method, string $path, string $operation, ?string $providerId, array $payload = [], bool $allowNotFound = false): ?array
    {
        $logger = app(PaymentCommunicationLogger::class);
        $started = hrtime(true);
        $endpoint = $this->baseUrl().$path;
        $log = $logger->start($method, $endpoint, $operation, $providerId, $payload);
        $activityLogger = app(ExternalApiActivityLogger::class);
        $activityStarted = hrtime(true);
        $activity = $activityLogger->start('midtrans', $operation, $method, $endpoint, $payload);
        try {
            $options = $method === 'GET' ? [] : ($payload === [] ? ['body' => ''] : ['json' => $payload]);
            $response = $this->request()->send($method, $path, $options);
        } catch (Throwable $exception) {
            $activityLogger->finish($activity, $activityStarted, $activityLogger->exceptionStatus($exception), exception: $exception,
                errorMessage: 'Midtrans tidak dapat dihubungi atau request belum dapat dikirim.');
            $logger->finish($log, $started, [
                'outcome' => 'connection_error', 'error_type' => get_class($exception),
                'error_message' => 'Midtrans tidak dapat dihubungi atau request belum dapat dikirim.',
            ]);
            // Do not propagate request exceptions: they can contain payment credentials or customer data.
            throw new PaymentGatewayUnavailableException('Midtrans tidak dapat dihubungi. Status transaksi perlu disinkronkan.');
        }
        $body = $response->json();
        $notFound = $allowNotFound && ($response->status() === 404 || (is_array($body) && (string) ($body['status_code'] ?? '') === '404'));
        $invalid = $response->failed() || $response->redirect() || ! is_array($body)
            || ! in_array((string) ($body['status_code'] ?? ''), ['200', '201', '202', '407'], true);
        $activityLogger->finish(
            $activity,
            $activityStarted,
            $notFound || ! $invalid ? 'succeeded' : 'failed',
            $response->status(),
            is_array($body) ? $body : null,
            errorMessage: $invalid && ! $notFound ? 'Midtrans memberikan respons yang belum mengonfirmasi transaksi.' : null,
        );
        $logger->finish($log, $started, [
            'http_status' => $response->status(), 'response_payload' => is_array($body) ? $body : null,
            'response_body' => is_array($body) ? null : $response->body(),
            'outcome' => $notFound ? 'not_found' : ($invalid ? 'failed' : 'succeeded'),
        ]);
        if ($notFound) {
            return null;
        }
        if ($invalid) {
            throw new PaymentGatewayUnavailableException('Midtrans belum mengonfirmasi transaksi. Silakan sinkronkan status pembayaran.');
        }

        return $body;
    }

    public function charge(array $payload): array
    {
        return $this->send('POST', '/v2/charge', 'charge', $payload['transaction_details']['order_id'] ?? null, $payload);
    }

    public function status(string $providerOrderId): ?array
    {
        return $this->send('GET', '/v2/'.rawurlencode($providerOrderId).'/status', 'status', $providerOrderId, allowNotFound: true);
    }

    public function cancel(string $providerOrderId): array
    {
        return $this->send('POST', '/v2/'.rawurlencode($providerOrderId).'/cancel', 'cancel', $providerOrderId);
    }

    public function qrImage(string $url): string
    {
        $parts = parse_url($url);
        $allowedHosts = [parse_url($this->baseUrl(), PHP_URL_HOST), ...config('services.midtrans.qr_allowed_hosts', [])];
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array($parts['host'] ?? '', $allowedHosts, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! preg_match('~^/v(?:2/qris/[^/]+|4/qris/(?:gopay/|shopeepay/)?[^/]+)/qr-code$~D', $parts['path'] ?? '')) {
            throw new PaymentGatewayUnavailableException('URL QR Midtrans tidak valid.');
        }
        $activityLogger = app(ExternalApiActivityLogger::class);
        $started = hrtime(true);
        $activity = $activityLogger->start('midtrans', 'Unduh gambar QR', 'GET', $url);
        try {
            $response = Http::accept('image/png')->withoutRedirecting()->connectTimeout(3)->timeout(10)->get($url);
        } catch (Throwable $exception) {
            $activityLogger->finish($activity, $started, $activityLogger->exceptionStatus($exception), exception: $exception);
            throw new PaymentGatewayUnavailableException('Gambar QR belum dapat dimuat.');
        }
        if (! $response->successful() || ! str_starts_with($response->body(), "\x89PNG\r\n\x1a\n")) {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), errorMessage: 'Respons gambar QR Midtrans tidak valid.');
            throw new PaymentGatewayUnavailableException('Gambar QR Midtrans tidak valid.');
        }

        $activityLogger->finish($activity, $started, 'succeeded', $response->status(), [
            'content_type' => $response->header('Content-Type'),
            'bytes' => strlen($response->body()),
        ]);

        return $response->body();
    }
}
