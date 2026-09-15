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
    }
}
