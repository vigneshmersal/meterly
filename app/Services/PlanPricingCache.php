<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Models\Merchant;
use Illuminate\Support\Facades\Cache;

class PlanPricingCache
{
    public const TTL_MINUTES = 30;

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     base_price: string,
     *     billing_cycle: string,
     *     included_units: int,
     *     overage_rate: string
     * }
     */
    public function get(Merchant $merchant, int $planId): array
    {
        return Cache::remember(
            $this->key($merchant->id, $planId),
            now()->addMinutes(self::TTL_MINUTES),
            function () use ($merchant, $planId): array {
                $plan = $merchant->plans()->findOrFail($planId);

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'base_price' => (string) $plan->base_price,
                    'billing_cycle' => BillingCycle::from($plan->getRawOriginal('billing_cycle'))->value,
                    'included_units' => $plan->included_units,
                    'overage_rate' => (string) $plan->overage_rate,
                ];
            },
        );
    }

    public function forget(Merchant $merchant, int $planId): void
    {
        Cache::forget($this->key($merchant->id, $planId));
    }

    public function key(int $merchantId, int $planId): string
    {
        return "merchant:{$merchantId}:plan:{$planId}";
    }
}
