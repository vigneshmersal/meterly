<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->decimal('base_price', 12, 2);
            $table->unsignedBigInteger('included_units');
            $table->decimal('overage_rate', 12, 4);
            $table->timestamps();

            $table->index(['subscription_id', 'starts_at', 'ends_at']);
            $table->index(['plan_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_periods');
    }
};
