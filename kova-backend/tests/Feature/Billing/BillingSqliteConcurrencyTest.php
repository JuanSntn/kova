<?php

namespace Tests\Feature\Billing;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class BillingSqliteConcurrencyTest extends TestCase
{
    private string $database;

    private PDO $first;

    private PDO $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = tempnam(
            sys_get_temp_dir(),
            'kova-billing-',
        );
        $this->first = $this->connection();
        $this->second = $this->connection();

        $this->first->exec(
            'CREATE TABLE plan_subscriptions ('
            .'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            .'tenant_id INTEGER NOT NULL, '
            .'pending_tenant_id INTEGER NULL UNIQUE)'
        );
        $this->first->exec(
            'CREATE TABLE billing_outbox ('
            .'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            .'status TEXT NOT NULL, '
            .'locked_at TEXT NULL, '
            .'lock_token TEXT NULL)'
        );
    }

    protected function tearDown(): void
    {
        unset($this->first, $this->second);

        if (is_file($this->database)) {
            unlink($this->database);
        }

        parent::tearDown();
    }

    public function test_unique_pending_slot_is_enforced_across_connections(): void
    {
        $this->first->exec(
            'INSERT INTO plan_subscriptions '
            .'(tenant_id, pending_tenant_id) VALUES (1, 1)'
        );

        try {
            $this->second->exec(
                'INSERT INTO plan_subscriptions '
                .'(tenant_id, pending_tenant_id) VALUES (1, 1)'
            );
            $this->fail('SQLite permitió dos contratos pendientes.');
        } catch (PDOException $exception) {
            $this->assertSame('23000', $exception->getCode());
        }

        $this->assertSame(
            1,
            (int) $this->first
                ->query(
                    'SELECT COUNT(*) FROM plan_subscriptions '
                    .'WHERE pending_tenant_id = 1'
                )
                ->fetchColumn(),
        );
    }

    public function test_expired_worker_cannot_finalize_after_another_connection_takes_lease(): void
    {
        $this->first->exec(
            "INSERT INTO billing_outbox (status) VALUES ('pending')"
        );
        $operationId = (int) $this->first->lastInsertId();

        $claimed = $this->first->exec(
            "UPDATE billing_outbox SET status = 'processing', "
            ."locked_at = '2026-09-17 10:00:00', lock_token = 'token-a' "
            ."WHERE id = {$operationId} AND status = 'pending'"
        );
        $this->assertSame(1, $claimed);

        $reclaimed = $this->second->exec(
            "UPDATE billing_outbox SET locked_at = '2026-09-17 10:11:00', "
            ."lock_token = 'token-b' WHERE id = {$operationId} "
            ."AND status = 'processing' AND locked_at < '2026-09-17 10:01:00'"
        );
        $this->assertSame(1, $reclaimed);

        $oldWorker = $this->first->exec(
            "UPDATE billing_outbox SET status = 'completed', "
            ."locked_at = NULL, lock_token = NULL WHERE id = {$operationId} "
            ."AND status = 'processing' AND lock_token = 'token-a'"
        );
        $this->assertSame(0, $oldWorker);

        $currentWorker = $this->second->exec(
            "UPDATE billing_outbox SET status = 'completed', "
            ."locked_at = NULL, lock_token = NULL WHERE id = {$operationId} "
            ."AND status = 'processing' AND lock_token = 'token-b'"
        );
        $this->assertSame(1, $currentWorker);
    }

    private function connection(): PDO
    {
        $connection = new PDO('sqlite:'.$this->database);
        $connection->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION,
        );
        $connection->exec('PRAGMA busy_timeout = 2000');

        return $connection;
    }
}
