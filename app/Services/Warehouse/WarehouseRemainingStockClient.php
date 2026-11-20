<?php

namespace App\Services\Warehouse;

use App\Exceptions\WarehouseUnavailableException;
use App\Services\ExternalApiActivityLogger;
use Illuminate\Support\Facades\Http;
use Throwable;

class WarehouseRemainingStockClient
{
    public function quantity(string $warehouseCode, string $productCode, ?float $deadline = null): int
    {
        if (! config('services.warehouse_remaining_stock.enabled')) {
            throw new WarehouseUnavailableException('API sisa stok belum diaktifkan.');
        }
        $timeout = min(3.0, max(0.1, (float) config('services.warehouse_remaining_stock.timeout', 3)));
        if ($deadline !== null) {
            $timeout = min($timeout, $deadline - hrtime(true) / 1e9);
        }
        if ($timeout <= 0) {
            throw new WarehouseUnavailableException('Batas waktu pemeriksaan stok telah habis.');
        }
        $baseUrl = rtrim((string) config('services.warehouse_remaining_stock.base_url'), '/');
        $path = (string) config('services.warehouse_remaining_stock.path');
        $logger = app(ExternalApiActivityLogger::class);
        $started = hrtime(true);
        $activity = $logger->start('warehouse_remaining_stock', 'Sisa stok produk', 'POST', $baseUrl.$path, ['code' => $warehouseCode, 'produk' => $productCode]);
        try {
            $response = Http::baseUrl($baseUrl)->acceptJson()->asMultipart()
                ->connectTimeout(min(2, $timeout))->timeout($timeout)
                ->post($path, [
                    ['name' => 'code', 'contents' => $warehouseCode],
                    ['name' => 'produk', 'contents' => $productCode],
                ]);
        } catch (Throwable $exception) {
            $logger->finish($activity, $started, $logger->exceptionStatus($exception), exception: $exception);
            throw new WarehouseUnavailableException('API sisa stok tidak dapat dihubungi.', 0, $exception);
        }
        $payload = $response->json();
        $quantity = data_get($payload, 'data.jumlah');
        $validError = is_array($payload)
            && array_key_exists('error', $payload)
            && in_array($payload['error'], [null, ''], true);
        $validQuantity = is_int($quantity)
            || (is_string($quantity) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $quantity)
                && filter_var($quantity, FILTER_VALIDATE_INT) !== false);
        if (! $response->successful() || ! $validError || ! $validQuantity) {
            $logger->finish($activity, $started, 'failed', $response->status(), is_array($payload) ? $payload : null, errorMessage: 'Respons sisa stok tidak valid.');
            throw new WarehouseUnavailableException('Respons sisa stok tidak valid.');
        }
        $logger->finish($activity, $started, 'succeeded', $response->status(), $payload);

        return (int) $quantity;
    }
}
