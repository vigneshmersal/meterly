<?php

namespace App\Jobs;

use App\Models\UsageDaily;
use App\Models\UsageEvent;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AggregateDailyUsageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public int $chunkSize = 1000;

    public function __construct(
        public string $fromDate,
        public string $toDate,
        public ?int $merchantId = null,
    ) {}

    public function handle(): void
    {
        /** @var array<string, array{
         *     merchant_id: int,
         *     customer_id: int,
         *     usage_date: string,
         *     units: int,
         *     created_at: CarbonInterface,
         *     updated_at: CarbonInterface
         * }> $dailyTotals */
        $dailyTotals = [];

        UsageEvent::query()
            ->whereDate('usage_date', '>=', $this->fromDate)
            ->whereDate('usage_date', '<=', $this->toDate)
            ->when(
                $this->merchantId !== null,
                fn ($query) => $query->where('merchant_id', $this->merchantId),
            )
            ->select(['id', 'merchant_id', 'customer_id', 'usage_date', 'units'])
            ->chunkById($this->chunkSize, function ($events) use (&$dailyTotals): void {
                foreach ($events as $event) {
                    $usageDate = substr((string) $event->getRawOriginal('usage_date'), 0, 10);
                    $key = implode(':', [
                        $event->merchant_id,
                        $event->customer_id,
                        $usageDate,
                    ]);

                    $dailyTotals[$key] = [
                        'merchant_id' => $event->merchant_id,
                        'customer_id' => $event->customer_id,
                        'usage_date' => $usageDate,
                        'units' => ($dailyTotals[$key]['units'] ?? 0) + $event->units,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            });

        DB::transaction(function () use ($dailyTotals): void {
            UsageDaily::query()
                ->whereDate('usage_date', '>=', $this->fromDate)
                ->whereDate('usage_date', '<=', $this->toDate)
                ->when(
                    $this->merchantId !== null,
                    fn ($query) => $query->where('merchant_id', $this->merchantId),
                )
                ->delete();

            if ($dailyTotals === []) {
                return;
            }

            UsageDaily::query()->upsert(
                array_values($dailyTotals),
                ['merchant_id', 'customer_id', 'usage_date'],
                ['units', 'updated_at'],
            );
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
}
