<?php

namespace App\Actions\Usage;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\SubscriptionPeriod;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RecordUsageAction
{
    /**
     * @param array{
     *     merchant_id: int,
     *     customer_id: int,
     *     subscription_period_id: int,
     *     event_key: string,
     *     usage_date: string,
     *     units: int
     * } $attributes
     * @return array{event: UsageEvent, alreadyRecorded: bool}
     */
    public function handle(User $user, array $attributes): array
    {
        $merchant = Merchant::query()->findOrFail($attributes['merchant_id']);
        if ($user->merchant_id !== $merchant->id) {
            throw (new ModelNotFoundException)->setModel(Merchant::class, [$merchant->id]);
        }
        $customer = Customer::query()->findOrFail($attributes['customer_id']);
        $period = SubscriptionPeriod::query()
            ->with(['subscription.customer'])
            ->findOrFail($attributes['subscription_period_id']);

        $this->ensureOwnership($merchant, $customer, $period);

        try {
            $event = DB::transaction(
                fn (): UsageEvent => UsageEvent::query()->create($attributes),
            );

            return ['event' => $event, 'alreadyRecorded' => false];
        } catch (QueryException $exception) {
            $existingEvent = UsageEvent::query()
                ->where('merchant_id', $merchant->id)
                ->where('event_key', $attributes['event_key'])
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
}
