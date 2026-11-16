<?php

namespace App\Services\Notifications;

use App\Exceptions\OtpDeliveryException;
use App\Services\ExternalApiActivityLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MaxchatService
{
    /**
     * @return array{status: string, provider_message_id: ?string, response: array<mixed>}
     */
    public function sendOtp(string $phone, string $code): array
    {
        if (! config('services.maxchat.enabled')) {
            Log::channel('whatsapp')->debug('Pengiriman OTP melalui Maxchat dilewati karena nonaktif.', [
                'phone' => $this->maskPhone($phone),
            ]);

            return [
                'status' => 'skipped',
                'provider_message_id' => null,
                'response' => ['reason' => 'Maxchat disabled.'],
            ];
        }

        $baseUrl = rtrim((string) config('services.maxchat.base_url'), '/');
        $token = (string) config('services.maxchat.api_token');

        if ($baseUrl === '' || $token === '') {
            Log::channel('whatsapp')->error('Konfigurasi Maxchat belum lengkap.', [
                'phone' => $this->maskPhone($phone),
                'base_url_configured' => $baseUrl !== '',
                'token_configured' => $token !== '',
            ]);

            throw new OtpDeliveryException('Konfigurasi layanan WhatsApp belum lengkap.');
        }

        Log::channel('whatsapp')->info('Mengirim OTP melalui Maxchat.', [
            'phone' => $this->maskPhone($phone),
            'endpoint' => $baseUrl.'/api/send',
            'timeout' => config('services.maxchat.timeout'),
        ]);
        $activityLogger = app(ExternalApiActivityLogger::class);
        $started = hrtime(true);
        $activity = $activityLogger->start('maxchat', 'Kirim OTP WhatsApp', 'GET', $baseUrl.'/api/send', [
            'to' => $phone,
            'type' => 'text',
            'text' => '[Konten OTP disamarkan]',
        ]);

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout((int) config('services.maxchat.connect_timeout', 5))
                ->timeout((int) config('services.maxchat.timeout', 30))
                ->get($baseUrl.'/api/send', [
                    'to' => $phone,
                    'type' => 'text',
                    'text' => "Kode OTP Walatra Anda adalah {$code}. Kode berlaku selama 5 menit. Jangan bagikan kode ini kepada siapa pun.",
                ]);
        } catch (ConnectionException $exception) {
            $activityLogger->finish($activity, $started, $activityLogger->exceptionStatus($exception), exception: $exception);
            Log::channel('whatsapp')->warning('Response Maxchat tidak diketahui setelah koneksi timeout.', [
                'phone' => $this->maskPhone($phone),
                'exception' => $exception::class,
                'error' => 'Permintaan ke Maxchat gagal atau timeout.',
            ]);

            throw OtpDeliveryException::unknown('Status pengiriman OTP belum dapat dipastikan.', $exception);
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        Log::channel('whatsapp')->info('Response Maxchat diterima.', [
            'phone' => $this->maskPhone($phone),
            'http_status' => $response->status(),
            'provider_status' => $payload['status'] ?? null,
            'provider_message_id' => isset($payload['id']) ? (string) $payload['id'] : null,
        ]);

        if (! $response->successful()) {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), $this->safeResponse($payload), errorMessage: 'Maxchat mengembalikan HTTP '.$response->status().'.');
            Log::channel('whatsapp')->error('Maxchat mengembalikan response gagal.', [
                'phone' => $this->maskPhone($phone),
                'http_status' => $response->status(),
                'provider_status' => $payload['status'] ?? null,
                'provider_error' => $payload['error'] ?? null,
                'provider_message' => $payload['message'] ?? null,
            ]);

            throw new OtpDeliveryException('Layanan WhatsApp gagal menerima OTP.');
        }

        $providerMessageId = isset($payload['id']) ? (string) $payload['id'] : null;
        $status = strtolower((string) ($payload['status'] ?? 'sent'));

        if ($status === 'pending' && $providerMessageId !== null) {
            $followUp = $this->getMessageStatus($providerMessageId);

            if ($followUp !== null) {
                $followUpStatus = strtolower((string) ($followUp['status'] ?? ''));

                if ($followUpStatus !== '') {
                    $status = $followUpStatus;
                    $payload['status'] = $followUpStatus;
                    $payload['status_response'] = $followUp;
                }
            }
        }

        if (in_array($status, ['failed', 'undelivered'], true)) {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), $this->safeResponse($payload), errorMessage: 'Maxchat melaporkan status pesan '.$status.'.');
            Log::channel('whatsapp')->error('Maxchat melaporkan OTP gagal dikirim.', [
                'phone' => $this->maskPhone($phone),
                'provider_status' => $status,
                'provider_message_id' => isset($payload['id']) ? (string) $payload['id'] : null,
            ]);

            throw new OtpDeliveryException('Layanan WhatsApp gagal mengirim OTP.');
        }

        $activityLogger->finish($activity, $started, 'succeeded', $response->status(), $this->safeResponse($payload));

        Log::channel('whatsapp')->info('OTP diterima Maxchat untuk diproses.', [
            'phone' => $this->maskPhone($phone),
            'provider_status' => $status,
            'provider_message_id' => $providerMessageId,
        ]);

        return [
            'status' => $status,
            'provider_message_id' => isset($payload['id']) ? (string) $payload['id'] : null,
            'response' => $this->safeResponse($payload),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMessageStatus(string $messageId): ?array
    {
        $baseUrl = rtrim((string) config('services.maxchat.base_url'), '/');
        $token = (string) config('services.maxchat.api_token');

        if ($baseUrl === '' || $token === '') {
            return null;
        }

        $activityLogger = app(ExternalApiActivityLogger::class);
        $started = hrtime(true);
        $endpoint = $baseUrl.'/api/messages/'.rawurlencode($messageId);
        $activity = $activityLogger->start('maxchat', 'Cek status pesan', 'GET', $endpoint, [
            'message_id' => $messageId,
            'authorization' => 'Bearer [configured token]',
        ]);

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout((int) config('services.maxchat.connect_timeout', 5))
                ->timeout((int) config('services.maxchat.timeout', 30))
                ->get($baseUrl.'/api/messages/'.rawurlencode($messageId));
        } catch (ConnectionException $exception) {
            $activityLogger->finish($activity, $started, $activityLogger->exceptionStatus($exception), exception: $exception);
            Log::channel('whatsapp')->warning('Pengecekan status pesan Maxchat timeout.', [
                'provider_message_id' => $messageId,
                'exception' => $exception::class,
            ]);

            return null;
        }

        if (! $response->successful()) {
            $providerResponse = $response->json();
            $responsePayload = is_array($providerResponse)
                ? $providerResponse
                : ['raw_response' => $activityLogger->sanitizeResponseBody($response->body())];
            $activityLogger->finish($activity, $started, 'failed', $response->status(), $responsePayload, errorMessage: 'Maxchat mengembalikan HTTP '.$response->status().'.');
            Log::channel('whatsapp')->warning('Pengecekan status pesan Maxchat gagal.', [
                'provider_message_id' => $messageId,
                'http_status' => $response->status(),
            ]);

            return null;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $activityLogger->finish($activity, $started, 'failed', $response->status(), [
                'raw_response' => $activityLogger->sanitizeResponseBody($response->body()),
            ], errorMessage: 'Response status Maxchat bukan JSON yang valid.');

            return null;
        }
        $message = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        Log::channel('whatsapp')->info('Status lanjutan pesan Maxchat diterima.', [
            'provider_message_id' => $messageId,
            'provider_status' => $message['status'] ?? null,
        ]);

        $activityLogger->finish($activity, $started, 'succeeded', $response->status(), $this->safeResponse($message));

        return $this->safeResponse($message);
    }

    /**
     * Remove fields that could contain credentials or message content before persistence.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function safeResponse(array $payload): array
    {
        unset($payload['text'], $payload['access_token'], $payload['authorization'], $payload['token']);

        return $payload;
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) <= 6) {
            return '******';
        }

        return substr($phone, 0, 4)
            .str_repeat('*', max(strlen($phone) - 7, 1))
            .substr($phone, -3);
    }
}
