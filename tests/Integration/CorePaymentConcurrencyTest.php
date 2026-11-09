<?php

namespace Tests\Integration;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CorePaymentConcurrencyTest extends TestCase
{
    public function test_concurrent_payment_requests_cannot_issue_a_second_charge(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! str_starts_with(DB::connection()->getDatabaseName(), 'walatra_payment_concurrency_test_')) {
            $this->markTestSkipped('Memerlukan PostgreSQL khusus dengan prefix walatra_payment_concurrency_test_.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config(['services.midtrans.server_key' => 'sandbox-test-key', 'services.midtrans.is_production' => false]);
        Http::preventStrayRequests();
        Http::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);
        $order = Order::create([
            'user_id' => $user->id, 'order_number' => 'WAL-CONCURRENT', 'idempotency_key' => 'concurrent-checkout',
            'status' => 'pending_payment', 'payment_provider' => 'midtrans', 'payment_integration' => 'core_api',
            'payment_due_at' => now()->addDay(), 'currency' => 'IDR', 'subtotal' => 30000, 'shipping_fee' => 0,
            'total' => 30000, 'shipping_address' => ['name' => 'Concurrent', 'phone' => '081234567890', 'address' => 'Test'],
        ]);
        $order->items()->create(['item_type' => 'bundle', 'sku' => 'TEST', 'product_name' => 'Test', 'variant_name' => 'Bundle',
            'store_name' => 'Test', 'quantity' => 1, 'unit_price' => 30000, 'subtotal' => 30000]);
        $directory = sys_get_temp_dir().'/walatra-payment-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = new Process([PHP_BINARY, base_path('tests/Support/payment-worker.php')], base_path(), timeout: 25);
        $worker->setInput(json_encode(['order_id' => $order->id, 'ready' => $directory.'/ready', 'release' => $directory.'/release']));
        try {
            $worker->start();
            $deadline = microtime(true) + 10;
            while (! is_file($directory.'/ready') && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($directory.'/ready', $worker->getErrorOutput());
            $this->assertDatabaseCount('order_payments', 1);
            $this->assertSame('creating', OrderPayment::sole()->state);
            foreach (['concurrent-payment', 'different-key'] as $key) {
                $this->postJson('/api/v1/orders/'.$order->id.'/payment', ['method' => 'bca_va'], ['Idempotency-Key' => $key])
                    ->assertStatus(202)->assertJsonPath('code', 'PAYMENT_PROCESSING');
            }
            $this->assertDatabaseCount('order_payments', 1);
            Http::assertNothingSent();
            touch($directory.'/release');
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $payment = OrderPayment::sole();
            $this->assertSame('pending', $payment->state);
            $this->postJson('/api/v1/orders/'.$order->id.'/payment', ['method' => 'bca_va'], ['Idempotency-Key' => 'concurrent-payment'])
                ->assertOk()->assertJsonPath('data.payment.id', $payment->id);
            $this->assertDatabaseCount('order_payments', 1);
            Http::assertNothingSent();
            $this->assertNull($order->fresh()->payment_operation_token);
        } finally {
            touch($directory.'/release');
            if ($worker->isRunning()) {
                $worker->stop();
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
