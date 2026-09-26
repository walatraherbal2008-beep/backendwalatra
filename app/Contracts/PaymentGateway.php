<?php

namespace App\Contracts;

use App\Models\Order;

interface PaymentGateway
{
    /**
     * @return array{payment_reference: string, payment_token: string, payment_url: string|null}
     */
    public function createPayment(Order $order): array;
}
