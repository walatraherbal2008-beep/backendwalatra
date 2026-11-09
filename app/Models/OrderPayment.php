<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'request_payload' => 'array', 'response_payload' => 'array',
            'expires_at' => 'datetime', 'paid_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isActive(): bool
    {
        return in_array($this->state, ['creating', 'unknown', 'pending', 'cancelling'], true);
    }
}
