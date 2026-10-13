<?php

namespace App\Console\Commands;

use App\Models\Addon;
use App\Models\Plan;
use App\Services\Subscriptions\CatalogService;
use Illuminate\Console\Command;

class PublishStampOverageOffer extends Command
{
    protected $signature = 'billing:publish-stamp-overage-offer';

    protected $description = 'Publica el timbre excedente de $1.12 más IVA y nuevas versiones de planes compatibles';

    public function handle(CatalogService $catalog): int
    {
        $identity = Addon::query()->where('code', 'extra_stamp')->firstOrFail();
        $existing = $identity->versions()->where('status', 'published')->whereHas('prices', fn ($query) => $query->where('interval', 'month')->where('amount', 112)->where('currency', 'mxn'))->latest('version')->first();
        if ($existing) {
            $this->components->info('La oferta de $1.12 más IVA ya está publicada.');

            return self::SUCCESS;
        }

        $addonVersion = $catalog->createVersion($identity, ['name' => 'Timbre excedente', 'mode' => 'metered', 'prices' => ['month' => 112], 'entitlements' => []]);
        $catalog->publish($addonVersion);

        Plan::query()->where('is_active', true)->where('code', '!=', 'free')->each(function (Plan $plan) use ($catalog, $addonVersion) {
            $source = $plan->versions()->where('status', 'published')->with(['entitlements.feature', 'prices', 'availableAddons.addon'])->latest('version')->first();
            if (! $source) {
                return;
            }
            $addons = $source->availableAddons->map(fn ($addon) => [
                'version' => $addon->addon->code === 'extra_stamp' ? $addonVersion->public_id : $addon->public_id,
                'automatic' => (bool) $addon->pivot->automatic,
            ])->all();
            $entitlements = $source->entitlements->mapWithKeys(fn ($value) => [
                $value->feature->code => $value->is_unlimited ? null : ($value->feature->type === 'boolean' ? (bool) $value->value : (int) $value->value),
            ])->all();
            $prices = $source->prices->mapWithKeys(fn ($price) => [$price->interval => (int) $price->amount])->all();
            $version = $catalog->createVersion($plan, ['name' => $source->name, 'prices' => $prices, 'entitlements' => $entitlements, 'addons' => $addons]);
            $catalog->publish($version);
            $this->line("Publicada {$plan->code} v{$version->version}.");
        });

        $this->components->info('Oferta publicada. Ejecuta billing:sync-prices en Stripe test antes de ofrecerla.');

        return self::SUCCESS;
    }
}
