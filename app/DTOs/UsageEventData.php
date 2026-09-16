<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

readonly class UsageEventData
{
    public function __construct(
        public int $merchantId,
        public int $customerId,
        public int $subscriptionPeriodId,
        public string $eventKey,
        public CarbonImmutable $usageDate,
        public int $units,
    ) {
    }

    /**
     * @param array{
     *     merchant_id: int,
     *     customer_id: int,
     *     subscription_period_id: int,
     *     event_key: string,
     *     usage_date: string,
     *     units: int
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (! isset(
            $data['merchant_id'],
            $data['customer_id'],
            $data['subscription_period_id'],
            $data['event_key'],
            $data['usage_date'],
            $data['units'],
        )) {
            throw new InvalidArgumentException('Usage event payload is incomplete.');
        }

        return new self(
            merchantId: (int) $data['merchant_id'],
            customerId: (int) $data['customer_id'],
            subscriptionPeriodId: (int) $data['subscription_period_id'],
            eventKey: (string) $data['event_key'],
            usageDate: CarbonImmutable::parse((string) $data['usage_date']),
            units: (int) $data['units'],
        );
    }

    /**
     * @return array{
     *     merchant_id: int,
     *     customer_id: int,
     *     subscription_period_id: int,
     *     event_key: string,
     *     usage_date: string,
     *     units: int
     * }
     */
    public function toArray(): array
    {
        return [
            'merchant_id' => $this->merchantId,
            'customer_id' => $this->customerId,
            'subscription_period_id' => $this->subscriptionPeriodId,
            'event_key' => $this->eventKey,
            'usage_date' => $this->usageDate->toDateString(),
            'units' => $this->units,
        ];
    }
}
