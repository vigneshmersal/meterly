<?php

namespace App\Services\Billing;

use App\DTOs\BillingSegmentData;
use App\Models\SubscriptionPeriod;
use App\Models\UsageDaily;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class BillingCalculationService
{
    /**
     * Calculate a base charge using inclusive active and billing-period dates.
     */
    public function proratedBaseCharge(
        SubscriptionPeriod $period,
        CarbonInterface $activeStart,
        CarbonInterface $activeEnd,
    ): string {
        $periodStartDate = CarbonImmutable::parse($period->getRawOriginal('starts_at'));
        $periodEndDate = CarbonImmutable::parse($period->getRawOriginal('ends_at'));
        $totalCycleDays = $periodStartDate->diffInDays($periodEndDate) + 1;
        $periodStart = max($periodStartDate->getTimestamp(), $activeStart->getTimestamp());
        $periodEnd = min($periodEndDate->getTimestamp(), $activeEnd->getTimestamp());

        if ($periodEnd < $periodStart) {
            return '0.00';
        }

        $activeDays = (int) floor(($periodEnd - $periodStart) / 86400) + 1;
        $ratio = bcdiv(
            $this->numericString((string) $activeDays),
            $this->numericString((string) $totalCycleDays),
            8,
        );
        $charge = bcmul($this->numericString((string) $period->base_price), $ratio, 8);

        return $this->roundMoney($charge);
    }

    /**
     * Calculate overage units and amount from one pricing segment.
     *
     * @return array{units: int, amount: string}
     */
    public function overage(int $usageUnits, int $includedUnits, string $overageRate): array
    {
        $overageUnits = max(0, $usageUnits - $includedUnits);

        return [
            'units' => $overageUnits,
            'amount' => $this->roundMoney(
                bcmul($this->numericString((string) $overageUnits), $this->numericString($overageRate), 8),
            ),
        ];
    }

    /**
     * Calculate charges for one subscription-period segment.
     *
     * @return array{
     *     usage_units: int,
     *     included_units: int,
     *     overage_units: int,
     *     base_charge: string,
     *     overage_amount: string,
     *     total: string
     * }
     */
    public function periodCharges(
        SubscriptionPeriod $period,
        CarbonInterface $activeStart,
        CarbonInterface $activeEnd,
    ): array {
        return $this->billingSegment($period, $activeStart, $activeEnd)->toArray();
    }

    public function billingSegment(
        SubscriptionPeriod $period,
        CarbonInterface $activeStart,
        CarbonInterface $activeEnd,
    ): BillingSegmentData {
        if ($activeEnd->lessThan($activeStart)) {
            throw new InvalidArgumentException('The active end date must not precede the active start date.');
        }

        $period->loadMissing(['subscription.customer']);
        $usageUnits = (int) UsageDaily::query()
            ->where('merchant_id', $period->subscription->customer->merchant_id)
            ->where('customer_id', $period->subscription->customer_id)
            ->where('usage_date', '>=', $activeStart->toDateString().' 00:00:00')
            ->where(
                'usage_date',
                '<',
                $activeEnd->copy()->addDay()->toDateString().' 00:00:00',
            )
            ->sum('units');
        $overage = $this->overage($usageUnits, $period->included_units, (string) $period->overage_rate);
        $baseCharge = $this->proratedBaseCharge($period, $activeStart, $activeEnd);

        return new BillingSegmentData(
            period: $period,
            startsAt: CarbonImmutable::parse($activeStart->toDateString()),
            endsAt: CarbonImmutable::parse($activeEnd->toDateString()),
            usageUnits: $usageUnits,
            includedUnits: $period->included_units,
            overageUnits: $overage['units'],
            baseCharge: $baseCharge,
            overageAmount: $overage['amount'],
            total: $this->roundMoney(bcadd(
                $this->numericString($baseCharge),
                $this->numericString($overage['amount']),
                8,
            )),
        );
    }

    private function roundMoney(string $amount): string
    {
        return bcadd($this->numericString($amount), '0.005', 2);
    }

    /**
     * @return numeric-string
     */
    private function numericString(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Billing values must be numeric.');
        }

        return $value;
    }
}
