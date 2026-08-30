# Gate r2 — Session I lane I-2 (fresh-tenant day-one census + tenant-only-unique catalogue ratchet)

**Commit under review:** `6d34f10bc` on `feat/i2-fresh-tenant-guards` (worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/i2-fresh-tenant-guards`, `git status --porcelain` empty). Diff base `3d2cd7027`.
**Fix-round brief:** `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I2-FIX-ROUND-1.md`.
**r1 record:** `docs/superpowers/reviews/2026-08-30-i2-fresh-tenant-guards-gate-r1-tenancy.md`.
**Reviewer:** tenancy-authz-reviewer (Opus), adversarial, code-grounded. **Scope of merge:** local `dev`, batch 3, guards only.

---

## 0. What I re-verified independently this round (not taken from the orchestrator)

| Claim | How | Result |
|---|---|---|
| Ratchet + liveness green under the NEW predicate | `DB_DATABASE=autoerp_test_i DB_CENTRAL_DATABASE=autoerp_test_i php artisan test -c phpunit-pgsql.xml --filter='TenantOnlyUnique'` | **7 passed, 136 assertions** (2 ratchet + 5 liveness) |
| Census Feature legs | same runner, `tests/Feature/Tenant/DayOneCensusCommandTest.php tests/Feature/Tenant/FreshTenantCensusInvariantsTest.php` | **19 passed + 1 incomplete** (I2-F2), 327 assertions |
| Baseline == live set under the new predicate | Direct `pg_index` replication of `TenantOnlyUniqueIndexScanner::scanAll()` against `autoerp_test_i` (127.0.0.1:5433), filtered to `CATALOGUE_TABLES` | **EXACT 20/20 match** with `tenant-only-unique-baseline.json`. No entry added or missing. |
| Partial / raw-SQL indexes still seen under the new predicate | `product_variants_tenant_barcode_unique`, `location_nodes_location_code_live_unique`, `unit_categories_system_code_unique` are all partial or raw-SQL and all appear in both the live scan and the baseline | TRUE — `indisunique` scan remains predicate-blind |
| PHPStan on the lane's own files | `phpstan analyse` on the 3 app files + the 2 ratchet test classes + `Support/TenantOnlyUnique*.php`, grepped for those filenames | **0 errors on lane files** (the 45 errors returned by a by-path run are pre-existing `EnumBackedColumnRegistry`/`EnumCheckParity*`/`WebhookFixtures` noise from bypassing the project ignore config) |
| Pint | `pint --test` on the 3 app files + `tests/Architecture` + `tests/Feature/Tenant` | `{"result":"pass"}` |
| Manifest | `php tools/feature-lane-manifest-check.php` | `EXIT=0` |
| `ci.yml` delta | `git diff 3d2cd7027..6d34f10bc --stat -- .github/workflows/ci.yml` | **1 insertion**, the rationale comment at `ci.yml:1113`. m-9 satisfied. |
| Deptrac | `php tools/deptrac-ratchet.php` → **185 vs baseline 183**; `deptrac analyse --formatter=table \| grep -iE 'DayOne\|Census\|UnitsProvisioning'` → **empty** | Unchanged from r1. The new `Tenant\Application → Company\Domain` and `→ Uom\Application` edges added **zero** deptrac violations. o-1 stands, still inherited from `dev`. |

---

## 1. Resolution of every r1 finding

| ID | r1 subject | Status | Evidence |
|---|---|---|---|
| **M-1** | waiver carries no signal; unbounded baseline growth | **PARTIAL** | Mechanism landed (see §2c); shipped baseline still demonstrates the escape hatch → **N-2** |
| **M-2** | no unclassified-table detector | **RESOLVED** | §2b |
| **M-3** | predicate narrower than the rule | **RESOLVED** | §2a |
| **M-4** | census copies the units-visibility predicate | **RESOLVED** (but introduces **N-1**) | §2d |
| **m-1** | `--company` bound into a uuid column unguarded | **RESOLVED** | `DayOneCensusCommand.php:35-39` `Str::isUuid()` → `self::INVALID`; pinned by `DayOneCensusCommandTest.php:161-168` (asserts exit **2**) |
| **m-2** | magic `'draft'` string | **RESOLVED** | `DayOneCensus.php:335` `DocumentStatus::Draft->value`; test twin `FreshTenantCensusInvariantsTest.php:396` `DocumentStatus::Draft` |
| **m-3** | fail-open bare-string vs private const | **RESOLVED** | `ProvisioningRequiredPurposesV1.php:128-138` public `conditionalPurposes()`/`softPurposes()` + private `purposesByClassification()` :355-368; consumed at `DayOneCensus.php:165-172`; the partitions are themselves pinned by `ProvisioningRequiredPurposesV1ConformanceTest.php:108-129` (4 conditional / 10 soft, named) — so the counters can no longer silently read 0/0 |
| **m-4** | SCOPE_REQUIRED hardcoded | **DEFERRED (not selected)** — acceptable, see §3 | still `DayOneCensus.php:160-164` (`SalesStampDutyPayable`, `country_code !== 'TN'`) |
| **m-5** | census vs test location predicate mismatch | **RESOLVED** | `DayOneCensus.php:223` `->where('is_active', true)` added; matches `FreshTenantCensusInvariantsTest.php:164-168` |
| **m-6** | company with zero POS locations vacuously CLEAN | **RESOLVED** | `DayOneCensus.php:229-238` emits `active_pos_locations>=1`; deny path planted and asserted at `DayOneCensusCommandTest.php:139-158` (deactivates the POS location, asserts `active_pos_locations=0` + `DRIFT(1)` + exit 1) |
| **m-7** | country-template pin exercises TN only | **DEFERRED (not selected)** — acceptable for merge, must not be dropped, see §3 | fixture still TN-only |
| **m-8** | zero-entry `CATALOGUE_TABLES` membership unpinned | **NOT-ADDRESSED** | see **N-8** — and it is slightly worse than r1 stated |
| **m-9** | `ci.yml` tokens with no rationale | **RESOLVED** | `.github/workflows/ci.yml:1113` |
| **m-10** | conv 09 line stale | **PARTIAL** | line 19 rewritten (see §2e) but the same document's ratchet section went stale in the opposite direction → **N-6** |
| **o-1** | deptrac red at 185 vs 183 | **UNCHANGED, still not attributable** | verified above; orchestrator reconciliation still owed before promotion |

---

## 2. Attack on the new design (the five questions asked)

### (a) Predicate "any unique on a catalogue table lacking `company_id`" — **sound**

`TenantOnlyUniqueIndexScanner.php:54-62` is now:
- `indisprimary` → skip (`:54-56`) — correct, and it is the *index property*, not a name heuristic, so a PK named anything is excluded;
- single-column unique on `id` / `uuid` → skip (`:57-59`) — correct scoping: it is guarded by `count($columns) === 1`, so a composite `(uuid, something)` is still scanned, and the exclusion cannot swallow `(tenant_id, code)`;
- `in_array('company_id', $columns, true)` → skip (`:60-62`), **position-independent**, and `tenant_id` is no longer consulted at all.

Verified against the live migrated schema by replicating the exact predicate in `psql`: the qualifying set inside `CATALOGUE_TABLES` is **exactly the 20 baseline keys**, no more, no less.

- **Partial / raw-SQL still seen:** yes. `unit_categories_system_code_unique` (raw `CREATE UNIQUE INDEX … WHERE tenant_id IS NULL`, migration `2026_04_28_120000_fix_unit_categories_partial_unique.php:67-71`), `product_variants_tenant_barcode_unique` and `location_nodes_location_code_live_unique` are all in the live scan and the baseline. The `indnkeyatts` bound (`:42`) also correctly excludes `INCLUDE` columns from the key list.
- **`unique(['sku'])` case:** covered *and proven live*, not merely by construction — `TenantOnlyUniqueRatchetLivenessTest.php:83-100` plants `CREATE UNIQUE INDEX liveness_products_sku_without_tenant ON products (sku)` inside the `RefreshDatabase` transaction and asserts the report names it. It passes. The `unique(['sku','tenant_id'])` ordering case is covered by construction (order-independent `in_array`) and needs no separate case.
- **Residual (Minor, safe direction):** the `company_id` exclusion is an exact match against the *rendered* column expression from `pg_get_indexdef(…, position, true)`. An index that expresses company scoping as an expression — e.g. `COALESCE(company_id, '')`, a shape this schema already uses for other columns (`media_attachments … COALESCE(channel,''), COALESCE(locale,'')`) — would render as `COALESCE(company_id, ...)` and would **not** be excluded. That over-reports (fails loudly, forcing a reviewed baseline line) rather than under-reports, so it is acceptable; worth one sentence in the scanner docblock.

### (b) Unclassified-table detector — **structurally correct; three of four sampled reasons honest, one is not**

`TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:221-238` + the pure helper `unclassifiedTableViolations()` `:244-261`. It asserts (i) `CATALOGUE_TABLES ∩ EXCLUDED_TABLES = ∅` (`:224-228`), (ii) every excluded reason is non-empty (`:229-231`), (iii) every table in `scanAll()` is in one of the two sets. Liveness case 5 (`TenantOnlyUniqueRatchetLivenessTest.php:102-118`) plants a **real** unclassified table with a real qualifying unique and asserts the exact classify sentence. Both green on my run. This closes M-2 as specified.

**Honesty sample of exclusion reasons** (I read the live index for each before judging):

1. `banks` (`:67`, "Bank reference data is shared tenant-wide across companies") — live key `(tenant_id, country_code, rib_bank_code)`; a RIB bank code is a national registry identifier, not an operator-authored code. **HONEST.**
2. `recipes` (`:155`, "An active recipe is uniquely owned by its company-scoped composite item") — live key `(composite_item_id)` (partial); `composite_items` carries `company_id` and `unique(['company_id','code'])` (`database/migrations/tenant/2026_02_19_100001_create_composite_items_table.php:16,34`). **HONEST — verified, not asserted.**
3. `stock_levels` (`:164`, "product-by-location projection") — live keys `(tenant_id, product_id, location_id)` and `(tenant_id, product_id, variant_id, location_id)`; `locations` is company-scoped, so the pair is company-determined. **HONEST.**
4. `journal_entries` (`:115`, "Journal entry identity and numbering are ledger-global rather than operator catalogue keys") — **NOT honest as written.** Live: the table **has** `company_id` (and `location_id`), and its key is `(tenant_id, entry_number)`. That is precisely the shape `documents(tenant_id, type, document_number)` was re-scoped away from (per the doc's own "numbering per company" note at `09-SECOND-OF-EVERYTHING.md:19`). See **N-3**.

I also confirmed the detector is *live-complete*: every table returned by my independent `scanAll`-equivalent query is in one of the two sets (the test is green, and it fails on a planted table).

### (c) Waivers optional + 7 legacy + `LEGACY_ENTRY_CEILING` — **can a contributor still silence growth in-diff? YES. State the residual.**

What landed is right in shape: `TenantOnlyUniqueBaseline.php:46-53` makes `waiver` optional and rejects an empty-but-present one; the class docblock `:11-20` states the meaning (`key` only = frozen legacy debt, `waiver` = legitimately tenant-global), the residual ("no protected-blob authority; reviewers must inspect its diff") and the re-pin procedure; the checker's remediation sentence now *teaches* the distinction (`TenantOnlyUniqueRatchetChecker.php:32`: "…re-pin reviewed legacy debt as `{"key": ...}`; `waiver` is only for a legitimately tenant-global key") and is asserted verbatim by liveness case 1; the seven copy-paste waivers are gone; `LEGACY_ENTRY_CEILING = 7` (`:187`) is asserted at `:210-218` and matches the seven un-waived entries I counted in the JSON (`brands` ×2 :2-7, `loyalty_members` :20-22, `price_lists` :31-33, `product_attributes` :38-40, `vehicles` ×2 :69-74).

**Residual, stated plainly: the ceiling does not bind on waived entries.** `:210-213` filters `waiver === null` before counting. So a contributor who lands `unique(['tenant_id','code'])` on `categories` tomorrow silences the ratchet **entirely inside the JSON file**, with no test-file edit, by writing:

```json
{ "key": "categories|categories_tenant_id_code_unique|tenant_id,code",
  "waiver": "Categories are tenant-scoped reference data with per-company visibility." }
```

— which is a *character-level near-copy* of the two waivers this diff ships for `units` (`:66-68`) and `unit_categories` (`:62-64`). The ceiling therefore constrains only the contributor who is already being honest. That is a genuine improvement over r1 (the honest path is now cheap and the dishonest path is now a *lie* rather than boilerplate), but M-1's core disease survives at reduced dose, and it survives **because the shipped baseline pre-legitimises the exact sentence** an abuser would write. See **N-2**.

**Are the 13 waived entries genuinely tenant-global-by-nature or child-of-a-company-owned-parent?** Nine are; **four are not**:

| Entry | Verdict |
|---|---|
| `products\|…platform_submission_id` :54-56 | SOUND — external platform identity |
| `product_variants\|…tenant_barcode` :50-52 | SOUND — RUL-2 is a real owner ruling, and the waiver says so |
| `product_variants\|…product_id` :42-44, `…product_id,variant_code` :46-48 | SOUND — `products` is company-scoped since G-3a |
| `composite_item_variants` ×2 :9-15 | SOUND — `composite_items` has `company_id` + `unique(company_id,code)` (verified) |
| `modifiers\|…modifier_group_id,code` :28-30 | SOUND — `modifier_groups` has `company_id` + `unique(company_id,code)` (`2026_02_19_100005…:16,28`) |
| `location_nodes\|…location_id,code` :17-19 | SOUND — `locations` is company-scoped |
| **`unit_categories\|unit_categories_system_code_unique\|code` :58-60** | **SOUND — and I specifically checked this one.** The index is `ON unit_categories (code) WHERE tenant_id IS NULL` (`2026_04_28_120000_fix_unit_categories_partial_unique.php:67-71`), i.e. it constrains **only the platform/system partition** shared by every tenant. The waiver "System unit-category codes identify global reference rows shared by every tenant and company" is exactly right. The *sibling* partial index on the tenant partition is a separate entry (:62-64) and is where the problem is. |
| **`units\|units_tenant_id_code_unique` :66-68** | **NOT SOUND** — see N-2 |
| **`unit_categories\|unit_categories_tenant_code_unique` :62-64** | **NOT SOUND** — see N-2 |
| **`product_attribute_values\|…attribute_id,code` :35-37** | **NOT SOUND** — see N-2 |
| **`loyalty_tiers\|…program_id,level` :24-26** | **NOT SOUND** — see N-2 |

`price_lists(code)` is correctly carried as un-waived LEGACY (:31-33) — and it deserves the call-out: `price_lists` *has* a `company_id` FK (`2025_12_01_201012_create_price_lists_table.php:17`) while its unique is a bare `(code)` with no tenant or company column at all. That is the single worst live entry in the file and it is honestly labelled.

### (d) `UnitsProvisioningService::visibleActiveUnitCount()` — **correct, and it was already public**

Point of fact for the record: the method was **not added by this diff** — `UnitsProvisioningService.php` is not in `git diff --stat 3d2cd7027..6d34f10bc`. It already existed as `public function visibleActiveUnitCount(Company $company): int` at `UnitsProvisioningService.php:18-27`, directly under the `⛔ SCOPE-DEPENDENT … single substitution point for OQ-G-25` docblock (`:16`). The fix is the *delegation*:
- `DayOneCensus.php:34` constructor-injects `private UnitsProvisioningService $unitsProvisioning` — constructor injection, **no `app()` anywhere in `DayOneCensus.php`** (grepped). Rule 13 respected. Rule 6 respected for the service (public Application service, the sanctioned cross-module surface).
- `DayOneCensus.php:97` `$this->unitsProvisioning->visibleActiveUnitCount($company['model'])` — the inlined copy is gone. When OQ-G-25 is ruled, one edit changes both the service and the census. M-4's actual defect is closed.
- Late binding survives: the service uses the `DB` facade internally (`:20`), which resolves the default connection at call time; the r1 pin `census resolves the current connection after command construction` still passes on my run.
- Deptrac is unmoved (185, no `DayOne`/`Census`/`UnitsProvisioning` violation), so the new `Tenant\Application → Uom\Application` edge is an allowed one.

**But the mechanism chosen to obtain the `Company` model introduces a new crash — see N-1.** `DayOneCensus.php:80` hydrates it with `Company::query()->findOrFail((string) $row->id)` from inside the loop over a **query-builder** result set that does not filter soft deletes (`:61-73`). `Company` uses `SoftDeletes` (`app/Modules/Company/Domain/Company.php:31`) and `companies.deleted_at` exists in the live schema (verified). The two disagree by construction.

### (e) `docs/conventions/09-SECOND-OF-EVERYTHING.md` line 19 — **accurate against the shipped baseline, but it desynchronised the rest of the document**

Line 19 as rewritten is **factually correct and I checked it row by row** against the live scan and the JSON: the seven "legacy, to re-scope" names match the seven un-waived entries exactly (including the `price_lists(code)` parenthetical "no tenant or company column at all", which is true); the "child keys scoped by a company-owned parent row" list matches the child-key waivers; the "Fixed since 2026-08-29" list matches the live schema (`documents` is `(company_id, type, document_number)`, `products`/`partners`/`product_variants` sku are company-scoped). It also correctly re-frames the row from "tenant-wide uniques" to "catalogue uniques lacking `company_id`", matching the new predicate.

Two inaccuracies inside it, both inherited from the baseline rather than authored by the orchestrator: it repeats the `units`/`unit_categories` waiver as settled fact ("tenant reference data with per-company visibility") and it silently promotes `product_attribute_values`/`loyalty_tiers` into the "child of a **company-owned** parent" list — neither parent is company-owned (N-2). Fixing N-2 in the baseline fixes line 19 mechanically.

**The desynchronisation:** the same document's `### The architecture ratchet` section was not updated and now contradicts the code this round shipped — `09-SECOND-OF-EVERYTHING.md:56` still says the scan looks "for unique keys whose **leading column is `tenant_id`**" (the exact predicate M-3 replaced), `:61-62` still quotes the *old* failure message ("or add the entry to the baseline with a `waiver` reason") which the checker no longer emits, and `:64-65` still reads as though a waiver is mandatory. Nothing there mentions `EXCLUDED_TABLES` or `LEGACY_ENTRY_CEILING`. See **N-6**.

---

## 3. Are the m-4 / m-7 deferrals acceptable for a guards-only merge?

**m-4 (SCOPE_REQUIRED hardcoded, `DayOneCensus.php:160-164`) — YES, acceptable to defer.** It is a derivation-quality item on one reported row. `ProvisioningRequiredPurposesV1` has exactly one SCOPE_REQUIRED entry today, so there is no live under-check; and deriving the TN scope properly requires adding a country field to the manifest, which is a change to the CountryDefaults authority — squarely outside a guards-only batch. Record it as a named follow-up.

**m-7 (country-template invariant exercised on TN only) — YES for merge, NO for dropping.** It is a coverage gap, not a defect: no production path changes, and the invariant is *correct* for TN. But it is the one place where a guard advertises coverage it does not have — invariant 4's stated purpose is "a future template that omits them fails HERE", and today `FranceChartOfAccountsSeeder` / `GenericChartOfAccountsSeeder` would fail only on a live FR tenant. Merging is fine **provided the residual is written down** ("the refund-purpose template guard is TN-only as of `6d34f10bc`") in the Session I ledger, alongside M-2/M-3 which are now *resolved* and can be struck from the r1 deferral list.

---

## Findings (this round)

### BLOCKER
None. No cross-tenant read, no central-vs-tenant connection change, no route middleware, no `can:` guard, no permission catalogue change, no module gating, no queue/job context, no money or quantity arithmetic, no `latestOfMany`. Both new Architecture classes still self-skip off PostgreSQL (`TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:193-195`, `TenantOnlyUniqueRatchetLivenessTest.php:24-26`).

### MAJOR

**N-1 — `DayOneCensus.php:80` crashes the operator command on a soft-deleted company (regression introduced by the M-4 fix).**
`:61-73` selects companies with the **query builder** (`$this->database->table('companies')`), which does **not** apply the `SoftDeletingScope`. `:80` then hydrates each row with `Company::query()->findOrFail((string) $row->id)`, which **does** — `Company` uses `SoftDeletes` (`app/Modules/Company/Domain/Company.php:31`) and `companies.deleted_at` exists in the live schema (verified by `information_schema` query). Any soft-deleted company row therefore throws an uncaught `ModelNotFoundException` out of `tenant:census-day-one` instead of producing a census row. Soft-deleted companies are producible — `database/migrations/tenant/2025_11_30_133000_migrate_tenant_data_to_companies.php:130` does `Company::query()->delete()` (an Eloquent soft delete) in its rollback path. Under `tenants:run tenant:census-day-one` (`docs/handoff/RUNBOOK-day-one-census.md:7`) an escaping exception aborts the remaining tenants — **the exact failure mode r1's m-1 was raised for, re-introduced one line lower**. And a clean `tenant:census-day-one` is a promotion precondition (`CLAUDE.md` rule 22), so this is on the gate path.
*Fix (one line):* add `->whereNull('deleted_at')` to the builder at `:61` so both halves agree and a soft-deleted company is simply not censused. (`Company::withTrashed()->findOrFail()` would instead census a deleted company — wrong direction.) Add a command test that soft-deletes a company and asserts the census still exits cleanly on the survivors.

**N-2 — Four of the thirteen waivers are legacy debt mislabelled as "legitimately tenant-global", which is exactly the abuse the `LEGACY_ENTRY_CEILING` was added to make visible.**
- `units|units_tenant_id_code_unique` (baseline `:66-68`) and `unit_categories|unit_categories_tenant_code_unique` (`:62-64`) share the sentence *"Units and their categories are tenant-scoped reference data with per-company visibility."* That is a **restatement of the status quo, not a justification** — and it is a restatement of a predicate the codebase itself declares **open**: `UnitsProvisioningService.php:16` says the visibility predicate "is the ⛔ SCOPE-DEPENDENT predicate of spec §4.13.2 and is the single substitution point for **OQ-G-25**". A key whose scope question is pending an owner ruling is by definition not "tenant-global by nature"; it is undecided debt. (Session G's own state carries "unit-scope ruling owed by owner".)
- `product_attribute_values|…attribute_id,code` (`:35-37`) is waived as *"unique within their **company-owned** attribute"*. `product_attributes` has **no `company_id` column** (`database/migrations/tenant/2026_06_02_100001_create_product_attributes_table.php:13-25` — `tenant_id` only, `unique(['tenant_id','code'])`) and is itself carried as an **un-waived legacy entry three lines below, at `:38-40` of the same file**. The waiver is internally contradicted by its own baseline.
- `loyalty_tiers|…program_id,level` (`:24-26`) is waived as *"unique within their **company-owned** loyalty program"*. `loyalty_programs` has no `company_id`; it has a nullable `json('company_ids')` documented `// null = all companies` (`database/migrations/tenant/2026_01_10_100000_create_loyalty_programs_table.php:21`). A tenant-wide program shared by all companies is not a company-owned parent.

Why it matters beyond bookkeeping: these four are precisely what keeps `LEGACY_ENTRY_CEILING` at 7 instead of the honest 11, and the `units`/`unit_categories` sentence is the ready-made template for the in-diff silencing described in §2c. The ceiling's whole value is that the number is uncomfortable and visible.
*Fix:* drop the `waiver` from those four entries (keep `key` only), raise `LEGACY_ENTRY_CEILING` to **11** at `TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:187`, and correct `09-SECOND-OF-EVERYTHING.md:19` to move `units`/`unit_categories`, `product_attribute_values` and `loyalty_tiers` from the waived list into the legacy list. Re-run `--filter='TenantOnlyUnique'`.

**N-3 — `journal_entries` is excluded as "ledger-global" although it is a company-owned table, contradicting the `documents` precedent in the same convention.**
`TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:115` excludes it with *"Journal entry identity and numbering are ledger-global rather than operator catalogue keys."* Live schema: `journal_entries` **has `company_id`** (and `location_id`) and its numbering key is `(tenant_id, entry_number)` — verified by `information_schema` + `pg_index` on `autoerp_test_i`. That is the same shape as `documents(tenant_id, type, document_number)`, which Session J re-scoped to `(company_id, type, document_number)` and which `09-SECOND-OF-EVERYTHING.md:19` now lists under "Fixed since 2026-08-29 … (numbering per company)". Accounting entry numbering is a per-legal-entity property; two companies in one tenant sharing one journal sequence is a fiscal question this exclusion silently closes with an assertion. (It is not a "company B cannot create X" failure, because `entry_number` is system-generated — hence MAJOR, not BLOCKER.)
*Fix:* either move `journal_entries` to `CATALOGUE_TABLES` (it then becomes two more un-waived legacy entries — `(tenant_id, entry_number)` and `fiscal_hash`/`(source_type, source_id)` will need their own dispositions), or keep the exclusion and rewrite the reason to cite the ruling that per-tenant journal numbering is intended across legal entities, naming the follow-up that will re-examine it. An assertion without a citation is not a reviewed reason.

### MINOR

**m-8 (carried forward, and slightly worse than r1 stated) → N-8 — zero-entry `CATALOGUE_TABLES` membership is still unpinned, and liveness case 2 does not pin it either.**
Nine of the 23 tables in `CATALOGUE_TABLES` (`:30-54`) carry **no** qualifying unique in the live schema (`partners`, `payment_methods`, `payment_repositories`, `accounts`, `tax_configurations`, `categories`, `pos_terminals`, `locations`, `documents` — verified by my psql run). Deleting any of them from the const leaves every test green, because the unclassified detector only sees tables that *have* a qualifying unique. r1 credited liveness with pinning `products` **and** `payment_methods`; on re-reading, only `products` is pinned: case 1 (`:29-46`) and case 4 (`:83-100`) assert **presence** on `products`, but case 2 (`:48-65`) asserts **absence** of the planted `payment_methods` index — which stays true if `payment_methods` is removed from the const. Case 2 is satisfiable vacuously.
*Fix:* `self::assertSame([...the 23 names...], self::CATALOGUE_TABLES)` in the classification test, and strengthen case 2 to plant **two** indexes on `payment_methods` (one with `company_id`, one without) and assert exactly the second is reported — then the case fails if the table leaves the const.

**N-4 — `onboarding_checklists` excluded as "tenant workflow state" is status-quo-describing, on the one entity Session I exists to question.**
`:125`. The table genuinely has no `company_id` (verified), so the exclusion is schema-consistent — but the consequence is that company B shares company A's onboarding checklist, which is the second-of-everything shape itself, and the census has a per-company `onboarding_checklist_consistent` invariant. Prefer: keep the exclusion, extend the reason to say "**no company dimension exists today; per-company onboarding is follow-up <id>**", so the exclusion carries its own expiry.

**N-5 — `docs/handoff/RUNBOOK-day-one-census.md` is stale against this commit — and it is the operator remediation surface.**
`:17` still names the removed key `payment_methods_seeded` (renamed to `cash_tender_coherent`, `DayOneCensus.php:311`; the only remaining reference in the repo — grepped). `:16` still says "provision one drawer per POS location and one safe" with no mention of the new **GL-linked** requirement or the new `active_pos_locations>=1` row. Exit code **2** (invalid `--company`) is undocumented while `:3` says "exit code is meaningful". Orchestrator or implementer, but it must land with the rename.

**N-6 — `docs/conventions/09-SECOND-OF-EVERYTHING.md` ratchet section now contradicts the shipped code (orchestrator action).**
`:56` "unique keys whose **leading column is `tenant_id`**" — replaced by M-3; `:61-62` quotes a failure message the checker no longer emits (`TenantOnlyUniqueRatchetChecker.php:32` is the real one); `:64-65` implies a waiver is required. Neither `EXCLUDED_TABLES` nor `LEGACY_ENTRY_CEILING` is documented. Same drift in `CLAUDE.md` rule 22, which still reads "…fails `TenantOnlyUniqueOnCatalogueTablesRatchetTest` … **unless** the baseline entry carries a written `waiver` reason".

**N-7 — `cash_code_methods` mixes active and inactive in an otherwise active-only invariant.**
`DayOneCensus.php:305-308` counts `UPPER(code) = 'CASH'` with **no `is_active` filter**, while the flag arm (`:294-303`) is active-only. A company that deactivated a legacy `CASH` method and created a new one reads `cash_code_methods=2` → permanent DRIFT on an otherwise clean tenant, with an `expected:` string (`:315`) that does not say inactive rows count. Either add `->where('is_active', true)` or say "including inactive" in `expected`/`description`. (The deny path that *is* tested — `DayOneCensusCommandTest.php:120-137`, renaming the flagged method to `CASH_ALT` → `cash_code_methods=0` — does not exercise this.)

### Observations

**o-1 (unchanged) — deptrac ratchet is RED at 185 vs baseline 183 and is still not attributable to this lane.** Re-verified this round: `tools/deptrac-ratchet.php` reports the same +2 `ModuleApplication on ModuleInfrastructure` / +1 `SharedContracts on ModuleDomain` / −1 `ModuleDomain on ModuleApplication` as at `3d2cd7027`, and `deptrac analyse --formatter=table | grep -iE 'DayOne|Census|UnitsProvisioning'` is empty even after the two new cross-module imports. Orchestrator reconciliation still owed before promotion.

**o-2 — what got genuinely stronger this round.** The liveness suite is now five cases and every added one plants **real** DDL and asserts the **exact** operator sentence, including the new classify message on a table created inside the test transaction (`:102-118`). The manifest authority gained real accessors *and* a conformance test that pins the exact conditional/soft partitions by name (`ProvisioningRequiredPurposesV1ConformanceTest.php:108-129`), so m-3's silent 0/0 can no longer recur unnoticed. Every new census invariant ships its deny path with a **planted** violation and a data-meaning assertion, not a status code: null the drawer's `gl_account_id` → `gl_linked=0` + `DRIFT(1)` + exit 1 (`DayOneCensusCommandTest.php:98-118`); rename the flagged tender → `flagged_code=CASH_ALT` (`:120-137`); deactivate the POS location → `active_pos_locations=0` (`:139-158`); a foreign in-memory connection with an empty `companies` table → the grep-shaped `NO-COMPANY` verdict (`:170-207`). And the `FreshTenantCensusInvariantsTest` twins were moved in lock-step (`:180`, `:192`, `:240-253`) rather than left to drift — the r1 m-5 class of bug was fixed on both sides.

---

## VERDICT: **CHANGES-REQUIRED** (batch 3, guards only) — spec ❌ narrowly; quality materially improved

Three of the four r1 MAJORs are genuinely closed and I verified each against the live schema rather than the diff: the predicate now catches the two spellings that evaded it (M-3, proven by a planted `unique(sku)`), the classification detector exists and fires on a real unclassified table (M-2), and the census no longer owns a second copy of the OQ-G-25 predicate (M-4). All four r1 minors on the must-fix list are closed. Nothing in this commit touches a tenancy boundary, an auth path, or a prod HTTP route.

It does not flip to MERGEABLE for two reasons, both small and both mechanical. **N-1** is a one-line regression that the M-4 fix introduced into a shipped operator command which is itself a promotion precondition — a soft-deleted company turns `tenant:census-day-one` into an uncaught exception, the same class of defect as the `--company` UUID crash this round was asked to fix. **N-2** is the ratchet's own honesty: four waivers assert facts the schema contradicts (`product_attributes` and `loyalty_programs` have no `company_id`) or pre-empt an owner ruling the code itself marks open (OQ-G-25), and those four are exactly what holds `LEGACY_ENTRY_CEILING` at 7 instead of 11 — hollowing out the one constant that was added to make growth visible. Since this batch exists to install the guard, shipping the guard with four pre-legitimised bad waivers ships the abuse template alongside it.

**Minimum to flip to MERGEABLE:**
1. **N-1** — `->whereNull('deleted_at')` at `DayOneCensus.php:61`, plus a command test with a soft-deleted company.
2. **N-2** — strip the `waiver` from the four entries (`units` :66-68, `unit_categories` tenant partition :62-64, `product_attribute_values` :35-37, `loyalty_tiers` :24-26), raise `LEGACY_ENTRY_CEILING` to 11, and mirror it into `09-SECOND-OF-EVERYTHING.md:19`.
3. **N-3** — give `journal_entries` a cited reason or move it to `CATALOGUE_TABLES`.
Then re-run `--filter='TenantOnlyUnique'` and the two census Feature classes on one PG leg.

**Acceptable to defer, provided they are written into the Session I ledger before promotion:** N-8/m-8 (membership pin), N-4 (`onboarding_checklists` expiry note), N-7 (`cash_code_methods` active filter), m-4 (SCOPE_REQUIRED derivation), m-7 (**residual: the refund-purpose template guard is TN-only as of `6d34f10bc`**). **Orchestrator actions, not implementer work:** N-5 (runbook), N-6 (conv 09 ratchet section + `CLAUDE.md` rule 22), o-1 (deptrac baseline reconciliation). M-2 and M-3 can be struck from the r1 deferral list — they are done.

**One line to fix before merge:** guard the soft-deleted company (`DayOneCensus.php:61`), un-waive the four dishonest baseline entries and raise the ceiling to 11, and cite or reclassify `journal_entries` — then re-run the four classes on one PG leg.

---

# Round 3 (delta only) — commits `c5587329a` + `e0c3c36b0`

**Reviewed:** `git diff 6d34f10bc..e0c3c36b0` (5 files, +61/−25), worktree clean at `e0c3c36b0`.
**Re-run by me, independently, on `autoerp_test_i`:**
- `--filter='TenantOnlyUnique'` → **8 passed, 139 assertions** (matches the coordinator's run).
- `tests/Feature/Tenant/DayOneCensusCommandTest.php tests/Feature/Tenant/FreshTenantCensusInvariantsTest.php` → **19 passed + 1 incomplete, 327 assertions**. **This leg was not in the coordinator's re-run and it is the one `c5587329a` touched** — it is green.
- `pint --test` on the two changed code files → `{"result":"pass"}`; `phpstan` → 0 errors on lane files; `tools/deptrac-ratchet.php` → **185 vs 183, unchanged** (o-1 still inherited, still not attributable).
- Baseline composition counted from the JSON: **20 entries = 11 legacy + 9 waived**, exactly equal to `LEGACY_ENTRY_CEILING = 11` and `WAIVED_ENTRY_CEILING = 9`.

## Per-item resolution

**N-1 (soft-deleted company crashes the census) — RESOLVED.**
`DayOneCensus.php:69-70` adds `->whereNull('deleted_at')` to the `companies()` builder with the reason inline ("Company uses SoftDeletes: a trashed company must not be censused (nor make findOrFail throw)"). Builder and `Company::query()->findOrFail()` at `:82` now agree, and the direction is the right one — a trashed company is skipped, not censused. Both census Feature classes pass on my run.
*Observation, not a finding (test robustness):* the `zero_company_tenant_...` fixture DDL at `DayOneCensusCommandTest.php:185-192` has no `deleted_at` column, and the test still passes only because of a SQLite misfeature — Laravel emits `"deleted_at" is null`, and SQLite silently reinterprets a double-quoted identifier that resolves to no column as a **string literal**, so the predicate becomes `'deleted_at' IS NULL` = false and filters every row (verified: raw unquoted SQL on the same table throws `no such column`, Laravel's quoted form returns 0 rows). The test asserts emptiness, so it is green for a partly wrong reason and would stay green if the fixture ever gained a company row. Production is PostgreSQL where the column exists, so there is no product impact. One-line hardening: add `deleted_at text` to that DDL.

**N-3 (`journal_entries` excluded on an uncited assertion) — RESOLVED, and better than I asked for.**
`TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:115` now states the concrete collision instead of a category claim, and **I verified every load-bearing part of it independently**: `AccountingOpeningService::generateEntryNumber(string $companyId)` (`app/Modules/Accounting/Application/Services/AccountingOpeningService.php:912-930`) takes its max from `JournalEntry::query()->where('company_id', $companyId)->where('entry_number','like',"OB-{$year}-%")` — a **company-scoped** max — while the live unique is **tenant-wide** `(tenant_id, entry_number)`; so company B's first opening batch re-mints `OB-2026-000001` and dies on 23505. The per-company chain-sequence claim also checks out (`uniq_je_company_chain_sequence` exists in the live schema). The exclusion reason is now falsifiable, cited, carries its own follow-up label, and in the process surfaced a **real latent second-company bug** for the accounting lane. This is what a reviewed reason should look like; it is the model for the remaining thin ones.

**N-2 (four unsound waivers) — RESOLVED in the enforcement surface, PARTIAL in the documentation.**
Baseline: `loyalty_tiers`, `product_attribute_values`, `unit_categories(tenant_id,code)` and `units(tenant_id,code)` are demoted to `key`-only legacy entries; `LEGACY_ENTRY_CEILING` 7→11 (`:187`). Counted from the JSON: 11 legacy, and the 9 that keep a waiver are exactly the nine I verified sound in §2c of this record (`products.platform_submission_id`, `product_variants` barcode/RUL-2 + two `product_id` child keys, `composite_item_variants` ×2, `modifiers`, `location_nodes`, and the `unit_categories` **system** partial index `ON (code) WHERE tenant_id IS NULL`). No unsound waiver remains.
**Still open (doc leg):** `docs/conventions/09-SECOND-OF-EVERYTHING.md:19` was **not** updated — it still lists `units`/`unit_categories(tenant_id,code)` under "**waived as tenant-global by nature**" and `product_attribute_values(attribute_id,code)` / `loyalty_tiers(program_id,level)` under "child keys scoped by a **company-owned** parent row". The document's inventory now contradicts the shipped baseline, which is the same stale-inventory failure m-10 was raised for. **Orchestrator, one line:** move those four names into the "legacy, to re-scope" list on that row.

**(c) escape hatch — RESOLVED.**
`WAIVED_ENTRY_CEILING = 9` (`:194`) with its own assertion at `:231-240` and a docblock that names the attack ("a contributor cannot silence growth in-diff by adding a `waiver` string"). Both ceilings sit **exactly at** the current counts, so **any** baseline growth — waived or not — now fails until a constant is raised in the test file, where a reviewer cannot mistake it for boilerplate. The hole I described in §2c is closed.
*Residual (accepted, worth one sentence in the docblock):* the ceilings are **counts, not sets**. A single commit that deletes a fixed entry and adds a new one of the same kind keeps the count equal and raises no constant; only the JSON diff shows it. That is inherent to a count-based ceiling and is strictly weaker than the DPA ratchet's pinned blob — the residual already acknowledged in `TenantOnlyUniqueBaseline.php:11-20`.

**m-8 / N-8 (zero-entry `CATALOGUE_TABLES` membership) — RESOLVED.**
`PINNED_CATALOGUE_TABLES` (`:199`) + `the_core_catalogue_tables_stay_in_scope` (`:242-249`). I checked the coverage rather than the count: the nine `CATALOGUE_TABLES` members that carry **no** qualifying unique in the live schema — `partners`, `payment_methods`, `payment_repositories`, `accounts`, `tax_configurations`, `categories`, `pos_terminals`, `locations`, `documents` — are the ones that were silently removable, and **all nine are in the pinned set**. Tables that *do* carry baseline entries are already self-pinned by the checker's stale arm. The design is exactly right, and it also moots my liveness-case-2 vacuity note, since `payment_methods` can no longer leave the const.
*Residual (one line, no action needed now):* the eight unpinned members (`vehicles`, `loyalty_members`, `price_lists`, `composite_item_variants`, `location_nodes`, `loyalty_tiers`, `modifiers`, `product_attribute_values`) rely on the stale arm, so each becomes silently removable on the day its key is fixed. Add them to the pinned set as their legacy entries are retired.

**m-10 / N-6 (convention 09 ratchet section + rule 22) — RESOLVED.**
`09-SECOND-OF-EVERYTHING.md:54-75` now describes the shipped predicate verbatim ("any unique key on a catalogue table whose column list lacks `company_id`", `tenant_id` position-irrelevant and optional, PK and single-column `id`/`uuid` excluded), the classification requirement, the legacy-vs-waiver semantics and both ceilings including the reviewer residual. `CLAUDE.md:102` rule 22 is aligned. Both now match the code.
*New MINOR (doc-only, non-blocking):* the blockquote at `:66-67` is typeset as the CI failure message but is a **paraphrase** — the checker actually emits `new tenant-only unique on catalogue table X (index Y) — add company_id to the key, or re-pin reviewed legacy debt as {"key": ...}; \`waiver\` is only for a legitimately tenant-global key` (`TenantOnlyUniqueRatchetChecker.php:32`, asserted verbatim by liveness case 1). The doc's wording is the better teaching text (it names both ceilings); the clean fix is to move it into the checker and keep the quote true, or drop the blockquote formatting.

**N-5 (`docs/handoff/RUNBOOK-day-one-census.md`) — NOT ADDRESSED.** `:17` still names the removed key `payment_methods_seeded` (the only remaining reference in the repo); `:16` still omits the GL-linked requirement and the new `active_pos_locations>=1` row; exit code 2 is still undocumented while `:3` promises "exit code is meaningful". This is the operator remediation surface for the command and should land with the rename — orchestrator, ~4 lines.

**m-7 (TN-only residual) — you asked me to check: it is stated NOWHERE.** I grepped `RUNBOOK-day-one-census.md`, `BuildsFreshTenantCensusFixture.php`, `FreshTenantCensusInvariantsTest.php` and `DayOneCensus.php` for `TN-only` / `Tunisia` / `only TN`: the sole hit is an unrelated assertion message (`FreshTenantCensusInvariantsTest.php:129`). **Please add the line** — suggested, on the `refund_purposes_seeded_by_country_template` bullet of the runbook: *"Coverage residual (as of `e0c3c36b0`): the fixture exercises the Tunisia chart template only; `FranceChartOfAccountsSeeder` and `GenericChartOfAccountsSeeder` are unguarded in CI."*

**Carried forward as deferred, unchanged:** N-4 (`onboarding_checklists` exclusion should carry its own expiry note), N-7 (`cash_code_methods` counts inactive rows in an otherwise active-only invariant, `DayOneCensus.php:307-310`), m-4 (SCOPE_REQUIRED hardcoded). **o-1** (deptrac 185 vs 183) is still owed by the orchestrator before promotion.

## VERDICT: **MERGEABLE** (batch 3, guards only) — spec ✅, quality APPROVED

All three round-2 blockers are closed and I verified each against the live schema and a re-run rather than against the diff: the census no longer crashes on a soft-deleted company (N-1, and the leg the coordinator did not re-run is green), the four dishonest waivers are gone with the ceiling raised to the honest 11 (N-2), and `journal_entries` now carries a cited, independently-verified reason that surfaced a real latent second-company defect instead of closing the question (N-3). The escape hatch I described in §2c is shut by `WAIVED_ENTRY_CEILING`, with both ceilings sitting exactly at their current counts so any growth of either kind forces a visible test-file edit; `PINNED_CATALOGUE_TABLES` covers precisely the nine tables that were silently removable. Convention 09 and rule 22 now describe the code that shipped. Nothing in the round-3 delta touches a tenancy boundary, an auth path, a permission, a module gate, a queue context, or money/quantity.

**Three non-blocking items for the orchestrator, none of them code:**
1. `09-SECOND-OF-EVERYTHING.md:19` — move `units`, `unit_categories(tenant_id,code)`, `product_attribute_values` and `loyalty_tiers` from the waived/child lists into the "legacy, to re-scope" list, so the document matches the baseline it summarises.
2. `RUNBOOK-day-one-census.md:16-17` — rename `payment_methods_seeded` → `cash_tender_coherent`, add GL-linked + `active_pos_locations>=1`, document exit code 2 (N-5).
3. Add the m-7 TN-only coverage residual (text suggested above), and reconcile the deptrac baseline (o-1) before promotion.

**One line before merge:** none blocking — land it, then fix the three documentation lines above in the same batch so the convention, the runbook and the shipped baseline tell the same story.
