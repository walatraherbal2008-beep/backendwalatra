<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\PaymentGateway;
use App\Contracts\WarehouseStockProvider;
use App\Contracts\ShippingRateProvider;
use App\Models\Order;
use App\Models\Address;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_requires_idempotency_key_and_warehouse_availability(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);

        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertOk();

        $payload = [
            'address_id' => Address::query()->create([
                'user_id' => $user->id,
                'label' => 'Rumah',
                'name' => 'Member Walatra',
                'phone' => '628123456789',
                'address' => 'Jl. Herbal No. 1',
                'city' => 'Bandung',
                'province' => 'Jawa Barat',
                'postal_code' => '40123',
            ])->id,
        ];

        $this->postJson('/api/v1/orders', $payload)
            ->assertUnprocessable();

        $this->postJson('/api/v1/orders', $payload, [
            'Idempotency-Key' => 'order-test-1',
        ])->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_order_snapshots_cart_and_is_idempotent_when_warehouse_is_available(): void
    {
        $this->app->bind(WarehouseStockProvider::class, function (): object {
            return new class implements WarehouseStockProvider
            {
                public function checkAvailability(array $items): array
                {
                    return [
                        'available' => true,
                        'items' => collect($items)->map(fn (array $item): array => [
                            'sku' => $item['sku'],
                            'requested' => $item['quantity'],
                            'available' => $item['quantity'],
                        ])->all(),
                    ];
                }

                public function reserve(array $items, string $reference): string
                {
                    return 'reservation-test';
                }

                public function confirm(string $reservationId): void {}

                public function release(string $reservationId): void {}
            };
        });
        $this->app->bind(ShippingRateProvider::class, function (): object {
            return new class implements ShippingRateProvider
            {
                public function quote(array $address, array $items): array
                {
                    return ['fee' => 10000, 'service' => 'REG', 'eta_days' => 3];
                }
            };
        });
        $this->app->bind(PaymentGateway::class, function (): object {
            return new class implements PaymentGateway
            {
                public function createPayment(Order $order): array
                {
                    return [
                        'payment_reference' => 'payment-reference-test',
                        'payment_token' => 'payment-token-test',
                        'payment_url' => null,
                    ];
                }
            };
        });

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);
        $product = Product::factory()->create(['name' => 'Produk Snapshot']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 15000,
        ]);
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);

        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertOk();

        $payload = [
            'shipping_address' => [
                'name' => 'Member Walatra',
                'phone' => '628123456789',
                'address' => 'Jl. Herbal No. 1',
                'city' => 'Bandung',
                'province' => 'Jawa Barat',
                'postal_code' => '40123',
            ],
        ];
        $headers = ['Idempotency-Key' => 'order-idempotent-1'];

        $first = $this->postJson('/api/v1/orders', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.total', '40000.00')
            ->assertJsonPath('data.shipping_fee', '10000.00');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('cart_items', 0);

        $this->postJson('/api/v1/orders/'.$first->json('data.id').'/payment')
            ->assertOk()
            ->assertJsonPath('data.payment_token', 'payment-token-test');

        $this->postJson('/api/v1/orders', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('orders', 1);

        $this->postJson('/api/v1/orders/'.$first->json('data.id').'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $first->json('data.id'),
            'status' => 'cancelled',
        ]);
    }

    public function test_midtrans_webhook_verifies_signature_and_marks_order_paid(): void
    {
        config(['services.midtrans.server_key' => 'server-key-test']);
        $this->app->bind(WarehouseStockProvider::class, function (): object {
            return new class implements WarehouseStockProvider
            {
                public function checkAvailability(array $items): array { return ['available' => true, 'items' => []]; }
                public function reserve(array $items, string $reference): string { return 'reservation'; }
                public function confirm(string $reservationId): void {}
                public function release(string $reservationId): void {}
            };
        });

        $user = User::factory()->create();
        $order = Order::query()->create([
            'user_id' => $user->id,
            'order_number' => 'WAL-WEBHOOK-1',
            'idempotency_key' => 'webhook-test',
            'status' => 'pending_payment',
            'currency' => 'IDR',
            'subtotal' => 10000,
            'shipping_fee' => 0,
            'total' => 10000,
            'shipping_address' => [
                'name' => 'Member',
                'phone' => '628123456789',
                'address' => 'Address',
                'city' => 'Bandung',
                'province' => 'Jawa Barat',
                'postal_code' => '40123',
            ],
            'stock_reservation_id' => 'reservation',
        ]);

        $statusCode = '200';
        $grossAmount = '10000.00';
        $signature = hash('sha512', $order->order_number.$statusCode.$grossAmount.'server-key-test');

        $this->postJson('/api/v1/payments/midtrans/webhook', [
            'order_id' => $order->order_number,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'stock_reservation_id' => null,
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status' => 'paid',
        ]);
    }
}
