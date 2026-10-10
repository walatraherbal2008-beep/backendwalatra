<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingSubsidyRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'scope_type', 'scope_code', 'scope_name', 'min_subtotal',
        'max_subsidy', 'max_weight_grams', 'priority', 'enabled',
        'starts_at', 'ends_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'min_subtotal' => 'decimal:2',
            'max_subsidy' => 'decimal:2',
            'enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
