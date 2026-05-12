# PR #117 — POS menu multi-category fixture — Codex review trail

**Branch:** `feat/api-menu-multi-category-fixture`
**Base:** `dev`
**Reviewer:** Codex CLI (`codex review --base dev`)
**Final state:** 2 rounds, APPROVE.

This PR adds a standalone backend seeder for the POS C2 audit-phase smoke: one Menu-capable tenant with the same sellable product listed in two menu categories at distinct override prices.

## Round 1 — APPROVE

> The changes add a focused seeder and coverage for the intended multi-category menu fixture, and the targeted test passes. I did not identify any discrete regression or actionable correctness issue in the diff.

No findings. PR ready for merge.

## Round 2 — APPROVE

> I did not identify any discrete correctness, security, performance, or maintainability issue introduced by this diff. The added fixture test passes locally.

No findings. PR ready for merge.

## Final shape

- **3 commits**.
- **4 files changed**:
  - `apps/api/database/seeders/MenuTenantMultiCategoryFixture.php`
  - `apps/api/tests/Feature/POS/MenuTenantMultiCategoryFixtureTest.php`
  - `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php`
  - `docs/superpowers/reviews/2026-05-11-pos-menu-multi-category-fixture-codex-rounds.md`
- API gates:
  - red first: `./vendor/bin/phpunit tests/Feature/POS/MenuTenantMultiCategoryFixtureTest.php` failed because `Database\Seeders\MenuTenantMultiCategoryFixture` did not exist.
  - `./vendor/bin/phpunit tests/Feature/POS/MenuTenantMultiCategoryFixtureTest.php` — 1/1 pass, 11 assertions.
  - `./vendor/bin/pint --test` — pass.
  - `./vendor/bin/phpstan analyse --memory-limit=2G` — pass.
  - direct local PostgreSQL seeding reached the seeder but timed out connecting to `127.0.0.1:5433`; the seeder path is covered by the feature test.

## Pre-flight audit

- **L1 cross-tenant audit:** Menu tenants are covered by a `coffee_shop` tenant whose default modules include `Menu`; standard-retail, hybrid, and non-Menu tenants are unaffected because no shared/default seeder, controller, or POS sync path is modified.
- **L8 ownership audit:** no screen or state-transition ownership changes; this is a backend fixture and test only.
- **L9 ingress audit:** audited standalone seeder writes, default seeding ingress (`DatabaseSeeder`, `DemoTenantSeeder`, `CoffeeShopSeeder`, `ParapharmacySeeder`), POS API ingress, and POS wire/cache ingress. No existing ingress path was changed.
- **Fixture shape:** creates one active default menu, categories `Drinks` and `Lunch combos`, and a single product-backed `Coca` sellable listed in both categories with `override_price` values `1.5000` and `3.0000`.
