<?php

namespace App\Actions\Plans;

use App\Models\Merchant;
use App\Models\Plan;
use App\Support\PlanValidationRules;
use Illuminate\Support\Facades\Validator;

class CreatePlanAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Merchant $merchant, array $attributes): Plan
    {
        $validated = Validator::validate($attributes, PlanValidationRules::rules());

        return $merchant->plans()->create($validated);
    }
}
