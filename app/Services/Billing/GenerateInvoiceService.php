<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SubscriptionPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class GenerateInvoiceService
{
    public function __construct(
        private readonly BillingCalculationService $billing,
    ) {}

    public function handle(SubscriptionPeriod $period): Invoice
    {
        $period->loadMissing(['subscription.customer', 'plan']);

        return DB::transaction(function () use ($period): Invoice {
            $periodStart = CarbonImmutable::parse($period->getRawOriginal('starts_at'));
            $periodEnd = CarbonImmutable::parse($period->getRawOriginal('ends_at'));
            $existingInvoice = Invoice::query()
                ->where('subscription_id', $period->subscription_id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->whereDate('period_end', $periodEnd->toDateString())
                ->first();

            if ($existingInvoice !== null) {
                return $existingInvoice->load('items');
            }

            $charges = $this->billing->periodCharges(
                $period,
                $periodStart,
                $periodEnd,
            );

            try {
                $invoice = Invoice::query()->create([
                    'merchant_id' => $period->subscription->customer->merchant_id,
                    'customer_id' => $period->subscription->customer_id,
                    'subscription_id' => $period->subscription_id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'subtotal' => $charges['base_charge'],
                    'overage_amount' => $charges['overage_amount'],
                    'total' => $charges['total'],
                    'status' => 'issued',
                    'issued_at' => now(),
                ]);
            } catch (QueryException $exception) {
                $existingInvoice = Invoice::query()
                    ->where('subscription_id', $period->subscription_id)
                    ->whereDate('period_start', $periodStart->toDateString())
                    ->whereDate('period_end', $periodEnd->toDateString())
                    ->first();

                if ($existingInvoice === null) {
                    throw $exception;
                }

                return $existingInvoice->load('items');
            }

            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'description' => "{$period->plan->name} Plan",
                'quantity' => 1,
                'unit_price' => $charges['base_charge'],
                'amount' => $charges['base_charge'],
                'type' => 'base',
            ]);

            if ($charges['overage_units'] > 0) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'description' => "{$period->plan->name} Usage Overage",
                    'quantity' => $charges['overage_units'],
                    'unit_price' => $period->overage_rate,
                    'amount' => $charges['overage_amount'],
                    'type' => 'overage',
                ]);
            }

            return $invoice->load('items');
        });
    }
}
