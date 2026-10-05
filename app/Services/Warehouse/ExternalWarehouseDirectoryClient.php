<?php

namespace App\Services\Warehouse;

use App\Exceptions\WarehouseDirectoryUnavailableException;
use Illuminate\Support\Facades\Http;
use Throwable;

class ExternalWarehouseDirectoryClient
{
    /** @return list<array<string, mixed>> */
    public function list(?string $areaGroup = null): array
    {
        if (! config('services.warehouse_directory.enabled')) {
            throw new WarehouseDirectoryUnavailableException('API daftar gudang belum dikonfigurasi.');
        }

        try {
            $request = Http::baseUrl(rtrim((string) config('services.warehouse_directory.base_url'), '/'))
                ->acceptJson()
                ->timeout((int) config('services.warehouse_directory.timeout', 10));

            $response = $areaGroup === null
                ? $request->post((string) config('services.warehouse_directory.path'))
                : $request->asMultipart()->post(
                    (string) config('services.warehouse_directory.path'),
                    [['name' => 'area_group', 'contents' => $areaGroup]],
                );
        } catch (Throwable $exception) {
            throw new WarehouseDirectoryUnavailableException('API daftar gudang tidak dapat dihubungi.', 0, $exception);
        }

        if ($response->failed()) {
            throw new WarehouseDirectoryUnavailableException('API daftar gudang mengembalikan kegagalan.');
        }

        $payload = $response->json();

        if (! is_array($payload) || filled($payload['error'] ?? null)) {
            throw new WarehouseDirectoryUnavailableException('Response API daftar gudang tidak valid.');
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new WarehouseDirectoryUnavailableException('Data daftar gudang tidak valid.');
        }

        return $data;
    }
}
