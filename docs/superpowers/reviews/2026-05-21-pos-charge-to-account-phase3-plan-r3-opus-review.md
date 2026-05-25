# Phase 3 Stage B Implementation Plan R3 Opus Review

Reviewed artifact: `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`

Prior Opus R2 review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-r2-opus-review.md`

R3 Codex review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-r3-codex-review.md`

Locked spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`

Verdict: APPROVE

## Findings

No blocking, request-changes, or minor findings.

## R3 Verification

The R2 request-changes finding is resolved. Task 7 now adds `test_pos_charge_refreshes_partner_receivable_balance()` (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:789`-`:798`) and makes `GeneralLedgerService::createPOSChargeEntry()` call `PartnerBalanceService::refreshPartnerBalance($companyId, $partnerId)` through the existing constructor dependency after journal persistence (`:843`-`:861`). The plan explicitly says this is mandatory, not best-effort, and that refresh failure must fail `createPOSChargeEntry()` and bubble through the Treasury bridge projection (`:861`).

Task 8 now adds `test_bridge_bubbles_partner_balance_refresh_failure()` (`:889`-`:900`), relies on `createPOSChargeEntry()` to refresh the cached Partner balance before projection success (`:921`-`:928`), and forbids catching or downgrading refresh failures (`:930`). This closes the prior silent-downgrade concern and matches the locked spec outcome that Treasury-active B2C account charges produce printable projection, AR posting, and Partner balance refresh/reconciliation.

The vague file-map wording is also cleaned up. The checkout/customer attach touch points are now explicit: `paymentStore.ts`, `CustomerAttachPanel.tsx`, `CustomerAttachPanel.test.tsx`, and `accountPaymentService.ts` only for shared customer snapshot helper extraction (`:72`-`:81`).

## Standing Pattern Sweep

- D16 bounded-module seam remains intact. The refresh work stays in Accounting/Treasury paths; POS-core remains module-independent and the widened D16 grep guard remains in the plan.
- Cross-tenant FK safety remains covered by tenant/company scoped Treasury customer resolution and Document partner tests.
- Fail-loud behavior is improved: balance refresh failure now fails the GL call and bridge projection instead of being optional.
- Dead-path rebuild remains covered through Task 7 GL tests, Task 8 bridge tests, provider registrations, and CI wiring.
- Contract drift gates remain explicit: exact parser tests, TS/PHP registry parity, golden canonical bytes, line/VAT summary preservation, and chokepoint sentinels.
- Constructor injection remains clean. The plan uses the existing `GeneralLedgerService` constructor dependency and introduces no production `app()`, `App::make()`, or `resolve()` usage.
- No class-level skips or skip-citation issues were introduced.

## Verification Commands

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3`:

- `git status --short && git branch --show-current`
- `nl -ba docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-r2-opus-review.md`
- `nl -ba docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-r3-codex-review.md`
- `rg -n "refreshes_partner_receivable_balance|refreshPartnerBalance|bubbles_partner_balance_refresh_failure|PartnerBalanceService|must not catch|downgrade|where needed|checkout|CustomerAttachPanel|CustomerSearchInput|paymentStore|accountPaymentService|createPOSChargeEntry|constructor|app\\(\\)|App::make|resolve\\(" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`
- `nl -ba docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md | sed -n '72,84p'`
- `nl -ba docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md | sed -n '788,932p'`
- `rg -n "current patterns support|if current patterns|where needed|best-effort|catch and downgrade|app\\(\\)|App::make|resolve\\(|class-level|skip\\(|markTestSkipped|@group|TODO|TBD|placeholder|\\.\\.\\.|maybe|similar to|appropriate" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`
- `rg -n 'requiresModule\\(\\)|Modules\\\\Customer|Modules\\\\Contact|Modules\\\\B2B|Modules\\\\Sales|Tax Invoice|createPOSPaymentEntry|SalesDiscount|vatBreakdown|lineVatSummary|PG merge-gate|ambiguous_alias|insufficient_credit|hard_stale|extra_top_level|malformed_money|malformed_vat' docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`

No runtime test suite was run; this was a plan/document review.
