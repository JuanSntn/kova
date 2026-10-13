<?php

namespace App\Models;

use App\Domain\Audit\AuditRecorder;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionChange extends Model
{
    use BelongsToTenant;

    protected $table = 'subscription_changes';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'pending',
        'change_mode' => 'period_end',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'completed_at' => 'datetime',

            'retained_branch_ids' => 'array',
            'retained_membership_ids' => 'array',

            'quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($model): void {
            $model->public_id ??= (string) Str::uuid();
        });

        static::created(function (SubscriptionChange $change): void {
            app(AuditRecorder::class)->record(
                request(),
                'billing.change_requested',
                $change,
                [],
                $change->auditState(),
            );
        });

        static::updated(function (SubscriptionChange $change): void {
            if (! $change->wasChanged([
                'status',
                'effective_at',
                'completed_at',
                'target_plan_subscription_id',
                'quantity',
                'retained_branch_ids',
                'retained_membership_ids',
            ])) {
                return;
            }

            app(AuditRecorder::class)->record(
                request(),
                'billing.change_updated',
                $change,
                array_intersect_key(
                    $change->getRawOriginal(),
                    array_flip(array_keys($change->auditState())),
                ),
                $change->auditState(),
            );
        });
    }

    private function auditState(): array
    {
        return [
            'kind' => $this->kind,
            'change_mode' => $this->change_mode,
            'status' => $this->status,
            'target_plan_price_id' => $this->target_plan_price_id,
            'target_plan_subscription_id' => $this->target_plan_subscription_id,
            'quantity' => $this->quantity,
            'retained_branch_ids' => $this->retained_branch_ids,
            'retained_membership_ids' => $this->retained_membership_ids,
            'effective_at' => $this->effective_at,
            'completed_at' => $this->completed_at,
        ];
    }

    public function planSubscription()
    {
        return $this->belongsTo(
            PlanSubscription::class,
            'plan_subscription_id',
        );
    }

    public function targetContract()
    {
        return $this->belongsTo(
            PlanSubscription::class,
            'target_plan_subscription_id',
        );
    }

    public function targetPrice()
    {
        return $this->belongsTo(
            PlanPrice::class,
            'target_plan_price_id',
        );
    }

    public function addon()
    {
        return $this->belongsTo(
            SubscriptionAddon::class,
            'subscription_addon_id',
        );
    }

    public function publicSummary(): array
    {
        $confirmed = false;
        $paymentOperation = null;

        if ($this->change_mode === 'period_end') {
            $confirmed = BillingOutbox::query()
                ->whereIn(
                    'kind',
                    [
                        'schedule_plan',
                        'schedule_addon',
                    ]
                )
                ->where(
                    'payload->change_id',
                    $this->id
                )
                ->where(
                    'status',
                    'completed'
                )
                ->exists();
        } else {
            $confirmed =
                $this->status === 'completed';

            $paymentOperation = BillingOutbox::query()
                ->where('kind', 'upgrade_plan')
                ->where('payload->change_id', $this->id)
                ->latest('id')
                ->first();
        }

        return [
            'id' => $this->public_id,

            'kind' => $this->kind,

            'change_mode' => $this->change_mode,

            'status' => $this->status,

            'confirmed' => $confirmed,

            'payment_status' => $paymentOperation
                ? data_get(
                    $paymentOperation->result,
                    'status',
                    $paymentOperation->status
                )
                : null,

            'invoice_url' => $paymentOperation
                ? data_get($paymentOperation->result, 'invoice_url')
                : null,

            'effective_at' => $this->effective_at,

            'quantity' => $this->quantity,

            'target_name' => $this
                ->targetPrice
                ?->version
                ?->name
                ?? $this
                    ->addon
                    ?->version
                    ?->name,

            'target_amount' => $this
                ->targetPrice
                ?->amount,

            'target_interval' => $this
                ->targetPrice
                ?->interval,

            'target_currency' => $this
                ->targetPrice
                ?->currency,

            'retained_branch_ids' => $this->retained_branch_ids
                ?? [],

            'retained_membership_ids' => $this->retained_membership_ids
                ?? [],
        ];
    }
}
