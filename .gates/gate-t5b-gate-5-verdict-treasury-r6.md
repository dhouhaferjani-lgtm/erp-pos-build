GATE VERDICT: APPROVE

Reviewer: treasury-reviewer (claude-opus-4-8), round 6, 2026-07-21, controller-dispatched after quota recovery. Target: HEAD `b61d7c20e`.

All four treasury r2 findings and the two Fable-exit conditions assigned to this lane are verifiably closed at HEAD `b61d7c20e`. I re-verified each from source rather than trusting the audit trail, then completed the full gate-focus list. No Critical or Important finding survives.

## r2 finding closures (verified from code)

- **C1 CLOSED** — `apps/web/e2e/smoke/treasury-phase5b-reconciliation.smoke.ts:700` now declares `async ({ page, request })`, so the `request` fixture the Tier 3 assertions dereference is bound. Every step's fixture matches its body: steps 1/2/7 `{ request }`, 3/5 `{ page }` (neither references `request`), 4 `{ page, request }`, 6 `{ request, page }`. The false-green vector is gone.
- **C2 CLOSED** — `ReconciliationWorkspacePage.tsx:110-111` gates `canAcknowledgeIgnored = !hasIgnoredLines || hasPermission('bank-statements.reopen')`, feeds it into `getWorkspaceCompletionState(...):44`, and renders a translated `ignoredRequiresAdmin` warning at `:118`. Backend mirrors exactly: `StatementCompletionService.php:161-163` throws unless `$acknowledgeIgnored && $actor->can('bank-statements.reopen')`. Render coverage added (`ReconciliationWorkspacePage.render.test.tsx`).
- **I1 CLOSED** — `AcquirerFeeService.php:49` replay branch now uses the injected `$this->scaleResolver->getScale($existing->currency)`, matching the fresh path at `:63`. No `CurrencyScale::for()` static map remains in the service.
- **I2 CLOSED** — over-allocation guards reduce per-row via `pluck('matched_amount')->reduce(bcadd(…bcformatStrict…))`: `StatementCompletionService.php:144-150` and `StatementMatchingService.php:232-237`. No raw Eloquent `sum()`-to-string cast.

## Fable-exit condition closures (verified from code)

- **F1 (date localization) CLOSED** — all six sites route through `lib/format.formatDate`: `StatementListPage.tsx:65`, `ReconciliationWorkspacePage.tsx:115` (subtitle) and `:129` (line rows), `LinePanel.tsx:75`, `StatementUploadWizard.tsx:167`, `ManualMatchSearch.tsx:42` (now `formatDate(movement.occurred_at)` instead of `.slice(0,10)`, restoring timezone conversion). Grep confirms zero remaining raw date renders under `statements/` (only non-render uses survive: a search-predicate array and column-mapping field names). Red-first assertions added in three test files assert the formatted value present and raw ISO absent.
- **F4 (smoke re-runnable) CLOSED and correct, not a weakening** — new step 7 (`smoke.ts:852-873`) POSTs the real `/bank-statements/{id}/reopen` endpoint and asserts `last_reconciled_at` is `null`. This is faithful to `StatementCompletionService::reopen():220-229`: with the reopened statement moved to `Reconciling` and no later reconciled statement, `$latest` is null, so the repository checkpoint is released (`last_reconciled_at`/`last_reconciled_balance` → null). Reopen only flips status and recomputes the checkpoint — statement/line/allocation/execution rows are preserved. It exercises a permission-gated, tenant-scoped real endpoint, so it strengthens rather than fakes.
- **F2 (audit record)** — `3c02ee19a` recovered the completed Fable r2 verdict and reconciled the r7 "empty output" note + HANDBACK lines. Outside the treasury lane's money surface; noted as satisfied.

## Full gate-focus results

1. **UI→backend contracts** — PASS. Statuses, signed amounts, completion gating traced; `remainingForLine` (status.ts:10-20) subtracts signed allocations by direction; completion CTA gated correctly.
2. **`resolved_by_creation` modeling** — PASS. Present in `api.ts` type union, the filter dropdown (`ReconciliationWorkspacePage.tsx:128`), all three locales (en/fr/ar `treasury.json`), and `status.ts:27,31` (counted resolved and successful). Zero-remaining math conceals nothing — ignored returns `Big(0)`, non-ignored subtracts real allocations.
3. **Legacy cutover** — PASS. All 8 legacy endpoints (incl. `summary`) asserted 404 via `assertNotFound()` (`BankReconciliationCutoverTest.php:33-47`); FE `BankReconciliationPage.tsx`, `api/reconciliation.ts`, `hooks/useReconciliation.ts` deleted; FinanceHub (`:75`), Sidebar (`:276`), and routes (`index.tsx:81-82`) repoint to `/treasury/statements`. No money-writing bypass — `last_reconciled_*` written only by the new service.
4. **Smoke false-green audit** — PASS. Step 2 creates a real Tier 1 adjustment (201), issues real outbound expense cheques (Tier 3), provisions a dedicated POS terminal, and ingests a genuine device fiscal SALE event on a genesis-seed hash chain (Tier 4 card batch). Real browser interactions in steps 3-5; checkpoint observation + rejected same-date writes in step 6.
5. **Signed-sum consistency** — PASS. `+15.000 −37.125 +98.500 −2.500 = +73.875` non-ignored; ignored `−1.250`; delta `72.625` = `STATEMENT_DELTA` (`smoke.ts:23-30,655-659`); `closingBalance = addMoney(openingBalance, STATEMENT_DELTA)` (`:368`). Integer-millimes helper (`:131`) is sign-correct.
6. **No mocked subject / weakened tests** — PASS. New render tests import and render the real `ReconciliationWorkspacePage`/`LinePanel`/`StatementUploadWizard`; only i18n/router/data infra is mocked. `allocate()` (`StatementMatchingService.php:32-91`) writes allocation metadata only; movements are created solely in `executeAndAllocate()`.
7. **Gate 0 resolver** — PASS. `EloquentPaymentMethodResolver::resolveByCode($tenantId,$companyId,$methodCode)` scopes by tenant + company + code. Both consumers pass `$event->company_id`: `PosCoreReceiptProjection.php:761` (writePayment) and `:1365` (payment_methods_hash), plus `TreasuryReceiptBridge.php:414`. Regression `EloquentPaymentMethodResolverTest.php:56-79` seeds two companies with duplicate `CARD` codes and asserts the requested company's method is selected; canonical fiscal payload is untouched (hash computed read-only, rolls back on miss).

## Invariant checklist

| Invariant | Result |
|---|---|
| Allocations are metadata; only executions write money | PASS — `allocate():32-91` saves allocation rows only |
| Execution atomic with allocation/provenance | PASS — `executeAndAllocate():104` single transaction |
| Tier 1 confirm / Tier 3 clear / Tier 4 gross + fee exactly once | PASS — idempotency key + digest replay (`AcquirerFeeService.php:46-59`) |
| Ignored counted resolved, excluded from remaining, signed acknowledgment | PASS — `status.ts:11,27`; `StatementCompletionService.php:156-162` |
| `resolved_by_creation` conceals no money | PASS — `status.ts:27,31`; `remainingForLine` exact |
| Checkpoint stamped; same-date writes rejected without partial mutation | PASS — completion stamps `:165-171`; guard verified in prior rounds |
| Money = numeric-string + bcmath, no float | PASS — I1/I2 now use injected resolver + bcformatStrict throughout |
| Legacy cutover leaves no money-writing bypass | PASS — 8 endpoints 404; FE deleted; UI repointed |
| Route middleware, permission gates, tenant/company scoping | PASS — resolver + completion service scope by tenant+company; reopen tenant-checked (`:195`) |
| Signed-sum consistency | PASS — `72.625` reconciles |
| Date localization (F1) | PASS — six sites via formatDate, no raw ISO remains |

## Test-evidence assessment

Tests are genuinely adversarial and do not mock their subject. The three new render/date tests are red-first (assert formatted value present AND raw ISO absent), the resolver regression proves company disambiguation on repeated codes, and the completion/backend tests assert the accountant/admin permission split and the signed ignored total. The live Playwright smoke was NOT re-run this session — I judge it from committed code: the sole prior blocker (C1's unbound `request`) is fixed, every step's fixture now matches its body, and step 7 makes the fixture re-runnable. The 3 larastan `checkModelProperties` errors in untouched Phase-3 `TreasuryAlertRecipients.php` are an APP_ENV=testing/no-DB introspection artifact, not code defects — not findings. Controller-reported counts (statement Vitest 35/35, lint/typecheck clean, targeted PHPUnit 58+96, PHPStan/Pint clean) are consistent with the code I read but were not independently re-executed here.

## Non-blocking (ticket, not gating)

- Fable I4: `payment_methods_hash` reprojection drift for pre-Gate-0 multi-company receipt rows — a data-remediation ticket; device-signed canonical bytes are unaffected.
- `STATEMENT_DELTA` hardcoded rather than summed from row constants (`smoke.ts:30`) — fragile if an amount is later edited, but currently correct.
- Dead legacy models `BankReconciliation.php`/`BankReconciliationItem.php` retained with zero writers.
- `lib/format.formatDate` parses date-only strings as UTC then formats in local tz — a pre-existing property of the shared app-wide helper (which F1 explicitly directed wiring through); could shift the day for users far behind UTC. Not a new defect in this wave.

VERDICT: spec ✅ + quality APPROVED

What must be fixed before the ⑤b exit review: nothing in the treasury lane — all r2 findings (C1/C2/I1/I2) and exit conditions F1/F4 are closed in code; ticket the four non-blocking items above and re-run the live Playwright smoke green in a DB-backed session to convert the committed-code judgement into executed evidence.
