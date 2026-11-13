<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentCommunicationLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['request_payload' => 'array', 'response_payload' => 'array', 'http_status' => 'integer',
            'duration_ms' => 'integer', 'requested_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'order_payment_id');
    }
}
