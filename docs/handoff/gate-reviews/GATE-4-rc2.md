# GATE 4 (Treasury Phase 2) — Adversarial Review, rc2

**Scope:** `git diff phase2-gate-3..HEAD` (`8682de10a` → `d4c42ae4b`), Tasks 19–28 plus the rc1 remediation commit.  
**Candidate:** `phase2-gate-4-rc2` — commit `d4c42ae4b4f8246331309791e06c65222ce5c2df`, branch `feat/treasury-instruments`, tag `phase2-gate-4-rc2` (branch and tag unchanged since the approving pass).  
**Reviewer:** `claude-opus-4-8`; primary review plus independent Treasury-backend and frontend-conventions passes.  
**Binding references:** `docs/superpowers/specs/2026-07-10-treasury-phase2-instruments-echeancier-design.md` **Rev 2** (§2 currency guard, §7 posting tables, §6 lock order, §10 échéancier, §20 reconciliation) and `docs/superpowers/plans/2026-07-11-treasury-phase2-instruments-echeancier.md` **Rev 2**.  
**Prior artifact:** `docs/handoff/gate-reviews/GATE-4-rc1.md` — `VERDICT: CHANGES-REQUIRED` (five MED, two LOW).

## Money-path attestation

The gate still introduces no new GL postings and no new treasury movements. Tasks 19–22 are read-only reporting/reconcile, Tasks 23–25 are frontend, Tasks 26–28 are CI/docs; the only write endpoint (`cancel`) delegates to the already-reviewed `InstrumentLifecycleService`. The rc2 remediation touched money-adjacent code in three places, each re-audited:

- **Reconcile check #4** (`ReconcileTreasuryCommand.php:339–343`) still returns failure and writes an audit event only — it never freezes or posts. The excluded-circuit net is computed with `bcsub`/`bcadd`/`bccomp` at the resolved currency scale; no float touches the comparison.
- **`InstrumentLifecycleService::receive()`** (`:66–80`) adds a pre-write currency guard; the subsequent `CurrencyScale::bcformatStrict($data->amount, $this->scaleResolver->getScale($data->currency))` is unchanged — explicit currency passed to `getScale`, no no-arg `getScale()`, no float.
- **Maturity alerts / maturing-instruments** now derive `today` from `$company->timezone`; bucket, alert, and `grand_total` arithmetic remain string/bc-based and are test-pinned.

The forecast double-count guard and migration/bucket logic are unchanged and remain green. No BLOCKER/HIGH money-path finding was raised at rc1 or rc2; the currency guard is a spec-§2-mandated hardening that adds no posting or movement. **Brief §3 Fable escalation rule is not triggered.**

## Frontend convention verification

- **Canonical components / tokens:** `RemittanceDetailPage.tsx:83,93` and `BordereauPrintView.tsx:27,52` now build a typed `DataTableColumn<RemittanceLine>[]` and pass `columns`/`data`/`keyExtractor` to the canonical `DataTable`, replacing the legacy `<thead>/<tbody>` passthrough. Totals footers retain design-token styling (`borderColors.default`, `tabular-nums`) and exact Big.js math (`BordereauPrintView.tsx` total via `Big(...).toFixed(3)`).
- **i18n:** both invalid keys now resolve to the existing `common:common.selectOption` entry (`InstrumentDetailPage.tsx:310`, `RemittanceCreatePage.tsx:93`), which is present at `en/common.json:67` and `fr/common.json:67`. No hardcoded user-facing strings introduced.
- **Permission gating:** `InstrumentDetailPage.tsx:186` gates the cancel action on the new `instruments.cancel` permission, mirrored in the client map `usePermissions.ts:75`.
- **Money/precision FE contract:** `MoneyInput`/`formatCurrency` preserved; no `parseFloat`/`Number(...)` on money introduced.
- **Gate evidence:** `pnpm typecheck && pnpm lint` pass at the unchanged 6,423-warning baseline; TanStack query-key audit **0 new**; design-system audit **0 new**; `pnpm vitest run src/features/treasury src/features/finance` — 54 files / 357 tests pass.

## Disposition of the seven rc1 findings

1. **MED — at-sight rows disappear under date filters → FIXED (test-first).** `MaturingInstrumentsController.php:51–83` now computes `$includesAtSight` (`:54`) from the company-local `today` and, when the window contains today, matches `whereNull('maturity_date')` OR the dated range (`:60`); otherwise it explicitly excludes null rows. At-sight paper now appears and feeds `total_in`. Regression: `MaturingInstrumentsTest::test_date_window_treats_null_maturity_as_due_today` (asserts the null-maturity row lands in bucket `d0_7` with `grand_total.total_in = '9.000'`, and is excluded for a future-only window). Spec §10 satisfied.

2. **MED — invalid select-option i18n keys → FIXED (test-first).** `RemittanceCreatePage.tsx:93` and `InstrumentDetailPage.tsx:310` now use `common:common.selectOption`, which exists (`en/common.json:67`, `fr/common.json:67`). The remittance test (`__tests__/Remittance.test.tsx`) now asserts translated output rather than a raw key.

3. **MED — cancellation used the detail-edit tier → FIXED (test-first).** A dedicated `instruments.cancel` permission now gates the route (`routes.php:134`), is seeded (`RolesAndPermissionsSeeder.php:217`, granted to manager `:472` and accountant `:692`), added to the client map (`usePermissions.ts:75`), and gates the detail action (`InstrumentDetailPage.tsx:186`). A details-only role can no longer reverse a receipt. Regressions: `PaymentInstrumentTest` (backend 403) and `InstrumentDetailPage.lifecycle.test.tsx` (cancel visibility). The reviewer-driven expansion beyond the plan's three-permission list is recorded in the progress doc and deploy checklist.

4. **MED — cutover watermark false portfolio drift → FIXED (test-first).** `ReconcileTreasuryCommand.php` renames `manualInstrumentGlNet` → `excludedInstrumentGlNet` (`:343`) and widens the excluded population to `payment_id IS NULL` **OR** `created_at < phase2_cutover_at`, while the JE-side query keeps the `created_at >= cutover` watermark — so a linked pre-cutover instrument remitted post-cutover is treated as one coherent excluded circuit on both legs. Regression: `ReconcilePortfolioCheckTest::test_pre_cutover_linked_instrument_with_post_cutover_remittance_is_one_excluded_circuit` sets `phase2_cutover_at`, runs `treasury:reconcile`, and asserts **0** `treasury.reconcile.portfolio_drift` audit events. No posting, movement-port, repository, or lock-order code changed.

5. **LOW — remittance/bordereau tables used DataTable legacy passthrough → FIXED.** Both migrated to the typed columns API (`RemittanceDetailPage.tsx:83,93`; `BordereauPrintView.tsx:27,52`), with the `effet`-only maturity column conditionally spread and totals moved to a token-styled footer row. Big.js exact totals retained.

6. **LOW — maturity alerts used application `today()` → FIXED (test-first).** `InstrumentMaturityAlertsCommand.php:94` now uses `CarbonImmutable::today($company->timezone)`, matching the controller's boundary source (`MaturingInstrumentsController.php:51`). Regression: `InstrumentMaturityAlertsTest::test_alert_boundary_uses_each_company_timezone` pins a `Pacific/Kiritimati` company and asserts `as_of_date = '2026-07-12'` with the expected `received_due_ids`.

7. **LOW — échéancier/forecast summed foreign-currency instruments without FX → RESOLVED BY CONSTRUCTION (test-first).** `InstrumentLifecycleService::receive()` (`:74`) now enforces the spec §2 hard guard (design doc line 95: “instrument currency == company currency”) before any instrument/event write, throwing `DomainException('Instrument currency must match company currency.')`. Foreign paper is rejected atomically, so portfolio/échéancier totals are single-currency and FX aggregation is not required. Regression: `InstrumentLifecycleReceiveTest::test_receive_rejects_currency_outside_the_company_currency` (expects the `DomainException` for a `EUR` receipt in a `TND` company).

## Residual LOW/INFO observations (all non-blocking)

- **INFO — forecast/échéancier totals still perform no FX conversion** (`EcheancierPanel.tsx`; `MaturingInstrumentsController` `grand_total`). Now safe going forward because `receive()` guarantees company-currency-only instruments; a hypothetical legacy row written before the guard could still mis-sum, but spec §2 has always disallowed such rows and the brownfield backfill is single-currency. No code path can create new foreign paper. Non-blocking.
- **INFO — new `instruments.cancel` permission expands the plan's original three-permission set to four.** Requires seeding plus the mandatory tenant-scoped `permission:cache-reset` at deploy (Spatie cache is tenant-blind); this is documented in `treasury-phase2-deploy-checklist.md` (“adds `instruments.update`, `instruments.bounce`, `instruments.remit`, and `instruments.cancel`”). Operational note only.
- **INFO — the currency guard reads `companies` via `DB::table(...)->value('currency')`** inside the `receive()` transaction rather than through an injected repository (`InstrumentLifecycleService.php:66–74`). Acceptable: the service stays context-free, the read is `tenant_id`+`id` scoped, and it precedes all writes. Non-blocking.
- **LOW — `receive()` now throws on foreign paper** where the prior store path could silently accept it. This is a behavior change, but it is spec-§2-mandated, atomic (before any write), and covered by regression. Non-blocking; spec-aligned.

## Escalation decision

No BLOCKER/HIGH finding survived; no money-path posting or movement shape changed; the sole money-adjacent addition (the currency guard) is a spec-mandated invariant with no GL/movement impact. The brief §3 Fable escalation rule is **not triggered**. rc2 full-gate verification is green: backend `phpunit tests/Feature/Treasury tests/Feature/Accounting` — 1,016 tests / 4,198 assertions pass; `phpstan --memory-limit=1G` — zero errors; `pint --dirty --test` and `git diff --check` clean; frontend typecheck/lint/audits clean with **0 new** violations; treasury/finance vitest — 357 tests pass. All four rc1 MED findings were fixed test-first with failing-first (RED) evidence recorded, and the three LOW findings are fixed or dispositioned with rationale.

VERDICT: APPROVE
