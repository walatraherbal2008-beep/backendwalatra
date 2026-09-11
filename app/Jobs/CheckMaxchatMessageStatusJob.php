<?php

namespace App\Jobs;

use App\Models\OtpChallenge;
use App\Services\Notifications\MaxchatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckMaxchatMessageStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 40;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $challengeId,
        public readonly string $providerMessageId,
        public readonly int $attempt = 1,
    ) {}

    public function uniqueId(): string
    {
        return 'check-maxchat-status:'.$this->challengeId.':'.$this->attempt;
    }

    public function handle(MaxchatService $maxchat): void
    {
        $challenge = OtpChallenge::query()->find($this->challengeId);

        if (! $challenge || $challenge->isVerified()) {
            return;
        }

        $response = $maxchat->getMessageStatus($this->providerMessageId);
        $status = strtolower((string) ($response['status'] ?? 'pending'));

        if ($response === null || $status === 'pending') {
            if ($this->attempt < 3 && $challenge->isUsable()) {
                self::dispatch(
                    challengeId: $this->challengeId,
                    providerMessageId: $this->providerMessageId,
                    attempt: $this->attempt + 1,
                )->onQueue('otp')->delay(now()->addSeconds(5));
            }

            return;
        }

        if (in_array($status, ['failed', 'undelivered'], true)) {
            $challenge->forceFill([
                'delivery_status' => 'failed',
                'failed_at' => now(),
                'invalidated_at' => now(),
                'provider_response' => $response,
            ])->save();

            return;
        }

        $challenge->forceFill([
            'delivery_status' => $status,
            'sent_at' => in_array($status, ['sent', 'delivered', 'read', 'played'], true) ? ($challenge->sent_at ?? now()) : null,
            'delivered_at' => in_array($status, ['delivered', 'read', 'played'], true) ? now() : null,
            'provider_response' => $response,
        ])->save();
    }
}
