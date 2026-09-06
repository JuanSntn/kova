<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateTicketSettingsRequest;
use App\Http\Resources\Settings\TicketSettingsResource;
use App\Models\TenantTicketSetting;
use App\Services\Tickets\TicketSettingsService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TicketSettingsController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TicketSettingsService $tickets,
    ) {}

    public function show(): JsonResponse
    {
        Gate::authorize(
            'viewAny',
            TenantTicketSetting::class,
        );

        $tenant =
            $this
            ->currentTenant
            ->get();

        $setting =
            $this
            ->tickets
            ->setting(
                $tenant
            );

        return response()->json([
            'data' =>
            new TicketSettingsResource(
                $setting
            ),

            'meta' =>
            $this
                ->tickets
                ->meta(
                    $tenant
                ),
        ]);
    }

    public function update(
        UpdateTicketSettingsRequest $request
    ): JsonResponse {
        $tenant =
            $this
            ->currentTenant
            ->get();

        $setting =
            $this
            ->tickets
            ->setting(
                $tenant
            );

        Gate::authorize(
            'update',
            $setting,
        );

        $setting =
            $this
            ->tickets
            ->update(
                tenant: $tenant,

                data: $request
                    ->validated(),
            );

        return response()->json([
            'data' =>
            new TicketSettingsResource(
                $setting
            ),

            'meta' =>
            $this
                ->tickets
                ->meta(
                    $tenant
                ),
        ]);
    }
}
