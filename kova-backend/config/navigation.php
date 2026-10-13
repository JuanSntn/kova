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
                'feature' => 'allows_counter_orders',
                'label' => 'POS',
                'path' => '/pos',
                'icon' => 'shopping-cart',
                'permission' => PermissionName::OrdersCreate->value,
                'order' => 10,
            ],

            [
                'key' => 'cash', 'label' => 'Caja y turnos', 'path' => '/cash', 'icon' => 'wallet',
                'permission' => PermissionName::CashView->value, 'order' => 15,
            ],
            [
                'key' => 'preparation',
                'feature' => 'allows_preparation',
                'label' => 'Comandas',
                'path' => '/preparation',
                'icon' => 'chef-hat',
                'permission' => PermissionName::PreparationView->value,
                'order' => 17,
            ],
            [
                'key' => 'sales-history',
                'label' => 'Ventas',
                'path' => '/sales',
                'icon' => 'chart-no-axes-combined',
                'permission' => PermissionName::OrdersView->value,
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
                'key' => 'inventory', 'label' => 'Inventario', 'path' => '/inventory',
                'icon' => 'package', 'permission' => PermissionName::InventoryView->value,
                'feature' => 'allows_inventory', 'order' => 20,
            ],
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
                        'permission' => PermissionName::ProductsView->value,
                        'order' => 10,
                    ],

                    [
                        'key' => 'categories',
                        'label' => 'Categorías',
                        'permission' => PermissionName::CategoriesView->value,
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
                'permission' => PermissionName::BusinessSettingsView->value,
                'order' => 1,
            ],

            [
                'key' => 'ticket-settings',
                'label' => 'Ticket',
                'path' => '/settings/ticket',
                'icon' => 'receipt-text',
                'permission' => PermissionName::TicketSettingsView->value,
                'order' => 3,
            ],

            [
                'key' => 'branches-settings',
                'label' => 'Sucursales',
                'path' => '/settings/branches',
                'icon' => 'store',
                'permission' => PermissionName::BusinessSettingsView->value,
                'order' => 2,
            ],

            [
                'key' => 'users-settings',
                'label' => 'Equipo',
                'path' => '/settings/users',
                'icon' => 'users',
                'permission' => PermissionName::UsersView->value,
                'order' => 4,
            ],

            [
                'key' => 'taxes',
                'label' => 'Impuestos',
                'path' => '/settings/taxes',
                'icon' => 'percent',
                'permission' => PermissionName::TaxesView->value,
                'order' => 5,
            ],

            [
                'key' => 'fiscal-settings',
                'label' => 'Facturación fiscal',
                'path' => '/settings/fiscal',
                'icon' => 'file-badge',
                'permission' => PermissionName::FiscalView->value,
                'feature' => 'allows_invoicing',
                'order' => 7,
            ],

            [
                'key' => 'billing',
                'label' => 'Suscripción',
                'path' => '/billing',
                'icon' => 'credit-card',
                'permission' => PermissionName::BillingView->value,
                'order' => 10,
            ],
        ],
    ],
];
