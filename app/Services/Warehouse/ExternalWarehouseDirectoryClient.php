<?php

namespace App\Services\Warehouse;

use App\Exceptions\WarehouseDirectoryUnavailableException;
use App\Services\ExternalApiActivityLogger;
use Illuminate\Support\Facades\Http;
use Throwable;

class ExternalWarehouseDirectoryClient
{
    public function detail(string $code): array
    {
        if (! config('services.warehouse_directory.enabled')) {
            throw new WarehouseDirectoryUnavailableException('API detail titik pengiriman belum dikonfigurasi.');
        }

        $baseUrl = rtrim((string) config('services.warehouse_directory.base_url'), '/');
        $path = (string) config('services.warehouse_directory.detail_path');
        $activityLogger = app(ExternalApiActivityLogger::class);
        $started = hrtime(true);
        $activity = $activityLogger->start('warehouse_directory', 'Detail titik pengiriman', 'POST', $baseUrl.$path, ['code' => $code]);

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->asForm()
                ->timeout((int) config('services.warehouse_directory.timeout', 10))
                ->post($path, ['code' => $code]);
        } catch (Throwable $exception) {
            $activityLogger->finish($activity, $started, $activityLogger->exceptionStatus($exception), exception: $exception);
            throw new WarehouseDirectoryUnavailableException('API detail titik pengiriman tidak dapat dihubungi.', 0, $exception);
        }

        $payload = $response->json();
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;
        if ($response->failed() || ! is_array($payload) || filled($payload['error'] ?? null)
            || ! is_array($data) || blank($data['codename'] ?? null) || blank($data['realname'] ?? null)) {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), is_array($payload) ? $payload : null, errorMessage: 'Respons detail titik pengiriman tidak valid.');
            throw new WarehouseDirectoryUnavailableException('Detail titik pengiriman tidak ditemukan atau respons API tidak valid.');
        }

        $detail = [];
        foreach (['codename', 'area_group', 'initial', 'realname', 'fulladdr', 'coordinates', 'postal_code'] as $field) {
            $value = $data[$field] ?? '';
            if (! is_string($value)) {
                $activityLogger->finish($activity, $started, 'failed', $response->status(), $payload, errorMessage: 'Format detail titik pengiriman tidak valid.');
                throw new WarehouseDirectoryUnavailableException('Format detail titik pengiriman tidak valid.');
            }
            $detail[$field] = trim($value);
        }

        $activityLogger->finish($activity, $started, 'succeeded', $response->status(), $payload);

        return $detail;
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $areaGroup = null): array
    {
        if (! config('services.warehouse_directory.enabled')) {
            throw new WarehouseDirectoryUnavailableException('API daftar gudang belum dikonfigurasi.');
        }

        $baseUrl = rtrim((string) config('services.warehouse_directory.base_url'), '/');
        $path = (string) config('services.warehouse_directory.path');
        $activityLogger = app(ExternalApiActivityLogger::class);
        $started = hrtime(true);
        $payload = $areaGroup === null ? null : ['area_group' => $areaGroup];
        $activity = $activityLogger->start('warehouse_directory', 'Daftar titik pengiriman', 'POST', $baseUrl.$path, $payload);

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->timeout((int) config('services.warehouse_directory.timeout', 10));

            $response = $areaGroup === null
                ? $request->post($path)
                : $request->asMultipart()->post(
                    $path,
                    [['name' => 'area_group', 'contents' => $areaGroup]],
                );
        } catch (Throwable $exception) {
            $activityLogger->finish($activity, $started, $activityLogger->exceptionStatus($exception), exception: $exception);
            throw new WarehouseDirectoryUnavailableException('API daftar gudang tidak dapat dihubungi.', 0, $exception);
        }

        if ($response->failed()) {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), $response->json(), errorMessage: 'API daftar gudang mengembalikan HTTP '.$response->status().'.');
            throw new WarehouseDirectoryUnavailableException('API daftar gudang mengembalikan kegagalan.');
        }

        $payload = $response->json();

        if (! is_array($payload) || filled($payload['error'] ?? null)) {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), is_array($payload) ? $payload : null, errorMessage: 'Respons API daftar gudang tidak valid.');
            throw new WarehouseDirectoryUnavailableException('Response API daftar gudang tidak valid.');
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), $payload, errorMessage: 'Data daftar gudang tidak valid.');
            throw new WarehouseDirectoryUnavailableException('Data daftar gudang tidak valid.');
        }

        $activityLogger->finish($activity, $started, 'succeeded', $response->status(), $payload);

        return $data;
    }
}
