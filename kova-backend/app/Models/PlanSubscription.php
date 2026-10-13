<?php

namespace App\Models;

use App\Domain\Audit\AuditRecorder;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PlanSubscription extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'cancellation_branch_ids',
        'cancellation_membership_ids',
        'plan_id',
        'public_id',
        'plan_version_id',
        'plan_price_id',
        'current_tenant_id',
        'pending_tenant_id',
        'usage_anchor_at',
        'grace_ends_at',
        'last_billing_verified_at',
        'billing_verification_failed_since',
        'cancel_at_period_end',
        'cancellation_requested_at',
        'provider',
        'stripe_subscription_id',
        'stripe_price_id',
        'status',
        'monthly_price_snapshot',
        'entitlements_snapshot',
        'starts_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancelled_at',
        'ended_at',
        'last_stripe_event_created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,

            'cancellation_branch_ids' => 'array',
            'cancellation_membership_ids' => 'array',

            'usage_anchor_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'last_billing_verified_at' => 'datetime',
            'billing_verification_failed_since' => 'datetime',

            'cancel_at_period_end' => 'boolean',

            'cancellation_requested_at' => 'datetime',

            'monthly_price_snapshot' => 'decimal:2',

            'entitlements_snapshot' => 'array',

            'starts_at' => 'datetime',

            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',

            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',

            'last_stripe_event_created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($model): void {
            $model->public_id ??= (string) Str::uuid();
        });

        static::saving(function ($model): void {
            if (
                $model->current_tenant_id !== null
                && (int) $model->current_tenant_id
                !== (int) $model->tenant_id
            ) {
                throw new \DomainException(
                    'El contrato vigente debe pertenecer a su tenant.'
                );
            }

            if (
                $model->pending_tenant_id !== null
                && (int) $model->pending_tenant_id
                !== (int) $model->tenant_id
            ) {
                throw new \DomainException(
                    'El contrato pendiente debe pertenecer a su tenant.'
                );
            }

            if (
                $model->current_tenant_id !== null
                && $model->pending_tenant_id !== null
            ) {
                throw new \DomainException(
                    'Un contrato no puede ocupar los slots vigente y pendiente al mismo tiempo.'
                );
            }

            if (
                $model->exists
                && $model->getRawOriginal(
                    'plan_version_id'
                ) !== null
                && $model->isDirty([
                    'plan_version_id',
                    'plan_price_id',
                    'entitlements_snapshot',
                    'monthly_price_snapshot',
                ])
            ) {
                throw new \DomainException(
                    'Las condiciones contratadas son inmutables.'
                );
            }
        });

        static::updated(function ($model): void {
            $fields = [
                'status',
                'current_tenant_id',
                'cancel_at_period_end',
                'cancellation_requested_at',
                'grace_ends_at',
                'ended_at',
                'cancelled_at',
            ];

            if (! $model->wasChanged($fields)) {
                return;
            }

            app(AuditRecorder::class)->record(
                request(),
                'billing.contract_updated',
                $model,
                array_intersect_key(
                    $model->getRawOriginal(),
                    array_flip($fields),
                ),
                array_intersect_key(
                    $model->getAttributes(),
                    array_flip($fields),
                ),
            );
        });
    }

    public function version()
    {
        return $this->belongsTo(
            PlanVersion::class,
            'plan_version_id',
        );
    }

    public function price()
    {
        return $this->belongsTo(
            PlanPrice::class,
            'plan_price_id',
        );
    }

    public function addons()
    {
        return $this->hasMany(
            SubscriptionAddon::class
        );
    }

    public function billingLinks()
    {
        return $this->hasMany(
            BillingSubscriptionLink::class
        );
    }

    public function usagePeriods()
    {
        return $this->hasMany(
            UsagePeriod::class
        );
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(
            Plan::class
        );
    }
}
