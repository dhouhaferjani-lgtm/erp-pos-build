# Bank Directory (phases 1–3) — Deploy Checklist

> Added at final review (2026-07-12) — the branch shipped with NO existing-tenant seeding step documented; this is the same gap class that broke the Phase-② staging smoke (chart accounts). Model: `treasury-phase2-deploy-checklist.md` §2 (corrected form).

## 1. Migrate tenant databases

> **Ordering gate (instrument FK follow-up, 2026-07-13):** on every existing tenant, complete the bank-directory backfill in §2 **before** running `2026_07_13_090000_add_bank_foreign_key_to_payment_instruments`. That migration deliberately nulls `payment_instruments.bank_id` values that do not match a populated `banks` row, then adds the FK. Do not use an unscoped `tenants:migrate` while catching a tenant up across this boundary.

```bash
cd apps/api
php artisan tenants:migrate --force \
  --path=database/migrations/tenant/2026_07_12_110000_create_banks_table.php \
  --path=database/migrations/tenant/2026_07_12_111000_add_bank_id_to_payment_repositories.php \
  --path=database/migrations/tenant/2026_07_12_120000_create_partner_bank_accounts_table.php
```

After this command, run the existing-tenant backfill in §2 and verify §3. Only then run:

```bash
php artisan tenants:migrate --force \
  --path=database/migrations/tenant/2026_07_13_090000_add_bank_foreign_key_to_payment_instruments.php
```

New tenants remain safe because `TenantInitializationService` seeds their banks during initialization.

## 2. Seed the bank directory on EXISTING tenants (pre-launch: test tenants only)

> **Owner clarification 2026-07-12:** there are NO production tenants yet. This step only applies to tenants that predate this code — the 5 staging test tenants and the local demo tenant (local demo seeded 2026-07-12, 32 banks confirmed). Every tenant created after this code deploys gets banks automatically via `TenantInitializationService` (test-pinned) — no backfill will ever be needed for real tenants.

`BanksSeeder` is wired into `TenantInitializationService` for **new** tenants only. Existing tenants get **zero** banks rows unless this step runs — the BankPicker will render an empty list and `PaymentRepositorySeeder`-style `rib_bank_code` lookups will find nothing.

> **⚠️ Do NOT use `php artisan tenants:run "db:seed --class=BanksSeeder"`.** It **silently no-ops**: the container resolves the seeder's `?Company $company` parameter to a fresh EMPTY `Company` (class-type resolution wins over the null default), so the `?? Company::first()` fallback never fires and the seeder early-returns with zero rows and zero errors. Same trap exists in `PaymentRepositorySeeder`.

Working form (per-tenant, per-company, explicit Company instance — same pattern as the corrected Phase-② chart command):

```bash
php artisan tinker --execute='tenancy()->runForMultiple(null, function ($t) {
  \App\Modules\Company\Domain\Company::query()->each(function ($c) {
    (new \Database\Seeders\BanksSeeder)->run($c);
    echo "BANKS SEEDED ".$c->id." | ".$c->name.PHP_EOL;
  });
});'
```

Idempotent for row **existence** (matched on `(tenant_id, country_code, rib_bank_code)`, name-keyed for the 7 null-code banks); custom banks (`is_custom=true`) are never touched.

On re-run, canonical rows preserve admin-managed `is_active`, `is_custom`, `name`, and `short_name` values. The seeder refreshes only directory-owned `bic`, `position`, and `city` fields, so repeating the backfill does not reactivate or rename a bank.

## 3. Verify

Per tenant spot-check: `banks` count = 32 per company (TN), `GET /api/v1/banks?country=TN` returns rows, BankPicker populates in AddRepositoryModal.

## 4. No permission changes

This track adds no new permissions (GET /banks is deliberately ungated reference data) — no `permission:cache-reset` needed for it.

## 🎫 Pre-launch ticket

Productize this backfill as a real artisan command (alongside the still-owed `accounting:seed-charts` + opening-balance backfill) — no ad-hoc tinker form may survive to a real-tenant deploy. The command should fix the `?Company` container-resolution trap by taking an explicit `--tenant`/`--all` flag.
