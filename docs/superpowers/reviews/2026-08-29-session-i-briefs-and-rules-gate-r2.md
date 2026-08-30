# Session I — adversarial gate r2: lane briefs I-1 / I-2 (r2) + docs commit `0e33e79bf`

**Reviewer:** tenancy-authz-reviewer (Opus, adversarial, code-grounded)
**Date:** 2026-08-29
**Round 1 record:** `docs/superpowers/reviews/2026-08-29-session-i-briefs-and-rules-gate-r1.md`
**Artifacts reviewed (all revised):**
1. `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I1-onboarding-campaign-BRIEF.md` (r2)
2. `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I2-fresh-tenant-guards-BRIEF.md` (r2)
3. docs commit `0e33e79bfaed251105841d4fb685407e1a165064` (amended) in `.worktrees/i-docs`, parent `cdc54f9f4` — 16 files, **513 insertions / 2 deletions**

**Tree verified against:** main checkout `/Users/houssamr/Projects/syneriva/apps/erp`, local `dev` @ **`c3cb8261e`** — i.e. dev has MOVED since r1 (`145433173` → `c3cb8261e`) and **G-12 is now MERGED** (`ddc466b7e` "SG-2 — Session G lane G-12 merged local dev", `4e7f9a031` merge of `fix/g12-g7-merge-interaction`). G-3a is still NOT merged.
**Live-schema evidence** read-only from local PG `127.0.0.1:5433`, tenant DB `tenant01a03028-9470-70e6-83ca-cdc354f17cf1` (**560 of 572 tenant migrations applied — 12 behind dev**; the interval was re-checked for unique-index changes, see I2-R2-05).

---

## VERDICTS

| Artifact | Verdict |
|---|---|
| **I-1 brief r2** (onboarding campaign) | **CHANGES-REQUIRED** — 3 BLOCKER, 3 MAJOR, 2 MINOR |
| **I-2 brief r2** (fresh-tenant guards + ratchet) | **CHANGES-REQUIRED** — 2 BLOCKER, 3 MAJOR, 2 MINOR |
| **docs commit `0e33e79bf`** | **CHANGES-REQUIRED** — 1 MAJOR, 2 MINOR (doc text only; r1's BLOCKER and all 5 r1 MAJORs are resolved) |

**One line before dispatch:** I-1 must vendor only the two alias-free canonical files (`hashService.ts` is the WRONG hash), put the golden test in a runner that actually collects it, name the terminal/genesis-seed prerequisite and pre-declare L9 as NOT_SCRIPTABLE (there is no `CASH_COUNT` event type); I-2 must pin `RefreshDatabase` on the ratchet and restate invariant 7 (the Products importer never writes `unit_id` at all); the glossary's "Settings → Companies" surface does not exist.

---

# I-1 — Automated onboarding campaign (r2)

## BLOCKER

### I1-R2-01 — §2a's vendoring set names the WRONG hash file and an unbuildable import graph; 90 minutes is not a real budget for what is listed
The server contract is `current_hash = sha256(canonical_bytes)` over the canonical **payload** string:
- `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:776` — quarantine reason `canonical_hash_mismatch:sha256(canonical_bytes)!=current_hash`; `:423` — `$this->integrity->verify($envelope->canonicalBytes, $envelope->currentHash)`.
- Device counterpart: `apps/pos/src/lib/fiscal/HashChainIntegrityProvider.ts:16-21` — `computeHash(canonicalBytes) => this.encoder.sha256Hex(canonicalBytes)`.

The brief names `apps/pos/src/lib/fiscal/hashService.ts`. That file is the **legacy receipt chain**: `computeFiscalHash()` at `:39-54` hashes `previousHash|receiptNumber|postedAt|total|currency|vatHash|paymentHash`. Vendoring it produces a hash the ingestor will never accept — the campaign would quarantine every event and the implementer would debug the wrong layer.

The payload builders are not "minimal" either:
- `apps/pos/src/lib/fiscal/payloads/SaleReceiptV5Payload.ts:42-67` imports `@/lib/currency`, `@/lib/decimal`, `@/lib/fiscal/FiscalEventEngine` (**4133 lines**), `SaleReceiptPayload` (396), `SaleReceiptV2Payload` (100), `SaleReceiptV3Payload` (197), `vatDiscountAllocation` (264).
- `apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts:26-38` additionally imports `@/lib/db/repositories/fiscalEventRepository` (the DEVICE SQLite repository) and `@/types/cart`.
- **Alias collision, verified:** `apps/web/vite.config.ts:20-22` maps `@` → `apps/web/src`. `apps/web/src/lib/currency.ts` **does not exist**, and `apps/web/src/lib/decimal.ts:50,65,115` exports `bcadd`/`bcsub`/`bccomp` but **not** `bcformat`/`bcabs` (which the POS versions do — `apps/pos/src/lib/decimal.ts:92,88`). A vendored copy that keeps its `@/…` imports therefore half-fails to resolve and half-binds to a *different* implementation, silently.

The genuinely self-contained core is exactly two files: `FiscalEventCanonicalEncoder.ts` (163 lines; its only import is `./canonicalCore`, `:24-27`) and `canonicalCore.ts` (108 lines, zero imports).

**Required change:** restrict the vendored set to `canonicalCore.ts` + `FiscalEventCanonicalEncoder.ts` (+ the 4-line integrity shape), rewrite every `@/` import to a relative path, state `current_hash = sha256(canonical_bytes)` explicitly, and **hand-author the v5 / v4 payload objects** from the golden fixture instead of vendoring the builders. That is a 90-minute task; the version in the brief is not.

### I1-R2-02 — the golden-vector test, as offered, runs in NO runner
`apps/web/vitest.config.ts:12` — `include: ['src/**/*.{test,spec}.{ts,tsx}', 'tools/**/*.{test,spec}.{ts,mjs}']`. `e2e/**` is not collected by anything. A "plain vitest under `e2e/campaign/fiscal/__tests__/`" (§2a's second option) executes on **no** command and in **no** CI job — the exact dead-detector class `docs/conventions/08-DETECTOR-LIVENESS.md` exists to prevent, shipped inside the lane that authors convention 09.
The first option, `fiscal.golden.campaign.ts`, *does* match the campaign config's `testMatch *.campaign.ts` — but then it runs serially against a live target it does not need.
**Required change:** choose ONE and make it real. Either (a) add `'e2e/**/*.{test,spec}.ts'` to the vitest `include` and require `pnpm vitest run e2e/campaign/fiscal` output in verification step 3, or (b) make it campaign leg **L-0a "golden vector"**, first in serial order, network-free, with its own ledger row.

### I1-R2-03 — L6/L9 omit the terminal-registration + genesis-seed prerequisite, and L9 chases an event type that does not exist
- **Genesis seed.** `OutboxIngestor.php:487-519` and `:682-708`: the FIRST event on a terminal must carry `previous_hash == pos_terminals.genesis_seed`, looked up **server-side**; an unknown terminal yields `sequence_gap:terminal_not_found_for_genesis_seed_lookup` (`:509-511`). The campaign must therefore create/claim a terminal and obtain its seed before any event. Routes exist and are unnamed in the brief: `apps/api/app/Modules/POS/routes.php:53` (`POST /pos/terminals`), `:56` (`/pos/terminals/claim`), `:60` (`/pos/terminals/web`, `getOrCreateWebTerminal`).
- **No `CASH_COUNT` type.** `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php:9-43` has no such case. The cash count is a FIELD SET on `SESSION_CLOSE` (`cash_count_lines`, `counted_cash`, `expected_cash`, `variance_amount|direction|reason|severity`) and on `Z_REPORT` (`cash_count`).
- **Wrong pointer.** The brief sends the implementer to `apps/pos/src/lib/fiscal/payloads/` for the session/Z payload names. That directory contains only `AccountChargePayload`, `AccountPaymentPayload`, `SaleReceiptPayload`, `SaleReceiptV2/V3/V5Payload`, `RefundReceiptV4Payload`. The key sets live at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1317` (SESSION_OPEN, 14 keys), `:1374` (SESSION_CLOSE, 28 keys), `:1405` (Z_REPORT, **32 keys** incl. `grand_totals_before/after`, `company_snapshot`, `seller`, `tolerance_summary`, `operational_event_range`), and the device-side authoring is `zSessionAuthoring.ts` (which reads the device SQLite DB directly — `:221`, `:279`).
**Required change:** name the terminal routes and the genesis-seed step in L6; correct the payload-key anchors; and **pre-declare L9 as `NOT_SCRIPTABLE(Z-session authoring not vendored)`** with its own follow-up lane, rather than letting it share the 90-minute canonicaliser budget. Hand-authoring a 32-key Z_REPORT payload is not a leg, it is a lane.

## MAJOR

### I1-R2-04 — L0's "create a SECOND company via the UI (Settings → Companies)" — that surface does not exist
- `apps/web/src/features/settings/CompanyPage.tsx:190, 207, 232, 250` — `updateMutation`, `updateProcurementMutation`, `uploadLogoMutation`, `deleteLogoMutation`. No create, no list. Settings → Companies edits the CURRENT company only.
- The only reachable creation path is the **company switcher**: `apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:57-60` — `handleAddCompany()` → `navigate('/company-onboarding')`; route at `apps/web/src/routes/index.tsx:542-552`; the page calls `createCompany` (`apps/web/src/features/company/api.ts:85,101` → `POST /companies`).
- `apps/web/src/components/organisms/AddCompanyModal/AddCompanyModal.tsx` calls the same `createCompany` (`:66`) but is **rendered nowhere**: the only importers are the barrels (`src/components/organisms/index.ts:6`, `src/features/company/AddCompanyModal.tsx:2`) and two test files. A dead second surface for the same concept — a convention-11 finding in its own right.
**Required change:** point L0 at the company switcher → `/company-onboarding` (or `POST /api/v1/companies` labelled `API-contract`), and record BOTH the missing Settings→Companies entry point and the orphaned `AddCompanyModal` as product findings.

### I1-R2-05 — L4's payload pointer is to a test that does not use the API; the real API column is `rows[].repository_code`
- `apps/api/tests/Feature/Accounting/OpeningCashFloatSeedsRepositoryTest.php` creates `OpeningBalanceImportRow` rows with **direct Eloquent** (`:636-660`); it only HTTP-posts the `post` action (`:513-514`, `:532-533`). Reading it "for the payload" yields something the campaign cannot send.
- The sanctioned API column is `rows.*.repository_code` on `opening-batches.import` — `apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php:408`; validated at `AccountingOpeningService.php:299-377` (unknown/inactive code → `Payment repository '…' was not found or is inactive.`), applied at `:522` and `:756`.
- **Bank opening IS supported today** — delete the "if supported" hedge: `OpeningCashFloatSeedsRepositoryTest.php:136` (a `BankAccount` repository `BANK-01` with its own 512 account), `:150` (row for 5000.000), `:157` (repository balance), `:206-207` (GL bank debit).
**Required change:** L4 names the sequence `POST …/opening-batches` → `…/{batchId}/import` with `rows[].repository_code` → `…/validate` → `…/post`, then L5's `…/lock`.

### I1-R2-06 — L2's two unit assertions measure two different columns, and the importer never writes `unit_id`
- `apps/api/app/Modules/Product/Application/Services/ProductService.php:73` — the import path sets `'unit' => $this->emptyToNull($data['unit'] ?? null)`, the free-text varchar. Grep over `app/Modules/Import/**` and `ProductService::importProduct` finds **no** `unit_id` write anywhere.
- The `unit` ⇄ `unit_id` resolution exists ONLY on the controller create/update path: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:1173-1228` ("FORWARD direction (unit_id → unit)" … `$validated['unit_id'] = $match->id;`).
- Live columns confirm both exist and both are nullable: `products.unit` `character varying` NULL, `products.unit_id` `uuid` NULL.
So **every** imported product has `unit_id = NULL`, whether or not the file carried a unit code — the brief's "one row with a blank `unit`" contrast is not the discriminator it thinks it is.
**Required change:** L2 asserts the `unit` string against the file, and records `unit_id = NULL for BOTH rows` as ONE product finding ("Products import never resolves `unit` → `unit_id`"). Mirror the same correction in I-2 invariant 7 (I2-R2-02).

## MINOR

### I1-R2-07 — base is stale; G-12 has landed and changed the second-company path
Brief line 4 pins `cdc54f9f4`; local `dev` is `c3cb8261e`. `CompanyController::store()` now calls `$this->unitsProvisioning->provisionForCompany($company)` (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:189`), so L0's second-company census must EXPECT units for company 2 (it previously would not have). Add the same "first action: `git merge dev`" line I-2 carries.

### I1-R2-08 — name the golden fixtures and their keys instead of hedging
`apps/api/tests/Fixtures/Fiscal/` contains: `canonical-golden-vectors.json`, `sale-receipt-golden/`, `sale-receipt-v2-golden.json`, **`sale-receipt-v4-refund-golden.json`**, **`sale-receipt-v5-golden.json`**, `v3-golden-hashes/`. The v4 refund golden IS present — drop "(and the v4 refund golden if present)". The v5 file's keys are `event_type`, `event_version`, `expected_canonical_string`, `expected_sha256_hex` (`_comment` documents the worked example: gross 640.000 TND, 50.000 remise, sealed base 524.547 + VAT 65.453). Naming them removes a guessing step.

## NOTE

### I1-R2-09 — no silent 403 on the fiscal ingress for a fresh tenant
`POST /api/v1/pos/sync/fiscal-events` is gated `can:pos.operate_terminal` (`apps/api/app/Modules/Fiscal/routes.php:52-53`), and the permission is seeded (`database/seeders/RolesAndPermissionsSeeder.php:364`) and held by `admin` unconditionally (`:544-545` — `syncPermissions($roleName === 'admin' ? Permission::all() : …)`, `:559`). The middleware tuple is rule-12 compliant (`routes.php:30`). No permission work is owed by this lane.

---

# I-2 — Fresh-tenant census + tenant-only-unique ratchet (r2)

## BLOCKER

### I2-R2-01 — the live-schema ratchet has no stated database lifecycle; verification step 2 as written reads an UNMIGRATED database
- The `backend-test-pgsql` job (`.github/workflows/ci.yml:578-605`, run step ending at the `--filter` line `:1096`) never runs `php artisan migrate`. The schema exists only because tests use `RefreshDatabase`.
- The schema the ratchet would read is the single-connection union of central + tenant migrations: `apps/api/app/Providers/AppServiceProvider.php:232-239` (`loadTenantMigrationsInTestingEnvironment()` — `$this->loadMigrationsFrom(database_path('migrations/tenant'))`, testing env only), with `phpunit-pgsql.xml` forcing `DB_CONNECTION=pgsql` and `TENANCY_DB_PER_TENANT=false`. That part is sound — but only if the schema is built.
- The brief never says the ratchet uses `RefreshDatabase`. Run in isolation (`php artisan test -c phpunit-pgsql.xml --filter='TenantOnlyUnique'` — verification step 2) against a fresh `autoerp_test`, `pg_index` returns nothing: **every baseline entry reports "stale"** and the growth direction can never fire. A ratchet that reports the exact inverse of the truth is worse than none.
**Required change:** mandate `use RefreshDatabase;` on BOTH `TenantOnlyUniqueOnCatalogueTablesRatchetTest` and `TenantOnlyUniqueRatchetLivenessTest`, and state in the brief that the introspected schema is the central+tenant union (so `CATALOGUE_TABLES` is the only scoping and a central table can never leak into the report).

### I2-R2-02 — invariant 7 is still mis-specified: the contrast it draws does not exist
Same evidence as I1-R2-06: `ProductService.php:73` writes only `products.unit`; nothing on the import path writes `products.unit_id`; the resolution lives only at `ProductController.php:1173-1228`. The brief's pre-declared finding — *"blank unit → NULL unit_id; G-12 only refuses when NO units exist"* — mis-locates the gap, and the "one row WITH a unit code, one WITHOUT" design produces an identical result for both rows, so the test proves nothing about the blank row.
**Required change:** restate invariant 7 as the true observed contract — *"the Products import writes `products.unit` (varchar) and NEVER `products.unit_id`, for every row"* — assert exactly that for both rows, and let the `markTestIncomplete` name the real gap and the owner lane. Do not attribute the gap to G-12 (which is now merged and adds a 422 refusal + per-company provisioning, `CompanyController.php:189`, `UnitsProvisioningService.php:49-73`, migration `2026_08_30_100300_ensure_units_visible_per_company.php`).

## MAJOR

### I2-R2-03 — the liveness fixture does not prove the STALE direction the brief declares
Brief line 46 declares two failing directions (growth **and** stale). Line 47's liveness plants only a growth case and a negative (`(tenant_id, company_id, code)`) case. `docs/conventions/08-DETECTOR-LIVENESS.md:52-56` requires, for a shrink-only ratchet, a case per distinct pattern the guard claims — and the stale direction is the whole reason the design moved from text to live schema (r1's I2-R1-01).
**Required change:** add a third liveness case — feed the checker an in-memory baseline containing an index that does not exist in the schema and assert it FAILS with the stale message. Do not mutate the committed baseline JSON to do it.

### I2-R2-04 — the `markTestIncomplete` instruction is self-contradictory and, as written, leaves the lane GREEN over a known P0
Brief line 22: *"write the assertion at full strength and let it fail; wrap ONLY that method in `markTestIncomplete(...)` AFTER asserting the gap exists (assert 0 repositories …)"*. Those are mutually exclusive — once you assert 0 and mark incomplete, the full-strength assertion never executes; and PHPUnit reports **incomplete as not a failure**, so `php artisan test` exits 0 and CI is green over "a second company cannot take cash".
**Required change:** (a) delete "let it fail"; (b) make the non-green channel explicit — `DayOneCensus` must return `passed = false` for invariant 5 on the second company so `php artisan tenants:run tenant:census-day-one --option=fail-on-drift` exits 1; (c) `DayOneCensusCommandTest` must assert THAT (a company-2 fixture ⇒ invariant 5 row FAIL ⇒ exit 1), otherwise the pre-declared product finding is recorded only in a test that cannot go red.

### I2-R2-05 — the pre-listed baseline was derived from a schema 12 migrations behind dev (it happens to still be right — keep "regenerate" binding)
- Local tenant DB: `select count(*) from migrations` → **560**; newest applied `2026_08_25_130500_add_enum_check_constraints_to_instrument_events`. `ls database/migrations/tenant/*.php | wc -l` → **572**; newest `2026_08_30_100300_ensure_units_visible_per_company.php`.
- I re-checked the whole interval for unique-index changes on catalogue tables. None: `2026_08_28_100000_enforce_company_scoped_payment_method_codes.php:32,65` only normalises `payment_methods` toward `payment_methods_company_id_code_unique`; `…_seed_base_units_for_unit_less_tenants`, `…_backfill_default_location_code_f1`, `…_ensure_units_visible_per_company` and `…_add_enum_check_constraints_to_documents` add none.
- So the brief's 13 entries match the live set exactly (verified by `pg_index`/`pg_get_indexdef` filtered on `(tenant_id` and NOT `company_id`): `products(tenant_id,sku)`, `product_variants(tenant_id,sku)` partial, `product_variants(tenant_id,barcode)` partial, `partners(tenant_id,vat_number)`, `units(tenant_id,code)`, `unit_categories(tenant_id,code)` partial, `product_attributes(tenant_id,code)`, `brands(tenant_id,slug)`, `brands(tenant_id,canonical_brand_id)`, `vehicles(tenant_id,vin)`, `vehicles(tenant_id,license_plate)`, `loyalty_members(tenant_id,phone)`, `documents(tenant_id,type,document_number)`. `documents` has **no other** tenant-leading unique — the CATALOGUE_TABLES addition carries no collateral.
- Confirmed nothing to report for the other catalogue tables: `payment_methods`, `payment_repositories`, `accounts`, `categories`, `locations`, `pos_terminals`, `tax_configurations`.
**Required change:** mark the 13-entry list **indicative**, make "regenerate from the migrated test schema" the binding instruction, and say the reviewer will diff the generated baseline against the live set.

## MINOR

### I2-R2-06 — line-number citations go stale the moment the mandated `git merge dev` runs
`CompanyController::store()` no longer ends at `:186` — the provisioning block now runs to `:191` and adds `unitsProvisioning->provisionForCompany()` at `:189`. Invariant 1's future tense ("G-12 adds a refusal + per-company visibility") is already past. Cite by symbol (`CompanyController::store()`, `TenantProvisioningService::initializeForNewRegistration`), not by line, for the files the brief itself says will move.

### I2-R2-07 — say in one line that I2-R1-11/12 are moot
The comment/docblock decoys and the named-index second argument were text-scanner concerns. The live-schema design retires them. Record that explicitly so a future reader does not re-open them as unaddressed.

## NOTE

### I2-R2-08 — anchors re-verified GOOD on current dev
- `apps/api/tests/Architecture/baselines/` **EXISTS** with `document-per-action-baseline.json`, `enum-check-parity-baseline.json`, `enum-check-parity-central-baseline.json`, `enum-check-parity-acknowledgements.json`, `enum-check-parity-register.md` — r1's I2-R1-14 ("cannot verify") is CLOSED; the brief's convention claim is correct.
- `apps/api/phpunit-pgsql.xml` exists; its `<testsuites>` include `tests/Architecture` (`:26-38`), so an Architecture class reached through the `backend-test-pgsql --filter` runs.
- `apps/api/tools/feature-lane-manifest-check.php:327-334` — *"filter RESOLUTION uses"* Unit/Feature/Integration/Architecture/PHPStan/E2E; group DISPOSITIONS stay scoped to `tests/Feature`. So adding the two Architecture classes to that filter will NOT trip the checker, and only the `Tenant` group ceiling (currently **29**, `tests/feature-lane-manifest.json`) needs raising to 31.
- **`markTestSkipped` on SQLite is not reported as a misleading pass anywhere:** no SQLite lane collects the new classes — `backend-architecture` runs a named file list (`ci.yml:202, 215, 228`), `backend-test` runs `--testsuite=Unit`, `backend-dpa-guard` runs named DPA classes. The only risk is a developer running `tests/Architecture/` locally on SQLite; the skip message the brief specifies already says why.
- `ProvisioningRequiredPurposesV1::requiredPurposes()` at `:115`; the partition assertion `28 + 1 + 4 + 10` at `:293`.
- `CleanRegistrationDownstreamAssumptionsTest::test_onboarding_checklist_payment_repositories_step_is_satisfied` at `:178`; `TenantReferenceDataSeedingTest::makeTenantCompanyUser` at `:141`.
- **19 units is exact**, not a floor: `tests/Feature/Uom/UnitsProvisioningTest.php:95` — `assertCount(19, $codes)`. "≥ 19" is a safe weaker form.
- **I2-F1 still stands on current dev:** `PaymentRepositorySeeder` has exactly one app call site (`app/Modules/Tenant/Application/Services/TenantInitializationService.php:26, 315`); `CompanyController::store()` seeds chart (`:170`), payment methods (`:175`), expense categories (`:184`), taxes (`:187`), units (`:189`) — and no repositories.

---

# Docs commit `0e33e79bf`

## MAJOR

### DOC-R2-01 — glossary "Company" row names a canonical surface that cannot create a company
`docs/glossary.md:18` — Company · canonical surface **"Settings → Companies"**. Evidence as I1-R2-04: `apps/web/src/features/settings/CompanyPage.tsx:190-250` carries update/logo mutations only; creation is reached from the **company switcher** (`apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:57-60` → `/company-onboarding`, `src/routes/index.tsx:542-552`, `src/features/company/api.ts:85,101`), and `AddCompanyModal` (`src/components/organisms/AddCompanyModal/AddCompanyModal.tsx:66`) is a **dead duplicate surface** rendered by nothing.
This matters more than usual: the same commit tells seven reviewers to grep this registry for "the canonical surface", and convention 11 is *about* undeclared duplicate surfaces. **Fix:** name the switcher → `/company-onboarding` path, and declare the orphaned modal (Session? owner ruling owed).

## MINOR

### DOC-R2-02 — DOC-R1-07 is half-fixed: the path is right, the reciprocal link is still uncommitted
`docs/glossary.md:7` and `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:36` now say `../../../claude/glossary.md`, which from `apps/erp/docs/` resolves to `/Users/houssamr/Projects/syneriva/claude/glossary.md` — **correct**.
But the glossary asserts *"it points here for ERP terms"* while the reciprocal edit is NOT committed: `git -C /Users/houssamr/Projects/syneriva status --porcelain claude/` → ` M claude/glossary.md` (the pointer text is at `claude/glossary.md:61`). Commit it in the parent repo, or mark it owed in the session HANDOVER — a registry that documents a link nobody can see from `main` is the drift this lane exists to stop.

### DOC-R2-03 — convention 09's incident row lists 12 of the 13 live instances
`docs/conventions/09-SECOND-OF-EVERYTHING.md:19` omits `brands(tenant_id, canonical_brand_id)` (live index `brands_tenant_canonical_unique`), which the I-2 baseline DOES include (brief line 45). A reviewer citing convention 09 as authority would flag that entry as new. Align the two lists.

## NOTE

### DOC-R2-04 — every r2-changed glossary row re-verified CORRECT
- **Import type** (`:68`) — inverted claim FIXED. `apps/api/app/Modules/Import/Domain/Enums/ImportType.php:39` (`case StockLevels`, `@deprecated` docblock `:14-38`), `deprecationMessage()` `:50-56`, `isDeprecated()` `:65-68`, `selectable()` `:75-80`; `case Partners` at `:10` carries no deprecation. The added claim *"today Parties and Partners share `importPartner`"* is TRUE: `app/Modules/Import/Services/ImportService.php:417-418` (`Parties => importParty`, `Partners => importPartner`), `:446` (`importParty` delegates to `importPartner`), `:497`.
- **Product / SKU** (`:36-37`) — `products_tenant_id_sku_unique` is live; also `product_variants_tenant_sku_unique` / `_barcode_unique`; no G-3a migration on dev. "Pending" is accurate.
- **Stock level** (`:41`) — table `stock_levels`; `stock_levels_non_variant (tenant_id, product_id, location_id) WHERE variant_id IS NULL` and `stock_levels_with_variant (tenant_id, product_id, variant_id, location_id) WHERE variant_id IS NOT NULL`.
- **Document number** (`:64`) — `documents_tenant_id_type_document_number_unique (tenant_id, type, document_number)` vs `document_sequences_company_id_type_year_unique`. Correct, including the "open hazard" framing.
- **Repository** (`:51`) — single seeder call site confirmed (I2-R2-08).
- **Contact** (`:29`) — BOTH tables exist in the tenant DB and both hold **0 rows**; the module carries `Contact.php` and `PartyContact.php` (`app/Modules/Contact/Domain/`). The "Session H rules on the second one's fate" declaration is the right shape.
- **Party** (`:26`) — two tiles today, retirement is G-9. Correct.
- **Purpose** (`:48`) — `requiredPurposes()` `:115`; partition `28 + 1 + 4 + 10` enforced `:293`.
- **Unit** (`:38`) — "≥ 19 seeded on day one" is safe; the pin is exactly 19 (`tests/Feature/Uom/UnitsProvisioningTest.php:95`).

### DOC-R2-05 — round-0 check 6 is now WIRED, and convention 09's two false claims are fixed
`docs/superpowers/SPEC-GATE-ROUND0-MECHANICAL-PRECHECK.md:24` ("checks 1–6"), `:30` ("The six checks"), check body `:97-106`, report row `| 6 | Journey-hardening sections | PASS/FAIL | n found |` at `:131` — a check with a row can now fail the document under the `:141` verdict rule.
`docs/conventions/09-SECOND-OF-EVERYTHING.md:19` now lists the live tenant-only set (payment methods/repositories/accounts/partners-code correctly moved to "already fixed in `2025_12_30_195300`"); `:54-68` now describes the live-PG-schema design and the automatic staleness property; `:35` carries "(lands with lane I-2)".

---

## r1 → r2 resolution table

### I-1
| r1 finding | Status | Resolving line |
|---|---|---|
| I1-R1-01 (L9/L6 not scriptable via server routes) | **PARTIAL** | L6 (brief `:57`) names the 410/409 evidence and the `/pos/sync/fiscal-events` ingress; L9 (`:60`) still invents a "cash-count event" and points at `payloads/` → **I1-R2-03** |
| I1-R1-02 (canonicaliser mechanism + budget) | **PARTIAL** | §2a (`:62-63`) names a vendoring decision + 90-min budget + NOT_SCRIPTABLE escape — but the file set is wrong and unbuildable → **I1-R2-01/02** |
| I1-R1-03 (`batch_number` does not exist) | **RESOLVED** | L2 (`:53`) — exact `ImportType.php:125` list, "There is no `batch_number`/lot column … opening stock lands on the `DEFAULT` lot" |
| I1-R1-04 (retired `stock_levels` alternative) | **RESOLVED** | L3 (`:54`) — "the `StockLevels` import type is RETIRED — `ImportType.php:39` … do not use it" |
| I1-R1-05 (census hedge / psql escape hatch) | **RESOLVED** | Rules (`:14`) "No `psql` anywhere in the campaign"; read endpoints named at `:47` |
| I1-R1-06 (e2e never typechecked) | **RESOLVED** | Files (`:26-28`) — `apps/web/e2e/tsconfig.json` + `typecheck:e2e` are deliverables; verification 1 (`:89`) |
| I1-R1-07 (lock route missing company segment) | **RESOLVED** | L5 (`:56`) — `POST /api/v1/companies/{companyId}/opening-batches/{batchId}/lock` |
| I1-R1-08 (name the opening-float route) | **PARTIAL** | L4 (`:55`) names the opening batch and rules out the 422 adjustments path, but points at a test that uses direct Eloquent → **I1-R2-05** |
| I1-R1-09 (two tiles / near-miss) | **RESOLVED** | L1 (`:52`) — near-miss recorded, 4 HIST docs double as proof of the right wizard |
| I1-R1-10 (collision map — NOTE) | n/a | no change needed; still clean for `apps/web/e2e/**` |

### I-2
| r1 finding | Status | Resolving line |
|---|---|---|
| I2-R1-01 (text scanner rejected) | **RESOLVED** | Part C (`:41-43`) — live `pg_index`/`pg_get_indexdef`, PG-only, text scanning explicitly rejected |
| I2-R1-02 (baseline materially wrong) | **RESOLVED** | `:44-45` — `product_variants` added, `documents` ruled IN, 13 entries match live, "regenerate … do not hand-type" |
| I2-R1-03 (invariant 5 RED for company 2) | **RESOLVED** (mechanism flawed) | `:22` pre-declares the product finding with the call-site evidence — but the incomplete instruction is contradictory → **I2-R2-04** |
| I2-R1-04 (invariant 3 is a partition) | **RESOLVED** | `:27` — `requiredPurposes()` for the hard assertion; SCOPE_REQUIRED/CONDITIONAL/SOFT reported separately |
| I2-R1-05 (invariant 4 is a country pin) | **RESOLVED** | `:28` — "a **country-defaults pin**, not a manifest requirement", with the three seeder line refs |
| I2-R1-06 (invariant 7 premise false) | **PARTIAL** | `:31` drops the G-12 fallback attribution, but the restated contract is still the wrong column → **I2-R2-02** |
| I2-R1-07 (units stay tenant-scoped) | **RESOLVED** | `:25` — "tenant-scoped rows, per-company visibility", conditional deleted |
| I2-R1-08 (parked `Tenant` lane gates nothing) | **RESOLVED** | Rules (`:16`) — append both Feature classes to the LIVE `backend-test-pgsql --filter`; `:15` ceiling raise + group note |
| I2-R1-09 (`backend-architecture` is an explicit list) | **RESOLVED** | `:16` and `:48` — ratchet + liveness into the same pgsql lane; `backend-architecture` NOT touched |
| I2-R1-10 (ci.yml / manifest dirty in siblings) | **RESOLVED** | `:4` first action `git merge dev` + STOP on conflict; `:16` append-only, never reflow |
| I2-R1-11 (skip comments/docblocks) | **NOT-ADDRESSED — moot** | text scanner retired; say so → **I2-R2-07** |
| I2-R1-12 (named-index second argument) | **NOT-ADDRESSED — moot** | same |
| I2-R1-13 (pin the provisioning ORDER) | **RESOLVED** | `:20` (Main Location before repositories) and `:29` (negative case: seeder with no locations ⇒ `location_id` NULL) |
| I2-R1-14 (confirm `tests/Architecture/baselines/`) | **RESOLVED** | `:45` claims the directory exists — **independently verified**, see I2-R2-08 |
| I2-R1-15 (anchors real — NOTE) | n/a | re-verified, see I2-R2-08 |

### Docs
| r1 finding | Status | Resolving line |
|---|---|---|
| DOC-R1-01 (import-type retirement inverted) — BLOCKER | **RESOLVED** | `docs/glossary.md:68` — StockLevels is the retired case; Partners retirement pending G-9 |
| DOC-R1-02 (stock level table/uniqueness) | **RESOLVED** | `:41` |
| DOC-R1-03 (document number scope) | **RESOLVED** | `:64` |
| DOC-R1-04 (repository "per company") | **RESOLVED** | `:51` — "an additional company created via `POST /api/v1/companies` gets none today (open gap, product finding I2-F1)" |
| DOC-R1-05 (round-0 check 6 inert) | **RESOLVED** | `SPEC-GATE-ROUND0…md:24, :30, :131` |
| DOC-R1-06 (convention 09's two false claims) | **RESOLVED** | `09-SECOND-OF-EVERYTHING.md:19` (live set) and `:54-68` (live-schema ratchet + true staleness) — one omission, **DOC-R2-03** |
| DOC-R1-07 (platform glossary path + uncommitted reciprocal) | **PARTIAL** | path fixed at `glossary.md:7` / `11-…md:36`; reciprocal still uncommitted → **DOC-R2-02** |
| DOC-R1-08 (Contact duplicate surface undeclared) | **RESOLVED** | `:29` — both tables named, canonical declared, Session H owns the fate |
| DOC-R1-09 (futures stated as facts) | **RESOLVED** | `:26`, `:36-37` — "pending" on both G-3a and G-9 |
| DOC-R1-10 (points at a file that does not exist) | **RESOLVED** | `09-…md:35` — "(lands with lane I-2)" |
| DOC-R1-11 / DOC-R1-12 (NOTEs) | n/a | additivity re-checked: 513 insertions / 2 deletions, the 2 deletions being the "checks 1–5"/"five checks" lines of DOC-R1-05 |

---

## What to fix before dispatch

**I-1:** rewrite §2a (vendor `canonicalCore.ts` + `FiscalEventCanonicalEncoder.ts` only — `hashService.ts` is the legacy receipt hash; `current_hash = sha256(canonical_bytes)`, `OutboxIngestor.php:776`), put the golden test in a runner that collects it (`vitest.config.ts:12` excludes `e2e/**`), add the terminal/genesis-seed step (`OutboxIngestor.php:487-519`) and pre-declare L9 NOT_SCRIPTABLE (no `CASH_COUNT` type; Z_REPORT is 32 keys at `FiscalEventEngine.ts:1405`), point L0 at the company switcher → `/company-onboarding`, point L4 at `rows[].repository_code` (`OpeningBalanceBatchController.php:408`), and correct L2's `unit` vs `unit_id`.

**I-2:** pin `RefreshDatabase` on the ratchet + liveness, restate invariant 7 as "the import never writes `unit_id`", add the stale-direction liveness case, resolve the `markTestIncomplete` contradiction by routing the second-company gap through `DayOneCensus.passed=false` + `--fail-on-drift` exit 1, and mark the 13-entry baseline indicative.

**Docs:** fix the glossary Company row's canonical surface (and declare the orphaned `AddCompanyModal`), commit or mark-owed `claude/glossary.md` in the parent repo, add `brands(tenant_id, canonical_brand_id)` to convention 09's incident row.
