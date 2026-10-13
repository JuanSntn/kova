<?php

namespace App\Services\Tickets;

use App\Models\Tenant;
use App\Services\Subscriptions\EntitlementService;

final class TicketCapabilityService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @return array<string, bool>
     */
    public function for(
        Tenant $tenant,
    ): array {
        $canInvoice =
            $this->entitlements->allows(
                $tenant,
                'allows_invoicing',
            );

        $allowsTableOrders =
            $this->entitlements->allows(
                $tenant,
                'allows_table_orders',
            );

        return [
            'logo' => true,
            'business_name' => true,
            'phone' => true,
            'email' => true,

            'branch_name' =>
            $canInvoice,

            'address' =>
            $canInvoice,

            'cashier' => true,
            'folio' => true,
            'datetime' => true,

            'order_type' =>
            $allowsTableOrders,

            'unit_price' => true,
            'tax_breakdown' => true,
            'payment_method' => true,
            'cash_received' => true,
            'change' => true,

            'rfc' =>
            $canInvoice,

            'invoice_qr' =>
            $canInvoice,

            'custom_texts' => true,
            'printing' => true,
        ];
    }
}
