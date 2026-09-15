<?php

namespace App\Jobs;

use App\Models\UsageDaily;
use App\Models\UsageEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class AggregateDailyUsageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 180];

    public int $uniqueFor = 3600;

    public int $chunkSize = 1000;

    public function __construct(
        public string $fromDate,
        public string $toDate,
        public ?int $merchantId = null,
    ) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            UsageDaily::query()
                ->whereDate('usage_date', '>=', $this->fromDate)
                ->whereDate('usage_date', '<=', $this->toDate)
                ->when(
                    $this->merchantId !== null,
                    fn ($query) => $query->where('merchant_id', $this->merchantId),
                )
                ->delete();

            UsageEvent::query()
                ->whereDate('usage_date', '>=', $this->fromDate)
                ->whereDate('usage_date', '<=', $this->toDate)
                ->when(
                    $this->merchantId !== null,
                    fn ($query) => $query->where('merchant_id', $this->merchantId),
                )
                ->selectRaw('merchant_id, customer_id, usage_date, SUM(units) AS units')
                ->groupBy('merchant_id', 'customer_id', 'usage_date')
                ->orderBy('merchant_id')
                ->orderBy('customer_id')
                ->orderBy('usage_date')
                ->chunk($this->chunkSize, function ($dailyTotals): void {
                    UsageDaily::query()->upsert(
                        $dailyTotals->map(fn ($dailyTotal): array => [
                            'merchant_id' => $dailyTotal->merchant_id,
                            'customer_id' => $dailyTotal->customer_id,
                            'usage_date' => $dailyTotal->usage_date,
                            'units' => $dailyTotal->units,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ])->all(),
                        ['merchant_id', 'customer_id', 'usage_date'],
                        ['units', 'updated_at'],
                    );
                });
        });
    }

    public function uniqueId(): string
    {
        return implode(':', [
            $this->merchantId ?? 'all',
            $this->fromDate,
            $this->toDate,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
