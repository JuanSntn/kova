<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

// CLI-only helper: always uses a disposable test database, never the development database.
require __DIR__.'/../../vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$database = $input['database'];
if (! str_starts_with(basename($database), 'kova-reset-test-') || realpath(dirname($database)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Disposable test database required.');
}
putenv('APP_ENV=testing');
putenv('APP_CONFIG_CACHE='.__DIR__.'/missing-config.php');
putenv('APP_ROUTES_CACHE='.__DIR__.'/missing-routes.php');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app['config']->set([
    'app.debug' => false,
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $database,
    'database.connections.sqlite.url' => null,
    'cache.default' => 'array',
    'session.driver' => 'array',
    'session.connection' => 'sqlite',
    'mail.default' => 'array',
    'hashing.bcrypt.rounds' => 4,
]);
$app['db']->purge();
$app['db']->connection()->getPdo()->exec('PRAGMA busy_timeout = 5000');
while (microtime(true) < $input['start']) {
    usleep(1000);
}
$request = Request::create('/api/auth/reset-password', 'POST', $input['payload'], server: ['HTTP_ACCEPT' => 'application/json']);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo $response->getStatusCode();
