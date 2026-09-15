# Queue and Scheduler Operations

The application uses queued work for daily usage aggregation and invoice generation.

## Queue worker

The default queue connection is the database driver. Run a worker in each application environment that processes the default queue:

```bash
php artisan queue:work --tries=3 --timeout=120
```

The queue reservation window defaults to 180 seconds, which is longer than the 120-second job timeout. If the worker timeout changes, keep `DB_QUEUE_RETRY_AFTER` higher than that timeout.

Both `AggregateDailyUsageJob` and `GenerateInvoiceJob` use three attempts with progressive delays of 10, 60, and 180 seconds. They are unique and idempotent, so a retry cannot double-count daily usage or create a duplicate invoice.

Failed jobs are recorded through Laravel's configured failed-job provider and reported after all attempts are exhausted.

## Usage aggregation

Usage ingestion writes append-oriented records to `usage_events`. Dispatch an
aggregation job for a merchant and date range after ingestion:

```php
AggregateDailyUsageJob::dispatch('2026-09-01', '2026-09-15', $merchantId);
```

The job groups raw events in the database, processes grouped rows in bounded
chunks, and transactionally rebuilds `usage_daily`. Re-running the same job is
safe and does not double-count events.

## Scheduler

Run the scheduler continuously in production:

```bash
php artisan schedule:work
```

The scheduler runs `billing:generate-invoices` daily at 00:05. The command finds completed subscription periods, dispatches unique invoice jobs, and uses a 30-minute overlap lock. `onOneServer()` prevents duplicate command execution when multiple scheduler processes share the configured cache store.

For one-off inspection or execution:

```bash
php artisan schedule:list
php artisan billing:generate-invoices
```

## Cache and environment

Queue uniqueness and scheduler locks require a shared cache store when multiple workers or application servers are used. Configure `CACHE_STORE` and `CACHE_PREFIX` consistently across those processes. Redis is recommended for multi-server deployments; the local default uses the database cache store.

Relevant environment variables:

```dotenv
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=180
CACHE_STORE=database
CACHE_PREFIX=meterly
```

For production, use a shared Redis cache store when multiple workers or
application servers are running. The same shared store must be available for
unique job locks, scheduler locks, and plan-pricing cache invalidation.
