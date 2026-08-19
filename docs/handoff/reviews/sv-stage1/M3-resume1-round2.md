I ran the full verification myself. Register below.

---

## M3 review — SV-9 "blind counting ON everywhere" (resumed allowance, round 2)

**Scope held to:** brief §M3 (`docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md:384-420`), R-6 guard, R-7 migration rules, the three §5.1 acceptances, and the M3 row of the evidence contract (`:440`). M3 delta = `b73a353f5` + fix commit `41da762c1` (+ `0417f59a2` report).

**Lens applicability**
- `fiscal-pos` — **applies** (count-screen concealment policy, device cache, POS settings transport). No hash-chain, sealed-bytes, event or projection surface is in the M3 diff; the new migration writes only `company_fraud_settings`.
- `frontend-conventions` — **applies** (`FraudSettingsPage`, `i18n.ts` ar registration, ratchets).
- `tenancy-authz` — **applies** (tenant-path migration, `pos.configure_cash_count` gate).

**Verification I ran myself (not taken from the report or from round 1):**
- Real PostgreSQL, fresh DB `autoerp_sv_stage1_m3r2_test` on `127.0.0.1:5432`, `phpunit-pgsql.xml`: `BlindCashCountDefaultMigrationTest` + `CompanyFraudSettingsVerticalDefaultsTest` + `FraudSettingsResolverTest` + `FraudSettingsControllerCashControlsTest` + `FraudSettingsPosControllerTest` → **OK (21 tests, 86 assertions)**. (`php artisan test` reports these as "warnings" from an unrelated `file_get_contents` notice; raw `phpunit` shows the real result.)
- `apps/web` `FraudSettingsPage.test.tsx`: **2/2 green**, including the new Arabic render.
- `apps/pos` `migration22.integration.test.ts`: **8/8 green**, `dflt_value === '0'` guard live.
- PHPStan L8 over `app/Modules/Compliance` + `FraudSettingsResolver` + `ReportGenerationService` (34 files): **no errors**. Pint `--test` on all six touched PHP files: **pass**. ESLint on the three touched web files: **0 errors** (13 baselined warnings).
- **Empirical probe of the unasserted `->change()`** on real PG: seeded a boolean column `DEFAULT false`, ran the migration's exact `Schema::table(...)->boolean(...)->default(true)->change()`, then `INSERT ... DEFAULT VALUES`. Result: `column_default false → true`, `is_nullable NO` preserved, `data_type boolean` preserved, inserted row `true`. The default flip is real and non-destructive.

---

### Round-1 findings — disposition (all five verified closed)

| # | Round-1 finding | Verdict |
|---|---|---|
| 1 | P2 Arabic tautology | **CLOSED.** `FraudSettingsPage.test.tsx:93-116` now `changeLanguage('ar')`, renders the page, and asserts the literal Arabic label in the DOM. Non-vacuous by construction: the only source of that string is `locales/ar/compliance.json` reached through the `i18n.ts:398-410` merge; collapsing back to `compliance: enCompliance` renders English and the assertion fails. Ran green. |
| 2 | P3 device v22 edit | **CLOSED, correctly.** `migrations.ts:518` restored to `DEFAULT 0`; the test became an immutability guard (`migration22.integration.test.ts:73,90`). I re-derived the reasoning independently: `companyFraudSettingsCacheRepository.ts:41-74` is the **only** writer and binds the column explicitly in both INSERT and `ON CONFLICT`; `getCompanyFraudSettings` returns `null` pre-first-sync. The default is genuinely inert. Ticket note is accurate. |
| 3 | P3 undisclosed blast radius | **CLOSED.** `docs/superpowers/tickets/2026-08-12-sv9-blind-count-default-deploy.md:8` now states a deliberately-disabled tenant is also re-enabled, with the no-provenance-column reason. |
| 4 | P3 unrecorded gates | **CLOSED.** Report `:494-503` records PHPStan, full `pnpm lint`, ratchets, typecheck, deptrac, React Doctor. I re-ran PHPStan/Pint/ESLint/deptrac myself — consistent with the record. |
| 5 | P3 dead vertical lookup | **CLOSED.** Constructor and `CompanyVerticalQueryContract` import removed from `CompanyFraudSettingsService.php`; `ComplianceServiceProvider.php:57` reduced to `singleton(CompanyFraudSettingsService::class)`. Resolution verified green on PG via `$this->app->make()` in `CompanyFraudSettingsVerticalDefaultsTest:24`. |

### Acceptance (§5.1 SV-9) — all three, independently verified

1. **Fresh company in both verticals resolves `true`** — `CompanyFraudSettingsVerticalDefaultsTest:29,45` (Mechanic + Retail), green on PG. Backed by `CompanyFraudSettings.php:58,179` (both the `$attributes` array *and* `getDefaults()`), `FraudSettingsResolver.php:30`, `CompanyFraudSettingsData.php:105`. Grep over `app/ database/ config/` finds **no** remaining `false` for this key except the migration's `WHERE` predicate.
2. **Pre-existing `false` row is migrated** — `BlindCashCountDefaultMigrationTest:29-64`: seeds one `false` + one `true`, asserts the row flipped, asserts `changed=1 skipped=1`, re-runs, asserts `changed=0 skipped=2`. The contract's named antidote ("a migration that worked because it silently no-op'd") is satisfied in the same test. Plus a dropped-table `schema=missing` case.
3. **FE round-trip on a row-less company does not re-disable** — `FraudSettingsPage.test.tsx:66-92` green; guarded at three points (`FraudSettingsPage.tsx:39` normalizer, `:62` initial state, `:403` submit fallback), all three of which were `false` before this commit.

### Milestone gate answers (brief `:482`)

- **R-6 prerequisite check executed and recorded either way** — yes, per-touch-point table at report `:446-455`, one row per touch point, each with a stated reason, correctly concluding G-3 is not a prerequisite (G-3 backfills Treasury float/drawer accounting; none of these six surfaces reads it).
- **`TREASURY_SHIFT_VARIANCE_GL_ENABLED` untouched** — confirmed: `config/treasury.php:24` still `env(..., false)`; the diff is comment-only.
- **Migration idempotent and self-guarding** — yes: `hasTable`/`hasColumn` guards, no `catch` (R-7-compliant: no bare try/catch, no 25P02 exposure), `Log::warning` completion token with per-tenant counts, forward-only `down()`. Lives in `database/migrations/tenant/`, which `config/tenancy.php:197` scopes to `tenants:migrate`.
- **Settings surface tenant-scoped and permission-gated as before** — yes: `FraudSettingsController.php` is **not in the diff**; `Gate::authorize('pos.configure_cash_count')` unchanged.
- **Red-by-design test declared, not silently weakened** — yes: `CompanyFraudSettingsVerticalDefaultsTest:43` carries `/** SV-9 red by design: … */`, the method was renamed `..._disabled` → `..._enabled`, and report `:458-463` names it as such.
- **Six touch points** — all landed (1 defaults array + `getDefaults`; 2 `defaultsForVertical` neutered + test rewritten; 3 the migration; 4 the historical column default; 5 the FE `false` initial/fallback; 6 device default reverted with corrected reasoning).

### Standing checks

- **Rule 19** — M3 touches no money/quantity value; thresholds remain scale-4 strings; no float, no `parseFloat`, no bare `getScale()`.
- **Constructor injection / no `app()`** — the provider uses `$this->app->singleton(...)` inside a `ServiceProvider`, which is the container-registration idiom, not the forbidden `app()` helper. No `app()` added.
- **en+fr+ar** — `en`/`fr` `compliance.json` already carried `blindCountLabel`; `ar` added and registered. Device (`apps/pos`) correctly untouched for Arabic per R-5.
- **No new named queue** (no `onQueue` in the diff) → no Horizon coverage owed.
- **Red-first** — report `:457-463` documents four red observations; I could not falsify any of the three §5.1 tests by inspection or by running them.

---

## Findings register (round 2)

### 1. P3 — CONFIRMED — the `->change()` default flip is executed but never asserted
`apps/api/database/migrations/tenant/2026_08_12_100000_enable_blind_cash_count_for_existing_settings.php:45-47` · `apps/api/tests/Feature/Compliance/BlindCashCountDefaultMigrationTest.php:29`

The test proves the row backfill and its idempotence, but nothing asserts that the column default actually moved `false → true`. Because `2026_04_25_000004:19` now also creates the column with `default(true)`, a fresh test DB cannot distinguish "the change worked" from "the change was a no-op".

**Failure scenario (latent):** a future Laravel upgrade or a `change()` semantics shift silently drops the default modifier; an existing tenant DB keeps `DEFAULT false`; any raw-SQL insert path (a future migration's data seed, a support fix, an import) that omits the column writes `false` and re-disables blind counting for that company, with a green suite.

**Mitigation already established:** I verified the behaviour empirically on PG this round (probe above) — `column_default` becomes `true`, NOT NULL and type preserved, and a `DEFAULT VALUES` insert yields `true`. No code change required; one `information_schema.columns` assertion in the existing test would close it permanently.

### 2. P3 — CONFIRMED — `CompanyVerticalQueryContract` is now fully orphaned
`apps/api/app/Shared/Contracts/Company/CompanyVerticalQueryContract.php:7` · `apps/api/app/Modules/Company/CompanyServiceProvider.php:18` · `apps/api/app/Modules/Company/Infrastructure/Services/CompanyVerticalQueryService.php:10`

Removing the last consumer (round-1 finding 5's fix) leaves the interface, its implementation and its container binding with **zero** callers anywhere in `app/`, `database/` or `tests/` (grep over the whole `apps/api` excluding `vendor/`). `defaultsForVertical(bool $_isAutomotive)` survives as a pass-through called only by the 2026-04-25 seed migration, which computes `$isAutomotive` itself from `$company->tenant?->vertical`.

This is the correct trade for this wave — deleting a `Shared/Contracts` interface is out of M3's scope fence — but it is dead weight that will read as live architecture to the next person. Note only; no failure scenario. Belongs in M5's carried notes or a ticket.

### 3. P3 — CONFIRMED — deptrac ratchet FAILs on this branch; the wave contributes zero of it
`apps/api/deptrac.baseline.json` · `.github/workflows/ci.yml:178`

I ran `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json`: **TOTAL 116 vs baseline 99, RESULT: FAIL**, including a `BLOCKER — new Domain-tier leakage: ModuleDomain on ModuleApplication`. The CI step at `ci.yml:178` will therefore be red when this branch lands.

I confirmed the wave adds none of it, per-file rather than by comparing totals: the JSON report attributes violations to only two of the wave's touched `app/` files — `FraudSettingsResolver.php:14` (constructor dependency, file **not in the diff at all**) and `ReportGenerationService.php:59` (constructor dependency; the diff touches only docblocks at `:510` and `:969`). `CompanyFraudSettings.php`, `CompanyFraudSettingsService.php` and `ComplianceServiceProvider.php` have **zero** violations — and the fix commit *removed* a `Shared/Contracts` edge, which can only reduce the count. Pre-existing repo debt with a stale checked-in baseline; the report discloses it at `:502`. Out of M3's scope to fix; flagging so M5/the owner do not discover a red CI at promotion.

### 4. P3 — CONFIRMED — pre-existing red in `apps/web` i18n coverage; do not later claim "apps/web vitest green"
`apps/web/src/__tests__/i18n/arLocaleCoverage.test.ts:83-85`

`npx vitest run src/__tests__/i18n` → **3 failed / 7 passed**: `ar/common.json` (122 missing keys), `ar/workshop-technicians.json`, `ar/vehicles.json`. None of these three files is touched by the wave (the only web locale change is the new `ar/compliance.json`), and `compliance` is **not** enumerated by that test — so registering an intentionally single-key `ar/compliance.json` does not trip it, correctly.

The brief's "tests by path" rule (`:468`) means M3 is compliant. The note is for M5: the wave declared `apps/web` vitest in its regression set at M0, and that suite is red at HEAD for reasons predating the wave. Any M5 statement must scope the claim to paths, not to the suite.

---

### Bypasses I attempted that FAILED (the implementation held)

| Attempt | Result |
|---|---|
| Prove the new Arabic test is still vacuous | It renders `FraudSettingsPage` under `lng: 'ar'` through the real i18next instance and asserts the literal Arabic string in the DOM. The string's only source is `ar/compliance.json` via the `i18n.ts:398-410` merge. Ran green; collapsing the merge necessarily renders the English fallback. Non-vacuous. |
| Prove the round-trip test was weakened by the fix | It was **strengthened**: `abandoned_draft_threshold` changed to a distinctive `7` and the test now waits for that value in the DOM before clicking save, plus waits for the invalidation refetch. It can no longer pass on initial state. |
| Find a device path where `DEFAULT 0` is reachable | `grep company_fraud_settings_cache` across `apps/pos/src` → exactly one writer (`companyFraudSettingsCacheRepository.ts:41-74`), which binds the column in both the INSERT list and the `ON CONFLICT` SET. `Header.tsx:130,152,197` and `fraudSettingsApi.ts:39` all pass an explicit value. Default unreachable. Round-1's fix is correct. |
| Find a blind-count `false` still alive on the server | grep `app/ database/ config/` — every occurrence is `true`; the sole `false` is the migration's `WHERE` predicate. Seeders and factories: no occurrence at all. |
| Break the `->change()` (dropped NOT NULL / dropped type / no-op default) | Empirical PG probe: `false → true`, `is_nullable NO`, `boolean`, and `INSERT DEFAULT VALUES` yields `true`. Held. |
| Make the migration run outside a tenant | `config/tenancy.php:197` scopes `database_path('migrations/tenant')` to `tenants:migrate`; `AppServiceProvider.php:211-226` documents it is deliberately not on the default path. If it ever did run centrally, the `hasTable` guard logs `schema=missing` and returns — proven by `BlindCashCountDefaultMigrationTest:66`. |
| Find a permission / route / tenancy regression | `FraudSettingsController.php` is not in the diff; `Gate::authorize('pos.configure_cash_count')` and the cash-control key set unchanged. No route file, middleware, or permission seeder in the M3 diff. |
| Find a Rule-19 float violation | M3 touches no money or quantity value. |
| Find a new `app()` helper or a broken binding | `$this->app->singleton(CompanyFraudSettingsService::class)` — provider idiom, not the helper; resolution proven green on PG through `$this->app->make()`. |
| Find a PHPStan / Pint / ESLint regression in the new code | PHPStan L8 over 34 files: no errors. Pint `--test`: pass. ESLint on the three touched web files: 0 errors. The one `react-hooks/set-state-in-effect` warning at `FraudSettingsPage.tsx:81` pre-dates the diff (the line was already `setFormData(...)` inside the same effect). |
| Find an unlisted named queue | No `onQueue` added. |
| Prove the deptrac regression belongs to this wave | Attributed per-file: zero violations on the three files the wave actually modified; the two hits are on untouched-or-docblock-only files. Pre-existing. |

All three §5.1 acceptances are met and independently verified on real PostgreSQL and real DOM. Every round-1 finding is closed with the fix I would have asked for. The four remaining items are P3 notes, none of which is a merge condition for this milestone.

VERDICT: ACCEPT
