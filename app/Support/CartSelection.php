<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CartSelection
{
    public static function summary(Collection $items, array $ids): array
    {
        $ids = array_map('intval', $ids);
        $selected = $items->whereIn('id', $ids);
        if ($selected->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'cart_item_ids' => 'Sebagian item yang dipilih tidak tersedia di cart Anda. Silakan muat ulang cart.',
            ]);
        }
        $available = $selected->filter(fn ($item): bool => CartItemAvailability::available($item));

        return [
            'cart_item_ids' => $ids,
            'line_count' => $available->count(),
            'total_items' => $available->sum('quantity'),
            'subtotal' => number_format((float) $available->sum(fn ($item): float => (float) $item->unit_price * $item->quantity), 2, '.', ''),
            'unavailable_item_ids' => $selected->whereNotIn('id', $available->modelKeys())->pluck('id')->values()->all(),
        ];
    }
}
