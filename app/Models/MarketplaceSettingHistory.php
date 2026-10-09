<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class MarketplaceSettingHistory extends Model
{
    protected $fillable = [
        'marketplace_setting_id',
        'old_enabled',
        'new_enabled',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'old_enabled' => 'boolean',
            'new_enabled' => 'boolean',
        ];
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(MarketplaceSetting::class, 'marketplace_setting_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
