<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingRateProvider;
use App\Exceptions\ShippingUnavailableException;
use App\Services\ExternalApiActivityLogger;
use Illuminate\Support\Facades\Http;
use Throwable;

class ShippingApiRateProvider implements ShippingRateProvider
{
    public function quote(array $address, array $items): array
    {
        $baseUrl = rtrim((string) config('services.shipping.base_url'), '/');
        $path = (string) config('services.shipping.quote_path', '/shipping/quote');
        $activityLogger = app(ExternalApiActivityLogger::class);
        $started = hrtime(true);
        $payload = ['destination' => $address, 'items' => $items];
        $activity = $activityLogger->start('shipping_rate', 'Hitung tarif ongkir', 'POST', $baseUrl.$path, $payload);

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken((string) config('services.shipping.token'))
                ->acceptJson()
                ->timeout((int) config('services.shipping.timeout', 10))
                ->post($path, $payload);
        } catch (Throwable $exception) {
            $activityLogger->finish($activity, $started, $activityLogger->exceptionStatus($exception), exception: $exception);
            throw new ShippingUnavailableException('API ongkir tidak dapat dihubungi.', 0, $exception);
        }

        if ($response->failed()) {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), $response->json(), errorMessage: 'API ongkir mengembalikan HTTP '.$response->status().'.');
            throw new ShippingUnavailableException('API ongkir mengembalikan kegagalan.');
        }

        $data = $response->json('data', []);
        $fee = data_get($data, 'fee');
        $service = data_get($data, 'service');

        if (! is_numeric($fee) || ! is_string($service) || $service === '') {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), is_array($data) ? $data : null, errorMessage: 'Data tarif ongkir tidak lengkap.');
            throw new ShippingUnavailableException('API ongkir mengembalikan data tidak lengkap.');
        }

        $activityLogger->finish($activity, $started, 'succeeded', $response->status(), is_array($data) ? $data : null);

        return [
            'fee' => (float) $fee,
            'service' => $service,
            'eta_days' => is_numeric(data_get($data, 'eta_days'))
                ? (int) data_get($data, 'eta_days')
                : null,
        ];
    }
}
