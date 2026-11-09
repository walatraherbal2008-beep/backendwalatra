<?php

use App\Models\Order;
use App\Services\Payments\OrderPayments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'walatra_payment_concurrency_test_')) {
    throw new RuntimeException('Database PostgreSQL khusus pengujian diperlukan.');
}
$data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['services.midtrans.server_key' => 'sandbox-test-key', 'services.midtrans.is_production' => false]);
Http::preventStrayRequests();
Http::fake(function ($request) use ($data) {
    if (! str_ends_with($request->url(), '/v2/charge')) {
        throw new RuntimeException('Request provider tidak diharapkan.');
    }
    file_put_contents($data['ready'], 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($data['release']) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($data['release'])) {
        throw new RuntimeException('Pengujian tidak melepas worker.');
    }

    return Http::response([
        'order_id' => $request['transaction_details']['order_id'], 'transaction_id' => 'concurrency-transaction',
        'currency' => 'IDR', 'gross_amount' => '30000.00', 'status_code' => '201',
        'transaction_status' => 'pending', 'payment_type' => 'bank_transfer',
        'va_numbers' => [['bank' => 'bca', 'va_number' => '123456789']],
    ], 201);
});
[$payment] = app(OrderPayments::class)->create(Order::findOrFail($data['order_id']), 'bca_va', 'concurrent-payment');
echo json_encode(['payment_id' => $payment->id, 'state' => $payment->state], JSON_THROW_ON_ERROR).PHP_EOL;
