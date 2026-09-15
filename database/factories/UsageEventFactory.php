<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\SubscriptionPeriod;
use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'customer_id' => Customer::factory(),
            'subscription_period_id' => SubscriptionPeriod::factory(),
            'event_key' => fake()->unique()->uuid(),
            'usage_date' => fake()->date(),
            'units' => fake()->numberBetween(1, 10000),
        ];
    }
}
