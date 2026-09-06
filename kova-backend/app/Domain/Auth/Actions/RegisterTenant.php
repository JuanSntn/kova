<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\PlanCode;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RegisterTenant
{
    public function __construct(private readonly CreateTenant $createTenant) {}

    /**
     * @return array{user: User, tenant: Tenant}
     */
    public function handle(
        string $name,
        string $email,
        string $password,
        string $businessName,
    ): array {
        return DB::transaction(function () use ($name, $email, $password, $businessName): array {
            $plan = Plan::query()
                ->where('code', PlanCode::Free->value)
                ->where('is_active', true)
                ->first();

            if (! $plan) {
                throw new RuntimeException('The Free plan must be configured before registration.');
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $tenant = $this->createTenant->handle(
                owner: $user,
                plan: $plan,
                name: $businessName,
                slug: $this->uniqueSlug($businessName),
            );

            return compact('user', 'tenant');
        });
    }

    private function uniqueSlug(string $businessName): string
    {
        $baseSlug = Str::slug($businessName) ?: 'negocio';
        $slug = $baseSlug;

        while (Tenant::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
