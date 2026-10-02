<?php

namespace App\Services\Shipping;

use App\Exceptions\EverproUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class EverproApiClient
{
    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->request('get', $path, query: $query);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $payload = []): array
    {
        return $this->request('post', $path, payload: $payload);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $query = [], array $payload = []): array
    {
        if (! config('services.everpro.enabled')
            || blank(config('services.everpro.client_key'))
            || blank(config('services.everpro.client_secret'))
        ) {
            throw new EverproUnavailableException('Integrasi Everpro belum dikonfigurasi.');
        }

        try {
            $request = Http::baseUrl(rtrim((string) config('services.everpro.base_url'), '/'))
                ->acceptJson()
                ->timeout((int) config('services.everpro.timeout', 10))
                ->withToken($this->token());

            $response = $method === 'get'
                ? $request->get($path, $query)
                : $request->post($path, $payload);
        } catch (Throwable $exception) {
            throw new EverproUnavailableException('API Everpro tidak dapat dihubungi.', 0, $exception);
        }

        if ($response->failed()) {
            throw new EverproUnavailableException('API Everpro mengembalikan kegagalan.');
        }

        return $response->json() ?? [];
    }

    private function token(): string
    {
        return Cache::remember('everpro.access_token', now()->addMinutes(50), function (): string {
            try {
                $response = Http::baseUrl(rtrim((string) config('services.everpro.base_url'), '/'))
                    ->acceptJson()
                    ->timeout((int) config('services.everpro.timeout', 10))
                    ->post((string) config('services.everpro.token_path', '/auth/v1/token'), [
                        'client_key' => config('services.everpro.client_key'),
                        'client_secret' => config('services.everpro.client_secret'),
                    ]);
            } catch (Throwable $exception) {
                throw new EverproUnavailableException('Token Everpro tidak dapat diperoleh.', 0, $exception);
            }

            if ($response->failed()) {
                throw new EverproUnavailableException('Token Everpro ditolak.');
            }

            $token = data_get($response->json(), 'data.access_token')
                ?? data_get($response->json(), 'data.token')
                ?? data_get($response->json(), 'access_token');

            if (! is_string($token) || $token === '') {
                throw new EverproUnavailableException('Response token Everpro tidak valid.');
            }

            return $token;
        });
    }
}
