<?php

namespace App\Actions\Plans;

use App\Models\Merchant;
use App\Models\Plan;
use App\Services\PlanPricingCache;
use App\Support\PlanValidationRules;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UpdatePlanAction
{
    public function __construct(
        private readonly PlanPricingCache $pricingCache,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Merchant $merchant, Plan $plan, array $attributes): Plan
    {
        $this->ensureMerchantOwnership($merchant, $plan);

        $validated = Validator::validate($attributes, PlanValidationRules::rules());

        $updatedPlan = DB::transaction(function () use ($plan, $validated): Plan {
            $plan->update($validated);

            return $plan->refresh();
        });

        $this->pricingCache->forget($merchant, $updatedPlan->id);

        return $updatedPlan;
    }

    private function ensureMerchantOwnership(Merchant $merchant, Plan $plan): void
    {
        if ($plan->merchant_id !== $merchant->id) {
            throw (new ModelNotFoundException)->setModel(Plan::class, [$plan->id]);
        }
    }
}
