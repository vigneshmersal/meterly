# AI-assisted development prompt log

This project used GitHub Copilot in VS Code for implementation, review,
testing, and documentation support.

## Primary implementation prompt

> Based on the requirements defined in `doc/task.md`, check and implement the
> application as specified in the project implementation prompt. Review the
> complete implementation against the requirements, including multi-tenant
> isolation, usage ingestion, idempotency, asynchronous aggregation, billing,
> proration, overage, plan changes, invoice generation, dashboard metrics,
> caching, queues, scheduler behavior, indexing, tests, and documentation.

## Senior engineer review prompt

> Act as a Senior Laravel Engineer and interviewer. Review the complete
> implementation against `doc/task.md`. Find only concrete CRITICAL, HIGH, and
> MEDIUM issues, focusing on scalability, database indexing, race conditions,
> idempotency, billing correctness, tenant isolation, queue retries,
> dashboard query efficiency, cache correctness, authorization, and test
> coverage. Fix all CRITICAL and HIGH findings and straightforward in-scope
> MEDIUM findings, remove genuinely duplicated logic, format modified PHP
> files, and run targeted tests, the complete suite, static analysis,
> formatter checks, route/scheduler verification, and `git diff --check`.

## Feature and verification prompts

- Add typed DTO support for usage ingestion and billing segments.
- Use Redis-preferred cache configuration with a safe local fallback.
- Add idempotent demo seed data covering active, completed, staggered,
  overage, zero-usage, exact-allowance, churn-risk, and mid-cycle plan-change
  scenarios.
- Convert usage ingestion to a Sanctum token-authenticated API and provide
  simple Postman login and usage request examples.
- Implement and verify the merchant dashboard, including a 30-day line chart,
  colored metric cards, churn-risk styling, and an informational system-status
  card.
- Add tracking logs to invoice generation and verify duplicate invoice
  handling.

## Prompt evidence

The exact prompt text is preserved here for review. The repository does not
contain screenshots of the VS Code chat panel; no prompt screenshot has been
created or altered to represent one.
