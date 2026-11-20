<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseStockSyncRun extends Model
{
    protected $fillable = [
        'requested_by',
        'status',
        'total',
        'checked',
        'succeeded',
        'failed',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
