<?php

namespace Tests\Feature\Fiscal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicInvoiceRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_public_invoice_read_limit_is_isolated_by_operation_and_hashed_token(): void
    {
        $token = str_repeat('a', 64);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->getJson('/api/public/factura/'.$token)->assertNotFound();
        }

        $this->getJson('/api/public/factura/'.$token.'/status')->assertNotFound();
        $this->getJson('/api/public/factura/'.str_repeat('b', 64))->assertNotFound();

        $response = $this->getJson('/api/public/factura/'.$token)
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'rate_limit_exceeded')
            ->assertJsonStructure(['message', 'code', 'retry_after', 'request_id'])
            ->assertHeader('Retry-After');

        $response->assertJsonMissing(['exception', 'file', 'trace']);
    }

    public function test_public_invoice_post_is_counted_once_by_its_write_limiter(): void
    {
        $token = str_repeat('c', 64);
        $payload = [
            'rfc' => 'EKU9003173C9',
            'legal_name' => 'ESCUELA KEMPER URGATE',
            'tax_regime_code' => '626',
            'postal_code' => '42501',
            'email' => 'fiscal@example.test',
            'cfdi_use_code' => 'G03',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/public/factura/'.$token, $payload)
                ->assertNotFound();
        }

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/public/factura/'.$token, $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'rate_limit_exceeded');
    }
}
