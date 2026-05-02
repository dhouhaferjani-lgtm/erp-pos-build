# Tenant Isolation + NF525 Database Foundation Plan

**Status:** Codex database/fiscal foundation audit, 2026-05-02.

**Purpose:** establish what the current PostgreSQL schema, Laravel API, and Tauri POS already provide for tenant isolation and fiscal compliance, then define the plan to close leakage risk before positioning the POS for NF525 certification and later French e-invoicing / ERP expansion.

## Bottom Line

The current system is a shared PostgreSQL database with row-level tenant/company columns, not a certifiable hard-isolation architecture. It already has useful fiscal assets for POS launch: company scoping, POS receipt hash chains, Z reports, grand-total events, document fiscal fields, immutability triggers, local Tauri SQLite fiscal chain logic, fixture parity tests, and Factur-X scaffolding. It does not yet have database-level tenant isolation: the live DB has no row-level security policies, all tenant tables are in `public`, and many foreign keys prove only that a referenced row exists, not that it belongs to the same tenant/company.

Recommendation: for the POS product, finish the existing tenant-isolation sweep plus add database guardrails around POS-critical tables. For the full ERP, plan a separate migration to database-per-tenant or schema-per-tenant. Do not wait for that migration to launch POS, but do not represent the current shared-DB posture as certification-grade isolation.

## Evidence Read

- Shared-DB company scoping migrations: `apps/api/database/migrations/2025_11_30_130000_add_company_id_to_existing_tables.php:12`, `apps/api/database/migrations/2025_11_30_134000_make_company_id_required.php:10`.
- Stancl tenancy config: `apps/api/config/tenancy.php:40`, `apps/api/config/tenancy.php:82`.
- Tenant model supports Stancl database concerns and schema naming: `apps/api/app/Modules/Tenant/Domain/Tenant.php:62`, `apps/api/app/Modules/Tenant/Domain/Tenant.php:248`.
- Live DB check on `autoerp_postgres`: PostgreSQL 16.10, schemas include `public` and Timescale internals only; `pg_policies` returned zero rows; `pg_class.relrowsecurity` was false on public business tables.
- POS fiscal schema: `apps/api/database/migrations/2026_01_08_190429_create_pos_terminals_table.php:40`, `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:15`, `apps/api/database/migrations/2026_01_08_190645_create_pos_grandtotal_events_table.php:15`.
- POS immutability trigger: `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:133`, updated for pending seal in `apps/api/database/migrations/2026_05_01_000003_update_pos_receipts_immutability_trigger_for_pending_seal.php:16`.
- Document fiscal hardening: `apps/api/database/migrations/2025_12_11_054337_add_fiscal_constraints_to_documents.php:23`, `apps/api/database/migrations/2025_12_11_054716_add_document_immutability_trigger.php:18`.
- POS desktop SQLite separation: `apps/pos/src/lib/db.ts:7` creates one SQLite DB file per company id.
- POS desktop fiscal hash logic: `apps/pos/src/lib/offline/receiptService.ts:237`, `apps/pos/src/lib/fiscal/v3/canonicalPayload.ts`, `apps/pos/src/lib/sync/syncService.ts:219`.
- Encryption-at-rest audit: `apps/pos/docs/encryption-at-rest-audit.md:11`.
- Factur-X scaffolding: `apps/api/database/migrations/2026_03_14_100001_add_facturx_fields_to_documents.php:13`, `apps/api/app/Modules/Document/Application/Services/FacturXService.php:16`, `apps/api/app/Modules/Document/Infrastructure/External/PdpClientInterface.php:8`.
- Official France POS certification deadline: economie.gouv.fr states self-certification ends 2026-08-31 and accredited certification is required from 2026-09-01: https://www.economie.gouv.fr/cedef/fiches-pratiques/en-quoi-consiste-la-certification-des-logiciels-de-caisse
- Official French e-invoicing timeline: impots.gouv.fr states rollout starts 2026-09-01, with all companies needing to receive e-invoices and large/ETI companies issuing/e-reporting from that date: https://www.impots.gouv.fr/professionnel/questions/partir-de-quand-suis-je-concerne

## What Already Exists

### 1. Tenant / company model is conceptually right

The schema explicitly says tenant is the subscription/account holder and company is the legal entity where business data is scoped (`2025_11_30_130000...:12-16`). Core business tables were given `company_id` (`partners`, `products`, `documents`, `accounts`, `journal_entries`, `payment_methods`, `payment_repositories`, `payment_instruments`, `payments`) and a later migration makes `company_id` non-null on those tables (`2025_11_30_134000...:24-82`).

This is the right conceptual basis for France: NF525/e-invoicing obligations attach to a legal entity, not merely a SaaS billing account.

### 2. Stancl tenancy is partially present

`config/tenancy.php` enables `DatabaseTenancyBootstrapper` and configures PostgreSQL schema management (`apps/api/config/tenancy.php:40-45`, `:80-85`). The `Tenant` model implements `TenantWithDatabase` and uses `HasDatabase` / `HasDomains` (`Tenant.php:62-65`). `tenant:reset` drops and recreates a tenant schema (`ResetTenantCommand.php:62-68`).

But the live DB has only `public` for app data. The current application still relies on `tenant_id` / `company_id` filters in shared tables. Treat the Stancl setup as a migration foundation, not current isolation.

### 3. POS fiscal chain foundation is real

POS terminals have independent chain state: `genesis_seed`, `current_sequence`, `current_year`, and `last_hash` (`create_pos_terminals...:40-44`). Receipts have fiscal hashes, previous hashes, VAT/payment hashes, receipt year, and chain sequence (`create_pos_receipts...:38-48`). Uniqueness exists for terminal/year/sequence (`:98-99`), and receipt deletion/update is blocked through a PostgreSQL trigger (`:133-189`).

Z reports and grand-total events are also chained (`create_pos_z_reports...:36-39`, `create_pos_grandtotal_events...:43-48`). That is a strong basis for NF525 inalterability, securisation, conservation, and audit trail work.

### 4. Desktop POS has local company-separated SQLite and offline hash logic

The Tauri POS creates the local DB as `izipos-{companyId}.db` (`apps/pos/src/lib/db.ts:7-8`), which is already a form of local raw-level separation. It computes v2/v3 fiscal hashes locally before sync (`apps/pos/src/lib/offline/receiptService.ts:271-308`) and pushes receipts sequentially to preserve chain order (`apps/pos/src/lib/sync/syncService.ts:219-223`).

That said, local DB separation is by company id only, not tenant plus company, and SQLite has no app-level encryption today. The current decision is full-disk encryption as P0, SQLCipher deferred to P1 (`apps/pos/docs/encryption-at-rest-audit.md:11-19`).

### 5. Document fiscal/e-invoicing scaffolding exists

Documents have fiscal categories/status and constraints for fiscal mandatory fields (`add_fiscal_fields...:14-28`, `add_fiscal_constraints...:23-54`). Sealed documents have immutability/deletion triggers (`add_document_immutability_trigger.php:18-97`).

Factur-X support is started: `documents` has `facturx_xml`, `facturx_profile`, `facturx_generated_at` columns (`add_facturx_fields...:13-17`), `FacturXService` can generate Basic WL XML (`FacturXService.php:59-87`), and there is a future PDP client interface (`PdpClientInterface.php:8-24`). This is not enough for France e-invoicing certification/submission, but it is a useful seed.

## What Is Missing

### 1. No database-enforced tenant isolation

There are no RLS policies in live PostgreSQL and no RLS migrations in `apps/api/database`. Current leakage prevention depends on every FormRequest, controller, service, sync endpoint, and cache key applying scope correctly. That is exactly where the recent tenant-isolation issue appeared.

For POS launch, this can be acceptable only if the sweep plus CI gates are finished. For long-term ERP/certification posture, it is too weak.

### 2. Foreign keys are mostly unscoped

Examples: `pos_receipt_payments.payment_method_id` references `payment_methods(id)` (`create_pos_receipt_payments...:29-32`). PostgreSQL confirms the referenced payment method exists, but not that it belongs to the same tenant/company as the receipt. Similarly, `pos_receipts.partner_id`, `terminal_id`, `cashier_id`, and related document links often reference a UUID alone.

Application scoping must stay, but certification-grade confidence needs composite constraints or RLS for high-risk fiscal paths.

### 3. Fiscal exports use some company filters, but not every verification path proves tenant context

`Nf525DataProvider::buildExportSnapshot()` scopes the export by company (`Nf525DataProvider.php:72-80`, `:104-113`). But chain verification methods accept terminal id and verify by terminal id (`:268-272`, `:322-326`). That is legitimate for audit tools if terminal id is already resolved through company context, but unsafe if exposed directly to users without a scoped terminal resolver.

### 4. Factur-X / PDP is only scaffolding

Basic WL XML generation is not full French e-invoicing readiness. Missing items include EN16931 completeness, validation against current French external specs, platform selection/routing, PDP/PF integration, submission status lifecycle, inbound invoice reception, e-reporting, and audit logs for platform exchanges.

### 5. POS desktop local data is not encrypted at the application layer

The current audit explicitly ships P0 with full-disk encryption and defers SQLCipher (`encryption-at-rest-audit.md:11-23`). This may be acceptable for first POS deployments if enforced operationally, but it is not a robust cross-country baseline.

## Architecture Decision

Short term: ship POS on the current shared-DB model only after the tenant-isolation sweep and POS-critical database hardening are complete.

Medium term: add PostgreSQL defense-in-depth on the POS fiscal tables. The fastest strong path is RLS plus composite tenant/company constraints on high-risk FKs.

Long term: move ERP tenants to database-per-tenant or schema-per-tenant. The repo already contains Stancl pieces, but the live app is not there yet. For a serious ERP with payroll/accounting/inventory documents, database-per-tenant is the correct target. For small POS tenants, schema-per-tenant or row-level plus RLS can be a transitional operating model.

## Execution Plan

### Phase 0: Freeze the evidence baseline

- Save live DB evidence: schemas, RLS/policies, trigger inventory, constraints, column inventory.
- Compare live DB against migrations. I found at least one mismatch: migration files include pending-seal changes, while the live local DB still reported `pos_receipts.chain_sequence` as non-null with `CHECK (chain_sequence > 0)`. Resolve whether the local DB is stale before relying on it for certification evidence.
- Add an audit command: `php artisan security:tenant-isolation:db-audit` that emits JSON/YAML for RLS, tenant columns, foreign keys, fiscal triggers, and policy drift.

### Phase 1: Finish the app-level tenant-isolation sweep

Use the existing master sweep plan at `docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md`. I agree with its direction: use a YAML inventory, explicit `ScopedExists` factories, service-layer `find()` gates, super-admin context, web cache-key checks, and Tauri cache/sync-envelope checks.

Add one constraint: POS fiscal paths are not optional. Receipt creation, sync, payments, voucher redemption, Z reports, QR index, terminal activation, and cash drawer operations must all be part of the P0 sweep because those become certification evidence.

### Phase 2: Add POS database guardrails

For `pos_receipts`, `pos_receipt_payments`, `pos_receipt_lines`, `pos_receipt_vat_details`, `pos_shifts`, `pos_z_reports`, `pos_grandtotal_events`, `vouchers`, and `voucher_ledger`:

- Add `tenant_id` / `company_id` columns to child tables that only reference `receipt_id` or `terminal_id`, or add generated/validated constraints through composite parent references.
- Add composite unique keys on parents, for example `pos_receipts(id, tenant_id, company_id)` and `pos_terminals(id, tenant_id, company_id)`.
- Replace or supplement child FKs with composite FKs, for example `pos_receipt_payments(receipt_id, tenant_id, company_id) -> pos_receipts(id, tenant_id, company_id)` and `payment_method_id, tenant_id, company_id -> payment_methods(id, tenant_id, company_id)`.
- Add regression migrations that fail on cross-tenant FK insertion at database level, not only HTTP level.

### Phase 3: Add RLS as defense-in-depth for the shared-DB window

Do not start with every ERP table. Pilot on POS fiscal tables:

- Define session variables `app.tenant_id`, `app.company_id`, and `app.super_admin`.
- Add middleware that sets local transaction settings on every request after auth/company resolution.
- Add RLS policies on POS fiscal tables with `USING` and `WITH CHECK`.
- Add console/audit escape hatch that runs only in explicit super-admin/audit context.
- Add tests that direct SQL insert/select/update fails without the correct session variables.

This should be treated as defense-in-depth, not a substitute for explicit Eloquent scoping.

### Phase 4: Certification evidence pack

Build a reproducible folder for certifiers:

- Schema evidence: migrations, trigger definitions, RLS policies, FK inventory.
- Functional evidence: receipt chain verification, Z report verification, grand-total continuity, void/refund compensating receipt flow, reprint logs, training mode exclusion.
- Fixture evidence: v2/v3 golden hash fixture checks, frontend/backend parity, fixture integrity hashes.
- Operational evidence: backup/restore, export, archive retention, device onboarding, FDE verification, incident response.
- Change-control evidence: CI gates that prevent bare `exists:` / unscoped find / query-key leaks.

### Phase 5: French e-invoicing track

Keep separate from NF525 POS certification. Build on `FacturXService`, but do not consider it done until:

- EN16931 XML mapping is validated.
- PDF/A-3 embedding is validated with an external validator.
- PDP/PF integration exists for submission, status updates, inbound reception, and e-reporting.
- Invoice lifecycle includes platform states and immutable exchange logs.
- Company legal identifiers and routing identifiers are normalized and validated.

### Phase 6: ERP tenant isolation migration

After POS launch stabilizes:

- Decide database-per-tenant versus schema-per-tenant. For full ERP, database-per-tenant is the stronger target.
- Split central tables from tenant tables.
- Squash tenant schema migrations if there are no production customers requiring historical migration replay.
- Move feature tests onto a tenant test kit.
- Define cross-tenant admin/audit operations as explicit tenant iteration, not unscoped queries.

## YAML Single Source Of Truth

Yes, use YAML. It is the right coordination mechanism for Codex and Opus because this work has many callsites, many agents, and hard evidence requirements. The existing `tenant-isolation-sweep-inventory.example.yml` is a good start for code callsites. I would add a second certification-oriented YAML that tracks database, fiscal, desktop, and legal-evidence workstreams.

Rules for the YAML:

- Every item gets a stable id.
- Every item lists owner, status, files, tests, acceptance criteria, and certification evidence.
- Every item has `risk_if_skipped`.
- Every item has `blocks_certification: true|false`.
- Agents update status only through scripted commands once the tooling exists.
- The YAML is committed and reviewed like code.

Recommended new file: `docs/superpowers/plans/2026-05-02-tenant-isolation-certification-sot.yaml`.

## Merge Protocol With Opus Plan

When Opus's plan lands, compare by IDs and workstreams:

1. Keep the stricter requirement when plans disagree.
2. Keep code-level sweep items in the existing tenant-isolation inventory.
3. Keep DB/fiscal/certification evidence items in the certification YAML.
4. Merge duplicate tasks by preserving both evidence links and the stronger test requirement.
5. Anything marked `blocks_certification: true` cannot be deferred without human sign-off.

## Immediate Next Steps

1. Commit this plan and the certification YAML.
2. Run the tenant-isolation inventory generator or create its first real YAML from current scans.
3. Start Phase 1 with the POS-critical API cluster included.
4. Add the database audit command before any RLS/composite-FK migration work.
5. Schedule a certifier/tax-counsel review of the evidence pack structure before building too much bespoke tooling.

