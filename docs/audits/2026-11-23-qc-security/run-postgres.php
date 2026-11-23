<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 3);
Dotenv\Dotenv::createImmutable($root)->safeLoad();
$pdo = new PDO(
    'pgsql:host='.$_ENV['DB_HOST'].';port='.$_ENV['DB_PORT'].';dbname=postgres;connect_timeout=5',
    $_ENV['DB_USERNAME'],
    $_ENV['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$suffix = 'qc_20261123_'.bin2hex(random_bytes(4));
$groups = [
    'cart' => [__DIR__.'/DiagnosedCartConcurrencyTest.php', __DIR__.'/WarehouseLockProbeTest.php'],
    'payment' => ['tests/Integration/CorePaymentConcurrencyTest.php'],
];
$results = [];
foreach ($groups as $group => $files) {
    $database = 'walatra_'.$group.'_concurrency_test_'.$suffix;
    $pdo->exec('CREATE DATABASE "'.$database.'"');
    try {
        $environment = [
            'DB_HOST' => $_ENV['DB_HOST'], 'DB_PORT' => $_ENV['DB_PORT'],
            'DB_USERNAME' => $_ENV['DB_USERNAME'], 'DB_PASSWORD' => $_ENV['DB_PASSWORD'],
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MAXCHAT_ENABLED' => 'false', 'MAXCHAT_API_TOKEN' => '', 'MIDTRANS_SERVER_KEY' => '',
            'CHECKOUT_WAREHOUSE_SELECTION' => 'legacy', 'ORDER_SIMULATION_ENABLED' => 'false',
        ];
        $process = new Process([
            PHP_BINARY, 'vendor/bin/phpunit', ...$files,
            '--log-junit', __DIR__.'/evidence/postgres-'.$group.'.xml',
        ], $root, $environment, timeout: 120);
        $process->run();
        file_put_contents(__DIR__.'/evidence/postgres-'.$group.'.txt', $process->getOutput().$process->getErrorOutput());
        $results[$group] = ['database' => $database, 'exit_code' => $process->getExitCode()];
        echo json_encode([$group => $results[$group]]).PHP_EOL;
    } finally {
        $pdo->exec('DROP DATABASE "'.$database.'" WITH (FORCE)');
    }
}
file_put_contents(__DIR__.'/evidence/postgres-run.json', json_encode($results, JSON_PRETTY_PRINT).PHP_EOL);
exit(max(array_column($results, 'exit_code')));
