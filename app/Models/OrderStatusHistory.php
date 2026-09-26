<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'status', 'note', 'created_by'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
