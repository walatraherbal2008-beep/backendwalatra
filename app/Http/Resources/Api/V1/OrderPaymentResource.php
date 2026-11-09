<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $body = $this->response_payload ?? [];
        $va = collect($body['va_numbers'] ?? [])->first();

        return [
            'id' => $this->id, 'method' => $this->method, 'payment_type' => $body['payment_type'] ?? null,
            'transaction_id' => $this->transaction_id, 'provider_order_id' => $this->provider_order_id,
            'state' => $this->state, 'transaction_status' => $this->transaction_status,
            'amount' => $this->amount, 'currency' => $this->currency,
            'bank' => $va['bank'] ?? ($this->method === 'permata_va' ? 'permata' : ($this->method === 'mandiri_bill' ? 'mandiri' : null)),
            'va_number' => $va['va_number'] ?? ($body['permata_va_number'] ?? null),
            'bill_key' => $body['bill_key'] ?? null, 'biller_code' => $body['biller_code'] ?? null,
            'qr_code_url' => $this->method === 'qris' ? url('/api/v1/orders/'.$this->order_id.'/payments/'.$this->id.'/qr-code') : null,
            'expires_at' => $this->expires_at?->toIso8601String(), 'paid_at' => $this->paid_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
