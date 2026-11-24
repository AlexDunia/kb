<?php

// Creates a brand-new temporary SQLite database for HTTP integration only.
// Production locking is separately tested by test-isolated-database.php.
$root = dirname(__DIR__);
if (is_file($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Clear configuration cache before isolated HTTP testing.');
}
$database = rtrim(sys_get_temp_dir(), '/\\').'/rushhour-phase03-'.bin2hex(random_bytes(12)).'.sqlite';
$handle = fopen($database, 'x');
if (! $handle) {
    throw new RuntimeException('Cannot create isolated database file.');
}
fclose($handle);
$fixture = tempnam(sys_get_temp_dir(), 'rushhour-fixture-');
$log = tempnam(sys_get_temp_dir(), 'rushhour-http-');
$server = null;
$environment = array_merge(getenv(), [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'database', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'APP_URL' => 'http://127.0.0.1:8013', 'FRONTEND_URL' => 'http://localhost:5174', 'SESSION_DOMAIN' => '',
    'PHASE03_FIXTURE' => $fixture,
]);
$run = function (array $command, string $cwd) use ($environment) {
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd, $environment);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Integration subprocess failed.');
    }
};
try {
    $socket = @stream_socket_client('tcp://127.0.0.1:8013', $errno, $error, 1);
    if ($socket) {
        fclose($socket);
        throw new RuntimeException('Integration port 8013 is already in use.');
    }
    $run([PHP_BINARY, 'artisan', 'migrate', '--force'], $root);
    $seed = proc_open([PHP_BINARY, 'tests/Support/phase03-http-fixture.php'], [0 => STDIN, 1 => ['file', $fixture, 'w'], 2 => STDERR], $pipes, $root, $environment);
    if (! is_resource($seed) || proc_close($seed) !== 0) {
        fwrite(STDERR, file_get_contents($fixture));
        throw new RuntimeException('Isolated fixture creation failed.');
    }
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8013', 'tests/Support/phase03-http-router.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $environment);
    if (! is_resource($server)) {
        throw new RuntimeException('Cannot start isolated HTTP server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client('tcp://127.0.0.1:8013', $errno, $error, 0.1);
        if ($socket) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (! $ready) {
        throw new RuntimeException('Isolated HTTP server did not start.');
    }
    $run(['node', 'scripts/check-phase03-e2e.mjs'], 'C:/Users/HP/kaka');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ([$database, $fixture, $log] as $temporary) {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    echo 'Removed only generated integration files.'.PHP_EOL;
}
