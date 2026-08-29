# Session I — adversarial gate r1: lane briefs I-1 / I-2 + docs commit `262d5f53c`

**Reviewer:** tenancy-authz-reviewer (Opus, adversarial, code-grounded)
**Date:** 2026-08-29
**Artifacts reviewed:**
1. `docs/sessions/session-I-process-hardening-2026-08-29/HANDOVER.md` (owner mandate)
2. `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I1-onboarding-campaign-BRIEF.md`
3. `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I2-fresh-tenant-guards-BRIEF.md`
4. docs commit `262d5f53cb9816e0a57022b52f4401fca9945337` in `.worktrees/i-docs` (branch `docs/session-i-process-hardening`, parent `cdc54f9f4`)

**Tree verified against:** main checkout `/Users/houssamr/Projects/syneriva/apps/erp`, `dev` @ `145433173`.
The briefs declare base `cdc54f9f4`; that commit **is** an ancestor of dev (`git merge-base --is-ancestor` → YES) and the
3 commits since are docs-only (`09977474f`, `c3b733a44`, `145433173`). Base is acceptable.

**Live-schema evidence** was taken read-only from local PG `127.0.0.1:5433`, tenant DB
`tenant01a03028-9470-70e6-83ca-cdc354f17cf1`, via `pg_index`/`pg_get_indexdef`. That is the ground truth used
below wherever a migration file and the live schema disagree.

---

## VERDICTS

| Artifact | Verdict |
|---|---|
| **I-1 brief** (onboarding campaign) | **CHANGES-REQUIRED** — 2 BLOCKER, 4 MAJOR |
| **I-2 brief** (fresh-tenant guards + ratchet) | **CHANGES-REQUIRED** — 4 BLOCKER, 6 MAJOR |
| **docs commit `262d5f53c`** | **CHANGES-REQUIRED** — 1 BLOCKER, 5 MAJOR (all doc-text fixes; nothing to revert — the commit is 507 insertions / 0 deletions) |

**One line before dispatch:** regenerate I-2's tenant-only-unique baseline and catalogue set from the **live
schema** (four of its named entries no longer exist and `product_variants` is missing), pre-declare the
second-company drawer gap as an expected RED, correct the glossary's inverted `ImportType` retirement and the
`stock_levels` / `documents` uniqueness claims, and tell I-1 that POS shift-open / cash-count / Z have **no
server route** on a non-demo tenant.

---

# I-1 — Automated onboarding campaign

## BLOCKER

### I1-R1-01 — L9 (cash count + Z) and L6's "shift open" are not scriptable through any server route on a fresh tenant
- `apps/api/app/Modules/POS/routes.php:114-121` — `/pos/shifts/open`, `/pos/shifts/{id}/close`,
  `/pos/cash-drawer/deposit`, `/pos/cash-drawer/payout`, `/pos/reports/x`, `/pos/reports/z` are **all** inside
  `Route::middleware(EnsureWebPosDemoTenant::class)`. The comment at `:109-113` states the rule: browser callers
  need `tenants.is_demo`; only Tauri devices (`X-Client-Type: pos-tauri`) pass. **A campaign-registered fresh
  tenant is not a demo tenant.**
- Even past that gate the routes refuse: `app/Modules/POS/Presentation/Controllers/ShiftController.php:63`
  (`open`) and `:127` (`close`) return 409 `SHIFT_DEVICE_AUTHORITY_REQUIRED`;
  `app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:79` returns 409
  `Z_SESSION_DEVICE_AUTHORITY_REQUIRED`; `app/Modules/POS/Presentation/Controllers/SyncController.php:56` the same.
- The ancestor campaign already recorded this:
  `docs/handoff/PLAYWRIGHT-first-tenant-campaign-wave4-critical-path-2026-08-24.md:1050` — *"3.12–3.14 | X-report,
  cash count + close, Z parity — **server routes are retired by design** (409 `Z_SESSION_DEVICE_AUTHORITY_REQUIRED`
  / `SHIFT_DEVICE_AUTHORITY_REQUIRED`)"*. Wave-4's D.4 shift-open PASS at `:576-583` was a device-authored
  `SESSION_OPEN` fiscal event, **not** `/pos/shifts/open`.
- **Why it matters:** the brief's L9 ("Count the drawer …, close the shift / Z") reads as a server-API leg. An
  implementer will burn the lane discovering a 409/403, and the ledger will report NOT_SCRIPTABLE for the wrong
  reason (looks like a product bug; it is a documented design).
- **Required change:** state in L6/L9 that the ONLY live path is device-authored fiscal events through
  `POST /api/v1/pos/sync/fiscal-events` (`app/Modules/Fiscal/routes.php:52`), name the event types
  (`SESSION_OPEN`, `SALE_RECEIPT`, refund, cash count, Z), and label the leg `API-contract (device-authored
  chain)` up front.

### I1-R1-02 — L6/L7 require re-implementing the device hash-chain canonicaliser; the brief gives no mechanism and no budget
- `app/Modules/POS/routes.php:191` — `POST /api/v1/pos/receipts` is retired (410
  `NEW_SALE_AUTHORING_RETIRED`); `:226` and `:240` retire void and receipt-payments the same way. The single
  ingress is `app/Modules/Fiscal/routes.php:52`.
- What that costs is documented at
  `docs/handoff/PLAYWRIGHT-first-tenant-campaign-wave4-critical-path-2026-08-24.md:531-545`: wave 4 *"reimplemented
  the device's canonicaliser in Node and validated it byte-for-byte against the repo's own golden vector
  (`apps/api/tests/Fixtures/Fiscal/sale-receipt-v4-refund-golden.json`)"*.
- A reusable TypeScript implementation DOES exist — `apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts`,
  `apps/pos/src/lib/fiscal/hashService.ts`, `apps/pos/src/lib/fiscal/payloads/SaleReceiptV5Payload.ts`,
  `apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts` — but it is **not reachable from `apps/web`**:
  `apps/web/tsconfig.json` `"include": ["src"]` with path aliases only for `@/*` and `@autoerp/shared/*`; no
  `apps/pos` alias, and `grep "apps/pos\|@pos/" apps/web/package.json apps/web/tsconfig.json` returns nothing.
  (`pnpm-workspace.yaml` does list `apps/*`, so a workspace dependency is possible — but that is a decision, not
  a detail.)
- Note also the version split the brief flattens: sale is `SaleReceiptV5Payload.ts` (brief's "event_version 5" is
  right) but the refund is `RefundReceiptV4Payload.ts`, and
  `app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1264-1269` enforces
  `event_version=4 requires invoice_type_code=REFUND`.
- **Required change:** name the import/reuse strategy for the canonicaliser explicitly (workspace dep on
  `apps/pos`, or a vendored copy in `e2e/campaign/fiscal/` pinned by a golden-vector assertion against
  `apps/api/tests/Fixtures/Fiscal/sale-receipt-v5-golden.json`), and give L6/L7 a stated time budget with
  permission to land them as `NOT_SCRIPTABLE(canonicaliser not wired)` if the budget blows. Otherwise this one
  leg eats the lane.

## MAJOR

### I1-R1-03 — L2 asserts a `batch_number` import column that does not exist
`apps/api/app/Modules/Import/Domain/Enums/ImportType.php:125` — the Products optional-column list is
`['sku','type','description','sale_price','sale_price_incl_tax','sale_price_excl_tax','purchase_price','margin','quantity','location_code','expiry_date','placement_path','barcode','category_name','brand','tax_rate','unit','is_active']`.
There is **no** `batch_number` / `lot` column (`grep -n "expiry\|batch_number\|lot" ImportType.php` → only
`expiry_date` and its validation at `:141-154`, `:200-207`). Opening stock lands on the `DEFAULT` lot.
**Fix:** L2/L3 assert `expiry_date` from the file on the DEFAULT lot; drop `batch_number`.

### I1-R1-04 — L3 offers a retired import type as an alternative path
The brief's L3 says "or the `stock_levels`/`opening_balances` import type if that is the sanctioned path".
`ImportType.php:39` + `deprecationMessage()` `:50-56` + `isDeprecated()` `:65-68` + `selectable()` `:75-80` make
**StockLevels unselectable** — `ImportController::store()/::execute()`, `ImportService::importRow()` and
`MigrationWizardService` all refuse it (docblock `:35-38`). Wave-4 confirms at `:75-80`.
**Fix:** delete the `stock_levels` alternative; opening stock is the Products import
(`quantity` + `purchase_price` + `location_code` → `ProductOpeningStockPhase` → `OpeningBalancePostingService`).

### I1-R1-05 — L0's census hedge is unnecessary; every read endpoint exists, so name them
- units: `app/Modules/Uom/Presentation/routes.php:15` — `GET uom/units`
- payment methods: `app/Modules/Treasury/Presentation/routes.php:39`
- repositories: `app/Modules/Treasury/Presentation/routes.php:66`
- companies: `app/Modules/Identity/routes.php:53` — `GET user/companies`
**Fix:** replace "if there is no units endpoint, read-only `psql` count is acceptable" with the real routes. A
`psql` escape hatch in a target-agnostic campaign is a trap: it cannot run against staging.

### I1-R1-06 — verification step 1 will silently not typecheck any of the new files
`apps/web/tsconfig.json` ends with `"include": ["src"]`; `apps/web/package.json` `"typecheck": "tsc --noEmit"`;
and `apps/web/e2e/tsconfig.json` **does not exist** (`ls` → No such file). So `pnpm typecheck` covers `src` only,
and the brief's fallback ("run `pnpm exec tsc --noEmit -p e2e/tsconfig.json` or equivalent") points at a file the
lane would have to create.
**Fix:** make it a deliverable — add `apps/web/e2e/tsconfig.json` (or a `tsconfig.e2e.json` project reference) and
a `typecheck:e2e` script, and require the paste of THAT command's output.

## MINOR

### I1-R1-07 — L5's lock route omits the company segment
Real route: `app/Modules/Accounting/Presentation/routes.php:136` —
`POST /companies/{companyId}/opening-batches/{batchId}/lock` (`opening-batches.lock`). Brief writes
`POST …/opening-batches/{id}/lock`.

### I1-R1-08 — L4 should name the sanctioned opening-float route rather than "the sanctioned route … W4-2 resolution"
W4-2 was the P0 "no working path to an opening cash float"
(`…wave4-critical-path-2026-08-24.md:897`, detail `:122-149`, incl. the refusal
`POST /payment-repositories/{drawer}/adjustments` → 422 `REPOSITORY_NOT_SEEDED` at `:137`). The lane merged
(`docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md:7` lists "W4-2 (+fixture)"), and the live CI pgsql filter now
names `OpeningCashFloatSeedsRepositoryTest` (`.github/workflows/ci.yml`, the `--filter` block at ~`:1093`) — i.e.
the float flows through the **opening batch**, not through repository adjustments.
**Fix:** name the endpoint in the brief so the implementer does not retry the 422 path.

### I1-R1-09 — tile selector: two tiles exist today, and the wrong one silently drops balances
`docs/tutorials/import-soldes-historiques.md:25-29`: the correct tile is *"Partenaires commerciaux — Importez
clients et fournisseurs avec soldes d'ouverture"*; the tutorial warns **"N'utilisez PAS la tuile « Partenaires »
… elle importe les fiches mais perd silencieusement les soldes"**. The brief's fallback regex
`/partenaires commerciaux|business partners|parties/i` does select the right one (French "Partenaires" does not
match "partenaires commerciaux"), so no change is required to the regex — but L1 should additionally assert that
the wizard it entered is the balance-bearing one (4 `HIST-*` documents), and the `selectors.ts` header comment
should record that a near-miss tile exists.
L1's four magnitudes and quadrants are **verified correct** against the tutorial (`:11-16` file rows, `:62-70`
resulting `HIST-INV-2026-00001` / `HIST-CN-…` / `HIST-SINV-…` / `HIST-SCN-…`), and the prefixes are real:
`apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:514-520`. L5's lock semantics are
confirmed at tutorial `:88`.

## NOTE

### I1-R1-10 — collision risk is low for I-1
No other worktree has dirty files under `apps/web/e2e/` or `apps/web/playwright*`. I-1 only **adds**
`.github/workflows/onboarding-campaign.yml` (new file), so it does not collide with the `ci.yml` edits currently
live in `.worktrees/g12-units` and `.worktrees/g3a-sku-scope`. The `apps/web/package.json` one-line script
addition is the only shared-file touch.

---

# I-2 — Fresh-tenant census + tenant-only-unique ratchet

## BLOCKER

### I2-R1-01 — Part C's scanner design measures migration TEXT, not the live schema; it is both false-positive and false-negative, and its "stale" direction can never fire
Four independent proofs against the current tree:

1. **`down()` bodies are indistinguishable from `up()`.**
   `apps/api/database/migrations/tenant/2025_12_30_195300_fix_multi_company_unique_constraints.php` — `up()` at
   `:21-34` DROPS `unique(['tenant_id','code'])` on `partners`, `payment_methods`, `payment_repositories` and adds
   `unique(['company_id','code'])`; `down()` at `:44-59` re-adds the tenant-only keys at `:46`, `:52`, `:58`. A
   scanner that "finds every `->unique([...])` … starting with `tenant_id`" reports three phantom violations from
   the ROLLBACK of an already-fixed constraint. Same shape at
   `2025_12_30_085029_make_document_number_nullable_on_documents_table.php:41`,
   `2026_04_28_120000_fix_unit_categories_partial_unique.php:89`,
   `2025_11_30_140002_add_company_id_to_document_sequences.php:34`.

2. **Superseded create-lines never go stale.** `2025_11_30_052119_create_partners_table.php:33` still reads
   `$table->unique(['tenant_id', 'code']);` although the live index is `partners_company_id_code_unique`. The text
   is immutable history. Therefore the brief's *"stale fails with 'baseline entry no longer in tree — remove it (a
   lane fixed it)'"* can never trigger, and the NOTE to the orchestrator *"G-3a (SKU per company) and G-12 (units)
   will remove entries → their merge must delete the stale baseline lines"* is **false**: those lanes add NEW
   migrations; the old lines stay.

3. **Raw-SQL uniques are invisible.** The live `unit_categories` uniqueness is
   `CREATE UNIQUE INDEX unit_categories_tenant_code_unique ON unit_categories (tenant_id, code) WHERE tenant_id IS
   NOT NULL` — authored by `DB::statement` at `2026_04_28_120000_fix_unit_categories_partial_unique.php:71-76`
   after `dropUnique(['tenant_id','code'])` at `:57`. The scanner cannot see the real constraint and WILL report
   the dead one at `2026_01_09_095018_create_unit_categories_table.php:30`. Same class:
   `2025_11_30_104000_create_companies_table.php:119-120`,
   `2026_02_19_000002_add_type_to_pos_terminals.php:27`.

4. Consequence: the ratchet's report bears no relation to what a second company would actually collide on.

**Required change:** make the ratchet a **live-schema** test. Boot the tenant schema (the suite already migrates
it), introspect unique indexes (`pg_index`/`pg_get_indexdef` on PG; `Schema` introspection on SQLite is
insufficient — pin the test to the PG lane), keep only indexes whose leading column is `tenant_id` and whose
column list lacks `company_id`, and restrict to `CATALOGUE_TABLES`. That design is driver-truthful, sees raw SQL
and partial indexes, ignores `down()`, and **goes stale automatically** the moment a lane fixes a constraint —
which is the property the whole ratchet is for. Keep the text scan, if at all, only as a "new migration text"
advisory with the comment/`down()`/named-arg handling of I2-R1-11/12.

### I2-R1-02 — the expected baseline is materially wrong: 4 named entries do not exist, and the highest-value real one is missing
Live tenant DB, unique indexes whose definition contains `(tenant_id` and not `company_id`:

**Named in the brief but NOT LIVE (already company-scoped):**
| Brief claims | Live index |
|---|---|
| `partners(tenant_id, code)` | `partners_company_id_code_unique (company_id, code)` |
| `accounts(tenant_id, code)` | `accounts_company_id_code_unique` + `accounts_company_code_unique` + `accounts_company_purpose_unique` |
| treasury `(tenant_id, code)` ×2 | `payment_methods_company_id_code_unique`, `payment_repositories_company_id_code_unique` |

**Live, operator-editable, and MISSING from the brief's catalogue set:**
`product_variants_tenant_sku_unique (tenant_id, sku) WHERE deleted_at IS NULL` and
`product_variants_tenant_barcode_unique (tenant_id, barcode) WHERE barcode IS NOT NULL AND deleted_at IS NULL` —
this is *exactly* the G-3a SKU class one table over, and `product_variants` is not in the brief's list.
Also live and un-triaged: `documents(tenant_id, type, document_number)`,
`journal_entries(tenant_id, entry_number)`, `onboarding_checklists(tenant_id, step_key)`,
`pos_customer_aliases(tenant_id, client_customer_uuid)`, `banks(tenant_id, country_code, rib_bank_code)`,
`media_assets(tenant_id, source_ref)`, `brands(tenant_id, canonical_brand_id)`.

**Correct as claimed:** `products(tenant_id, sku)`, `partners(tenant_id, vat_number)`, `units(tenant_id, code)`,
`product_attributes(tenant_id, code)`, `brands(tenant_id, slug)`, `vehicles(tenant_id, vin)` +
`(tenant_id, license_plate)`, `loyalty_members(tenant_id, phone)`, `unit_categories(tenant_id, code)` (as a
partial index).

Tables in the brief's set with **nothing to report** (already company-scoped — harmless, but the brief should not
imply otherwise): `payment_methods`, `payment_repositories`, `accounts`, `categories`
(`categories_company_id_slug_unique`), `locations` (`idx_locations_company_code`), `pos_terminals` (all keys carry
`company_id`), `tax_configurations` (no unique beyond the PK).

**Required change:** regenerate the expected baseline from the live schema before dispatch; add `product_variants`
to `CATALOGUE_TABLES`; add an explicit in/out ruling for `documents` (it is a genuine second-company hazard, not a
"legitimately tenant-scoped sequence").

### I2-R1-03 — invariant 5 will be RED for the second company on today's tree, and the brief does not warn the implementer
- `PaymentRepositorySeeder` has exactly **one** caller in app code:
  `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:25,317` (grep over `app/` and
  `database/migrations` returns only that call site plus comment references).
- The second-company path does **not** call it:
  `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:69-186` creates the company
  (`:83`), the default POS-enabled Main Location (`:123-140`), the owner membership, hash chains, then seeds
  chart of accounts, payment methods, expense categories and tax configurations — **no payment repositories**.
- No event listener fills the gap: `apps/api/app/Providers/EventServiceProvider.php:69-72` binds `CompanyCreated`
  to fiscal-year creation, growth-advisor registration and `EnsureFraudSettingsOnCompanyCreated` only.
- The second-LOCATION path IS covered:
  `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:215` →
  `provisionCashRegisterIfPosEnabled()` at `:227-240` (`LocationCashRegisterProvisionerInterface`).
- Registration itself is fine — the N-12 attribution is real and ordered correctly:
  `PaymentRepositorySeeder::defaultLocationId()` (`database/seeders/PaymentRepositorySeeder.php:152-176`) resolves
  the default/active/pos_enabled/oldest location, and
  `TenantProvisioningService.php:161` creates the Main Location **before** `:197` calls
  `initializeForNewRegistration`.

**Why it matters:** the brief makes second-company assertions **mandatory** ("Second-of-everything is mandatory
here"), so invariant 5 ("exactly ONE active `cash_register` per pos_enabled location … and exactly one `safe` per
company") is a guaranteed RED that the implementer will be tempted to soften into "company 1 only".
**Required change:** pre-declare it. State that the second company has zero repositories today, that this is a
**PRODUCT FINDING of the F/G/H class** (a second company cannot take cash), and instruct the lane to assert the
gap loudly — a failing named test or `markTestIncomplete` with the exact call-site evidence — never a narrowed
assertion.

### I2-R1-04 — invariant 3 is false as written: `ProvisioningRequiredPurposesV1` is a partition, not a required list
`apps/api/app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php:31-84` enumerates
**every** `SystemAccountPurpose`, classified REQUIRED / SCOPE_REQUIRED / CONDITIONAL / SOFT; the partition is
enforced at `:293-298` as exactly `28 + 1 + 4 + 10` and must cover every case. SOFT entries carry
`call_site = 'NONE'` and `NONE:` evidence (`:70-83`) — nothing guarantees they are seeded. CONDITIONAL entries
(`SalesReturn`, `RefundWriteOff`, the two rounding-difference purposes, `:65-68`) are deliberately gated by a
`DOMAIN_PRECHECK_4XX`.
So "every purpose in `ProvisioningRequiredPurposesV1` resolves to exactly one active account per company" would
fail on a perfectly healthy tenant.
**Required change:** use the authority's own accessor `ProvisioningRequiredPurposesV1::requiredPurposes()`
(`:115-126` — added precisely because callers filtering on the private const `REQUIRED` fail OPEN) for the hard
assertion, and assert SCOPE_REQUIRED / CONDITIONAL / SOFT separately with their documented dispositions.

## MAJOR

### I2-R1-05 — invariant 4 is true today for TN/FR/generic, but it is a *country-defaults* pin, not a manifest requirement — say so or it will read as contradicting invariant 3
Seeded: `database/seeders/TunisiaChartOfAccountsSeeder.php:286` (`RefundWriteOff`) and `:317` (`SalesReturn`);
`database/seeders/FranceChartOfAccountsSeeder.php:343,386`; `database/seeders/GenericChartOfAccountsSeeder.php:202,211`.
But both purposes are CONDITIONAL in the manifest (`ProvisioningRequiredPurposesV1.php:65-66`).
**Fix:** phrase invariant 4 as "the country chart template seeds both refund purposes" and cite the seeder lines,
so a future country template that omits them fails HERE rather than at an operator's first refund.

### I2-R1-06 — invariant 7's premise ("the fallback unit must be applied") is not what G-12 does, and G-12 is unmerged
- G-12 is **not on dev**: `ls apps/api/app/Modules/Uom/Application/Services/` → does not exist;
  `find apps/api -name ImportErrorCode.php` → nothing; no `2026_08_30_*ensure_units_visible_per_company*`
  migration. It lives dirty in `.worktrees/g12-units`.
- G-12 does not add a per-row fallback unit. Its own gate record
  `docs/superpowers/reviews/2026-08-29-g12-units-invariant-gate-r1-imports-tenancy.md:45-59` documents a 422
  **refusal** (`units_not_seeded`, "seed them in Settings → Units before importing") at
  `ImportController.php:123-134` plus a worker re-check at `ProcessImportJob.php:100-122`, and per-company
  provisioning — no fallback.
- The testable case exists today regardless: `unit` IS an optional Products column
  (`ImportType.php:125`), so "one row WITH a unit code, one WITHOUT" is a real scenario now.
**Fix:** restate invariant 7 as the observed contract ("a Products row with a blank `unit` lands with `unit_id`
= X"), determine X from the current tree, and if the answer is NULL, record it as a finding with the lane that
owns it — do not attribute a fallback to G-12.

### I2-R1-07 — the unit-scope question the brief defers is already answered: units stay TENANT-scoped
- `apps/api/database/migrations/tenant/2026_01_09_095045_create_units_table.php:15` (`tenant_id` nullable), `:37`
  (`unique(['tenant_id','code'])`) — no `company_id` column anywhere in the table.
- `TenantInitializationService::seedUnitsOfMeasure()` `:259-266` guards on a **global**
  `DB::table('units')->exists() || DB::table('unit_categories')->exists()` — i.e. once per tenant database.
- The G-12 gate record confirms it stays that way:
  `…g12-units-invariant-gate-r1…md:23` — *"No `company_id` column, index, model fillable, or unit-scope write was
  added … RUL-7 option (b) remains the follow-on G-13 lane"*; `:20` — the visibility predicate is
  `tenant_id IS NULL OR tenant_id = $company->tenant_id`.
**Fix:** delete the "check the ruling first" conditional; invariant 1 asserts **tenant-scoped rows + per-company
visibility** (≥ 19 visible active units for each company), and says so in its description.

### I2-R1-08 — under `tests/Feature/Tenant/`, the census gates NOTHING; the brief's "runs cheaply in the default suite" is false for CI
`apps/api/tests/feature-lane-manifest.json`: group `Tenant` → lane `feature-lane-tenancy/Tenant`, with
`"runs_on_pr_dev": false` and execution gate
`${{ vars.SELF_HOSTED_RUNNER_READY == 'true' && (workflow_dispatch || base_ref == main || push→main) }}`; the
group note says the lane is **PARKED** behind `SELF_HOSTED_RUNNER_READY` and its `classes` ceiling is 29.
This is the identical trap already booked against G-12 as a gate finding
(`…g12-units-invariant-gate-r1…md:158`, G12-R2-03: a class left BY PATH in a parked lane "executes on **no** CI
event today").
**Fix:** require the lane to append `FreshTenantCensusInvariantsTest` and `DayOneCensusCommandTest` to the LIVE
`backend-test-pgsql --filter` allowlist in `.github/workflows/ci.yml` (the long `--filter='/\\(…)::/'` block at
~`:1093`, which already carries the W4-1 and G-7 precedents documented in the comment block at `:1070-1092`), and
to record the ceiling raise + the pin in the `Tenant` manifest note.

### I2-R1-09 — Part C's CI question is answerable now: `backend-architecture` is an EXPLICIT list, so an unlisted Architecture test runs nowhere
`.github/workflows/ci.yml:143-236` — the job runs, by name only:
`tests/Architecture/FeatureLaneManifestCheckerTest.php` (`:198`),
`tests/Architecture/FeatureLaneLocalHarnessTest.php` (`:212`),
`tests/Architecture/OrphanedEventRatchetTest.php tests/Architecture/ProjectorEmissionRatchetTest.php` (`:225`),
plus `tools/feature-lane-manifest-check.php` and the Deptrac ratchet. The step comment at `:219-224` is explicit:
*"Named files, not the whole `tests/Architecture` directory — that directory carries unrelated inherited baseline
failures (4, named in the `backend-dpa-guard` job comment)."*
**Fix:** turn "confirm how the job selects tests … and add the class if it is an explicit list" into a hard
requirement: add ONE new named step running **both** the ratchet and its liveness class (convention
`docs/conventions/08-DETECTOR-LIVENESS.md` requires the liveness proof in the **same CI lane**), and nothing else
in `ci.yml`.

### I2-R1-10 — `ci.yml` and `feature-lane-manifest.json` are already dirty in two sibling lanes — sequence I-2 or it will conflict
`git -C .worktrees/g12-units status --porcelain` → ` M .github/workflows/ci.yml`,
` M apps/api/tests/feature-lane-manifest.json`, ` M apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php`,
` M apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php`.
`git -C .worktrees/g3a-sku-scope status --porcelain` → ` M .github/workflows/ci.yml`, plus `ProductService`,
`PartnerService`, product/partner FormRequests.
I-2 must touch both shared files (I2-R1-08, I2-R1-09).
**Fix:** state the merge order (G-12 and G-3a land first, then I-2 rebases and regenerates the baseline), forbid
reflowing the giant `--filter` line (append only), and warn that G-3a's SKU migration will change the live schema
the ratchet reads — which under the live-schema design of I2-R1-01 is *self-correcting*, another reason to switch.

## MINOR

### I2-R1-11 — the scanner must skip comments/docblocks; two real decoys exist
`database/migrations/tenant/2026_07_28_100000_add_is_cash_tender_to_payment_methods.php:51` — the literal
`unique(['tenant_id','code'])` appears in a **comment** explaining that the constraint was dropped.
`database/migrations/tenant/2026_04_28_120000_fix_unit_categories_partial_unique.php:12` — same string in the
docblock. Make "skip `T_COMMENT` / `T_DOC_COMMENT`" explicit and add a liveness fixture for it.

### I2-R1-12 — named-index `unique([...], 'name')` calls are common; extract argument 1 only
`2026_01_08_190429_create_pos_terminals_table.php:65`, `2026_05_04_000001_create_tenant_signing_keys_table.php:46`,
`2026_03_10_400004_create_platform_supplier_mappings_table.php:25`,
`2026_04_19_130001_create_workshop_work_orders_table.php:124` all pass a second string argument. Add a fixture.

### I2-R1-13 — invariant 5 must also pin the provisioning ORDER, not just the end state
`database/seeders/PaymentRepositorySeeder.php:152-160` documents that the seeder is deliberately null-safe:
*"a seeder run against a company that has no locations yet returns null and the repositories stay unattributed,
which is the pre-N-12 shape"*. So `location_id` attribution is an **ordering** property. Registration satisfies it
(`TenantProvisioningService.php:161` before `:197`); say which path the test drives, and add a negative case
(repositories seeded with no location ⇒ NULL) so the ordering is pinned rather than assumed.

### I2-R1-14 — confirm the baseline-file convention before inventing `tests/Architecture/baselines/`
The established baseline in this repo is `apps/api/deptrac.baseline.json` (consumed at `ci.yml:231`). I could not
find a `tests/Architecture/baselines/` directory on dev — **cannot verify** that the path the brief specifies is a
precedent. Ask the lane to follow whichever convention `docs/conventions/08-DETECTOR-LIVENESS.md` and the existing
ratchets use, and to say which it chose.

## NOTE

### I2-R1-15 — every other anchor the brief cites is real
`tests/Feature/Tenant/TenantReferenceDataSeedingTest.php`, `tests/Feature/Tenant/CleanTenantRegistrationTreasuryTest.php`,
`tests/Feature/Tenant/CleanRegistrationDownstreamAssumptionsTest.php`, `tests/Feature/Identity/TenantLaunchContractTest.php`,
`tests/Feature/Document/DeferredDocumentNumberingTest.php`,
`app/Console/Commands/PosReceiptVatLegCensusCommand.php`,
`database/migrations/tenant/2026_08_26_100000_backfill_payment_repository_location_n12.php`,
`docs/conventions/08-DETECTOR-LIVENESS.md` — all present. `TenantLaunchContractTest` is additionally in the live
pgsql `--filter`, so it is a good model for I2-R1-08.

---

# Docs commit `262d5f53c`

## BLOCKER

### DOC-R1-01 — `docs/glossary.md` states the import-type retirement BACKWARDS
The "Import type" row reads: *"(`ImportType`): Parties, Products, OpeningBalances, CompositeItems,
ProductImages, StockLevels; **`Partners` is retired** (readable for history only)."*
The code says the opposite:
- `apps/api/app/Modules/Import/Domain/Enums/ImportType.php:39` — `case StockLevels` carries the `@deprecated`
  docblock (`:14-38`); `deprecationMessage()` `:50-56` returns a message for `StockLevels` and `default => null`
  for everything else; `isDeprecated()` `:65-68`; `selectable()` `:75-80` filters it out.
- `case Partners = 'partners';` at `:10` has **no** deprecation and is therefore still selectable today. Its
  retirement is Session G Wave 3 (lane G-9), per the I-1 brief's own selector contract and the tutorial warning at
  `docs/tutorials/import-soldes-historiques.md:27`.
**Why it matters:** the same commit adds a reviewer instruction to *"grep the glossary row's synonyms"* in seven
`.claude/agents/*-reviewer.md` files. A registry that inverts which importer is retired will make reviewers
approve the wrong retirement and flag the right one. **Fix the row before the reviewer appendices go live.**

## MAJOR

### DOC-R1-02 — glossary "Stock level" row: wrong table name and wrong uniqueness
Row says table `inventory_stock_levels`, unique `(product, location)`.
Live: table is `stock_levels`; indexes are
`stock_levels_non_variant (tenant_id, product_id, location_id) WHERE variant_id IS NULL` and
`stock_levels_with_variant (tenant_id, product_id, variant_id, location_id) WHERE variant_id IS NOT NULL`
(created at `database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php:27` and later split).

### DOC-R1-03 — glossary "Document number" row: claims company scope, live index is tenant-wide
Row: *"unique per `(company, type, number)`"*. Live:
`documents_tenant_id_type_document_number_unique ON documents (tenant_id, type, document_number)`
(migration `2025_12_30_085029_make_document_number_nullable_on_documents_table.php:24`). It is
`document_sequences` that is company-scoped (`document_sequences_company_id_type_year_unique`, migration
`2025_11_30_140002_add_company_id_to_document_sequences.php`). Two companies in one tenant therefore share the
`documents` number key — which is itself an un-triaged instance of the very class convention 09 is about (see
I2-R1-02).

### DOC-R1-04 — glossary "Repository" row: "day one seeds one drawer + one safe per company" is true only for the FIRST company
Same evidence as I2-R1-03: `PaymentRepositorySeeder` is called only from
`TenantInitializationService.php:317`; `CompanyController::store()` (`:69-186`) does not call it and no
`CompanyCreated` listener does (`EventServiceProvider.php:69-72`). Reword to "…on registration; an additional
company created via `POST /api/v1/companies` gets none today (open gap)".

### DOC-R1-05 — Round-0 check 6 is inert as landed: it has no row in the report table and the runner is told to execute checks 1–5
`docs/superpowers/SPEC-GATE-ROUND0-MECHANICAL-PRECHECK.md:24` — *"The runner executes checks **1–5** plus the
hygiene sub-check"*; `:30` — heading *"## The five checks"*; `:116-129` — the mandated report table has rows
`1,2,3,4,5,H` only. The verdict rule at `:141` is *"any single FAIL **row** = the document FAILS round 0"*. A
check with no row cannot fail anything, and a mechanical runner following "How to run" will not run it at all.
**Fix:** update `:24` to "checks 1–6", `:30` to "The six checks", and add row `6 | Journey-hardening sections | PASS/FAIL`
to the output-format table.

### DOC-R1-06 — Convention 09 carries two claims that are false against the tree
- `docs/conventions/09-SECOND-OF-EVERYTHING.md`, incident table: *"Payment methods / VAT number / units carried
  tenant-wide uniques (4 instances)"*. `payment_methods` has been `(company_id, code)` since
  `2025_12_30_195300_fix_multi_company_unique_constraints.php:26-29`; the live index is
  `payment_methods_company_id_code_unique`. (`payment_repositories` likewise.) The real live set is in I2-R1-02.
- Same file, ratchet section: *"When a lane fixes a baselined instance (G-3a SKU, G-12 units), the merge deletes
  the stale baseline entry — the ratchet fails on stale entries too, so it cannot be forgotten."* Impossible under
  the specified text scanner (I2-R1-01): the original `create_*_table.php` line survives the fix forever.
**Why it matters:** this is a standing convention that reviewers will cite as authority. Correct the instance
count/list, and align the ratchet paragraph with whatever design I-2 actually ships.

## MINOR

### DOC-R1-07 — wrong relative path to the platform glossary, and the reciprocal link is uncommitted
`docs/glossary.md` header: *"Platform-level terms live in `../../claude/glossary.md`"*; convention 11 §1 repeats
it. From `apps/erp/docs/` that resolves to `apps/claude/glossary.md`. The real file is
`/Users/houssamr/Projects/syneriva/claude/glossary.md` — three levels up.
Also: the reciprocal pointer **does** exist at `claude/glossary.md:61` (it links
`apps/erp/docs/glossary.md`), but it is **uncommitted** in the parent repo —
`git -C /Users/houssamr/Projects/syneriva status --porcelain claude/` → ` M claude/glossary.md`. Deliverable 5's
"`claude/glossary.md` linkage" is therefore not landed anywhere reviewable; commit it in the parent repo or note
it as owed.

### DOC-R1-08 — the "one surface per concept" registry ships with an undeclared duplicate surface of its own
`docs/glossary.md` "Contact" row names table `party_contacts` / module `Contact`. The tenant database contains
**both** `contacts` and `party_contacts`. Convention 11's own incident table cites *"`Contact` module: TDD'd,
laned, 0 rows in 8 tenant DBs, reachable from nowhere"* — so the ambiguity is known. The row must name which
table is canonical and what becomes of the other (Session H owns the fix; the glossary owns the declaration).

### DOC-R1-09 — glossary states two futures as facts
- "Product … keyed by **SKU** (unique per company after G-3a)" — G-3a is unmerged (`.worktrees/g3a-sku-scope`
  dirty); live index is `products_tenant_id_sku_unique`.
- "Party … **one** importer `ImportType::Parties` (`import-tile-parties`, presets customers/suppliers)" — two
  tiles exist today (`docs/tutorials/import-soldes-historiques.md:25-29`), and the testids are a Session-G Wave-3
  commitment.
A registry reviewers grep should state today's shape with the pending change marked, not the target state.

### DOC-R1-10 — convention 09 points at a file that does not exist yet
*"The canonical list is the `CATALOGUE_TABLES` constant in
`apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php`"* — not on dev. Acceptable only if
I-2 lands in the same owner-visible batch; otherwise annotate "(lands with lane I-2)".

## NOTE

### DOC-R1-11 — additivity and rule-22 placement verified
`git show --numstat 262d5f53c` → every file is `N 0`: seven `.claude/agents/*-reviewer.md` at `6 0`,
`.claude/context/new-feature-checklist.md` `7 0`, `CLAUDE.md` `9 0`, `docs/conventions/README.md` `15 0`,
`SPEC-GATE-ROUND0-MECHANICAL-PRECHECK.md` `10 0`, three new conventions + glossary + manual-testing-loop.
**507 insertions, 0 deletions** — the reviewer-template appendices are purely additive, as claimed.
Rule 22 sits at `CLAUDE.md:100`, after rule 21 at `:89` — no renumbering. I checked it against rules 4 (scope
creep), 6 (module boundaries), 19 (precision) and 21 (branch discipline) and found **no contradiction**: rule 22
expands the *test set inside a lane*, which rule 4 already permits, and touches neither money handling nor branch
mechanics.

### DOC-R1-12 — glossary rows verified CORRECT (no change needed)
HIST prefixes `HIST-INV` / `HIST-CN` / `HIST-SINV` / `HIST-SCN`
(`app/Modules/Document/Application/Services/ArApOpeningService.php:514-520`, and the tutorial's realised
documents at `docs/tutorials/import-soldes-historiques.md:65-69`);
`RepositoryType` cases `cash_register` / `safe` / `bank_account` / `virtual`
(`app/Modules/Treasury/Domain/Enums/RepositoryType.php:9-12`);
`accounts.system_purpose` + `SystemAccountPurpose::SalesReturn` / `RefundWriteOff`
(`app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:89,97`; live index
`accounts_company_purpose_unique`);
tables `party_contacts`, `product_batches`, `inventory_batch_stock`, `user_company_memberships`, `payments`,
`payment_allocations`, `pos_terminals`, `pos_shifts`, `pos_receipts`, `opening_balance_batches`,
`document_sequences` all exist;
"Draft — `document_number` is NULL (deferred numbering)" matches
`2025_12_30_085029_make_document_number_nullable_on_documents_table.php`;
`ProvisioningRequiredPurposesV1` exists as cited.

---

## Cross-cutting: collision map with Sessions F / G / H

| File | Also dirty in | Lane that needs it |
|---|---|---|
| `.github/workflows/ci.yml` | `.worktrees/g12-units`, `.worktrees/g3a-sku-scope` | **I-2** (I2-R1-08, I2-R1-09) — sequence after both, append-only |
| `apps/api/tests/feature-lane-manifest.json` | `.worktrees/g12-units` | **I-2** (ceiling raise) |
| `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php` | `.worktrees/g12-units` | I-2 reads it (copies the provisioning helper) — read-only, no conflict, but the census's units invariant changes behaviour once G-12 lands |
| `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php` | `.worktrees/g12-units` | I-2's second-company test drives `POST /api/v1/companies`; after G-12 that path also provisions units |
| `apps/web/e2e/**`, `apps/web/playwright*.ts`, `.github/workflows/onboarding-campaign.yml` | nobody | **I-1** — clean |
| `docs/glossary.md` Party/Contact rows | `.worktrees/h1-*` (Session H, currently clean) | docs commit declares Session H owns those rows — keep that ownership line |

