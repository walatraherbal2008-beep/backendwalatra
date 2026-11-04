<?php

namespace App\Services\Marketplace;

use App\Models\ProductBundle;
use App\Models\ProductVariant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class CheckoutPricing
{
    public function review(Collection $items, bool $lock = false): array
    {
        $variants = ProductVariant::query()->whereIn('id', $items->pluck('product_variant_id')->filter())
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $bundles = ProductBundle::query()->whereIn('id', $items->filter->isBundle()
            ->map(fn ($item) => $item->bundleListing?->product_bundle_id)->filter())
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $changes = [];

        foreach ($items as $item) {
            $master = $item->isBundle()
                ? $bundles->get($item->bundleListing?->product_bundle_id)
                : $variants->get($item->product_variant_id);

            if (! $master || $master->status !== 'active') {
                $this->conflict('STALE_CART', 'Sebagian item cart sudah tidak tersedia. Silakan perbarui cart.');
            }

            $currentPrice = (string) $master->price;
            if ($item->unit_price !== $currentPrice) {
                $changes[] = [
                    'cart_item_id' => $item->id,
                    'type' => $item->item_type,
                    'previous_unit_price' => $item->unit_price,
                    'current_unit_price' => $currentPrice,
                ];
            }

            $item->unit_price = $currentPrice;
        }

        return $changes;
    }

    public function token(int $userId, Collection $items): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $userId,
            'expires_at' => now()->addMinutes(15)->timestamp,
            'fingerprint' => $this->fingerprint($items),
        ], JSON_THROW_ON_ERROR));
    }

    public function confirm(?string $token, int $userId, Collection $items, array $changes): void
    {
        if ($token === null) {
            if ($changes !== []) {
                $this->conflict('PRICE_CHANGED', 'Harga barang berubah. Silakan tinjau ulang preview checkout.', ['price_changes' => $changes]);
            }

            return;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['checkout_token' => 'Token checkout tidak valid. Silakan buat preview baru.']);
        }

        if (! is_array($payload) || ($payload['user_id'] ?? null) !== $userId
            || ! is_int($payload['expires_at'] ?? null) || ! is_string($payload['fingerprint'] ?? null)) {
            throw ValidationException::withMessages(['checkout_token' => 'Token checkout tidak valid untuk akun ini.']);
        }

        if ($payload['expires_at'] <= now()->timestamp) {
            $this->conflict('CHECKOUT_EXPIRED', 'Preview checkout sudah kedaluwarsa. Silakan buat preview baru.');
        }

        if (! hash_equals($payload['fingerprint'], $this->fingerprint($items))) {
            $this->conflict('CHECKOUT_CHANGED', 'Pilihan barang, jumlah, atau harga berubah. Silakan tinjau ulang preview checkout.', ['price_changes' => $changes]);
        }
    }

    private function fingerprint(Collection $items): string
    {
        return hash('sha256', json_encode($items->sortBy('id')->map(fn ($item): array => [
            'id' => $item->id,
            'type' => $item->item_type,
            'listing_id' => $item->store_product_listing_id,
            'bundle_listing_id' => $item->store_bundle_listing_id,
            'variant_id' => $item->product_variant_id,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
        ])->values()->all(), JSON_THROW_ON_ERROR));
    }

    private function conflict(string $code, string $message, ?array $data = null): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false, 'message' => $message, 'code' => $code, 'errors' => null, 'data' => $data,
        ], 409));
    }
}
