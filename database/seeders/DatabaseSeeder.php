<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\UsageDaily;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $merchant = Merchant::query()->updateOrCreate(
            ['name' => 'Acme SaaS'],
            [],
        );

        User::query()->updateOrCreate(
            ['email' => 'demo@meterly.test'],
            [
                'name' => 'Meterly Demo',
                'merchant_id' => $merchant->id,
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $plan = Plan::query()->updateOrCreate(
            [
                'merchant_id' => $merchant->id,
                'name' => 'Pro',
            ],
            [
                'name' => 'Pro',
                'base_price' => 3000,
                'billing_cycle' => BillingCycle::Monthly,
                'included_units' => 50000,
                'overage_rate' => 0.08,
            ],
        );
        $basicPlan = Plan::query()->updateOrCreate(
            [
                'merchant_id' => $merchant->id,
                'name' => 'Basic',
            ],
            [
                'name' => 'Basic',
                'base_price' => 1000,
                'billing_cycle' => BillingCycle::Monthly,
                'included_units' => 10000,
                'overage_rate' => 0.10,
            ],
        );

        $periodStart = Carbon::today()->subDays(29);
        $periodEnd = $periodStart->copy()->addMonth()->subDay();
        $customers = collect([
            ['name' => 'ABC Company', 'email' => 'abc@example.com'],
            ['name' => 'Northwind Labs', 'email' => 'northwind@example.com'],
            ['name' => 'Globex Retail', 'email' => 'globex@example.com'],
        ])->map(function (array $attributes) use ($merchant, $plan, $periodStart, $periodEnd): SubscriptionPeriod {
            $customer = Customer::query()->updateOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'email' => $attributes['email'],
                ],
                ['name' => $attributes['name']],
            );

            $subscription = Subscription::query()->updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'plan_id' => $plan->id,
                    'starts_at' => $periodStart,
                    'ends_at' => $periodEnd,
                    'status' => 'active',
                ],
            );

            return SubscriptionPeriod::query()->updateOrCreate(
                [
                    'subscription_id' => $subscription->id,
                    'starts_at' => $periodStart,
                ],
                [
                    'plan_id' => $plan->id,
                    'ends_at' => $periodEnd,
                    'billing_cycle' => BillingCycle::Monthly,
                    'base_price' => $plan->base_price,
                    'included_units' => $plan->included_units,
                    'overage_rate' => $plan->overage_rate,
                ],
            );
        });

        $customers->each(function (SubscriptionPeriod $period, int $index) use ($merchant): void {
            $customerId = $period->subscription->customer_id;
            $events = collect(range(0, 29))->map(function (int $daysAgo) use (
                $merchant,
                $customerId,
                $period,
                $index,
            ): array {
                $usageDate = Carbon::today()->subDays($daysAgo);

                return [
                    'merchant_id' => $merchant->id,
                    'customer_id' => $customerId,
                    'subscription_period_id' => $period->id,
                    'event_key' => "demo-{$customerId}-{$usageDate->toDateString()}",
                    'usage_date' => $usageDate->toDateString(),
                    'units' => 1000 + ($index * 250) + (($daysAgo % 5) * 100),
                    'created_at' => now(),
                ];
            })->all();

            UsageEvent::query()->upsert(
                $events,
                ['merchant_id', 'event_key'],
                ['customer_id', 'subscription_period_id', 'usage_date', 'units'],
            );
        });

        $completedPeriodStart = Carbon::today()->subDays(59);
        $completedPeriodEnd = Carbon::today()->subDays(30);
        $completedCustomer = Customer::query()->updateOrCreate(
            [
                'merchant_id' => $merchant->id,
                'email' => 'billing-demo@example.com',
            ],
            ['name' => 'Billing Demo Customer'],
        );
        $completedSubscription = Subscription::query()->updateOrCreate(
            ['customer_id' => $completedCustomer->id],
            [
                'plan_id' => $plan->id,
                'starts_at' => $completedPeriodStart,
                'ends_at' => $completedPeriodEnd,
                'status' => 'ended',
            ],
        );
        $completedPeriod = SubscriptionPeriod::query()->updateOrCreate(
            [
                'subscription_id' => $completedSubscription->id,
                'starts_at' => $completedPeriodStart,
            ],
            [
                'plan_id' => $plan->id,
                'ends_at' => $completedPeriodEnd,
                'billing_cycle' => BillingCycle::Monthly,
                'base_price' => $plan->base_price,
                'included_units' => $plan->included_units,
                'overage_rate' => $plan->overage_rate,
            ],
        );

        UsageDaily::query()->upsert(
            [[
                'merchant_id' => $merchant->id,
                'customer_id' => $completedCustomer->id,
                'usage_date' => $completedPeriodStart->addDays(14),
                'units' => 60000,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['merchant_id', 'customer_id', 'usage_date'],
            ['units', 'updated_at'],
        );

        $dailyUsage = UsageEvent::query()
            ->where('merchant_id', $merchant->id)
            ->whereBetween('usage_date', [
                Carbon::today()->subDays(29)->toDateString(),
                Carbon::today()->toDateString(),
            ])
            ->selectRaw('merchant_id, customer_id, usage_date, SUM(units) AS units')
            ->groupBy('merchant_id', 'customer_id', 'usage_date')
            ->get()
            ->map(fn ($row): array => [
                'merchant_id' => $row->merchant_id,
                'customer_id' => $row->customer_id,
                'usage_date' => $row->usage_date,
                'units' => $row->units,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

        UsageDaily::query()->upsert(
            $dailyUsage,
            ['merchant_id', 'customer_id', 'usage_date'],
            ['units', 'updated_at'],
        );

        $this->seedActiveBillingScenarios($merchant, $basicPlan, $plan);
        $this->seedCompletedBillingScenarios($merchant, $basicPlan);
    }

    private function seedActiveBillingScenarios(Merchant $merchant, Plan $basicPlan, Plan $proPlan): void
    {
        $today = Carbon::today();

        $this->seedSubscriptionScenario(
            $merchant,
            $basicPlan,
            'zero-usage@example.com',
            'Zero Usage Customer',
            $today->copy()->subDays(10),
            $today->copy()->addDays(19),
            [[
                'date' => $today->copy()->subDays(2),
                'units' => 0,
            ]],
        );

        $this->seedSubscriptionScenario(
            $merchant,
            $basicPlan,
            'exact-allowance@example.com',
            'Exact Allowance Customer',
            $today->copy()->subDays(10),
            $today->copy()->addDays(19),
            [[
                'date' => $today->copy()->subDays(1),
                'units' => 10000,
            ]],
        );

        $this->seedSubscriptionScenario(
            $merchant,
            $proPlan,
            'staggered-cycle@example.com',
            'Staggered Cycle Customer',
            $today->copy()->subDays(14),
            $today->copy()->addDays(15),
            [[
                'date' => $today->copy()->subDays(3),
                'units' => 12000,
            ]],
        );

        $customer = Customer::query()->updateOrCreate(
            [
                'merchant_id' => $merchant->id,
                'email' => 'plan-change@example.com',
            ],
            ['name' => 'Mid-cycle Plan Change Customer'],
        );
        $subscription = Subscription::query()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'plan_id' => $proPlan->id,
                'starts_at' => $today->copy()->subDays(14),
                'ends_at' => $today->copy()->addDays(15),
                'status' => 'active',
            ],
        );
        $oldPeriod = SubscriptionPeriod::query()->updateOrCreate(
            [
                'subscription_id' => $subscription->id,
                'starts_at' => $today->copy()->subDays(14),
            ],
            [
                'plan_id' => $basicPlan->id,
                'ends_at' => $today->copy()->subDays(5),
                'billing_cycle' => BillingCycle::Monthly,
                'base_price' => $basicPlan->base_price,
                'included_units' => $basicPlan->included_units,
                'overage_rate' => $basicPlan->overage_rate,
            ],
        );
        $newPeriod = SubscriptionPeriod::query()->updateOrCreate(
            [
                'subscription_id' => $subscription->id,
                'starts_at' => $today->copy()->subDays(4),
            ],
            [
                'plan_id' => $proPlan->id,
                'ends_at' => $today->copy()->addDays(15),
                'billing_cycle' => BillingCycle::Monthly,
                'base_price' => $proPlan->base_price,
                'included_units' => $proPlan->included_units,
                'overage_rate' => $proPlan->overage_rate,
            ],
        );

        $this->upsertUsage(
            $merchant,
            $customer,
            $oldPeriod,
            [['date' => $today->copy()->subDays(14), 'units' => 8000]],
        );
        $this->upsertUsage(
            $merchant,
            $customer,
            $newPeriod,
            [['date' => $today->copy()->subDays(4), 'units' => 65000]],
        );

        $churnCustomer = Customer::query()->updateOrCreate(
            [
                'merchant_id' => $merchant->id,
                'email' => 'churn-risk@example.com',
            ],
            ['name' => 'Churn Risk Customer'],
        );
        $churnSubscription = Subscription::query()->updateOrCreate(
            ['customer_id' => $churnCustomer->id],
            [
                'plan_id' => $proPlan->id,
                'starts_at' => $today->copy()->subDays(29),
                'ends_at' => $today->copy()->addDays(30),
                'status' => 'active',
            ],
        );
        $churnPeriod = SubscriptionPeriod::query()->updateOrCreate(
            [
                'subscription_id' => $churnSubscription->id,
                'starts_at' => $today->copy()->subDays(29),
            ],
            [
                'plan_id' => $proPlan->id,
                'ends_at' => $today->copy()->addDays(30),
                'billing_cycle' => BillingCycle::Monthly,
                'base_price' => $proPlan->base_price,
                'included_units' => $proPlan->included_units,
                'overage_rate' => $proPlan->overage_rate,
            ],
        );

        $this->upsertDailyUsage([
            [
                'merchant_id' => $merchant->id,
                'customer_id' => $churnCustomer->id,
                'usage_date' => $today->copy()->subMonth()->startOfMonth(),
                'units' => 100000,
            ],
            [
                'merchant_id' => $merchant->id,
                'customer_id' => $churnCustomer->id,
                'usage_date' => $today->copy()->subDays(2),
                'units' => 1000,
            ],
        ]);

        $this->upsertUsage(
            $merchant,
            $churnCustomer,
            $churnPeriod,
            [['date' => $today->copy()->subDays(2), 'units' => 1000]],
        );
    }

    private function seedCompletedBillingScenarios(Merchant $merchant, Plan $basicPlan): void
    {
        $today = Carbon::today();
        $scenarios = [
            [
                'email' => 'completed-zero@example.com',
                'name' => 'Completed Zero Usage Customer',
                'units' => 0,
            ],
            [
                'email' => 'completed-exact@example.com',
                'name' => 'Completed Exact Allowance Customer',
                'units' => 10000,
            ],
        ];

        foreach ($scenarios as $scenario) {
            $customer = Customer::query()->updateOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'email' => $scenario['email'],
                ],
                ['name' => $scenario['name']],
            );
            $subscription = Subscription::query()->updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'plan_id' => $basicPlan->id,
                    'starts_at' => $today->copy()->subDays(59),
                    'ends_at' => $today->copy()->subDays(30),
                    'status' => 'ended',
                ],
            );
            $period = SubscriptionPeriod::query()->updateOrCreate(
                [
                    'subscription_id' => $subscription->id,
                    'starts_at' => $today->copy()->subDays(59),
                ],
                [
                    'plan_id' => $basicPlan->id,
                    'ends_at' => $today->copy()->subDays(30),
                    'billing_cycle' => BillingCycle::Monthly,
                    'base_price' => $basicPlan->base_price,
                    'included_units' => $basicPlan->included_units,
                    'overage_rate' => $basicPlan->overage_rate,
                ],
            );
            $usageDate = $today->copy()->subDays(45);

            $this->upsertDailyUsage([[
                'merchant_id' => $merchant->id,
                'customer_id' => $customer->id,
                'usage_date' => $usageDate,
                'units' => $scenario['units'],
            ]]);
        }
    }

    /**
     * @param  array<int, array{date: Carbon, units: int}>  $usage
     */
    private function seedSubscriptionScenario(
        Merchant $merchant,
        Plan $plan,
        string $email,
        string $name,
        Carbon $startsAt,
        Carbon $endsAt,
        array $usage,
    ): void {
        $customer = Customer::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'email' => $email],
            ['name' => $name],
        );
        $subscription = Subscription::query()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'plan_id' => $plan->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'active',
            ],
        );
        $period = SubscriptionPeriod::query()->updateOrCreate(
            ['subscription_id' => $subscription->id, 'starts_at' => $startsAt],
            [
                'plan_id' => $plan->id,
                'ends_at' => $endsAt,
                'billing_cycle' => $plan->billing_cycle,
                'base_price' => $plan->base_price,
                'included_units' => $plan->included_units,
                'overage_rate' => $plan->overage_rate,
            ],
        );

        $this->upsertUsage($merchant, $customer, $period, $usage);
    }

    /**
     * @param  array<int, array{date: Carbon, units: int}>  $usage
     */
    private function upsertUsage(
        Merchant $merchant,
        Customer $customer,
        SubscriptionPeriod $period,
        array $usage,
    ): void {
        UsageEvent::query()->upsert(
            collect($usage)->map(fn (array $entry): array => [
                'merchant_id' => $merchant->id,
                'customer_id' => $customer->id,
                'subscription_period_id' => $period->id,
                'event_key' => "demo-{$customer->id}-{$period->id}-{$entry['date']->toDateString()}",
                'usage_date' => $entry['date']->toDateString(),
                'units' => $entry['units'],
                'created_at' => now(),
            ])->all(),
            ['merchant_id', 'event_key'],
            ['customer_id', 'subscription_period_id', 'usage_date', 'units'],
        );

        $this->upsertDailyUsage(collect($usage)->map(fn (array $entry): array => [
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'usage_date' => $entry['date'],
            'units' => $entry['units'],
        ])->all());
    }

    /** @param array<int, array{merchant_id: int, customer_id: int, usage_date: Carbon, units: int}> $rows */
    private function upsertDailyUsage(array $rows): void
    {
        UsageDaily::query()->upsert(
            collect($rows)->map(fn (array $row): array => $row + [
                'created_at' => now(),
                'updated_at' => now(),
            ])->all(),
            ['merchant_id', 'customer_id', 'usage_date'],
            ['units', 'updated_at'],
        );
    }
}
