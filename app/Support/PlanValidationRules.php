<?php

namespace App\Support;

use App\Enums\BillingCycle;
use Illuminate\Validation\Rule;

final class PlanValidationRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'included_units' => ['required', 'integer', 'min:0'],
            'overage_rate' => ['required', 'numeric', 'min:0'],
        ];
    }
}
