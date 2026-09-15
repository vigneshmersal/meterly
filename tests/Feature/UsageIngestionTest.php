<?php

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\UsageEvent;
use App\Models\User;

function usagePayload(Merchant $merchant, Customer $customer, SubscriptionPeriod $period): array
{
    return [
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'subscription_period_id' => $period->id,
        'event_key' => 'evt_'.fake()->unique()->uuid(),
        'usage_date' => '2026-09-13',
        'units' => 250,
    ];
}

function usageFixture(): array
{
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create();
    $customer = Customer::factory()->for($merchant)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create();
    $period = SubscriptionPeriod::factory()->for($subscription)->for($plan)->create();

    return [$merchant, $customer, $period];
}

it('requires authentication to record usage', function () {
    [$merchant, $customer, $period] = usageFixture();

    $this->postJson('/usage', usagePayload($merchant, $customer, $period))
        ->assertUnauthorized();
});

it('records a validated usage event for an authenticated merchant request', function () {
    [$merchant, $customer, $period] = usageFixture();
    $payload = usagePayload($merchant, $customer, $period);

    $this->actingAs(User::factory()->for($merchant)->create())
        ->postJson('/usage', $payload)
        ->assertCreated()
        ->assertExactJson([
            'message' => 'Usage recorded successfully',
            'event_id' => $payload['event_key'],
        ]);

    expect(UsageEvent::query()->where('event_key', $payload['event_key'])->value('units'))
        ->toBe(250);
});

it('rejects invalid usage input', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/usage', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'merchant_id',
            'customer_id',
            'subscription_period_id',
            'event_key',
            'usage_date',
            'units',
        ]);
});

it('returns the idempotent response without duplicating a usage event', function () {
    [$merchant, $customer, $period] = usageFixture();
    $payload = usagePayload($merchant, $customer, $period);
    $user = User::factory()->for($merchant)->create();

    $this->actingAs($user)->postJson('/usage', $payload)->assertCreated();
    $this->actingAs($user)
        ->postJson('/usage', $payload)
        ->assertOk()
        ->assertExactJson([
            'message' => 'Usage event already recorded',
            'event_id' => $payload['event_key'],
        ]);

    expect(UsageEvent::query()->where('event_key', $payload['event_key'])->count())->toBe(1);
});

it('rejects cross-merchant usage references', function () {
    [$merchant, $customer, $period] = usageFixture();
    $otherMerchant = Merchant::factory()->create();
    $payload = usagePayload($otherMerchant, $customer, $period);

    $this->actingAs(User::factory()->for($otherMerchant)->create())
        ->postJson('/usage', $payload)
        ->assertNotFound();

    expect(UsageEvent::query()->count())->toBe(0);
});

it('registers the usage route with its named rate limiter', function () {
    expect(app('router')->getRoutes()->getByName('usage.store')->uri())
        ->toBe('usage');

    expect(app('router')->getRoutes()->getByName('usage.store')->gatherMiddleware())
        ->toContain('throttle:usage');
});
