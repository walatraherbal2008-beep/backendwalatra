<?php

namespace App\Services\Marketplace;

use App\Contracts\ShippingRateProvider;
use App\Models\Address;
use App\Models\CartItem;
use App\Services\Shipping\ShippingCostCalculator;
use App\Support\CheckoutShipping;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CheckoutReview
{
    private const ADDRESS_FIELDS = [
        'label', 'name', 'phone', 'address', 'address_note', 'province', 'province_id', 'province_name',
        'city', 'city_id', 'city_name', 'district', 'district_id', 'district_name', 'sub_district_id',
        'sub_district_name', 'postal_code', 'latitude', 'longitude',
    ];

    public function address(int $userId, ?int $addressId, ?array $directAddress, bool $lock = false): ?array
    {
        if ($addressId !== null) {
            $query = Address::query()->where('user_id', $userId);
            if ($lock) {
                $query->lockForUpdate();
            }
            $snapshot = $query->findOrFail($addressId)->only(self::ADDRESS_FIELDS);
        } elseif ($directAddress !== null) {
            $snapshot = array_intersect_key($directAddress, array_flip(self::ADDRESS_FIELDS));
        } else {
            return null;
        }

        $fingerprint = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $value = $snapshot[$field] ?? null;
            $fingerprint[$field] = in_array($field, ['latitude', 'longitude'], true)
                ? ($value === null ? null : number_format((float) $value, 7, '.', ''))
                : ($value === null ? null : (string) $value);
        }

        return ['snapshot' => $snapshot, 'fingerprint' => $fingerprint];
    }

    public function quote(
        ShippingRateProvider $shipping,
        ShippingCostCalculator $shippingCosts,
        array $address,
        array $addressFingerprint,
        Collection $items,
    ): array {
        $stockItems = $this->stockItems($items);
        $subtotal = (float) $items->sum(fn (CartItem $item): float => (float) $item->unit_price * $item->quantity);
        $shippingQuote = CheckoutShipping::quote($shipping, $address, $stockItems);
        $shippingCost = $shippingCosts->calculate($shippingQuote, $address, $subtotal, $this->weightGrams($items));
        $shippingData = [
            'service' => $shippingQuote['service'],
            'eta_days' => $shippingQuote['eta_days'] ?? null,
            'gross_fee' => number_format($shippingCost['gross_fee'], 2, '.', ''),
            'subsidy' => number_format($shippingCost['subsidy'], 2, '.', ''),
            'fee' => number_format($shippingCost['fee'], 2, '.', ''),
            'subsidy_rule_id' => $shippingCost['rule_id'],
        ];
        $subtotalFormatted = number_format($subtotal, 2, '.', '');
        $totalFormatted = number_format($subtotal + (float) $shippingCost['fee'], 2, '.', '');

        return [
            'stock_items' => $stockItems,
            'subtotal' => $subtotal,
            'subtotal_formatted' => $subtotalFormatted,
            'shipping_quote' => $shippingQuote,
            'shipping_cost' => $shippingCost,
            'shipping' => $shippingData,
            'total' => $totalFormatted,
            'fingerprint' => [
                'shipping_address' => $addressFingerprint,
                'subtotal' => $subtotalFormatted,
                'shipping' => $shippingData,
                'total' => $totalFormatted,
            ],
        ];
    }

    public function stockItems(Collection $items): array
    {
        return $items
            ->flatMap(function (CartItem $item) {
                if (! $item->isBundle()) {
                    return collect([['sku' => $item->variant->sku, 'quantity' => $item->quantity]]);
                }

                return $item->bundleListing->bundle->items->map(fn ($component): array => [
                    'sku' => $component->variant->sku,
                    'quantity' => $component->quantity * $item->quantity,
                ]);
            })
            ->groupBy('sku')
            ->map(fn ($skuItems, string $sku): array => [
                'sku' => $sku,
                'quantity' => $skuItems->sum('quantity'),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{product_code: string, quantity: int}> */
    public function productRequirements(Collection $items): array
    {
        return $items->flatMap(function (CartItem $item) {
            if (! $item->isBundle()) {
                return [['product_code' => $item->variant->product->product_code, 'quantity' => $item->quantity]];
            }

            return $item->bundleListing->bundle->items->map(fn ($component): array => [
                'product_code' => $component->variant->product->product_code,
                'quantity' => $component->quantity * $item->quantity,
            ]);
        })->groupBy('product_code')->map(function ($rows, string $code): array {
            if (trim($code) === '') {
                throw ValidationException::withMessages(['product_code' => 'Produk belum memiliki kode stok yang valid.']);
            }

            return ['product_code' => $code, 'quantity' => (int) $rows->sum('quantity')];
        })->sortBy('product_code', SORT_STRING)->values()->all();
    }

    private function weightGrams(Collection $items): int
    {
        return (int) $items->sum(function (CartItem $item): int {
            if ($item->isBundle()) {
                return (int) $item->bundleListing->bundle->items->sum(
                    fn ($component): int => (int) $component->variant->weight_grams * $component->quantity * $item->quantity,
                );
            }

            return (int) $item->variant->weight_grams * $item->quantity;
        });
    }
}
