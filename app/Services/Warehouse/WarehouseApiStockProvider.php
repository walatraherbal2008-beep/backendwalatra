<?php

namespace App\Services\Warehouse;

use App\Contracts\WarehouseStockProvider;
use App\Exceptions\WarehouseUnavailableException;
use Illuminate\Support\Facades\Http;
use Throwable;

class WarehouseApiStockProvider implements WarehouseStockProvider
{
    public function checkAvailability(array $items): array
    {
        $response = $this->request('check', ['items' => $items]);

        return [
            'available' => (bool) data_get($response, 'data.available', false),
            'items' => (array) data_get($response, 'data.items', []),
        ];
    }

    public function reserve(array $items, string $reference): string
    {
        $response = $this->request('reserve', [
            'reference' => $reference,
            'items' => $items,
        ]);
        $reservationId = data_get($response, 'data.reservation_id');

        if (! is_string($reservationId) || $reservationId === '') {
            throw new WarehouseUnavailableException('API gudang tidak mengembalikan reservation id.');
        }

        return $reservationId;
    }

    public function confirm(string $reservationId): void
    {
        $this->request('confirm', ['reservation_id' => $reservationId]);
    }

    public function release(string $reservationId): void
    {
        $this->request('release', ['reservation_id' => $reservationId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $operation, array $payload): array
    {
        $path = (string) config('services.warehouse.paths.'.$operation);

        try {
            $response = Http::baseUrl(rtrim((string) config('services.warehouse.base_url'), '/'))
                ->withToken((string) config('services.warehouse.token'))
                ->acceptJson()
                ->timeout((int) config('services.warehouse.timeout', 10))
                ->post($path, $payload);
        } catch (Throwable $exception) {
            throw new WarehouseUnavailableException('API gudang tidak dapat dihubungi.', 0, $exception);
        }

        if ($response->failed()) {
            throw new WarehouseUnavailableException('API gudang mengembalikan kegagalan.');
        }

        return $response->json() ?? [];
    }
}
