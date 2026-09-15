<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_periods', function (Blueprint $table): void {
            $table->string('billing_cycle', 32)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_periods', function (Blueprint $table): void {
            $table->dropColumn('billing_cycle');
        });
    }
};
