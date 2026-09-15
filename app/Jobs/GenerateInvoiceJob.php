<?php

namespace App\Jobs;

use App\Models\SubscriptionPeriod;
use App\Services\Billing\GenerateInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public int $subscriptionPeriodId) {}

    public function handle(GenerateInvoiceService $generateInvoice): void
    {
        $period = SubscriptionPeriod::query()->findOrFail($this->subscriptionPeriodId);

        $generateInvoice->handle($period);
    }

    public function uniqueId(): string
    {
        return (string) $this->subscriptionPeriodId;
    }
}
