<?php

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Exceptions\DomainConflictException;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Cash\CashService;
use App\Services\Pos\CheckoutQuoteService;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Only this disposable fixture is allowed. Never bootstrap a development DB.
$path = $argv[1] ?? '';
if (realpath(dirname($path)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($path), 'kova-inventory-concurrency-') || ! is_file($path)) {
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
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $path,
    'database.connections.sqlite.url' => null, 'cache.default' => 'array',
    'queue.default' => 'sync', 'session.driver' => 'array', 'fiscal.enabled' => false,
    'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
]);
DB::purge('sqlite');
if (DB::connection()->getDatabaseName() !== $path) {
    throw new RuntimeException('Unsafe connection.');
}
DB::statement('PRAGMA busy_timeout = 50');

if (($argv[2] ?? '') === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('db:seed', ['--class' => PlanSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $tenant = app(CreateTenant::class)->handle($user, Plan::where('code', 'free')->sole(), 'Concurrente', 'concurrente');
    $result = app(CurrentTenant::class)->run($tenant, function () use ($tenant, $user) {
        $branch = $tenant->branches()->sole();
        DB::table('plan_entitlements')->where('plan_version_id', DB::table('plan_subscriptions')->where('current_tenant_id', $tenant->id)->value('plan_version_id'))
            ->where('feature_id', Feature::where('code', 'allows_inventory')->value('id'))->update(['value' => 1]);
        setPermissionsTeamId($tenant->id);
        $supply = app(\App\Services\Inventory\SupplyService::class)->save($tenant, ['id' => (string) Str::uuid(), 'name' => 'Bebida', 'category' => 'Bebidas', 'unit' => 'pz', 'produced' => false, 'presentations' => []], null);
        $category = $tenant->categories()->create(['name' => 'Bebidas', 'is_active' => true]);
        $product = $tenant->products()->create(['name' => 'Bebida', 'price' => '10.00', 'category_id' => $category->id, 'is_active' => true]);
        DB::table('inventory_product_links')->insert(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'supply_id' => DB::table('inventory_supplies')->where('public_id', $supply['id'])->value('id'), 'quantity' => '1']);
        $items = [['product_id' => $product->public_id, 'quantity' => 1]];
        $quote = app(CheckoutQuoteService::class)->prepare($tenant, $branch, $user, $items);
        return ['tenant' => $tenant->id, 'user' => $user->id, 'branch' => $branch->public_id, 'supply' => $supply['id'],
            'payload' => ['order_type' => 'counter', 'payment_method' => 'cash', 'amount_received' => '10.00', 'items' => $items, 'quote_token' => $quote['quote_token']]];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 10;
while (! file_exists($path.'.go') && microtime(true) < $deadline) {
    usleep(10000);
}
$tenant = Tenant::findOrFail($input['tenant']);
$user = User::findOrFail($input['user']);
setPermissionsTeamId($tenant->id);
try {
    Auth::guard('web')->setUser($user);
    $request = Request::create($input['endpoint'], 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TENANT_ID' => $tenant->public_id, 'HTTP_X_BRANCH_ID' => $input['branch'],
        'HTTP_IDEMPOTENCY_KEY' => $input['key'],
    ], json_encode($input['payload']));
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => $body]);
} catch (DomainConflictException $e) {
    echo json_encode(['code' => $e->domainCode]);
} catch (HttpResponseException $e) {
    echo $e->getResponse()->getContent();
}
