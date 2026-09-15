<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Models\Merchant;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\UsageDaily;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class DashboardService
{
    public function __construct(
        private readonly PlanPricingCache $pricingCache,
    ) {}

    /**
     * @return array{
     *     active_plan: array<string, mixed>|null,
     *     system_status: array{
     *         status: string,
     *         message: string,
     *         aggregation_up_to_date: bool,
     *         cache: string,
     *         cache_ttl_minutes: int,
     *         aggregation: string,
     *         aggregation_chunk_size: int,
     *         usage_rate_limit: string
     *     },
     *     top_customers: array<int, array{customer_id: int, name: string, usage: int}>,
     *     projected_overage_revenue: float,
     *     churn_risk_customers: array<int, array{customer_id: int, name: string, previous_month_usage: int, current_month_usage: int, drop_percentage: float}>,
     *     usage_trend: array<int, array{date: string, units: int}>
     * }
     */
    public function handle(Merchant $merchant): array
    {
        $today = CarbonImmutable::today();
        $subscription = $this->activeSubscription($merchant, $today);
        $currentPeriod = $subscription?->periods
            ->first(fn (SubscriptionPeriod $period): bool => $this->containsDate($period, $today));

        return [
            'active_plan' => $currentPeriod === null
                ? null
                : $this->activePlan($merchant, $currentPeriod, $today),
            'system_status' => $this->systemStatus($merchant),
            'top_customers' => $this->topCustomers($merchant, $today),
            'projected_overage_revenue' => $subscription === null
                ? 0.0
                : $this->projectedOverageRevenue($merchant, $subscription, $today),
            'churn_risk_customers' => $this->churnRiskCustomers($merchant, $today),
            'usage_trend' => $this->usageTrend($merchant, $today),
        ];
    }

    private function activeSubscription(Merchant $merchant, CarbonInterface $date): ?Subscription
    {
        return Subscription::query()
            ->whereHas('customer', fn ($query) => $query->where('merchant_id', $merchant->id))
            ->active()
            ->whereDate('starts_at', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('ends_at')
                    ->orWhereDate('ends_at', '>=', $date->toDateString());
            })
            ->with(['periods' => fn ($query) => $query->with('plan')->orderBy('starts_at')])
            ->latest('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function activePlan(
        Merchant $merchant,
        SubscriptionPeriod $period,
        CarbonInterface $today,
    ): array {
        $periodStart = $this->periodStart($period);
        $periodEnd = $this->periodEnd($period);
        $cachedPlan = $this->pricingCache->get($merchant, $period->plan_id);
        $usage = $this->usageForRange(
            $merchant,
            $period->subscription->customer_id,
            $periodStart,
            $this->minimumDate($periodEnd, $today),
        );
        $percentage = $period->included_units === 0
            ? 0.0
            : round(($usage / $period->included_units) * 100, 2);

        return [
            'name' => $cachedPlan['name'],
            'billing_cycle' => BillingCycle::from($period->getRawOriginal('billing_cycle'))->value,
            'included_units' => $period->included_units,
            'current_period_start' => $periodStart->toDateString(),
            'current_period_end' => $periodEnd->toDateString(),
            'current_cycle_usage' => [
                'units' => $usage,
                'allowance' => $period->included_units,
                'percentage' => $percentage,
            ],
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     message: string,
     *     aggregation_up_to_date: bool,
     *     cache: string,
     *     cache_ttl_minutes: int,
     *     aggregation: string,
     *     aggregation_chunk_size: int,
     *     usage_rate_limit: string
     * }
     */
    private function systemStatus(Merchant $merchant): array
    {
        $latestRawDate = $merchant->usageEvents()->max('usage_date');
        $latestAggregatedDate = $merchant->dailyUsage()->max('usage_date');
        $upToDate = $latestRawDate === null || (
            $latestAggregatedDate !== null
            && $latestAggregatedDate >= $latestRawDate
        );

        return [
            'status' => $upToDate ? 'operational' : 'processing',
            'message' => $upToDate
                ? 'Usage data is up to date'
                : 'Usage aggregation is processing recent data',
            'aggregation_up_to_date' => $upToDate,
            'cache' => ucfirst((string) config('cache.default')).' plan pricing cache',
            'cache_ttl_minutes' => PlanPricingCache::TTL_MINUTES,
            'aggregation' => 'Queued, chunked',
            'aggregation_chunk_size' => 1000,
            'usage_rate_limit' => '1,000 requests/minute per merchant',
        ];
    }

    /** @return array<int, array{customer_id: int, name: string, usage: int}> */
    private function topCustomers(Merchant $merchant, CarbonInterface $today): array
    {
        return UsageDaily::query()
            ->where('usage_daily.merchant_id', $merchant->id)
            ->whereBetween('usage_date', [
                $today->startOfMonth()->toDateString(),
                $today->toDateString(),
            ])
            ->join('customers', 'customers.id', '=', 'usage_daily.customer_id')
            ->selectRaw('usage_daily.customer_id, customers.name, SUM(usage_daily.units) AS total_usage')
            ->groupBy('usage_daily.customer_id', 'customers.name')
            ->orderByDesc('total_usage')
            ->orderBy('usage_daily.customer_id')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'customer_id' => (int) data_get($row, 'customer_id'),
                'name' => (string) data_get($row, 'name'),
                'usage' => (int) data_get($row, 'total_usage'),
            ])
            ->all();
    }

    private function projectedOverageRevenue(
        Merchant $merchant,
        Subscription $subscription,
        CarbonInterface $today,
    ): float {
        $total = 0.0;

        foreach ($subscription->periods as $period) {
            $periodStart = $this->periodStart($period);
            $periodEnd = $this->periodEnd($period);

            if ($periodStart->greaterThan($today)) {
                continue;
            }

            $elapsedEnd = $this->minimumDate($periodEnd, $today);
            $elapsedDays = $periodStart->diffInDays($elapsedEnd) + 1;
            $totalDays = $periodStart->diffInDays($periodEnd) + 1;
            $usage = $this->usageForRange(
                $merchant,
                $subscription->customer_id,
                $periodStart,
                $elapsedEnd,
            );
            $projectedUsage = ($usage / $elapsedDays) * $totalDays;
            $projectedOverageUnits = max(0, $projectedUsage - $period->included_units);

            $total += $projectedOverageUnits * (float) $period->overage_rate;
        }

        return round($total, 2);
    }

    /** @return array<int, array{customer_id: int, name: string, previous_month_usage: int, current_month_usage: int, drop_percentage: float}> */
    private function churnRiskCustomers(Merchant $merchant, CarbonInterface $today): array
    {
        $currentStart = $today->startOfMonth();
        $previousStart = $currentStart->subMonth();
        $previousEnd = $currentStart->subDay();
        $usage = UsageDaily::query()
            ->where('usage_daily.merchant_id', $merchant->id)
            ->whereBetween('usage_date', [$previousStart->toDateString(), $today->toDateString()])
            ->join('customers', 'customers.id', '=', 'usage_daily.customer_id')
            ->selectRaw(
                'usage_daily.customer_id, customers.name,
                SUM(CASE WHEN usage_date BETWEEN ? AND ? THEN usage_daily.units ELSE 0 END) AS previous_usage,
                SUM(CASE WHEN usage_date BETWEEN ? AND ? THEN usage_daily.units ELSE 0 END) AS current_usage',
                [
                    $previousStart->toDateString(),
                    $previousEnd->toDateString(),
                    $currentStart->toDateString(),
                    $today->toDateString(),
                ],
            )
            ->groupBy('usage_daily.customer_id', 'customers.name')
            ->get();

        return $usage
            ->map(function ($row): ?array {
                $previousUsage = (int) data_get($row, 'previous_usage');
                $currentUsage = (int) data_get($row, 'current_usage');

                if ($previousUsage === 0) {
                    return null;
                }

                $dropPercentage = (($previousUsage - $currentUsage) / $previousUsage) * 100;

                return $dropPercentage > 50
                    ? [
                        'customer_id' => (int) data_get($row, 'customer_id'),
                        'name' => (string) data_get($row, 'name'),
                        'previous_month_usage' => $previousUsage,
                        'current_month_usage' => $currentUsage,
                        'drop_percentage' => round($dropPercentage, 2),
                    ]
                    : null;
            })
            ->filter()
            ->sortBy([
                ['drop_percentage', 'desc'],
                ['customer_id', 'asc'],
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{date: string, units: int}> */
    private function usageTrend(Merchant $merchant, CarbonInterface $today): array
    {
        $start = $today->subDays(29);
        $dailyUsage = UsageDaily::query()
            ->where('merchant_id', $merchant->id)
            ->whereBetween('usage_date', [$start->toDateString(), $today->toDateString()])
            ->selectRaw('usage_date, SUM(units) AS total_units')
            ->groupBy('usage_date')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                CarbonImmutable::parse((string) data_get($row, 'usage_date'))->toDateString() => (int) data_get($row, 'total_units'),
            ]);

        return collect(range(0, 29))
            ->map(fn (int $offset): array => [
                'date' => $start->addDays($offset)->toDateString(),
                'units' => (int) ($dailyUsage->get($start->addDays($offset)->toDateString()) ?? 0),
            ])
            ->all();
    }

    private function usageForRange(
        Merchant $merchant,
        int $customerId,
        CarbonInterface $start,
        CarbonInterface $end,
    ): int {
        if ($end->lessThan($start)) {
            return 0;
        }

        return (int) $merchant->dailyUsage()
            ->where('customer_id', $customerId)
            ->whereBetween('usage_date', [$start->toDateString(), $end->toDateString()])
            ->sum('units');
    }

    private function containsDate(SubscriptionPeriod $period, CarbonInterface $date): bool
    {
        return $this->periodStart($period)->lessThanOrEqualTo($date)
            && $this->periodEnd($period)->greaterThanOrEqualTo($date);
    }

    private function periodStart(SubscriptionPeriod $period): CarbonImmutable
    {
        return CarbonImmutable::parse($period->getRawOriginal('starts_at'));
    }

    private function periodEnd(SubscriptionPeriod $period): CarbonImmutable
    {
        return CarbonImmutable::parse($period->getRawOriginal('ends_at'));
    }

    private function minimumDate(CarbonInterface $first, CarbonInterface $second): CarbonImmutable
    {
        return CarbonImmutable::instance($first->lessThanOrEqualTo($second) ? $first : $second);
    }
}
