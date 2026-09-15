<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\Merchant;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->randomElement(['Basic', 'Pro', 'Enterprise']),
            'base_price' => fake()->randomFloat(2, 500, 5000),
            'billing_cycle' => BillingCycle::Monthly,
            'included_units' => fake()->numberBetween(1000, 100000),
            'overage_rate' => fake()->randomFloat(4, 0.01, 1),
        ];
    }
}
