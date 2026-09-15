<?php

use App\Jobs\GenerateInvoiceJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\UsageDaily;
use App\Services\Billing\GenerateInvoiceService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function invoiceFixture(bool $ended = true): array
{
    $merchant = Merchant::factory()->create();
    $plan = Plan::factory()->for($merchant)->create([
        'base_price' => 3000,
        'included_units' => 10000,
        'overage_rate' => 0.10,
    ]);
    $customer = Customer::factory()->for($merchant)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'starts_at' => '2026-09-01',
        'ends_at' => $ended ? '2026-09-10' : '2099-09-30',
    ]);
    $period = SubscriptionPeriod::factory()->for($subscription)->for($plan)->create([
        'starts_at' => '2026-09-01',
        'ends_at' => $ended ? '2026-09-10' : '2099-09-30',
        'base_price' => 3000,
        'included_units' => 10000,
        'overage_rate' => 0.10,
    ]);

    return [$merchant, $plan, $customer, $subscription, $period];
}

it('generates an invoice and line items in one billing transaction', function () {
    [, $plan, $customer, $subscription, $period] = invoiceFixture();
    UsageDaily::factory()->for($period->subscription->customer->merchant)->for($customer)->create([
        'usage_date' => '2026-09-05',
        'units' => 13000,
    ]);

    $invoice = app(GenerateInvoiceService::class)->handle($period);

    expect($invoice->total)->toBe('3300.00')
        ->and($invoice->subtotal)->toBe('3000.00')
        ->and($invoice->overage_amount)->toBe('300.00')
        ->and($invoice->items)->toHaveCount(2)
        ->and($invoice->items->pluck('type')->all())->toBe(['base', 'overage'])
        ->and($invoice->subscription->is($subscription))->toBeTrue()
        ->and($invoice->customer->is($customer))->toBeTrue()
        ->and($invoice->items->first()->description)->toBe("{$plan->name} Plan");
});

it('does not create a duplicate invoice when generation is retried', function () {
    [, , , , $period] = invoiceFixture();
    $service = app(GenerateInvoiceService::class);

    $first = $service->handle($period);
    $second = $service->handle($period);

    expect($second->is($first))->toBeTrue()
        ->and(Invoice::query()->count())->toBe(1)
        ->and($first->items()->count())->toBe(1);
});

it('logs invoice generation and idempotent reuse', function () {
    Log::spy();
    [, , , , $period] = invoiceFixture();
    $service = app(GenerateInvoiceService::class);

    $service->handle($period);
    $service->handle($period);

    Log::shouldHaveReceived('info')
        ->with('Invoice generated successfully.', Mockery::on(
            fn (array $context): bool => isset($context['invoice_id'], $context['total']),
        ))
        ->once();
    Log::shouldHaveReceived('info')
        ->with('Invoice generation skipped because invoice already exists.', Mockery::on(
            fn (array $context): bool => isset($context['invoice_id']),
        ))
        ->once();
});

it('dispatches jobs only for completed periods', function () {
    Queue::fake();
    [, , , , $endedPeriod] = invoiceFixture();
    [, , , , $futurePeriod] = invoiceFixture(false);

    $this->artisan('billing:generate-invoices')->assertSuccessful();

    Queue::assertPushed(GenerateInvoiceJob::class, 1);
    Queue::assertPushed(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job): bool => $job->subscriptionPeriodId === $endedPeriod->id);
    expect($futurePeriod->id)->not->toBe($endedPeriod->id);
});

it('does not dispatch an already invoiced period', function () {
    Queue::fake();
    [, , , , $period] = invoiceFixture();
    app(GenerateInvoiceService::class)->handle($period);

    $this->artisan('billing:generate-invoices')->assertSuccessful();

    Queue::assertNotPushed(GenerateInvoiceJob::class);
});
