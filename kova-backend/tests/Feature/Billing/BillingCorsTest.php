<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;

class BillingCorsTest extends TestCase
{
    public function test_checkout_preflight_allows_credentials_and_idempotency_from_frontend(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);
        $response = $this->options('/api/billing/checkout', [], [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,idempotency-key,x-tenant-id,x-branch-id,x-xsrf-token',
        ]);
        $response->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $allowed = array_map('trim', explode(',', strtolower($response->headers->get('Access-Control-Allow-Headers'))));
        foreach (['content-type', 'idempotency-key', 'x-tenant-id', 'x-branch-id', 'x-xsrf-token'] as $header) {
            $this->assertContains($header, $allowed);
        }
    }

    public function test_checkout_preflight_does_not_allow_an_untrusted_origin(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);
        $this->options('/api/billing/checkout', [], [
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'idempotency-key',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
