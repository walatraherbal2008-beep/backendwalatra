<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$directory = realpath($input['directory']);
if ($directory === false || ! str_starts_with($directory, sys_get_temp_dir().'/walatra-checkout-lock-audit-')) {
    throw new RuntimeException('Audit output directory is not isolated.');
}
$app = require $root.'/bootstrap/app.php';
$operation = $input['operation'] ?? 'order';
$request = Request::create(
    $operation === 'preview' ? '/api/v1/checkout/preview' : '/api/v1/orders',
    'POST', [], [], [],
    ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'lock-audit-'.$input['user_id']],
    json_encode($input['payload'], JSON_THROW_ON_ERROR),
);
$app->instance('request', $request);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (DB::getDriverName() !== 'pgsql'
    || ! str_starts_with(DB::connection()->getDatabaseName(), 'walatra_checkout_lock_audit_')) {
    throw new RuntimeException('Worker requires an isolated PostgreSQL audit database.');
}
config([
    'app.debug' => false, 'checkout.mode' => 'manual', 'checkout.free_shipping' => true,
    'checkout.stock_mode' => 'check_only', 'checkout.warehouse_selection' => 'nearest_available',
    'checkout.preferred_warehouse_code' => null,
    'checkout.warehouse_selection_timeout' => $input['selection_timeout'] ?? 5,
    'services.warehouse_remaining_stock.enabled' => true,
    'services.warehouse_remaining_stock.base_url' => 'https://stock.audit.test',
    'services.warehouse_remaining_stock.path' => '/stock',
]);
if (isset($input['lock_timeout_ms'])) {
    $timeout = max(1, min(5000, (int) $input['lock_timeout_ms']));
    DB::statement("SET lock_timeout = '{$timeout}ms'");
}
Sanctum::actingAs(User::findOrFail($input['user_id']), ['customer-api']);
$queries = [];
$errors = [];
$firstHttp = null;
$started = hrtime(true);
$app->make(ExceptionHandler::class)->reportable(function (QueryException $exception) use (&$errors): bool {
    $errors[] = ['class' => $exception::class, 'sqlstate' => (string) $exception->getCode()];

    return false;
});
DB::listen(function (QueryExecuted $query) use (&$queries): void {
    if (str_contains(strtolower($query->sql), 'for update')) {
        preg_match('/from "([a-z_]+)"/', $query->sql, $matches);
        $queries[] = ['table' => $matches[1] ?? 'unknown', 'duration_ms' => round($query->time, 2)];
    }
});
Http::preventStrayRequests();
Http::fake(function () use ($directory, $input, &$firstHttp) {
    $firstHttp ??= hrtime(true);
    file_put_contents($directory.'/entered-http.json', json_encode(['at_ns' => $firstHttp]));
    if ($input['hold_stock'] ?? false) {
        $deadline = microtime(true) + 10;
        while (! is_file($directory.'/release-stock') && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($directory.'/release-stock')) {
            throw new RuntimeException('Audit coordinator did not release stock response.');
        }
    }
    if ($input['stock_failure'] ?? false) {
        throw new ConnectionException('Synthetic audit upstream connection failure.');
    }

    return Http::response(['error' => '', 'data' => ['jumlah' => 1]]);
});
file_put_contents($directory.'/ready.json', json_encode(['pid' => DB::selectOne('select pg_backend_pid() as pid')->pid]));
$response = $kernel->handle($request);
$elapsed = (hrtime(true) - $started) / 1e6;
$kernel->terminate($request, $response);
$body = json_decode($response->getContent(), true);
$result = [
    'status' => $response->getStatusCode(), 'code' => $body['code'] ?? null,
    'operation' => $operation, 'order_id' => $operation === 'order' ? ($body['data']['id'] ?? null) : null,
    'warehouse' => $body['data']['shipping_origin']['warehouse_code'] ?? null,
    'elapsed_ms' => round($elapsed, 2),
    'selection_timeout_ms' => (float) config('checkout.warehouse_selection_timeout') * 1000,
    'first_http_delay_ms' => $firstHttp === null ? null : round(($firstHttp - $started) / 1e6, 2),
    'lock_queries' => $queries, 'reported_database_errors' => $errors,
    'transactions_after_request' => DB::transactionLevel(),
    'orders_for_user' => Order::where('user_id', $input['user_id'])->count(),
    'cart_items_for_user' => CartItem::whereHas('cart', fn ($query) => $query->where('user_id', $input['user_id']))->count(),
];
file_put_contents($directory.'/result.json', json_encode($result, JSON_PRETTY_PRINT));
echo json_encode($result).PHP_EOL;
