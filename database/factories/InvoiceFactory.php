<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $periodStart = Carbon::today()->startOfMonth();
        $subtotal = fake()->randomFloat(2, 500, 5000);
        $overageAmount = fake()->randomFloat(2, 0, 1000);

        return [
            'merchant_id' => Merchant::factory(),
            'customer_id' => Customer::factory(),
            'subscription_id' => Subscription::factory(),
            'period_start' => $periodStart,
            'period_end' => $periodStart->copy()->endOfMonth(),
            'subtotal' => $subtotal,
            'overage_amount' => $overageAmount,
            'total' => $subtotal + $overageAmount,
            'status' => 'issued',
            'issued_at' => now(),
        ];
    }
}
