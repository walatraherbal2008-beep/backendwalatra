<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Payments\OrderPayments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileOrderPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $orderId)
    {
        $this->onQueue('payments');
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(OrderPayments $payments): void
    {
        $order = Order::find($this->orderId);
        if ($order && $order->payment_provider === 'midtrans') {
            $payments->refresh($order);
        }
    }
}
