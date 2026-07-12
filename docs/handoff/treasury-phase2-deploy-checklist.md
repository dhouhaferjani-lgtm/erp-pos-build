# Treasury Phase 2 — deployment checklist

Scope: payment-instrument portfolio, remittances, maturity schedule, portfolio reconciliation, and alerts.

Run this checklist for every deployed tenant before exposing the Phase-2 routes. Commands are issued from `apps/api` on the release revision.

## 1. Migrate every tenant

```bash
php artisan tenants:migrate --force
```

The additive migrations create the portfolio/remittance/event schema, widen method/instrument metadata, add the maturity-alert setting, and stamp `companies.phase2_cutover_at` for brownfield companies. That watermark excludes pre-deploy paper and GL noise from reconcile check #4; it does not rewrite historical books or movements.

Verify no tenant has a pending migration and every existing company has a non-null `phase2_cutover_at`.

## 2. Re-run chart provisioning

Re-run the locale chart seeder through `ChartOfAccountsService::seedForCompany()` for every company in every tenant. The seeders are idempotent and add the portfolio/fee accounts without replacing existing accounts.

Required purpose codes:

| Purpose | Tunisia | Other charts |
|---|---:|---:|
| Cheques to collect | 5312 | 5112 |
| Effects receivable | 413 | 413 |
| Effects in collection | 5313 | 5113 |
| Effects discounted | 5314 | 5114 |
| Instrument bank fees | 6275 | 627 |
| Recoverable VAT on fees | 43666 | 44566 |
| Doubtful receivables | 416 | 416 |

For an operator shell where Tinker is available, the per-tenant runner is:

> **⚠️ CORRECTED 2026-07-12 (staging remediation):** the original `tenants:run tinker --option='execute=…'` form HANGS — `tenants:run` does not forward the option to tinker, which then waits on stdin. Use plain `tinker --execute` with `tenancy()->runForMultiple` instead (verified working against all 5 staging tenants):

```bash
php artisan tinker --execute='tenancy()->runForMultiple(null, function ($t) {
  \App\Modules\Company\Domain\Company::query()->each(function ($c) {
    app(\App\Modules\Accounting\Application\Services\ChartOfAccountsService::class)->seedForCompany($c);
    echo "SEEDED ".$c->id." | ".$c->name.PHP_EOL;
  });
});'
```

Idempotent — safe to re-run; a long tenant list may need the command re-run if the shell times out (already-seeded tenants no-op). **TICKET (pre-launch):** productize this as a real artisan command (e.g. `accounting:seed-charts` on the `TenantScopedCommand` batch contract), alongside the still-unbuilt opening-balance backfill command — neither ad-hoc tinker form should survive to a real-tenant deploy.

Do not manually assign repository balances while seeding. No movement or journal entry is created by chart provisioning.

## 3. Re-seed permissions and reset the tenant cache

```bash
php artisan tenants:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force
php artisan tenants:run permission:cache-reset
```

This deploy adds `instruments.update`, `instruments.bounce`, `instruments.remit`, and `instruments.cancel`. The cache reset is mandatory because the Spatie permission cache key is tenant-blind; skipping it can leave accountant-equivalent users with stale 403 responses.

## 4. Restart application workers

Restart the API/queue release processes so they load the new projection and command registrations. No new queue name is introduced; retain the existing `default,fiscal-projections,enrichment,images,imports` worker coverage.

## 5. Post-deploy verification

For each tenant/company:

1. Confirm the seven chart codes above resolve for the company's country.
2. Confirm the four new permission names exist and intended roles hold them.
3. Confirm `phase2_cutover_at` is non-null on brownfield companies.
4. Register a non-cash paper instrument in a controlled smoke account and verify repository cash does not move at receipt/remittance.
5. Run reconciliation:

   ```bash
   php artisan treasury:reconcile --tenant=<tenant-uuid>
   ```

   Expected: zero cash freezes, zero portfolio drift, zero errors. A non-zero exit is a deployment stop; investigate rather than repairing balances manually.

6. Run `php artisan treasury:instrument-maturity-alerts` once and confirm a `treasury.instrument.maturity_alert` audit event is written per company.

## Accounting/FEC declaration

Portfolio lifecycle entries use journal code `EF` (journal des effets). `source_type=instrument` and `source_type=instrument_remittance` map to `EF`; receipt-side customer payments remain in their existing bank/cash journal. Posted entries are never edited—corrections and dishonors use new contre-passation entries.
