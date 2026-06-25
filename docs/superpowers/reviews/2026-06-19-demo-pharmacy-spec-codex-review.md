# Adversarial Review: Demo Pharmacy Account Design Spec
Date: 2026-06-19
Reviewer: Codex (staff-engineer adversarial mode)
Verdict: NEEDS-REWORK

---

## Executive Summary

The spec has the right demo intent, but it overstates how reusable the current France parapharmacy seeder is and leaves several operational gates vague. The biggest risk is assuming a clean "extend with hooks" path when the real seeder is `final`/private/hardcoded-France and the DB-per-tenant lifecycle is explicit (not queued magic). Without addressing the seeder coupling, Tunisia fiscal rendering gaps, and the Tauri staging build gap, this demo will not be repeatable or safe to run.

---

## Findings

### Area 1: DB-Per-Tenant Seeding

**[BLOCKER] Tenant creation does not auto-provision databases**
- Evidence: `apps/api/app/Providers/TenancyServiceProvider.php:40`; `apps/api/app/Modules/Tenant/Domain/Tenant.php:62`; `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:116`
- Problem: The seeder must explicitly create/migrate/initialize the tenant DB before any tenant-scoped writes. There is no queued `CreateDatabase`/`MigrateDatabase` job wired to tenant model creation in this codebase — provisioning is synchronous through `TenantProvisioningService`. The spec's claim that `php artisan db:seed --class=DemoPharmacySeeder` will "just work" is only true if the seeder explicitly calls the provisioning service with proper error handling. A half-provisioned tenant (crash after DB creation, before migration) leaves a dangling DB with no cleanup path since G1 (tenant deletion 500s) is unresolved.
- Fix: Make the seeder own tenant resolution, synchronous provisioning (create DB → migrate → initialize tenancy context → seed data → end tenancy), and add a pre-flight check that aborts if the tenant DB already exists but is half-migrated.

**[MAJOR] "Additive/idempotent" conflicts with inherited destructive behavior**
- Evidence: `apps/api/database/seeders/ParapharmacySeeder.php:267` (tenant delete), `:275` (db drop); spec line `:163`
- Problem: The existing seeder deletes the tenant and drops the DB. The spec proposes the new seeder be "additive/idempotent" — but it inherits from or copies this pattern. Idempotency requires a completely different entry path (find-or-create tenant, skip if already seeded). The spec does not prescribe how to detect "already seeded" state.
- Fix: Define an explicit idempotency guard (e.g., check for a sentinel row in a seeder_runs table or a config flag) and document that the seeder MUST NOT call the parent destructive path on re-run.

**[MAJOR] Horizon is not required for DB provisioning, but IS required after demo sales**
- Evidence: `apps/api/database/seeders/ParapharmacySeeder.php:356` (fiscal event dispatch); `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:960`; `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:171`
- Problem: DB provisioning is synchronous. But fiscal events dispatched after POS sync are queued jobs — projections (pos_receipts, pos_shifts, Z-report aggregates) will not appear in the web admin or POS until Horizon workers consume the queue. The spec says nothing about verifying Horizon is running on the Dokploy staging server before/during demo.
- Fix: Runbook must include: (1) verify Horizon is running (`php artisan horizon:status`), (2) verify no stuck/dead-lettered jobs in `fiscal-projections` queue after the pre-demo dry-run sales.

---

### Area 2: Tunisia Fiscal Correctness

**[MAJOR] Tunisia identifiers are only partially surfaced in receipts; Z-report ignores branch override**
- Evidence: `apps/api/app/Modules/Company/Application/Services/TaxIdentityResolver.php:19`; `apps/api/app/Modules/POS/Application/Services/ReceiptPdfService.php:119`; `apps/api/resources/views/pos/receipt.blade.php:328`; `apps/api/resources/views/pos/z-report.blade.php:239`
- Problem: The receipt blade uses a branch tax ID generically. The Z-report pulls company tax ID and ignores the branch-level override that Tunisia requires (per-establishment matricule fiscal). The label rendered on receipts does not localize to "Matricule Fiscal" for TN — it falls through to a generic label.
- Fix: Define TN identifier keys in `config/tax_identity.php`, use `TaxIdentityResolver` in the Z-report blade with branch context, and localize the rendered label as "Matricule Fiscal" for country=TN.

**[MAJOR] Tunisia tax validation exists but stored/rendered format is not pinned**
- Evidence: `apps/api/database/seeders/CountriesSeeder.php:30`; `apps/api/app/Shared/Domain/Validation/CountryTaxNumberRules.php:18`; `apps/api/config/tax_identity.php:8`; `apps/api/database/seeders/TunisiaTaxConfigurationSeeder.php:20`, `:84`
- Problem: Runtime TN validation regex in `CountryTaxNumberRules` and the country seed data differ. The seeder seeds a matricule fiscal value but it is not verified that the FormRequest validation accepts the same format that the seeder writes. Mismatch = validation error on the settings UI when an admin tries to update the branch tax ID.
- Fix: Pick one canonical matricule fiscal format (e.g., `7\d{7}[A-Z]{3}\d{3}`), apply it consistently in `CountryTaxNumberRules`, the seeder, and integration test the round-trip through the FormRequest and receipt rendering.

**[MEDIUM] TND scale=3 is correctly referenced in the platform but must be verified end-to-end in POS**
- Evidence: `apps/api/app/Shared/Domain/ValueObjects/CurrencyScale.php` (not read directly); spec assumes scale=3
- Problem: If any POS money formatting path uses hardcoded scale=2 (EUR assumption), TND amounts will be truncated. This is a known risk class per CLAUDE.md Rule 19.
- Fix: Grep for `bcformat` calls with hardcoded scale=2 in the fiscal and POS modules; run a TND sale in the dry-run and verify the receipt shows 3 decimal places.

**[MEDIUM] Stamp duty (timbre fiscal) is not present in the codebase**
- Evidence: `grep -r 'stamp_duty\|timbre\|TND' apps/api --include='*.php'` returned 0 results for stamp_duty/timbre
- Problem: Tunisia's 0.600 TND stamp duty per receipt is a legal requirement. The spec does not mention it. If the demo runs without it, the receipts are not fiscally representative of a real TN parapharmacy.
- Fix: Either implement a stamp duty line item for TN receipts, or explicitly mark the demo as "fiscal simulation only — stamp duty pending" with a note visible to the demo audience.

---

### Area 3: Seeder Design — Reuse vs Fork

**[BLOCKER] Hook/override pattern is not feasible without prior refactoring**
- Evidence: `apps/api/database/seeders/ParapharmacyMultiBranchSeeder.php:72` (`final class`), `:204`, `:313`, `:407`, `:569`, `:607` (all `private` methods); `apps/api/database/seeders/ParapharmacySeeder.php:534`, `:1165`
- Problem: `ParapharmacyMultiBranchSeeder` is `final` with all substantive methods `private`. You cannot extend it or inject locale overrides without modifying the class. The spec's "extract hooks" plan requires a non-trivial refactor of a production seeder used in CI.
- Fix: For demo timeline, **fork** into `DemoPharmacySeeder` rather than pretending hooks are extractable. If the refactor is desired later, do it as a separate PR before the next locale.

**[BLOCKER] France coupling is pervasive and interleaved, not isolated**
- Evidence: `apps/api/database/seeders/ParapharmacyMultiBranchSeeder.php:208` (FR), `:210` (EUR), `:213` (SIRET), `:245` (Paris), `:264` (siren), `:267` (nic), `:291` (Lyon), `:456` (French pharmacy names); `apps/api/database/seeders/ParapharmacySeeder.php:430` (APE code), `:789` (French barcode prefix), `:1167` (20% VAT rate)
- Problem: FR locale data (country code, currency, SIRET/SIREN/NIC/APE, city names, French pharmacy trade names, French barcode prefix, 20% VAT rate, France COA accounts) appears in at least 12 distinct locations across the two seeders. There is no locale config object — these are inline strings.
- Fix: A Tunisia fork must replace all 12+ locations. Document them as a checklist in the seeder or a comment block at the top.

---

### Area 4: Tauri POS Against Remote Staging

**[MAJOR] POS binary must be rebuilt for staging — spec does not say so explicitly**
- Evidence: `apps/pos/.env.production:2` (`VITE_API_URL=https://api.riserpos.app`); `apps/pos/src/stores/authStore.ts:134` (uses `import.meta.env.VITE_API_URL`); `apps/pos/src/lib/echo.ts:79` (Reverb host from `import.meta.env`)
- Problem: `VITE_API_URL` is baked in at build time. The production binary points to `api.riserpos.app`, not the Dokploy staging server. Running the production binary against staging is impossible. The spec says "point POS at staging" but does not prescribe a staging `.env` file and a dedicated `tauri build` step.
- Fix: Add to the runbook: create `apps/pos/.env.staging` with the correct staging API URL and Reverb host, run `pnpm tauri build --config src-tauri/tauri.staging.conf.json` (or equivalent), distribute the staging binary separately. Never use the production binary for staging demos.

**[MAJOR] CORS and Reverb host for staging are not configured**
- Evidence: `apps/api/config/cors.php:22` (allowed origins list defaults to local Vite origins); `apps/pos/src/lib/echo.ts:79` (Reverb host from env)
- Problem: The Dokploy staging server's CORS config will reject the Tauri app's requests unless `tauri://localhost` (or the desktop app's origin) is in `CORS_ALLOWED_ORIGINS`. Reverb WebSocket connections will fail if the host/port/scheme in the staging `.env` don't match the deployed Reverb instance.
- Fix: Staging server `.env` must include the Tauri origin in `CORS_ALLOWED_ORIGINS`. Runbook must document Reverb `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` for staging.

**[MINOR] Terminal count is inconsistent between spec sections**
- Evidence: Spec line `:63` says four shop terminals; spec line `:140` says claim three terminals
- Problem: If Sfax is in scope (4 shops), all 4 terminals must be claimed. If only 3 are claimed, the Sfax terminal will fail to authenticate.
- Fix: Decide: 3 shops or 4. Make the count consistent in the seeder and the runbook.

---

### Area 5: Manual Sales for History — Hidden Blockers

**[MAJOR] Seeded terminals must be active and unclaimed for POS login to work**
- Evidence: `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:303` (request flow), `:329` (activate gate), `:397` (claim flow), `:225` (status check)
- Problem: The spec conflates two different terminal flows: (a) seeded-active-unclaimed terminals that the POS operator can claim immediately, and (b) terminals that go through request → admin activates → claim. If the seeder seeds terminals in `requested` status, no demo sale is possible until an admin activates each terminal in the web panel — a manual step not mentioned in the runbook.
- Fix: Seeder must create terminals with `status=active` and `claimed_at=null` so the POS claim flow works immediately. Document this explicitly.

**[MEDIUM] Fiscal chain initialization is implicit — dry-run required before demo day**
- Evidence: `apps/api/app/Modules/Company/Domain/Company.php:125` (fiscal chain head per company); terminal migration `2026_01_08_190429...php:38` (genesis seed on terminal creation)
- Problem: Chain genesis is seeded on terminal creation (no separate init step needed). However, if projections fail to process (Horizon down), the POS will show sales that the web admin cannot see, creating a confusing demo state.
- Fix: Mandatory dry-run: ring 1 sale per terminal → Z-report → verify all events projected in web admin before demo day.

**[LOW] Offline-first queue drain during demo**
- Problem: If the staging server is unreachable mid-demo, the POS queues locally (SQLite). This is correct behavior but may alarm the demo audience if they can see a "syncing" indicator.
- Fix: Ensure the demo environment has reliable internet. Note in the runbook.

---

### Area 6: Enrichment Branch Check

**[MAJOR] Spec uncertainty about enrichment branch is stale for this worktree**
- Evidence: Current branch = `feat/demo-pharmacy-account`; `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php:88` (enrichment routes present); `apps/web/src/features/enrichment/components/EnrichmentReviewPanel.tsx:95` (enrichment UI present); local branch `feat/parapharmacy-enrichment-erp` also exists
- Problem: Enrichment code (API routes + web panel) is already present on this worktree's current branch. The spec's hedge ("this may be on a separate branch") is incorrect. The real gate is whether the integration works end-to-end: platform API key configured, enrichment job dispatched, webhook received, product data saved.
- Fix: Replace the branch uncertainty with a staging integration smoke test: configure `SYNERIVIA_PLATFORM_URL` + `SYNERIVIA_API_KEY` on staging, trigger enrichment for 2-3 products, verify the review panel shows results, accept one product, verify the product is updated in the ERP catalog.

---

### Area 7: Sequencing Gaps

**[MAJOR] Projection recovery command exists but is absent from demo readiness checklist**
- Evidence: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php:95`, `:32` (`--actor-id` parameter); `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:973`
- Problem: If projections fail during the demo dry-run (Horizon blip, queue flush), there is a recovery command. But the spec/runbook does not mention it. A demo operator who sees blank Z-reports in the web admin has no documented recovery path.
- Fix: Add to runbook: if web admin shows missing receipts/Z-report after dry-run sales, run `php artisan fiscal:enqueue-resolved-projections --actor-id=<company_uuid>`.

**[MEDIUM] No explicit step for verifying Tunisia COA is seeded before first sale**
- Evidence: `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:1` (exists); `apps/api/database/seeders/TunisiaTaxConfigurationSeeder.php:1` (exists)
- Problem: The spec assumes these seeders are called by `DemoPharmacySeeder`. But the call chain is not written yet. If either seeder is skipped, the first sale will 500 at the journal-entry creation step.
- Fix: Add explicit seeder call sequence in the spec: `TunisiaChartOfAccountsSeeder` → `TunisiaTaxConfigurationSeeder` → `TunisianParapharmacySeeder` → terminal seeding → test sale.

---

## Critical Path to Demo-Ready

1. **Fork the seeder.** Create `DemoPharmacySeeder` (fork of `TunisianParapharmacySeeder`/`ParapharmacyMultiBranchSeeder`) — do not attempt hook extraction under demo time pressure.
2. **Implement explicit DB-per-tenant provisioning** in the seeder: create DB → migrate → initialize tenancy → seed COA → seed tax config → seed company/branches/partners/products/terminals (active, unclaimed).
3. **Replace all 12+ France data points** with Tunisia equivalents: TN/TND/matricule fiscal format/Tunis-Sfax-Monastir-Sousse/Tunisian pharmacy names/TN barcode prefix/19% VAT/Tunisia COA.
4. **Fix Z-report and receipt to render Matricule Fiscal** using `TaxIdentityResolver` with branch context for TN.
5. **Decide on stamp duty** (timbre fiscal): implement or explicitly exclude with a demo caveat.
6. **Build a staging POS binary** with `VITE_API_URL` pointing to the Dokploy staging server + matching Reverb host. Configure staging CORS to allow the Tauri origin.
7. **Configure staging server**: `SYNERIVIA_PLATFORM_URL`, `SYNERIVIA_API_KEY`, Reverb, Horizon workers for `fiscal-projections`.
8. **Dry-run on staging** (day before demo): run seeder → claim 4 terminals → ring 1 sale per branch → verify projections in web admin → run Z-report → verify enrichment flow for 3 products.
9. **Recovery runbook**: document `fiscal:enqueue-resolved-projections --actor-id=<uuid>` and Horizon restart steps.

---

## Overall Verdict

**NEEDS-REWORK.** Two BLOCKERs (seeder class is `final`/private, making hook extraction impossible under demo timeline; tenant DB provisioning is not handled in the spec's proposed seeder design) plus four MAJORs (Tunisia receipt/Z-report fiscal identity rendering, Tauri staging build gap, CORS/Reverb gap, terminal status gap) would each independently cause a demo failure. The spec is directionally correct but must be rewritten to reflect the real seeder architecture and the real Tauri build/deploy chain before any implementation starts.
