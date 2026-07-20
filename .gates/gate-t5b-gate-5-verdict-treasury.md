Verified independently. Both agents' key claims hold up against the code.

---

GATE VERDICT: REJECT

## Findings

### Critical

**1. Spec §5.4 violated twice — a second, live writer of the reconciliation checkpoint survives the cutover.**

Spec line 122 is explicit: *"Only the new completion action may stamp `payment_repositories.last_reconciled_*`… `BankReconciliationService` deleted in the same cutover."*

Both clauses are unmet:
- `apps/api/app/Modules/Treasury/Application/Services/BankReconciliationService.php:189-192` still writes `last_reconciled_at` / `last_reconciled_balance` — the exact fields `StatementCompletionService.php:165-166` now owns. It stamps `now()`, not an end-of-period checkpoint.
- It is not dead code: `apps/api/database/seeders/DemoPharmacySeeder.php:1278,1308` calls `$service->completeReconciliation(...)`.

That checkpoint is read at `TreasuryMovementService.php:740` to set `recorded_behind_checkpoint`. Per the standing deploy note that staging reruns `DemoPharmacySeeder`, a legacy seeded reconciliation can clobber the statement-derived checkpoint and mis-flag movement integrity. Routes being gone does not close this — the seeder reaches the service directly.

### Important

**2. The accountant — the role meant to reconcile — cannot complete any statement containing an ignored line.** `StatementCompletionService.php:157` requires `bank-statements.reopen` to acknowledge ignored lines. `RolesAndPermissionsSeeder.php:714` grants accountant only `view`/`import`/`reconcile`; `usePermissions.ts:94` confirms `reopen` is `['admin']`. The smoke ran as `owner@pharmabio.tn` (admin), which masked this entirely.

**3. FE and BE gate acknowledgment on different predicates — a permanently uncompletable statement.** `StatementCompletionDialog.tsx:23` keys on `!new Big(ignoredTotal).eq(0)`; the backend keys on *existence* (`StatementCompletionService.php:102,157`). Two ignored lines netting to zero (e.g. `+1.250` in, `-1.250` out) → no checkbox rendered → `onConfirm(false)` → `DomainException` with no UI path to recover. `StatementCompletionDialog.test.tsx:22-24` encodes the buggy branch, and its name ("only when ignored lines exist") misdescribes what it asserts.

**4. Manager-role dead-end link.** `Sidebar.tsx:276` and `FinanceHubPage.tsx:76` gate on module `treasury`, but the route requires `bank-statements.view` (`routes/index.tsx:1829`), which is server-authoritative (`usePermissions.ts:253`) and not granted to manager. The old route used `repositories.view`, which manager has. Managers see the link and hit a wall.

**5. Hardcoded bcmath scale on money — rule 19, in a CI blind spot.** `RepositoryMovementController.php:103,110`: `bcadd($raw,'0',3)` / `bcsub($movement->amount,$allocatedAmount,3)`, with `$movement->currency` available on the next line. `ForbidHardcodedBcmathScale.php:117-121` only matches `Application/Services/` and `Domain/Services/`, so PHPStan passing proves nothing here — I confirmed `[OK] No errors` on that file. Every sibling controller injects the resolver (`CashPositionController.php:50`, `MaturingInstrumentsController.php:21`, `PaymentController.php:64`), so this is a deviation from same-layer convention, not codebase norm. Same smell on the FE: `ReconciliationWorkspacePage.tsx:98,99,111` `.toFixed(3)`.

**6. The live smoke does not meet gate criterion 4 — four required proofs are UI-badge-only or vacuous.**
- Tier 4 fee "exactly once" is proven by `getByText('Card batch and fee created')` (`:706-707`) — that badge renders for one fee, two, or a wrong amount. No movements query.
- Tier 3: `outboundInstrumentId` is assigned at `:456` and **never read again**. Nothing asserts `received → cleared`.
- "No partial GL mutation" (`:802`) is structurally incapable of failing. I verified the ordering: `OutboundInstrumentService.php:123-136` creates *and posts* the GL entry, the checkpoint guard throws inside `movementService->record(...)` at `:138`, and the status write is at `:158` — after the throw. `status === 'received'` is guaranteed either way. Atomicity is real (transaction rollback), but the assertion cannot observe it.
- The live repository `balance` is never re-read (expected ledger movement `+73.875`), and the signed ignored total `-1.250` on the acknowledge label is never asserted (`:742-743`). The outbound-clear 422 (`:796`) is not message-pinned, unlike the adjustment one (`:790`), so any 422 satisfies it.

**7. Over-broad test deletion.** `usePermissions.treasuryReconciliation.test.ts` removal drops two assertions unrelated to reconciliation and still live: `repositories.view === treasury.view` (backs `/treasury/repositories`) and `treasury.adjust === ['admin','manager','accountant']` (backs cash adjustments — a money-mutating gate).

### Minor
- `smoke.ts:31-34` conditional skip means CI green ≠ smoke ran; `playwright.config.ts:4` picks the file up with no exclusion.
- Screenshot evidence lands in `docs/sessions/`, gitignored at `.gitignore:58` — cannot be attached as a gate artifact.
- `status.ts:18` clamps negative remaining to 0, concealing over-allocation the backend treats as invalid.
- `BankReconciliationCutoverTest.php:35` asserts 404 unauthenticated only; would pass if routes returned under a different prefix.
- `RepositoryMovementController.php:63-65` leading-wildcard `CAST(id AS TEXT) LIKE` — bound, so not injectable, but an unindexable scan on a per-movement-growth table.
- Legacy `bank_reconciliations` rows are now unreachable with no export path.

## Invariant checklist

| Invariant | Status |
|---|---|
| Matching is metadata; money only via executions, atomic with allocation | ✅ `StatementMatchingService.php:120-168` |
| Numeric-string + bcmath at explicit currency scale | ⚠️ services ✅; `RepositoryMovementController.php:103,110` ❌ |
| Completion counts matched/resolved_by_creation/ignored | ✅ `StatementCompletionService.php:104-123` |
| Ignored excluded from remaining; included via signed acknowledgment | ✅ backend `:112-159`; ⚠️ FE predicate diverges (finding 3) |
| Tier 4 fee created exactly once | ✅ structurally (`action_key` unique, migration `:24`; replay reuses produced ids `:125`) — ❌ unproven by smoke |
| Completion stamps checkpoint; same-date writes rejected without partial mutation | ✅ code `:161-167`; ❌ **second writer exists** (finding 1) |
| Legacy routes removed, no reachable money-writing HTTP bypass | ✅ routes gone, no references — ❌ seeder-level bypass remains |
| Tenant/company scoping, route middleware, permission gates | ✅ all new routes carry `can:` middleware; ⚠️ FE/BE grant mismatch (findings 2, 4) |
| `resolved_by_creation` modeled end-to-end | ✅ enum, `api.ts:22`, `status.ts`, filters, badges, en/fr/ar |
| Gate 0 follow-up: tenant+company+code scoping | ✅ resolver scopes all three; **all three** consumers pass `company_id` (`PosCoreReceiptProjection:761,1365`, `TreasuryReceiptBridge:414`); repeated-CARD regression is real; `PaymentDTO` change is docblock-only — canonical payload untouched; company-scoped uniqueness pre-exists (`2025_12_30_195300`) |
| E2E signed-sum consistency | ✅ `+15.000 −37.125 +98.500 −2.500 = +73.875`, ignored `−1.250`, delta `+72.625` = `STATEMENT_DELTA:29`; BigInt millimes helpers `:125-141`, zero float paths; server-enforced at `StatementCompletionService.php:152-155` |

## Test-evidence assessment

The decimal math and the core matching/completion engine are genuinely sound — that part of the claimed evidence holds. But the evidence is weaker than the fresh-evidence summary implies in three ways.

I could **not** independently re-run the backend PHPUnit paths — the command was denied in this session, so "95 tests / 301 assertions" and "53/53" are unverified; I verified those tests by reading instead. The "targeted PHPStan passed" claim is technically true and materially misleading: I ran PHPStan on `RepositoryMovementController.php` and got `[OK] No errors` on code that violates rule 19, because the guard does not cover the Presentation layer. And the headline "6/6 passed in 45.0s" live smoke passes while leaving four of gate criterion 4's required proofs unasserted, having run as an admin that structurally could not surface findings 2 and 4.

No test mocks the subject under test; the one `vi.mock` (`StatementCompletionDialog.test.tsx:6`) stubs only `react-i18next`, which is conventional. Finding 3 is the exception in spirit — that test does not mock the subject, but it encodes the defect as expected behavior.

VERDICT: spec ❌ + quality CHANGES-REQUESTED

Before the ⑤b exit review: delete `BankReconciliationController` + `BankReconciliationService` + `StartBankReconciliationRequest` and re-point `DemoPharmacySeeder` off the legacy service (spec §5.4 — no second checkpoint writer); reconcile the acknowledgment gate on both existence-of-ignored-lines and the `bank-statements.reopen` grant so an accountant can complete; align the sidebar/hub gate with `bank-statements.view`; resolve the scale from `$movement->currency`; and replace the four UI-badge-only smoke proofs with server-state assertions (instrument `cleared`, exactly one `1.500` fee movement, the `-1.250` acknowledge label, live balance `= opening + 73.875`, and no journal entry after the rejected clear).

One note: `.gates/gate-t5b-gate-5-verdict-treasury.md` exists but is empty (0 bytes) from the two interrupted runs. I did not write to it, per the gate-only instruction — tell me if you want this verdict committed there.
