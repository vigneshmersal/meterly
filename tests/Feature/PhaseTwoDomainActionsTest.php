<?php

use App\Actions\Customers\CreateCustomerAction;
use App\Actions\Plans\CreatePlanAction;
use App\Actions\Subscriptions\CreateSubscriptionAction;
use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

it('creates a merchant plan from validated attributes', function () {
    $merchant = Merchant::factory()->create();

    $plan = app(CreatePlanAction::class)->handle($merchant, [
        'name' => 'Pro',
        'base_price' => 3000,
        'billing_cycle' => BillingCycle::Monthly,
        'included_units' => 50000,
        'overage_rate' => 0.08,
    ]);

    expect($plan->merchant->is($merchant))->toBeTrue()
        ->and($plan->base_price)->toBe('3000.00')
        ->and($plan->included_units)->toBe(50000);
});

it('rejects invalid plan attributes', function () {
    $merchant = Merchant::factory()->create();

    expect(fn () => app(CreatePlanAction::class)->handle($merchant, [
        'name' => '',
        'base_price' => -1,
        'billing_cycle' => 'weekly',
        'included_units' => -1,
        'overage_rate' => -1,
    ]))->toThrow(ValidationException::class);
});

it('creates a customer with email uniqueness scoped to its merchant', function () {
    $merchant = Merchant::factory()->create();
    $otherMerchant = Merchant::factory()->create();
    $attributes = ['name' => 'ABC Company', 'email' => 'customer@example.com'];

    $customer = app(CreateCustomerAction::class)->handle($merchant, $attributes);
    $otherCustomer = app(CreateCustomerAction::class)->handle($otherMerchant, $attributes);

    expect($customer->merchant->is($merchant))->toBeTrue()
        ->and($otherCustomer->merchant->is($otherMerchant))->toBeTrue();

    expect(fn () => app(CreateCustomerAction::class)->handle($merchant, $attributes))
        ->toThrow(ValidationException::class);
});

it('creates a subscription and snapshots the plan pricing in its period', function () {
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create([
        'base_price' => 3000,
        'billing_cycle' => BillingCycle::Monthly,
        'included_units' => 50000,
        'overage_rate' => 0.08,
    ]);
    $customer = Customer::factory()->for($merchant)->create();
    $startsAt = CarbonImmutable::parse('2026-09-15');

    $subscription = app(CreateSubscriptionAction::class)->handle(
        $merchant,
        $customer,
        $plan,
        $startsAt,
    );

    $period = $subscription->periods->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->starts_at->toDateString())->toBe('2026-09-15')
        ->and($subscription->ends_at->toDateString())->toBe('2026-10-14')
        ->and($period->starts_at->toDateString())->toBe('2026-09-15')
        ->and($period->ends_at->toDateString())->toBe('2026-10-14')
        ->and($period->billing_cycle)->toBe(BillingCycle::Monthly)
        ->and($period->base_price)->toBe('3000.00')
        ->and($period->included_units)->toBe(50000)
        ->and($period->overage_rate)->toBe('0.0800');
});

it('rejects subscriptions that cross merchant boundaries', function () {
    $merchant = Merchant::factory()->create();
    $otherMerchant = Merchant::factory()->create();
    $customer = Customer::factory()->for($merchant)->create();
    $plan = Plan::factory()->for($otherMerchant)->create();

    expect(fn () => app(CreateSubscriptionAction::class)->handle($merchant, $customer, $plan))
        ->toThrow(ModelNotFoundException::class);
});

it('calculates yearly subscription periods from the customer start date', function () {
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create(['billing_cycle' => BillingCycle::Yearly]);
    $customer = Customer::factory()->for($merchant)->create();

    $subscription = app(CreateSubscriptionAction::class)->handle(
        $merchant,
        $customer,
        $plan,
        CarbonImmutable::parse('2026-02-01'),
    );

    expect($subscription->ends_at->toDateString())->toBe('2027-01-31');
});
