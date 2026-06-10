# Phase 5 Customer-Account DEPOSIT_RECEIPT Review

## Verdict

REQUEST-CHANGES

## Findings

### BLOCKER

1. `runPendingProjectionsSync()` is not a safe synchronous drain in production: it can skip in-flight async rows and it dead-letters retryable projection failures on the first HTTP attempt.

   Evidence:
   - `FiscalEventProjectionDispatcher::dispatch()` always registers an after-commit callback that dispatches one queued `ApplyFiscalEventProjectionJob` per pending row (`apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:131`).
   - The new HTTP drain only selects rows that are still `pending` (`apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:155`) and dispatches that fixed list (`apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:162`). A real queue worker can claim a row first: the job flips it to `running` before projector work (`apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:313`) and only writes `applied` after `apply()` returns (`apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:392`, `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:408`).
   - The orchestrator reads the allocation summary immediately after this pending-only drain (`apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:91`, `apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:97`). If the Treasury row is already `running`, `DepositAllocationSummaryService` can still see no `Payment` and returns `settled=0, credited=0` (`apps/api/app/Modules/Treasury/Application/Services/DepositAllocationSummaryService.php:32`, `apps/api/app/Modules/Treasury/Application/Services/DepositAllocationSummaryService.php:38`).
   - `Bus::dispatchSync()` is also not "call `handle()` inline and leave retries alone" for a `ShouldQueue` job. Laravel routes it to the sync queue (`apps/api/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php:93`, `apps/api/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php:98`). On exception, the sync queue calls `$queueJob->fail($e)` immediately (`apps/api/vendor/laravel/framework/src/Illuminate/Queue/SyncQueue.php:220`, `apps/api/vendor/laravel/framework/src/Illuminate/Queue/SyncQueue.php:224`), which invokes the job's `failed()` hook (`apps/api/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php:359`). This job's `failed()` marks the projection row terminal `dead_lettered` (`apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:449`, `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:457`).

   Repro:
   - Race path: run a queue worker for `fiscal-projections`, add a temporary sleep in `TreasuryDepositBridge::apply()` after the worker claims the row but before `Payment::create()` (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:86`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:114`), then POST a deposit. The worker can set the row to `running`; the HTTP drain skips it because it only selects `pending`; the response can report `credited_amount=0.000` even though the Treasury bridge is still working.
   - Failure path: use an active repository whose `account_id` is missing/inactive so `resolveRepository()` throws before payment creation (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:195`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:206`). The fiscal event and POS receipt can remain committed, the POST returns 500, and the Treasury projection row is immediately `dead_lettered` instead of remaining pending for the already-enqueued async retry.

   Fix:
   - Split "seed projection rows" from "enqueue async jobs." For the request path, commit the fiscal event + projection rows, run a single owner-controlled in-process drain before enqueuing async jobs, then enqueue only rows still `pending` after a failed drain.
   - Do not execute this `ShouldQueue` job through `Bus::dispatchSync()` if the intended semantics are "record failure accounting and leave the row retryable." Use a projection runner service shared by the queue job and the HTTP drain, or invoke a non-queue `processOnce()` path that advances `attempts` but does not call `failed()`.
   - Add a regression test where the Treasury projector throws once and assert the projection row remains retryable, plus a test that an already-`running` row cannot produce a successful synchronous response with zero allocation summary.

### P1

1. Invalid client amounts pass the FormRequest and then throw plain runtime exceptions inside fiscal authoring, turning validation errors into 500s.

   Evidence:
   - `RecordDepositRequest` accepts `"0"` and any number of decimal places because the amount rule is only `required|string|regex:/^(0|[1-9]\d*)(\.\d+)?$/` (`apps/api/app/Modules/Partner/Presentation/Requests/RecordDepositRequest.php:31`).
   - Fiscal authoring later rejects over-precision with `RuntimeException` (`apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:338`, `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:339`) and rejects zero through payload validation (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:497`, `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:498`), wrapped again as `RuntimeException` (`apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:358`, `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:361`).
   - `PartnerDepositController::store()` does not catch these as validation failures; it calls the service and returns success only if no exception bubbles (`apps/api/app/Modules/Partner/Presentation/Controllers/PartnerDepositController.php:61`, `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerDepositController.php:72`).

   Repro:
   - POST `/api/v1/partners/{customer}/deposits` with `amount: "0"` or, for TND, `amount: "10.0001"`. The FormRequest accepts the payload; the lower fiscal layer throws a runtime exception; the API returns a server error instead of a 422 with field errors.

   Fix:
   - Add request-level `gt:0` and a scale ceiling such as `regex:/^\d+(\.\d{1,3})?$/` for the storage contract, or add a currency-aware `after()` validator that uses the requested/default currency scale.
   - Keep the lower fiscal guard as defense-in-depth, but translate known `deposit_receipt_amount_*` / payload amount failures into `ValidationException` before authoring a fiscal event.
   - Add feature tests for zero, negative, scientific notation, and over-precision.

2. `settled_amount` can exceed the deposit amount for 3-decimal currencies because allocation code writes 4-scale amounts while the database column is still 2-scale.

   Evidence:
   - The original migration creates `payment_allocations.amount` as `decimal(15, 2)` (`apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:171`, `apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:175`).
   - The model advertises `amount` as `decimal:4` (`apps/api/app/Modules/Treasury/Domain/PaymentAllocation.php:42`, `apps/api/app/Modules/Treasury/Domain/PaymentAllocation.php:45`), and `PaymentAllocationService` accumulates allocation math at scale 4 (`apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:157`, `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:187`) before inserting the raw allocation amount (`apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:173`, `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:176`).
   - `DepositAllocationSummaryService` trusts the persisted `payment_allocations.amount` values when computing `settled` (`apps/api/app/Modules/Treasury/Application/Services/DepositAllocationSummaryService.php:44`, `apps/api/app/Modules/Treasury/Application/Services/DepositAllocationSummaryService.php:52`) and clamps only the credited remainder (`apps/api/app/Modules/Treasury/Application/Services/DepositAllocationSummaryService.php:55`).

   Repro:
   - With a TND company, create an open invoice/balance of `1.005`, then record a deposit of `1.005`. `payments.amount` is widened to 3 decimals (`apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:110`), but `payment_allocations.amount` is not in that widening list. PostgreSQL will coerce the allocation into the 2-decimal column, so the summary can report `settled_amount=1.010` and `credited_amount=0.000` for a `1.005` deposit.

   Fix:
   - Add a tenant migration that widens `payment_allocations.amount` to the intended scale (`decimal(15, 4)` if allocation internals are really 4-scale, otherwise at least the currency storage scale).
   - Align the balance-due trigger and any generated casts with the same scale.
   - Add a Phase 5 HTTP test for a 3-decimal partial/full settlement amount such as `1.005`.

### P2

1. Deposit history does not return the method/reference/actor fields the Phase 5 API/UI contract needs.

   Evidence:
   - The spec says the deposit history list should include date, amount, method, reference, and actor (`docs/superpowers/specs/2026-06-08-customer-account-deposit-topup-phase5-design.md:212`, `docs/superpowers/specs/2026-06-08-customer-account-deposit-topup-phase5-design.md:214`).
   - `DepositReceiptQueryService` returns only fiscal event id, receipt UUID, customer id/name, amount, currency, and recorded time (`apps/api/app/Modules/POS/Application/Services/DepositReceiptQueryService.php:31`, `apps/api/app/Modules/POS/Application/Services/DepositReceiptQueryService.php:38`).
   - The projection stores `payload_snapshot` (`apps/api/database/migrations/tenant/2026_06_09_130000_create_pos_deposit_receipts_table.php:23`), and that payload contains `actor_name`, `actor_user_id`, `notes`, and `payment.method_code` (`apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:224`, `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:236`), but the query service drops them.

   Repro:
   - POST a deposit with `payment_method_code: "CASH"` and `note: "Paid in cash at the depot"`, then GET `/api/v1/partners/{partner}/deposits`. The response has `data.0.amount` but no `payment_method_code`, no note/reference, and no actor.

   Fix:
   - Extend the history read model to expose stable fields from `payload_snapshot` (`payment_method_code`, `repository_id`, `actor_user_id`, `actor_name`, `note`) and/or join the Treasury `Payment` by `fiscal_event_id` for the payment reference.
   - Add assertions to `RecordCustomerDepositTest::test_post_records_a_pure_advance_deposit_end_to_end_and_lists_it()` for those history fields.

### NIT

None.

## False-Positive Checks I Ran

- Transaction/fiscal truth: authoring and projection-row seeding are inside one transaction (`apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:63`, `apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:84`), and sync projection runs after commit (`apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:91`). The fiscal event persisting when a downstream projection fails is acceptable; the blocker is that Phase 5 turns retryable sync failures into immediate `dead_lettered` rows and can return before async Treasury finishes.
- Idempotency/double-apply: the projection row unique key plus projector guards are real. POS no-ops on existing `pos_deposit_receipts.fiscal_event_id` (`apps/api/app/Modules/POS/Application/Projections/DepositReceiptProjection.php:55`, `apps/api/app/Modules/POS/Application/Projections/DepositReceiptProjection.php:87`), and Treasury no-ops only after asserting the existing payment matches (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:99`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:101`). I did not find a duplicate-payment path independent of the sync/async race above.
- Module boundaries: Partner imports Partner's own model plus public POS/Fiscal/Treasury services (`apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:7`, `apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:12`; `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerDepositController.php:7`, `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerDepositController.php:12`). I did not find Partner importing Treasury/POS/Fiscal models.
- Route/API gates: the routes use `api`, `auth:sanctum`, `SetPermissionsTeam`, `EnforceTokenTenantClaim`, `can:payments.create`/`can:payments.view`, and `whereUuid` (`apps/api/app/Modules/Partner/routes.php:20`, `apps/api/app/Modules/Partner/routes.php:32`, `apps/api/app/Modules/Partner/routes.php:37`).
- Tenant/company scope and actor membership: global API middleware validates that the user has access to the current company before the controller runs (`apps/api/app/Http/Middleware/CompanyContextMiddleware.php:66`, `apps/api/app/Http/Middleware/CompanyContextMiddleware.php:77`), and the Treasury bridge independently requires an active company membership before creating the payment (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:245`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:251`).
- `payment_method_code` vs `payment_method_id`: accepting a method code is consistent with the canonical deposit payload (`apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:218`, `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:221`) and with the Treasury bridge resolving active payment methods by `(tenant, company, code)` (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:162`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:168`).
- Currency default: the controller defaults omitted currency to the current company currency (`apps/api/app/Modules/Partner/Presentation/Controllers/PartnerDepositController.php:56`, `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerDepositController.php:59`).
- Balances: returning posted-GL cached balances alongside `credited_amount` is acceptable if documented for API consumers. The service comments make that eventual-consistency distinction (`apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:104`, `apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:106`), and the bridge fails loud before payment creation if the actor could not post the advance path (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:219`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:231`).
- Verification: `composer test -- --filter RecordCustomerDepositTest` does not pass through `--filter` in this repo and exits before running tests. `php artisan test --filter=RecordCustomerDepositTest` completed with 3 PHPUnit warnings and 21 assertions; no assertion failures.

---

## Resolution (Claude, 2026-06-10)

**BLOCKER-1 — unsafe synchronous drain (dead-letters retryable rows; races async workers): FIXED.**
The dispatcher is split into `seedProjections()` (idempotent row insert, NO async
enqueue) and `runSeededProjectionsSync()`. The orchestrator now seeds rows inside the
authoring transaction and, after commit, runs them in-process. Each row is driven by
invoking `ApplyFiscalEventProjectionJob::handle($db, $registry)` DIRECTLY — not via
`Bus::dispatchSync()` — so the sync-queue `failed()` hook never fires: a projector that
throws leaves its row `pending` (retryable) with advanced attempts, never
`dead_lettered`. Because nothing is enqueued up-front in the sync path there is no async
worker to race; a row that still fails is handed to the async queue as a retry safety
net and the failure is re-thrown to the caller. The legacy async `dispatch()` keeps its
`afterCommit` enqueue for non-request callers. Tests:
`test_run_seeded_projections_sync_applies_rows_in_process` and
`test_run_seeded_projections_sync_leaves_a_failed_row_retryable_not_dead_lettered`
(throwing projector → row stays `pending`, attempts=1, re-queued for async retry).

**P1-1 — invalid amounts returned 500 instead of 422: FIXED.**
`PartnerDepositController::amountError()` does a currency-aware boundary check (resolves
the scale from the requested/default currency) and returns 422 `INVALID_AMOUNT` for a
non-positive or over-precise amount before any fiscal authoring. The fiscal-layer
`RuntimeException` guard stays as defense-in-depth. Tests: zero amount and a 4-decimal
TND amount both return 422 with no fiscal event / payment created.

**P1-2 — `payment_allocations.amount` is decimal(15,2) but allocations write scale-4: DEFERRED (ticket).**
Pre-existing Treasury-wide schema defect (affects device ACCOUNT_PAYMENT + manual
allocation too, not just deposits); fixing it is a shared-table migration + broad
allocation/GL regression, outside the deposit feature's scope. Filed as
`docs/superpowers/tickets/2026-06-10-payment-allocations-amount-scale.md` (widen the
column to the currency storage scale + a 3-decimal settlement regression). The deposit
feature is correct for scale-2 currencies and the pure-advance path used today.

**P2-1 — history missing method/actor/reference fields: FIXED.**
`DepositReceiptQueryService` now also returns `payment_method_code`, `actor_name`, and
`note` from the stored `payload_snapshot`. The full-flow test asserts them.

Gates after fixes: Phase 5 tests 15 green (5 HTTP full-flow incl. 2 new 422 cases + 10
dispatcher incl. 2 new sync-run cases); Pint pass; PHPStan L8 clean; deptrac unchanged
(0 new violations); full Fiscal+Partner+Treasury regression re-run below.
