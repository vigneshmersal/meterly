# Task - Subscription Billing & Usage-Metering System

## 1. Problem Statement

Build a small backend for a multi-tenant SaaS system that:

- Allows merchants (tenants) to define subscription plans.
- Allows customers to subscribe to merchant plans.
- Records high-volume customer usage events.
- Aggregates usage efficiently.
- Generates invoices at the end of each billing cycle.
- Calculates proration and overage charges correctly.
- Supports mid-cycle plan upgrades/downgrades.
- Provides a merchant dashboard with usage and revenue insights.
- Uses queues, caching, idempotency, indexing and rate limiting appropriately.

The assignment specifically asks us to consider scalability for **50L+ (5 million+) usage-event rows** and to document schema, aggregation, caching and architectural decisions.

---

# 2. Functional Requirements

## 2.1 Merchant and Plans

Each merchant can define one or more plans.

A plan contains:

- Name
- Base price
- Billing cycle
- Included usage units
- Overage rate per unit

Example:

| Plan | Base Price | Billing Cycle | Included Units | Overage |
|---|---:|---|---:|---:|
| Basic | ₹1,000 | Monthly | 10,000 | ₹0.10/unit |
| Pro | ₹3,000 | Monthly | 50,000 | ₹0.08/unit |

---

## 2.2 Customers

Customers belong to a merchant and can subscribe to one of that merchant's plans.

A customer subscription has a billing period/cycle.

Important assumption:

> Billing cycles are based on each customer's subscription period rather than assuming every customer follows the calendar month.

For example:

- Customer A: Sep 1 → Sep 30
- Customer B: Sep 15 → Oct 14
- Customer C: Sep 20 → Oct 19

Therefore, invoice generation should find subscriptions whose individual billing periods have ended rather than relying on only the last day of a calendar month.

---

# 3. High-Level Architecture

```text
                         ┌─────────────────────┐
                         │       Client        │
                         └──────────┬──────────┘
                                    │
                         POST /usage│
                                    ▼
                    ┌───────────────────────────┐
                    │    UsageController        │
                    │ Validation + Auth         │
                    │ Rate Limiting             │
                    └─────────────┬─────────────┘
                                  │
                                  ▼
                    ┌───────────────────────────┐
                    │      UsageService         │
                    │ Idempotency + persistence │
                    └─────────────┬─────────────┘
                                  │
                                  ▼
                    ┌───────────────────────────┐
                    │      usage_events         │
                    │      Source of Truth       │
                    └─────────────┬─────────────┘
                                  │
                                  │ Queue
                                  ▼
                    ┌───────────────────────────┐
                    │ AggregateDailyUsageJob     │
                    │ Chunked processing         │
                    └─────────────┬─────────────┘
                                  │
                                  ▼
                    ┌───────────────────────────┐
                    │       usage_daily         │
                    │ Pre-aggregated read model  │
                    └─────────────┬─────────────┘
                                  │
                   ┌──────────────┴──────────────┐
                   ▼                             ▼
        ┌─────────────────────┐       ┌─────────────────────┐
        │     Dashboard       │       │    Billing Engine   │
        │ 30-day trend        │       │ Proration           │
        │ Top 5 usage         │       │ Overage             │
        │ Churn risk          │       │ Invoice generation  │
        └─────────────────────┘       └──────────┬──────────┘
                                                 │
                                                 ▼
                                      ┌─────────────────────┐
                                      │ invoices             │
                                      │ invoice_items        │
                                      └─────────────────────┘
```

---

# 4. Database Design

## 4.1 Tables

Recommended tables:

```text
merchants
customers
plans
subscriptions
subscription_periods
usage_events
usage_daily
invoices
invoice_items
```

---

## 4.2 merchants

Stores tenants.

```text
merchants
---------
id
name
created_at
updated_at
```

---

## 4.3 customers

Customers belong to a merchant.

```text
customers
---------
id
merchant_id
name
email
created_at
updated_at
```

Indexes:

```text
INDEX (merchant_id)
UNIQUE (merchant_id, email)
```

---

# 5. plans

Stores the current definition of a merchant's plans.

```text
plans
-----
id
merchant_id
name
base_price
billing_cycle
included_units
overage_rate
created_at
updated_at
```

Recommended indexes:

```text
INDEX (merchant_id)
```

### Important billing decision

The invoice should **not** depend on the current plan row.

Historical billing needs the pricing that was applicable at the time usage occurred.

Therefore, pricing information is also captured in `subscription_periods`.

---

# 6. subscriptions

Represents a customer's subscription.

```text
subscriptions
-------------
id
customer_id
plan_id
starts_at
ends_at
status
created_at
updated_at
```

Example:

```text
id:          1001
customer:    501
plan:        Pro
starts_at:   2026-09-01
ends_at:     2026-09-30
status:      active
```

Indexes:

```text
INDEX (customer_id, status)
INDEX (plan_id)
INDEX (ends_at, status)
```

The `(ends_at, status)` index helps find subscriptions whose cycles are due for invoicing.

---

# 7. subscription_periods

This is an important design decision for handling plan changes.

It preserves the pricing and allowance applicable to each segment of a subscription.

```text
subscription_periods
--------------------
id
subscription_id
plan_id
starts_at
ends_at
base_price
included_units
overage_rate
created_at
updated_at
```

Example:

Customer starts on Basic:

```text
Sep 1 → Sep 15
Basic
₹1,000
10,000 included
₹0.10/unit
```

Then upgrades to Pro:

```text
Sep 16 → Sep 30
Pro
₹3,000
50,000 included
₹0.08/unit
```

The two periods allow billing to calculate each segment independently.

This prevents a later plan change from incorrectly applying the new price to historical usage.

---

# 8. usage_events

Stores raw usage events.

```text
usage_events
------------
id
merchant_id
customer_id
subscription_period_id
event_key
usage_date
units
created_at
```

Example:

```text
id:                    10001
merchant_id:           1
customer_id:           501
subscription_period_id: 9001
event_key:             evt_abc123
usage_date:            2026-09-13
units:                 250
```

## Idempotency

Every usage request should contain an idempotency/event key.

Example:

```text
event_key = evt_abc123
```

Database constraint:

```text
UNIQUE (merchant_id, event_key)
```

If the same request is retried, the second insert fails the unique constraint and must not double-count usage.

This makes idempotency database-enforced rather than relying only on application code.

---

# 9. usage_daily

A deliberately denormalized aggregate/read model.

```text
usage_daily
-----------
id
merchant_id
customer_id
usage_date
units
created_at
updated_at
```

Unique constraint:

```text
UNIQUE (merchant_id, customer_id, usage_date)
```

Indexes:

```text
INDEX (merchant_id, usage_date)
INDEX (customer_id, usage_date)
```

Example:

```text
merchant  customer  date         units
1         501       2026-09-10   3200
1         501       2026-09-11   4100
1         501       2026-09-12   3800
```

The raw `usage_events` table remains the source of truth.

`usage_daily` is a performance-oriented read model.

---

# 10. invoices

`invoices` is the invoice header/summary.

```text
invoices
--------
id
merchant_id
customer_id
subscription_id
period_start
period_end
subtotal
overage_amount
total
status
issued_at
created_at
updated_at
```

Recommended unique constraint:

```text
UNIQUE (
    subscription_id,
    period_start,
    period_end
)
```

This prevents duplicate invoices if the scheduler or invoice job is executed more than once.

---

# 11. invoice_items

Stores individual invoice charges.

```text
invoice_items
-------------
id
invoice_id
description
quantity
unit_price
amount
type
created_at
updated_at
```

Example:

```text
Invoice INV-1001

Item 1:
Pro Plan
Quantity: 1
Unit price: ₹3,000
Amount: ₹3,000

Item 2:
Usage Overage
Quantity: 15,000
Unit price: ₹0.10
Amount: ₹1,500
```

Total:

```text
₹4,500
```

## Why separate invoices and invoice_items?

`invoices` represents the overall bill.

`invoice_items` represents the breakdown.

This also becomes important for plan changes:

```text
Basic base charge
Basic overage
Pro base charge
Pro overage
```

Each can be represented as a separate line item.

---

# 12. Scaling to 50L+ Usage Events

The requirement explicitly asks us to explain how the design handles 50L+ usage-event rows.

## Strategy

### 1. Keep raw events append-oriented

`usage_events` is primarily used for ingestion and audit/history.

Avoid repeatedly running expensive dashboard aggregation directly against it.

### 2. Index based on access patterns

Important indexes:

```text
UNIQUE (merchant_id, event_key)

INDEX (customer_id, usage_date)

INDEX (merchant_id, usage_date)

INDEX (subscription_period_id, usage_date)
```

### 3. Aggregate asynchronously

Use a queue to aggregate raw events into `usage_daily`.

```text
usage_events
     ↓
Queue
     ↓
AggregateDailyUsageJob
     ↓
usage_daily
```

### 4. Chunk processing

Never load millions of records with:

```php
UsageEvent::all();
```

Use:

```php
UsageEvent::query()
    ->where(...)
    ->chunkById(1000, function ($events) {
        // aggregate
    });
```

The exact chunk size can be tuned through benchmarking.

### 5. Partitioning

At significantly larger scale, partition `usage_events` by `usage_date`.

For example:

```text
usage_events_2026_08
usage_events_2026_09
usage_events_2026_10
```

This can reduce the amount of data scanned for date-based operations.

Partitioning is not required for the initial take-home implementation, but it is an option I would consider as volume grows.

---

# 13. POST /usage

Endpoint:

```http
POST /usage
```

Example request:

```json
{
    "customer_id": 501,
    "event_key": "evt_abc123",
    "usage_date": "2026-09-13",
    "units": 250
}
```

## Request flow

```text
Request
  ↓
Authentication / tenant identification
  ↓
Rate limiting
  ↓
Validation
  ↓
Resolve active subscription period
  ↓
Insert usage event
  ↓
Return success
```

The ingestion endpoint should remain lightweight.

Do not calculate invoices synchronously inside `/usage`.

---

# 14. Usage Idempotency

A retry should not create duplicate usage.

Example:

First request:

```text
event_key = evt_123
units = 100
```

Database:

```text
evt_123 → 100
```

Client retries the same request:

```text
event_key = evt_123
units = 100
```

The unique constraint detects the duplicate.

Result:

```text
Usage remains 100
```

It must not become:

```text
200
```

## Recommended approach

Use:

```text
UNIQUE (merchant_id, event_key)
```

and handle duplicate-key exceptions safely.

This is more reliable under concurrent requests than:

```php
if (!exists()) {
    create();
}
```

because two concurrent requests could both pass the `exists()` check.

---

# 15. Rate Limiting

Apply Laravel rate limiting to the usage endpoint.

Example conceptual policy:

```text
1000 requests / minute / merchant
```

The exact limit can be adjusted according to expected traffic.

Use a merchant/customer-aware key where appropriate.

The important requirement is to protect the high-throughput ingestion endpoint from accidental or abusive traffic.

---

# 16. Daily Usage Aggregation

The raw events are asynchronously aggregated into `usage_daily`.

Example raw events:

```text
Customer 501
Sep 13:

100
250
500
150
```

Daily aggregate:

```text
Sep 13 → 1,000 units
```

## Queue flow

```text
AggregateDailyUsageJob
        ↓
Read events in chunks
        ↓
Group by:
    merchant_id
    customer_id
    usage_date
        ↓
UPSERT usage_daily
```

Use an atomic upsert:

```php
DB::table('usage_daily')->upsert(
    $rows,
    ['merchant_id', 'customer_id', 'usage_date'],
    ['units', 'updated_at']
);
```

The exact aggregation strategy should ensure an event is not counted twice if a job is retried.

---

# 17. Billing Cycle and Invoice Generation

Invoice generation should be automated.

However, it should **not** simply run once per calendar month.

Customers can have different billing cycle dates.

## Recommended architecture

```text
Laravel Scheduler
        ↓
billing:generate-invoices
        ↓
Find subscriptions whose cycle ended
        ↓
Dispatch GenerateInvoiceJob
        ↓
Calculate billing
        ↓
Create invoice + invoice_items
```

Example scheduler:

```php
Schedule::command('billing:generate-invoices')
    ->dailyAt('00:05');
```

The command identifies due subscriptions.

The actual billing calculation belongs in the billing service/job.

---

# 18. Invoice Generation Idempotency

The invoice job may be retried.

Therefore:

```text
UNIQUE (
    subscription_id,
    period_start,
    period_end
)
```

is required.

Example:

```text
Subscription 1001
Sep 1 → Sep 30
```

Only one invoice can exist for this period.

If the job runs twice:

```text
Run 1 → Invoice created
Run 2 → Existing invoice detected
```

No duplicate bill.

---

# 19. Base Price and Overage Calculation

Example:

```text
Plan:
Base price = ₹3,000
Included units = 10,000
Overage = ₹0.10/unit

Actual usage = 13,000
```

Calculation:

```text
Overage units
= 13,000 - 10,000
= 3,000
```

```text
Overage amount
= 3,000 × ₹0.10
= ₹300
```

```text
Invoice total
= ₹3,000 + ₹300
= ₹3,300
```

If usage is below the allowance:

```text
Usage = 8,000
Included = 10,000
Overage = 0
```

---

# 20. Proration

If a subscription starts mid-cycle, the base plan charge must be prorated.

Example:

```text
Monthly plan = ₹3,000
Billing cycle = 30 days
Subscription starts on day 16
Remaining days = 15
```

Daily price:

```text
₹3,000 / 30 = ₹100/day
```

Prorated base price:

```text
₹100 × 15 = ₹1,500
```

The exact day-count convention should be documented and consistently applied.

## Recommended assumption

Use the actual number of days in the billing period:

```text
prorated_price =
    base_price × active_days / total_cycle_days
```

This makes the calculation deterministic and works across different month lengths.

---

# 21. Mid-Cycle Upgrade / Downgrade

This is one of the most important requirements.

Example:

```text
Sep 1 → Sep 15
Basic
```

Then:

```text
Sep 16 → Sep 30
Pro
```

Usage before the change must use the Basic pricing.

Usage after the change must use Pro pricing.

Therefore, create separate `subscription_periods`.

```text
Subscription
    │
    ├── Period 1
    │   Sep 1 → Sep 15
    │   Basic
    │
    └── Period 2
        Sep 16 → Sep 30
        Pro
```

---

# 22. Plan Change Billing Example

Assume:

### Basic

```text
Base = ₹1,000
Included = 10,000
Overage = ₹0.10
```

### Pro

```text
Base = ₹3,000
Included = 50,000
Overage = ₹0.08
```

Customer changes from Basic to Pro on Sep 16.

Billing:

```text
Basic segment
Sep 1 → Sep 15
Prorated base charge
+ Basic overage

Pro segment
Sep 16 → Sep 30
Prorated base charge
+ Pro overage
```

Invoice items can therefore look like:

```text
Basic Plan - Prorated
Basic Usage Overage
Pro Plan - Prorated
Pro Usage Overage
```

This preserves exactly which pricing was used for each segment.

---

# 23. Billing Service Design

Recommended separation:

```text
BillingService
    ↓
BillingPeriodResolver
    ↓
ProrationService
    ↓
UsageCalculator
    ↓
InvoiceBuilder
```

Example responsibilities:

### BillingPeriodResolver

Determines the subscription period being invoiced.

### ProrationService

Calculates:

```text
base_price × active_days / total_period_days
```

### UsageCalculator

Calculates:

```text
actual usage
included usage
overage units
overage amount
```

### InvoiceBuilder

Creates:

```text
invoice
invoice_items
```

inside a database transaction.

---

# 24. Dashboard

Endpoint:

```http
GET /merchants/{id}/dashboard
```

Required information:

1. The active plan for the dashboard's subscription context, including the plan name, billing cycle, included usage and current billing period.
2. System status as informational dashboard metadata, such as whether usage aggregation is up to date. This is not intended to be a full operational health-check endpoint.
3. Top 5 customers by usage this month.
4. Projected overage revenue for the current cycle.
5. Customers whose usage dropped more than 50% month-over-month.
6. A daily usage trend can also be shown for the last 30 days as a useful dashboard visualization.

---

# 25. Current Cycle Usage

Current cycle usage means:

> Total usage accumulated from the beginning of a customer's current billing cycle through today.

Example:

```text
Plan: Pro
Cycle: Sep 1 → Sep 30
Included: 50,000
Today: Sep 15

Usage so far: 32,500
```

Dashboard:

```text
Current Cycle Usage

32,500 / 50,000
65%

Sep 1 → Sep 30
```

Use `usage_daily` rather than raw `usage_events` for this dashboard query.

---

# 26. Daily Usage Trend — Last 30 Days

The dashboard can show a line/bar chart containing one point per calendar day.

Example:

```text
Date         Usage
--------------------
Aug 15       1,250
Aug 16       1,840
Aug 17       2,100
...
Sep 13       4,250
```

Query from `usage_daily`:

```php
$dailyUsage = DailyUsage::query()
    ->where('merchant_id', $merchantId)
    ->whereBetween('usage_date', [
        now()->subDays(29)->toDateString(),
        now()->toDateString(),
    ])
    ->selectRaw('usage_date, SUM(units) as total_units')
    ->groupBy('usage_date')
    ->orderBy('usage_date')
    ->get();
```

## Missing dates

If a day has no usage, return zero rather than omitting the day.

Example:

```text
Sep 4 → 2500
Sep 5 → 0
Sep 6 → 3100
```

This gives the frontend exactly 30 calendar points and produces a more accurate trend chart.

---

# 27. Top 5 Customers by Usage This Month

Use `usage_daily`.

Conceptual query:

```php
DailyUsage::query()
    ->where('merchant_id', $merchantId)
    ->whereBetween('usage_date', [
        now()->startOfMonth(),
        now()->endOfMonth(),
    ])
    ->selectRaw('customer_id, SUM(units) AS total_usage')
    ->groupBy('customer_id')
    ->orderByDesc('total_usage')
    ->limit(5)
    ->get();
```

This avoids scanning the entire raw event table.

---

# 28. Projected Overage Revenue

Projected overage revenue is a **forecast**, not the final invoice.

It answers:

> Based on current usage in the current billing cycle, how much overage revenue do we expect by cycle end?

Example:

```text
Cycle = 30 days
Elapsed = 10 days
Usage so far = 4,000
Included = 10,000
Overage rate = ₹0.10
```

Average daily usage:

```text
4,000 / 10 = 400/day
```

Projected usage:

```text
400 × 30 = 12,000
```

Projected overage:

```text
12,000 - 10,000
= 2,000 units
```

Projected overage revenue:

```text
2,000 × ₹0.10
= ₹200
```

## Formula

```text
average_daily_usage
    = usage_so_far / elapsed_days

projected_usage
    = average_daily_usage × total_cycle_days

projected_overage_units
    = max(0, projected_usage - included_units)

projected_overage_revenue
    = projected_overage_units × overage_rate
```

### Assumption

The forecast assumes the customer's average daily usage continues at approximately the same rate for the remainder of the cycle.

This assumption should be explicitly documented because the brief does not prescribe a forecasting formula.

---

# 29. Projected Revenue with Plan Changes

Plan changes require segment-aware calculations.

Do not blindly use the customer's current plan for the entire billing cycle.

Instead:

```text
Current Cycle
    │
    ├── Basic segment
    │   Usage + Basic rate
    │
    └── Pro segment
        Usage + Pro rate
```

The projection should apply the applicable allowance and overage rate to each relevant segment.

For a simple take-home implementation, document the forecasting convention clearly.

---

# 30. Churn Risk

Requirement:

> Return customers whose usage dropped by more than 50% month-over-month.

Conceptual calculation:

```text
previous_month_usage = X
current_month_usage = Y

drop_percentage =
    ((X - Y) / X) × 100
```

If:

```text
drop_percentage > 50
```

then mark the customer as:

```text
churn_risk = true
```

## Edge case: previous month = 0

Do not divide by zero.

A reasonable rule is:

```text
previous usage = 0
current usage > 0
→ no >50% drop
```

If both are zero:

```text
previous = 0
current = 0
→ no churn signal
```

This rule should be documented.

---

# 31. Caching

Plan/pricing lookups are specifically required to be cached.

Recommended:

```text
Redis
```

Cache key:

```text
merchant:{merchantId}:plan:{planId}
```

Cached values:

```text
id
name
base_price
billing_cycle
included_units
overage_rate
```

Example:

```php
Cache::remember(
    "merchant:{$merchantId}:plan:{$planId}",
    now()->addMinutes(30),
    fn () => Plan::findOrFail($planId)
);
```

---

# 32. Cache Invalidation

When a plan is updated:

```text
Update Plan
    ↓
DB commit
    ↓
Forget cache
```

Example:

```php
Cache::forget("merchant:{$merchantId}:plan:{$planId}");
```

## Important historical billing rule

Changing a plan's current pricing must not alter existing invoice calculations.

Historical subscription periods already contain the applicable:

```text
base_price
included_units
overage_rate
```

Therefore:

```text
Current Plan
     ↓
Future pricing

Subscription Period Snapshot
     ↓
Historical/current billing segment
```

---

# 33. Laravel Project Structure

Recommended structure:

```text
app/
├── Console/
│   └── Commands/
│       └── GenerateDueInvoices.php
│
├── DTOs/
│   ├── UsageData.php
│   └── BillingSegmentData.php
│
├── Http/
│   ├── Controllers/
│   │   ├── UsageController.php
│   │   └── DashboardController.php
│   │
│   └── Requests/
│       └── StoreUsageRequest.php
│
├── Jobs/
│   ├── AggregateDailyUsageJob.php
│   └── GenerateInvoiceJob.php
│
├── Models/
│   ├── Merchant.php
│   ├── Customer.php
│   ├── Plan.php
│   ├── Subscription.php
│   ├── SubscriptionPeriod.php
│   ├── UsageEvent.php
│   ├── DailyUsage.php
│   ├── Invoice.php
│   └── InvoiceItem.php
│
└── Services/
    ├── UsageService.php
    ├── UsageAggregationService.php
    ├── BillingService.php
    ├── ProrationService.php
    └── DashboardService.php
```

The goal is to avoid putting business logic inside controllers.

---

# 34. Recommended API Response

## POST /usage

Successful creation:

```json
{
    "message": "Usage recorded successfully",
    "event_id": "evt_abc123"
}
```

Idempotent retry:

```json
{
    "message": "Usage event already recorded",
    "event_id": "evt_abc123"
}
```

---

# 35. Dashboard API Response

Example:

```json
{
    "active_plan": {
        "name": "Pro",
        "billing_cycle": "monthly",
        "included_units": 50000,
        "current_period_start": "2026-09-01",
        "current_period_end": "2026-09-30"
    },
    "system_status": {
        "status": "operational",
        "message": "Usage data is up to date"
    },
    "top_customers": [
        {
            "customer_id": 501,
            "name": "Customer A",
            "usage": 125000
        }
    ],
    "projected_overage_revenue": 4250.50,
    "churn_risk_customers": [
        {
            "customer_id": 502,
            "name": "Customer B",
            "previous_month_usage": 100000,
            "current_month_usage": 45000,
            "drop_percentage": 55
        }
    ],
    "usage_trend": [
        {
            "date": "2026-08-15",
            "units": 1250
        },
        {
            "date": "2026-08-16",
            "units": 1840
        }
    ]
}
```

---

# 36. Invoice Example

Customer:

```text
ABC Company
```

Plan:

```text
Pro
Base price: ₹3,000
Included: 50,000
Overage: ₹0.08
```

Actual usage:

```text
65,000
```

Calculation:

```text
Overage = 65,000 - 50,000
        = 15,000
```

```text
Overage amount
= 15,000 × ₹0.08
= ₹1,200
```

Invoice:

```text
Pro Plan              ₹3,000
Usage Overage         ₹1,200
-----------------------------
Total                 ₹4,200
```

---

# 37. Invoice vs Invoice Email

The requirement explicitly asks for **invoice generation**.

Invoice generation means:

```text
Calculate
    ↓
Persist invoice
    ↓
Persist invoice items
```

Sending an email is a separate concern.

If implemented:

```text
GenerateInvoiceJob
        ↓
InvoiceCreated event
        ↓
SendInvoiceEmailJob
```

Email delivery should not cause the invoice transaction to fail.

---

# 38. Transactions

Invoice creation should use a database transaction.

Conceptually:

```php
DB::transaction(function () {
    // Create invoice

    // Create invoice items

    // Update billing state if required
});
```

This ensures that an invoice is not persisted without its line items.

---

# 39. Tests

The brief explicitly requires tests for aggregation and billing, including proration and overage edge cases.

Recommended test cases:

## Usage aggregation

- Multiple events aggregate into one daily row.
- Multiple customers remain isolated.
- Multiple merchants remain isolated.
- Job retry does not double-count events.
- Missing usage day results in zero for dashboard trend.

## Overage

```text
usage < allowance
→ overage = 0
```

```text
usage = allowance
→ overage = 0
```

```text
usage > allowance
→ correct overage
```

## Proration

- Subscription starts on cycle start.
- Subscription starts mid-cycle.
- Subscription starts near cycle end.
- Different month lengths.

## Plan changes

- Upgrade mid-cycle.
- Downgrade mid-cycle.
- Usage before change uses old rate.
- Usage after change uses new rate.
- Both base charges are prorated.

## Idempotency

- Duplicate event key does not double-count.
- Concurrent duplicate requests are safely handled.
- Duplicate invoice generation does not create a second invoice.

## Dashboard

- Top 5 customers are correctly ordered.
- Active plan details are returned for the current subscription context.
- Informational system status is returned without coupling it to billing calculations.
- Only current month is included.
- >50% usage drop is detected.
- Previous month zero does not cause division-by-zero.
- Last 30 days always contains 30 date points.

---

# 40. Important Edge Cases

## Usage is exactly the allowance

```text
usage = included_units
overage = 0
```

## Usage below allowance

```text
usage < included_units
overage = 0
```

## Usage exceeds allowance

```text
overage = usage - included_units
```

## Zero usage

Customer should still receive the applicable prorated/base charge when billing rules require it.

## Mid-cycle upgrade

Old usage stays on old pricing.

## Mid-cycle downgrade

Old usage stays on old pricing.

## Duplicate usage event

Must not be double-counted.

## Duplicate invoice job

Must not create duplicate invoice.

## Plan price changes

Historical billing must remain unchanged.

---

# 41. Assumptions

The assignment intentionally leaves some details open and asks the candidate to make reasonable decisions and document them.

The implementation uses these assumptions:

1. Billing cycles are customer/subscription-specific, not necessarily calendar-month based.
2. Invoice generation happens at the end of each subscription billing period.
3. A daily scheduled command checks for subscriptions whose billing periods have ended.
4. Raw usage events are the source of truth.
5. `usage_daily` is a denormalized read model used for dashboard and billing queries.
6. Daily aggregation is asynchronous and therefore dashboard data may be eventually consistent.
7. The projected overage calculation uses average daily usage so far as the forecast for the remainder of the cycle.
8. Plan changes create separate subscription periods.
9. Each subscription period stores a pricing snapshot.
10. A unique event key provides usage idempotency.
11. A unique subscription/period combination provides invoice idempotency.
12. Historical invoice amounts are immutable.
13. The exact proration day-count convention is consistently based on actual days in the relevant billing period.
14. Email delivery is separate from invoice generation and is not required unless implemented as an optional feature.

---

# 42. Eventual Consistency

Because usage aggregation happens asynchronously:

```text
POST /usage
    ↓
usage_events
    ↓
Queue
    ↓
usage_daily
```

there can be a short period where:

```text
Raw events = latest
usage_daily = slightly behind
```

For the take-home, the dashboard uses `usage_daily` and accepts this small amount of eventual consistency.

This is preferable to making every dashboard request scan the high-volume raw events table.

---

# 43. Why Not Calculate Everything Directly From usage_events?

A naive dashboard could do:

```sql
SELECT SUM(units)
FROM usage_events
WHERE customer_id = ?
AND usage_date BETWEEN ? AND ?;
```

This may work for small datasets.

At 50L+ rows, repeatedly doing large aggregations can become expensive.

Instead:

```text
Raw Events
    ↓
Async aggregation
    ↓
Daily summary
    ↓
Fast dashboard queries
```

This provides a scalable read path while retaining raw data for auditing/reprocessing.

---

# 44. Queue Strategy

Use separate jobs for separate responsibilities:

```text
AggregateDailyUsageJob
GenerateInvoiceJob
```

Potential future jobs:

```text
SendInvoiceEmailJob
RebuildDailyUsageJob
```

Jobs should be retryable and idempotent.

Avoid a single large job that performs:

```text
all events
+ all customers
+ all invoices
```

in one execution.

---

# 45. Failure and Retry Strategy

A queue job can fail and be retried.

Therefore:

### Usage aggregation

Use deterministic aggregation/upsert logic.

### Invoice generation

Use the invoice unique constraint.

### Email

Use its own retry mechanism and do not roll back an already-created invoice.

The overall design should assume that **any queued job may execute more than once**.

---

# 46. Security / Multi-Tenancy

Every merchant-owned query should be scoped by `merchant_id`.

Example:

```php
DailyUsage::query()
    ->where('merchant_id', $merchantId)
```

Do not allow a merchant to access another merchant's:

- customers
- plans
- subscriptions
- usage
- invoices
- dashboard data

Tenant scoping should be applied consistently at the service/query layer.

---

# 47. Performance Summary

| Area | Decision |
|---|---|
| Raw usage | Append-oriented `usage_events` |
| Daily reporting | `usage_daily` |
| Large processing | Queue + chunking |
| Large raw table | Consider date partitioning |
| Duplicate usage | Unique idempotency key |
| Duplicate invoice | Unique subscription period |
| Dashboard | Read from aggregates |
| Plan lookup | Cache |
| Cache invalidation | Forget on plan update |
| Rate limiting | Usage endpoint |
| Billing | Dedicated service/job |
| Plan changes | Subscription periods |
| Historical pricing | Pricing snapshot |
| Invoice creation | DB transaction |

---

# 48. Suggested Implementation Order

## Phase 1 — Foundation

- Laravel project
- Database configuration
- Models
- Migrations
- Factories/seeders

## Phase 2 — Usage

- POST `/usage`
- Validation
- Idempotency
- Rate limiting
- Usage event persistence

## Phase 3 — Aggregation

- `AggregateDailyUsageJob`
- `usage_daily`
- Chunking
- Upsert
- Aggregation tests

## Phase 4 — Billing

- Subscription periods
- Proration service
- Overage calculation
- Invoice generation
- Invoice items
- Invoice idempotency
- Billing tests

## Phase 5 — Dashboard

- Active plan
- Informational system status
- Top 5 customers
- Current cycle usage
- Projected overage revenue
- Churn risk
- 30-day usage trend

## Phase 6 — Cache

- Plan cache
- Cache invalidation

## Phase 7 — Polish

- README
- Architecture diagram
- Seed data
- API examples
- Tests
- Prompt screenshots
- Screen recording

---

# 49. Trade-offs

## Why use usage_daily?

**Benefit:**

- Fast dashboard queries.
- Smaller data scanned.
- Good fit for high-volume events.

**Trade-off:**

- Data is eventually consistent.
- Requires aggregation infrastructure.

---

## Why keep usage_events?

**Benefit:**

- Source of truth.
- Auditability.
- Ability to rebuild aggregates.

**Trade-off:**

- Storage grows continuously.
- Requires indexing/partitioning strategy at larger scale.

---

## Why use subscription_periods?

**Benefit:**

- Correct mid-cycle billing.
- Historical pricing is preserved.
- Simplifies old/new plan calculations.

**Trade-off:**

- Additional table and billing logic.

---

## Why use Redis cache?

**Benefit:**

- Reduces repeated plan/pricing database lookups.

**Trade-off:**

- Cache invalidation needs to be handled correctly.

---

# 50. What I Would Do With More Time

With more time I would consider:

- PostgreSQL/date partitioning for very large usage-event volumes.
- Dedicated read models/materialized summaries for more dashboard metrics.
- More sophisticated forecasting for projected revenue.
- Outbox pattern for reliable domain events.
- Stronger observability with metrics/tracing.
- Queue monitoring and alerting.
- Invoice PDF generation.
- Invoice email delivery.
- Payment gateway integration.
- More comprehensive authorization policies.
- Load testing the usage endpoint.
- Database query profiling and index benchmarking.
- Reconciliation jobs between raw events and daily aggregates.
- Automated archival/retention for old raw events.

---

# 51. Final Architecture

```text
                    ┌──────────────────────┐
                    │       Merchant       │
                    └──────────┬───────────┘
                               │
                    ┌──────────▼───────────┐
                    │        Plans         │
                    └──────────┬───────────┘
                               │
                    ┌──────────▼───────────┐
                    │    Subscription      │
                    └──────────┬───────────┘
                               │
                    ┌──────────▼───────────┐
                    │ Subscription Periods │
                    └──────────┬───────────┘
                               │
                    ┌──────────▼───────────┐
                    │     Usage Events     │
                    │    Source of Truth   │
                    └──────────┬───────────┘
                               │
                         Queue / Jobs
                               │
                    ┌──────────▼───────────┐
                    │     Usage Daily      │
                    │    Read / Aggregate  │
                    └───────┬───────┬──────┘
                            │       │
               ┌────────────┘       └────────────┐
               ▼                                 ▼
       ┌────────────────┐                ┌────────────────┐
       │   Dashboard    │                │    Billing     │
       │                │                │                │
       │ Active Plan    │                │ Proration      │
       │ System Status  │                │ Overage        │
       │ Top 5          │                │ Plan Changes   │
       │ Trend          │                │                │
       │ Churn Risk     │                │                │
       │ Projection     │                │                │
       └────────────────┘                └───────┬────────┘
                                                │
                                      ┌─────────▼─────────┐
                                      │     Invoices      │
                                      └─────────┬─────────┘
                                                │
                                      ┌─────────▼─────────┐
                                      │   Invoice Items   │
                                      └───────────────────┘
```

---

# 52. Assignment Submission Checklist

- [ ] Laravel application complete
- [ ] Normalized database schema
- [ ] Appropriate indexes
- [ ] 50L+ scalability strategy documented
- [ ] `/usage` endpoint
- [ ] Idempotency implemented
- [ ] Rate limiting implemented
- [ ] Daily aggregation job
- [ ] Chunked processing
- [ ] Invoice generation job
- [ ] Proration implemented
- [ ] Overage implemented
- [ ] Mid-cycle upgrade/downgrade implemented
- [ ] Plan/pricing cache implemented
- [ ] Cache invalidation documented
- [ ] Dashboard endpoint
- [ ] Active plan shown on dashboard
- [ ] Informational system status shown on dashboard
- [ ] Top 5 customers
- [ ] Projected overage revenue
- [ ] Churn-risk calculation
- [ ] 30-day usage trend
- [ ] Invoice + invoice items
- [ ] Aggregation tests
- [ ] Billing tests
- [ ] Edge-case tests
- [ ] README completed
- [ ] Assumptions documented
- [ ] Trade-offs documented
