<?php

namespace App\Services;

use App\Filament\Resources\ExternalApiActivityLogs\ExternalApiActivityLogResource;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ExternalApiHealthChecker
{
    /** @return list<array{service: string, label: string, status: string, detail: string, http_status: ?int}> */
    public function check(?string $service = null): array
    {
        $configurations = ExternalApiActivityLogResource::serviceConfigurations();
        $targets = $service === null
            ? $configurations
            : array_intersect_key($configurations, [$service => true]);

        return collect($targets)
            ->map(fn (array $configuration, string $key): array => $configuration['enabled']
                ? $this->checkService($key, $configuration['label'])
                : [
                    'service' => $key,
                    'label' => $configuration['label'],
                    'status' => 'skipped',
                    'detail' => 'Nonaktif atau konfigurasi belum lengkap.',
                    'http_status' => null,
                ])
            ->values()
            ->all();
    }

    /** @return array{service: string, label: string, status: string, detail: string, http_status: ?int} */
    private function checkService(string $service, string $label): array
    {
        $logger = app(ExternalApiActivityLogger::class);
        $started = hrtime(true);
        $endpoint = '';
        $activity = null;
        $response = null;
        $expectedNotFound = false;

        try {
            $probe = match ($service) {
                'midtrans' => $this->midtransProbe(),
                'everpro' => $this->everproProbe(),
                'maxchat' => $this->maxchatProbe(),
                'warehouse_directory' => $this->warehouseDirectoryProbe(),
                'warehouse_stock' => $this->warehouseStockProbe(),
                'shipping_rate' => $this->shippingRateProbe(),
                default => throw new RuntimeException('Layanan API tidak dikenal.'),
            };
            $endpoint = $probe['endpoint'];
            $activity = $logger->start($service, 'Uji koneksi', $probe['method'], $endpoint, $probe['payload']);
            $this->assertConfigured($probe['base_url'], ...$probe['credentials']);
            $response = ($probe['request'])();

            $reachable = $response->successful()
                || ($probe['expected_not_found'] && $response->status() === 404)
                || ($probe['expected_method_not_allowed'] && $response->status() === 405);

            $logger->finish(
                $activity,
                $started,
                $reachable ? 'succeeded' : 'failed',
                $response->status(),
                $this->responsePayload($response),
                errorMessage: $reachable ? null : 'Endpoint menolak pemeriksaan koneksi dengan HTTP '.$response->status().'.',
            );

            return [
                'service' => $service,
                'label' => $label,
                'status' => $reachable ? 'succeeded' : 'failed',
                'detail' => $this->responseDetail($service, $response, $reachable),
                'http_status' => $response->status(),
            ];
        } catch (Throwable $exception) {
            $status = $logger->exceptionStatus($exception);
            $configurationError = $exception instanceof RuntimeException && str_contains($exception->getMessage(), 'Konfigurasi');
            $detail = $configurationError
                ? 'Konfigurasi endpoint atau kredensial belum lengkap.'
                : 'Tidak dapat terhubung. Periksa koneksi jaringan dan endpoint layanan.';

            $logger->finish(
                $activity,
                $started,
                $status,
                $response?->status(),
                $response === null ? ['transport_error' => $exception::class] : $this->responsePayload($response),
                exception: $exception,
                errorMessage: 'Pemeriksaan koneksi ke '.$label.' gagal: '.$detail,
            );

            return [
                'service' => $service,
                'label' => $label,
                'status' => $status,
                'detail' => $detail,
                'http_status' => $response?->status(),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function midtransProbe(): array
    {
        $baseUrl = rtrim((string) config('services.midtrans.base_url'), '/');
        $key = (string) config('services.midtrans.server_key');
        $orderId = 'CONNECTION-CHECK-'.Str::uuid();
        $endpoint = $baseUrl.'/v2/'.$orderId.'/status';

        return [
            'base_url' => $baseUrl,
            'credentials' => [$key],
            'endpoint' => $endpoint,
            'method' => 'GET',
            'payload' => [
                'order_id' => $orderId,
                'authorization' => 'Basic [configured server key]',
            ],
            'expected_not_found' => true,
            'expected_method_not_allowed' => false,
            'request' => fn (): Response => Http::withBasicAuth($key, '')->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)->get($endpoint),
        ];
    }

    /** @return array<string, mixed> */
    private function everproProbe(): array
    {
        $baseUrl = rtrim((string) config('services.everpro.base_url'), '/');
        $path = (string) config('services.everpro.token_path', '/auth/v1/token');
        $key = (string) config('services.everpro.client_key');
        $secret = (string) config('services.everpro.client_secret');
        $endpoint = $baseUrl.$path;
        $payload = ['client_key' => $key, 'client_secret' => $secret];

        return [
            'base_url' => $baseUrl,
            'credentials' => [$key, $secret],
            'endpoint' => $endpoint,
            'method' => 'POST',
            'payload' => $payload,
            'expected_not_found' => false,
            'expected_method_not_allowed' => false,
            'request' => fn (): Response => Http::acceptJson()->asJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)->post($endpoint, $payload),
        ];
    }

    /** @return array<string, mixed> */
    private function maxchatProbe(): array
    {
        $baseUrl = rtrim((string) config('services.maxchat.base_url'), '/');
        $token = (string) config('services.maxchat.api_token');
        $endpoint = $baseUrl.'/api/system/status';

        return [
            'base_url' => $baseUrl,
            'credentials' => [$token],
            'endpoint' => $endpoint,
            'method' => 'GET',
            'payload' => [
                'probe' => 'read-only system status',
                'authorization' => 'Bearer '.($token !== '' ? '[configured]' : '[missing]'),
            ],
            'expected_not_found' => false,
            'expected_method_not_allowed' => false,
            'request' => fn (): Response => Http::withToken($token)->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)->get($endpoint),
        ];
    }

    /** @return array<string, mixed> */
    private function warehouseDirectoryProbe(): array
    {
        $baseUrl = rtrim((string) config('services.warehouse_directory.base_url'), '/');
        $path = (string) config('services.warehouse_directory.path');
        $endpoint = $baseUrl.$path;

        return [
            'base_url' => $baseUrl,
            'credentials' => [],
            'endpoint' => $endpoint,
            'method' => 'POST',
            'payload' => null,
            'expected_not_found' => false,
            'expected_method_not_allowed' => false,
            'request' => fn (): Response => Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)->post($endpoint),
        ];
    }

    /** @return array<string, mixed> */
    private function warehouseStockProbe(): array
    {
        $baseUrl = rtrim((string) config('services.warehouse.base_url'), '/');
        $path = (string) config('services.warehouse.paths.check');
        $token = (string) config('services.warehouse.token');
        $endpoint = $baseUrl.$path;

        return [
            'base_url' => $baseUrl,
            'credentials' => [$token],
            'endpoint' => $endpoint,
            'method' => 'HEAD',
            'payload' => null,
            'expected_not_found' => false,
            'expected_method_not_allowed' => true,
            'request' => fn (): Response => Http::withToken($token)->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)->head($endpoint),
        ];
    }

    /** @return array<string, mixed> */
    private function shippingRateProbe(): array
    {
        $baseUrl = rtrim((string) config('services.shipping.base_url'), '/');
        $path = (string) config('services.shipping.quote_path', '/shipping/quote');
        $token = (string) config('services.shipping.token');
        $endpoint = $baseUrl.$path;

        return [
            'base_url' => $baseUrl,
            'credentials' => [$token],
            'endpoint' => $endpoint,
            'method' => 'HEAD',
            'payload' => null,
            'expected_not_found' => false,
            'expected_method_not_allowed' => true,
            'request' => fn (): Response => Http::withToken($token)->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)->head($endpoint),
        ];
    }

    private function assertConfigured(string $baseUrl, string ...$credentials): void
    {
        if (blank($baseUrl) || collect($credentials)->contains(fn (string $value): bool => blank($value))) {
            throw new RuntimeException('Konfigurasi endpoint atau kredensial belum lengkap.');
        }
    }

    private function responseDetail(string $service, Response $response, bool $reachable): string
    {
        if (! $reachable) {
            return 'Endpoint merespons HTTP '.$response->status().'. Periksa kredensial atau konfigurasi endpoint.';
        }

        if ($service === 'midtrans' && (string) $response->json('status_code') === '404') {
            return 'Koneksi API dan autentikasi Midtrans berhasil.';
        }

        if ($service === 'maxchat' && $response->status() === 404) {
            return 'Endpoint status pesan merespons; ID uji tidak ditemukan dan autentikasi belum dapat dipastikan.';
        }

        if (in_array($service, ['warehouse_stock', 'shipping_rate'], true) && $response->status() === 405) {
            return 'Host merespons, tetapi endpoint menolak metode HEAD; autentikasi belum dapat dipastikan.';
        }

        if ($response->status() === 404) {
            return 'Endpoint merespons 404 untuk ID uji; koneksi Midtrans tersedia.';
        }

        return 'Endpoint merespons.';
    }

    private function responsePayload(Response $response): array
    {
        $payload = $response->json();

        if (is_array($payload)) {
            return $payload;
        }

        return ['raw_response' => app(ExternalApiActivityLogger::class)->sanitizeResponseBody($response->body())];
    }
}
