<?php

use App\Enums\PermissionName;

return [
    [
        'key' => 'main',
        'label' => null,
        'order' => 10,

        'items' => [
            [
                'key' => 'dashboard',
                'label' => 'Inicio',
                'path' => '/dashboard',
                'icon' => 'layout-dashboard',
                'permission' => null,
                'order' => 10,
            ],
        ],
    ],

    [
        'key' => 'sales',
        'label' => 'Punto de venta',
        'order' => 20,

        'items' => [
            [
                'key' => 'pos',
                'label' => 'POS',
                'path' => '/pos',
                'icon' => 'shopping-cart',
                'permission' =>
                PermissionName::OrdersCreate->value,
                'order' => 10,
            ],

            [
                'key' => 'sales-history',
                'label' => 'Ventas',
                'path' => '/sales',
                'icon' => 'chart-no-axes-combined',
                'permission' =>
                PermissionName::OrdersView->value,
                'order' => 20,
            ],
        ],
    ],

    [
        'key' => 'catalog',
        'label' => 'Catálogo',
        'order' => 30,

        'items' => [
            [
                'key' => 'menu',
                'label' => 'Menú',
                'path' => '/menu',
                'icon' => 'book-open',
                'order' => 10,

                'children' => [
                    [
                        'key' => 'products',
                        'label' => 'Productos',
                        'permission' =>
                        PermissionName::ProductsView->value,
                        'order' => 10,
                    ],

                    [
                        'key' => 'categories',
                        'label' => 'Categorías',
                        'permission' =>
                        PermissionName::CategoriesView->value,
                        'order' => 20,
                    ],
                ],
            ],
        ],
    ],

    [
        'key' => 'settings',
        'label' => 'Configuración',
        'order' => 100,

        'items' => [
            [
                'key' => 'business-settings',
                'label' => 'Negocio',
                'path' => '/settings/business',
                'icon' => 'building-2',
                'permission' =>
                PermissionName::BusinessSettingsView->value,
                'order' => 1,
            ],

            [
                'key' => 'ticket-settings',
                'label' => 'Ticket',
                'path' => '/settings/ticket',
                'icon' => 'receipt-text',
                'permission' =>
                PermissionName::TicketSettingsView->value,
                'order' => 3,
            ],

            [
                'key' => 'taxes',
                'label' => 'Impuestos',
                'path' => '/settings/taxes',
                'icon' => 'percent',
                'permission' =>
                PermissionName::TaxesView->value,
                'order' => 5,
            ],

            [
                'key' => 'billing',
                'label' => 'Suscripción',
                'path' => '/billing',
                'icon' => 'credit-card',
                'permission' =>
                PermissionName::BillingView->value,
                'order' => 10,
            ],
        ],
    ],
];
