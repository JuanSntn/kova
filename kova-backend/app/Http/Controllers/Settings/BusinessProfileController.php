<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBusinessProfileRequest;
use App\Http\Resources\Settings\BusinessProfileResource;
use App\Models\TenantBusinessProfile;
use App\Services\Business\BusinessProfileService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BusinessProfileController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly BusinessProfileService $profiles,
    ) {}

    public function show(): JsonResponse
    {
        Gate::authorize(
            'viewAny',
            TenantBusinessProfile::class,
        );

        $profile =
            $this->profiles
            ->profile(
                $this
                    ->currentTenant
                    ->get()
            );

        return response()->json([
            'data' =>
            new BusinessProfileResource(
                $profile
            ),
        ]);
    }

    public function update(
        UpdateBusinessProfileRequest $request
    ): JsonResponse {
        $tenant =
            $this
            ->currentTenant
            ->get();

        $profile =
            $this->profiles
            ->profile(
                $tenant
            );

        Gate::authorize(
            'update',
            $profile,
        );

        $profile =
            $this->profiles
            ->update(
                tenant: $tenant,

                data: $request
                    ->validated(),

                logo: $request
                    ->file(
                        'logo'
                    ),
            );

        return response()->json([
            'data' =>
            new BusinessProfileResource(
                $profile
            ),
        ]);
    }
}
