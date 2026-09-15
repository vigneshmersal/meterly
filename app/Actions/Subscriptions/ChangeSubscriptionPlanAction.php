<?php

namespace App\Actions\Subscriptions;

use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChangeSubscriptionPlanAction
{
    public function handle(
        Merchant $merchant,
        Subscription $subscription,
        Plan $plan,
        CarbonInterface $effectiveAt,
    ): SubscriptionPeriod {
        $subscription->loadMissing('customer');
        $this->ensureMerchantOwnership($merchant, $subscription, $plan);

        $currentPeriod = $subscription->periods()
            ->whereDate('starts_at', '<=', $effectiveAt->toDateString())
            ->whereDate('ends_at', '>=', $effectiveAt->toDateString())
            ->first();

        if ($currentPeriod === null) {
            throw new InvalidArgumentException('The plan change must occur inside an active subscription period.');
        }

        if ($effectiveAt->lessThanOrEqualTo($currentPeriod->starts_at)) {
            throw new InvalidArgumentException('The plan change must occur after the current period starts.');
        }

        return DB::transaction(function () use ($currentPeriod, $effectiveAt, $plan, $subscription): SubscriptionPeriod {
            $periodEnd = $currentPeriod->ends_at;

            $currentPeriod->update([
                'ends_at' => $effectiveAt->copy()->subDay(),
            ]);

            $newPeriod = $subscription->periods()->create([
                'plan_id' => $plan->id,
                'starts_at' => $effectiveAt,
                'ends_at' => $periodEnd,
                'billing_cycle' => $plan->billing_cycle,
                'base_price' => $plan->base_price,
                'included_units' => $plan->included_units,
                'overage_rate' => $plan->overage_rate,
            ]);

            $subscription->update(['plan_id' => $plan->id]);

            return $newPeriod;
        });
    }

    private function ensureMerchantOwnership(
        Merchant $merchant,
        Subscription $subscription,
        Plan $plan,
    ): void {
        if (
            $subscription->customer->merchant_id !== $merchant->id
            || $plan->merchant_id !== $merchant->id
        ) {
            throw (new ModelNotFoundException)->setModel(Subscription::class, [$subscription->id]);
        }
    }
}
