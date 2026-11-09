<?php

namespace App\Services\Marketplace;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StoreTransactionReport
{
    public const STATUSES = [
        'pending_payment' => 'Menunggu pembayaran', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan',
        'expired' => 'Kedaluwarsa', 'refunded' => 'Dikembalikan', 'payment_review' => 'Perlu pemeriksaan pembayaran',
    ];

    public static function query(): Builder
    {
        return Order::query()->whereIn('payment_provider', ['manual', 'midtrans'])
            ->with(['user', 'activePayment', 'payments', 'statusHistories.createdBy',
                'items.productListing' => fn ($query) => $query->withTrashed(),
                'items.bundleListing' => fn ($query) => $query->withTrashed(),
            ]);
    }

    public static function itemsForStore(Builder $query, int $storeId): Builder
    {
        return $query->where(fn (Builder $items) => $items
            ->whereHas('productListing', fn (Builder $listing) => $listing->withTrashed()->where('store_id', $storeId))
            ->orWhereHas('bundleListing', fn (Builder $listing) => $listing->withTrashed()->where('store_id', $storeId)));
    }

    public static function filterStore(Builder $query, int $storeId): Builder
    {
        return $query->whereHas('items', fn (Builder $items) => self::itemsForStore($items, $storeId))
            ->withSum(['items as store_subtotal' => fn (Builder $items) => self::itemsForStore($items, $storeId)], 'subtotal');
    }

    public static function selectedItems(Order $order, ?int $storeId): Collection
    {
        return $storeId === null ? collect() : $order->items->filter(fn (OrderItem $item): bool => ($item->productListing?->store_id ?? $item->bundleListing?->store_id) === $storeId)->values();
    }

    public static function summary(Builder $query, ?int $storeId): array
    {
        $amount = $storeId === null ? 'total' : 'store_subtotal';
        $result = DB::query()->fromSub((clone $query)->withoutEagerLoads()->reorder(), 'transactions')
            ->selectRaw("COUNT(*) AS total_count,
                COALESCE(SUM(CASE WHEN status = 'pending_payment' THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
                COALESCE(SUM(CASE WHEN status = 'payment_review' THEN 1 ELSE 0 END), 0) AS review_count,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN {$amount} ELSE 0 END), 0) AS paid_amount")
            ->first();

        return ['total' => (int) $result->total_count, 'pending' => (int) $result->pending_count,
            'paid' => (int) $result->paid_count, 'review' => (int) $result->review_count,
            'paid_amount' => (string) $result->paid_amount];
    }

    public static function dateBoundary(string $date, bool $nextDay = false): string
    {
        $boundary = Carbon::parse($date, 'Asia/Jakarta')->startOfDay();
        if ($nextDay) {
            $boundary->addDay();
        }

        return DB::getDriverName() === 'pgsql' ? $boundary->toIso8601String()
            : $boundary->setTimezone(config('app.timezone'))->toDateTimeString();
    }

    public static function environment(Order $order): string
    {
        return $order->payment_provider === 'manual' ? 'manual' : ($order->activePayment?->environment ?? 'unknown');
    }
}
