<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use Midtrans\Config;
use Midtrans\Snap;

class MidtransPaymentGateway implements PaymentGateway
{
    public function createPayment(Order $order): array
    {
        Config::$serverKey = (string) config('services.midtrans.server_key');
        Config::$isProduction = (bool) config('services.midtrans.is_production');
        Config::$isSanitized = true;
        Config::$is3ds = true;

        $token = Snap::getSnapToken([
            'transaction_details' => [
                'order_id' => $order->order_number,
                'gross_amount' => (int) round((float) $order->total),
            ],
            'item_details' => $order->items->map(fn ($item): array => [
                'id' => $item->sku,
                'price' => (int) round((float) $item->unit_price),
                'quantity' => $item->quantity,
                'name' => mb_substr($item->product_name.' - '.$item->variant_name, 0, 50),
            ])->values()->all(),
            'customer_details' => [
                'first_name' => $order->shipping_address['name'] ?? 'Member Walatra',
                'phone' => $order->shipping_address['phone'] ?? null,
            ],
        ]);

        return [
            'payment_reference' => $token,
            'payment_token' => $token,
            'payment_url' => null,
        ];
    }
}
