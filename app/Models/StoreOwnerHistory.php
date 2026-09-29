<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreOwnerHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'previous_owner_user_id',
        'new_owner_user_id',
        'changed_by',
        'reason',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function previousOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_owner_user_id');
    }

    public function newOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_owner_user_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
