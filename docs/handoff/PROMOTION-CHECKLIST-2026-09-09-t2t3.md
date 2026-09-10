# Promotion checklist — T-2 / T-3 transfer receipt + blind receiving (lane t2t3-transfer-receipt-blind-receiving)

Plan: docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-10.md
Spec: docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md (§11 rollout)
Applies to: source Push 1 (S1). Pushes 2-4 add no permission and no backfill.
Promoting to origin/dev = staging auto-deploy including `tenants:migrate` (memory
feedback_push_dev_autodeploys_migrations). Every step below runs AFTER that deploy settles.

## S1 review disclosures

- T-4: The backend list/show gate accepts any of view, complete or reconcile. Until S4, the web route guard still requires `inventory.transfers.view` and has no Inventory module gate; complete-only and reconcile-only actors cannot reach those pages.
- G-2: Before launch, verify that both `inventory` and `inventory_shrinkage_expense` account purposes resolve for every launch company. Destructive receipt/close actions refuse with `GL_ACCOUNTS_UNMAPPED` before any stock or GL write otherwise.
- G-4: Lost-in-transit close write-offs are landed and scrapped at the destination; movement feeds and per-location shrinkage therefore attribute the loss to the destination.
- G-7: Freight capitalization raises inventory-subledger WAC without a matching GL debit, while damage/write-off credits GL Inventory. The resulting GL-versus-subledger freight divergence remains owned by `docs/superpowers/tickets/2026-09-09-t1-freight-capitalization-no-gl.md`.

- I-11/F-4: Until S4, a short shipment is closed via `POST /close` (`disposition=write_off|return_to_source`); the web Complete on a `partially_received` transfer books the entire outstanding remainder as received-good. Its confirmation states short-line and total-line counts.
- F-3 / R5: S4 must mask sent/remaining behind `canSeeExpected`/`blind` on `StockTransferDetailPage` before any terminal affordance on a partial. `StockTransferData` needs a receiver-shape discriminant in S2.

## 1. Tenant permission seeding  (spec §11.4 item 1)
- [ ] `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'`
      Two NEW permissions ship in this lane: `inventory.transfers.reconcile` (visibility;
      gates GET /stock-transfers/{id}/reconciliation and is the "sees expected" disjunct)
      and `inventory.transfers.close` (write authority; required IN ADDITION to reconcile
      for POST /stock-transfers/{id}/close). Both are seeded to `manager` and reach `admin`
      through permissionNames(); no other seeded role receives either.
      Under database-per-tenant they do not exist in an already-provisioned tenant DB until
      this seeder runs THERE.
- [ ] Staging only: confirm `SYNC_PERMISSIONS_ON_BOOT=true` is still set on the API service
      in Dokploy before relying on the boot path
      (apps/api/docker/entrypoint.sh:153-161 runs the seeder, :176 runs the cache reset).
      The variable is in NO in-repo compose file and .env.example:178 ships `false`.
- [ ] `tenants:seed` and `tenants:run` exit 0 regardless of per-tenant failures.
      GATE ON THE PRINTED PER-TENANT OUTPUT, not on the exit code.
- [ ] Verify, per tenant DB:
      `SELECT name FROM permissions WHERE name IN
       ('inventory.transfers.reconcile','inventory.transfers.close') ORDER BY name;`
      -> exactly two rows.

## 2. Permission cache reset  (spec §11.4 item 2)
- [ ] `php artisan permission:cache-reset`
      Spatie caches the permission map per process. Without this, an already-warm API or
      Horizon worker keeps refusing the two new permissions until its cache expires.
- [ ] Re-verify after the reset with one real request: a manager on a destination location
      gets 200 from `GET /stock-transfers/{id}/reconciliation`.

## 3. Frontend permission-map export  (spec §11.4 item 3)
- [ ] `php artisan permissions:export-frontend-map` — BUILD TIME, on the laptop, committed
      in Push 1. NEVER on the host.
      It regenerates `apps/web/src/hooks/permissionsMap.generated.ts`, which has a hard CI
      drift gate (scripts/preflight.sh:140-155 and .github/workflows/ci.yml:2705-2714).
- [ ] Confirm the committed file contains both new permission keys before promoting.
- [ ] `php artisan typescript:transform` likewise, for
      `packages/shared/types/generated.d.ts` (gate: scripts/preflight.sh:110-133 and
      .github/workflows/ci.yml:2683-2700). TransferStatus must show SEVEN members.

## 4. Manual post-commit migration-drift recovery  (spec §3.1 / §11.4 item 4)
- [ ] Migrations run inside ONE PostgreSQL transaction and are logged to `migrations` only
      on success (apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:448-451
      and :252-258). A rolled-back, unlogged migration is simply re-selected by the next
      `tenants:migrate` — no operator action.
- [ ] **NEVER run `migrate:rollback` on this lane's migrations.** `down()` on
      2026_09_09_100100_create_stock_transfer_receipt_tables.php DROPS POSTED DOCUMENTS.
- [ ] Post-commit drift (a migration that COMMITTED but left the schema wrong) is repaired
      by a NEW FORWARD MIGRATION, never by a rollback and never by a manual ALTER on the host.
      Record the drift, write the forward migration, promote it as a normal push.
- [ ] Backfill-specific: interruption recovery is PostgreSQL-only. On PG the migration
      transaction discards every chunk of a failed run. On SQLite no convergence after
      interruption is claimed. Staging and production are PG.
- [ ] Lane census after Push 1, per tenant:
      `SELECT COUNT(*) FROM stock_transfers WHERE status = 'completed'
         AND id NOT IN (SELECT transfer_id FROM stock_transfer_receipts);`
      -> MUST return 0. A non-zero result means the backfill did not complete on that
      tenant; re-run `tenants:migrate` for it and re-census.

## 5. Backups
- [ ] One verified NON-ZERO host backup per tenant database BEFORE Push 1, because Push 1
      carries the backfill. Pushes 2-4 add no row-level write.

## 6. Activation (NOT part of any push)
- [ ] `blind_receiving` defaults to false everywhere and is a PER-COMPANY OPERATOR ACTION in
      Settings -> Fraud & controls, taken only AFTER Push 4. There is no environment
      variable and no config key to set on any application.
