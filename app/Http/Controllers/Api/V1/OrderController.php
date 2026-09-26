<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\WarehouseStockProvider;
use App\Contracts\ShippingRateProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Address;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        return OrderResource::collection(
            Order::query()
                ->where('user_id', $request->user()->id)
                ->with('items')
                ->latest()
                ->paginate(15),
        )->additional([
            'success' => true,
            'message' => 'Daftar order berhasil diambil.',
        ]);
    }

    public function show(Request $request, int $order): OrderResource
    {
        $record = Order::query()
            ->where('user_id', $request->user()->id)
            ->with('items')
            ->findOrFail($order);

        return new OrderResource($record);
    }

    public function cancel(
        Request $request,
        int $order,
        WarehouseStockProvider $warehouse,
    ): OrderResource|JsonResponse {
        $record = Order::query()
            ->where('user_id', $request->user()->id)
            ->with('items')
            ->findOrFail($order);

        if ($record->status !== 'pending_payment') {
            throw ValidationException::withMessages([
                'status' => 'Order ini tidak dapat dibatalkan pada status sekarang.',
            ]);
        }

        if ($record->stock_reservation_id !== null) {
            $warehouse->release($record->stock_reservation_id);
        }

        DB::transaction(function () use ($record): void {
            $record->update([
                'status' => 'cancelled',
                'stock_reservation_id' => null,
            ]);
            $record->statusHistories()->create([
                'status' => 'cancelled',
                'note' => 'Order dibatalkan oleh member.',
            ]);
        });

        return new OrderResource($record->fresh()->load('items'));
    }

    public function store(
        CreateOrderRequest $request,
        WarehouseStockProvider $warehouse,
        ShippingRateProvider $shipping,
    ): OrderResource|JsonResponse {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Header Idempotency-Key wajib diisi dan maksimal 100 karakter.',
            ], 422);
        }

        $existing = Order::query()
            ->where('user_id', $request->user()->id)
            ->where('idempotency_key', $idempotencyKey)
            ->with('items')
            ->first();

        if ($existing) {
            return new OrderResource($existing);
        }

        $shippingAddress = $request->validated('shipping_address');

        if ($request->filled('address_id')) {
            $shippingAddress = Address::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->integer('address_id'))
                ->only([
                    'name',
                    'phone',
                    'province',
                    'city',
                    'district',
                    'postal_code',
                    'address',
                ]);
        }

        $cart = Cart::query()
            ->where('user_id', $request->user()->id)
            ->with(['items.variant.product', 'items.listing.store'])
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart masih kosong.',
            ], 422);
        }

        $validItemCount = CartItem::query()
            ->where('cart_id', $cart->id)
            ->whereHas('listing', fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('product', fn ($productQuery) => $productQuery->where('status', 'active')))
            ->whereHas('variant', fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('product', fn ($productQuery) => $productQuery->where('status', 'active')))
            ->whereHas('listing.store', fn ($query) => $query->eligibleForMarketplace())
            ->count();

        if ($validItemCount !== $cart->items->count()) {
            return response()->json([
                'success' => false,
                'message' => 'Sebagian item cart sudah tidak tersedia. Silakan perbarui cart.',
            ], 409);
        }

        $stockItems = $cart->items->map(fn ($item): array => [
            'sku' => $item->variant->sku,
            'quantity' => $item->quantity,
        ])->values()->all();
        $shippingQuote = $shipping->quote($shippingAddress, $stockItems);
        $availability = $warehouse->checkAvailability($stockItems);

        if (! $availability['available']) {
            return response()->json([
                'success' => false,
                'message' => 'Stok gudang belum dapat divalidasi.',
                'data' => ['stock' => $availability['items']],
            ], 503);
        }

        $reference = (string) Str::uuid();
        $reservationId = $warehouse->reserve($stockItems, $reference);

        try {
            $order = DB::transaction(function () use ($request, $cart, $idempotencyKey, $reservationId, $shippingAddress, $shippingQuote): Order {
                $subtotal = (float) $cart->items->sum(
                    fn ($item): float => (float) $item->variant->price * $item->quantity,
                );
                $shippingFee = (float) $shippingQuote['fee'];

                $order = Order::query()->create([
                    'user_id' => $request->user()->id,
                    'order_number' => 'WAL-'.Str::upper(Str::random(12)),
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'pending_payment',
                    'currency' => 'IDR',
                    'subtotal' => $subtotal,
                    'shipping_fee' => $shippingFee,
                    'shipping_service' => $shippingQuote['service'],
                    'total' => $subtotal + $shippingFee,
                    'shipping_address' => $shippingAddress,
                    'stock_reservation_id' => $reservationId,
                    'placed_at' => now(),
                ]);

                foreach ($cart->items as $item) {
                    $unitPrice = $item->variant->price;
                    $order->items()->create([
                        'store_product_listing_id' => $item->store_product_listing_id,
                        'product_variant_id' => $item->product_variant_id,
                        'sku' => $item->variant->sku,
                        'product_name' => $item->variant->product->name,
                        'variant_name' => $item->variant->name,
                        'store_name' => $item->listing->store->name,
                        'quantity' => $item->quantity,
                        'unit_price' => $unitPrice,
                        'subtotal' => (float) $unitPrice * $item->quantity,
                    ]);
                }

                $order->statusHistories()->create([
                    'status' => 'pending_payment',
                    'note' => 'Order dibuat dan menunggu pembayaran.',
                ]);

                $cart->items()->delete();

                return $order;
            });
        } catch (\Throwable $exception) {
            $warehouse->release($reservationId);
            throw $exception;
        }

        return new OrderResource($order->load('items'));
    }
}
