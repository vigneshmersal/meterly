<?php

namespace App\Actions\Usage;

use App\DTOs\UsageEventData;
use App\Jobs\AggregateDailyUsageJob;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\SubscriptionPeriod;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\DatabaseExceptionClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordUsageAction
{
    /**
     * @return array{event: UsageEvent, alreadyRecorded: bool}
     */
    public function handle(User $user, UsageEventData $data): array
    {
        $merchant = Merchant::query()->findOrFail($data->merchantId);
        if ($user->merchant_id !== $merchant->id) {
            throw (new ModelNotFoundException)->setModel(Merchant::class, [$merchant->id]);
        }
        $customer = Customer::query()->findOrFail($data->customerId);
        $period = SubscriptionPeriod::query()
            ->with(['subscription.customer'])
            ->findOrFail($data->subscriptionPeriodId);

        $this->ensureOwnership($merchant, $customer, $period);
        $this->ensureUsageDateWithinPeriod($data, $period);

        $attributes = $data->toArray();

        try {
            $event = DB::transaction(
                fn (): UsageEvent => UsageEvent::query()->create($attributes),
            );

            AggregateDailyUsageJob::dispatch(
                $data->usageDate->toDateString(),
                $data->usageDate->toDateString(),
                $merchant->id,
            )->afterCommit();

            return ['event' => $event, 'alreadyRecorded' => false];
        } catch (QueryException $exception) {
            if (! DatabaseExceptionClassifier::isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existingEvent = UsageEvent::query()
                ->where('merchant_id', $merchant->id)
                ->where('event_key', $data->eventKey)
                ->first();

            if ($existingEvent === null) {
                throw $exception;
            }

            return ['event' => $existingEvent, 'alreadyRecorded' => true];
        }
    }

    private function ensureOwnership(
        Merchant $merchant,
        Customer $customer,
        SubscriptionPeriod $period,
    ): void {
        $periodCustomer = $period->subscription?->customer;

        if (
            $customer->merchant_id !== $merchant->id
            || $periodCustomer?->merchant_id !== $merchant->id
            || $periodCustomer->id !== $customer->id
        ) {
            throw (new ModelNotFoundException)->setModel(Customer::class, [$customer->id]);
        }
    }

    private function ensureUsageDateWithinPeriod(
        UsageEventData $data,
        SubscriptionPeriod $period,
    ): void {
        $usageDate = $data->usageDate->toDateString();
        $periodStart = CarbonImmutable::parse($period->getRawOriginal('starts_at'))->toDateString();
        $periodEnd = CarbonImmutable::parse($period->getRawOriginal('ends_at'))->toDateString();

        if ($usageDate < $periodStart || $usageDate > $periodEnd) {
            throw ValidationException::withMessages([
                'usage_date' => 'The usage date must fall within the subscription period.',
            ]);
        }
    }
}
