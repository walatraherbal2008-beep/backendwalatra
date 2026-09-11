<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OtpChallenge extends Model
{
    protected $fillable = [
        'phone',
        'purpose',
        'code_hash',
        'expires_at',
        'attempts',
        'max_attempts',
        'verified_at',
        'invalidated_at',
        'request_ip',
        'user_agent',
        'delivery_status',
        'provider_message_id',
        'sent_at',
        'failed_at',
        'delivered_at',
        'provider_response',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'provider_response' => 'array',
        ];

    }

    public function scopeForPhone(
        Builder $query,
        string $phone,
        string $purpose = 'customer_login',

    ): Builder {
        return $query
            ->where('phone', $phone)
            ->where('purpose', $purpose);
    }

    public function isExpired(): bool
    {
        return $this->expires_at?->lessThanOrEqualTo(now()) ?? true;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;

    }

    public function isInvalidated(): bool
    {
        return $this->invalidated_at !== null;
    }

    public function hasExceededAttempts(): bool
    {
        return $this->attempts >= $this->max_attempts;

    }

    public function isUsable(): bool
    {
        return ! $this->isExpired()
            && ! $this->isVerified()
            && ! $this->isInvalidated()
            && ! $this->hasExceededAttempts();
    }
}
