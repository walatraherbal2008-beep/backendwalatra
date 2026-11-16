<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\ShippingRateProvider;
use App\Contracts\WarehouseStockProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketplace\CheckoutPricing;
use App\Services\Marketplace\CheckoutReview;
use App\Services\Marketplace\CheckoutShippingOrigin;
use App\Services\Marketplace\OrderImageSnapshots;
use App\Services\Payments\OrderPayments;
use App\Services\Shipping\ShippingCostCalculator;
use App\Support\CartItemAvailability;
use App\Support\CheckoutSimulation;
use App\Support\CheckoutStock;
use App\Support\ManualCheckout;
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
                ->with(['items.media', 'activePayment'])
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
            ->with(['items.media', 'activePayment'])
            ->findOrFail($order);

        return (new OrderResource($record))->additional([
            'success' => true,
            'message' => 'Detail order berhasil diambil.',
        ]);
    }

    public function cancel(
        Request $request,
        int $order,
        WarehouseStockProvider $warehouse,
    ): OrderResource|JsonResponse {
        $existing = Order::where('user_id', $request->user()->id)->findOrFail($order);
        if ($existing->payment_provider === 'midtrans') {
            $cancelled = app(OrderPayments::class)->cancel($existing);

            return (new OrderResource($cancelled->load('items.media', 'activePayment')))->additional(['success' => true, 'message' => 'Order berhasil dibatalkan.']);
        }
        $record = DB::transaction(function () use ($request, $order, $warehouse): Order {
            $record = Order::query()
                ->where('user_id', $request->user()->id)
                ->with(['items.media', 'activePayment'])
                ->lockForUpdate()
                ->findOrFail($order);

            if ($record->status !== 'pending_payment') {
                throw ValidationException::withMessages([
                    'status' => 'Order ini tidak dapat dibatalkan pada status sekarang.',
                ]);
            }

            if ($record->stock_reservation_id !== null) {
                $warehouse->release($record->stock_reservation_id);
            }

            $record->update([
                'status' => 'cancelled',
                'stock_reservation_id' => null,
            ]);
            $record->statusHistories()->create([
                'status' => 'cancelled',
                'note' => 'Order dibatalkan oleh member.',
                'created_by' => $request->user()->id,
            ]);

            return $record;
        });

        return (new OrderResource($record->fresh()->load(['items.media', 'activePayment'])))->additional([
            'success' => true,
            'message' => 'Order berhasil dibatalkan.',
        ]);
    }

    public function store(
        CreateOrderRequest $request,
        WarehouseStockProvider $warehouse,
        ShippingRateProvider $shipping,
        ShippingCostCalculator $shippingCosts,
        OrderImageSnapshots $snapshots,
        CheckoutReview $review,
    ): OrderResource|JsonResponse {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Header Idempotency-Key wajib diisi dan maksimal 100 karakter.',
            ], 422);
        }

        try {
            return DB::transaction(function () use ($request, $warehouse, $shipping, $shippingCosts, $idempotencyKey, $snapshots, $review) {
                User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

                return $this->createFromCart($request, $warehouse, $shipping, $shippingCosts, $idempotencyKey, $snapshots, $review);
            });
        } catch (\Throwable $exception) {
            $snapshots->discard();
            throw $exception;
        }
    }

    private function createFromCart(
        CreateOrderRequest $request,
        WarehouseStockProvider $warehouse,
        ShippingRateProvider $shipping,
        ShippingCostCalculator $shippingCosts,
        string $idempotencyKey,
        OrderImageSnapshots $snapshots,
        CheckoutReview $review,
    ): OrderResource|JsonResponse {
        $existing = Order::query()
            ->where('user_id', $request->user()->id)
            ->where('idempotency_key', $idempotencyKey)
            ->with(['items.media', 'activePayment'])
            ->first();

        if ($existing) {
            return (new OrderResource($existing))->additional([
                'success' => true,
                'message' => 'Order sebelumnya berhasil diambil.',
            ]);
        }

        if (! $request->filled('checkout_token')) {
            throw ValidationException::withMessages([
                'checkout_token' => 'Preview checkout wajib dilakukan sebelum membuat order. Kirim checkout_token dari preview.',
            ]);
        }

        $addressContext = $review->address(
            $request->user()->id,
            $request->filled('address_id') ? $request->integer('address_id') : null,
            $request->validated('shipping_address'),
            lock: true,
        );
        $shippingAddress = $addressContext['snapshot'];

        $cart = Cart::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'items' => fn ($query) => $query->orderBy('id')->lockForUpdate(),
                'items.variant.product.media',
                'items.variant.media',
                'items.listing.media',
                'items.bundleListing.media',
                'items.listing.store.owner.roles',
                'items.bundleListing.store.owner.roles',
                'items.bundleListing.bundle.items.variant.product.media',
                'items.bundleListing.bundle.items.variant.media',
            ])
            ->first();

        $selectedItems = $request->selectedCartItems($cart?->items ?? collect());
        $cart?->setRelation('items', $selectedItems);

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart masih kosong.',
                'code' => 'CART_EMPTY',
                'errors' => null,
            ], 422);
        }

        if ($cart->items->contains(fn (CartItem $item): bool => ! CartItemAvailability::available($item))) {
            return response()->json([
                'success' => false,
                'message' => 'Sebagian item cart sudah tidak tersedia. Silakan perbarui cart.',
                'code' => 'STALE_CART',
                'errors' => null,
            ], 409);
        }

        $pricing = app(CheckoutPricing::class);
        $priceChanges = $pricing->review($cart->items, lock: true);
        $origin = app(CheckoutShippingOrigin::class)->resolve($cart->items, lock: true);
        $stockItems = $review->stockItems($cart->items);
        $manual = ManualCheckout::enabled();
        $checkoutQuote = $review->quote(
            $shipping,
            $shippingCosts,
            $shippingAddress,
            $addressContext['fingerprint'],
            $cart->items,
        );
        $pricing->confirm(
            $request->validated('checkout_token'),
            $request->user()->id,
            $cart->items,
            $priceChanges,
            $origin['warehouse_code'],
            $checkoutQuote['fingerprint'],
        );
        $shippingQuote = $checkoutQuote['shipping_quote'];
        $shippingCost = $checkoutQuote['shipping_cost'];
        $subtotal = $checkoutQuote['subtotal'];
        $unlimitedStock = CheckoutStock::unlimited();
        $availability = $unlimitedStock ? ['available' => true, 'items' => []] : $warehouse->checkAvailability($stockItems);

        if (! $availability['available']) {
            return response()->json([
                'success' => false,
                'message' => 'Stok gudang belum dapat divalidasi.',
                'code' => 'STOCK_UNAVAILABLE',
                'errors' => null,
                'data' => ['stock' => $availability['items']],
            ], 503);
        }

        $reference = (string) Str::uuid();
        $reservationId = $unlimitedStock ? null : $warehouse->reserve($stockItems, $reference);

        try {
            $order = DB::transaction(function () use ($request, $cart, $idempotencyKey, $reservationId, $shippingAddress, $shippingQuote, $shippingCost, $subtotal, $manual, $snapshots, $origin): Order {
                $shippingFee = (float) $shippingCost['fee'];
                $simulated = CheckoutSimulation::enabled();

                $order = Order::query()->create([
                    'user_id' => $request->user()->id,
                    'order_number' => 'WAL-'.Str::upper(Str::random(12)),
                    'idempotency_key' => $idempotencyKey,
                    'status' => $simulated ? 'paid' : 'pending_payment',
                    'payment_provider' => $manual ? 'manual' : ($simulated ? 'simulation' : 'midtrans'),
                    'payment_integration' => ! $manual && ! $simulated ? 'core_api' : null,
                    'payment_due_at' => ! $manual && ! $simulated ? now()->addHours(max(1, (int) config('services.midtrans.order_ttl_hours', 24))) : null,
                    'payment_reference' => $simulated ? 'SIM-PAY-'.Str::uuid() : null,
                    'currency' => 'IDR',
                    'subtotal' => $subtotal,
                    'shipping_gross_fee' => $shippingCost['gross_fee'],
                    'shipping_subsidy' => $shippingCost['subsidy'],
                    'shipping_fee' => $shippingFee,
                    'shipping_service' => $shippingQuote['service'],
                    'total' => $subtotal + $shippingFee,
                    'shipping_address' => $shippingAddress,
                    'shipping_origin' => $origin,
                    'stock_mode' => CheckoutStock::mode(),
                    'stock_reservation_id' => $simulated ? null : $reservationId,
                    'placed_at' => now(),
                ]);

                foreach ($cart->items as $item) {
                    if ($item->isBundle()) {
                        $bundle = $item->bundleListing->bundle;
                        $orderItem = $order->items()->create([
                            'item_type' => 'bundle',
                            'store_bundle_listing_id' => $item->store_bundle_listing_id,
                            'sku' => 'BUNDLE-'.$bundle->code,
                            'product_name' => $bundle->name,
                            'variant_name' => 'Bundle',
                            'store_name' => $item->bundleListing->store->name,
                            'bundle_components' => $bundle->items->map(fn ($component): array => [
                                'variant_id' => $component->variant->id,
                                'sku' => $component->variant->sku,
                                'product_name' => $component->variant->product->name,
                                'variant_name' => $component->variant->name,
                                'quantity' => $component->quantity,
                            ])->values()->all(),
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'subtotal' => (float) $item->unit_price * $item->quantity,
                        ]);

                        $snapshots->capture($item, $orderItem);

                        continue;
                    }

                    $unitPrice = $item->unit_price;
                    $orderItem = $order->items()->create([
                        'item_type' => 'product',
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
                    $snapshots->capture($item, $orderItem);
                }

                $order->statusHistories()->create([
                    'status' => $order->status,
                    'note' => $simulated
                        ? 'Order simulasi dibuat dan dianggap sudah dibayar. Stok serta ongkir menggunakan simulasi.'
                        : ($manual ? 'Order dibuat. Stok dan pembayaran menunggu verifikasi manual admin.' : 'Order dibuat dan menunggu pembayaran.'),
                    'created_by' => $request->user()->id,
                ]);

                $cart->items()->whereIn('id', $cart->items->modelKeys())->delete();

                return $order;
            });
        } catch (\Throwable $exception) {
            if ($reservationId !== null) {
                $warehouse->release($reservationId);
            }
            throw $exception;
        }

        return (new OrderResource($order->load(['items.media', 'activePayment'])))->additional([
            'success' => true,
            'message' => 'Order berhasil dibuat.',
        ]);
    }
}
