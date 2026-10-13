<?php

namespace Tests\Feature\Pos;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CheckoutConcurrencyTest extends TestCase
{
    private string $path;
    private array $fixture;

    protected function tearDown(): void
    {
        foreach (glob($this->path.'*') ?: [] as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    private function setupDatabase(string $price = '1.00', string $configuration = ''): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kova-pos-concurrency-');
        $process = new Process([PHP_BINARY, __DIR__.'/../../Support/pos-checkout-process.php', $this->path, 'setup', $price, $configuration]);
        $process->mustRun();
        $this->fixture = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function worker(string $key): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../../Support/pos-checkout-process.php', $this->path, 'run']);
        $process->setInput(json_encode([...$this->fixture, 'key' => $key]));
        $process->start();
        return $process;
    }

    private function results(Process ...$workers): array
    {
        touch($this->path.'.go');
        return array_map(function (Process $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $workers);
    }

    private function countOrders(): int
    {
        return (int) (new \PDO('sqlite:'.$this->path))->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    }

    public function test_same_key_in_independent_processes_creates_one_order(): void
    {
        $this->setupDatabase();
        $key = (string) Str::uuid();
        [$a, $b] = $this->results($this->worker($key), $this->worker($key));
        $this->assertSame($a['id'], $b['id']);
        $this->assertNotSame($a['created'], $b['created']);
        $this->assertSame(1, $this->countOrders());
    }

    public function test_modified_sale_retries_in_independent_processes_keep_one_snapshot(): void
    {
        $this->setupDatabase('1.00', 'modifiers');
        $key = (string) Str::uuid();
        [$a, $b] = $this->results($this->worker($key), $this->worker($key));
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, $this->countOrders());
        $row = (new \PDO('sqlite:'.$this->path))->query('SELECT unit_price_snapshot, modifiers_snapshot FROM order_items')->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(3.25, $row['unit_price_snapshot']);
        $this->assertSame('Queso', json_decode($row['modifiers_snapshot'], true)[0]['option_name']);
    }

    public function test_distinct_sales_are_serialized_against_last_capacity(): void
    {
        $this->setupDatabase('15000.00');
        $results = $this->results($this->worker((string) Str::uuid()), $this->worker((string) Str::uuid()));
        $this->assertCount(1, array_filter($results, fn ($r) => isset($r['id'])));
        $this->assertSame(['MONTHLY_SALES_LIMIT_EXCEEDED'], array_values(array_column($results, 'code')));
        $this->assertSame(1, $this->countOrders());
    }

    public function test_distinct_sales_with_capacity_both_commit(): void
    {
        $this->setupDatabase();
        [$a, $b] = $this->results($this->worker((string) Str::uuid()), $this->worker((string) Str::uuid()));
        $this->assertNotSame($a['id'], $b['id']);
        $this->assertSame(2, $this->countOrders());
    }

    public function test_busy_database_returns_recoverable_error_and_same_key_can_retry(): void
    {
        $this->setupDatabase();
        $connection = new \PDO('sqlite:'.$this->path);
        $connection->exec('BEGIN IMMEDIATE');
        $key = (string) Str::uuid();
        [$result] = $this->results($this->worker($key));
        $connection->exec('ROLLBACK');
        $this->assertSame('POS_CHECKOUT_RETRY', $result['code']);
        $this->assertSame(0, $this->countOrders());
        [$retry] = $this->results($this->worker($key));
        $this->assertTrue($retry['created']);
        $this->assertSame(1, $this->countOrders());
    }
}
