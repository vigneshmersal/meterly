<?php

namespace App\Console\Commands;

use App\Jobs\GenerateInvoiceJob;
use App\Models\SubscriptionPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateDueInvoices extends Command
{
    protected $signature = 'billing:generate-invoices';

    protected $description = 'Dispatch invoice jobs for completed subscription periods';

    public function handle(): int
    {
        $dispatched = 0;

        SubscriptionPeriod::query()
            ->whereDate('ends_at', '<=', today()->toDateString())
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('invoices')
                    ->whereColumn('invoices.subscription_id', 'subscription_periods.subscription_id')
                    ->whereColumn('invoices.period_start', 'subscription_periods.starts_at')
                    ->whereColumn('invoices.period_end', 'subscription_periods.ends_at');
            })
            ->select('id')
            ->chunkById(500, function ($periods) use (&$dispatched): void {
                foreach ($periods as $period) {
                    GenerateInvoiceJob::dispatch($period->id);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} invoice job(s).");

        return self::SUCCESS;
    }
}
