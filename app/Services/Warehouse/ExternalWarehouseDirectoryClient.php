<?php

namespace App\Services\Warehouse;

use App\Exceptions\WarehouseDirectoryUnavailableException;
use Illuminate\Support\Facades\Http;
use Throwable;

class ExternalWarehouseDirectoryClient
{
    public function detail(string $code): array
    {
        if (! config('services.warehouse_directory.enabled')) {
            throw new WarehouseDirectoryUnavailableException('API detail titik pengiriman belum dikonfigurasi.');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.warehouse_directory.base_url'), '/'))
                ->acceptJson()
                ->asForm()
                ->timeout((int) config('services.warehouse_directory.timeout', 10))
                ->post((string) config('services.warehouse_directory.detail_path'), ['code' => $code]);
        } catch (Throwable $exception) {
            throw new WarehouseDirectoryUnavailableException('API detail titik pengiriman tidak dapat dihubungi.', 0, $exception);
        }

        $payload = $response->json();
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;
        if ($response->failed() || ! is_array($payload) || filled($payload['error'] ?? null)
            || ! is_array($data) || blank($data['codename'] ?? null) || blank($data['realname'] ?? null)) {
            throw new WarehouseDirectoryUnavailableException('Detail titik pengiriman tidak ditemukan atau respons API tidak valid.');
        }

        $detail = [];
        foreach (['codename', 'area_group', 'initial', 'realname', 'fulladdr', 'coordinates', 'postal_code'] as $field) {
            $value = $data[$field] ?? '';
            if (! is_string($value)) {
                throw new WarehouseDirectoryUnavailableException('Format detail titik pengiriman tidak valid.');
            }
            $detail[$field] = trim($value);
        }

        return $detail;
    }

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
