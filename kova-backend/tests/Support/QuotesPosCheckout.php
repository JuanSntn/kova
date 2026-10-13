<?php

namespace Tests\Support;

use Illuminate\Support\Str;

trait QuotesPosCheckout
{
    protected function postCheckout(array $payload, array $headers = [])
    {
        $quote = $this->postJson('/api/pos/checkout/quote', ['items' => $payload['items'] ?? []], $headers);
        return $this->postJson('/api/pos/orders', [
            ...$payload,
            'quote_token' => $quote->json('data.quote_token') ?? 'invalid-test-quote',
        ], ['Idempotency-Key' => (string) Str::uuid(), ...$headers]);
    }
}
