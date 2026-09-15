<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && ($this->missing('merchant_id')
                || $this->user()->merchant_id === $this->integer('merchant_id'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'subscription_period_id' => ['required', 'integer', 'exists:subscription_periods,id'],
            'event_key' => ['required', 'string', 'max:255'],
            'usage_date' => ['required', 'date'],
            'units' => ['required', 'integer', 'min:1'],
        ];
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
    public function usageData(): array
    {
        return [
            'merchant_id' => $this->integer('merchant_id'),
            'customer_id' => $this->integer('customer_id'),
            'subscription_period_id' => $this->integer('subscription_period_id'),
            'event_key' => $this->string('event_key')->toString(),
            'usage_date' => $this->string('usage_date')->toString(),
            'units' => $this->integer('units'),
        ];
    }
}
