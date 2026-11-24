<?php

$projectRoot = dirname(__DIR__);
require $projectRoot.'/vendor/autoload.php';
$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($app->configurationIsCached()) {
    fwrite(STDERR, 'Clear the local configuration cache before running isolated tests.'.PHP_EOL);
    exit(1);
}
if (! in_array(config('app.env'), ['local', 'testing'], true)) {
    fwrite(STDERR, 'Run this helper only in a local or testing environment.'.PHP_EOL);
    exit(1);
}
$engine = config('database.default');
echo 'Configured database engine: '.$engine.PHP_EOL;
if (! in_array($engine, ['pgsql', 'mysql'], true)) {
    exit(2);
}
try {
    $pdo = Illuminate\Support\Facades\DB::connection($engine)->getPdo();
} catch (Throwable $e) {
    echo 'Configured database connection unavailable ('.get_class($e).').'.PHP_EOL;
    exit(3);
}
echo 'Connected server version: '.$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
$testDatabase = 'rushhour_phase01_test_'.bin2hex(random_bytes(6));
if (! preg_match('/^rushhour_phase01_test_[a-f0-9]{12}$/D', $testDatabase)) {
    exit(4);
}
$quote = $engine === 'pgsql' ? '"' : '`';
try {
    $pdo->exec('CREATE DATABASE '.$quote.$testDatabase.$quote);
} catch (Throwable $e) {
    echo 'Cannot create isolated database ('.get_class($e).'). No existing database was changed.'.PHP_EOL;
    exit(5);
}
echo 'Created isolated test database: '.$testDatabase.PHP_EOL;
try {
    $connection = config('database.connections.'.$engine);
    $testEnvironment = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => $engine, 'DB_DATABASE' => $testDatabase,
        'DB_URL' => '', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
        'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    ]);
    $process = proc_open([PHP_BINARY, $projectRoot.'/vendor/pestphp/pest/bin/pest'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $projectRoot, $testEnvironment);
    $status = is_resource($process) ? proc_close($process) : 6;
} finally {
    // This identifier was generated and created above, never read from application configuration.
    $pdo->exec('DROP DATABASE '.$quote.$testDatabase.$quote);
    echo 'Removed the isolated test database.'.PHP_EOL;
}
exit($status);
