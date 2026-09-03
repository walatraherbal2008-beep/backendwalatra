<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use League\Uri\Builder;

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
    ];

    protected function casts(): array
    {
        return [
            'expires_at'=>'datetime',
            'verified_at'=>'datetime',
            'invalidated_at'=>'datetime',
            'attempts'=>'integer',
            'max_attempts'=>'integer',


        ];

    }

    public function scopeForPhone(
        Builder $query,
        string $phone,
        string $purpose = 'customer_login',

    ):Builder{
        return $query
            ->where('phone',$phone)
            ->where('purpose',$purpose);
    }

    public function isExpired():bool
    {
        return $this->expires_at instanceof Carbon
                && $this->expires_at->isPast();
    }


    public function isVerified():bool
    {
        return $this->isVerified !== null;

    }

    public function isInvalidated():bool
    {
        return $this->invalidated_at !== null;
    }

    public function hasExceededAttempts():bool
    {
        return $this->attempts >= $this->max_attempts;

    }
    public function isUseable(): bool
    {
        return ! $this->isExpired()
            && ! $this->isVerified()
            && ! $this->isInvalidated()
            && ! $this->hasExceededAttempts();
    }
}
