<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\UsageDaily;
use App\Models\UsageEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates the complete billing domain and exposes its relationships', function () {
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create([
        'base_price' => 3000,
    ]);
    $customer = Customer::factory()->for($merchant)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create();
    $period = SubscriptionPeriod::factory()->for($subscription)->for($plan)->create([
        'base_price' => $plan->base_price,
        'included_units' => $plan->included_units,
        'overage_rate' => $plan->overage_rate,
    ]);
    $usageEvent = UsageEvent::factory()
        ->for($merchant)
        ->for($customer)
        ->for($period, 'subscriptionPeriod')
        ->create();
    $dailyUsage = UsageDaily::factory()->for($merchant)->for($customer)->create();
    $invoice = Invoice::factory()
        ->for($merchant)
        ->for($customer)
        ->for($subscription)
        ->create();

    expect($merchant->customers->contains($customer))->toBeTrue()
        ->and($merchant->plans->contains($plan))->toBeTrue()
        ->and($customer->subscriptions->contains($subscription))->toBeTrue()
        ->and($plan->subscriptionPeriods->contains($period))->toBeTrue()
        ->and($period->usageEvents->contains($usageEvent))->toBeTrue()
        ->and($merchant->dailyUsage->contains($dailyUsage))->toBeTrue()
        ->and($subscription->invoices->contains($invoice))->toBeTrue()
        ->and($subscription->starts_at->toDateString())->toBe($subscription->starts_at->format('Y-m-d'))
        ->and($plan->base_price)->toBe('3000.00');
});

it('creates every required billing domain table', function () {
    foreach ([
        'merchants',
        'customers',
        'plans',
        'subscriptions',
        'subscription_periods',
        'usage_events',
        'usage_daily',
        'invoices',
        'invoice_items',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('allows the same customer email for different merchants but not the same merchant', function () {
    $email = 'customer@example.com';
    $firstMerchant = Merchant::factory()->create();
    $secondMerchant = Merchant::factory()->create();

    Customer::factory()->for($firstMerchant)->create(['email' => $email]);
    Customer::factory()->for($secondMerchant)->create(['email' => $email]);

    expect(fn () => Customer::factory()->for($firstMerchant)->create(['email' => $email]))
        ->toThrow(QueryException::class);
});

it('enforces usage and invoice idempotency constraints', function () {
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create();
    $customer = Customer::factory()->for($merchant)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create();
    $period = SubscriptionPeriod::factory()->for($subscription)->for($plan)->create();

    UsageEvent::factory()
        ->for($merchant)
        ->for($customer)
        ->for($period, 'subscriptionPeriod')
        ->create(['event_key' => 'evt_123']);

    expect(fn () => UsageEvent::factory()
        ->for($merchant)
        ->for($customer)
        ->for($period, 'subscriptionPeriod')
        ->create(['event_key' => 'evt_123']))
        ->toThrow(QueryException::class);

    $invoiceAttributes = [
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'subscription_id' => $subscription->id,
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
    ];

    Invoice::factory()->create($invoiceAttributes);

    expect(fn () => Invoice::factory()->create($invoiceAttributes))
        ->toThrow(QueryException::class);
});
