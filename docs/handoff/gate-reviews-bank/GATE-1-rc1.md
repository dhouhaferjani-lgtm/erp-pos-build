# ADVERSARIAL GATE REVIEW — Bank Directory, GATE 1 (Phase 1 Foundation)

- **Reviewer:** Claude (Opus 4.8), adversarial gate per `docs/handoff/CODEX-bank-directory-2026-07-10.md` §Autonomous-audit-gates.
- **Scope:** Phase 1 only (banks table, TN data, seeder, model, DTO, `GET /api/v1/banks`, tenant-init wiring). §4 Phase 1.
- **Branch:** `feat/bank-reference-verification` @ `d97fa7eff` (2 ahead / 4 behind `origin/dev` = `1223dcc37`).
- **Contract:** brief §3 ground rules + §5 verification + design doc `docs/superpowers/specs/2026-06-30-bank-reference-and-account-verification-design.md`.

## Diff range used (important)

The brief's gate prompt says review `git diff origin/dev..HEAD`. **That two-dot range is contaminated** for this branch: the worktree is 4 commits behind current `origin/dev` (design-system sweep + tanstack-key audit + treasury Phase ② landed after the branch's rebase point `3b4f11434`), so `origin/dev..HEAD` shows ~90 FE files as *reverse-diffs* of work that exists on dev but not here. Those are NOT Phase 1 changes.

I therefore reviewed the **true Phase 1 scope** = commit `d97fa7eff` (11 files, +500), which is exactly the Phase-1 deliverable set. Every finding below cites that commit's files. The contamination itself is logged as Finding #1 (process, non-blocking for Phase 1 code).

## Hunt-list results

| Hunt target (brief) | Result |
|---|---|
| int/float leakage in mod-97 RIB/IBAN math | **N/A for Gate 1.** No validator exists yet — `app/Shared/Banking/` is absent; no RIB/IBAN math in Phase 1. Correctly deferred to Phase 2 (progress.md §Deviations). Nothing to leak. |
| Module-boundary violation (Partner → Treasury internals) | **None.** Phase 1 adds no Partner code. `Bank` lives in `Treasury\Domain` per §4/design §3. No cross-module import. |
| Seeder non-idempotency | **Idempotent + directly tested.** See §Verified below. |
| Hard-blocking validation where warn-but-allow specced | **None.** No RIB/IBAN validator in Phase 1; `BankController::index` validates only query params (`country size:2`, `q max:100`) — appropriate. FormRequest RIB rules are a Phase 2/3 surface. |
| Missing i18n | **N/A.** No FE runtime component in Phase 1 (only the generated `BankData` type). `BankPicker`/labels are Phase 2. |
| Hardcoded colors | **N/A.** No `.tsx` added. |
| Wrong middleware tuple | **Correct.** `routes.php:32` uses `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`; `/banks` (line 33) is ungated (no `can:`), matching the §2.3 `categories`/`attachments/config` precedent. No `module:` gate — correct (reference data, both verticals). |

## Verified against code (file:line)

- **Migration** `apps/api/database/migrations/tenant/2026_07_12_110000_create_banks_table.php`
  - Columns match design/brief §4 exactly (`:19-30`); `Schema::hasTable` re-run guard (`:14-16`) per §3.13.
  - Partial unique index `banks_tenant_country_rib_code_uniq ... WHERE rib_bank_code IS NOT NULL` (`:36-40`) — valid PG, matches the cited `WHERE ... IS NOT NULL` precedent.
  - No FK on `tenant_id` — **correct** for db-per-tenant (Tenant lives in central DB; a cross-DB FK is impossible). Consistent with topology.
- **Model** `apps/api/app/Modules/Treasury/Domain/Bank.php` — `HasUuids`, boolean/int casts (`:52-59`), `scopeForTenant`/`scopeActive` (`:73-85`), full strict typing + property docblock; no default connection override (correct — default is swapped to tenant DB per-request).
- **DTO** `apps/api/app/Modules/Treasury/Application/DTOs/BankData.php` — `#[TypeScript]`, strict-typed, `fromModel` (`:25-37`). Regenerated `packages/shared/types/generated.d.ts:1965-1974` matches the DTO shape (types flow from backend per §3.7 — not hand-edited).
- **Controller** `BankController.php` — constructor-injects `CompanyContext` (`:16-18`, no `app()`, §3.4 ✔); `requireCompany()` exists (`CompanyContext.php:100`); country defaults to company country then `strtoupper` (`:27`); active + tenant + country scoping (`:31-33`); parameterized `LOWER(name)/LOWER(short_name) LIKE ?` search (`:34-40`, no SQL injection — bound); `orderBy('position')->orderBy('name')` (`:41-42`) per §4; single-wrap `['data' => ...]` envelope (`:45-47`) — correct for FE `apiGet` (no double-unwrap risk, §3.8).
- **Seeder** `BanksSeeder.php` — company-scoped, reads `country_code` (`:28`), null-safe file guard (`:30-32`), strict JSON decode with typed failure (`:64-83`). Idempotency via `updateOrCreate` keyed on `(tenant_id, country_code, rib_bank_code)` for coded banks and `(…, name)` for the 7 null-`rib_bank_code` banks (`:35-50`) — matches the DB partial-unique key and the §3.13 fallback. Always `is_custom=false`, `is_active=true` on managed rows.
- **Tenant wiring** `TenantInitializationService.php:92` — `seedBanks()` inserted before `seedPaymentRepositories`, mirrors `seedPaymentMethods` shape (`:224-228`); grouped correctly per §2.4.
- **Test** `tests/Feature/Treasury/BankDirectoryTest.php`
  - §5 idempotency: seeds twice, mutates name+bic between runs, asserts row count unchanged AND `updateOrCreate` repairs the changed fields (`:48-79`) — satisfies the §5 "changed name/bic for existing rib_bank_code" requirement precisely.
  - Ungated read + active scope: `?country=tn&q=internationale` returns exactly the 2 French-named banks, excludes a deactivated Amen (`:81-98`). Search term "internationale" (trailing-e) correctly excludes the "INTERNATIONAL" (no-e) banks — count assertion is sound.
- **TN.json** — 33 rows, one per bank, `rib_bank_code` attached for clearing-coded banks (Amen = `07` ✔ per the design's worked example), `null` for the 7 non-clearing entities (ALUBAF, Bankers Trust, BCT, BTS, NAIB, TIB, TFB). Deduped, stable `position`. France correctly absent (§6 deferred).
- **Out-of-scope respected:** no `PaymentInstrument*` files touched; no FR data; no validator/picker (Phase 2). Confirmed against `git show --stat`.

## Findings (numbered by severity)

### 1. [MEDIUM — process, blocks final merge NOT Phase 1] Branch is 4 commits behind current `origin/dev`; the recorded "rebase" was onto a now-stale tip
`bank-directory-progress.md:5` states the rebase was onto `origin/dev @ 3b4f11434`. That SHA is now only the **merge-base** — `origin/dev` has advanced to `1223dcc37` (design-system sweep, tanstack-key audit, treasury Phase ②). Consequences: (a) the gate's `origin/dev..HEAD` diff is contaminated (handled above); (b) **before Phase 2** the branch must re-sync to current `origin/dev`, because the sweep changed the FE conventions that `BankPicker` must follow (§staleness note in brief §6) and the tanstack-audit tool changed under it; (c) `generated.d.ts` was regenerated on the stale base and will need re-generation post-sync. **Not a Phase-1 code defect** — Phase 1 is backend-only and correct. Action: re-sync to `origin/dev` and for future gates diff against the phase tag / merge-base (`git diff 3b4f11434..HEAD`), not two-dot `origin/dev`.

### 2. [LOW] Re-seed reactivates admin-deactivated banks and resets `is_custom`
`BanksSeeder.php:47-48` unconditionally writes `is_active=true`, `is_custom=false` on every managed row. If an admin deactivates a seeded bank (the API filters on `is_active`, so this is a real user action) and the seeder later re-runs, the bank is silently reactivated. Currently low-risk because the seeder is only invoked once, at `initializeForNewRegistration` (`TenantInitializationService.php:92`) — not on redeploy. Flagging so it isn't wired into a repeatable deploy/backfill path later (cf. the treasury chart-seed productization work) without making `is_active`/`is_custom` preserve-on-update.

### 3. [LOW] LIKE wildcards in the `q` param are not escaped
`BankController.php:35-38` interpolates the user's `q` into `%…%` without escaping `%`/`_`. It is fully parameterized (no injection), but a user typing `%` matches all banks. Cosmetic on a ~33-row reference table; escape `%`/`_` if you want literal search semantics.

### 4. [INFO] `Bank::tenant()` belongsTo crosses DB boundaries and is unused
`Bank.php:64-67` defines `belongsTo(Tenant::class)`, but `Tenant` resolves on the central connection while `Bank` is on the per-tenant connection, so eager/lazy loading it would query the wrong DB. It is unused in Phase 1. Leave it, but do not rely on it from tenant-context code.

### 5. [INFO] Verification re-run not performed by reviewer
Per rule 12 / memory (worktree symlinked-vendor + full-suite-crash gotchas), I did **not** independently execute PHPUnit/PHPStan here; findings are by inspection. The progress doc claims GREEN (2 tests / 13 assertions, PHPStan clean, Pint clean, `typescript:transform` 430 types, `pnpm typecheck`/`lint` clean, 0 new audit findings). The test file and code are consistent with those claims. The Claude-side merge session should re-run the targeted commands on a fresh (non-symlinked) checkout before merge.

## Assessment

Phase 1 meets the §4 contract and every §3 ground rule that applies to a backend-only foundation phase. None of the gate's blocking criteria are triggered: no validator math to leak, no module-boundary breach, seeder is idempotent and tested to §5, no hard-blocking validation, no missing i18n / hardcoded colors, correct middleware tuple. The only MEDIUM is a branch-freshness/process item that must be resolved before Phase 2 and final merge but does not make any Phase 1 code wrong. Findings #2–#4 are LOW/INFO polish, not gate blockers.

VERDICT: APPROVE
