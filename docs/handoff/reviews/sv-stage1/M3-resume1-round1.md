## M3 review — SV-9 "blind counting ON everywhere" (round 1, resumed allowance)

**Scope held to:** brief §M3 (`docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md:384-420`), its R-6 guard, R-7 migration rules, the three §5.1 acceptances, and the M3 row of the evidence contract (`:440`).

**Lens applicability**
- `fiscal-pos` — **applies** (count-screen concealment policy + device cache). No hash-chain, sealed-bytes, event or projection surface is in the M3 diff; verified no `fiscal_events`/receipt/shift/`z_reports` row is written by the new migration.
- `frontend-conventions` — **applies** (`FraudSettingsPage`, i18n registration, ratchets).
- `tenancy-authz` — **applies** (tenant-dir migration, `pos.configure_cash_count` gate).

**Verification I ran myself** (not taken from the report):
- `tests/Feature/Compliance/BlindCashCountDefaultMigrationTest.php` + `Unit/Compliance/CompanyFraudSettingsVerticalDefaultsTest.php` + `Unit/POS/FraudSettingsResolverTest.php` on **real PostgreSQL** (`autoerp_sv_stage1_m3r1_test`, 5432): **10 tests / 32 assertions, OK**. `RefreshDatabase` runs the new migration through the real migrator, so the unattended `tenants:migrate` path is exercised, not just `up()`.
- `apps/web` `FraudSettingsPage.test.tsx`: **2/2 green**. `apps/pos` `migration22.integration.test.ts`: **8/8 green**.
- Web ratchets: `audit:keys` 0 new, `audit:design-system` 738 baselined / **0 new**, `audit:quantity` 0 new.
- PHPStan on the touched `app/` files: **OK, no errors**.

---

### 1. P2 — CONFIRMED — Arabic deliverable is proven by a tautology; the i18n registration is untested
`apps/web/src/features/compliance/pages/FraudSettingsPage.test.tsx:88-101` · `apps/web/src/lib/i18n.ts:398-410`

The test named *"ships the blind-count label in English, French, and Arabic"* asserts `arCompliance.fraudSettings.cashControls.blindCountLabel === '…'` — i.e. it re-states the JSON file it just imported. It never renders under `ar`, never resolves through i18next, and therefore proves nothing about the hand-rolled three-level deep merge added at `i18n.ts:398-410`, which is the only thing that makes the Arabic string reachable.

I confirmed no other test covers it: `src/__tests__/i18n/arLocaleCoverage.test.ts:16-33` enumerates nine AutoSpecs namespaces and **does not include `compliance`**, and no other test file references `locales/ar/compliance.json`.

**Failure scenario:** a later cleanup collapses `compliance: { ...enCompliance, ...arCompliance, fraudSettings: {…} }` back to `compliance: enCompliance` (the shape every neighbouring namespace uses, and the shape it had before this commit). The whole suite stays green, and Arabic admins silently get the English label back. This is precisely the failure class the wave's own evidence contract names ("Asserting on i18n **keys** instead of rendered text"), and it lands on an explicit M3 deliverable ("apps/web strings en+fr+ar", progress yaml M3 title). The repo already has the right pattern: `src/features/partners/PartnerListArabic.test.tsx` renders under `ar` and asserts the DOM.

**Fix:** one `i18n.changeLanguage('ar')` + render + `screen.getByText('طلب جرد أعمى …')` assertion.

### 2. P3 — CONFIRMED — device touch point 6: the stated reasoning is wrong, and editing a shipped migration diverges fresh vs. field devices
`apps/pos/src/lib/db/migrations.ts:518` · `apps/pos/src/lib/db/__tests__/migration22.integration.test.ts:89-91` · report `docs/sessions/codex-sv-stage1-report.md:481`

The report claims *"The device cache schema default is 1 for pre-first-sync operation."* No code path exercises that default. The sole production insert is `companyFraudSettingsCacheRepository.ts:41-72`, which lists and binds `require_blind_cash_count` explicitly (`:45`, `:68`); and pre-first-sync there is **no row at all** — `getCompanyFraudSettings` returns null, `Header.tsx:78` holds `fraudSettings = null`, and `EndOfDayPreviewModal.tsx:93` gates the section on `fraudSettings != null`. The column DEFAULT is inert today.

Separately: v22 is long shipped (the device is at v67, `migrations.ts:2163`), and the list is otherwise strictly append-only with no immutability guard in `apps/pos/src/lib/db/`. Every device already past v22 keeps `DEFAULT 0`; only fresh installs get `1`. The new assertion sits in the test case titled *"applies cleanly to a **fresh** DB"*, so it certifies a schema no field device has.

**Failure scenario (latent):** any future insert path that omits the column — a partial-sync writer, a repair routine, a new migration's data seed — yields `require_blind_cash_count = 0` on upgraded devices and `1` on fresh ones. On the upgraded fleet that renders expected cash before Commit Counts: an SV-10-class blind-mode leak, on exactly the devices that are in production, with a green test suite.

**Fix (cheap):** state the accurate reasoning (the default is unreachable; the concealment contract is enforced by the always-bound upsert and the null-gate), and either revert the v22 edit or add a v68 that brings upgraded devices to the same default.

### 3. P3 — CONFIRMED — the migration flips *every* `false` row, not the seed-authored ones R-7 names; undisclosed
`apps/api/database/migrations/tenant/2026_08_12_100000_enable_blind_cash_count_for_existing_settings.php:41-43`

R-7 scopes the migration to *"rows already persisted `false` by the one-off 2026-04-25 seed."* The implementation is `where('require_blind_cash_count', false)->update([… => true])` — no discriminator. An admin **can** persist `false` deliberately: `FraudSettingsController.php:120` validates `require_blind_cash_count` and `:146` writes it via `updateOrCreate`, behind `Gate::authorize('pos.configure_cash_count')` (`:104`).

**Failure scenario:** a tenant that intentionally disabled blind counting has it silently re-enabled on the next `origin/dev` promotion (staging auto-deploys `tenants:migrate`), with no audit trail and no warning — and the same migration also moves the column default to `true`, so it will not come back on its own. Neither the report (`:476`) nor `docs/superpowers/tickets/2026-08-12-sv9-blind-count-default-deploy.md` discloses that admin-set values are in the blast radius.

I am **not** asking for a code change — "ON everywhere" is the ruling, and R-7's bullet reads primarily as a *table*-scoping instruction (don't touch shift/receipt/fiscal rows), which the implementation honours. What is missing is the disclosure: one sentence in the deploy ticket.

### 4. P3 — CONFIRMED — three binding quality gates have no record; I re-ran two of them and they are clean
brief `:469` · report `docs/sessions/codex-sv-stage1-report.md:483-488`

The brief makes `phpstan` level 8 on touched files, web `pnpm lint`, and `deptrac` no-regression binding every milestone. The report's Green-evidence block records Pint, "focused ESLint", React Doctor and typecheck — and **zero** mention of PHPStan, `pnpm lint` (which is `lint:eslint` + three ratchets + `test:eslint-rules`, not focused ESLint), or deptrac. `grep -ni phpstan` over the whole report returns nothing.

This is an **evidence** gap, not a defect: I ran PHPStan on the touched `app/` files (`CompanyFraudSettings.php`, `CompanyFraudSettingsService.php`, `FraudSettingsResolver.php`) → **OK, no errors**, and the three web ratchets → **0 new** on all. deptrac I did not run.

**Fix:** record the runs. (Note for the record: passing `tests/` or `database/` to PHPStan surfaces 5 errors, but `phpstan.neon:6-7` scopes `paths: app/`, so they are outside the project's analysed set; the three `Log::shouldHaveReceived` hits have wide precedent, e.g. `tests/Feature/Accounting/BackfillChartPurposesMigrationTest.php`.)

### 5. P3 — CONFIRMED — dead vertical lookup retained on every company creation
`apps/api/app/Modules/Compliance/Application/Services/CompanyFraudSettingsService.php:25` · `apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php:198`

`ensureForCompany` still calls `$this->companyVerticalQuery->isAutomotive($companyId)` and passes the result to `defaultsForVertical(bool $_isAutomotive)`, which now ignores it entirely. The brief permitted "dead or inverted", so this is in scope — but a cross-module contract query is now executed for no effect on every company creation, and the `$_` parameter prefix is not a convention used elsewhere in this codebase. Note only; no failure scenario.

---

### Bypasses I attempted that FAILED (the implementation held)

| Attempt | Result |
|---|---|
| Find a blind-count default still `false` anywhere in `apps/api` | grep over `app/ database/ config/` — every occurrence is `true`; the only `false` is the migration's `WHERE` predicate. Clean. |
| Break acceptance #3 via an unnormalized `undefined` reaching the toggle | `CashDrawerControlsSection.tsx:52,68,99` seeds state from its `value` prop, built at `FraudSettingsPage.tsx:400-406` with `?? true`. No `undefined` path. |
| Prove the FE round-trip test vacuous | Ran it: green. Both guards (`:39` normalizer, `:403` submit) were `false` before this commit, so removing either makes it red. Non-vacuous. |
| Prove the migration "worked by silently no-op'ing" (the contract's named antidote) | Test seeds one `false` + one `true`, asserts `changed=1/skipped=1`, re-runs, asserts `changed=0/skipped=2`, plus a dropped-table `schema=missing` case. Ran on real PG: 10/32 OK. Antidote satisfied. |
| Find a PHPStan violation in new code | Clean once scoped to the project's `paths: app/`. |
| Find a permission/route/tenancy regression | `Gate::authorize('pos.configure_cash_count')` (`FraudSettingsController.php:104`) unchanged; no route, middleware, or permission file in the M3 diff; migration lives in `database/migrations/tenant/` and is tenant-scoped. |
| Find a Rule-19 float violation | M3 touches no money/quantity value; thresholds remain scale-4 strings. |
| Find an unlisted named queue | No `onQueue` added. |
| Find a device migration-immutability guard the v22 edit would trip | None exists. |
| `->change()` unsafe/unattended | Laravel 12 (no dbal needed); 22 prior `->change()` migrations, including the near-identical `2026_04_28_130000_default_smart_prompts_variant_to_off.php`. Executed through the real migrator in my PG run. |

**Gate answers (brief `:482`):** prerequisite check executed and recorded — **yes**, per-touch-point table at report `:450-461`, correctly concluding G-3 is not a prerequisite; `TREASURY_SHIFT_VARIANCE_GL_ENABLED` untouched — **confirmed**. Migration idempotent and self-guarding — **yes**. FE round-trip stops re-disabling — **yes, verified green**. Settings surface tenant-scoped and permission-gated as before — **yes**. Red-by-design test declared, not silently weakened — **yes** (`CompanyFraudSettingsVerticalDefaultsTest.php:43` docblock + report `:465`).

All three §5.1 acceptances are met and independently verified. What blocks is finding **1**: the Arabic half of an explicit M3 deliverable is certified by an assertion that cannot fail, over wiring that has no coverage.

VERDICT: CHANGES-REQUIRED
