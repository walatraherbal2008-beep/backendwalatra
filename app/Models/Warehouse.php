<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = [
        'code', 'realname', 'codename', 'initial', 'area_group', 'fulladdr', 'postal_code',
        'coordinates', 'latitude', 'longitude', 'is_active', 'is_listed', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7', 'longitude' => 'decimal:7',
            'is_active' => 'boolean', 'is_listed' => 'boolean', 'synced_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_listed', true);
    }
}
