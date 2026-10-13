<?php

namespace Tests\Feature\Cash;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CashConcurrencyTest extends TestCase
{
    private string $path;

    private array $fixture;

    protected function tearDown(): void
    {
        if (isset($this->path)) {
            foreach (glob($this->path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function setupDatabase(string $mode = 'open'): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kova-cash-concurrency-');
        $process = new Process([PHP_BINARY, __DIR__.'/../../Support/cash-process.php', $this->path, 'setup', $mode]);
        $process->mustRun();
        $this->fixture = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function worker(string $endpoint, array $payload, ?string $key = null): Process
    {
        $p = new Process([PHP_BINARY, __DIR__.'/../../Support/cash-process.php', $this->path, 'run']);
        $p->setInput(json_encode([...$this->fixture, 'endpoint' => $endpoint, 'payload' => $payload, 'key' => $key ?? (string) Str::uuid()]));
        $p->start();

        return $p;
    }

    private function results(Process ...$workers): array
    {
        touch($this->path.'.go');

        return array_map(function ($p) {
            $p->wait();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

            return json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $workers);
    }

    private function scalar(string $sql): mixed
    {
        return (new \PDO('sqlite:'.$this->path))->query($sql)->fetchColumn();
    }

    public function test_two_openings_have_one_winner_and_database_slots_are_unique(): void
    {
        $this->setupDatabase('unopened');
        $data = ['register_id' => $this->fixture['register'], 'opening_cash' => '0'];
        $results = $this->results($this->worker('/api/cash/shifts', $data), $this->worker('/api/cash/shifts', $data));
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM cash_shifts WHERE status='OPEN'"));
        $db = new \PDO('sqlite:'.$this->path);
        $row = $db->query('SELECT * FROM cash_shifts')->fetch(\PDO::FETCH_ASSOC);
        unset($row['id']);
        $row['public_id'] = (string) Str::uuid();
        $this->expectException(\PDOException::class);
        $db->prepare('INSERT INTO cash_shifts ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')')->execute(array_values($row));
    }

    public function test_duplicate_movement_and_duplicate_close_commit_once(): void
    {
        $this->setupDatabase();
        $shift = $this->fixture['shift'];
        $key = (string) Str::uuid();
        $data = ['type' => 'IN', 'amount' => '10.00', 'reason' => 'Cambio'];
        [$a,$b] = $this->results($this->worker("/api/cash/shifts/$shift/movements", $data, $key), $this->worker("/api/cash/shifts/$shift/movements", $data, $key));
        $this->assertSame($a['body']['data'], $b['body']['data']);
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM cash_movements'));
        [$count] = $this->results($this->worker("/api/cash/shifts/$shift/counts", ['counted_cash' => '1010']));
        $data = ['count_id' => $count['body']['data']['id']];
        $key = (string) Str::uuid();
        [$a,$b] = $this->results($this->worker("/api/cash/shifts/$shift/close", $data, $key), $this->worker("/api/cash/shifts/$shift/close", $data, $key));
        $this->assertSame($a['body']['data'], $b['body']['data']);
        $this->assertSame('CLOSED', $a['body']['data']['status']);
    }

    public function test_sale_versus_close_never_leaves_sale_outside_snapshot(): void
    {
        $this->setupDatabase();
        $shift = $this->fixture['shift'];
        [$sale,$close] = $this->results($this->worker('/api/pos/orders', $this->fixture['payload']), $this->worker("/api/cash/shifts/$shift/close", ['count_id' => $this->fixture['count']]));
        if ($sale['status'] === 201) {
            $this->assertSame('CASH_COUNT_STALE', $close['body']['code']);
            [$count] = $this->results($this->worker("/api/cash/shifts/$shift/counts", ['counted_cash' => '1180']));
            [$closed] = $this->results($this->worker("/api/cash/shifts/$shift/close", ['count_id' => $count['body']['data']['id']]));
            $this->assertSame('180.00', $closed['body']['data']['cash_sales_total']);
        } else {
            $this->assertSame(201, $close['status']);
            $this->assertSame(409, $sale['status']);
            $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM orders'));
        }
    }

    public function test_movement_versus_close_requires_fresh_count_or_rejects_movement(): void
    {
        $this->setupDatabase();
        $shift = $this->fixture['shift'];
        [$move,$close] = $this->results($this->worker("/api/cash/shifts/$shift/movements", ['type' => 'OUT', 'amount' => '10', 'reason' => 'Insumos']), $this->worker("/api/cash/shifts/$shift/close", ['count_id' => $this->fixture['count']]));
        if ($move['status'] === 201) {
            $this->assertSame('CASH_COUNT_STALE', $close['body']['code']);
        } else {
            $this->assertSame('CASH_SHIFT_CLOSED', $move['body']['code']);
            $this->assertSame(201, $close['status']);
        }
    }

    public function test_blocked_database_returns_retry_without_partial_writes(): void
    {
        $this->setupDatabase();
        $db = new \PDO('sqlite:'.$this->path);
        $db->exec('BEGIN IMMEDIATE');
        $shift = $this->fixture['shift'];
        $key = (string) Str::uuid();
        $data = ['type' => 'IN', 'amount' => '1', 'reason' => 'Prueba'];
        [$result] = $this->results($this->worker("/api/cash/shifts/$shift/movements", $data, $key));
        $db->exec('ROLLBACK');
        $this->assertSame('CASH_RETRY', $result['body']['code']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM cash_movements'));
        [$result] = $this->results($this->worker("/api/cash/shifts/$shift/movements",$data,$key));
        $this->assertSame(201,$result['status']);
    }
}
