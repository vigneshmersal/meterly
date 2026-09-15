<?php

namespace App\Models;

use Database\Factories\UsageDailyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['merchant_id', 'customer_id', 'usage_date', 'units'])]
class UsageDaily extends Model
{
    /** @use HasFactory<UsageDailyFactory> */
    use HasFactory;

    protected $table = 'usage_daily';

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'units' => 'integer',
        ];
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
