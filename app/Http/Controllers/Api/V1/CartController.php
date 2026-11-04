<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddCartItemRequest;
use App\Http\Requests\Api\V1\UpdateCartItemRequest;
use App\Http\Resources\Api\V1\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request): CartResource
    {
        return (new CartResource($this->cart($request)->load($this->cartRelations())))->additional([
            'success' => true,
            'message' => 'Cart berhasil diambil.',
        ]);
    }

    public function store(AddCartItemRequest $request): CartResource
    {
        return DB::transaction(function () use ($request): CartResource {
            $this->lockCartOwner($request);

            return $this->addItem($request);
        });
    }

    private function addItem(AddCartItemRequest $request): CartResource
    {
        $cart = $this->cart($request);
        $type = $request->input('type', 'product');

        if ($type === 'bundle') {
            $bundleListing = StoreBundleListing::query()
                ->whereKey($request->integer('bundle_listing_id'))
                ->where('status', 'active')
                ->whereHas('store', fn ($query) => $query->eligibleForMarketplace())
                ->whereHas('bundle', fn ($query) => $query->where('status', 'active')->purchasable())
                ->with('bundle.items.variant.product')
                ->first();

            if (! $bundleListing || ! $bundleListing->bundle->isPurchasable()) {
                throw ValidationException::withMessages([
                    'bundle_listing_id' => 'Bundle toko tidak tersedia.',
                ]);
            }

            DB::transaction(function () use ($cart, $bundleListing, $request): void {
                $item = CartItem::query()
                    ->where('cart_id', $cart->id)
                    ->where('store_bundle_listing_id', $bundleListing->id)
                    ->lockForUpdate()
                    ->first();

                if ($item) {
                    $item->update([
                        'quantity' => min(99, $item->quantity + $request->integer('quantity')),
                        'unit_price' => $bundleListing->bundle->price,
                        'last_added_at' => now(),
                    ]);
                } else {
                    $cart->items()->create([
                        'item_type' => 'bundle',
                        'store_bundle_listing_id' => $bundleListing->id,
                        'quantity' => $request->integer('quantity'),
                        'unit_price' => $bundleListing->bundle->price,
                        'last_added_at' => now(),
                    ]);
                }
            });

            return (new CartResource($cart->fresh()->load($this->cartRelations())))->additional([
                'success' => true,
                'message' => 'Bundle berhasil ditambahkan ke cart.',
            ]);
        }

        $listing = StoreProductListing::query()
            ->whereKey($request->integer('listing_id'))
            ->where('status', 'active')
            ->whereHas('product', fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('variants', fn ($variantQuery) => $variantQuery
                    ->whereKey($request->integer('variant_id'))
                    ->where('status', 'active')))
            ->whereHas('store', fn ($query) => $query->eligibleForMarketplace())
            ->with('product')
            ->first();

        if (! $listing) {
            throw ValidationException::withMessages([
                'listing_id' => 'Produk toko tidak tersedia.',
            ]);
        }

        $variant = ProductVariant::query()
            ->whereKey($request->integer('variant_id'))
            ->where('product_id', $listing->product_id)
            ->where('status', 'active')
            ->first();

        if (! $variant) {
            throw ValidationException::withMessages([
                'variant_id' => 'Varian produk tidak tersedia.',
            ]);
        }

        DB::transaction(function () use ($cart, $listing, $variant, $request): void {
            $item = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('item_type', 'product')
                ->where('store_product_listing_id', $listing->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            if ($item) {
                $item->update([
                    'quantity' => min(99, $item->quantity + $request->integer('quantity')),
                    'unit_price' => $variant->price,
                    'last_added_at' => now(),
                ]);
            } else {
                $cart->items()->create([
                    'item_type' => 'product',
                    'store_product_listing_id' => $listing->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $request->integer('quantity'),
                    'unit_price' => $variant->price,
                    'last_added_at' => now(),
                ]);
            }
        });

        return (new CartResource($cart->fresh()->load($this->cartRelations())))->additional([
            'success' => true,
            'message' => 'Produk berhasil ditambahkan ke cart.',
        ]);
    }

    public function update(UpdateCartItemRequest $request, int $item): CartResource
    {
        return DB::transaction(function () use ($request, $item): CartResource {
            $this->lockCartOwner($request);

            $cart = $this->cart($request);
            $cartItem = $cart->items()->whereKey($item)->firstOrFail();
            $cartItem->update(['quantity' => $request->integer('quantity')]);

            return (new CartResource($cart->fresh()->load($this->cartRelations())))->additional([
                'success' => true,
                'message' => 'Jumlah item cart berhasil diperbarui.',
            ]);
        });
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        return DB::transaction(function () use ($request, $item): JsonResponse {
            $this->lockCartOwner($request);

            $this->cart($request)->items()->whereKey($item)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Item cart dihapus.',
                'code' => 'CART_ITEM_DELETED',
                'data' => null,
            ]);
        });
    }

    public function clear(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $this->lockCartOwner($request);

            $this->cart($request)->items()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Cart dikosongkan.',
                'code' => 'CART_CLEARED',
                'data' => null,
            ]);
        });
    }

    private function lockCartOwner(Request $request): void
    {
        User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
    }

    private function cart(Request $request): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $request->user()->id]);
    }

    private function cartRelations(): array
    {
        return [
            'items' => fn ($query) => $query
                ->orderByRaw('COALESCE(last_added_at, created_at) DESC')
                ->orderByDesc('id'),
            'items.variant.product',
            'items.listing.store',
            'items.bundleListing.store',
            'items.bundleListing.bundle.items.variant.product',
        ];
    }
}
