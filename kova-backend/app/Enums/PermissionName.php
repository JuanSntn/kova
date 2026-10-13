<?php

namespace App\Enums;

enum PermissionName: string
{
    case InventoryView = 'inventory.view';
    case InventoryManage = 'inventory.manage';

    case CashView = 'cash.view';
    case CashOperate = 'cash.operate';
    case CashManage = 'cash.manage';

    case BillingView = 'billing.view';
    case BillingManage = 'billing.manage';
    case FiscalView = 'fiscal.view';
    case FiscalManage = 'fiscal.manage';

    case BusinessSettingsView =
    'business_settings.view';

    case BusinessSettingsManage =
    'business_settings.manage';

    case TicketSettingsView =
    'ticket_settings.view';

    case TicketSettingsManage =
    'ticket_settings.manage';

    case TaxesView = 'taxes.view';
    case TaxesManage = 'taxes.manage';

    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';

    case PreparationView = 'preparation.view';
    case PreparationManage = 'preparation.manage';

    case ProductsView = 'products.view';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDelete = 'products.delete';

    case CategoriesView = 'categories.view';
    case CategoriesCreate = 'categories.create';
    case CategoriesUpdate = 'categories.update';
    case CategoriesDelete = 'categories.delete';

    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
}
