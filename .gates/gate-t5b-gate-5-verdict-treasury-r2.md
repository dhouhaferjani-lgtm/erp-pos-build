GATE VERDICT: REJECT

Two Critical findings, one of which invalidates the gate's own evidence pack. Both were verified from source; the second was reached independently by the backend and frontend reviewers from opposite sides of the boundary, then confirmed by me.

## Critical

**C1 — The live smoke cannot pass at HEAD; the "6/6 passed" evidence is a false green.**
`apps/web/e2e/smoke/treasury-phase5b-reconciliation.smoke.ts:700` declares `async ({ page })`, but the body dereferences a bare `request` at `:709`, `:719`, `:728`, `:730`. No module-scope `request` binding exists in the file, so step 4 throws `ReferenceError` on the first Tier 3 assertion.

This is not a cosmetic typo. Commit `5dce9f1ca` added those assertions (+47 lines to the smoke); the repair commit `8e6b95184` did not touch the smoke file. The cited run therefore predates the strengthened assertions, so the Tier 3 cleared-status check, the `acquirer_fee`-exactly-once check, and the fee movement amount/direction check have **never executed**. `apps/web/tsconfig.json:54` sets `"include": ["src"]`, placing `e2e/` outside `pnpm typecheck` — which is exactly why the passing typecheck did not catch it. Gate focus 4 asked me to audit for false greens; this is one, and it sits on the highest-value assertions in the wave.

**C2 — Completion CTA gated on the wrong permission: a permanent dead-end for the accountant persona.**
`ReconciliationWorkspacePage.tsx:43` derives `canComplete` from `mutable` (= `bank-statements.reconcile`) alone. `StatementCompletionService.php:157` refuses completion of any statement containing ignored lines unless the actor also holds `bank-statements.reopen`. Per `usePermissions.ts:93-94`, `reconcile` → `['admin','accountant']` but `reopen` → `['admin']`; `RolesAndPermissionsSeeder.php:714` matches on the backend. An accountant may ignore a line (`LinePanel.tsx:79`, needs only `reconcile`) and is then permanently unable to complete: the button renders enabled, the checkbox ticks, and submit always 422s with a raw untranslated domain string (`bootstrap/app.php:356-363`), violating rule 11 on the error surface. The routine ignore-then-complete flow is broken for the primary reconciler.

## Important

- **I1** `AcquirerFeeService.php:49,54` — replay branch resolves scale via the static `CurrencyScale::for()` map while the fresh path at `:62` uses the injected resolver. Two scale sources in one service; on disagreement the replay guard compares at a different scale than the original write. Rule 19 violation.
- **I2** `StatementCompletionService.php:144-146`, `StatementMatchingService.php:232-234` — over-allocation guard casts a raw Eloquent `sum('matched_amount')` to string. Safe on pgsql only by framework implementation detail; `RepositoryMovementController.php` hard-guards this same hazard.
- **I3** `ReconciliationWorkspacePage.test.tsx:1-68` never renders the page — it tests only the extracted `getWorkspaceCompletionState` helper, which cannot observe permissions, CTA rendering, or dialog wiring. Its `line()` factory `:23` omits `resolved_by_creation` and `partial`. This is precisely why C2 escaped, and it contradicts the evidence pack's claim of "page-level completion-control coverage."
- **I4** Gate 0 ships no remediation for rows projected under the old tenant-only resolution. `payment_methods_hash` derives from the resolved method's display name (`PosCoreReceiptProjection.php:1362-1380`), so reprojecting an affected multi-company receipt would compute a hash differing from the stored one. Device-signed canonical bytes are unaffected.
- **I5** `api.ts:232-237` reads a `paginate(20)` endpoint and discards `meta`; `ManualMatchSearch` presents the newest 20 movements as the complete eligible set with no truncation affordance.
- **I6** `hooks/__tests__/tenantScope.test.tsx` (416 lines) was deleted wholesale where plan Task 12 said "prune references"; the replacement `queryScope.test.ts` covers only the six statement namespaces.

## Minor

`StatementCompletionDialog.tsx:31` passes `requiresAcknowledgment` to `onConfirm`, not the user's `acknowledged` state (equivalent only because the button is disabled otherwise). `ReconciliationWorkspacePage.tsx:43` lets a zero-line statement satisfy `0 === 0`. `:41-42` places an unsigned `remainingTotal` beside a signed `ignoredTotal`. `LinePanel.tsx:84` concatenates a bare count with a translated noun (no pluralization, fragile RTL). Untokenized borders at `ManualMatchSearch.tsx:43` and `StatementUploadWizard.tsx:245-246`. Orphan key `statements.workspace.create.noIncomeAccounts` in all three locales. Spec §6.5 states a different completion identity than the code implements — the code's version is the correct one; fix the spec. Dead legacy models `BankReconciliation.php` / `BankReconciliationItem.php` survive with zero writers.

## Invariant checklist

| Invariant | Result |
|---|---|
| Allocations are metadata; only executions write money | **PASS** — `StatementMatchingService::allocate():32-45` writes allocation rows only |
| Execution atomic with allocation/provenance | **PASS** — `executeAndAllocate():110-169` single transaction; `lockMutableAggregate():274` serializes |
| Tier 1 confirm / Tier 3 clear / Tier 4 gross + fee exactly once | **PASS** — unique `action_key` (migration `:24`) plus movement-source idempotency key (`AcquirerFeeService.php:46-59`) |
| Ignored counted resolved, excluded from remaining, signed acknowledgment | **PASS** — `status.ts:11,26-31`; `StatementCompletionService.php:104-141,157,175` |
| `resolved_by_creation` conceals no money | **PASS** — `derive():30-35` requires exact `bccomp` equality; completion re-asserts per line at `:135-137` |
| Checkpoint stamped; same-date writes rejected without partial mutation | **PASS** — `TreasuryMovementService.php:735-755` fires before `insertMovementLeg` |
| Money = numeric-string + bcmath/Big.js, no float | **PASS with I1/I2** — zero `parseFloat`/`Number(` in `statements/` |
| Legacy cutover leaves no money-writing bypass | **PASS** — all 8 routes incl. `summary` removed; `last_reconciled_*` written only by the new service |
| Route middleware, permission gates, tenant/company scoping | **PASS** — `routes.php:34,265-330`; scoping at `BankStatementController:161-172`, `StatementLineController:146-159` |
| Completion cannot proceed with real remaining money | **PASS on money, FAIL on permission** — see C2 |
| Signed-sum consistency | **PASS** — CSV `:651-660` gives `+73.875` non-ignored, `−1.250` ignored, `72.625` total, matching `STATEMENT_DELTA:30`. Integer millimes helpers are sign-correct. Weakness: `STATEMENT_DELTA` is hardcoded rather than summed from the row constants, so a future amount edit desyncs the closing balance silently. |
| i18n EN/FR/AR parity, tenantScopedKey, tokens, no `any` | **PASS** |

## Test-evidence assessment

**No claim in the evidence pack was independently verified this session.** The sandbox denied `phpunit` to the backend reviewer, `pnpm`/`vitest`/`typecheck` to the frontend reviewer, and `tsc`/`eslint`/`curl` to me. Every reported count — 95 backend tests, 86+32 frontend tests, lint exit 0, 6/6 smoke — stands unconfirmed, and C1 proves at least the smoke figure is stale. Tests do not mock their subjects and `status.test.ts:28-35` shows genuine adversarial intent, but the single highest-risk surface in the wave has no render coverage (I3). Deleted backend coverage was genuinely relocated, not dropped; the deleted 416-line frontend file (I6) still needs an entry-by-entry replay.

VERDICT: spec ❌ + quality CHANGES-REQUESTED

Before the ⑤b exit review: fix the smoke's missing `request` fixture at `smoke.ts:700` and rerun it green with the strengthened assertions actually executing (and bring `e2e/` into a typecheck or lint scope so this class of false green cannot recur); gate `canComplete` on `hasIgnoredLines && !hasPermission('bank-statements.reopen')` with a translated explanation, add page-level render coverage proving it, and resolve with the owner whether accountants should hold `reopen`; then land I1/I2 and ticket the rest.
