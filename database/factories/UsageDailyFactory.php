<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\UsageDaily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageDaily>
 */
class UsageDailyFactory extends Factory
{
    protected $model = UsageDaily::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'customer_id' => Customer::factory(),
            'usage_date' => fake()->date(),
            'units' => fake()->numberBetween(1, 10000),
        ];
    }
}
