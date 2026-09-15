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
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (
            ! array_key_exists('merchant_id', $data)
            || ! array_key_exists('customer_id', $data)
            || ! array_key_exists('subscription_period_id', $data)
            || ! array_key_exists('event_key', $data)
            || ! array_key_exists('usage_date', $data)
            || ! array_key_exists('units', $data)
        ) {
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
