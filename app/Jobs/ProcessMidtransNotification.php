<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentNotification;
use App\Services\Payments\OrderPayments;
use App\Support\PaymentMethods;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class ProcessMidtransNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $notificationId)
    {
        $this->onQueue('payments');
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(OrderPayments $payments): void
    {
        $notification = PaymentNotification::find($this->notificationId);
        if ($notification === null || $notification->processed_at !== null) {
            return;
        }
        if ($notification->environment !== PaymentMethods::environment()) {
            throw new RuntimeException('Environment notifikasi berbeda dari konfigurasi aktif.');
        }
        $payment = OrderPayment::where('provider_order_id', $notification->provider_order_id)->first();
        $order = $payment?->order ?? Order::where('payment_provider', 'midtrans')->where('order_number', $notification->provider_order_id)->first();
        if ($order !== null) {
            $payments->refresh($order, $payment?->id);
        }
        $notification->update(['processed_at' => now()]);
    }
}
