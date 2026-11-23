<?php

use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();
$pdo = new PDO('pgsql:host='.$_ENV['DB_HOST'].';port='.$_ENV['DB_PORT'].';dbname=postgres;connect_timeout=5',
    $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'walatra_checkout_lock_audit_'.bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE "'.$database.'"');
$result = ['database' => $database, 'dropped' => false];
try {
    $connection = new PDO('pgsql:host='.$_ENV['DB_HOST'].';port='.$_ENV['DB_PORT'].';dbname='.$database,
        $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $result['settings'] = $connection->query("select current_setting('server_version') as version, current_setting('lock_timeout') as lock_timeout, current_setting('statement_timeout') as statement_timeout, current_setting('transaction_isolation') as transaction_isolation")->fetch(PDO::FETCH_ASSOC);
    $connection = null;
    $environment = [
        'DB_HOST' => $_ENV['DB_HOST'], 'DB_PORT' => $_ENV['DB_PORT'],
        'DB_USERNAME' => $_ENV['DB_USERNAME'], 'DB_PASSWORD' => $_ENV['DB_PASSWORD'],
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'MAXCHAT_ENABLED' => 'false', 'MAXCHAT_API_TOKEN' => '', 'MIDTRANS_SERVER_KEY' => '',
        'ORDER_SIMULATION_ENABLED' => 'false',
    ];
    $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', __DIR__.'/CheckoutLockAuditTest.php',
        '--log-junit', __DIR__.'/evidence/tests.xml'], $root, $environment, timeout: 150);
    $process->run();
    file_put_contents(__DIR__.'/evidence/tests.txt', $process->getOutput().$process->getErrorOutput());
    $result['exit_code'] = $process->getExitCode();
} finally {
    $pdo->exec('DROP DATABASE "'.$database.'" WITH (FORCE)');
    $result['dropped'] = true;
    file_put_contents(__DIR__.'/evidence/run.json', json_encode($result, JSON_PRETTY_PRINT).PHP_EOL);
}
echo json_encode($result).PHP_EOL;
exit($result['exit_code']);
