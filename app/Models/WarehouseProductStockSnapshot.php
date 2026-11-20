<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseProductStockSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'reported_quantity',
        'available_quantity',
        'status',
        'checked_at',
        'last_attempted_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'reported_quantity' => 'integer',
            'available_quantity' => 'integer',
            'checked_at' => 'datetime',
            'last_attempted_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
