<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('overage_amount', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('status', 32)->default('issued');
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'period_start', 'period_end']);
            $table->index(['merchant_id', 'period_start', 'period_end']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
