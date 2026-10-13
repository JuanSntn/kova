<?php

// Only this disposable fixture is allowed. Never bootstrap a development DB.
$path = $argv[1] ?? '';
if (realpath(dirname($path)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($path), 'kova-pos-concurrency-') || ! is_file($path)) {
    throw new RuntimeException('A disposable POS database is required.');
}
putenv('APP_ENV=testing');
putenv('APP_CONFIG_CACHE='.$path.'.no-config');
putenv('APP_ROUTES_CACHE='.$path.'.no-routes');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$path);
putenv('DB_URL');
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $path,
    'database.connections.sqlite.url' => null, 'cache.default' => 'array',
    'queue.default' => 'sync', 'session.driver' => 'array', 'fiscal.enabled' => false,
    'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
]);
Illuminate\Support\Facades\DB::purge('sqlite');
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== $path) {
    throw new RuntimeException('Unsafe connection.');
}
Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 50');

if (($argv[2] ?? '') === 'setup') {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Database\Seeders\PlanSeeder::class, '--force' => true]);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Database\Seeders\RoleSeeder::class, '--force' => true]);
    $user = App\Models\User::factory()->create(['email_verified_at' => now()]);
    $tenant = app(App\Domain\Tenancy\Actions\CreateTenant::class)->handle($user, App\Models\Plan::where('code', 'free')->sole(), 'Concurrente', 'concurrente');
    $result = app(App\Tenancy\CurrentTenant::class)->run($tenant, function () use ($tenant, $user, $argv) {
        $category = $tenant->categories()->create(['name' => 'Productos', 'is_active' => true]);
        $product = $tenant->products()->create(['name' => 'Producto', 'price' => $argv[3] ?? '1.00', 'category_id' => $category->id, 'is_active' => true]);
        $branch = $tenant->branches()->sole();
        $items = [['product_id' => $product->public_id, 'quantity' => 1]];
        if (($argv[4] ?? '') === 'modifiers') {
            $option = (string) Illuminate\Support\Str::uuid();
            $group = Illuminate\Support\Facades\DB::table('menu_modifier_groups')->insertGetId([
                'public_id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Extras', 'kind' => 'extras',
                'options' => json_encode([['id' => $option, 'name' => 'Queso']]), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Illuminate\Support\Facades\DB::table('menu_product_modifiers')->insert([
                'tenant_id' => $tenant->id, 'product_id' => $product->id, 'group_id' => $group, 'min_selections' => 0, 'max_selections' => 1,
                'options' => json_encode([['id' => $option, 'price' => '2.25', 'effect' => 'none']]), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $items[0]['modifiers'] = [$option];
        }
        $quote = app(App\Services\Pos\CheckoutQuoteService::class)->prepare($tenant, $branch, $user, $items);
        return ['tenant' => $tenant->id, 'user' => $user->id, 'branch' => $branch->public_id,
            'payload' => ['order_type' => 'counter', 'payment_method' => 'card', 'items' => $items, 'quote_token' => $quote['quote_token']]];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 10;
while (! file_exists($path.'.go') && microtime(true) < $deadline) {
    usleep(10000);
}
$tenant = App\Models\Tenant::findOrFail($input['tenant']);
$user = App\Models\User::findOrFail($input['user']);
setPermissionsTeamId($tenant->id);
try {
    Illuminate\Support\Facades\Auth::guard('web')->setUser($user);
    $request = Illuminate\Http\Request::create('/api/pos/orders', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TENANT_ID' => $tenant->public_id, 'HTTP_X_BRANCH_ID' => $input['branch'],
        'HTTP_IDEMPOTENCY_KEY' => $input['key'],
    ], json_encode($input['payload']));
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode(isset($body['data']['id'])
        ? ['id' => $body['data']['id'], 'created' => $response->getStatusCode() === 201]
        : ['code' => $body['code'] ?? $body['message'] ?? $response->getStatusCode()]);
} catch (App\Exceptions\DomainConflictException $e) {
    echo json_encode(['code' => $e->domainCode]);
} catch (Illuminate\Http\Exceptions\HttpResponseException $e) {
    echo $e->getResponse()->getContent();
}
