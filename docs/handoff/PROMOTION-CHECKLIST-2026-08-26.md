# Promotion checklist — local `dev` → `origin/dev` (staging auto-deploy) — prepared 2026-08-25 for the 2026-08-26 ceremony

Scope: ~571 local commits on top of `origin/dev` (Sessions A/B/C, 2026-08-21 → 08-25), ALL CI-UNVERIFIED (S-17: Actions quota out).
Owner owns the promotion. Promoting to `origin/dev` = staging auto-deploy incl. `tenants:migrate` — everything below is ordered for that.

## 0. Preconditions (tick before promoting)
- [ ] Every Session A lane merged or explicitly deferred: N-1, N-2, N-3/4/7, N-5, N-6 Ph1, W2-3, W2-6, W2-7+W4-5, W4-9, W4-6, W4-2 ✅ · W4-3 (OQ-74) · D-1 · P-1 · W2-1 (in gates at time of writing — update).
- [ ] Session B / C lanes: per their session logs — each with a register row in OWNER-SHEET §E.
- [ ] End-of-day consolidation on merged dev GREEN: CI-shaped filtered suites on a throwaway PG (backend-test-pgsql allowlist + the parked-lane classes touched this week), deptrac 183/183, manifest EXIT=0, web lint ≤ baseline, pos lint 84, i18n audit, DPA scanner, tanstack-keys audit. Non-inherited reds fixed or reverted.
- [ ] Wave-4 re-run on a FRESH tenant: balances-sanity table (supplier / customer / drawer / safe / bank) all == GL; counting-with-sales-on green.
- [ ] Owner manual smoke sheet (`SMOKE-TEST-tenant-critical-path-treasury-2026-08-24.md`) 🟠 rows done on the Tauri POS.

## 1. Git (rule 21 + the dev push-guard hook)
1. `git fetch origin dev`
2. `git log --oneline origin/dev..dev | wc -l` — local ahead count.
3. `git log --oneline dev..origin/dev | wc -l` — MUST be 0; otherwise merge `origin/dev` into local `dev` first and re-run the consolidation.
4. Promote local `dev` to `origin/dev` as a plain fast-forward (the guard hook refuses rewritten or diverged history).
Never rewrite shared history. If the hook blocks, reconcile exactly as it prints, re-run the consolidation, promote again.

## 2. Migrations that will auto-run on staging (`tenants:migrate`) — all self-guarding by gate
Tenant (since 08-23): `2026_08_23_*` ×11 (Session B: coupon/promo unique, membership revocation, source_document index, B-3 pos_enabled backfill, counting-apply unique, loyalty redemption_key, terminal identity, voucher unique, receipt trigger whitelist, held-orders), `2026_08_24_100000_add_advance_markers_to_payment_allocations`, `2026_08_24_100000_backfill_products_tax_rate_from_tax_configuration_n1` (writes products only; prints a WORKLIST), `2026_08_24_100100_add_status_check_constraint_to_documents`, `2026_08_24_140000_add_fiscal_period_transition_audit_columns`, `2026_08_25_120000_add_count_movement_markers_to_counting_items`, `2026_08_25_120000_retype_sales_discount_accounts_as_contra_revenue`, + D-1 ×3 (`pos_receipts_totals` disjunction, `discount_allocated`, forward-gate index) + P-1 (`country_inventory_settings` + company column) once merged.
ORDER RULE: `tenants:migrate` BEFORE any manual `accounting:backfill-chart-purposes` (W4-9: the backfill refuses accounts whose type disagrees with the definition until the retype migration ran).
- [ ] After deploy: grep the migration log per tenant for `status=FAILED` and for the N-1 `worklist` lines; hand worklists to the operator (confirmed invoices need `vat:backfill-tax-details --apply`).

## 3. Seeders / one-shot steps that do NOT self-run
- [ ] `CountryDocumentSettingsSeeder`, `CountryInventorySettingsSeeder` (P-1), `PlansSeeder` if plans empty, `RolesAndPermissionsSeeder` + `permission:cache-reset` per tenant (S-18; new perms: `pos.manage_terminals` Q-7, others per register).
- [ ] i18n baseline re-pin (B-10) if the audit says the blob moved; `I18N_BASELINE_PROTECTED_BLOB`.
- [ ] `ProvisioningRequiredPurposesV1` AST ratchet regen (W4-9: ADD 3 / REMOVE 3 / re-pin) — dedicated commit, safe to defer.
- [ ] Impersonation seeder + role reseed (country-defaults promotion owes).

## 4. CI (S-14) — `.github/workflows/ci.yml` changed by 10+ lanes (allowlist appends, lane jobs)
- [ ] Actions quota / self-hosted runner (B-11): if CI can run, dispatch the `workflow_dispatch` leg on the candidate BEFORE promoting; else the register rows already say CI-UNVERIFIED and the consolidation (§0) is the local substitute.
- [ ] `actionlint` on ci.yml (owed since G-4).

## 5. Device / client coupling
- [ ] **POS build required for D-1** (event_version 5, remise ventilation, zero-tender comps). Until the build ships, tills seal v≤4 and W4-9's legacy ledger shape applies — correct, just pre-remise. The build also carries C-2+C-6 device-Z (mandatory coupling, LEDGER C-2) and the W2-7 device residuals.
- [ ] Web: no build coupling beyond the deploy.

## 6. Post-deploy verification (staging, per tenant) — read-only commands
- [ ] `php artisan pos:census-vat-legs --tenant=…` → exit 0 (2 = unprovisioned purposes, 1 = drift)
- [ ] `php artisan inventory:repair-phantom-default-batches --tenant=… --dry-run` → 0 phantom / 0 drift on a fresh tenant
- [ ] `php artisan treasury:reconcile --tenant=…` → 0 frozen
- [ ] `php artisan documents:repair-paid-never-posted --tenant=… --dry-run` → 0 candidates
- [ ] Provision a fresh staging tenant via signup → wave-4 flows A–G by hand or Playwright against staging.

## 7. Environment
- [ ] Staging Dokploy is DOWN (owner) — bring it up first; DB `postgres-one` creds via Dokploy; central migrate + `tenants:migrate` logs captured.
- [ ] Horizon queues: any new `onQueue` covered by `HorizonQueueCoverageTest` (CI-guarded) — verify the worker consumes `fiscal-projections`, `imports`.
