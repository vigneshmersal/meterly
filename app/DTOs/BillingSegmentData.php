<?php

namespace App\DTOs;

use App\Models\SubscriptionPeriod;
use Carbon\CarbonImmutable;

readonly class BillingSegmentData
{
    public function __construct(
        public SubscriptionPeriod $period,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $usageUnits,
        public int $includedUnits,
        public int $overageUnits,
        public string $baseCharge,
        public string $overageAmount,
        public string $total,
    ) {}

    /**
     * @return array{
     *     usage_units: int,
     *     included_units: int,
     *     overage_units: int,
     *     base_charge: string,
     *     overage_amount: string,
     *     total: string
     * }
     */
    public function toArray(): array
    {
        return [
            'usage_units' => $this->usageUnits,
            'included_units' => $this->includedUnits,
            'overage_units' => $this->overageUnits,
            'base_charge' => $this->baseCharge,
            'overage_amount' => $this->overageAmount,
            'total' => $this->total,
        ];
    }
}
