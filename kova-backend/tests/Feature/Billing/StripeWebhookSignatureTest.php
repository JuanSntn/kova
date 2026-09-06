<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        config(['cashier.webhook.secret' => 'whsec_test_only']);

        $this->withHeader('Stripe-Signature', 't=1,v1=invalid')
            ->postJson('/stripe/webhook', [
                'id' => 'evt_invalid',
                'type' => 'ping',
                'data' => ['object' => []],
            ])
            ->assertForbidden();
    }

    public function test_webhook_accepts_a_current_valid_signature_without_csrf(): void
    {
        $secret = 'whsec_test_only';
        $timestamp = now()->timestamp;
        $payload = json_encode([
            'id' => 'evt_valid',
            'type' => 'ping',
            'data' => ['object' => []],
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        config(['cashier.webhook.secret' => $secret]);

        $this->call(
            'POST',
            '/stripe/webhook',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $payload,
        )->assertOk();
    }
}
