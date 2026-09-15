<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $merchant = Merchant::factory()->create([
            'name' => 'Acme SaaS',
        ]);

        $plan = Plan::factory()->for($merchant)->create([
            'name' => 'Pro',
            'base_price' => 3000,
            'billing_cycle' => BillingCycle::Monthly,
            'included_units' => 50000,
            'overage_rate' => 0.08,
        ]);

        $customer = Customer::factory()->for($merchant)->create([
            'name' => 'ABC Company',
        ]);

        $subscription = Subscription::factory()
            ->for($customer)
            ->for($plan)
            ->create();

        SubscriptionPeriod::factory()
            ->for($subscription)
            ->for($plan)
            ->create([
                'base_price' => $plan->base_price,
                'included_units' => $plan->included_units,
                'overage_rate' => $plan->overage_rate,
            ]);
    }
}
