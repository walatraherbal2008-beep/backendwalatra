<?php

namespace App\Contracts;

interface PaymentGateway
{
    public function charge(array $payload): array;

    public function status(string $providerOrderId): ?array;

    public function cancel(string $providerOrderId): array;

    public function qrImage(string $url): string;
}
