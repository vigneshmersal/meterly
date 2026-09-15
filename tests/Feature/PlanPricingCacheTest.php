<?php

use App\Actions\Plans\UpdatePlanAction;
use App\Enums\BillingCycle;
use App\Models\Merchant;
use App\Models\Plan;
use App\Services\PlanPricingCache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\travel;

it('caches merchant-scoped plan pricing after the first lookup', function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create([
        'name' => 'Growth',
        'base_price' => 3000,
        'billing_cycle' => BillingCycle::Monthly,
        'included_units' => 50000,
        'overage_rate' => 0.08,
    ]);
    $pricingCache = app(PlanPricingCache::class);

    $first = $pricingCache->get($merchant, $plan->id);
    $plan->update(['name' => 'Changed in database']);
    $second = $pricingCache->get($merchant, $plan->id);

    expect($first)->toBe($second)
        ->and($first['name'])->toBe('Growth')
        ->and(Cache::has($pricingCache->key($merchant->id, $plan->id)))->toBeTrue();
});

it('keeps cache entries isolated between merchants', function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    $merchant = Merchant::factory()->create();
    $otherMerchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create(['name' => 'Merchant One']);
    $pricingCache = app(PlanPricingCache::class);

    expect(fn () => $pricingCache->get($otherMerchant, $plan->id))
        ->toThrow(ModelNotFoundException::class);
});

it('expires cached pricing after the configured lifetime', function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create(['name' => 'Growth']);
    $pricingCache = app(PlanPricingCache::class);

    $pricingCache->get($merchant, $plan->id);
    $plan->update(['name' => 'Renewed Growth']);
    travel(31)->minutes();

    expect($pricingCache->get($merchant, $plan->id)['name'])->toBe('Renewed Growth');
});

it('forgets cached pricing after a successful plan update', function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create(['name' => 'Growth']);
    $pricingCache = app(PlanPricingCache::class);
    $pricingCache->get($merchant, $plan->id);

    $updatedPlan = app(UpdatePlanAction::class)->handle($merchant, $plan, [
        'name' => 'Scale',
        'base_price' => $plan->base_price,
        'billing_cycle' => $plan->billing_cycle,
        'included_units' => $plan->included_units,
        'overage_rate' => $plan->overage_rate,
    ]);

    expect($updatedPlan->name)->toBe('Scale')
        ->and(Cache::has($pricingCache->key($merchant->id, $plan->id)))->toBeFalse()
        ->and($pricingCache->get($merchant, $plan->id)['name'])->toBe('Scale');
});
