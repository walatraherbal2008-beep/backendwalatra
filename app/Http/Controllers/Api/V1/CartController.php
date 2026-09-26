<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddCartItemRequest;
use App\Http\Requests\Api\V1\UpdateCartItemRequest;
use App\Http\Resources\Api\V1\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request): CartResource
    {
        return new CartResource($this->cart($request)->load($this->cartRelations()));
    }

    public function store(AddCartItemRequest $request): CartResource
    {
        $cart = $this->cart($request);
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
                ->where('store_product_listing_id', $listing->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            if ($item) {
                $item->update([
                    'quantity' => min(99, $item->quantity + $request->integer('quantity')),
                    'unit_price' => $variant->price,
                ]);
            } else {
                $cart->items()->create([
                    'store_product_listing_id' => $listing->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $request->integer('quantity'),
                    'unit_price' => $variant->price,
                ]);
            }
        });

        return new CartResource($cart->fresh()->load($this->cartRelations()));
    }

    public function update(UpdateCartItemRequest $request, int $item): CartResource
    {
        $cart = $this->cart($request);
        $cartItem = $cart->items()->whereKey($item)->firstOrFail();
        $cartItem->update(['quantity' => $request->integer('quantity')]);

        return new CartResource($cart->fresh()->load($this->cartRelations()));
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->cart($request)->items()->whereKey($item)->delete();

        return response()->json(['success' => true, 'message' => 'Item cart dihapus.']);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->cart($request)->items()->delete();

        return response()->json(['success' => true, 'message' => 'Cart dikosongkan.']);
    }

    private function cart(Request $request): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $request->user()->id]);
    }

    private function cartRelations(): array
    {
        return ['items.variant.product', 'items.listing.store'];
    }
}
