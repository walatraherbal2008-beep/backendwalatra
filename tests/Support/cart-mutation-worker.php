<?php

use App\Models\ProductBundle;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$request = Request::create($input['path'] ?? '/', $input['method'] ?? 'GET', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
], json_encode($input['payload'] ?? [], JSON_THROW_ON_ERROR));
$app->instance('request', $request);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'walatra_cart_concurrency_test_')) {
    throw new RuntimeException('Worker hanya boleh memakai database PostgreSQL pengujian terisolasi.');
}

config(['checkout.mode' => 'manual', 'checkout.free_shipping' => true]);
Sanctum::actingAs(User::findOrFail($input['user_id']), ['customer-api']);
$pid = DB::selectOne('select pg_backend_pid() as pid')->pid;
DB::connection()->beforeExecuting(function (string $query) use ($pid, $input): void {
    if ((str_contains($query, '"users"') && str_contains($query, 'for update'))
        || (($input['operation'] ?? null) === 'price' && str_starts_with($query, 'update '))) {
        echo 'locking:'.$pid."\n";
        flush();
    }
});
if (($input['operation'] ?? null) === 'price') {
    $master = $input['type'] === 'product'
        ? ProductVariant::findOrFail($input['id'])
        : ProductBundle::findOrFail($input['id']);
    $master->update(['price' => 99000]);
    echo 'result:'.json_encode(['status' => 200], JSON_THROW_ON_ERROR)."\n";
    exit;
}
$response = $kernel->handle($request);
echo 'result:'.json_encode(['status' => $response->getStatusCode()], JSON_THROW_ON_ERROR)."\n";
$kernel->terminate($request, $response);
