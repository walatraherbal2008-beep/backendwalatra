<?php

namespace App\Services\Shipping;

use App\Exceptions\EverproUnavailableException;
use App\Services\ExternalApiActivityLogger;
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

        $baseUrl = rtrim((string) config('services.everpro.base_url'), '/');
        $endpoint = $baseUrl.$path;
        $token = $this->token();
        $activityLogger = app(ExternalApiActivityLogger::class);
        $started = hrtime(true);
        $activity = $activityLogger->start('everpro', strtoupper($method).' '.$path, strtoupper($method), $endpoint, $method === 'get' ? $query : $payload);

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->timeout((int) config('services.everpro.timeout', 10))
                ->withToken($token);

            $response = $method === 'get'
                ? $request->get($path, $query)
                : $request->post($path, $payload);
        } catch (Throwable $exception) {
            $activityLogger->finish($activity, $started, $activityLogger->exceptionStatus($exception), exception: $exception);
            throw new EverproUnavailableException('API Everpro tidak dapat dihubungi.', 0, $exception);
        }

        if ($response->failed()) {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), $response->json(), errorMessage: 'API Everpro mengembalikan HTTP '.$response->status().'.');
            throw new EverproUnavailableException('API Everpro mengembalikan kegagalan.');
        }

        $body = $response->json();
        $activityLogger->finish($activity, $started, 'succeeded', $response->status(), is_array($body) ? $body : null);

        return is_array($body) ? $body : [];
    }

    private function token(): string
    {
        return Cache::remember('everpro.access_token', now()->addMinutes(50), function (): string {
            $baseUrl = rtrim((string) config('services.everpro.base_url'), '/');
            $path = (string) config('services.everpro.token_path', '/auth/v1/token');
            $activityLogger = app(ExternalApiActivityLogger::class);
            $started = hrtime(true);
            $activity = $activityLogger->start('everpro', 'Ambil access token', 'POST', $baseUrl.$path, [
                'client_key' => config('services.everpro.client_key'),
                'client_secret' => config('services.everpro.client_secret'),
            ]);

            try {
                $response = Http::baseUrl($baseUrl)
                    ->acceptJson()
                    ->timeout((int) config('services.everpro.timeout', 10))
                    ->post($path, [
                        'client_key' => config('services.everpro.client_key'),
                        'client_secret' => config('services.everpro.client_secret'),
                    ]);
            } catch (Throwable $exception) {
                $activityLogger->finish($activity, $started, $activityLogger->exceptionStatus($exception), exception: $exception);
                throw new EverproUnavailableException('Token Everpro tidak dapat diperoleh.', 0, $exception);
            }

            if ($response->failed()) {
                $activityLogger->finish($activity, $started, 'failed', $response->status(), $response->json(), errorMessage: 'Endpoint token Everpro mengembalikan HTTP '.$response->status().'.');
                throw new EverproUnavailableException('Token Everpro ditolak.');
            }

            $body = $response->json();
            $token = data_get($body, 'data.access_token')
                ?? data_get($body, 'data.token')
                ?? data_get($body, 'access_token');

            if (! is_string($token) || $token === '') {
                $activityLogger->finish($activity, $started, 'failed', $response->status(), is_array($body) ? $body : null, errorMessage: 'Response token Everpro tidak valid.');
                throw new EverproUnavailableException('Response token Everpro tidak valid.');
            }

            $activityLogger->finish($activity, $started, 'succeeded', $response->status(), is_array($body) ? $body : null);

            return $token;
        });
    }
}
