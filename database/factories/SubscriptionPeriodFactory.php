<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SubscriptionPeriod>
 */
class SubscriptionPeriodFactory extends Factory
{
    protected $model = SubscriptionPeriod::class;

    public function definition(): array
    {
        $plan = Plan::factory();
        $startsAt = Carbon::today();

        return [
            'subscription_id' => Subscription::factory(),
            'plan_id' => $plan,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMonth()->subDay(),
            'base_price' => fake()->randomFloat(2, 500, 5000),
            'included_units' => fake()->numberBetween(1000, 100000),
            'overage_rate' => fake()->randomFloat(4, 0.01, 1),
        ];
    }
}
