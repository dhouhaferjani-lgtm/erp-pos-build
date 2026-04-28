# Q2 2026 Release — Post-Merge Deferred Items

**Date:** 2026-04-28
**Released to main:** PR #58 (merge commit `50055029`)
**Owner:** TBD per item

---

## Overview

The dev → main merge of 2026-04-28 shipped 548 commits across the AutoSpecs, Payment Tolerance v2, POS, and Test-Rehab clusters. Pre-merge audits ran on every major PR plus a dedicated cross-cluster audit before the dev → main PR; all CRITICAL and HIGH findings were remediated pre-merge. The items below are MEDIUM, LOW, and INFO findings that the team consciously deferred so the release could ship without scope creep.

These items are **not bugs in production today** — they're a mix of:
- Latent correctness gaps that surface only in narrow edge cases (e.g., Workshop converter bypass)
- Architectural drift that doesn't break user flows
- Test-suite hygiene
- Pre-existing issues that predate this release

Each section is ordered by priority within its category. Status fields can be edited inline (`To-do` / `In progress` / `Done` / `Cancelled`).

---

## Section 1 — Material follow-ups (real correctness or design)

### M1. Workshop `DocumentGenerationAdapter` bypasses Phase 4 auto-strip

| Field | Value |
|---|---|
| **Origin** | Pre-merge dev → main audit (Section B, Medium) |
| **Status** | To-do |
| **Owner** | TBD |
| **Effort** | Medium (~½–1 day, requires design + audit) |

**What.** `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:66-79,128-150` — `generateInvoice` builds an Invoice directly and calls `DocumentPostingService::post`, bypassing both `SalesOrderToInvoiceConverter` AND `StripSubToleranceDiscountsService`. A WO Invoice with a quote-stage sub-tolerance discount (e.g., €0.20) is NOT stripped → survives into the fiscal hash chain, defeating Phase 4 §7's anti-abuse rule.

**Why deferred.** Not a release blocker — only manifests if a Workshop tenant uses sub-tolerance discounts on a quote that converts to invoice via the WO path (rare today). Fix requires a design call (route through converter chain vs. inline `StripSubToleranceDiscountsService` call) plus tests; deserves its own audit cycle.

**Recommended approach.** Either:
1. Refactor WO invoicing to route through `SalesOrderToInvoiceConverter` so the strip + recalc path is shared.
2. Inject `StripSubToleranceDiscountsService` into `DocumentGenerationAdapter` and invoke before `DocumentPostingService::post`.

Option 1 is cleaner long-term; Option 2 is faster. Add a regression test: WO Quote with €0.20 line discount → convert to Invoice → assert discount stripped, line_total recomputed, `DocumentLineDiscountStrippedAtConversion` event dispatched.

---

### M2. `LoyaltyMember::customer()` migration to `loyaltyable` morph

| Field | Value |
|---|---|
| **Origin** | Pre-merge audit LOW E2 (deletion blocked) |
| **Status** | To-do |
| **Owner** | TBD |
| **Effort** | Small–medium (FE/DTO ripple) |

**What.** `apps/api/app/Modules/Loyalty/Domain/Entities/LoyaltyMember.php:88` — `customer()` is `@deprecated` but has a live caller at `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyMemberController.php:82`: `LoyaltyMember::with(['enrollments.program', 'customer'])->findOrFail($id)`. Likely also referenced in `LoyaltyMemberData::fromModel()` and one or more frontend consumers.

**Why deferred.** Removal requires migrating consumers to the canonical `loyaltyable` polymorphic relation. Audit's "zero callers" claim was wrong; we caught it in remediation.

**Recommended approach.** Migrate the controller eager-load to `loyaltyable`, verify `LoyaltyMemberData::fromModel()` and any TS consumers, then delete the deprecated relation in a follow-up commit. Add a contract test ensuring the API response shape doesn't change (camelCase / DTO key compatibility).

---

### M3. Stamp duty draft guard

| Field | Value |
|---|---|
| **Origin** | Test rehab session (PR #55) |
| **Status** | To-do |
| **Owner** | TBD |
| **Effort** | Small (production fix + test un-skip) |

**What.** `TaxCalculationService` applies document-level taxes purely by `fiscal_category`, with no `DocumentStatus` guard, so a draft `TAX_INVOICE` receives the same 1.000 TND stamp duty the posted version would. The test `Tests\Feature\Taxation\TaxCalculationTest::test_it_does_not_apply_stamp_duty_for_drafts` is currently `markTestSkipped` with rationale.

**Why deferred.** Fiscal-compliance call: should drafts be excluded from stamp duty? Needs product / accounting input before code change.

**Recommended approach.** Confirm with accounting that drafts should be excluded (typical for stamp duty in TN/FR). Add a `DocumentStatus` guard inside `TaxCalculationService::applyDocumentLevelTaxes` (or wherever the stamp duty branch lives). Un-skip the test.

---

### M4. `unit_categories` partial-unique-index gap

| Field | Value |
|---|---|
| **Origin** | Test rehab session (PR #57); pre-merge audit Cat D |
| **Status** | To-do |
| **Owner** | TBD |
| **Effort** | Small (one migration + duplicate cleanup) |

**What.** `apps/api/database/migrations/2026_01_09_095018_create_unit_categories_table.php:30` — `unique(['tenant_id', 'code'])` constraint. PostgreSQL treats each NULL as distinct, so system-level rows (`tenant_id IS NULL`) can have duplicate `code`. Re-running the UoM seeder really can produce duplicate weight/volume system rows.

**Recommended approach.** New migration that:
1. Deletes duplicate `(NULL, code)` system rows (keep the earliest by `created_at`).
2. Drops the existing combined unique index.
3. Creates two partial unique indexes: one for `WHERE tenant_id IS NULL`, one for `WHERE tenant_id IS NOT NULL`.

---

### M5. Two parallel `FraudSettings` shapes

| Field | Value |
|---|---|
| **Origin** | Pre-merge audit Cat B Medium |
| **Status** | To-do |
| **Owner** | TBD |
| **Effort** | Small (decision + delete loser) |

**What.** Two parallel TypeScript types for fraud settings:
- Hand-written: `apps/web/src/features/compliance/types/fraud.ts:1-21` (snake_case + extra fields)
- Generated: `packages/shared/types/generated.d.ts:875-884` `FraudSettingsDTO` (camelCase + cash-variance subset)

PR #36's drift guard catches file-level regen drift but cannot detect this kind of shadow. Generated DTOs are not currently imported anywhere in `apps/web` or `apps/pos` — the generated namespace is dead-on-consumer for this type.

**Recommended approach.** Decide canonical (likely the generated `FraudSettingsDTO`); delete `fraud.ts`; migrate consumers. If FE keeps hand-written for legitimate reasons, document why and exclude from drift expectation.

---

## Section 2 — Hygiene follow-ups

### H1. `assertJsonValidationErrors` envelope mismatch

| Field | Value |
|---|---|
| **Origin** | Test rehab session |
| **Status** | To-do |
| **Owner** | TBD |
| **Effort** | Medium (sweep + helper) |

**What.** The application wraps validation failures in `{ error: { code, errors } }`, but Laravel's stock `assertJsonValidationErrors` reads the top-level `errors` key. Other tests across the repo using the standard helper would silently miss validation errors against this envelope.

**Recommended approach.** Add a project-specific helper `assertJsonValidationErrors($response, $errors)` that reads from the `error.errors` envelope. Sweep tests to migrate. Or document the convention in `CLAUDE.md` and rely on per-test discipline.

---

### H2. `copyLine()` whitelist + `DocumentLine::$fillable` missing `work_order_line_id`

| Field | Value |
|---|---|
| **Origin** | Pre-merge audit Cat B Medium |
| **Status** | To-do |
| **Owner** | TBD |
| **Effort** | Small (whitelist + fillable + backfill plan) |

**What.** `apps/api/app/Modules/Document/Domain/Services/Conversion/Concerns/CopiesDocumentData.php:113-130` whitelist omits `work_order_line_id`; column also missing from `DocumentLine::$fillable` so direct mass-assignment in `DocumentGenerationAdapter::mapLine()` is silently dropped under default mass-assignment guarding. WO ↔ DocumentLine traceability is broken end-to-end.

**Why deferred.** Pre-existing latent bug, NOT introduced by this release.

**Recommended approach.** Add `work_order_line_id` to both `$fillable` and the `copyLine` whitelist. Backfill any WO-generated invoices that need lineage retroactively (separate task).

---

### H3. Compliance imports POS Domain classes (Rule #6 violation)

| Field | Value |
|---|---|
| **Origin** | Pre-merge audit Cat E Low |
| **Status** | To-do |
| **Owner** | TBD |
| **Effort** | Medium (architectural cleanup) |

**What.** `apps/api/app/Modules/Compliance/Presentation/Controllers/Nf525ExportController.php:9-14` and `Nf525JetExportService.php:9-15` import 13 POS Domain classes including two POS Domain Services. Cross-module Domain imports should go via `Shared/Contracts/` interfaces, Events, or public Application services (Rule #6).

**Why deferred.** Pre-existing baseline drift; release adds no NEW edges of this severity.

**Recommended approach.** Define `App\Shared\Contracts\Compliance\Nf525DataProviderContract` (or similar) on the POS side; have Compliance depend on the contract. Move data-extraction logic to a POS Application service that implements the contract.

---

### H4. `preflight.sh` PHPStan memory regression

| Field | Value |
|---|---|
| **Origin** | Mediums/lows remediation session note |
| **Status** | Done (session1, commit `4751b648`) |
| **Owner** | session1 |
| **Effort** | One-line fix |

**What.** `scripts/preflight.sh:28` — PR #36 (`b6603304`) silently reverted commit `80d497e8`'s bump of PHPStan memory limit from 512M back to 2G. CI's PHPStan job runs with its own memory config and is fine, but local `./scripts/preflight.sh` OOMs on PHPStan.

**Recommended approach.** One-line edit: bump `--memory-limit` back to `2G` in `scripts/preflight.sh:28`.

---

## Section 3 — Lower-priority follow-ups

### L1. 13 missing `Schema::hasColumn` guards

`add_*_to_*` migrations missing idempotency guards. Full list per pre-merge audit Cat A Low. All add nullable or default-valued columns; safe on first execution but error on re-run. Document the convention going forward; not retrofitting.

### L2. `2026_04_23_000001` missing `dropUniqueIfExists` guard

Pre-cleanup of duplicate `shift_id` rows runs but no `dropUniqueIfExists` guard — re-run would fail. One-shot only.

### L3. Coupon + Promotion date-dependent test exclusions

`tests/Unit/Coupon/CouponValidationServiceTest.php` and `tests/Unit/Promotion/PromotionEvaluationServiceTest.php` excluded from CI in `phpunit.xml` for date-dependent failures. Worth a date-injection refactor when convenient.

### L4. 24 skipped Taxation Feature tests

Pre-existing skips + 1 new (stamp duty draft — see M3). Worth a triage to decide which can be re-enabled vs. retire.

### L5. PHPStan errors in `TechnicianTimeEntryControllerTest`

11 errors (Mockery property access, parameter type missing) in `apps/api/tests/Feature/Workshop/Technician/TechnicianTimeEntryControllerTest.php`. Outside the project's PHPStan config scope (`app/` only) so doesn't break preflight, but worth cleaning up.

### L6. ReportsController `// TODO`s

`apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php:143,298,449` — three `// TODO: Fix hierarchy balance calculation` comments; `include_hierarchy=true` silently no-ops. Pre-dev TODO. Document for downstream consumers if relevant.

### L7. `DailyExpiryCheck` `// TODO`s

`apps/api/app/Modules/BatchExpiry/Jobs/DailyExpiryCheck.php:64,196` — `// TODO: Send notifications` / `// TODO: Send alert to system administrators`. Job collects state but never notifies. Pre-dev. Backlog.

### L8. Unbatched `Company::all()` in 2026-03-24 backfill

**Status:** Done (session1, commit `539045bb`). `Company::all()` replaced with `Company::query()->chunk(50, ...)`; per-company logic extracted to `backfillCompany()`. Idempotency preserved (each branch short-circuits when target state already reached).

`2026_03_24_200000_backfill_tunisian_payment_repositories_and_gl_purposes.php:31` — unbatched. Realistic company count is small so practically OK; convert to `Company::chunk(50, ...)` in a future cleanup pass.

### L9. Per-distinct-value UPDATE in vehicle sanitize migration

`2026_04_19_100003_1_sanitize_vehicle_enum_values.php:40-44,50-54` — one `UPDATE vehicles SET fuel_type=? WHERE fuel_type=?` per legacy value, unbatched. Per-distinct-value (not per-row) so PG handles it for any reasonable fleet. No action.

### L10. Node.js 20 → Node.js 24 actions migration

**Status:** Done — PR #59 (merged 2026-04-28 to `dev`, merge SHA `905106f5`).

| Action | Old | New | Path |
|---|---|---|---|
| `actions/checkout` | v4 | **v5** | bumped (Node 24) |
| `actions/cache` | v4 | **v5** | bumped (Node 24) |
| `actions/setup-node` | v4 | **v5** | bumped (Node 24) |
| `pnpm/action-setup` | v4 | **v5** | bumped (Node 24) |
| `codecov/codecov-action` | v4 | **v5** | bumped (composite, OS binary) |
| `actions/upload-artifact` | v4 | v4 | env-var fallback — v5 still on Node 20; v6 introduces artifact-immutability semantics not bundled here |
| `shivammathur/setup-php` | v2 | v2 | already Node 24 native (verified action.yml head) |
| `SonarSource/sonarcloud-github-action` | master | master | composite (Sonar scanner CLI), not a Node JS action |

Workflow-level `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24: 'true'` added to all three workflows (`ci.yml`, `smoke-test.yml`, `sonarcloud.yml`) as the in-repo, reviewable belt-and-braces fallback for the residual `actions/upload-artifact@v4` and any future addition we might forget to bump.

**Follow-up to schedule before 2026-06-02:** bump `actions/upload-artifact@v4` to `@v6` (first Node 24 major). v6 changes artifact naming + adds immutability — small contract test recommended on the smoke-test workflow's `playwright-report/` and `test-results/` upload.

---

## Status tracking template

When picking up an item, change its `Status` field to `In progress` and assign an `Owner`. When closing, change to `Done` with the PR number that closed it. Use `Cancelled` if the team decides to abandon (with a one-line rationale).

| Item | Status | Owner | PR # | Notes |
|---|---|---|---|---|
| M1 — Workshop converter bypass | Done | session4 | dev `de4533e2` | Option 2 (inject `StripSubToleranceDiscountsService`) — adapter now derives `discount_amount` from WO `discount_percent` and strips before posting; regression suite covers €0.20 strip + €5 above-tolerance preserve |
| M2 — LoyaltyMember morph migration | Done | session5 | — | Closed by 858d872c on session5-types-m2-m5; wire-format byte-stable, zero FE migration needed |
| M3 — Stamp duty draft guard | To-do | — | — | Needs accounting input |
| M4 — `unit_categories` partial unique | To-do | — | — | Migration + duplicate cleanup |
| M5 — FraudSettings shape decision | Done | session5 | — | Closed by d8c1addc on session5-types-m2-m5; canonical CompanyFraudSettingsData DTO + 5-consumer FE migration; latent is_configured gap on update/reset closed in passing |
| H1 — JsonValidationErrors envelope | To-do | — | — | Sweep needed |
| H2 — `work_order_line_id` whitelist | Done | session4 | dev `de4533e2` | `DocumentLine::$fillable` + `CopiesDocumentData::copyLine()` whitelist + property docblock; backfill migration `2026_04_28_120000_*` committed unstaged pending owner approval |
| H3 — Compliance Rule #6 cleanup | To-do | — | — | Architectural |
| H4 — preflight.sh PHPStan memory | Done | session1 | dev `8bb3cca3` | `--memory-limit` restored to `2G` in `scripts/preflight.sh` (commit `4751b648`) |
| L1–L4, L6, L7, L9 | To-do | — | — | Bundle when convenient |
| L5 — PHPStan in TechnicianTimeEntryControllerTest | Done | session4 | dev `de4533e2` | All 11 errors across `tests/Feature/Workshop/` swept (Mockery typing + missing array param types + redundant assertIsString); `phpstan analyse tests/Feature/Workshop/` returns zero errors |
| L8 — TN backfill `Company::all()` chunking | Done | session1 | dev `8bb3cca3` | `Company::query()->chunk(50, ...)` + `backfillCompany()` extraction (commit `539045bb`) |
| L10 — Node 24 actions migration | Done | session7 | #59 | All 5 listed actions bumped to v5; `actions/upload-artifact@v4` covered by `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24=true` env var pending v6 follow-up |

---

## Out-of-scope notes

- **Refund flows in IziPOS** — separate workstream; planning underway by user.
- **Pre-existing `feature/phase-3.1-finance-reports` branch** (22 unique commits, Dec 2025) — see branch-cleanup decision elsewhere.
- **PR #1 ("Full Codebase Review", Dec 2025)** — likely abandoned baseline PR; consider closing.
