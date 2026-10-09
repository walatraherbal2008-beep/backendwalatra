<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class MarketplaceSetting extends Model
{
    protected $fillable = ['key', 'enabled', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function enabled(string $key, bool $default = false): bool
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting?->enabled ?? $default;
    }

    public static function label(string $key): string
    {
        return match ($key) {
            'self_service_store_registration_enabled' => 'Pendaftaran toko mandiri',
            'self_service_store_admin_invitation_enabled' => 'Undangan admin toko mandiri',
            default => $key,
        };
    }
}
