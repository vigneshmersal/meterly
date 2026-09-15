# Implementation Prompt — Subscription Billing & Usage-Metering System

You are implementing the Laravel subscription billing and usage-metering system described in `doc/task.md`.

Build the feature completely, not as a prototype. Follow the existing project conventions, use the installed Laravel and package versions, preserve existing behavior, and make surgical changes. Do not add dependencies unless they are required and approved. Do not leave placeholder implementations, TODO-only methods, or undocumented assumptions.

## Global Engineering Requirements

1. Read `doc/task.md` completely before making changes.
2. Inspect the existing application structure, routes, models, migrations, queues, cache configuration, authentication, tests, and coding conventions before creating files.
3. Keep the system multi-tenant: every merchant-owned read and write must be scoped by `merchant_id`.
4. Use explicit validation, authorization, return types, transactions, indexes, and database constraints.
5. Use queued jobs for asynchronous aggregation and invoice generation.
6. Make all retryable operations idempotent.
7. Keep raw usage events as the source of truth and use `usage_daily` for reporting and dashboard reads.
8. Preserve historical pricing by storing pricing snapshots on subscription periods and invoice items.
9. Add or update meaningful Pest tests for every behavior and important failure mode.
10. Run formatting, targeted tests, and relevant static checks after each phase. Fix failures before continuing.
11. Do not weaken security, validation, authorization, or test assertions to make a test pass.
12. Update the README or other existing documentation where the implementation introduces a required setup step, command, environment variable, or assumption.

## Phase 0 — Reconnaissance and Plan

Before coding:

1. Inspect `composer.json`, `package.json`, `.env.example`, database configuration, queue configuration, cache configuration, and test configuration.
2. Inspect existing models, migrations, factories, seeders, routes, controllers, services, jobs, requests, policies, commands, and tests.
3. Confirm the installed Laravel, PHP, queue, cache, and database APIs before relying on them.
4. Identify reusable authentication and tenant-scoping patterns.
5. Create a short implementation plan in the working context, then execute it phase by phase.

Do not change unrelated code or introduce a second architectural pattern where the application already has an established one.

## Phase 1 — Database Schema and Domain Models

Implement the normalized schema required by `task.md`:

- `merchants`
- `customers`
- `plans`
- `subscriptions`
- `subscription_periods`
- `usage_events`
- `usage_daily`
- `invoices`
- `invoice_items`

For each table:

1. Use appropriate foreign keys, nullability, defaults, precision, timestamps, and delete behavior.
2. Add indexes for tenant scoping, billing-period lookup, usage aggregation, customer lookups, and invoice generation.
3. Add unique constraints for:
   - Usage idempotency/event keys within the required tenant scope.
   - One daily aggregate per merchant/customer/date.
   - One invoice per subscription period.
4. Store monetary values using a safe fixed-precision representation consistent with the application.
5. Store subscription-period pricing snapshots:
   - Base price
   - Included units
   - Overage rate
   - Billing-cycle data needed for historical calculations
6. Add model relationships, casts, guarded/fillable conventions, and useful query scopes.
7. Ensure all models enforce or support merchant ownership checks.
8. Create or update factories and seeders for realistic development and test data.

Run migrations and model-level tests before proceeding.

## Phase 2 — Plans, Customers, and Subscriptions

Implement the domain operations needed to:

1. Create merchant plans with:
   - Name
   - Base price
   - Billing cycle
   - Included usage units
   - Overage rate per unit
2. Create customers belonging to a merchant.
3. Subscribe a customer to a plan belonging to the same merchant.
4. Create a subscription period with a pricing snapshot.
5. Support customer-specific billing periods instead of assuming calendar-month billing.
6. Prevent cross-merchant plan, customer, subscription, and period references.
7. Represent active, ended, and changed subscription periods clearly.
8. Preserve historical periods when a plan is changed.

Implement authorization and validation at the appropriate request/service/policy layers. Add tests for valid creation, invalid ownership, invalid dates, and historical snapshot preservation.

## Phase 3 — Usage Ingestion API

Implement:

```http
POST /usage
```

The endpoint must:

1. Authenticate and authorize the caller.
2. Validate merchant, customer, subscription, event key, event time, and units.
3. Confirm that all referenced records belong to the same merchant.
4. Persist the raw event in `usage_events`.
5. Require an idempotency/event key.
6. Handle duplicate requests safely using the database unique constraint and a deterministic response.
7. Never double-count concurrent duplicate requests.
8. Apply rate limiting appropriate for a high-throughput ingestion endpoint.
9. Keep the synchronous request lightweight; dispatch aggregation work asynchronously.
10. Return the documented success and idempotent-retry response shapes.
11. Surface validation, authorization, and persistence errors clearly; do not silently ignore failures.

Add feature tests for:

- Successful ingestion.
- Validation failures.
- Cross-tenant access attempts.
- Duplicate event keys.
- Concurrent or repeated duplicate requests.
- Rate-limit behavior.
- Correct response payloads.

## Phase 4 — Daily Usage Aggregation

Implement `AggregateDailyUsageJob` and the `usage_daily` read model.

The aggregation must:

1. Read from `usage_events`, never treat `usage_daily` as the source of truth.
2. Process large volumes with chunking or another bounded-memory strategy.
3. Aggregate by merchant, customer, and calendar date.
4. Use deterministic upsert/rebuild logic.
5. Be safe when a job is retried or executed more than once.
6. Avoid double-counting events.
7. Support rebuilding a date range when needed.
8. Keep merchant data isolated.
9. Use appropriate indexes and query constraints.
10. Allow the dashboard to be eventually consistent while aggregation catches up.

Add tests for:

- Multiple events becoming one daily row.
- Multiple customers and merchants remaining isolated.
- Job retries not double-counting.
- Empty dates.
- Rebuilding aggregates.
- Large-batch/chunk behavior where practical.

## Phase 5 — Billing Calculations

Implement reusable services for billing calculations.

### Proration

1. Use the actual relevant billing-period day count consistently.
2. Prorate a base charge when a subscription or plan segment starts mid-cycle.
3. Cover cycle-start, mid-cycle, near-end, and different-month-length cases.
4. Document the inclusive/exclusive day-count convention.

### Overage

Implement:

```text
overage_units = max(0, usage_units - included_units)
overage_amount = overage_units * overage_rate
```

Verify behavior when usage is below, equal to, and above the allowance.

### Plan Changes

1. Support mid-cycle upgrades and downgrades.
2. Create separate subscription periods for each pricing segment.
3. Apply old pricing to usage before the change.
4. Apply new pricing to usage after the change.
5. Prorate applicable base charges for each segment.
6. Never calculate an historical invoice from the current plan row.

Add focused unit and feature tests for all calculation boundaries and plan-change cases.

## Phase 6 — Invoice Generation

Implement the invoice-generation service/job and scheduled command.

The implementation must:

1. Find subscriptions whose individual billing periods have ended.
2. Not depend only on the last day of a calendar month.
3. Read usage through the aggregate/read-model strategy described in `task.md`.
4. Calculate base, prorated, overage, and plan-segment charges correctly.
5. Persist one invoice and its invoice items inside a database transaction.
6. Use a unique subscription-period constraint to prevent duplicate invoices.
7. Make retries safe and idempotent.
8. Keep historical invoice amounts immutable.
9. Separate optional invoice email delivery from invoice creation.
10. Ensure email failure cannot roll back an already-created invoice.

Add tests for:

- Due and not-yet-due billing periods.
- Correct invoice totals and line items.
- Zero, exact-allowance, and over-allowance usage.
- Proration.
- Mid-cycle upgrades and downgrades.
- Duplicate invoice-job execution.
- Transaction rollback behavior.
- Historical pricing after a plan price change.

## Phase 7 — Dashboard API

Implement:

```http
GET /merchants/{id}/dashboard
```

All dashboard queries must be merchant-scoped and should use `usage_daily` rather than scanning raw events.

Return the following:

1. **Active plan**
   - Plan name
   - Billing cycle
   - Included usage units
   - Current billing-period start and end
   - Any current-cycle usage fields required by the existing dashboard design
2. **Informational system status**
   - A clear status such as `operational`, `processing`, or `attention`
   - A human-readable message
   - Whether usage aggregation appears up to date, if that information is available
   - Keep this informational; do not turn the dashboard into a full infrastructure health-check endpoint
3. Top five customers by usage for the current month.
4. Projected overage revenue for the current cycle.
5. Customers whose usage dropped by more than 50% month-over-month.
6. A 30-day daily usage trend with exactly 30 calendar date points, including zeroes for missing dates.
7. Current-cycle usage where required by the task, including usage versus allowance and percentage.

For projections:

1. Use the documented average-daily-usage forecast.
2. Apply allowances and overage rates correctly for plan-change segments.
3. Clearly document the forecasting assumption.

For churn risk:

1. Compare current and previous month usage.
2. Mark only drops greater than 50%.
3. Avoid division by zero when previous usage is zero.
4. Preserve deterministic ordering and response shapes.

Add tests for ordering, date ranges, missing dates, tenant isolation, active-plan data, informational system status, projections, churn thresholds, zero previous usage, and authorization.

## Phase 8 — Caching

Implement plan/pricing caching using the application’s configured cache store.

1. Cache merchant-scoped plan lookups with a stable key such as:

```text
merchant:{merchantId}:plan:{planId}
```

2. Set a sensible expiration.
3. Invalidate the cache only after a successful plan update/database commit.
4. Ensure cache invalidation cannot change historical subscription-period or invoice snapshots.
5. Add tests for cache hits, misses, expiration behavior where practical, and invalidation.

## Phase 9 — Queues, Scheduling, and Operations

1. Register queue jobs using the project’s existing queue conventions.
2. Add the scheduled command that finds due subscription periods and dispatches invoice jobs.
3. Configure retry, timeout, backoff, and failure behavior consistently with the application.
4. Ensure every queued job is safe to execute more than once.
5. Document required queue workers, scheduler commands, cache services, and environment variables.
6. Do not make dashboard reads depend on a synchronous full-table aggregation.

## Phase 10 — Security and Performance Review

Review the complete implementation for:

1. Merchant scoping on every query and relationship boundary.
2. Authorization on every endpoint and mutation.
3. Validation of all external input.
4. No mass-assignment or ownership bypasses.
5. No raw-event table scans in dashboard paths.
6. Correct indexes for high-volume usage ingestion and aggregation.
7. Bounded-memory batch processing.
8. Safe monetary arithmetic.
9. No duplicate usage or invoice creation under retries/concurrency.
10. No leakage of another merchant’s customers, plans, usage, invoices, or dashboard data.

## Phase 11 — Documentation and Submission Readiness

Update existing documentation with:

1. Setup and environment requirements.
2. Migration, seeding, queue-worker, scheduler, and cache instructions.
3. API examples for usage ingestion and dashboard responses.
4. Assumptions, especially:
   - Customer-specific billing periods.
   - Eventual consistency of `usage_daily`.
   - Projection formula.
   - Proration day-count convention.
   - Previous-month-zero churn behavior.
   - Informational meaning of dashboard system status.
5. Scalability strategy for 50L+ usage-event rows.
6. Trade-offs and future improvements.

## Required Final Verification

Before declaring the implementation complete:

1. Run the project formatter on modified PHP files.
2. Run the narrowest relevant Pest tests for each changed area.
3. Run the complete test suite if targeted tests pass and the project supports it.
4. Run migrations from a clean test database where supported.
5. Verify route registration, scheduled commands, queue jobs, and cache behavior.
6. Run `git diff --check`.
7. Review the final diff for unrelated changes, missing migrations, missing tests, or undocumented setup.
8. Confirm every item in the `doc/task.md` submission checklist is either implemented and tested or explicitly documented as an approved optional item.

## Definition of Done

The work is complete only when:

- The Laravel application boots successfully.
- All required schema, models, endpoints, jobs, commands, services, caching, and authorization are implemented.
- Usage ingestion is idempotent and rate-limited.
- Aggregation is scalable, retry-safe, and eventually consistent by design.
- Billing correctly handles subscription periods, proration, overage, plan changes, and invoice idempotency.
- The dashboard includes active plan details, informational system status, usage metrics, projections, churn risk, and a complete 30-day trend.
- Historical invoices remain unchanged after plan edits.
- Relevant tests pass.
- Documentation and the submission checklist are complete.