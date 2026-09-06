<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Contracts\BillingGateway;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingInvoiceController extends Controller
{
    public function index(
        Request $request,
        CurrentTenant $currentTenant,
        BillingGateway $billing,
    ): JsonResponse {
        /*
         * El middleware tenant ya estableció el tenant actual
         * y configuró el team correspondiente para Spatie.
         */
        abort_unless(
            $request->user()?->can(
                PermissionName::BillingView->value
            ),
            403
        );

        /*
         * limit:
         * Número de facturas que se devolverán.
         *
         * cursor:
         * ID de la última factura recibida en la página anterior.
         */
        $validated = $request->validate([
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:20',
            ],

            'cursor' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^in_[A-Za-z0-9]+$/',
            ],
        ]);

        $limit = (int) ($validated['limit'] ?? 5);

        $cursor = isset($validated['cursor'])
            ? trim((string) $validated['cursor'])
            : null;

        if ($cursor === '') {
            $cursor = null;
        }

        try {
            $result = $billing->listInvoices(
                /*
                 * El tenant viene del middleware.
                 * Nunca se recibe tenant_id ni customer_id
                 * desde el frontend.
                 */
                tenant: $currentTenant->get(),

                limit: $limit,

                startingAfter: $cursor,
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json($result);
    }
}
