# CODEX Dispatch — Multi-location Wave 4 (analytics) + Task 0 backlog burn-down — 2026-07-22

## Context / current state
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`, branch `feat/multi-location`, tip `daecce54d`. Work ONLY here.
- **Gate 3b is CLOSED.** All four gates are tagged clean: `multiloc-gate-1` … `multiloc-gate-3b`. Round-2 verdicts: `.gates/gate-3b-verdict-r2.md` (treasury APPROVE) and `.gates/wave3-fe-conventions-verdict.md` (FE APPROVE, r3 addendum).
- The controller (not you) landed two commits on top of your remediation — do not revert them:
  - `50da9d07a`: `cashWidget.byLocation` moved to the correct block in en/fr `treasury.json`; dead `instruments.byLocation` key removed; `borderColors.light` on both aged-report bucket sections; `LocationFactory` code → `'LOC-'.$this->faker->unique()->numerify('#####')`.
  - `daecce54d`: gate-3b closure addendum.

## Task 0 — two deferred Gate-3b follow-ups (do BEFORE Wave 4, commit separately)

**0a. Emit `buckets_by_location` from the backend DTOs so the transformer covers it.** The upcoming-payments and maturing-instruments payloads currently carry location buckets that are NOT in any transformed DTO, so the FE hand-declares their shapes (`apps/web/src/features/finance/types.ts` — inline `UpcomingLocationBucket` augmentation on `UpcomingPaymentsData`, ~line 340; `apps/web/src/features/treasury/InstrumentListPage.tsx` — local `MaturityResponse.meta` interface, ~line 90; both marked FRONTEND-ONLY). Add the bucket arrays (reuse `LocationReportBucketData`) to the source DTOs those endpoints serialize, run `CACHE_STORE=array php artisan typescript:transform`, then delete the hand-declared FE shapes and consume the generated types. Verify: `pnpm typecheck` green; targeted vitest on `TreasuryOverviewPage.test.tsx` and `InstrumentListPage.test.tsx`; the touched backend endpoint tests by path on both runners.

**0b. AP GR-IR accrual reconciliation case.** `AgedPayablesService::locationBuckets()`'s accrual branch (auto-generated Received POs valued at the computed GR-IR accrual via the mutated `balance_due`, AgedPayablesService.php:227) reconciles by construction but has no test. Add a case to `tests/Feature/Treasury/LocationReconciliationTest.php` seeding at least one auto-generated **Received** PO (`payload->auto_generated`, with goods-receipt data so the accrual differs from raw `total`) attributed to a location, plus one with NULL location, and assert `sum(location buckets) == grand_total` for the unrestricted caller. Must pass on sqlite AND pgsql.

## Wave 4 — execute the plan exactly
`docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md` — all tasks, in order (POS analytics location dimension; wire the orphaned `SalesByLocationChart` into the owner dashboard as a grouped-bar store comparison, fixing its money→chart conversion + colors; `BranchLeaderboard` respects the view scope; plus the plan's remaining tasks). The plan file is the contract; where it names files/tests, follow it verbatim.

## Standing rules (unchanged from Waves 1–3)
1. TDD: red → green → refactor. Tests BY PATH only — NEVER the full PHPUnit or vitest suite.
2. Aggregate/money Feature tests must pass on BOTH runners; every NEW backend Feature class goes into the pgsql CI `--filter` allowlist (`.github/workflows/ci.yml` ~:555).
   PG runner: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret ./vendor/bin/phpunit -c phpunit-pgsql.xml <paths>`
3. **i18n: every new `t()` key must exist in en AND fr AND ar.** Gate 3b's only FE MAJOR was a key present in ar but missing in en/fr — CI does NOT catch this and test-mode i18next masks it. Grep all three locale files for every key you add.
4. Design tokens (no hardcoded Tailwind colors in touched lines); `locationScopedKey`/`tenantScopedKey` on scope-dependent queries; strict types, no `any`; bcmath-only money with the injected scale resolver; constructor injection.
5. Verification claims must be re-run at the FINAL tip — a stale "typecheck passes" claim was a round-1 Critical.
6. Kill hung vitest worker pools after any run (`ps aux | grep 'node (vitest'`).

## Gate
When Task 0 + Wave 4 are complete and verified: write `.gates/gate-4-request.md` (same pattern as `.gates/gate-3b-request-r2.md` — reviewer persona, exact scope diff paths, plan reference, verification evidence at tip) and **STOP**. Never tag, merge, or push — the controller runs Gate 4 and performs the merge.
