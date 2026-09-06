# Promotion checklist — local `dev` → `origin/dev` (staging auto-deploy) — prepared 2026-08-25 for the 2026-08-26 ceremony

Scope: 876 local commits on top of `origin/dev` (`git rev-list --count origin/dev..dev`, re-derived 2026-08-26 after the T9 batch merged; re-derive again at ceremony time).
Owner owns the promotion. Promoting to `origin/dev` = staging auto-deploy incl. `tenants:migrate` — everything below is ordered for that.

## 0. Preconditions (tick before promoting)
- [x] Every Session A lane merged (2026-08-25 end): N-1, N-2, N-3/4/7, N-5, N-6 Ph1 (+DPA rollback), W2-3, W2-6, W2-7+W4-5, W4-9, W4-6, W4-2 (+fixture), W4-3, D-1, P-1, W2-1, W4R-2, CI-hygiene, test-infra r1+r2. Hold-list (NOT blocking promotion): W4R2-2 dashboard tile, N-12, W4R-1, W4R-3 (Menu tenants). W4-1 moved off the hold-list — FIXED-MERGED `98a449d14` (Session D; see below).
- [x] **All ten Session D lanes merged: B2-6, C-F0w, W4R2-2, N-12, B-19, B-13, O-30, W4-1, T9** — merge SHAs `9d0d08ae5` (B2-6), `8664d180c` (C-F0w), `4ae7c68a8` (W4R2-2), `4807f0045` (N-12), `c94d23043` (B-19), `e56321a76` (B-13), `8c8316ab7` (O-30), `98a449d14` (W4-1), `16088fdb7` (T9). LEDGER rows filed: D-N12-1, D-W4R2-1 (closes C-45(i)), D-B26-1 (closes C-14(ii)/(iii)/(iv)), D-CF0W-1, D-B13-1..4, D-B19-1..4 (already filed by the B-19 lane itself), D-O30-1..5 (O-30(a) delivered; O-30(b)/(c) + C-17(ii)/(viii) status updated), D-W41-1..4 + D-MANIFEST-1 (W4-1), D-T9-1..9 + N-9/N-14 (T9 — closes C-45(iv), C-23(iii) import-path-only, C-13(i); N-9 FIXED-MERGED, N-14 PARTIAL/R-2 open).
- [ ] Session B / C lanes: per their session logs — each with a register row in OWNER-SHEET §E.
- [x] Consolidation r3 (`docs/sessions/session-A-2026-08-24/CONSOLIDATION-2026-08-25-r3.md`, tip `ddf2e45d9`): SAFE TO PROMOTE — PG 1655/0 fail, sqlite 1618/0, all static gates green, DPA zero delta; web lint +4 (non-CI, discipline). Inherited non-CI red I-5 `PosCoreReceiptProjectionLoyaltyEarnTest` ×3 (PG 25P02) — micro-lane owed. Original spec: CI-shaped filtered suites on a throwaway PG (backend-test-pgsql allowlist + the parked-lane classes touched this week), deptrac 183/183, manifest EXIT=0, web lint ≤ baseline, pos lint 84, i18n audit, DPA scanner, tanstack-keys audit. Non-inherited reds fixed or reverted.
- [x] Wave-4 re-run (`PLAYWRIGHT-first-tenant-campaign-wave4-RERUN-2026-08-25.md`) + targeted re-check (`PLAYWRIGHT-w4r2-w43-recheck-2026-08-25.md`): balances tie on every entity; lots + AP arm verified on fresh tenants.
- [ ] Owner manual smoke sheet (`SMOKE-TEST-tenant-critical-path-treasury-2026-08-24.md`) 🟠 rows done on the Tauri POS.
- [ ] **Onboarding campaign GREEN + day-one census CLEAN (Session I, CLAUDE.md rule 22 — standing precondition from 2026-08-29):** `scripts/campaign-onboarding.sh` against the candidate tree (local stack, or staging via `--web/--api`) with every leg PASS or pre-declared NOT_SCRIPTABLE (ledger attached to the promotion thread; `docs/qa/ONBOARDING-CAMPAIGN.md`), and `php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1'` on the candidate's local tenants with every verdict line CLEAN (`docs/handoff/RUNBOOK-day-one-census.md`). Until lanes I-1/I-2 land, the manual fresh-tenant journey in `docs/qa/MANUAL-TESTING-LOOP.md` §2 is the substitute and its bug reports block promotion at P0/P1.

## 1. Git (rule 21 + the dev push-guard hook)
1. `git fetch origin dev`
2. `git log --oneline origin/dev..dev | wc -l` — local ahead count.
3. `git log --oneline dev..origin/dev | wc -l` — MUST be 0; otherwise merge `origin/dev` into local `dev` first and re-run the consolidation.
4. Promote local `dev` to `origin/dev` as a plain fast-forward (the guard hook refuses rewritten or diverged history).
Never rewrite shared history. If the hook blocks, reconcile exactly as it prints, re-run the consolidation, promote again.

## 2. Migrations that will auto-run on staging (`tenants:migrate`) — all self-guarding by gate
Full `git diff origin/dev..dev --name-only -- apps/api/database/migrations` (re-derived 2026-08-26), in repo order:
- `2026_01_05_150000_create_product_batches_table.php.bak`, `2026_01_05_150001_create_inventory_batch_stock_table.php.bak`, `2026_01_05_150002_create_inventory_batch_movements_table.php.bak`, `2026_01_05_150004_add_batch_id_to_document_lines_table.php.bak`, `2026_01_05_150005_add_batch_id_to_stock_reservations_table.php.bak` — **`.bak`, inert**: Laravel's migration loader ignores non-`.php` files, so none of these five run.
- `2026_08_08_160000_create_supplier_goods_return_notes_tables.php`
- `2026_08_21_140000_backfill_chart_required_purposes_o27.php`
- `2026_08_23_000100_unique_journal_entries_source_opening_balance_batch.php`
- `2026_08_23_000500_unique_coupon_and_promotion_usage_per_receipt.php`
- `2026_08_23_100000_add_revocation_tracking_to_user_company_memberships.php`
- `2026_08_23_100000_add_source_document_id_index_to_documents.php`
- `2026_08_23_120000_backfill_location_pos_enabled_b3.php`
- `2026_08_23_120000_unique_stock_movements_counting_apply.php`
- `2026_08_23_140000_add_redemption_key_to_loyalty_transactions.php`
- `2026_08_23_140000_harden_pos_terminals_identity_and_lifecycle.php`
- `2026_08_23_150000_unique_voucher_ledger_voided_per_voucher.php`
- `2026_08_23_160000_harden_pos_receipt_immutability_trigger_whitelist.php`
- `2026_08_23_163000_harden_pos_held_orders_status_and_discard.php`
- `2026_08_24_100000_add_advance_markers_to_payment_allocations.php`
- `2026_08_24_100000_backfill_products_tax_rate_from_tax_configuration_n1.php` (writes products only; prints a WORKLIST)
- `2026_08_24_100100_add_status_check_constraint_to_documents.php`
- `2026_08_24_140000_add_fiscal_period_transition_audit_columns.php`
- `2026_08_25_000100_add_fiscal_authority_columns_unactivated.php`
- `2026_08_25_090000_add_discount_allocated_to_pos_receipt_vat_details_d1.php`
- `2026_08_25_090100_widen_pos_receipts_totals_check_for_post_remise_base_d1.php`
- `2026_08_25_090200_index_sale_receipt_v5_watermark_d1.php`
- `2026_08_25_120000_add_count_movement_markers_to_counting_items.php`
- `2026_08_25_120000_retype_sales_discount_accounts_as_contra_revenue.php`
- `2026_08_25_130100_add_enum_check_constraints_to_vouchers.php`
- `2026_08_25_130200_add_enum_check_constraints_to_journal_entries.php`
- `2026_08_25_130300_add_enum_check_constraints_to_payments.php`
- `2026_08_25_130400_add_enum_check_constraints_to_documents.php`
- `2026_08_25_130500_add_enum_check_constraints_to_instrument_events.php`
- `2026_08_25_140000_seed_count_correction_gl_posting_default.php`
- `2026_08_25_150000_widen_payments_payment_type_check_for_pos_refund.php` **(W4R2-2)**
- `2026_08_25_150100_retype_supplier_and_pos_refund_payments.php` **(W4R2-2)**
- `2026_08_26_100000_backfill_payment_repository_location_n12.php` **(N-12)**
- `2026_08_26_100000_seed_base_units_for_unit_less_tenants.php` **(N-9, T9 batch)** — self-guarding; skips when either units table has rows. ⚠️ D-T9-3: a tenant that hand-created a single unit is skipped — run the docblock census per tenant BEFORE promotion and seed manually where the census says so.
- `2026_08_26_100000_make_batch_expiry_date_nullable.php` **(W4-1)**
- `2026_08_26_100100_null_invented_default_lot_expiries.php` **(W4-1)**

ORDER RULES:
1. `tenants:migrate` BEFORE any manual `accounting:backfill-chart-purposes` (W4-9: the backfill refuses accounts whose type disagrees with the definition until the retype migration ran).
2. **W4R2-2 — migrate BEFORE rolling the API image.** `2026_08_25_150000` (widen `chk_payments_payment_type_enum`) MUST run, then `2026_08_25_150100` (retype), before the new API image serves traffic (see LEDGER D-W4R2-1).
3. **N-12 — provisioning, then a manual step.** `2026_08_26_100000` provisions a branch drawer for every already-claimed/`pos_enabled` branch location; AFTER it runs, the operator must execute a `RepositoryTransfer` per branch named by its `drawer-provisioned-transfer-owed` census log line (see LEDGER D-N12-1, S-13).
4. **W4-1 — the nullable migration MUST run before the backfill.** `2026_08_26_100000_make_batch_expiry_date_nullable.php` MUST apply BEFORE `2026_08_26_100100_null_invented_default_lot_expiries.php` — filename order guarantees this on a normal `tenants:migrate`; the backfill self-guards (skips with a warning, does not abort, if the column is still `NOT NULL`) but a partial/manual run applying only the second file is a SILENT no-op. Every tenant with opening stock must print a `[W4-1] invented DEFAULT-lot expiry census` line in the migrate log — absence on such a tenant means the guard skipped and needs investigating (see LEDGER D-W41-2).
- [ ] After deploy: grep the migration log per tenant for `status=FAILED` and for the N-1 `worklist` lines; hand worklists to the operator (confirmed invoices need `vat:backfill-tax-details --apply`).
- [ ] After deploy, per tenant: grep the migrate log for `[W4-1] invented DEFAULT-lot expiries …` on any tenant with opening stock (D-W41-2) — absence = investigate. If any forced terminal release has occurred (O-30), run the runbook §1b query to find unresolved orphans, then `php artisan tenants:run pos:shift:close-orphaned` (dry run first, `--apply` once reviewed) per `docs/handoff/RUNBOOK-orphaned-shift.md`.

## 3. Seeders / one-shot steps that do NOT self-run
- [ ] `CountryDocumentSettingsSeeder`, `CountryInventorySettingsSeeder` (P-1), `PlansSeeder` if plans empty, `RolesAndPermissionsSeeder` + `permission:cache-reset` per tenant (S-18; new perms: `pos.manage_terminals` Q-7, others per register).
- [ ] **F-W2-14 (PR #210 + fix round 1, 2026-09-07) — `RolesAndPermissionsSeeder` MUST re-run on every already-provisioned tenant DB.** Two NEW permissions ship: `supplier-invoices.manage` (supplier-invoice create/re-match/post + ingestion commit + supplier-invoice attachments) and `payments.pay-supplier` (the AP branch of `POST /payments`). Under database-per-tenant they do not exist in an existing tenant DB until the seeder runs there.
  - Staging: covered automatically IF `SYNC_PERMISSIONS_ON_BOOT=true` is still set on the API service in Dokploy (`apps/api/docker/entrypoint.sh:153-161` runs `tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'`, then `permission:cache-reset` at `:176`). The var is in NO in-repo compose file and `.env.example:178` ships `false` — **confirm it on the service before promoting**.
  - Every environment where the flag is false (local dev tenants, production): run manually, per environment —
    `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'` then `php artisan permission:cache-reset`. `tenants:run`/`tenants:seed` exit 0 regardless — **gate on the printed per-tenant output**.
  - `syncPermissions` resets built-in roles to the canonical set: any tenant-customised built-in role is reverted (standing caveat, `entrypoint.sh:150-152`). Note it in the release notes.
  - Post-deploy smoke on ONE pre-existing tenant: manager posts a draft supplier invoice → 200; cashier `POST /api/v1/supplier-invoices` → 403; cashier `POST /api/v1/payments` allocating a posted supplier invoice → 403 with no `payments` row and no `repository_movements` row; cashier customer payment → 201; cashier `POST /api/v1/documents/{confirmed PO}/revert` → 403.
  - The frontend now fails CLOSED for both permissions (`SERVER_AUTHORITATIVE_PERMISSIONS`, `apps/web/src/hooks/usePermissions.ts`), so a tenant that has NOT been re-seeded hides the controls instead of showing a button that 403s — the seeder step is what turns them back on for manager/accountant.
- [ ] i18n baseline re-pin (B-10) if the audit says the blob moved; `I18N_BASELINE_PROTECTED_BLOB`.
- [x] `ProvisioningRequiredPurposesV1` AST ratchet regen — DONE `e917a3d38` (CI-hygiene lane; 100→103 sites).
- [ ] Impersonation seeder + role reseed (country-defaults promotion owes).
- [ ] **`vat:backfill-tax-details` per tenant (B-19, LEDGER S-23)** — supplier invoices and supplier credit notes posted BEFORE B-19 carry no `document_tax_details` row, so their deductible VAT is missing from every TN declaration and cannot self-heal (`post()` is idempotent — a re-post no-ops and never rewrites). `tenants:run vat:backfill-tax-details` is DRY-RUN by default: **review the printed per-tenant census first**, then re-run with `--apply`. Afterwards act on any printed CLOSED-PERIOD reopen/re-close instruction. FILED-period documents are refused unless `--include-filed` — escalate to the accountant rather than passing it; CLOSED periods that cannot be reopened (a successor is already closed/filed) are refused unconditionally and need a manual accountant correction. Local demo tenant dry run: 43 documents, 12 in scope, 313.884 TND, 0 skipped. Historical AR/AP openings, drafts and never-posted cancellations are excluded and censused separately. Procedure + census detail: `docs/superpowers/reviews/2026-08-26-b19-handback.md` §6.

## 4. CI (S-14) — `.github/workflows/ci.yml` changed by 10+ lanes (allowlist appends, lane jobs)
- [ ] Actions quota / self-hosted runner (B-11): if CI can run, dispatch the `workflow_dispatch` leg on the candidate BEFORE promoting; else the register rows already say CI-UNVERIFIED and the consolidation (§0) is the local substitute.
- [x] `actionlint` on all workflows — DONE `e917a3d38` (10→0).

## 5. Device / client coupling
- [ ] **POS build required for D-1** (event_version 5, remise ventilation, zero-tender comps). Until the build ships, tills seal v≤4 and W4-9's legacy ledger shape applies — correct, just pre-remise. The build also carries C-2+C-6 device-Z (mandatory coupling, LEDGER C-2) and the W2-7 device residuals.
- [ ] Web: no build coupling beyond the deploy.

## 6. Post-deploy verification (staging, per tenant) — read-only commands
- [ ] `php artisan tenants:run pos:census-vat-legs --tenants=…` → exit 0 (2 = unprovisioned purposes, 1 = drift) — MUST run under `tenants:run`; a bare `--tenant` reports a false 'chart not provisioned'
- [ ] `php artisan tenants:run inventory:lot-drift-census --tenants=… --option=fail-on-drift` → 0 drift on a fresh tenant
- [ ] `php artisan inventory:repair-phantom-default-batches --tenant=… --dry-run` → 0 phantom / 0 drift on a fresh tenant
- [ ] `php artisan treasury:reconcile --tenant=…` → 0 frozen
- [ ] `php artisan documents:repair-paid-never-posted --tenant=… --dry-run` → 0 candidates
- [ ] Provision a fresh staging tenant via signup → wave-4 flows A–G by hand or Playwright against staging.

## 7. Environment
- [ ] Staging Dokploy is DOWN (owner) — bring it up first; DB `postgres-one` creds via Dokploy; central migrate + `tenants:migrate` logs captured.
- [ ] Horizon queues: any new `onQueue` covered by `HorizonQueueCoverageTest` (CI-guarded) — verify the worker consumes `fiscal-projections`, `imports`.
