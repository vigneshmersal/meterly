<?php

namespace App\Actions\Plans;

use App\Enums\BillingCycle;
use App\Models\Merchant;
use App\Models\Plan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreatePlanAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Merchant $merchant, array $attributes): Plan
    {
        $validated = Validator::validate($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'included_units' => ['required', 'integer', 'min:0'],
            'overage_rate' => ['required', 'numeric', 'min:0'],
        ]);

        return $merchant->plans()->create($validated);
    }
}
