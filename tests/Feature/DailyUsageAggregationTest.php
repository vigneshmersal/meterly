<?php

use App\Jobs\AggregateDailyUsageJob;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\UsageDaily;
use App\Models\UsageEvent;

function aggregationFixture(): array
{
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create();
    $customer = Customer::factory()->for($merchant)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create();
    $period = SubscriptionPeriod::factory()->for($subscription)->for($plan)->create();

    return [$merchant, $customer, $period];
}

it('aggregates events into one daily row per merchant and customer', function () {
    [$merchant, $customer, $period] = aggregationFixture();

    UsageEvent::factory()->count(3)
        ->for($merchant)
        ->for($customer)
        ->for($period, 'subscriptionPeriod')
        ->sequence(
            ['event_key' => 'evt_1', 'usage_date' => '2026-09-13', 'units' => 100],
            ['event_key' => 'evt_2', 'usage_date' => '2026-09-13', 'units' => 250],
            ['event_key' => 'evt_3', 'usage_date' => '2026-09-14', 'units' => 500],
        )
        ->create();

    $job = new AggregateDailyUsageJob('2026-09-13', '2026-09-14');
    $job->handle();

    expect(UsageDaily::query()
        ->where('merchant_id', $merchant->id)
        ->orderBy('usage_date')
        ->pluck('units')
        ->all())->toBe([350, 500]);
});

it('keeps merchants and customers isolated during aggregation', function () {
    [$merchant, $customer, $period] = aggregationFixture();
    [$otherMerchant, $otherCustomer, $otherPeriod] = aggregationFixture();

    UsageEvent::factory()->for($merchant)->for($customer)->for($period, 'subscriptionPeriod')->create([
        'event_key' => 'merchant-one-event',
        'usage_date' => '2026-09-13',
        'units' => 100,
    ]);
    UsageEvent::factory()->for($otherMerchant)->for($otherCustomer)->for($otherPeriod, 'subscriptionPeriod')->create([
        'event_key' => 'merchant-two-event',
        'usage_date' => '2026-09-13',
        'units' => 900,
    ]);

    (new AggregateDailyUsageJob('2026-09-13', '2026-09-13', $merchant->id))->handle();

    expect(UsageDaily::query()->where('merchant_id', $merchant->id)->value('units'))->toBe(100)
        ->and(UsageDaily::query()->where('merchant_id', $otherMerchant->id)->exists())->toBeFalse();
});

it('rebuilds a date range without double-counting on retry', function () {
    [$merchant, $customer, $period] = aggregationFixture();

    UsageEvent::factory()->for($merchant)->for($customer)->for($period, 'subscriptionPeriod')->create([
        'event_key' => 'retry-safe-event',
        'usage_date' => '2026-09-13',
        'units' => 250,
    ]);

    $job = new AggregateDailyUsageJob('2026-09-13', '2026-09-13', $merchant->id);
    $job->handle();
    $job->handle();

    expect(UsageDaily::query()->where('merchant_id', $merchant->id)->value('units'))->toBe(250)
        ->and(UsageDaily::query()->where('merchant_id', $merchant->id)->count())->toBe(1);
});

it('removes stale aggregates when rebuilding a range with no raw events', function () {
    [$merchant, $customer] = aggregationFixture();

    UsageDaily::factory()->for($merchant)->for($customer)->create([
        'usage_date' => '2026-09-13',
        'units' => 999,
    ]);

    (new AggregateDailyUsageJob('2026-09-13', '2026-09-13', $merchant->id))->handle();

    expect(UsageDaily::query()->where('merchant_id', $merchant->id)->exists())->toBeFalse();
});

it('processes raw events in bounded chunks', function () {
    [$merchant, $customer, $period] = aggregationFixture();

    UsageEvent::factory()->count(3)
        ->for($merchant)
        ->for($customer)
        ->for($period, 'subscriptionPeriod')
        ->sequence(
            ['event_key' => 'chunk-event-1', 'units' => 100],
            ['event_key' => 'chunk-event-2', 'units' => 200],
            ['event_key' => 'chunk-event-3', 'units' => 300],
        )
        ->create(['usage_date' => '2026-09-13']);

    $job = new AggregateDailyUsageJob('2026-09-13', '2026-09-13', $merchant->id);
    $job->chunkSize = 1;
    $job->handle();

    expect(UsageDaily::query()->where('merchant_id', $merchant->id)->value('units'))->toBe(600);
});
