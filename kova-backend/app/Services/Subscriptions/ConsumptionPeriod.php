<?php

namespace App\Services\Subscriptions;

use App\Models\PlanSubscription;
use Carbon\CarbonImmutable;

final class ConsumptionPeriod
{
    public function for(PlanSubscription $s, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now('UTC');
        if ($s->price->amount === 0) {
            $local = $at->setTimezone($s->tenant->timezone ?: 'UTC');

            return ['starts_at' => $local->startOfMonth()->utc(), 'ends_at' => $local->startOfMonth()->addMonth()->utc(), 'type' => 'calendar_month'];
        }
        $anchor = CarbonImmutable::instance($s->usage_anchor_at)->utc();
        if ($at->lt($anchor)) {
            throw new \DomainException('El consumo precede al contrato.');
        }
        $months = ($at->year - $anchor->year) * 12 + $at->month - $anchor->month;
        $start = $anchor->addMonthsNoOverflow($months);
        if ($start->gt($at)) {
            $start = $anchor->addMonthsNoOverflow(--$months);
        }

        return ['starts_at' => $start, 'ends_at' => $anchor->addMonthsNoOverflow($months + 1), 'type' => 'billing_cycle'];
    }
}
