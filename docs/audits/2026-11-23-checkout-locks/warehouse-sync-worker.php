<?php

use App\Contracts\WarehouseDirectoryProvider;
use App\Models\Warehouse;
use App\Services\Warehouse\ExternalWarehouseDirectoryClient;
use App\Services\Warehouse\WarehouseSyncService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$directory = realpath($input['directory']);
if ($directory === false || ! str_starts_with($directory, sys_get_temp_dir().'/walatra-checkout-lock-audit-')) {
    throw new RuntimeException('Audit output directory is not isolated.');
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'pgsql'
    || ! str_starts_with(DB::connection()->getDatabaseName(), 'walatra_checkout_lock_audit_')) {
    throw new RuntimeException('Worker requires an isolated PostgreSQL audit database.');
}
Http::preventStrayRequests();
$provider = Mockery::mock(WarehouseDirectoryProvider::class);
$provider->shouldReceive('list')->once()->andReturn(array_map(fn ($code) => [
    'code' => $code, 'realname' => $code.' synced', 'codename' => $code, 'initial' => $code, 'area_group' => 'pulaujawa',
], ['W2', 'W1']));
$client = Mockery::mock(ExternalWarehouseDirectoryClient::class);
foreach (['W2' => '108.2', 'W1' => '107.6'] as $code => $longitude) {
    $client->shouldReceive('detail')->with($code)->once()->andReturn([
        'coordinates' => '-6.9, '.$longitude, 'fulladdr' => 'Jalan Uji', 'postal_code' => '40135',
    ]);
}
$app->instance(WarehouseDirectoryProvider::class, $provider);
$app->instance(ExternalWarehouseDirectoryClient::class, $client);
Warehouse::updated(function (Warehouse $warehouse) use ($directory): void {
    if ($warehouse->code !== 'W2') {
        return;
    }
    file_put_contents($directory.'/held-w2.json', json_encode(['transaction_depth' => DB::transactionLevel()]));
    $deadline = microtime(true) + 10;
    while (! is_file($directory.'/release-sync') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($directory.'/release-sync')) {
        throw new RuntimeException('Audit coordinator did not release sync.');
    }
});
file_put_contents($directory.'/ready.json', json_encode(['pid' => DB::selectOne('select pg_backend_pid() as pid')->pid]));
$started = hrtime(true);
$states = [];
$status = 200;
try {
    $app->make(WarehouseSyncService::class)->sync();
} catch (QueryException $exception) {
    $states[] = (string) $exception->getCode();
    $status = 500;
}
$result = ['status' => $status, 'sqlstates' => $states,
    'elapsed_ms' => round((hrtime(true) - $started) / 1e6, 2), 'transactions_after_request' => DB::transactionLevel()];
file_put_contents($directory.'/result.json', json_encode($result, JSON_PRETTY_PRINT));
echo json_encode($result).PHP_EOL;
