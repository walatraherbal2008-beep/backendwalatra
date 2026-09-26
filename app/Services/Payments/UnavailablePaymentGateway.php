<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\Order;

class UnavailablePaymentGateway implements PaymentGateway
{
    public function createPayment(Order $order): array
    {
        throw new PaymentGatewayUnavailableException('Payment gateway belum dikonfigurasi.');
    }
}
