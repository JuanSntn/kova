<?php

return [
    'livemode' =>
    env(
        'BILLING_LIVEMODE',
        false
    ),

    'annual_enabled' =>
    env(
        'BILLING_ANNUAL_ENABLED',
        false
    ),

    'portal_configuration' =>
    env(
        'STRIPE_PORTAL_CONFIGURATION'
    ),

    'currency' => 'mxn',

    'tax_rate' =>
    env(
        'STRIPE_TAX_RATE_MX_IVA'
    ),

    'plans' => [
        'business' => [
            'price' =>
            env(
                'STRIPE_PRICE_BUSINESS'
            ),
        ],

        'restaurant' => [
            'price' =>
            env(
                'STRIPE_PRICE_RESTAURANT'
            ),
        ],
    ],

    'extras' => [
        'branch_price' =>
        env(
            'STRIPE_PRICE_EXTRA_BRANCH'
        ),

        'stamp_price' =>
        env(
            'STRIPE_PRICE_EXTRA_STAMP'
        ),

        'stamp_meter' =>
        env(
            'STRIPE_METER_EXTRA_STAMPS'
        ),

        'stamp_meter_event' =>
        env(
            'STRIPE_METER_EVENT_EXTRA_STAMPS',
            'kova_extra_stamps'
        ),
    ],
];
