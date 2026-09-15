<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateCustomerAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Merchant $merchant, array $attributes): Customer
    {
        $validated = Validator::validate($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('customers', 'email')->where(
                    fn ($query) => $query->where('merchant_id', $merchant->id),
                ),
            ],
        ]);

        return $merchant->customers()->create($validated);
    }
}
