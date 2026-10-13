<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Todo importe monetario dentro de este archivo se expresa
    | en minor units.
    |
    | MXN:
    | $100.00     = 10_000
    | $1.12       = 112 (Stripe agrega IVA exclusivo y el total estimado es $1.30)
    | $25,000.00  = 2_500_000
    |
    */

    'currency' => 'mxn',

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Esta es la definición estructural de las capacidades de Kova.
    |
    | Los beneficios concretos de cada plan viven más abajo
    | dentro de plans.*.entitlements.
    |
    */

    'features' => [
        'max_users' => [
            'name' => 'max_users',
            'type' => 'integer',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => true,
        ],

        'max_products' => [
            'name' => 'max_products',
            'type' => 'integer',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => true,
        ],

        'max_categories' => [
            'name' => 'max_categories',
            'type' => 'integer',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => true,
        ],

        'monthly_sales_limit' => [
            'name' => 'monthly_sales_limit',
            'type' => 'integer',
            'unit' => 'mxn_minor',
            'reset_policy' => 'month',
            'additive' => true,
        ],

        'included_branches' => [
            'name' => 'included_branches',
            'type' => 'integer',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => true,
        ],

        'included_stamps' => [
            'name' => 'included_stamps',
            'type' => 'integer',
            'unit' => 'count',
            'reset_policy' => 'month',
            'additive' => true,
        ],

        'allows_counter_orders' => [
            'name' => 'allows_counter_orders',
            'type' => 'boolean',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => false,
        ],

        'allows_table_orders' => [
            'name' => 'allows_table_orders',
            'type' => 'boolean',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => false,
        ],

        'allows_cash_shifts' => [
            'name' => 'allows_cash_shifts',
            'type' => 'boolean',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => false,
        ],

        'allows_preparation' => [
            'name' => 'allows_preparation',
            'type' => 'boolean',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => false,
        ],

        'max_preparation_stations' => [
            'name' => 'max_preparation_stations',
            'type' => 'integer',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => false,
        ],

        'allows_inventory' => [
            'name' => 'allows_inventory',
            'type' => 'boolean',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => false,
        ],

        'allows_invoicing' => [
            'name' => 'allows_invoicing',
            'type' => 'boolean',
            'unit' => 'count',
            'reset_policy' => 'none',
            'additive' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Addons
    |--------------------------------------------------------------------------
    */

    'addons' => [
        'extra_branch' => [
            'name' => 'Sucursal adicional',

            'is_active' => true,

            'mode' => 'recurring',

            'prices' => [
                'month' => 35_000,
            ],

            'entitlements' => [
                'included_branches' => 1,
            ],
        ],

        'extra_stamp' => [
            'name' => 'Timbre excedente',

            'is_active' => true,

            'mode' => 'metered',

            'prices' => [
                'month' => 112,
            ],

            'entitlements' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    */

    'plans' => [
        /*
        |--------------------------------------------------------------------------
        | Free
        |--------------------------------------------------------------------------
        */

        'free' => [
            'name' => 'Free',

            'tier' => 0,

            'is_active' => true,

            /*
             * Free no tiene Stripe.
             */
            'prices' => [
                'month' => 0,
            ],

            'entitlements' => [
                'max_users' => 1,

                'max_products' => 20,

                'max_categories' => 5,

                /*
                 * $25,000 MXN
                 */
                'monthly_sales_limit' =>
                2_500_000,

                'included_branches' => 1,

                'included_stamps' => 0,

                'allows_counter_orders' => true,

                'allows_table_orders' => false,

                'allows_cash_shifts' => false,

                'allows_preparation' => false,

                'max_preparation_stations' => 0,

                'allows_inventory' => false,

                'allows_invoicing' => false,
            ],

            'addons' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Business
        |--------------------------------------------------------------------------
        */

        'business' => [
            'name' => 'Negocio',

            'tier' => 100,

            'is_active' => true,

            'prices' => [
                /*
                 * $129 MXN / month
                 */
                'month' => 12_900,

                /*
                 * $1,290 MXN / year
                 *
                 * Se crea en catálogo y Stripe,
                 * pero sigue bloqueado mientras:
                 *
                 * BILLING_ANNUAL_ENABLED=false
                 */
                'year' => 129_000,
            ],

            'entitlements' => [
                'max_users' => 5,

                'max_products' => 150,

                'max_categories' => 20,

                /*
                 * $150,000 MXN
                 */
                'monthly_sales_limit' =>
                15_000_000,

                'included_branches' => 1,

                'included_stamps' => 50,

                'allows_counter_orders' => true,

                'allows_table_orders' => false,

                'allows_cash_shifts' => true,

                'allows_preparation' => true,

                'max_preparation_stations' => 1,

                'allows_inventory' => true,

                'allows_invoicing' => true,
            ],

            'addons' => [
                /*
                 * Se provisiona automáticamente porque
                 * el excedente de timbres es medido.
                 */
                'extra_stamp' => [
                    'automatic' => true,
                ],

                /*
                 * Negocio también puede crecer a más
                 * sucursales. Cada una añade una unidad
                 * de capacidad al contrato.
                 */
                'extra_branch' => [
                    'automatic' => false,
                ],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Restaurant
        |--------------------------------------------------------------------------
        */

        'restaurant' => [
            'name' => 'Restaurante',

            'tier' => 200,

            'is_active' => true,

            'prices' => [
                /*
                 * $599 MXN / month
                 */
                'month' => 59_900,

                /*
                 * $5,990 MXN / year
                 */
                'year' => 599_000,
            ],

            'entitlements' => [
                /*
                 * null = unlimited
                 */
                'max_users' => null,

                'max_products' => null,

                'max_categories' => null,

                'monthly_sales_limit' => null,

                'included_branches' => 1,

                'included_stamps' => 150,

                'allows_counter_orders' => true,

                'allows_table_orders' => true,

                'allows_cash_shifts' => true,

                'allows_preparation' => true,

                'max_preparation_stations' => null,

                'allows_inventory' => true,

                'allows_invoicing' => true,
            ],

            'addons' => [
                'extra_stamp' => [
                    'automatic' => true,
                ],

                'extra_branch' => [
                    'automatic' => false,
                ],
            ],
        ],
    ],
];
