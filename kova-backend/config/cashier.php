<?php

use Laravel\Cashier\Invoices\DompdfInvoiceRenderer;

return [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET_KEY'),
    'path' => env('CASHIER_PATH', 'stripe'),
    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        'events' => [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',

            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',

            'customer.subscription.pending_update_applied',
            'customer.subscription.pending_update_expired',

            'customer.updated',
            'customer.deleted',

            'payment_method.automatically_updated',

            'invoice.payment_action_required',
            'invoice.payment_succeeded',
            'invoice.payment_failed',
            'invoice.paid',
            'invoice.voided',
        ],
    ],
    'currency' => env('CASHIER_CURRENCY', 'mxn'),
    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'es_MX'),
    'payment_notification' => env('CASHIER_PAYMENT_NOTIFICATION'),
    'invoices' => [
        'renderer' => env('CASHIER_INVOICE_RENDERER', DompdfInvoiceRenderer::class),
        'options' => [
            'paper' => env('CASHIER_PAPER', 'letter'),
            'remote_enabled' => env('CASHIER_REMOTE_ENABLED', false),
        ],
    ],
    'logger' => env('CASHIER_LOGGER'),
];
