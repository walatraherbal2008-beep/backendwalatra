<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingRateProvider;
use App\Exceptions\ShippingUnavailableException;
use Illuminate\Support\Facades\Http;
use Throwable;

class ShippingApiRateProvider implements ShippingRateProvider
{
    public function quote(array $address, array $items): array
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('services.shipping.base_url'), '/'))
                ->withToken((string) config('services.shipping.token'))
                ->acceptJson()
                ->timeout((int) config('services.shipping.timeout', 10))
                ->post((string) config('services.shipping.quote_path', '/shipping/quote'), [
                    'destination' => $address,
                    'items' => $items,
                ]);
        } catch (Throwable $exception) {
            throw new ShippingUnavailableException('API ongkir tidak dapat dihubungi.', 0, $exception);
        }

        if ($response->failed()) {
            throw new ShippingUnavailableException('API ongkir mengembalikan kegagalan.');
        }

        $data = $response->json('data', []);
        $fee = data_get($data, 'fee');
        $service = data_get($data, 'service');

        if (! is_numeric($fee) || ! is_string($service) || $service === '') {
            throw new ShippingUnavailableException('API ongkir mengembalikan data tidak lengkap.');
        }

        return [
            'fee' => (float) $fee,
            'service' => $service,
            'eta_days' => is_numeric(data_get($data, 'eta_days'))
                ? (int) data_get($data, 'eta_days')
                : null,
        ];
    }
}
