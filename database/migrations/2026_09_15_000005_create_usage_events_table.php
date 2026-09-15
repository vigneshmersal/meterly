<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_period_id')->constrained()->cascadeOnDelete();
            $table->string('event_key');
            $table->date('usage_date');
            $table->unsignedBigInteger('units');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['merchant_id', 'event_key']);
            $table->index(['merchant_id', 'usage_date']);
            $table->index(['customer_id', 'usage_date']);
            $table->index(['subscription_period_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
