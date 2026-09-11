<?php

namespace App\Jobs;

use App\Exceptions\OtpDeliveryException;
use App\Models\OtpChallenge;
use App\Services\Notifications\MaxchatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendCustomerOtpJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 40;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $challengeId,
        public readonly string $phone,
        public readonly string $code,
    ) {}

    public function uniqueId(): string
    {
        return 'send-customer-otp:'.$this->challengeId;
    }

    public function handle(MaxchatService $maxchat): void
    {
        $challenge = OtpChallenge::query()->find($this->challengeId);

        if (! $challenge || ! $challenge->isUsable()) {
            return;
        }

        try {
            $delivery = $maxchat->sendOtp($this->phone, $this->code);
            $status = $delivery['status'];

            $challenge->forceFill([
                'delivery_status' => $status,
                'provider_message_id' => $delivery['provider_message_id'],
                'sent_at' => in_array($status, ['sent', 'delivered', 'read', 'played'], true) ? now() : null,
                'delivered_at' => in_array($status, ['delivered', 'read', 'played'], true) ? now() : null,
                'provider_response' => $delivery['response'],
            ])->save();

            if ($status === 'pending' && $delivery['provider_message_id'] !== null) {
                CheckMaxchatMessageStatusJob::dispatch(
                    challengeId: $challenge->id,
                    providerMessageId: $delivery['provider_message_id'],
                )->onQueue('otp')->delay(now()->addSeconds(2));
            }
        } catch (OtpDeliveryException $exception) {
            if ($exception->isOutcomeUnknown()) {
                $challenge->forceFill([
                    'delivery_status' => 'unknown',
                    'provider_response' => ['error' => $exception->getMessage()],
                ])->save();

                return;
            }

            $challenge->forceFill([
                'delivery_status' => 'failed',
                'failed_at' => now(),
                'invalidated_at' => now(),
                'provider_response' => ['error' => $exception->getMessage()],
            ])->save();
        } catch (Throwable $exception) {
            $challenge->forceFill([
                'delivery_status' => 'unknown',
                'provider_response' => ['error' => 'Job pengiriman OTP gagal diproses.'],
            ])->save();

            throw $exception;
        }
    }
}
