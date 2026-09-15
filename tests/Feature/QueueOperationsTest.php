<?php

use App\Jobs\AggregateDailyUsageJob;
use App\Jobs\GenerateInvoiceJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

it('configures queue reservations longer than job timeouts', function () {
    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan(120)
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan(120);
});

it('configures retry-safe backoff and bounded attempts for usage aggregation', function () {
    $job = new AggregateDailyUsageJob('2026-09-15', '2026-09-15', 1);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(120)
        ->and($job->backoff)->toBe([10, 60, 180])
        ->and($job->uniqueId())->toBe('1:2026-09-15:2026-09-15');
});

it('configures retry-safe backoff and uniqueness for invoice generation', function () {
    $job = new GenerateInvoiceJob(42);

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(120)
        ->and($job->backoff)->toBe([10, 60, 180])
        ->and($job->uniqueId())->toBe('42');
});
