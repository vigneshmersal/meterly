<?php

use App\Actions\Subscriptions\ChangeSubscriptionPlanAction;
use App\Enums\BillingCycle;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\UsageDaily;
use App\Services\Billing\BillingCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

function billingFixture(array $planAttributes = []): array
{
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create(array_merge([
        'base_price' => 3000,
        'billing_cycle' => BillingCycle::Monthly,
        'included_units' => 10000,
        'overage_rate' => 0.10,
    ], $planAttributes));
    $customer = Customer::factory()->for($merchant)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'starts_at' => '2026-09-01',
        'ends_at' => '2026-09-30',
    ]);
    $period = SubscriptionPeriod::factory()->for($subscription)->for($plan)->create([
        'starts_at' => '2026-09-01',
        'ends_at' => '2026-09-30',
        'base_price' => $plan->base_price,
        'billing_cycle' => $plan->billing_cycle,
        'included_units' => $plan->included_units,
        'overage_rate' => $plan->overage_rate,
    ]);

    return [$merchant, $plan, $customer, $subscription, $period];
}

it('prorates base charges using inclusive actual calendar days', function () {
    [, , , , $period] = billingFixture();
    $service = app(BillingCalculationService::class);

    expect($service->proratedBaseCharge(
        $period,
        CarbonImmutable::parse('2026-09-16'),
        CarbonImmutable::parse('2026-09-30'),
    ))->toBe('1500.00');
});

it('supports different month lengths and full-period charges', function () {
    [, , , $subscription, $period] = billingFixture();
    $period->update([
        'starts_at' => '2026-02-01',
        'ends_at' => '2026-02-28',
    ]);

    expect(app(BillingCalculationService::class)->proratedBaseCharge(
        $period,
        CarbonImmutable::parse('2026-02-01'),
        CarbonImmutable::parse('2026-02-28'),
    ))->toBe('3000.00');
});

it('calculates zero, exact, and above-allowance overage', function () {
    $service = app(BillingCalculationService::class);

    expect($service->overage(8000, 10000, '0.1000'))->toBe(['units' => 0, 'amount' => '0.00'])
        ->and($service->overage(10000, 10000, '0.1000'))->toBe(['units' => 0, 'amount' => '0.00'])
        ->and($service->overage(13000, 10000, '0.1000'))->toBe(['units' => 3000, 'amount' => '300.00']);
});

it('calculates segment charges from aggregated usage', function () {
    [, , $customer, , $period] = billingFixture();
    UsageDaily::factory()->for($period->subscription->customer->merchant)->for($customer)->create([
        'usage_date' => '2026-09-13',
        'units' => 13000,
    ]);

    expect(app(BillingCalculationService::class)->periodCharges(
        $period,
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
    ))->toMatchArray([
        'usage_units' => 13000,
        'overage_units' => 3000,
        'base_charge' => '3000.00',
        'overage_amount' => '300.00',
        'total' => '3300.00',
    ]);
});

it('creates a new immutable pricing segment for a mid-cycle plan change', function () {
    [$merchant, $basic, $customer, $subscription, $period] = billingFixture();
    $pro = Plan::factory()->for($merchant)->create([
        'base_price' => 5000,
        'billing_cycle' => BillingCycle::Monthly,
        'included_units' => 50000,
        'overage_rate' => 0.08,
    ]);

    $newPeriod = app(ChangeSubscriptionPlanAction::class)->handle(
        $merchant,
        $subscription,
        $pro,
        CarbonImmutable::parse('2026-09-16'),
    );

    expect($period->fresh()->ends_at->toDateString())->toBe('2026-09-15')
        ->and($period->fresh()->base_price)->toBe('3000.00')
        ->and($newPeriod->starts_at->toDateString())->toBe('2026-09-16')
        ->and($newPeriod->ends_at->toDateString())->toBe('2026-09-30')
        ->and($newPeriod->base_price)->toBe('5000.00')
        ->and($newPeriod->included_units)->toBe(50000)
        ->and($subscription->fresh()->plan_id)->toBe($pro->id)
        ->and($basic->id)->not->toBe($pro->id);
});

it('rejects plan changes across merchants and outside the current period', function () {
    [$merchant, , , $subscription] = billingFixture();
    $otherMerchant = Merchant::factory()->create();
    $otherPlan = Plan::factory()->for($otherMerchant)->create();

    expect(fn () => app(ChangeSubscriptionPlanAction::class)->handle(
        $merchant,
        $subscription,
        $otherPlan,
        CarbonImmutable::parse('2026-09-16'),
    ))->toThrow(ModelNotFoundException::class);
});

it('rejects a plan change at the current period start', function () {
    [$merchant, $plan, , $subscription] = billingFixture();

    expect(fn () => app(ChangeSubscriptionPlanAction::class)->handle(
        $merchant,
        $subscription,
        $plan,
        CarbonImmutable::parse('2026-09-01'),
    ))->toThrow(InvalidArgumentException::class);
});
