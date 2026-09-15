<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SubscriptionPeriod;
use App\Support\DatabaseExceptionClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateInvoiceService
{
    public function __construct(
        private readonly BillingCalculationService $billing,
    ) {}

    public function handle(SubscriptionPeriod $period): Invoice
    {
        $period->loadMissing(['subscription.customer', 'plan']);

        $periodStart = CarbonImmutable::parse($period->getRawOriginal('starts_at'));
        $periodEnd = CarbonImmutable::parse($period->getRawOriginal('ends_at'));
        $context = [
            'merchant_id' => $period->subscription->customer->merchant_id,
            'customer_id' => $period->subscription->customer_id,
            'subscription_id' => $period->subscription_id,
            'subscription_period_id' => $period->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ];

        Log::info('Invoice generation started.', $context);

        try {
            [$invoice, $created] = DB::transaction(function () use ($period, $periodStart, $periodEnd): array {
                $existingInvoice = Invoice::query()
                    ->where('subscription_id', $period->subscription_id)
                    ->whereDate('period_start', $periodStart->toDateString())
                    ->whereDate('period_end', $periodEnd->toDateString())
                    ->first();

                if ($existingInvoice !== null) {
                    return [$existingInvoice->load('items'), false];
                }

                $charges = $this->billing->periodCharges(
                    $period,
                    $periodStart,
                    $periodEnd,
                );

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

                return [$invoice->load('items'), true];
            });

            if ($created) {
                Log::info('Invoice generated successfully.', $context + [
                    'invoice_id' => $invoice->id,
                    'subtotal' => $invoice->subtotal,
                    'overage_amount' => $invoice->overage_amount,
                    'total' => $invoice->total,
                ]);
            } else {
                Log::info('Invoice generation skipped because invoice already exists.', $context + [
                    'invoice_id' => $invoice->id,
                ]);
            }

            return $invoice;
        } catch (QueryException $exception) {
            if (! DatabaseExceptionClassifier::isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existingInvoice = Invoice::query()
                ->where('subscription_id', $period->subscription_id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->whereDate('period_end', $periodEnd->toDateString())
                ->first();

            if ($existingInvoice === null) {
                throw $exception;
            }

            Log::warning('Invoice generation encountered a concurrent duplicate and reused the existing invoice.', $context + [
                'invoice_id' => $existingInvoice->id,
            ]);

            return $existingInvoice->load('items');
        }
    }
}
