<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->index('usage_date');
        });

        Schema::table('subscription_periods', function (Blueprint $table): void {
            $table->index('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->dropIndex(['usage_date']);
        });

        Schema::table('subscription_periods', function (Blueprint $table): void {
            $table->dropIndex(['ends_at']);
        });
    }
};
