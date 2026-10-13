<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class InventoryConcurrencyTest extends TestCase
{
    private string $path;
    private array $fixture;
    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kova-inventory-concurrency-');
        $p = new Process([PHP_BINARY, __DIR__.'/../../Support/inventory-process.php', $this->path, 'setup']);
        $p->mustRun();
        $this->fixture = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    protected function tearDown(): void
    {
        foreach (glob($this->path.'*') ?: [] as $f) unlink($f);
        parent::tearDown();
    }
    private function worker(string $path, array $data, string $key): Process
    {
        $p = new Process([PHP_BINARY, __DIR__.'/../../Support/inventory-process.php', $this->path, 'run']);
        $p->setInput(json_encode($this->fixture + ['endpoint' => $path, 'key' => $key] + ['unused' => null]));
        $input = $this->fixture; $input['endpoint'] = $path; $input['key'] = $key; $input['payload'] = $data;
        $p->setInput(json_encode($input)); $p->start(); return $p;
    }
    private function results(Process ...$workers): array
    {
        touch($this->path.'.go');
        return array_map(function ($p) { $p->wait(); $this->assertTrue($p->isSuccessful(), $p->getErrorOutput()); return json_decode($p->getOutput(), true); }, $workers);
    }
    private function scalar(string $query): mixed { return (new \PDO('sqlite:'.$this->path))->query($query)->fetchColumn(); }
    public function test_same_key_commits_one_movement_and_different_keys_accumulate(): void
    {
        $data = ['type' => 'IN','quantity' => '5','supply_id' => $this->fixture['supply'],'reason' => 'Recepción'];
        $key = (string)Str::uuid();
        [$a,$b] = $this->results($this->worker('/api/inventory/operations', $data, $key), $this->worker('/api/inventory/operations', $data, $key));
        $this->assertSame(200, $a['status']); $this->assertSame($a['body'], $b['body']);
        $this->assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM inventory_movements'));
        $this->results($this->worker('/api/inventory/operations', $data, (string)Str::uuid()), $this->worker('/api/inventory/operations', $data, (string)Str::uuid()));
        $this->assertSame('15.000000', $this->scalar('SELECT quantity FROM inventory_balances'));
    }
    public function test_sale_and_movement_preserve_balance_and_sale_retry(): void
    {
        $data = ['type' => 'IN','quantity' => '5','supply_id' => $this->fixture['supply'],'reason' => 'Recepción'];
        $key = (string)Str::uuid();
        [$sale,$move] = $this->results($this->worker('/api/pos/orders', $this->fixture['payload'], $key), $this->worker('/api/inventory/operations', $data, (string)Str::uuid()));
        $this->assertSame(201, $sale['status']); $this->assertSame(200, $move['status']);
        $this->assertSame('4.000000', $this->scalar('SELECT quantity FROM inventory_balances'));
        [$retry] = $this->results($this->worker('/api/pos/orders', $this->fixture['payload'], $key));
        $this->assertSame(200, $retry['status']);
        $this->assertSame($sale['body']['data']['id'], $retry['body']['data']['id']);
        $this->assertSame(2, (int)$this->scalar('SELECT COUNT(*) FROM inventory_movements'));
    }
}
