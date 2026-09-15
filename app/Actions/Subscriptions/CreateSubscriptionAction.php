<?php

namespace App\Actions\Subscriptions;

use App\Enums\BillingCycle;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CreateSubscriptionAction
{
    public function handle(
        Merchant $merchant,
        Customer $customer,
        Plan $plan,
        ?CarbonInterface $startsAt = null,
    ): Subscription {
        $this->ensureMerchantOwnership($merchant, $customer, $plan);

        $startsAt ??= today();
        $billingCycle = $plan->billingCycle();
        $endsAt = $this->calculateEndDate($startsAt, $billingCycle);

        return DB::transaction(function () use ($customer, $plan, $startsAt, $endsAt, $billingCycle): Subscription {
            $subscription = $customer->subscriptions()->create([
                'plan_id' => $plan->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'active',
            ]);

            $subscription->periods()->create([
                'plan_id' => $plan->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'base_price' => $plan->base_price,
                'billing_cycle' => $billingCycle,
                'included_units' => $plan->included_units,
                'overage_rate' => $plan->overage_rate,
            ]);

            return $subscription->load('periods');
        });
    }

    private function ensureMerchantOwnership(Merchant $merchant, Customer $customer, Plan $plan): void
    {
        if ($customer->merchant_id !== $merchant->id) {
            throw (new ModelNotFoundException)->setModel(Customer::class, [$customer->id]);
        }

        if ($plan->merchant_id !== $merchant->id) {
            throw (new ModelNotFoundException)->setModel(Plan::class, [$plan->id]);
        }
    }

    private function calculateEndDate(CarbonInterface $startsAt, BillingCycle $billingCycle): CarbonInterface
    {
        $startsAt = CarbonImmutable::instance($startsAt);

        return $billingCycle === BillingCycle::Monthly
            ? $startsAt->addMonth()->subDay()
            : $startsAt->addYear()->subDay();
    }
}
