<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $startsAt = Carbon::today();

        return [
            'customer_id' => Customer::factory(),
            'plan_id' => Plan::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMonth()->subDay(),
            'status' => 'active',
        ];
    }
}
