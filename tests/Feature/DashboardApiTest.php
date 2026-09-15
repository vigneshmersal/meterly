<?php

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\UsageDaily;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\travelTo;

function dashboardFixture(): array
{
    $merchant = Merchant::factory()->create();
    $user = User::factory()->create(['merchant_id' => $merchant->id]);
    $plan = Plan::factory()->for($merchant)->create([
        'name' => 'Growth',
        'included_units' => 100,
        'overage_rate' => 0.10,
    ]);
    $customer = Customer::factory()->for($merchant)->create(['name' => 'Alpha Retail']);
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'starts_at' => '2026-09-01',
        'ends_at' => '2026-09-30',
    ]);
    $period = SubscriptionPeriod::factory()->for($subscription)->for($plan)->create([
        'starts_at' => '2026-09-01',
        'ends_at' => '2026-09-30',
        'included_units' => 100,
        'overage_rate' => 0.10,
    ]);

    return [$merchant, $user, $customer, $period];
}

it('returns the merchant dashboard with active plan metrics and ordered usage insights', function () {
    travelTo(Carbon::parse('2026-09-15'));
    [$merchant, $user, $customer, $period] = dashboardFixture();
    $secondCustomer = Customer::factory()->for($merchant)->create(['name' => 'Beta Foods']);
    $churnCustomer = Customer::factory()->for($merchant)->create(['name' => 'Churn Risk']);
    $stableCustomer = Customer::factory()->for($merchant)->create(['name' => 'Stable']);

    UsageDaily::factory()->for($merchant)->for($customer)->create([
        'usage_date' => '2026-09-10',
        'units' => 300,
    ]);
    UsageDaily::factory()->for($merchant)->for($secondCustomer)->create([
        'usage_date' => '2026-09-10',
        'units' => 100,
    ]);
    UsageDaily::factory()->for($merchant)->for($churnCustomer)->create([
        'usage_date' => '2026-08-20',
        'units' => 1000,
    ]);
    UsageDaily::factory()->for($merchant)->for($churnCustomer)->create([
        'usage_date' => '2026-09-10',
        'units' => 400,
    ]);
    UsageDaily::factory()->for($merchant)->for($stableCustomer)->create([
        'usage_date' => '2026-08-20',
        'units' => 1000,
    ]);
    UsageDaily::factory()->for($merchant)->for($stableCustomer)->create([
        'usage_date' => '2026-09-10',
        'units' => 500,
    ]);

    $response = $this->actingAs($user)->getJson(route('merchants.dashboard', $merchant));

    $response->assertOk()->assertJsonPath('active_plan.name', 'Growth')
        ->assertJsonPath('active_plan.billing_cycle', 'monthly')
        ->assertJsonPath('active_plan.included_units', 100)
        ->assertJsonPath('active_plan.current_period_start', '2026-09-01')
        ->assertJsonPath('active_plan.current_period_end', '2026-09-30')
        ->assertJsonPath('active_plan.current_cycle_usage.units', 300)
        ->assertJsonPath('active_plan.current_cycle_usage.allowance', 100)
        ->assertJsonPath('active_plan.current_cycle_usage.percentage', 300)
        ->assertJsonPath('system_status.status', 'operational')
        ->assertJsonPath('system_status.aggregation_up_to_date', true)
        ->assertJsonPath('top_customers.0.name', 'Stable')
        ->assertJsonPath('top_customers.0.usage', 500)
        ->assertJsonPath('top_customers.1.name', 'Churn Risk')
        ->assertJsonPath('projected_overage_revenue', 50)
        ->assertJsonPath('churn_risk_customers.0.customer_id', $churnCustomer->id)
        ->assertJsonPath('churn_risk_customers.0.drop_percentage', 60)
        ->assertJsonCount(30, 'usage_trend')
        ->assertJsonPath('usage_trend.0.date', '2026-08-17')
        ->assertJsonPath('usage_trend.0.units', 0)
        ->assertJsonPath('usage_trend.24.date', '2026-09-10')
        ->assertJsonPath('usage_trend.24.units', 1300);
});

it('reports processing when raw usage is newer than the aggregate read model', function () {
    travelTo(Carbon::parse('2026-09-15'));
    [$merchant, $user, $customer, $period] = dashboardFixture();

    UsageEvent::factory()->for($merchant)->for($customer)->for($period, 'subscriptionPeriod')->create([
        'usage_date' => '2026-09-15',
        'units' => 50,
    ]);

    $response = $this->actingAs($user)->getJson(route('merchants.dashboard', $merchant));

    $response->assertOk()
        ->assertJsonPath('system_status.status', 'processing')
        ->assertJsonPath('system_status.aggregation_up_to_date', false);
});

it('does not expose another merchant dashboard', function () {
    [$merchant, $user] = dashboardFixture();
    $otherMerchant = Merchant::factory()->create();

    $response = $this->actingAs($user)->getJson(route('merchants.dashboard', $otherMerchant));

    $response->assertNotFound();
});

it('requires authentication for the dashboard API', function () {
    $merchant = Merchant::factory()->create();

    $response = $this->getJson(route('merchants.dashboard', $merchant));

    $response->assertUnauthorized();
});
