<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayUnavailableException;

class UnavailablePaymentGateway implements PaymentGateway
{
    private function unavailable(): never
    {
        throw new PaymentGatewayUnavailableException('Payment gateway belum dikonfigurasi.');
    }

    public function charge(array $payload): array
    {
        $this->unavailable();
    }

    public function status(string $providerOrderId): ?array
    {
        $this->unavailable();
    }

    public function cancel(string $providerOrderId): array
    {
        $this->unavailable();
    }

    public function qrImage(string $url): string
    {
        $this->unavailable();
    }
}
