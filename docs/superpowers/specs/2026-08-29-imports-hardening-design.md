# Imports hardening — design spec (Session G)

> **Status: DRAFT r4.1 (post Codex gate r3; converged; G-12 ownership reconciled).** Gates r1/r2/r3 all returned **REWORK**
> (`G-R1..G-R24`, `G-R25..G-R45`, `G-R46..G-R56`); **every finding is ACCEPTED** and adjudicated in
> **§13**. No lane is authorized until a gate returns ACCEPT. Per
> `feedback_codex_adversarial_review_before_execution`, the spec is reviewed before any code and at
> every subsequent milestone.
>
> **r4 is a CONVERGENCE round, not a design round.** Gate r3 stated that every remaining finding is a
> deterministic spec/lane consistency correction. r4 therefore *removes* contradictions rather than
> adding text: one typed outcome model with its equations stated once (§3.2/§4.2.4); the XLSX
> boundary made implementable through an injected normalizer interface and the workbook's own active
> tab (§4.11); the opening correction given one call order with the cost lock **inside** the Inventory
> seam (§4.9.2); unit resolution made deterministic on today's schema with `unit_ambiguous`,
> `units_not_seeded` and a new **G-12** invariant lane (§4.13); a two-clock, compare-and-set reaper
> with no ownership token (§4.7); exact serialized JSONB schemas (§3.2); and a lane graph re-cut so
> every method has ONE owner, with waves **derived from the dependency closure** (§9).
>
> **Owner:** Session G orchestrator (imports hardening program), for the AutoERP owner.
> **Date:** 2026-08-29. **Baseline:** `dev` @ `a4ceeb0f5` (all Phase-1 audits worked read-only
> against this tip; every `path:line` in this spec is that tip's).
>
> **Inputs**
> - Handover (owner mandate, requirements 1–11, rulings): `docs/sessions/session-G-imports-hardening-2026-08-29/HANDOVER.md` — **gitignored** (ephemeral session workspace); its rulings are reproduced verbatim in §2 so this spec stands alone.
> - Phase-1 synthesis (tracked, the sole durable Phase-1 record): `docs/superpowers/audits/2026-08-29-imports-hardening-gap-matrix.md` — matrix, 28 findings `G-F-1..28`, natural-key table, lane map, OQ-G-1..15, doc-drift table.
> - Six read-only audits, same gitignored directory: **A** idempotency/natural keys, **B** transparency/history/export, **C** wizard flow/preview/UX, **D** SKU scope census, **E** templates/locale/type coverage, **F** parties-split feasibility.
> - Unit-scoping research (tracked): `docs/superpowers/research/2026-08-29-unit-scoping-tenant-vs-company.md` — the verified unit facts §4.13 rests on, and the a/b/c scope options OQ-G-25 puts to the owner.
> - Schema precedent: `apps/api/database/migrations/tenant/2026_08_28_100000_enforce_company_scoped_payment_method_codes.php` (census-then-refuse) and its gate record `docs/superpowers/reviews/2026-08-28-session-e-i1-followups-gate-r1-treasury.md`.
> - Sign convention for opening balances: `docs/guides/legacy-migration-accounting-conventions.md` §3.
> - Operating/merge protocol: `docs/sessions/session-F-testing-2026-08-29/HANDOVER.md` §Operating constraints.
> - **Gate records:** `docs/superpowers/reviews/2026-08-29-imports-hardening-spec-codex-review-r1.md`, `…-r2.md`, `…-r3.md`. Every `path:line` they cite was independently re-verified against `a4ceeb0f5` before this revision; corrections found are recorded in §13.

---

## 1. Goals and non-goals

### 1.1 Goals

The owner's mandate is **transparency**: "the user must never guess whether an import succeeded,
where it is, what errors happened, and how to fix them." Concretely, after this program:

1. Every refusal carries a **coded, translatable** reason — no raw `SQLSTATE` reaches an operator.
2. Every failing **and** every warning row is **exportable in the operator's own column names** and
   re-importable after correction, for async jobs as well as sync ones.
3. A re-import of the same file is **idempotent per company**: no duplicate products, no duplicate
   partners, no silently blanked columns.
4. A blank SKU cell is **never** a row error and **never** a name-slug collision: it gets a real
   generated SKU and the operator is told.
5. Opening stock can be corrected by re-import **exactly when no operations have occurred** for the
   product, and is refused with a stock-adjustment direction otherwise.
6. Every past import is inspectable — paginated history, per-job detail, counts by code, downloads,
   operator, company, duration — and no job can sit stuck in `importing` forever.
7. Templates match the operator's country number convention, in CSV **and** XLSX.
8. Every live import type has an HTTP round-trip test asserting persisted rows.

### 1.2 Non-goals (explicit, do not expand)

| Non-goal | Reason |
|---|---|
| A `contacts` import | Blocked on the unratified `party_kind` target model (Audit F §B Option 3): `grep party_kind` over `apps/` returns zero hits, and the governing research doc rules contacts are "people at an organisation, never billable" (`docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md:17,389`). Building it now risks importing individuals into the wrong table. |
| The **B-18 party/contact redesign program** | Separate program with its own owner sign-off (OQ1–5). This spec touches neither its scope nor its schema. Explicitly untouched. |
| Un-retiring `stock_levels` | Retired by owner ruling D4; the case survives only so historical `import_jobs` rows stay castable (`ImportType.php:14-39`). Opening stock rides the products import. Stays retired. |
| A B2B **document** import (invoices, orders, delivery notes) | Not requested; the AR/AP historical-document path already exists inside the parties importer (`PartiesBalancesPhase`), and per-invoice aged files are governed by `legacy-migration-accounting-conventions.md` §4, not by this program. |
| A **fix-in-place validation grid** | Deferred with reason. `ValidationGrid` already accepts an `onRowUpdate` prop that neither call site supplies (`ValidationGrid.tsx:11,140-153`), and no endpoint would persist an edit — `docs/modules/imports.md:485-518` documents `updateStagingRow`/`revalidateRow` endpoints that do not exist. Building it means a new row-mutation + revalidation surface on staged rows; R1's error-line export (fix offline, re-upload) delivers the same operator outcome for a fraction of the surface. G-10 removes the dead prop and the doc's claim; a future lane may build the grid. |
| Import **rollback / undo** | `docs/modules/imports.md:783` promises it; no rollback path exists anywhere in the module. Out of scope — the opening-stock reset (`ResetOpeningBalanceService`) is the only reversal this program uses, and only inside D1. G-6 deletes the doc's claim. |
| Re-scoping the other ~20 tenant-wide unique indexes | Audit D §4 enumerates them (`users.email`, `units.code`, `accounts.code`, `vehicles.vin`, `brands.slug`, `journal_entries.entry_number`, …). G-3a re-scopes **only** the three that break an import: `products.sku`, `product_variants.sku`, `partners.vat_number`. The rest are a separate census lane. |
| **Adding any uniqueness to `products.barcode`** (G-R1) | `products.barcode` has **no unique index at any scope today** — only the plain index `index(['tenant_id','barcode'])` (`2025_11_30_052910_create_products_table.php:29,39`). Minting one is a catalogue-wide data-quality decision with its own census, unrelated to making imports idempotent. Recorded as **OQ-G-19**, default NO. |
| **Re-scoping `product_variants.barcode`** (G-R1) | Its tenant-wide partial unique (`2026_06_02_100003_create_product_variants_table.php:40-41`) is load-bearing for scan safety and stays exactly as-is — see §3.1a. |

---

## 2. Requirements and rulings

### 2.1 Requirements (one line each)

| Id | Requirement |
|---|---|
| **R1** | Error-line export — export exactly the lines that failed (and the lines that warn), ideally re-importable after fixing. |
| **R2** | SKU optional — use the file's SKU when given, otherwise **generate one regardless of what is in the file**; a blank cell is never a row error. |
| **R3** | Idempotency — a documented natural key / source of truth per import type, such that re-imports create no duplicates. |
| **R4** | Duplicate handling — detect duplicates and show them; standard behaviour is **newer data overrides**. |
| **R5** | Opening-balance caveat — opening stock may be overridden by re-import only if **no operations took place** for that product; otherwise refuse and direct to a stock adjustment. |
| **R6** | Import history page — statuses and import reports for every past import; full visibility of what was imported, when, and how it ended. |
| **R7** | Coverage of ALL import types — every live `ImportType` gets an end-to-end test (upload → validate → execute → DB rows asserted), including customers. |
| **R8** | Locale-aware templates — downloadable as CSV **and** XLSX, number conventions (decimal comma/dot, CSV delimiter `;`/`,`) chosen from the company's country (fallback tenant country); the parser accepts the same conventions back. |
| **R9** | Customers/suppliers clarity — one clear file for customers, one for suppliers; get rid of the confusion the `type` column creates. |
| **R11** | **Units must be entered exactly as spelled** (owner, 2026-08-29) — a differently-spelled unit is an error, not a silent acceptance; the template's example must carry a comma-separated list of the units **exactly as written**, with a clear instruction to enter them exactly. |
| **R10** | **Point-of-use sign/perspective guidance** (owner, 2026-08-29) — the import UI must explain what belongs where and from whose perspective, at the point of use, so the operator never needs the docs to get signs right: an inline explainer + per-column hints on every balance-bearing step, a preview that **echoes the interpretation per row**, the same hints in the downloadable templates, and wording single-sourced from `docs/guides/legacy-migration-accounting-conventions.md` §3/§3b. |

### 2.2 Owner rulings (verbatim, binding — do not re-litigate)

> **RUL-1 (duplicate flow).** "OQ duplicate-flow RULED: option 2 — no mid-run interactive prompt;
> the PRE-IMPORT PREVIEW shows the duplicate summary ("N existing products in this file —
> override / skip / cancel") once, then the run is non-interactive."

> **RUL-2 (SKU scope).** "RULING: SKU is unique per COMPANY, shared across its branches/locations.
> Barcode (EAN/UPC) is the cross-company key. Branch imports UPSERT stock, never products."
> Consequences: (a) spec the schema lane relaxing `(tenant_id, sku)` → `(company_id, sku)` after
> enumerating every tenant-wide SKU lookup (POS sync, search, reports) — follow the Session E
> payment_methods `(company_id, code)` re-scope precedent incl. census migration; (b) the import
> pipeline's natural key per product row = company_id + SKU (barcode as secondary match); (c) a
> second-location import must resolve products from the company catalog and upsert stock rows only.

> **RUL-3 (B-4, parties vs partners).** "One import type only: keep `ImportType::Parties` (the
> superset — the only one handling `opening_balance` → AR/AP historical documents); retire
> `Partners` from the wizard UI (keep the enum case readable for historical import_jobs rows, same
> pattern as the existing deprecated case). If the UX splits customers vs suppliers, do it as TWO
> TEMPLATES/PRESETS over the same Parties importer (pre-set `type` column + the relevant balance
> column) — never as a second import type/writer. This closes the narrow B-4; the B-18 party/contact
> redesign program (OQ1-5 sign-off) is SEPARATE and unchanged. Spec + lane per the usual protocol;
> sign-convention reference for the balance columns = docs/guides/legacy-migration-accounting-conventions.md §3."

> **REQ-R10 (owner requirement, in-product sign/perspective guidance, verbatim).** "The import UI
> must explain, AT THE POINT OF USE, what belongs where and from whose perspective — the user should
> never need the docs to get signs right. Specifically: Every balance-bearing import step (parties
> balances, AR/AP open items, GL openings) shows a short inline explainer + per-column hints: parties
> = signed, sign picks facture vs avoir, 'written from YOUR company's point of view'; open items =
> amounts ≥ 0 + `document_type`; GL = no signs, debit/credit columns, with the BANK-STATEMENT
> INVERSION called out ('your statement says "credit" when you have money — that is the bank's
> books; here, money in bank goes in the DEBIT column'). The validation/preview step should ECHO the
> interpretation back per row ('−300.000 → will create a customer credit note AVOIR-2026-007') so
> the user confirms meaning, not just format — same spirit as the ruled duplicate-preview choice.
> Downloadable templates carry the same hints in a header/comment row. i18n EN/FR (AR later per
> B-7 defer). Source of truth for wording: docs/guides/legacy-migration-accounting-conventions.md
> §3/§3b — UI copy must stay consistent with it (single source; do not fork the wording)."

> **RUL-4 (`.xls`, 2026-08-29 — SUPERSEDES the r3 "reject `.xls`" design).** "`.xls` = ACCEPT via
> conversion: the parser converts legacy `.xls` (PhpSpreadsheet `Xls` reader → cells) and then EVERY
> money/quantity cell must pass the same exact-decimal validation as XLSX/CSV (rule-19 regex ceilings
> on the STRING form, at most 3/4 decimals); because `.xls` has no exact lexical value, any money/qty
> cell whose exact value cannot be guaranteed (float repr with more than the allowed scale, or
> non-terminating decimal expansion) becomes a ROW error `xls_inexact_value` with remedy 're-save as
> .xlsx' — exactness enforced **per row, not by format ban**." Implemented in §4.11(1a); **OQ-G-24 is
> RULED**.

> **RUL-5 (opening corrections and reservations, 2026-08-29).** "Opening corrections fence at held
> quantity — as already decided; the refusal message must point to **'release the reservation or use
> a stock adjustment'**." Implemented in §4.9.2a; **OQ-G-3's amendment is RULED**.

> **RUL-6 (retention, 2026-08-29).** "ZIP purged immediately, other sources 90 days." Implemented in
> §4.14; **OQ-G-18 and OQ-G-23 are RULED**.

> **RUL-7 (unit scoping, 2026-08-29 — RULES OQ-G-25 and OQ-G-22 (ii)/(iii)).** "**Option (b):
> shared tenant defaults + per-company additions.** `units.company_id` **nullable**; two partial
> unique indexes on the `2026_04_28_120000` `unit_categories` idiom —
> `unique(tenant_id, code) WHERE company_id IS NULL` for shared rows and
> `unique(company_id, code) WHERE company_id IS NOT NULL`. **Visibility for company C** = rows where
> `company_id IS NULL` (system/tenant shared) **OR** `company_id = C`. **Shadowing is LEGAL and the
> company row wins:** if a shared row and a company row match the same code, the company row
> resolves and pickers show one entry (the company row). **Sequencing:** G-4's `UnitResolver` and
> G-12's empty-set invariant ship **now**, against today's schema (visibility = shared rows only,
> because `company_id` does not exist yet); the re-scope is a follow-on lane **G-13 'Unit company
> scoping'** — add column, partial indexes, resolver/picker/FormRequest updates, tests; **no backfill
> and no FK re-pointing**. **Blank unit cell on a CREATED product defaults to `pc`** with warning
> `unit_defaulted` in the result report; on **update** the existing unit is kept with **no warning**.
> Under (b) the default must resolve to a row visible to the company; if `pc` is not visible the row
> is refused `unit_default_missing`."

**RUL-3 supersedes the orchestrator's original D6** (two new `customers`/`suppliers` enum cases,
retire both `parties` and `partners`, collapse to one signed `opening_balance`). D6 as written into
this spec at §8 is the ruled shape: presets over one writer, `Partners` retired from the UI, the
`opening_balance_customer`/`opening_balance_supplier` pair **kept**.

---

## 3. Domain model changes

**Eleven migration files**, all in `apps/api/database/migrations/tenant/`: M1, M2, M3, M4, M5, M6a,
M6b, M6c, M7, M8, M9 (M6 is three files — §3.1d). **Every push to `origin/dev`
auto-deploys and runs `tenants:migrate` across the whole staging fleet**
(`feedback_push_dev_autodeploys_migrations`), so each migration below is written self-guarding
(driver check, table check, column check, idempotent no-op on already-correct shape).

**The whole program is forward-only, stated once and true of every file in the register below:
`down()` is a LOGGED NO-OP.** That is the precedent's shape
(`2026_08_28_100000_enforce_company_scoped_payment_method_codes.php:39-72,81-84`) and it removes the
r3 contradiction where a file was labelled forward-only while its `down()` dropped columns (G-R54).
Recovery from a bad deploy is a **new** migration, never a rollback.

### 3.1 The census-then-refuse pattern (G-3a's three migrations)

Copied from the precedent, not paraphrased:

1. **Guards** — skip on a driver other than `pgsql`/`sqlite`; skip if the table is absent; skip if
   any of the participating columns is absent (`:41-60`).
2. **Census then refuse** — group by the target tuple, `HAVING COUNT(*) > 1`; **log the group count
   unconditionally** (`Log::info` + `echo`, so the per-tenant deploy log shows it even when clean);
   if non-empty, `Log::error('<key>.collision_census', …)` with `company=<id> key=<value> (<n> rows)`
   per group and **throw `RuntimeException`** (`:86-127`). Never auto-merge, never auto-drop.
3. **Discover the old unique by its COLUMNS, not by a historical name** — the precedent exists
   because constraint names had drifted between environments (`:129-148`). Same risk here:
   `products_tenant_id_sku_unique` was created by `2025_11_30_052910_create_products_table.php:37`
   and never migrated, but `product_variants`' uniques were created by raw `DB::statement` behind a
   `pgsql` guard (`2026_06_02_100003_create_product_variants_table.php:34,37-40`), so SQLite
   environments have **no** such index and the drop must be a no-op there.
4. **Driver-specific DROP** — PG constraint vs standalone index are different paths (`:150-174`).
5. **Add the company unique only if absent** (`:65-69`).
6. **`down()` is a logged no-op** with the reason recorded (`:74-84`).

**Staging safety — verify, never assume (G-R23).** M1/M3 move to a **strictly weaker** scope, so on a
database whose constraints are intact a collision is **impossible**; the F-BUG-1 triage's 856
`products_tenant_id_sku_unique` violations are evidence the conflicting inserts were **rejected**, not
that duplicates exist (`docs/sessions/session-F-testing-2026-08-29/F-BUG-1-TRIAGE.md:28-29`; each row
runs in its own transaction, `ImportService.php:351-365`). The census is nevertheless **mandatory and
blocking** — see §11.1 for the two things that can actually produce a collision — because it proves
integrity at the one moment damage becomes irreversible. Per-tenant counts are recorded before the
auto-migration push (§11.2).

**The migration register.** Every file appears exactly once, with its owning lane, its wave, its
guards, its `down()` policy and the test that pins it. No later wave edits any of them (§3.1d).

| # | Migration file (name) | Table | Owning lane / wave | Change | Guards | Pinning test |
|---|---|---|---|---|---|---|
| **M1** | `enforce_company_scoped_product_skus` | `products` | **G-3a / Wave 0** | drop `unique(tenant_id, sku)` (`2025_11_30_052910_create_products_table.php:37`); add `unique(company_id, sku)` named `products_company_id_sku_unique` | driver + `hasTable` + `hasColumn`; census-then-refuse; index added only if absent | `ProductSkuCompanyScopeMigrationTest` — collision → `RuntimeException` with the census logged; clean → repaired shape; PG **and** SQLite |
| **M2** | `enforce_company_scoped_product_variant_skus` | `product_variants` | **G-3a / Wave 0** | drop **only** the partial PG unique `product_variants_tenant_sku_unique` (`2026_06_02_100003_create_product_variants_table.php:38-39`); re-create as `product_variants_company_sku_unique` on `(company_id, sku)` with the same `WHERE deleted_at IS NULL`. **`product_variants_tenant_barcode_unique` is NOT touched** — §3.1a | PG-only (`DB::statement`); complete no-op on SQLite, where neither index exists; census excludes soft-deleted rows | `VariantIndexScopeTest` — the SQLite no-op is asserted explicitly |
| **M3** | `enforce_company_scoped_partner_vat_numbers` | `partners` | **G-3a / Wave 0** | drop `unique(tenant_id, vat_number)` (`2025_11_30_052119_create_partners_table.php:34` — untouched by `2025_12_30_195300_fix_multi_company_unique_constraints.php:20-23`, which re-scoped only `code`); add `unique(company_id, vat_number)` | same as M1; rows with NULL `vat_number` excluded from the census (a unique permits many NULLs) | sibling case in `ProductSkuCompanyScopeMigrationTest` |
| **M4** | `add_company_and_source_hash_to_import_jobs` | `import_jobs` | **G-3b / Wave 0** | add `company_id` uuid **nullable**, indexed `(tenant_id, company_id, created_at)`; add `source_hash` char(64) nullable, indexed; evidence-based backfill per §3.3 | per-column `hasColumn`; index added only if absent; the backfill runs **whenever `company_id` is NULL**, independently of whether this run created the column | `ImportJobCompanyPinTest` — agreeing links, mixed links, one-company fallback, evidence-free |
| **M5** | `create_sku_sequences_table` | new `sku_sequences` | **G-2 / Wave 3** | `id` uuid PK, `tenant_id` uuid, `company_id` uuid, `prefix` string(16), `next_value` unsignedBigInteger default 1, `padding` unsignedTinyInteger default 6, timestamps; `unique(company_id, prefix)` | `! Schema::hasTable('sku_sequences')` → create, else no-op | `SkuGenerationTest` — first-use race on PG |
| **M6a** | `add_outcome_to_import_rows` | `import_rows` | **G-4 / Wave 2** | add `outcome` string(32) default `pending` (cast `ImportRowOutcome`, index `(import_job_id, outcome)`) and `duplicate_bucket` string(24) nullable (cast `DuplicateBucket`); **plus the historical backfill/census of §3.1d** | per-column `hasColumn`; index added only if absent; **repair order** = create missing columns → create the index if absent → run the backfill for every row still at the `pending` default. Each of the three steps is guarded independently, so a partially-repaired rerun (column present, index or backfill missing) completes the rest | `ImportRowOutcomeBackfillTest` — the four historical mappings + the census line + a partial-repair rerun |
| **M6b** | `add_error_code_to_import_rows` | `import_rows` | **G-4 / Wave 2** | add `import_error_code` string(64) nullable (indexed, cast `ImportErrorCode`) and `import_error_detail` jsonb nullable (cast `ImportErrorDetailData`) | per-column `hasColumn` | `ImportRowCodedErrorTest` |
| **M6c** | `add_lifecycle_columns_to_import_jobs` | `import_jobs` | **G-6a / Wave 0** | add `error_code` string(64) nullable (cast `ImportErrorCode` — the job-level code `formatJob` and the reaper both require), `error_detail` jsonb nullable, `claimed_at` timestamp nullable, `worker_started_at` timestamp nullable (§4.1.1), `source_purged_at` timestamp nullable (§4.14) | per-column `hasColumn` | `ImportJobClaimConcurrencyTest` + `ReapStuckImportsTest` |
| **M7** | `add_number_conventions_to_countries` | `countries` | **G-8 / Wave 3** | add `number_decimal_separator` char(1) default `'.'` and `csv_delimiter` char(1) default `','`, **then backfill every existing row from the explicit country-code table inside the migration** (§7.1) and log a census | `hasTable('countries')` + per-column `hasColumn`; the backfill is an idempotent `UPDATE … WHERE code IN (…)` that runs on every invocation, independently of column creation | `LocaleTemplateTest` brownfield case — FR, TN, US |
| **M9** | `add_company_scope_to_units` | `units` | **G-13 / Wave 3** | add `company_id` uuid **nullable**; create `unique(tenant_id, code) WHERE company_id IS NULL` and `unique(company_id, code) WHERE company_id IS NOT NULL` (raw `DB::statement`, the `2026_04_28_120000_fix_unit_categories_partial_unique.php:67-77` idiom); drop the old `unique(tenant_id, code)`. **No backfill and no FK re-pointing** — existing rows stay `company_id = NULL` and every `unit_id` stays valid (RUL-7, research §5(b)) | PG-only for the partial indexes, no-op on SQLite; `hasColumn` guard; **census-then-refuse** before dropping the old unique, on the M1 pattern — it must find zero, since the shared partition is strictly weaker | `UnitCompanyScopeMigrationTest` — clean repair, collision refusal, SQLite no-op |
| **M8** | `census_companies_without_visible_units` | `units` (read-only) | **G-12 / Wave 1** | **no schema change.** Counts, per company, the units visible to it (§4.13.2) that are `is_active`; logs `Log::info('units.visibility_census', ['companies' => n, 'empty' => n])` unconditionally and `Log::warning('units.empty_for_company', ['company' => <id>])` per empty company. **It never seeds and never refuses** — a brownfield tenant must not have its deploy aborted by reference data, and G-12's provisioning guarantee covers new companies | `hasTable('units')` + `hasTable('companies')`; driver-agnostic | `UnitsInvariantTest` — a company with no visible active unit is logged, a normal tenant logs zero |

**M2 partial-predicate note.** Re-creating a partial unique is not a `$table->unique()` call — it is
raw `DB::statement` behind the same `pgsql` guard the create-table migration used, and the drop must
use `DROP INDEX IF EXISTS` (these are indexes, not constraints — a partial unique cannot be a PG
constraint). M2's census must exclude soft-deleted rows to mirror the `WHERE deleted_at IS NULL`
predicate it is about to enforce, or it will refuse on rows the index would have allowed.

### 3.1a The barcode contract (G-R1) — read before touching any index

RUL-2 says "**barcode (EAN/UPC) is the cross-company key**". r1 mis-implemented that as a
company-scoped variant-barcode index, which is the **opposite** of the ruling and would have made
the barcode namespace weaker. r2 implements it as follows, and the distinction is load-bearing:

| Layer | r2 decision | Why |
|---|---|---|
| `products.barcode` | **No constraint added.** It has none today at any scope — only `index(['tenant_id','barcode'])` (`2025_11_30_052910_create_products_table.php:29,39`). | Adding one is a catalogue data-quality decision needing its own census. **OQ-G-19**, default NO, out of scope (§1.2). |
| `product_variants.barcode` | **Stays tenant-wide** — `product_variants_tenant_barcode_unique` on `(tenant_id, barcode) WHERE barcode IS NOT NULL AND deleted_at IS NULL` (`2026_06_02_100003_create_product_variants_table.php:40-41`) is untouched. | It is a **scan-safety** invariant, not a catalogue-scoping one, and `VariantLabelService` depends on it being tenant-wide (below). Narrowing it to company would let two sibling companies mint the same label barcode. |
| `product_variants.sku` | Re-scoped to `(company_id, sku)` by M2. | This is the catalogue key RUL-2 governs, and the variant repositories already take `$companyId` (Audit D §3). |
| Product **resolution** arm 2 (§4.5) | Matches `barcode` **within the company**. | Cross-company barcode identity is a **lookup concept, not a constraint**: a barcode identifies the same physical article across companies, but each company still owns its own product row for it. The import must not reach into a sibling company's catalogue. |

**`VariantLabelService` keeps its tenant-wide contract verbatim.** `valueIsUsable()` rejects a value
claimed by any variant barcode in the tenant **including soft-deleted ones** (`withTrashed()`), and
`collidesWithProductCode()` rejects a value equal to any product's `barcode` **or** `sku` in the
tenant (`VariantLabelService.php:45-57,60-80`). Its own docblock states why: "a stale offline POS
device may still hold the deleted variant row, so reusing its barcode would cause that device to
mis-scan", and Spec A resolves a scanned code against product barcode/sku **before** the variant
tier. Note this makes `VariantLabelService` a **consumer of `products.sku`**: once product SKUs may
repeat across sibling companies, `collidesWithProductCode()` will report a collision against a
sibling company's SKU. That is **the desired behaviour** (a scanner does not know about companies)
and G-3a must pin it with a test rather than "fix" it.

### 3.1b Constraint names are a shared contract (G-R2)

Two services **branch on the literal old index names** and turn the violation into a 422 instead of
a raw 23505:

- `ProductVariantService::saveBarcodeSafe()` — `DuplicateBarcodeException::isViolationOf($e, 'product_variants_tenant_barcode_unique')` (`ProductVariantService.php:131`).
- `ProductVariantService`'s restore path — branches on **both** `product_variants_tenant_barcode_unique` and `product_variants_tenant_sku_unique` (`:385,:387`), mapping each to its own 422 message.

Because M2 renames the SKU index, the restore path's SKU branch would silently stop matching and a
restore collision would surface as a raw 23505. G-3a therefore:

1. Introduces **one PHP constant class**, `App\Modules\Catalog\Domain\VariantIndexNames` (plus
   `ProductIndexNames` for `products_company_id_sku_unique` and `PartnerIndexNames`), and replaces
   every string literal at the sites above with a constant reference, **in the same commit as the
   migration** so the two can never drift.
2. Pins **create-collision → 422** and **restore-collision → 422** for both barcode and SKU against
   the **post-migration** names.
3. Adds a test that the same barcode on two variants in **sibling companies** is still refused
   (tenant-wide barcode preserved) while the same **SKU** in sibling companies is now allowed.

### 3.1c Uniqueness is LIFETIME, not active-only (G-R3)

M1/M2/M3 keep their **non-partial** shape for products and partners (M2's variant SKU keeps the
pre-existing `WHERE deleted_at IS NULL` predicate it already had — that is the status quo, not a new
choice). So a **soft-deleted** product still owns its SKU and a soft-deleted partner still owns its
VAT number. Today that is a trap: the DB unique includes deleted rows
(`2025_11_30_052910_create_products_table.php:32,37`; `2025_11_30_052119_create_partners_table.php:28,34`)
while every lookup and validator excludes them —
`ProductService::findExistingProduct()` uses the default soft-delete scope (`:289-308`),
`PartnerService` likewise (`:64-70`), and both FormRequests add `->whereNull('deleted_at')`
(`CreateProductRequest.php:177-184`, `UpdateProductRequest.php:162-169`). The result is a
"SKU available" answer followed by a 23505.

r2 closes it in one direction — **lifetime uniqueness, made visible**:

- Every **lookup, validator and generator pre-check** in scope uses `withTrashed()`.
- An import row whose SKU or VAT is held by a **soft-deleted** record is a **coded row error**:
  `sku_held_by_deleted_product` / `vat_held_by_deleted_partner`, message "restore or purge it in the
  UI". **No auto-restore** — restoring a product that carries stock history and movements is a
  master-data decision, not something a spreadsheet cell may trigger. Recorded as **OQ-G-20**
  (auto-restore instead? default NO).
- Both FormRequests gain `->where('company_id', …)` **and drop the `whereNull('deleted_at')`
  exclusion**, so the UI reports exactly the same conflict the DB will enforce.
- PG tests cover both deleted-holder paths (import row and UI create).

### 3.1d Deployed migrations are immutable; shared artifacts belong to the earliest lane (G-R27, G-R50, G-R54)

**Rule, stated once and binding on every lane:** a migration that has shipped to `origin/dev` has
been recorded in each tenant's `migrations` table by the auto-deploy `tenants:migrate` and **will
never run again**. Editing that file in a later wave therefore adds nothing anywhere — the columns
simply never appear. **A later wave always adds a NEW migration file.**

r2 violated this: one `M6` was to be created by G-4 for `outcome`/`duplicate_bucket` and then
*edited* by a later lane to add `import_error_code`/`import_error_detail`. Those two columns would
never have existed on any tenant that ran the earlier file. M6 is therefore **three files** — M6a,
M6b, M6c — each with its own timestamp, name, guards, casts and pinning test.

**The general rule this is one case of (stated once, binding on every lane):** a *shared artifact* —
an enum, a migration, a cast, a DTO used by more than one lane — is created by the **earliest lane in
the dependency order (§9) that needs it**; every later lane only **adds cases or rows** to it, and no
lane ever edits a deployed migration. Applied to this program:

| Shared artifact | Earliest need | Owner | Later lanes |
|---|---|---|---|
| `ImportErrorCode` enum | `ImportErrorCode` is CREATED by G-12 (earliest lane to need a job-level code; single case `units_not_seeded`) — dispatched 2026-08-29 before G-6a | **G-12** | G-6a adds `worker_lost` and owns M6c (`import_jobs.error_code`); until M6c lands, G-12's worker refusal stores the code via the existing `failJob()` leading-token convention and the upload refusal returns a coded 422 via the existing envelope. G-4, G-2, G-8, G-1, G-5 add their own cases |
| `ImportWarningCode` enum + collection cast + re-typed `addRowWarning()` | G-4 emits `preview_drift`, `matched_by_name`, `unit_defaulted` | **G-4** | G-2, G-5, G-1 add cases |
| M6a/M6b (`outcome`, `duplicate_bucket`, `import_error_code`, `import_error_detail`) | G-4 writes terminal outcomes **and** the coded row errors `unit_unknown` / `unit_ambiguous` / `barcode_ambiguous` | **G-4** (both files, one wave) | none |
| M6c (`error_code`, `error_detail`, `claimed_at`, `worker_started_at`, `source_purged_at`) | G-6a's claim + reaper + purge | **G-6a** | none |
| `ColumnMappingData`, `ImportJobOptionsData`, `ImportRowSourceData`, `ImportRowWarningData`, `ImportRowErrorBagData`, `ImportErrorDetailData` | G-4 rewrites the row-mapping layer | **G-4** | G-6a's `import_jobs.error_detail` reuses `ImportErrorDetailData` — but G-6a lands **first**, so G-6a declares that one DTO and G-4 declares the other five |

This rule replaces r3's "record `unit_unknown` as a leading token in `import_rows.errors` until a
later lane adds the column, then migrate them" workaround, which is **deleted**: M6b now lands in
G-4's own wave, so the coded column exists the moment the first coded row error is written.

**M6a's historical backfill.** M6a must not default every historical row to `pending`, or a completed
2026-07 import would report `0 imported` on the new detail page. Inside the migration, derive each
existing row's outcome from what the old columns already say, then log a census of the four counts:

| Existing state | Backfilled `outcome` |
|---|---|
| `is_imported = true` | `imported` |
| `is_valid = false` | `failed` (validation) |
| `is_valid = true AND import_error IS NOT NULL` | `failed` (execution) |
| otherwise (valid, not imported, no error) | `pending` |

The order matters — `is_imported` wins over an `import_error` left from an earlier attempt, because a
row that landed is imported whatever an earlier message says. Both `duplicate_skipped` and
`duplicate_loser` are **absent** from the backfill by construction: nothing could have produced them
before G-4.

### 3.2 The typed vocabulary: enums, the outcome model, and the JSONB schemas (rules 3 + 9)

#### 3.2.1 Enums

Ownership follows the earliest-lane rule of §3.1d. **Every code string in this program appears in
exactly one of these enums.** HTTP envelope constants (`IMPORT_NOT_EXECUTABLE`,
`IMPORT_ALREADY_STARTED`, `IMPORT_IN_PROGRESS`, `IMPORT_COMPANY_MISMATCH`, `IMPORT_TYPE_RETIRED`)
are a different namespace and are listed once in §5.3; they are not enum cases.

| Enum | File | Owner | Cases |
|---|---|---|---|
| `ImportErrorCode` (new, backed string) | `apps/api/app/Modules/Import/Domain/Enums/ImportErrorCode.php` | **G-12** (created; G-6a adds `worker_lost`) | **Row-level:** `duplicate_sku_in_company`, `sku_held_by_deleted_product`, `vat_held_by_deleted_partner`, `sku_allocation_exhausted`, `opening_locked_has_operations`, `opening_locked_reserved`, `opening_correction_failed`, `product_not_found`, `partner_not_found`, `location_unknown`, `category_resolution_failed`, `unit_unknown`, `unit_ambiguous`, `unit_default_missing`, `barcode_ambiguous`, `numeric_cell_is_date`, `formula_not_allowed`, `xls_inexact_value`, `party_key_insufficient`, `image_file_failed`, `internal_error` (the catch-all for an unmapped `\Throwable`). **Job-level:** `worker_lost`, `units_not_seeded`, `unsupported_file_format`, `mapping_not_injective`. One exhaustive `isJobLevel(): bool` match separates them, so `formatJob` and the row channel share one translation namespace and a new case cannot skip the decision |
| `ImportWarningCode` (new, backed string) — **exhaustive** | same directory | **G-4** | existing: `price_conflict`, `margin_without_cost`, `balance_not_posted`, `opening_failed`, `quantity_ignored_service`, `qty_without_cost`, `expiry_in_past`, `expiry_conflict_existing_lot`, `expiry_ignored_not_batch_tracked`, `expiry_ignored_no_default_lot`; the **dynamic `category_*` family** composed at `ImportService.php:557-562` from `CategoryResolutionOutcome` — `category_matched_by_slug`, `category_created`, `category_restored` (`Matched` is not a state change and emits nothing, `CategoryResolutionOutcome.php:35-39`); new: `sku_generated`, `code_generated`, `matched_by_name`, `duplicate_in_file`, `preview_drift`, `opening_skipped_existing`, `opening_corrected`, `unit_defaulted`, and the `location_unresolved` **split** into `location_not_supplied` / `location_code_unknown`. **Two read-only legacy cases**, retired from emission but still translated because historical rows carry them: `location_unresolved` and `opening_exists` |
| `ImportRowOutcome` (new, backed string) — M6a column | same directory | **G-4** | `pending`, `imported`, `duplicate_skipped`, `duplicate_loser`, `failed`, `opening_locked` |
| `DuplicateBucket` (new, backed string) — M6a column | same directory | **G-4** | `new`, `existing_sku`, `existing_barcode`, `existing_name`, `in_file`. **`in_file`, not `duplicate_in_file`** — a bucket and a warning must not share a string (§3.2.1's one-enum rule); the loser row carries bucket `in_file` **and** warning `duplicate_in_file` |
| `DuplicatePolicy` (new, backed string) | same directory | **G-4** | `override`, `skip` |
| `ImportStatus` | existing, `ImportStatus.php:9-14` | — | **unchanged.** The six cases are `pending`, `validating`, `validated`, `importing`, `completed`, `failed`. **No new case is added by this program** — not `cancelled` (§4.7) and not `completed_with_errors` (§3.2.2) |

**`duplicate_skipped` is an OUTCOME, never a warning**, and `opening_corrected` is a **WARNING, never
an outcome** — the two mistakes r3 made in opposite directions. Each string lives in one enum only.

**Product-image failures are coded too.** `ProcessProductImageImport` writes per-file failures as
free text into `errors.file` (`:118-121`). They become `ImportErrorCode::image_file_failed` with a
typed detail (`filename`, `sku`, reason), so "every refusal is coded" holds without a carve-out.

**Why `location_unresolved` splits:** one fixed detail string today covers two different causes and
is false for the majority staging case (422 rows where nothing was supplied) —
`ProductOpeningStockPhase.php:79` vs `:243-255`, Audit B §2.4.

#### 3.2.2 The row-outcome model and its equations — stated ONCE, referenced everywhere (G-R46)

`import_rows.outcome` (M6a, cast `ImportRowOutcome`) is the single authority for what happened to a
row. `is_imported` is kept in sync as `outcome === imported` for backward compatibility with existing
consumers, but it is never the decision input again.

| Outcome | Set when | Terminal? |
|---|---|---|
| `pending` | validated, not yet applied | no — this is the retry set |
| `imported` | the entity write committed (**including a corrected opening**, §4.9) | yes |
| `duplicate_skipped` | policy `skip` and execute-time resolution matched an existing entity | yes |
| `duplicate_loser` | a later row won the same `(product, location)` side-effect key | yes |
| `failed` | the row transaction rolled back; `import_error_code` is set | yes |
| `opening_locked` | the master write committed but the opening was refused by the fence (§4.9) | yes |

**Equations (the only definitions; §4, §9 and §12 reference them and never restate them):**

```
processed      = imported + duplicate_skipped + duplicate_loser + failed + opening_locked
processed      = total_rows                    // at the end of a finished run; a row left
                                               // `pending` means the run did not finish
successful_rows = imported
skipped_rows    = duplicate_skipped + duplicate_loser
failed_rows     = failed + opening_locked
successful_rows + skipped_rows + failed_rows = total_rows
```

- **`opening_locked` counts as FAILED**, not as success: the operator must act on it (post a stock
  adjustment). Its master row *did* land, so the operator-facing message says so explicitly and the
  row keeps its `imported_entity_id`; it is the counts, not the entity, that call it failed.
- **`opening_corrected` is a warning on an `imported` row**, so it needs no equation term.
- **Terminal job status uses the existing `ImportStatus` cases only:** `Failed` iff
  `successful_rows + skipped_rows === 0`; otherwise `Completed`. A `Completed` job with
  `failed_rows > 0` is surfaced through the counts and the by-code breakdown (§4.10, G-6b) — **no
  `completed_with_errors` status is invented**, because `ImportStatus.php:9-14` has no such case and
  no migration in this program adds one.
- **`ProcessImportJobStatusTest.php:163-177` asserts `failed_rows = rows(is_imported = false)`**,
  which the model above supersedes. G-4 amends it deliberately to the equations above.
- **One permitted terminal→terminal transition: `imported` → `opening_locked`.** The opening phase
  runs after the row loop by construction (`ImportService::finalizeImport`, `:464-471`), so the fence
  refusal is a *later* decision about a row that already landed its master write. It is written in
  the same statement as the row's coded error. Nothing else ever rewrites a terminal outcome, and the
  resume selector (`is_valid = true AND outcome = pending`) never re-selects one.

#### 3.2.3 Typed JSONB — the exact serialized schema of every column (rule 3, G-R51)

Rule 3 says "JSONB columns must have a corresponding PHP DTO", and today **every** one is a bare
`'array'` cast (`ImportJob.php:83-92`, `ImportRow.php:62-70`). The shapes below are the ones the code
actually persists today, re-verified at `a4ceeb0f5`; each cast must reproduce them exactly.

| Column | DTO / cast | Owner | Exact serialized shape |
|---|---|---|---|
| `import_rows.data` | `ImportRowSourceData` | G-4 | a **string map** of canonical target key → cell value, **plus two reserved keys**: `_provided` (list of canonical keys whose source cell was non-blank, §4.4.1) and `_results` (a **one-level nested `array<string,string>`** of execution breadcrumbs). Execution **mutates this column in place** — `type` (`ImportService.php:513`), `tax_rate` (`:529-530`), `sale_price` and the `_results` merges (`:525-549`), then `finalizeImport` merges each phase's `results` map (`:473-485`; the phases return `array{row_id,code,detail,results: array<string,string>}` — `ProductOpeningStockPhase.php:30,218,263`). So it is **not** a flat source map, and the DTO must model the reserved keys explicitly. Every value is a **string** (rule 19) |
| `import_rows.errors` | `ImportRowErrorBagData` | G-4 | a **bag keyed by canonical field name** → list of already-translated validator messages: `{"sku": ["The sku field is required."], "sale_price": ["…"]}` (`ImportService.php:162-166`; `ImportRow.php:19` types it `array<string, array<string>>`). It is **not** a collection of per-error DTOs, and it is **not** where coded errors live — those go to `import_error_code` (M6b) |
| `import_rows.warnings` | collection cast of `ImportRowWarningData` | G-4 | a **list** of `{"code": "<ImportWarningCode>", "detail": "<string>"}` (`ImportRow.php:20`, written by `addRowWarning`, `ImportService.php:93-98`). `code` becomes enum-backed; `detail` stays a free string |
| `import_rows.import_error_detail` | `ImportErrorDetailData` | G-4 (M6b) | a per-code payload with nullable typed fields — `supplied`, `accepted` (list), `candidates` (list), `sku`, `existing_product_id`, `filename`, `held_quantity`, `held_at`. Unset fields are omitted, never `null`-padded |
| `import_jobs.column_mapping` | `ColumnMappingData` | G-4 | `{"<source header>": "<canonical target>"}`, validated **injective** (§4.10) |
| `import_jobs.options` | `ImportJobOptionsData` (new — no such DTO exists today; the FE has a `types.ts` interface only) | G-4 | `duplicate_census` (`DuplicateCensusData`), `duplicate_policy`, `location_code`, `price_authority`, `placement_mode`, `placement_node_types` |
| `import_jobs.error_detail` | `ImportErrorDetailData` | **G-6a** (it lands first — §3.1d) | same payload shape, job-level |

**Legacy hydration is part of the contract**, documented on each cast class and pinned by a fixture
per historical shape: (1) `data` with **no `_provided` key** → the mask hydrates **empty**, which is
safe because a legacy row is terminal and the merger is never invoked on it; (2) `data` with no
`_results`; (3) `warnings` stored as `null` versus `[]`; (4) `errors` stored as `null` versus `{}`;
(5) a warning whose `code` is not a current case → hydrates to the matching **legacy** case
(`location_unresolved`, `opening_exists`) if there is one, otherwise to a null code with the raw
string preserved in `detail`, which the FE renders through `import.warnings.unknown`. Unknown keys
are ignored, missing keys defaulted; nothing throws on read.

Controller and service signatures consume the DTOs instead of `array<string,mixed>`.
`addRowWarning()` changes signature to accept `ImportWarningCode` rather than a string, so a new
emitter cannot invent an untranslatable code. `php artisan typescript:transform` runs in each lane
that adds a DTO and the generated `packages/shared/types/` output is committed with it (rule 7).

### 3.3 `import_jobs.company_id` backfill (M4)

r1 claimed a historical job has "no recoverable company attribution". **That was wrong (G-R15):**
`import_rows.imported_entity_id` links each applied row to the entity it created
(`2025_11_30_150000_create_import_tables.php:44`), and every one of those entities carries a company
— `products.company_id` (`Product.php:62,94`), `partners.company_id` (`Partner.php:32,99`),
`composite_items.company_id`, and for GL openings the **posted journal entry** the phase relinks the
rows to (`AccountingBalancesPhase.php:67,296-301`, pinned by
`OpeningBalancesImportBatchTest.php:192,437,460`).

M4's backfill is therefore **evidence-based**, in this order:

1. Resolve each job's `imported_entity_id` set against `products` → `partners` → `composite_items` →
   `journal_entries` (by type). If **all** resolvable entities agree on one company, set it.
2. If they **disagree**, leave `company_id` **NULL** and `Log::warning('import_jobs.company_backfill.mixed', …)`
   with the per-company evidence counts. **No majority rule** (G-R35): a mixed job has no
   authoritative company, a majority silently hides the minority's writes, and attributing the job to
   one company would surface it under a company that did not produce half of it.
3. If the job has **no** resolvable entity (never executed, or GL rows whose batch never posted) and
   the tenant holds exactly **one** company, use that company.
4. Otherwise the row stays NULL, with `Log::info('import_jobs.company_backfill.unattributed', ['rows' => n])`.

So `company_id` is set **only when all resolvable links agree**, or when the tenant has exactly one
company. Everything else is honestly unattributed.

**Unattributed-history policy (G-R15).** A NULL job is shown to **all** companies of the tenant with
an explicit "unattributed" badge and a tooltip explaining it predates company pinning. It is **not**
assigned to "the first company" — that would arbitrarily hide a job from the company that actually
produced it. Read access is still `imports.manage` within the tenant; no new permission.

The column stays nullable forever (**OQ-G-6** ratifies pinning a job to its company and scoping
history by it), and no `NOT NULL` is added — making it required would fail the
migration on brownfield tenants and the deploy runs fleet-wide. New jobs always carry it (§5.2).
Existing test fixtures that create `import_jobs` rows must be updated for M4 in the same lane.

---

## 4. Pipeline design

### 4.1 Lifecycle state machine (as coded today + additions)

Today's six statuses (`ImportStatus.php:9-14`) and their writers are enumerated in Audit B §1.3.
**No status is added** (§3.2.1). The graph changes in exactly two ways: the controller claims the job
(so `importing` is entered by the HTTP request, not by the worker), and a reaper can end a job the
worker abandoned.

```
(none) ──createJob──> pending ──validateJob──> validating ──> validated
                         │                                       │
                         └───────────────┬───────────────────────┘
                                         │
                    controller CLAIM (compare-and-set, §4.1.1)
                    UPDATE … SET status=importing, claimed_at=now()
                    WHERE status IN (pending, validated)
                                         │
                                     importing ──finalize (CAS)──> completed | failed
                                         │
                                         └── reaper (NEW, CAS, two clocks §4.7) ──> failed
                                                                       error_code = worker_lost
```

Additions: the reaper edge above (§4.7), and a **DELETE** that removes a job in
`pending|validating|validated` outright (job row, its `import_rows` via the existing cascade
`2025_11_30_150000_create_import_tables.php:48-51`, and the uploaded file). DELETE is **not** a
status transition — no `cancelled` case is added, keeping the Session-B `I — Import jobs`
workstream's future adjacency map unchanged. The row-level resume filter (§4.2) is preserved and is
part of the re-execution guard (`ImportReExecutionGuardTest.php`), which must not regress. The
`status = Pending`-before-dispatch dance (`ImportController.php:550-563`) **disappears** — the claim
replaces it, which is exactly what that code's own comment warns about.

#### 4.1.1 Claim ownership: the CONTROLLER claims, the worker re-verifies (G-R21 + G-R38 + G-R50)

r2 said "one claim used by both paths" without naming the owner, which is not implementable: the
controller must count rows to choose sync vs async, and if it claims first with the same predicate
the worker's own claim then loses; if only the worker claims, two HTTP requests both return 202.
Both sides also read row state **before** any claim today — the worker calls `canStart()` at
`ProcessImportJob.php:86`, and the controller runs its whole precondition block and a
`rows()->where('is_valid', true)->count()` before dispatching (`ImportController.php:490-563`).

1. **The controller claims first, before any row read.** One
   `ImportJobClaimService::claim(ImportJob): bool` issues the conditional
   `UPDATE import_jobs SET status = importing, claimed_at = now() WHERE id = ? AND status IN
   (pending, validated)` and returns whether it affected a row. A loser returns **409
   `IMPORT_ALREADY_STARTED`** immediately, having read nothing.
2. **The sync/async decision happens after the claim** — the row count is read by the winner only.
3. **The worker re-verifies rather than re-claims.** It issues its own conditional
   `UPDATE … SET worker_started_at = now() WHERE id = ? AND status = importing AND worker_started_at
   IS NULL` (both columns from **M6c**). Zero rows affected → the delivery is a duplicate and the job
   **exits idempotently** with a log line, not an error. The worker never calls `canStart()` before
   this point.
4. **There is NO ownership token.** r3 claimed one and never defined it; the claim is
   `claimed_at` + `status`, nothing more. A token would only be needed if a failed job could be
   resumed or redispatched, and it cannot — see the one-shot invariant below.
5. **Every terminal transition is compare-and-set.** Worker and reaper alike write
   `UPDATE import_jobs SET status = <completed|failed>, … WHERE id = ? AND status = 'importing'`
   and **check the affected-row count**. A writer that affected zero rows lost the race, logs
   `import_jobs.terminal_write_lost` and returns without touching counters. This is what makes the
   reaper and a late-finishing worker safe against each other; the clocks (§4.7) make the race rare,
   the CAS makes it harmless.
6. **One-shot invariant:** a job that reached `failed` — by the worker, by the reaper, or by a
   dispatch failure — is **never resumed and never redispatched**. Re-running an import means
   creating a **new** job (the wizard's "re-upload" affordance, §4.10). `canStartImport()`
   (`ImportStatus.php:16-20`) already forbids it; the reaper therefore needs no ownership handover.
7. **Dispatch failure** leaves a job `importing` with `claimed_at` set and `worker_started_at` NULL —
   which is precisely the first of the reaper's two clocks (§4.7).

Tests (G-6a): two concurrent sync requests (one 200, one 409), two concurrent async requests (one
202, one 409), worker **double delivery** (second exits without reprocessing), dispatch failure
reaped, and the two race tests of §4.7. The existing async redelivery test
(`ImportReExecutionGuardTest.php:237-273`) stays green, as does the row-level resume filter.
### 4.2 Preview, duplicate computation, and durable row outcomes (D10, RUL-1)

**Today:** `GET /imports/{id}/preview` returns 4 sample rows, job-level counts and the placement
dry-run, and performs no existence lookup at all (`ImportController.php:35,261-296`; empty grep at
Audit C §2.4). Validation already ran over **all** rows at upload (`validateJob` chunks at 500,
`ImportService.php:139-188`), so the 4-row cap is a display cap only.

#### 4.2.1 Two layers, not one (G-R6)

r1's "same natural key → later row wins" would have destroyed a **valid and pinned** shape: a
products file that opens the same SKU at several locations. `ProductsImportPipelineTest.php:465-525`
imports `LOT-TWOSITE` twice in one file — `MAIN, 4.0000` and `ANNEX, 3.0000` — and asserts **both**
post, with the second row's expiry filling the undated lot. That is the contract, and it stays green.

The resolution is that a products row carries **two different kinds of instruction**:

| Layer | Key | Duplicate semantics |
|---|---|---|
| **Product master** (name, prices, barcode, unit, category, brand, tax) | the natural key of §4.3 → one product | rows resolving to the same product are **coalesced**: later non-blank cells win, blank cells never overwrite (§4.4). Not a conflict — the normal multi-location shape. |
| **Stock side effect** (opening quantity, cost, expiry, location) | `(product_id, location_id)` — the same key the opening poster enforces (`OpeningBalancePostingService.php:90-105`) | each **distinct** location posts its own opening. A within-file duplicate exists only when a later row repeats **the same product AND the same location**. |
| **Placement side effect** | `(product_id, location_id)` — **the path is the value, not part of the key** | the schema enforces one **live** placement per `(product_id, location_id)` — `product_placements_product_location_live_unique … WHERE deleted_at IS NULL` (`2026_07_07_100001_rename_zones_to_location_nodes.php:81-82`), and `ProductPlacement`'s own docblock says "reassigning to another node **moves** the live row" (`:17-22`). Two rows for the same product+location with **different** paths are therefore still one instruction: the same winner as the stock instruction applies, the loser reports `duplicate_in_file`, and the preview shows the **final** moved path. |

So `duplicate_in_file` fires **only** on a repeated `(product, location)` pair. The loser row gets
outcome `duplicate_loser` and warning `duplicate_in_file` naming the winning row number; the winner
posts. Two-location and same-location cases are both pinned.

#### 4.2.2 Preview census (advisory)

A `DuplicateCensusService` (constructor-injected, rule 13) runs **server-side over ALL rows** in one
pass at the end of `validateJob()`, using the **same resolver the writer uses**. It writes:

- a per-row `import_rows.duplicate_bucket` (`DuplicateBucket`: `new`, `existing_sku`,
  `existing_barcode`, `existing_name`, `in_file`), persisted **per 500-row chunk**; and
- an aggregate `DuplicateCensusData` into `import_jobs.options['duplicate_census']` for the summary.

Cost: keys are batched into one `whereIn` per key kind per chunk — three queries per 500 rows, so
roughly 300 lookups for a 50 000-row file. The `(import_job_id, outcome)` index from M6a backs the
execute-time selectors.

**The bucket is ADVISORY.** Entities can be created between preview and execute (another operator,
another job, the UI). At execute the resolver runs **again inside the row transaction** and **that
result is authoritative**. When it disagrees with the stored bucket, the row records warning
`preview_drift` with both values, and the job's completion payload reports the drift count so the
operator learns the preview they approved was not what executed.

#### 4.2.3 The RUL-1 choice

The wizard shows the summary **once**, on the validation step, with override / skip / cancel
(RUL-1). The choice persists as `ImportJobOptions.duplicate_policy ∈ {override, skip}`; cancel is a
client action (navigate away, offer Discard, §4.7). Execute is **non-interactive** — it reads the
persisted policy and never prompts. That is already true by construction today (nothing prompts
inside `executeImport`, `ImportService.php:351-370`; the only pre-execute dialog is the
partial-import confirm, `ImportWizardPage.tsx:509-519,1185-1197`) and must stay true.

#### 4.2.4 Durable outcomes replace the `is_imported` + warning inference (G-R5, G-R26, G-R46)

A skipped row marked `is_imported = false` + a warning is **not distinguishable** from "valid and not
yet processed": the execution selector re-selects every `is_valid = true AND is_imported = false` row
(`ImportService.php:287-294`), so a resumed job re-processes every skipped row, and the opening phase
avoids them only by accident (`ProductOpeningStockPhase.php:40-44`). Hence the durable column.

**The outcome vocabulary, the equations, the job-status rule and the one permitted terminal→terminal
transition are defined in §3.2.2 and are not restated here.** What this section adds is *when the
write happens*.

**Atomicity — the outcome commits with the decision it describes (G-R26).** Writing outcomes "per
500-row chunk" reintroduces the exact hazard the column exists to remove: the entity write commits
inside the row transaction (`ImportService.php:351-365`), so if the process dies before the chunk
update, `outcome` is still `pending`, the resume selector picks the row up again, and the write is
applied **twice**. So:

- **Every terminal outcome commits with its entity write or with its explicit no-op decision, in the
  same row transaction** — `imported`, `duplicate_skipped`, `duplicate_loser`, and the finalize
  phase's `opening_locked`. One commit, one truth. `is_imported` is set in the same statement, so the
  two can never disagree.
- **`failed` is written AFTER the rollback**, in its own single-row `UPDATE` immediately following
  the `catch` — it cannot be inside the transaction that just rolled back. It carries the coded error
  and detail in that same statement, exactly as `import_error` is persisted today (`:360-364`).
- **Chunking survives only for reads and aggregates**: the advisory `duplicate_bucket` census
  (§4.2.2) and the end-of-run recomputation of the job counters. **No terminal outcome is ever
  written per chunk**, in this section or in any lane brief.

**Fault-injection tests (owned by G-4, listed once here and referenced from §9):**

| Fault | Assertion after resume |
|---|---|
| kill after an entity commits, before the next row | the row is `imported`, is **not** re-selected, and the entity exists exactly once |
| kill after a duplicate no-op decision | the row is `duplicate_skipped`, no entity was created, and it is not retried |
| kill after a correction commits, before the next row | the row is `imported` + warning `opening_corrected`, and the correction is not re-applied (§4.9) |
| throw inside the row transaction | the row is `failed` with its code, the entity does **not** exist, and the post-rollback write is a separate statement |

Rules:
- `getValidRows()` selects `is_valid = true AND outcome = pending` — **terminal outcomes are never
  retried**.
- Counts and terminal status derive from `outcome`, never from warning JSON (§3.2.2).
- The opening phase's selector becomes `outcome = imported` rather than `is_imported = true`.
### 4.3 Natural keys (R3) — the contract this program pins

| Import type | Natural key (match precedence) | Scope | Today | Change |
|---|---|---|---|---|
| `products` | file `sku` → `barcode` → normalized `name` — **all three within the company**, `withTrashed()` | `tenant_id + company_id`, **lifetime** (§3.1c) | strict ladder: a non-blank `sku` that misses returns `null` without trying barcode; default soft-delete scope hides trashed holders (`ProductService.php:289-308`) | becomes a true chain (§4.5); trashed holders become a coded refusal |
| `parties` | `code` → `vat_number` → `name`, `withTrashed()` | `tenant_id + company_id`, **lifetime** | `PartnerService.php:59-70` | keys unchanged, but identity is resolved **before** any synthetic code is minted (§4.6) |
| `opening_balances` (GL) | `OpeningBalanceBatch.import_file_reference['import_job_id']` | company, via the batch | `AccountingBalancesPhase.php:425-431` | unchanged; the "already imported" message becomes coded (§4.8) |
| `composite_items` | `code` | `tenant_id + company_id`; DB agrees `unique(company_id, code)` (`2026_02_19_100001_create_composite_items_table.php:34`) | `CompositeItemImportService.php:63-66` | unchanged — 1d-safe by construction |
| `categories` (create-on-miss) | `name` → `slug` → trashed-slug (restore) | `company_id`; DB agrees `unique(company_id, slug)` | `CategoryResolutionService.php:63-98` | unchanged |
| `product_images` (ZIP) | SKU from filename | **tenant-wide today** (`ProductImageImportService.php:238-240`) | — | **company-scoped** in G-3a. `ProcessProductImageImport::__construct` takes only `(importJobId, zipPath, tenantId)` (`:53-58`) — G-3a adds `companyId` to the job and threads it into the lookup. This is the **real** queued-context gap (G-R16). |
| `stock_levels` | — | — | retired | stays retired |

`import_jobs.source_hash` (M4) is a **sha256 of the uploaded bytes**, stored for support and for a
non-blocking preview notice ("this file was already imported on <date>"). It is deliberately **not** a
uniqueness constraint: re-uploading a corrected file with identical bytes is legitimate (e.g. after a
company switch), and the per-type natural keys are what actually guarantee idempotency.

### 4.4 Coalescing merge, applied at the row-mapping layer (D2, G-R12)

**Today** the product upsert writes `purchase_price`, `barcode`, `unit`, `description` from the row
even when the cell is blank, because `emptyToNull()` turns `''` into `null` and the attribute array
always carries the key (`ProductService.php:65-74,313-320`) — a thinner re-import NULLs columns the
first import populated (finding `G-F-10`). The partner upsert does the same, unconditionally, for
`code, name, type, email, phone, vat_number, street_address, street_address_2, city, state,
postal_code, country, country_code` (`PartnerService.php:90-106`). One field already behaves
correctly: `tax_rate` is preserved when the file cell is blank (`ProductService.php:99-113`).

**New rule (products and partners alike):** on **update**, a **blank source cell never overwrites an
existing value**; a non-blank source cell overrides ("newer overrides" is field-level). On
**create**, a blank cell writes NULL as today (**OQ-G-13**). No **clear token** (`\N`, `<empty>`, …)
— YAGNI: a magic token is a new parsing surface needing its own escaping story, and clearing a field
stays a UI edit (**OQ-G-5**).

#### 4.4.1 Provenance must be captured BEFORE defaulting (G-R12)

A merger applied to the final update payload cannot work: by then the row no longer knows what the
operator typed, because `importProduct()` **mutates `$row->data` in place** with computed values
before the upsert:

- `$data['type'] = $this->emptyString(...) ? ProductType::Part->value : $data['type']` (`ImportService.php:513`) — a blank type becomes `part`;
- `$data['tax_rate'] = $tax->taxRate` when the cell is blank (`:529-530`), from the resolved category→company ladder;
- resolved category, price and `_results` breadcrumbs are merged in (`:525-549`).

A generic null-coalescer over the final payload would therefore see `part` and a resolved rate as
"provided", and — worse — would **preserve a stale `default_tax_configuration_id`** in the case where
the current pinned behaviour requires a **computed NULL to clear it**.

So: `applyColumnMapping()` (`ImportService.php:691-717`) additionally emits a **`provided` set** —
the canonical target keys whose **source cell was non-blank** — carried alongside `data` and handed
to the writer. The merge policy is then explicit:

A blanket "derived fields are exempt" rule defeats the headline rule (G-R34): a thinner
`name + sale_price` re-import would resolve a blank `tax_rate` to the current category/company default
and overwrite the operator's rate. The rule is instead **governing-cell provenance** — a derived field
is recomputed **only when a source cell that governs it was provided**:

| Field | Governing source cell(s) | On update when the governing cell is BLANK |
|---|---|---|
| every source-mapped field (name, sku, barcode, unit, description, prices, addresses, contacts…) | itself | **preserve** |
| `tax_rate` | `tax_rate`, or `category_name` (a category change can re-derive the rate) | **preserve** the existing rate |
| `default_tax_configuration_id` | the same two cells | **preserve** — and when a governing cell IS provided, re-resolve, **including to NULL** when nothing matches |
| `category_id` | `category_name` | **preserve** |
| `brand_id` | `brand` | **preserve** |
| price-authority output (`sale_price`) | the mapped price columns + the job's `price_authority` option | **preserve** |
| `type` | `type` | **preserve** on update; the `part` default applies **only on CREATE** (`ImportService.php:513` runs for both today — it becomes create-only) |

So "blank source never overwrites" holds without exception; the derived rule is not an exception to
it but the same rule applied to a field whose source is another cell.

**The three `default_tax_configuration_id` tests still hold**, and re-verifying why matters: each of
them **supplies the governing cell**. `:823` re-imports at the *same* `tax_rate` → governing cell
provided → re-resolve → same configuration, so "does not clobber" still passes. `:869` re-imports at
a *changed* `tax_rate` → provided → re-resolve to the new one. `:914` re-imports at a rate no
configuration states → provided → re-resolve → **NULL**, clearing the stale id. None of the three
exercises a blank `tax_rate`, which is why a blanket exemption looked justified by them and was not.

#### 4.4.2 Pinned tests, enumerated and deliberately amended

Codex confirmed (`rg` exited 1) that **no existing test positively asserts the generic
blank-overwrites behaviour** — so this is a **behaviour change to code**, not the overturning of a
pinned contract. The tests that touch the seam, and their disposition:

**Seven tests stay green untouched**, and it matters *why*: the three
`default_tax_configuration_id` cases (`ProductsImportPipelineTest.php:823`, `:869`, `:914`) each
**supply** their governing cell, so each re-resolves — including `:914`, which re-resolves to NULL and
would go red under a naive coalescer over the final payload; `:470` is the §4.2.1 two-layer contract
(same SKU at MAIN + ANNEX + THIRD); and `PartnerCodeUpsertTest.php:70,94,119` all supply non-blank
values or exercise the §4.6 ladder.

**One test is amended, deliberately.**
`ImportRowWarningsTest::test_warnings_accumulate_and_do_not_affect_validity_or_failed_counts` (`:81`)
ends with `assertNull($exportPath)` for a warning-only job (`:107-108`); warning rows are now **in**
the export (§4.10), so that assertion becomes a **non-null** path containing the row with
`_status = warning`. The first half — warnings do not affect `is_valid` or `failed_rows` — stays
exactly as-is. G-1 owns the amendment.

Implementation: one `CoalescingAttributeMerger` (constructor-injected) taking
`(existing, incoming, provided)` — **there is no `exemptKeys` parameter and no "computed-exempt"
class** (G-R34): a derived field is governed by the source cell(s) named in the matrix above, and the
merger asks the same question of every field. Money/quantity values pass through as **strings**,
never floats (rule 19) — the merger chooses between two strings, it never arithmetics.

### 4.5 Product identity, resolution and SKU generation (D3, R2, RUL-2, G-R7/G-R8)

#### 4.5.1 ONE identity contract (G-R7)

r1 contained a genuine contradiction: arm 3 matched by normalized name, while a G-2 test demanded
that two same-name blank-SKU rows produce **two different** generated SKUs, and the demo expected a
later blank-SKU re-import to **match** by name. Those cannot all hold. r2 states one contract:

> **When a row supplies neither SKU nor barcode, the normalized product name within the company IS
> the identity.**

Consequences, stated plainly so no test contradicts them:

- Two blank-SKU rows with the **same name** in one file resolve to **one product** and therefore
  **one generated SKU**. The later row is a duplicate of the earlier at the master layer (coalesced,
  §4.2.1); if it also repeats the location, it is a `duplicate_in_file` loser at the stock layer.
- A later blank-SKU **re-import matches by name** and updates rather than creating — which is what
  makes the 859-row tenant-#1 file idempotent, and is what the demo script asserts.
- Two genuinely **distinct** products that share a name and supply no SKU and no barcode **will
  merge**. That is accepted (it is also today's behaviour, via the name-slug SKU at
  `ProductService.php:59-63,282-287`), and it is made **visible**: the preview buckets them as
  `existing_name` / `in_file` and the row carries `matched_by_name`, which is the operator's
  cue to add SKUs or barcodes. Recorded as **OQ-G-21**, default accept.

**Match order** — `(1) company_id + sku`, `(2) company_id + barcode`, `(3) company_id + normalized
name`, all `withTrashed()` (§3.1c), with one guard: **arm (3) is reached only when the row supplied
neither `sku` nor `barcode`.** An explicit SKU or barcode is a positive assertion of identity;
falling from a missed explicit SKU through to a name match would let a row with a genuinely new SKU
take over an existing product and rename it. Arms (1)→(2) do chain (a supplied SKU that misses still
tries the barcode), which is the change from today's strict ladder. This guard is a deliberate
reading of D3's literal wording, surfaced as **OQ-G-11**.

Arm (2) matches barcode **within the company** — cross-company barcode identity is a lookup concept,
not a constraint (§3.1a). Because `products.barcode` carries **no unique index at any scope** and
OQ-G-19 defers adding one, arm 2 must **count** its company-local matches rather than take
`first()` as the resolver does today (`ProductService.php:296-307`): **0** → continue to arm 3 (or
create); **1** → resolve; **more than 1** → coded row error `barcode_ambiguous`, whose detail lists
the candidate SKUs, plus its own preview bucket/count. Deferring the constraint does not license a
nondeterministic update of whichever duplicate the planner happens to return first (G-R37). A
brownfield duplicate-barcode fixture pins it.

**Deleted holders.** If arm 1 or 2 resolves to a **soft-deleted** product, the row is refused with
`sku_held_by_deleted_product` (§3.1c) rather than silently resurrecting it or hitting a 23505.

#### 4.5.2 Generation

The `barcode`-as-SKU and `Str::slug(name)`-as-SKU fallbacks (`ProductService.php:59-63,282-287`) are
**both removed**. When resolution finds nothing and the row supplied no SKU, one is allocated from a
company sequence.

- **Storage:** `sku_sequences (company_id, prefix, next_value, padding)` (M5). A PostgreSQL
  `SEQUENCE` per company is not available — multi-tenancy is database-per-tenant with one shared
  schema per tenant, so a per-company sequence would mean unbounded DDL inside a tenant DB.
- **Allocation (G-R8).** `SELECT … FOR UPDATE` alone does **not** serialize *first* use: with no row
  to lock, two transactions both see nothing and both insert. So allocation is:
  1. `INSERT INTO sku_sequences (company_id, prefix, next_value, padding) VALUES (…) ON CONFLICT DO NOTHING` — creates the row exactly once, race-free;
  2. `SELECT … FOR UPDATE` on `(company_id, prefix)` — now guaranteed to find a row;
  3. format `PREFIX + zero-padded(next_value, padding)`, increment, release at commit.
  Default `SKU-` + padding 6 → `SKU-000001`; padding **grows** past 999999 (`SKU-1000000`) and never
  wraps (**OQ-G-16**). SQLite has no row-level `FOR UPDATE` (Laravel emits nothing), so concurrency is asserted on
  **PostgreSQL only**, as in the precedent's migration test.
- **A pre-check is advisory, not a guarantee (G-R8).** A candidate is checked `withTrashed()` against
  `products` in that company, but a **manual UI create typing `SKU-000042` does not lock the sequence
  row**, so the check can always be raced by the final insert. The write therefore also **catches the
  exact `products_company_id_sku_unique` violation** — matched by the `ProductIndexNames` constant of
  §3.1b, never a substring guess, and **only** that constraint, so a category or barcode violation is
  never mislabelled (the discipline `ProductVariantService::saveBarcodeSafe()` already models at
  `:126-139`). The insert runs in a **nested transaction** so Laravel emits a SAVEPOINT and the
  23505 aborts only the attempt, not the whole row transaction. On catch: re-allocate and retry,
  bounded at **5** attempts, then fail the row with `sku_allocation_exhausted`.
- **Lock ordering (documented, G-R8).** The `sku_sequences` row lock is taken **before**
  `ProductCostLock`. `ProductCostLock` acquires `pg_advisory_xact_lock` per product in ascending id
  order inside an open transaction (`ProductCostLock.php:13-29,40-51`) and today the two never meet
  in one transaction — SKU allocation happens in the row loop, opening correction in finalize. If a
  future lane puts them under one transaction, this is the order.
- The operator is **told**: warning `sku_generated` carrying the value, surfaced in the result
  workbook and the error-line export.

**UI create path.** `CreateProductRequest` hard-requires SKU (`:177-184`), so typing none returns 422
and nothing is generated. It is relaxed to `nullable` with `ProductController::store()` calling the
**same allocator** (not a second implementation). Both `CreateProductRequest` and
`UpdateProductRequest`'s `Rule::unique('products','sku')` chains gain `->where('company_id', …)` and
**drop `->whereNull('deleted_at')`** (§3.1c) in G-3a; without the company predicate the FE gets a
false-negative "SKU available" and then hits the new company unique. **This is the exact class of
miss the precedent's gate caught** — an "all consumers verified" claim that had left
`PaymentMethodController::index()` tenant-wide
(`docs/superpowers/reviews/2026-08-28-session-e-i1-followups-gate-r1-treasury.md:27`).

**Branch imports upsert stock, never products** (RUL-2c). With `(company_id, sku)` in force, a
second-location import of the same catalogue resolves every row to the existing company product and
takes the update branch; opening stock is per `(product, location)`
(`OpeningBalancePostingService.php:90-105`), so the second location gets its own opening without
minting product rows. G-7's second-location fixture asserts exactly this: product count unchanged,
`stock_levels` rows +N.

### 4.6 Parties identity, then synthetic code (R3 defect, G-R14)

A parties row carrying any balance column with a blank `code` mints
`'IMP-'.substr($job->id,0,8).'-'.$row->row_number` (`ImportService.php:441-444`) — job-and-row
derived, so **re-uploading the same file mints a different code and creates a second partner**.

r1 proposed hashing `company + normalized_name + vat_number`. Codex is right that this is worse in a
specific, common case (G-R14): `PartnerService` matches **code-first and exclusively** — once a
non-blank code is supplied it never falls back to VAT or name (`PartnerService.php:59-70,82-86`,
pinned at `PartnerCodeUpsertTest.php:94-117`). So a partner whose **name is corrected** between two
uploads would hash to a **new** code, and the code-first match would then create a duplicate while
the unchanged VAT sat there matching nothing.

**r2 order — resolve identity first, mint a code second:**

1. **Resolve** the partner by the natural ladder `code → vat_number → name` (§4.3), `withTrashed()`.
2. If a partner is **found**, use **its existing `code`**. No synthesis, no rename, no duplicate.
3. If **not found** and the row needs a code (it carries a balance), derive a **stable** one:
   - VAT present → `IMP-` + first 12 hex of `sha256(company_id + normalized_vat)`. Stable across
     renames, which is the case that broke r1.
   - No VAT → `IMP-` + first 12 hex of `sha256(company_id + normalized_name)`, with the **stated
     limitation**: a name-only partner that is later renamed *and* re-imported creates a new partner.
     The preview surfaces `matched_by_name` so the operator can add a `code` or `tax_id` column.
4. Either way, emit warning `code_generated` carrying the value so the operator can adopt it.

A row with **neither** code, VAT nor a usable name degrades to bare `name` today
(`PartnerService.php:69`). Whether such rows should instead be refused with
`party_key_insufficient` is **OQ-G-4**; the default remains today's behaviour.

**Tests:** "same VAT, corrected name, blank source code → one partner, updated name"; "name-only
re-upload → one partner"; "balance-bearing row re-uploaded twice → one partner, one AR document".

### 4.7 Stuck jobs, cancel, and the reaper (D7, **OQ-G-9** — reaper **and** operator affordance)

**Today** a worker SIGKILL / OOM / container restart never invokes `failed()`, nothing sweeps a job
frozen in `importing`, the job becomes permanently un-executable (execute requires
`Validated|Pending`, `ImportController.php:496-506`), and the wizard polls forever with no ceiling
(`ProcessImportJob.php:51,56,131-134,308`; `ImportWizardPage.tsx:255-259,379-386`). No test covers
any of it (`ProcessImportJobStatusTest.php` has four tests, none exercising a crash).

**Reaper — two clocks, not one (G-R50).** A console command `imports:reap-stuck` on the scheduler,
**every 5 minutes**, fails a job in `importing` when **either** clock is stale:

```
(worker_started_at IS NULL     AND claimed_at        < now() - 90 minutes)   -- never reached a worker
OR
(worker_started_at IS NOT NULL AND worker_started_at < now() - 90 minutes)   -- worker died mid-run
```

One clock is not enough: a job claimed at 09:00 that sits in a backed-up queue and starts at 10:20 is
a **live** worker at 10:31, and a claim-only sweep would kill it. `claimed_at` answers "did anything
ever pick this up?"; `worker_started_at` answers "is the thing that picked it up still alive?".
Both columns come from **M6c**.

The flip is the **compare-and-set** of §4.1.1 item 5 —
`UPDATE … SET status = failed, error_code = worker_lost, error_message = … WHERE id = ? AND
status = 'importing'` — so a worker that finishes in the same instant either wins (the reaper's
update affects zero rows and it logs and moves on) or loses (its own terminal write affects zero rows
and it logs and returns). Neither can overwrite the other's terminal status.

Rationale for 90 minutes: the job `timeout` is 3600 s (`ProcessImportJob.php:56`), so 60 minutes is
the longest a live worker can legitimately hold a job, and 90 leaves a restart margin. Surfaced as
**OQ-G-17**. A reaped job is `failed` and, per the one-shot invariant (§4.1.1 item 6), is never
resumed — the operator creates a new job.

The command **must** extend `TenantScopedCommand` and iterate with `forEachTenant()`
(`apps/api/app/Console/TenantScopedCommand.php:52`): under database-per-tenant the scheduler runs on
the **central** connection where `import_jobs` does not exist — the exact failure recorded on the
fiscal auto-lock entry in `apps/api/routes/console.php`, a swallowed 42P01 that left the nightly lock
silently dead from 2026-05-28 to 2026-08-05. Registered in `routes/console.php` with `->onFailure()`
and `->withoutOverlapping()`, in-process (no `runInBackground()`), for the same documented reason.

**Cancel / discard.** A real `DELETE /api/v1/imports/{id}` on `ImportController::destroy()`, refusing
anything in `importing` (409 `IMPORT_IN_PROGRESS` — `ProcessImportJob` has no cancellation check and
adding one is out of scope), deleting the job row, its `import_rows` (existing FK cascade) and the
uploaded file under `imports/{tenantId}/`. Permission: the existing **`imports.manage`**, which
`RolesAndPermissionsSeeder.php:532` shows is the **only** `imports.*` grant in the registry, and
which already gates the whole route group (`ImportServiceProvider.php:67`) — no new permission is
minted. The dead `useDeleteImport` / `importApi.deleteJob` client (`queries.ts:163-181`,
`importApi.ts:105-107`), which would 405 today, is **wired** to this route rather than deleted.

**Elapsed time.** The wizard's execute step and the history page render "running for N min" from
`started_at`, with a Cancel affordance that calls DELETE for a not-yet-started job and, for a running
one, explains that the reaper will resolve it.

### 4.8 Error-code channel (D9, **OQ-G-10** — a typed column, not a leading-token convention)

**Today** `import_error = $e->getMessage()` verbatim on both paths (`ProcessImportJob.php:169-175`,
`ImportService.php:360-366`), rendered inline (`ValidationGrid.tsx:122-125`) and exported verbatim
(`FailedRowsExportService.php:133-135`, `ResultWorkbookService.php:126-133`). Warnings already have a
proper `{code, detail}` channel (`Domain/ImportRow.php:19`, `addRowWarning` `ImportService.php:93-98`).
That asymmetry — non-blocking warnings coded, blocking errors free text — is the core defect.

**New:** every refusal path sets `import_rows.import_error_code` from `ImportErrorCode` (rule 9) plus
an optional `import_error_detail` JSON payload (e.g. `{"sku":"ZF1161","existing_product_id":"…"}`).
The raw exception string still lands in `import_error` — **for support**, never as the operator's
message; an unmapped `\Throwable` is stored there and coded `internal_error`. The FE translates codes
through a new `import.errors.<code>` namespace with a generic fallback (`import.errors.unknown`), so a
French or Arabic operator stops receiving raw English Laravel prose (`ValidationGrid.tsx:165`,
`ImportPreviewTable.tsx:133`). Row-level **validation** errors (`import_rows.errors`,
`field => [message]`, `ImportService.php:162-166`) keep their current shape — translating Laravel's
own validator output is a separate, larger lane; the coded channel covers execution refusals, which
is where the SQLSTATE came from.

### 4.9 Opening-stock fence and correction (D1, R5, G-R9/G-R10/G-R11)

**Today** the products import never consults the predicate that already exists (`grep -n
"hasDownstream" apps/api/app/Modules/Import/` → no hits): a second opening is refused identically as
the non-blocking warning `opening_exists` whether or not operations happened
(`ProductOpeningStockPhase.php:131-132`). `InventoryService::hasDownstreamMovements()`
(`InventoryService.php:52-60`) is exactly R5's question and already fences
`ResetOpeningBalanceService::reset()` and `ProductController::postOpening()` (`:640`).

**The fence (D1) is unchanged:** reuse `hasDownstreamMovements(companyId, productId)`. Any
non-`Opening`, non-reversed `StockMovement` locks the opening — sales, purchases, returns, transfers,
counts, adjustments and write-offs are all covered by the single movement-type test (Audit A
§5.1–5.2). **Drafts, stock reservations and open counting sheets do NOT count** — none posts a stock
movement. Scope stays **product, company-wide** (**OQ-G-2 / OQ-G-3**).

#### 4.9.1 Why the existing "reset then re-post" cannot be reused (G-R9/R10/R11)

Three verified defects, which together are why G-5 builds a new service rather than calling
`ResetOpeningBalanceService::reset()`:

1. **Wrong location.** `reset(companyId, tenantId, productId, userId)` takes **no location** (`:61`);
   it loads the active opening product-wide with `firstOrFail()` (`:83-91`) and zeroes **that**
   movement's location's stock level (`:129-138`). On the multi-location shape
   `ProductsImportPipelineTest.php:470` pins, correcting ANNEX can reverse MAIN.
2. **No atomic coordinator.** Reset owns its own `DB::transaction` (`:68`) and the poster owns
   another (`OpeningBalancePostingService.php:59-81`), while finalize turns any throwable into the
   `opening_failed` **warning** (`ProductOpeningStockPhase.php:88-135`) — so a committed reset
   followed by a failed re-post leaves a reversed movement, a zeroed stock level, a contra JE and
   cleared cost fields, reported as a *warning*. (SAVEPOINTs are not the problem; the missing piece
   is the coordinator.)
3. **Batch stock desynchronises.** Reset never touches `batches`/`batch_stocks` (`:83-198`), and the
   poster's lot path **only tops up** — `if (bccomp($delta,'0',4) > 0)`
   (`BatchStockService.php:145-149`), whose remainder helper documents that shrinking is "a ledger
   correction … never a silent side effect" (`:241-251`). Correcting 10 → 5 would leave
   `stock_levels.quantity = 5` and the DEFAULT `BatchStock` at 10; expiry is **set-once**
   (`:127-131`), so a corrected date could not be applied either.

#### 4.9.2 The replacement: one coordinator-owned transaction (G-R28 + G-R48)

An Inventory-owned `correct()` cannot commit the Import row's outcome with the correction, so a crash
between the two leaves the row lying. The **Import coordinator owns the outer
transaction**, because it is the only party that can commit the correction and the row truth
together — and **Import never touches an Inventory domain class** (rule 6), so the cost lock is
acquired *inside* the public Inventory seam, not around it:

```
ProductOpeningStockPhase::correctRow(row, payload)            ← Import module, OWNS the transaction
└── DB::transaction(fn () =>
    ├── $result = OpeningCorrectionService::correctInCurrentTransaction(…)  ← Inventory, PUBLIC seam
    │     ├── assert DB::transactionLevel() > 0               (refuses to run unwrapped)
    │     └── ProductCostLock::acquire(tenant, company, [productId], fn () =>   ← INSIDE the seam
    │           ├── 1. FENCE     hasDownstreamMovements + the reservation precondition (§4.9.2a)
    │           ├── 2. REVERSE   reverseAtLocation(product, location)
    │           │                + BatchStockService::reduceOpeningLot(…)  ← reconcile the lots
    │           ├── 3. REPOST    OpeningBalancePostingService::post(…)
    │           │                (its one-line cost stamp is NOT the final write)
    │           └── 4. RECOMPUTE products.cost_price = qty-weighted WAC across ALL
    │                            remaining active openings of the product (§4.9.2b)
    │        )  → returns OpeningCorrectionResult (DTO: new qty, new cost, lots touched, JE id)
    └── row->update(outcome: imported, warning: opening_corrected, _results)  ← Import writes row truth
)
```

**The order 1 → 2 → 3 → 4 is normative and is stated only here.** Recompute is **step 4, after the
repost**, because the poster unconditionally stamps that one line's unit cost
(`OpeningBalancePostingService.php:219-226`) and on a multi-location product that value is wrong for
the product as a whole; the WAC recompute is the final write. G-5 pins the order with a test that
**fails if the poster's one-line cost is what `products.cost_price` ends up holding**.

`correctInCurrentTransaction()` is a **public service method** — the sanctioned cross-module seam
(rule 6: "Cross-module communication only via `Shared/Contracts/` interfaces, Events, or a module's
public Service class"). It takes no callback, acquires `ProductCostLock` itself, and returns an
`OpeningCorrectionResult` DTO; it **asserts `DB::transactionLevel() > 0`** and throws if called
unwrapped, so it can never be used as a standalone committing operation by mistake. Laravel's nested
transactions become SAVEPOINTs, so the inner services' own `DB::transaction` calls join the outer one
rather than committing early (`ResetOpeningBalanceService.php:68`,
`OpeningBalancePostingService.php:59-81`). `ProductCostLock` is a `pgsql`-only advisory lock and a
documented no-op elsewhere (`ProductCostLock.php:42-49`), so the SQLite test path is unaffected.

Any failure rolls the **whole** correction back — reversal, batch reconciliation, contra JE, repost,
cost recompute and the row outcome together — and the row is then written `failed` with
`opening_correction_failed` in the separate post-rollback statement of §4.2.4.

##### 4.9.2a Active reservations are a hard precondition (G-R29)

r2 ruled that a stock reservation does not fence a correction. That is unsafe: a correction drives
`stock_levels.quantity` **down** and may remove a lot, while
`inventory_batch_stock.available_quantity` is a **generated column** `quantity - reserved_quantity`
(`2026_01_05_150001_create_inventory_batch_stock_table.php:25-31`). A 10 → 5 correction with 8
reserved yields negative availability, or deletes lot stock whose reservation is still live.
Reservations sit on the **lot OR the aggregate, never both** — `StockReservationService.php:113-116`
documents that asymmetry and the oversell incidents it caused — so both must be checked.

**The reservation rule, stated once (every other section and lane brief references it, none
restates it):** a correction is **allowed only if the corrected aggregate AND every affected lot
remain at or above the quantity held against them** — `stock_levels.quantity >=` the aggregate
`reserved_quantity`, and each affected lot's quantity `>=` that lot's
`inventory_batch_stock.reserved_quantity`. Otherwise the row is refused with
**`opening_locked_reserved`** (outcome `opening_locked`), whose detail lists the held quantity and
where it is held and whose operator-facing message is, per RUL-5, **"release the reservation or use a
stock adjustment"**. Checked inside the transaction, under the same lock, **before any write** — i.e.
step 1 of the order above. Where a lot's column and the sum of its active reservations disagree, the
**larger** is authoritative, matching `StockReservationService`'s own rule.

**Invariants asserted after every correction**, at both levels: `0 <= reserved_quantity <= quantity`,
and `sum(batch_stocks at location) == stock_levels.quantity`.

This is an integrity constraint about not deleting stock somebody is holding — **not** a redefinition
of "operations". Drafts and open counting sheets still do not fence a correction (**OQ-G-3**, whose
default this amends and which does not restate the rule).

##### 4.9.2b `products.cost_price` after a correction (G-R28)

Today the two halves disagree: the reversal **clears** `cost_price` to `'0'`
(`ResetOpeningBalanceService.php:194-198`) and the re-post **stamps that one line's unit cost**
unconditionally (`OpeningBalancePostingService.php:219-226`). On a multi-location product, correcting
ANNEX would therefore overwrite the product's cost with ANNEX's — "last corrected location wins",
which is wrong whenever MAIN still holds an active opening.

**r3 formula.** After the re-post, `products.cost_price` is recomputed as the **quantity-weighted
average unit cost across ALL remaining active openings of that product**, company-wide:

```
cost_price = Σ(qty_i × unit_cost_i) / Σ(qty_i)      over every active, non-reversed Opening
                                                      movement of the product in the company
```

computed with `bcmath` at **monetary scale + 4** for the intermediates and written back through
`CurrencyScale::bcformatStrict($value, $scale)` at the company currency's scale (rule 19). When
`Σ(qty_i)` is zero — every opening reversed and none re-posted — `cost_price` is cleared to `'0'` and
`cost_updated_at` to NULL, matching today's reset semantics for a genuinely cost-less product.

Tests: **MAIN remains + ANNEX corrected** (cost is the weighted blend, not ANNEX's);
**single-location correction** (cost is that location's new unit cost); **correction to zero at the
only location** (cost cleared).

#### 4.9.3 Behaviour on an already-opened product

```
row has quantity > 0, product already has an active opening AT THIS LOCATION
        │
        ├── duplicate_policy = skip   ──────────> warning `opening_skipped_existing`   (no write)
        │
        └── duplicate_policy = override
                 │
                 ├── fence passes (no downstream movements AND the corrected
                 │   aggregate and every lot stay >= what is held) ──> correction (§4.9.2)
                 │                                        outcome `imported`
                 │                                        + warning `opening_corrected`
                 │
                 ├── hasDownstreamMovements = true  ──> ROW ERROR, outcome `opening_locked`
                 │                                       code `opening_locked_has_operations`
                 │                                       "operations exist for this product;
                 │                                        correct the stock with a stock adjustment"
                 │
                 └── a reservation would be broken   ──> ROW ERROR, outcome `opening_locked`
                                                         code `opening_locked_reserved` (§4.9.2a)
```

An opening at a **different** location is not a correction at all — it is a first opening for that
location and posts normally (§4.2.1). **Never silent** in any branch.

#### 4.9.4 Invariants asserted by G-5's tests

- `sum(batch_stocks at that location) == stock_levels.quantity` after a **lower**, **higher** and
  **equal** quantity correction.
- **GL net effect == new value − old value** across the contra JE and the re-post.
- **Fault injection**: throw after the reversal and prove `stock_movements`, `stock_levels`,
  `journal_entries`, `batches`/`batch_stocks` and `products.cost_price` are all unchanged. A second
  injection throws **after** the correction's inner work but **before** the row outcome write, proving
  the coordinator's single transaction rolls both back together (G-R28).
- **The cost order is pinned:** on a two-location product, after correcting ANNEX,
  `products.cost_price` equals the **quantity-weighted blend of MAIN and ANNEX**, so the test fails if
  the poster's single-line stamp (`OpeningBalancePostingService.php:219-226`) is the final write.
- `correctInCurrentTransaction()` called **without** an open transaction throws rather than running.
- Import contains **no reference to any Inventory domain class**: `grep -rEn 'ProductCostLock|Inventory.Domain' app/Modules/Import` returns nothing (rule 6).
- A two-location product corrected at ANNEX leaves **MAIN's** opening movement and stock untouched.
- `ProductsImportPipelineTest.php:470` (MAIN + ANNEX + THIRD, set-once expiry) stays green.

Precision: the correction posts through `OpeningBalancePostingService` exactly as a first opening
does — quantities as `QuantityScale` strings, costs as `CurrencyScale::bcformatStrict` at the entity
currency's scale, with the currency passed **explicitly** because the phase runs inside
`finalizeImport`, called from the queued `ProcessImportJob` where no `CompanyContext` is bound
(rule 20; `ProcessImportJob.php:186`).

### 4.10 Error-line export (D4, R1)

**Today** the CSV excludes warning-only rows (`FailedRowsExportService.php:33-39`;
`ImportRowWarningsTest.php:107-108`), takes headers from row 1 only so a column first seen in row 40
is dropped for every row (`:102-109`), carries **canonical post-mapping** keys rather than the
operator's spelling (`ImportService.php:691-717`), writes no BOM (`:57,171-186`), and — worst — is
unreachable from the wizard for any import of ≥100 rows: `failed_rows_csv_url` is populated only on
the synchronous branch (`ImportController.php:531-535` vs `:568-571`) while the completion step gates
its button on that field (`ImportWizardPage.tsx:1074-1086`).

**New `ImportRowExportService`** (replacing `FailedRowsExportService`, whose dead `cleanup()` and
`getFilePath()` go with it, `:80-92,191-200`):

- **Row selection:** `is_valid = false` **OR** `outcome = failed` **OR** the row has warnings —
  failed **and** warning rows, because the 422 `location_not_supplied` rows are precisely the lines
  the operator must fix (OQ-G-8). The warning predicate **must use the existing portable driver
  split**, not raw `jsonb_array_length` — `ImportController::countWarningRows()` already models it
  (`:681-691`: an `sqlite` branch on `whereNotNull('warnings')->where('warnings','!=','[]')`,
  `jsonb_array_length(warnings) > 0` otherwise). G-1 extracts that predicate into one reusable scope
  so the export, the history counts and the detail breakdown cannot drift apart (G-R18).
- **Columns:** the **source file's own header names**, reverse-mapped from
  `import_jobs.column_mapping` — persisted at `create_import_tables.php:25`, and exactly the
  `source → target` map the operator built, so no raw-row persistence is needed. **Unmapped source
  columns are dropped**: they were discarded at upload (`ImportController.php:165,171`) and cannot be
  recovered (**OQ-G-7**). CSV has no comment row, so the FE download affordance carries that caveat
  text.
- **The mapping must be injective for the reverse to exist (G-R18).** `applyColumnMapping()` builds
  `$mapped[$target]` in mapping order (`ImportService.php:706-711`), so two sources mapped to one
  target silently keep only the last — and no reverse mapping can then reproduce the source headers
  or values. Neither `store()` nor `updateOptions()` validates the mapping's shape today
  (`ImportController.php:87-99,120-122`). G-1 adds validation at both: `array<string,string>`, and
  **duplicate canonical targets are refused 422 `mapping_not_injective`** naming the colliding
  sources. The reverse mapping is defined only over that injective map, and an exact source-header
  round trip is pinned.
- **Appended columns:** `_status` (`error` | `warning`), `_code` (the `ImportErrorCode` or first
  warning code), `_message` (the translated operator message). Underscore-prefixed so a re-upload
  treats them as unknown-and-ignored — `validateHeaders` reports unknown columns without blocking
  (`ValidationEngine.php:130-146`; `ImportController.php:180-193` refuses only on `missing`).
- **Header union across ALL rows**, as `ResultWorkbookService::headersForRows` (`:92-110`) already
  does correctly — not row 1 only.
- **Two formats:** CSV **with a UTF-8 BOM** in the company's convention (§7), and XLSX. The BOM is
  the inverse of the CP1252 problem the parser already solves per-field
  (`SpreadsheetParserService.php:120-127`); without it Excel-on-Windows mojibakes every accented
  value on the round trip.
- **Available on async jobs.** `failed_rows_csv_url` stops being the gate: the completion step and
  the history detail page both link to the job-id route unconditionally, and the endpoint 404s only
  when there is genuinely nothing to export.

**Re-import.** The FE offers "re-upload the corrected file" from the completion/detail screen, which
navigates to the wizard with `?reimport_of=<jobId>`. `POST /imports` accepts an optional
`reimport_of` uuid, loads that job's `column_mapping`, and pre-applies it — so the operator skips the
mapping step entirely. **Chosen over header fingerprinting** because a fingerprint breaks the moment
the operator renames a column while fixing the file, which is the likeliest edit. Pinned by a
**round-trip test** (upload → fail some rows → export → re-upload the export with `reimport_of` →
those rows import), which does not exist today anywhere in `apps/api/tests/Feature/Import/`.

**Result workbook truthfulness.** The "Imported" sheet's query is `is_valid = true AND import_error
IS NULL` with **no `is_imported` check** (`ResultWorkbookService.php:22-25`), so a merely-validated
job, or a row demoted by a post-loop finalize phase, is reported as Imported — and can contradict the
job's own `successful_rows`, which does derive from `is_imported` (`ImportService.php:379-380`). Add
the `is_imported` predicate, add a third `Skipped` sheet for `duplicate_skipped`, and add a **job
header block** (filename, type, company, operator, status, started/finished, counts, options used) —
today it is two raw grids with no provenance.

### 4.11 Precision (rule 19) at the spreadsheet boundary (G-R4/G-R25/G-R47, RUL-4)

**The module is NOT rule-19 clean today**, and the two live defects are why this section exists:

- PhpSpreadsheet returns a numeric XLSX cell as a **PHP float** and the parser stringifies it —
  `elseif (is_scalar($value) …) { $rowData[] = (string) $value; }`
  (`SpreadsheetParserService.php:174-176`). `(string)` on a float uses `serialize_precision`, so
  `7.140` typed in Excel arrives as `7.14`, and a long decimal can arrive in exponential form.
- `ResultWorkbookService::formatValue(null|bool|int|float|string)` (`:136`) accepts a float on the
  money-bearing **output** path. (`is_float` at `SpreadsheetParserService.php:236,252` is the benign
  date-cell guard.)

Templates must therefore **not** write money as numeric-with-a-format, and the reader must be
mapping-aware: `parse()` takes only a path (`:24-34`), so it cannot know which columns are money and
it runs before the mapping exists. Seven pieces, all required:

**(1) `.xls` is ACCEPTED, and its exactness is enforced per cell (RUL-4).** The upload rule today is
`mimes:csv,txt,xlsx,xls` (`ImportController.php:90`) and **stays as it is** — r3 proposed dropping
`xls`; the owner ruled the opposite on 2026-08-29. `unsupported_file_format` remains as the coded 422
for a format outside that list (`.ods`, `.numbers`, …), not for `.xls`.

**(1a) The `.xls` conversion contract.** Binary BIFF carries no lexical decimal, so PhpSpreadsheet's
`Xls` reader hands PHP a **float** for every numeric cell. The rule below is the whole contract and is
the only place a float is ever touched in this program:

For a cell landing on a **money/quantity/percent-mapped** column whose PHP value is a float `$v`:

```
$s = sprintf('%.15g', $v);                  // shortest faithful decimal, never (string)$v
                                            // ((string) uses serialize_precision → 17 digits/exponent)
accept  iff  preg_match($ceiling, $s)       // the column's rule-19 regex: money {1,3},
                                            // quantity {1,4}, percent {1,2} — §7.3a
       AND   (float) $s === $v;             // the decimal round-trips to the SAME double
```

Both conditions are required and each catches a different failure:

- the **ceiling** rejects a value with more decimals than the column may hold (`7.1401` on money,
  `1/3` → `0.333333333333333`) — the case that would otherwise be silently truncated at staging;
- the **round-trip** rejects a float artefact: the double produced by `0.1 + 0.2` prints as `0.3` at
  15 significant digits, but `(float) '0.3'` is a *different* double, so the decimal is not a faithful
  representation of what the file holds. A genuine `7.14` round-trips exactly and passes.

A cell failing either condition is a **row error `xls_inexact_value`**, detail
`{"column": "<source header>", "raw": sprintf('%.17g', $v), "remedy": "re-save the file as .xlsx"}`.
**`bcformat`/`bcformatStrict` is NEVER called on a float** (rule 19) — the float is converted to a
candidate decimal string by `sprintf` and then either accepted as a string or refused. Everything
downstream of the reader is a string, exactly as for CSV/XLSX, so the no-float guard of §4.11(6)
covers `.xls` unchanged.

Non-numeric `.xls` cells follow the same semantics as the XLSX table below where they apply: a
date-styled numeric on a money column is `numeric_cell_is_date`; a genuine date column still converts
through the existing narrow path; a formula on a money column is `formula_not_allowed` (the reader's
cached value is not trusted, exactly as for XLSX).

**(2) Two-pass parsing.** Pass 1 reads **headers only** and feeds the mapping step — it needs no type
knowledge. Pass 2 runs **after the mapping is validated and saved** and is the typed read: it
receives the `ImportType` **and** the injective `ColumnMappingData` (§4.10), so it knows exactly which
*source* columns land on money/quantity targets. `parse(string $filePath)` therefore splits into
`parseHeaders(string $filePath)` and `parseRows(string $filePath, ImportType $type,
ColumnMappingData $mapping)`. This is also what makes G-R25's "mapping before parse" objection go
away rather than being worked around.

**(3) Sheet selection = the workbook's own active tab.** Read `xl/workbook.xml` `<sheets>` for the
ordered sheet list with each `name`, `sheetId`, `state` and `r:id`, resolve `r:id` through
`xl/_rels/workbook.xml.rels` to the actual part path, and select:

1. the sheet at the index in `<bookViews><workbookView activeTab="N"/></bookViews>` — **if that sheet
   exists and its `state` is not `hidden`/`veryHidden`**;
2. otherwise the **first visible** sheet in document order.

A workbook whose sheets are all hidden is a coded row-zero error. **There is no wizard-recorded sheet
choice** — r3 invented one with no field, no API and no owner; the idea is deleted. The active tab is
the sheet the operator was looking at when they saved, which is both what PhpSpreadsheet's
`getActiveSheet()` approximates today and what the operator means.

**(4) Cell-kind contract** — exhaustive, so nothing is left to the reader's discretion:

| Cell shape | Money/quantity-mapped column | Any other column |
|---|---|---|
| numeric `<v>`, **no `t` attribute** | **exact lexical string of `<v>`, verbatim** — never through PHP float | same lexical string |
| **`t="n"` (numeric, written explicitly)** | identical to the no-`t` case — **the exact lexical `<v>`**. Excel omits `t` for numbers but other writers emit `t="n"`, and a reader that only handles the omitted form silently drops those cells | same lexical string |
| `t="s"` (shared string) | index into `xl/sharedStrings.xml` → **the concatenation of every `<t>` in that `<si>`, in document order** (a styled cell is stored as multiple `<r>` runs; taking the first `<t>` truncates `"12"`+`"3"` to `"12"`) | same |
| `t="inlineStr"` | the inline `<is>` text, **aggregated over its `<r>` runs the same way** | same |
| `t="str"` (formula cached string) | see formula row below | the cached text |
| `t="b"` | `'1'` / `'0'` | same |
| `t="e"` (error, `#REF!`…) | row error `formula_not_allowed` | the literal error text |
| missing cell / empty `<v>` | `''` (sparse rows are normal — cells are addressed by `r`, not position) | `''` |
| **date-styled numeric** (the cell's `s` style resolves to a date `numFmt`) | **row error `numeric_cell_is_date`** — a date where money is expected is a mapping mistake, not a value | serial → `Y-m-d`, preserving today's narrow, tested behaviour (`SpreadsheetParserService.php:230-261`) |
| **formula cell** (`<f>` present) | **row error `formula_not_allowed`** — the cached `<v>` may be stale, and today a formula is refused anyway, so accepting a cache would be a silent *loosening* | cached `<v>` accepted |

**(5) Templates bind text explicitly.** PhpSpreadsheet's `DefaultValueBinder` classifies an ordinary
decimal string as `TYPE_NUMERIC` (`DefaultValueBinder.php:107-123`), so `setCellValue('29.990')`
would store a number and reintroduce the float. Every money/quantity template cell uses
**`setCellValueExplicit($value, DataType::TYPE_STRING)`** — mandatory, not stylistic.

**(6) Everything else stays string-typed end to end**, and the guard is made **substitutable**.
`NumericFieldNormalizer` returns strings and already skips non-strings (`:31-33`), so a float arriving
there is silently *ignored* rather than loudly rejected — that is exactly what a boundary test must
catch. But the class is `final` and `ImportService` is handed the **concrete type**
(`ImportServiceProvider.php:37-52`), so no double can be substituted and r3's advertised guard test
was not implementable (G-R47).

**Fix, owned by G-8:** introduce `App\Modules\Import\Application\Contracts\NumericFieldNormalizerInterface`
— the module-internal `Application/Contracts` placement every sibling module already uses
(`app/Modules/Inventory/Application/Contracts`, `…/Product/Application/Contracts`, …); it is **not**
`App\Shared\Contracts`, which is reserved for cross-module seams (rule 6). The existing final class
implements it unchanged; `ImportServiceProvider` binds interface → class; `ImportService`
constructor-injects the **interface** (rule 13). The guard test then binds a double that **throws on
`is_float($value)`** for every value it receives, so a float reaching normalization fails loudly
instead of being skipped.

Everything downstream is already lexical: the duplicate census compares identifiers; the coalescing
merger chooses between two strings; the opening correction posts via `QuantityScale` /
`CurrencyScale::bcformatStrict` with an explicit currency (rule 20);
`ResultWorkbookService::formatValue()` drops `float` from its union (`:136`, **G-8 only**).

**Tests (G-8) — one test per row, so nothing in the contract above is untested:**

| # | Case | Assertion |
|---|---|---|
| 1 | numeric `<v>` with no `t` on a money column | persists as the **exact** lexical string (`7.140`) |
| 2 | `t="n"` on a money column | same exact lexical string; the cell is **not** dropped |
| 3 | `t="s"` shared string, single `<t>` | the text |
| 4 | `t="s"` shared string, **multiple `<r>` runs** | the **concatenation**, not the first run |
| 5 | `t="inlineStr"`, multiple runs | same |
| 6 | `t="str"` (formula cached string) on a non-money column | the cached text is accepted |
| 7 | `t="b"` | `'1'` / `'0'` |
| 8 | `t="e"` on a money column | row error `formula_not_allowed`; on another column, the literal error text |
| 9 | missing cell / empty `<v>` — **sparse row** missing column C | `''`, and the row's other columns keep their `r`-addressed positions |
| 10 | **date-styled numeric** on a money column | row error `numeric_cell_is_date` |
| 11 | date-styled numeric on a genuine date column | still converts to `Y-m-d` via the existing narrow path (`SpreadsheetParserService.php:230-261`) |
| 12 | **formula cell** (`<f>` present) on a money column | row error `formula_not_allowed` — the cached `<v>` is never trusted |
| 13 | multi-sheet workbook, `activeTab` = **sheet 2** | sheet 2 is read, not sheet 1 |
| 14 | multi-sheet workbook, `activeTab` points at a **hidden** sheet | the first **visible** sheet is read |
| 15 | multi-sheet workbook, **all sheets hidden** | coded row-zero error |
| 16a | `.xls` money cells holding in-scale values (`7.14`, `4.0000`, integers) | **import**, persisting the exact decimal strings (RUL-4) |
| 16b | `.xls` money cell holding a `0.1 + 0.2`-style float artefact | row error `xls_inexact_value` with the `re-save as .xlsx` remedy — the round-trip condition |
| 16c | `.xls` money cell holding `1/3` (non-terminating) | row error `xls_inexact_value` — the ceiling condition |
| 16d | `.xls` date cell | still converts via the existing narrow date path |
| 16e | an `.ods` upload | refused 422 `unsupported_file_format` (the code survives RUL-4; `.xls` no longer uses it) |
| 17 | `7.140` / `4.0000` from a **European-convention** and a **US-convention** workbook | survive parse → normalize → persist as exactly those strings |
| 18 | **the no-float guard** | a `NumericFieldNormalizerInterface` double that throws on `is_float` is never triggered across any case above |

### 4.12 Point-of-use sign and perspective guidance (R10)

Signs are where a migration silently goes wrong: a negative customer balance is not "a negative
sale", and a bank statement's "credit" is the *bank's* books, not yours. R10 moves that knowledge out
of the guide and into the screen the operator is looking at. Three surfaces, one source of truth.

**The surfaces R10 names are NOT all import types (G-R31).** `open_items` and GL openings are **not**
`ImportType` cases (`ImportType.php:7-43`) — they run through a **separate Opening Balance wizard**
with its own batch APIs and services. r2 pointed G-11 at the wrong place. The real extension points,
all of which already compute the authoritative interpretation:

| R10 surface | Backend authority (existing) | FE surface (existing) |
|---|---|---|
| Parties balances | `PartiesRowMapper::toBalancePayloads()` → `balancePayload()` (`:79-113`) — the **same** call posting uses | import wizard `ImportPreviewTable.tsx` |
| AR/AP open items | `ArApOpeningService::getPostPreview()` (`:431`), already returning validated `document_type` and non-negative total/open amounts | `apps/web/src/features/opening-balances/components/BatchPreview.tsx`, `pages/OpeningBalanceWizardPage.tsx` |
| GL openings | `AccountingOpeningService::getPostPreview()` (`:836`), already returning validated debit/credit/account per line | same two files |

**No second sign implementation.** For Parties the preview **formats the payload the mapper returns**
— it does not re-derive the sign. If a shared pure helper is ever wanted, both the mapper and the
preview must delegate to it; a parallel function is forbidden. For open items and GL the preview
echoes the **already-validated** fields from the two `getPostPreview()` methods.

**`HistoricalOpeningSideReader` is used in exactly one place: a parity test.** It reads AR-vs-AP side
from a **persisted** `OpeningBalanceImportRow` (`:30-44`) and is not a sign oracle — it cannot know
invoice-vs-credit-note. The test pins the chain **payload → staged row → posted document type and
magnitude**, so the echo the operator approved is provably what posted. (Recorded in r2 as G-R10a;
G-R31 confirms and narrows it.)

**Source of truth (G-R33) — one tracked catalog, not two prose copies.** Synchronising two manually
maintained files by test is not a single source; a **tracked copy catalog** is:

- `docs/guides/legacy-migration-conventions.copy.yaml` — stable enum-backed keys (e.g.
  `parties.sign.explainer`, `gl.bank_inversion`, `open_items.non_negative`) → `{en, fr}` sentences.
  **This file is the single source.** Sentences are **literal**: no interpolation, no placeholders,
  no pluralization — stated in the file's own header so a contributor cannot add one silently;
  punctuation is part of the value and values are single-line block scalars.
- **Backend** reads it through a constructor-injected `ConventionCopy` (rule 13) for template hint
  rows and XLSX cell comments, resolving locale as **request → company country `default_locale` →
  `en`**.
- **Frontend** does not read YAML at runtime: `pnpm i18n:conventions` **generates** the
  `import.conventions.*` / `openingBalances.conventions.*` blocks in `locales/{en,fr}/*.json`, and CI
  fails if the committed output differs.
- **The guide keeps its prose** (`§3`/`§3b`), a parity test asserts each key's **EN sentence appears
  verbatim** in it (containment against fixed strings, not prose parsing), and the guide gains a
  pointer to the YAML as the authority.

**(a) Explainer + per-column hints.** `ImportType` (and, for the opening-balance wizard, the batch
type) exposes an `explainerKey` and a per-column `hintKey` map — **enum-backed keys resolved through
the §4.12 catalog, never literal strings on the FE** (rule 9 + rule 11). The FE renders the explainer
above the mapping/options step and the hint beside each column; the hint surface is the
`ColumnMapper` `description` rendering, the W4-1 residual confirmed unbuilt (`ColumnMapper.tsx:10`
types it, nothing reads it).

| Surface | Explainer (catalog keys, wording from the guide) | Notable column hints |
|---|---|---|
| parties (customers/suppliers presets) | balances are **signed** and written **from YOUR company's point of view**; the sign selects invoice vs credit note, never the account; zero is skipped | `opening_balance*`: "+ = the client owes you → invoice; − = you owe the client → credit note for the absolute amount" |
| AR/AP open items | amounts are **≥ 0**; direction comes from `document_type` (`invoice` \| `credit_note`); a negative amount is a row error **by design** | `amount`, `document_type` |
| GL openings | **no signs at all** — two columns, `debit` and `credit`, both **≥ 0**; you state the side | the bank inversion verbatim: "your statement says 'credit' when you have money — that is the bank's books; here money in bank goes in the **DEBIT** column"; plus "bank has money → debit `512`", "overdrawn → credit `512`", "cash float → debit `53x`" |

**(b) The preview echoes the interpretation per row**, computed by the authorities in the table above
— never a second implementation. Parties: `"−300.000 → customer credit note, 300.000 on 411"`. Open
items: the validated `document_type` echoed, and a negative amount echoed as the row error it is.
GL: `"5 000.000 in DEBIT of 512 → bank has money"`.

**(c) Templates carry the hints — with an exact sentinel, not a `#` prefix rule (G-R32).** r2 said
the parser should "skip any row whose first cell begins with `#`". That is a **data-loss** rule: the
parser treats every non-empty record after the header as data (`SpreadsheetParserService.php:67-100`),
and a legitimate `#SKU-1`, an account code written `#411`, or an operator's note can begin with `#`
at any row. r3 uses one exact, versioned, positional marker:

- **CSV:** the hint row is **physical row 2** (immediately after the header), and its **column 1 is
  exactly `#__AUTOERP_HINT_V1__`**. The parser skips **only** a row meeting *both* conditions —
  position 2 **and** that exact literal. Nothing else is ever skipped, at any position, whatever it
  starts with.
- **XLSX:** header **cell comments**, which need no sentinel and no parser rule at all (the installed
  writer supports them).

Tests: an untouched downloaded template round-trips with the hint row skipped and no phantom row;
a data row whose first cell is `#SKU-1` **is imported**; an account code `#411` at row 5 **is
imported**; a row containing the literal sentinel at position 7 **is imported** (position is part of
the rule).

**(d) i18n EN/FR now; AR deferred** per B-7. G-11 asserts EN/FR key parity; AR falls back to EN as
the rest of the namespace does until B-7 lands.

### 4.13 Units (R11)

#### 4.13.1 What happens today — verified, and worse than "a wrong spelling errors"

The `unit` column is optional and validated **only** as a string (`ImportType.php:125,215` —
`['nullable','string','max:50']`); the importer writes the cell **verbatim** into the legacy free-text
column (`ProductService.php:73`); and **`products.unit_id` is never written by the import or by
`ProductService` at all** (`grep -n "unit_id"` over both → no hits, although the FK exists,
`2026_01_09_095108_add_unit_id_to_products_table.php:15`, beside the legacy `products.unit` string(50),
`2025_11_30_052910_create_products_table.php:26`).

So a misspelled unit is **silently accepted** — and the defect is worse than R11 assumed: there is no
unit *resolution* to spell wrongly. `"Kilogrammes"`, `"KG "`, `"kgs"` and `"kg"` land as four
different free-text values, `unit_id` stays NULL, and every quantity the operator later sees falls
back to a default precision instead of the unit's own `decimal_places` — the very signal
`QuantityScale::formatForUnit` and the web `getQuantityDecimals` exist to carry (rule 19's display
half).

#### 4.13.2 The unit catalogue — verified facts (research doc §1, §3.5, §4)

| Fact | Evidence |
|---|---|
| The table is `units (tenant_id, category_id, code, name, symbol, conversion_factor, decimal_places, is_base_unit, is_system, is_active)` with `unique(['tenant_id','code'])`, **no `company_id`, no soft deletes** — "delete" is `is_active = false` | `2026_01_09_095045_create_units_table.php:14-39`; `UomController.php:247` |
| Every **seeded** row is written with `tenant_id = NULL`, so the 19 system units are **tenant-database-global** and visible to every company of the tenant | `UomSeeder.php:16-259` (never sets `tenant_id`) |
| **Operator-authored** units ARE tenant-stamped (`tenant_id = <tenant>`, `is_system = false`) | `UomController.php:131,140` |
| The read path is therefore a **union**: `tenant_id IS NULL OR tenant_id = <tenant>` | `UomController.php:40-46,72-79` |
| `units` never got the partial-unique repair `unit_categories` got, so **two `tenant_id = NULL` rows with the same code are legal in PostgreSQL** | `2026_04_28_120000_fix_unit_categories_partial_unique.php:67-77` fixed only `unit_categories`; research §1.4 |
| **Live ambiguity, not hypothetical:** demo tenants hold lowercase **system** `kg`/`l`/`hr` *and* uppercase **tenant** `KG`/`L`/`HR` in different categories | `UomSeeder.php:52,113,249` vs `DemoTenantSeeder.php:328-420`; research §3.5 |
| Seeded set: **19 units in 5 categories** — weight `g`(base)/`mg`/`kg`/`oz`/`lb`, volume `ml`(base)/`cl`/`l`/`floz`, length `mm`(base)/`cm`/`m`/`in`, pieces `pc`(base)/`pair`/`doz`, time `min`(base)/`hr`/`day` | `UomSeeder.php:24-259` |
| Provisioned per **tenant** at registration, and backfilled for older tenants | `TenantInitializationService.php:238,259-265`; `2026_08_26_100000_seed_base_units_for_unit_less_tenants.php` |
| **There is no company default unit** to fall back to: `grep -rniE "default_unit\|defaultUnit"` over Company/Uom/Product returns nothing, and there are **five** category base units, not one | research §0, §3.4 |

**The list is dynamic, never hardcoded.** Units are user-editable —
`POST`/`PUT`/`DELETE /api/v1/uom/units` (`app/Modules/Uom/Presentation/routes.php:17-19`) — so a
tenant may add `sachet`, `flacon`, `boîte`. Every surface below reads the **live** list at request
time; a static list in a template, a hint or a test fixture is a defect.

#### 4.13.2a Unit scope — RULED (RUL-7): shared tenant defaults + per-company additions

The owner ruled option **(b)** of the research doc. It lands in **two steps**, and the split is what
keeps the hardening wave independent of a schema change:

| | **Now** (Waves 0–2: G-12 + G-4, today's schema) | **After G-13** (Wave 3, the re-scope) |
|---|---|---|
| Schema | `units` unchanged — **no `company_id` column exists** | `units.company_id` uuid **nullable**; `unique(tenant_id, code) WHERE company_id IS NULL` and `unique(company_id, code) WHERE company_id IS NOT NULL`, on the proven `2026_04_28_120000_fix_unit_categories_partial_unique.php:67-77` idiom — which also repairs the §4.13.2 NULL-distinct gap for the shared partition |
| **Visibility for company C** | `is_active = true AND (tenant_id IS NULL OR tenant_id = <tenant>)` — every visible row is a **shared** row, because per-company rows cannot exist yet | `is_active = true AND (company_id IS NULL OR company_id = C)` |
| **Shadowing** | not expressible | **LEGAL, and the company row wins.** A company may define its own `kg` beside the shared `kg`; the resolver returns the company row and pickers show **one** entry |
| Provisioning target (G-12) | the tenant's visible set (guaranteed at registration, `TenantInitializationService.php:238,259-265`) | unchanged — shared rows keep serving every company; `CompanyController::store`'s call stays a self-guarding no-op unless the company owns rows |
| Backfill / FK re-pointing | none | **none** — existing rows keep `company_id = NULL` and every existing `unit_id` stays valid (research §5(b)) |

**Both columns of that table are served by one class:** `UnitCatalogQuery` (G-12,
§9.2) is the sole home of the visibility predicate **and of the shadowing tie-break**, so G-13 changes
one file and every consumer — resolver, template list, ColumnMapper hint, export message, UoM pickers
— inherits the new scope without edits. Nothing else in §4.13 changes with the step: the resolver
algorithm, the ambiguity refusal, the accepted-vocabulary rule and the `units_not_seeded` invariant
are written once and hold in both columns.

#### 4.13.3 Resolution contract (owned by **G-4**) — deterministic on today's schema (G-R49)

G-4 is the lane that rewrites product row mapping — it owns `ImportService::applyColumnMapping` (the
`_provided` mask, §4.4.1) and the `ProductService::upsert` write path — so the unit resolver belongs
there.

A new `UnitResolver` (constructor-injected, rule 13) resolves the cell as follows. Each step is
scope-independent; only the word **visible** carries the substitution above.

1. `trim()` the cell. A blank cell skips to step 5.
2. Match the cell **against `code` only**, **case-insensitively**, over the rows **visible** to the
   importing company **with `is_active = true`**. Name and symbol matching is **REMOVED** (r3 accepted
   `code`/`name`/`symbol` while showing the operator codes only, so the displayed list was not the
   accepted vocabulary — §4.13.4).
3. **Apply the RUL-7 tie-break, then count. Never take an arbitrary first row.** If the matches span
   both visibility tiers — a **shared** row (`company_id IS NULL`) and the importing **company's own**
   row — the **company row wins** and the shared one is discarded before counting. `unit_ambiguous`
   therefore describes ambiguity **within one tier** (two shared rows `kg` and `KG` under a
   case-insensitive match, which is the live demo-tenant shape), never the legal shadowing case.
   Until G-13 lands there is only one tier, so the tie-break is a no-op and the rule is already
   correct today.
   - **1 match** (after the tie-break) → resolve: write **both** `unit_id` (the FK the import has
     never populated) and the legacy `unit` string set to that unit's canonical `code`, so the stored
     value is normalised rather than whatever the operator typed.
   - **0 matches** → coded ROW ERROR `unit_unknown`. Detail: `{"supplied": "<cell>", "accepted":
     ["kg","g",…]}`; the operator-facing message is the "enter exactly as spelled" instruction plus
     the full comma-separated list of accepted **codes**.
   - **more than 1 match** → coded ROW ERROR **`unit_ambiguous`**. Detail lists the matching rows
     (`id`, `code`, `name`, category, and whether the row is system or tenant-authored) so the
     operator can see *why* it is ambiguous. This is not hypothetical: a case-insensitive `"kg"` on a
     demo tenant matches the system `kg` and the tenant `KG` (§4.13.2). **Never resolve to an
     arbitrary row** — `ProductController::resolveUnitId` already refuses this way
     (`ProductController.php:1226`, `count() === 1 ? … : null`), except that it refuses *silently*;
     the import refuses *loudly*.
4. Never a silent default, never a free-text passthrough: every non-blank cell ends at exactly one of
   the three outcomes above.
5. **Blank cell (RUL-7).** On **update**, keep the product's existing unit — §4.4's coalescing rule,
   and **no warning**, because nothing changed. On **create**, default to **`pc`** (Piece, the
   `pieces` category base unit — verified seeded at `UomSeeder.php:192-193`) and emit warning
   `unit_defaulted` so the result report says plainly that the file stated no unit. The default must
   resolve through the **same visibility predicate** as any other value; if `pc` is not visible to the
   company the row is refused with row error **`unit_default_missing`** — the row-level sibling of
   `units_not_seeded`, and the reason G-12's provisioning guarantee is a hard dependency of G-4.

**Job-level: the catalogue must not be empty (owner invariant, R11 refinement).** A new job-level
`ImportErrorCode::units_not_seeded` is checked **twice**:

- at **upload**, in `ImportController::store`, before the file is parsed or queued — the same place a
  missing-header set is already refused (`:183-188`). The upload returns **422** with the code, so the
  operator is told the configuration problem instead of watching every row fail;
- **re-verified by the worker** before the row loop (`ProcessImportJob`, alongside its existing
  `failJob($job, 'Company not found')` shape at `:96-107`), because the set can be emptied between
  upload and run. The worker path stamps `import_jobs.error_code` (M6c) and fails the job.

It is **job-level, not row-level**: an empty catalogue is a configuration fault, not a data fault.
The provisioning guarantee, the brownfield census (M8) and both checks are owned by **lane G-12**
(§9), which lands before G-4 — so the resolver never runs against an empty set without a coded
refusal ahead of it.

**Prior art, rejected deliberately.** A ~90-entry EN+FR alias map exists —
`kilogram|kilogramme|kilo → kg`, `litre|liters → l`, `pièce|pièces|unité|unités → pc`,
`douzaine → doz`, `pouce → in`, `heures → hr` — in
`2026_01_09_100552_map_product_units_to_uom_ids.php:20-108`, applied `strtolower(trim(...))` as a
**one-off backfill** that logged its unmapped values (`:141-165`). R11's instruction ("exactly as
spelled") is the **opposite** policy, so the default is **not** to reuse it: an alias that silently
converts `Kilogrammes` is precisely the misconception the owner wants removed. **OQ-G-22(iii)** carries
the alternative.

**Already-imported products keep their NULL `unit_id`** unless the owner rules otherwise — a backfill
that re-reads the legacy free-text `products.unit` and resolves it through this same resolver is
**OQ-G-26** (default **yes**, implemented in G-4 as a guarded, logged, forward-only pass that touches
only rows where `unit_id IS NULL AND unit IS NOT NULL` and resolves to exactly one visible active
code; everything else is left alone and counted).

#### 4.13.4 Where the accepted vocabulary is surfaced

**The list shown anywhere is EXACTLY the set the resolver accepts** — the live, visible, active
**codes**, and nothing else. That equality is the point of removing name/symbol matching in step 2:
an operator who types a value from the list can never be refused, and a value not on the list is
always refused.

| Surface | Lane | Behaviour |
|---|---|---|
| Template hint row | **G-8** | the `unit` hint is `"Enter the unit EXACTLY as spelled: "` + the comma-separated live codes, generated at download time |
| XLSX template | **G-8** | plus a **data-validation dropdown** of those codes |
| ColumnMapper hint + preview echo | **G-11** | same instruction and list; the preview echoes the **resolved** unit per row and flags `unit_unknown` / `unit_ambiguous` rows with the list |
| Error-line export | **G-1** | `unit_unknown` and `unit_ambiguous` rows carry the accepted list in `_message` |

A test in each of those three lanes asserts the surfaced list is **identical** to what the resolver
accepts for the same company (same query, same order), so the two can never drift.

**XLSX dropdown mechanics (verified).** `PhpSpreadsheet\Cell\DataValidation::TYPE_LIST` exists in
the vendored version (`vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Cell/DataValidation.php:12`)
and the `Xlsx` writer is already in use (`ResultWorkbookService.php:11,49`). Excel caps an **inline**
list formula at **255 characters**, which 19 seeded codes fit comfortably but a tenant with custom
units may not. So: build the comma-separated inline list; if it exceeds 255 characters, write the
codes to a hidden **`Units`** sheet and point `setFormula1('Units!$A$1:$A$n')` at it instead. Both
branches are tested.


### 4.14 Retention and purge (G-R41; **RULED by RUL-6** — OQ-G-18 window, OQ-G-23 cadence)

r2 decided 90-day retention but gave it no implementation, while the ProductImages worker deletes its
ZIP **immediately** after processing (`ProcessProductImageImport.php:144-148`) — so the policy and
the code disagreed. r3 makes the transition owned:

**`imports:purge-expired`** — a tenant-scoped console command (`TenantScopedCommand::forEachTenant()`,
same reason as the reaper), scheduled **daily**, `withoutOverlapping()`, with `->onFailure()` logging.

| Artifact | Storage key | Clock | Action at > 90 days |
|---|---|---|---|
| uploaded source (CSV/XLSX) | `imports/{tenantId}/…` — the **relative** key already on `import_jobs.file_path` | `import_jobs.completed_at`, else `created_at` | delete the file, stamp `source_purged_at` (M6c) |
| generated exports (rows-export, result workbook) | regenerated on demand today; r3 keeps them **ephemeral** (`deleteFileAfterSend`) so there is nothing to purge — the dead `FailedRowsExportService::cleanup()`/`getFilePath()` timestamped-file path is deleted with the service (§4.10) | — | n/a by construction |
| ProductImages ZIP | `imports/{tenantId}/product-images/…` | — | **documented exception:** purged immediately after processing, as today. A ZIP of images is bulky and its content is already persisted as media; retaining it for 90 days would multiply storage for no recovery value. `source_purged_at` is stamped at processing time so the UI is honest about it |

Rules: **rows are always kept** — only files are deleted, so counts, codes and the row snapshot
survive forever. A missing file is a no-op (idempotent; a re-run purges nothing twice). Download
endpoints return **410 `source_purged`** once `source_purged_at` is set, never a 404 that reads like
a bug.

Tests: 89-day file survives / 91-day file purged; a purged job's source download returns 410; the
command is tenant-isolated (tenant A's purge leaves tenant B's files); a second run is a no-op; the
ProductImages exception is asserted explicitly rather than incidentally.

---

## 5. API contract changes

All import routes live in one group behind
`['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'can:imports.manage']`
(`ImportServiceProvider.php:67`), prefix `api/v1`. Every new route below **joins that same group** —
rule 12 is satisfied by construction, and no route is added outside it.

### 5.1 New routes

| Method | Path | Handler | Lane | Notes |
|---|---|---|---|---|
| `DELETE` | `/imports/{id}` | `ImportController::destroy` | **G-6a** | 409 `IMPORT_IN_PROGRESS` when status is `importing`; deletes job + rows + file |
| `GET` | `/imports/{id}/rows-export` | `ImportController::downloadRowsExport` | G-1 | `?format=csv\|xlsx`, default csv; failed **and** warning rows; 404 when empty |
| `GET` | `/imports/{id}/source-file` | `ImportController::downloadSourceFile` | **G-6a** | the original upload, for the detail page; **410 `source_purged`** once `source_purged_at` is set (§4.14) |
| `GET` | `/migration-wizard/template/{type}` | existing | G-8 | gains `?format=csv\|xlsx` and `?preset=customers\|suppliers` |

`GET /imports/{id}/failed-rows.csv` is **kept as an alias** of `rows-export?format=csv` for one
release so the live history-page button (`ImportHistoryPage.tsx:201-215`) never 404s mid-deploy; it
is removed in a follow-up.

### 5.2 Changed request/response shapes

| Endpoint | Change | Lane |
|---|---|---|
| `POST /imports` | accepts `reimport_of` (uuid, optional) and `options.duplicate_policy` (`in:override,skip`); **stamps `company_id` from `CompanyContext` onto the new job** and `source_hash` from the uploaded bytes | G-3b, G-4 |
| `PATCH /imports/{id}/options` | whitelist gains `options.duplicate_policy`; refuses 409 `IMPORT_COMPANY_MISMATCH` when the job's `company_id` differs from the current context | G-3b, G-4 |
| `POST /imports/{id}/execute` | refuses 409 `IMPORT_COMPANY_MISMATCH` on a company switch; claims the job atomically (§4.1) and returns 409 `IMPORT_ALREADY_STARTED` to the loser. **Correction (G-R16):** `ProcessImportJob` **already** receives `companyId` and `tenantId` explicitly (`:61-66`, dispatched at `ImportController.php:559-564`) and looks the company up by both (`:96-107`) — r1 wrongly presented this as new work. G-3b **preserves and pins** that contract; the real queued gap is `ProcessProductImageImport`, which carries no company at all (`:53-58`) and is closed in G-3a | G-3b |
| `GET /imports` (`index`, `:54-73`) | accepts `page`, `per_page`, `status`, `type`, `q`; **scoped to the current company**, plus **every** NULL-company job labelled "unattributed" — one policy, matching §3.3 (G-R35 removed r2's contradictory "first company only" text here); response keeps its `meta` | **G-3b only** (G-R52: r3 co-owned this method with G-6a; the whole method — company predicate, pagination and filters — is G-3b's, and G-6a no longer touches it) |
| `GET /imports/{id}` (`formatJob`) | adds `company_id`, `company_name`, `user_id`, `user_name`, `duration_seconds`, `error_code`, `skipped_rows`, `warning_summary` (F1's, if merged) and `column_mapping` | **G-6b** |
| `GET /imports/{id}/preview` | adds the `duplicates` block (§4.2) | G-4 |
| `POST /imports` (`store`, pre-flight) | job-level **`units_not_seeded`** pre-flight, refused **422** before the file is parsed or queued (§4.13.3) | **G-12** |
| `GET /imports/{id}/errors` | adds `import_error_code`, `import_error_detail`, and `warnings` to each row; the FE starts sending `page`/`per_page` and rendering `meta` (today it sends neither and silently caps at the server's 50, `queries.ts:61` vs `ImportController.php:320-321`) | G-1, G-10 |
| `POST /imports` (`store`) | for `partners`, refuses 422 with the deprecation message (RUL-3) | G-9 |

### 5.3 Error codes returned by the API

**HTTP envelope codes** (`error.code`, SCREAMING_SNAKE) are a **different namespace** from
`ImportErrorCode` (§3.2.1) and no string appears in both. Existing: `IMPORT_NOT_EXECUTABLE`
(`:496-506`), `IMPORT_ALREADY_STARTED` (`:379-381`). New: `IMPORT_COMPANY_MISMATCH` (409),
`IMPORT_IN_PROGRESS` (409), `IMPORT_TYPE_RETIRED` (422, the existing deprecation refusal, now also
covering `partners`).

Three 4xx bodies instead carry an **`ImportErrorCode` value** as `error.code`, because the same coded
reason is also persisted or is the operator's whole message: `units_not_seeded` (422, §4.13.3),
`mapping_not_injective` (422, §4.10) and `unsupported_file_format` (422, §4.11). `source_purged`
(410, §4.14) is an envelope code with no enum twin.

### 5.4 Permissions and module gating

- Backend: `imports.manage` only. `RolesAndPermissionsSeeder.php:532` is the sole `imports.*` grant
  in the registry; no `imports.delete` exists and none is minted (D7's parenthetical resolved
  against the registry).
- Frontend: the three import routes are gated `RequirePermission moduleKey="settings"`
  (`apps/web/src/routes/index.tsx:2398,2408,2418`) while the API requires the `imports.manage`
  **permission** — a user with the settings module but no grant reaches the pages and collects 403s
  (Audit B §4). G-6 aligns the FE guard to the permission. This is why G-6 carries the
  `tenancy-authz-reviewer` gate.
- **`composite_items` DOES need module entitlement (G-R20).** Every native composite-item route sits
  behind `module:CompositeItems` (`apps/api/app/Modules/Catalog/Presentation/routes.php:33`) and the
  FE guards them with `ModuleGuard` (`apps/web/src/routes/index.tsx:2507-2518`), yet the generic
  import group carries only auth/tenant/`imports.manage` (`ImportServiceProvider.php:65-89`) while
  `CompositeItems` is live and selectable (`ImportType.php:43,75-81`) — so a tenant without the module
  can **create composite items through the importer**, a rule 12 violation.

  Because the entitlement is **per import type**, not per route, `module:` middleware cannot sit on
  the shared group. G-3b adds a constructor-injected **`ModuleEntitlementCheck`** (rule 13) wrapping
  the **same** resolution the middleware uses —
  `CompanyConfigService::getConfigForTenant($user->tenant)` then `$config->hasModule($module)`
  (`RequireModule.php:60-66`, `CompanyConfigService.php:48-60`) — with its exact 403 status and
  message. `ImportType::requiredModule(): ?string` is an exhaustive match, so a future
  vertical-exclusive type cannot be added without deciding.

  **It is used by BOTH controllers and on every surface that exposes the job (G-R30).** r2 named only
  `ImportController`, but **`MigrationWizardController::template()` is a different controller**
  (`:139-160`) and would have kept handing out composite-item templates; r2 also dropped r1's
  show/resume requirement, so an already-created composite job stayed readable and resumable after the
  module was removed. The gated surfaces are **every route that exposes the job or its type**:
  `store`, `template`, `preview`, `execute`, **`show`**, resume, **`updateOptions` (PATCH options)**,
  **`errors`**, **`error-summary`**, and every type-specific download (`rows-export`,
  `result-workbook`, `source-file`). The last three were the gap gate r3 flagged under rule 12; they
  are in G-3b's test list, not a follow-up.

  FE: `ImportDashboardPage` hides the composite-items card and the wizard refuses the type when the
  module is absent. Both sides are tested from **G-7** onward — a disabled tenant is refused on every
  listed surface, an enabled tenant succeeds.

---

## 6. Frontend changes

**F1 collision protocol (G-R19, plus Session F's confirmed touch list).** Session F's Lane F1
(`.worktrees/f-bug-1`, branch `fix/f-bug-1-import-location`) is **NOT merged** — Codex fix round 1 is
in progress, with F2 (company switch) in the same round. Its confirmed touch list is the **G hold
set**:

1. `ImportController::formatJob` (`warning_rows` / `warning_summary`)
2. `apps/web/src/features/import/pages/ImportWizardPage.tsx` (stock-location select, completion warning panel)
3. `apps/web/src/features/import/types.ts`
4. `apps/web/src/features/import/components/ColumnMapper.tsx`
5. `apps/web/src/locales/{en,fr,ar}/import.json`

**Session F detail that changes a G-3b line:** F1 gives `formatJob` a **second bool parameter** and
makes the `index` payload carry `warning_summary: null`. So G-3b, which owns `index` (§5.2), adds
**only** the `unattributed` flag inside `index()`'s mapping — it does **not** touch `formatJob`'s
signature or its `warning_summary` handling, both of which stay F1's until G-6b.

**Every lane that touches any of those five carries "after F1 merge (rebase)" as a precondition
line in §9.** **Lanes HELD by F1: G-4, G-2, G-5, G-1, G-6b, G-8, G-9, G-10, G-11.** Not held:
**G-7, G-3a, G-3b, G-6a, G-12** — all five are backend/test-only by construction.

**Dispatch note (r4.1):** three of these five were already dispatched to Codex on 2026-08-29 —
**G-7**, **G-3a**, and **G-12** (brief
`docs/sessions/session-G-imports-hardening-2026-08-29/briefs/LANE-G12-units-invariant-BRIEF.md`) —
in parallel and ahead of G-6a. This is captured below by cutting G-12 free of G-6a in the
dependency closure (it now depends on nothing) and making G-6a the dependent instead (it needs
G-12's enum to add its `worker_lost` case).

### 6.1 "Startable now" is derived from the dependency closure, not from the file-hold check

r3's four-lane claim was proved only against the five held files, which shows *non-collision*, not
*startability* (G-R42 NOT CLOSED, G-R52). A lane is startable now iff **both** hold:

1. **every lane in its dependency closure (§9.1) is already merged** — i.e. its dependency set is
   empty, since nothing is merged yet; **and**
2. **it touches none of the five F1-held files.**

| Lane | Dependencies (§9.1) | Touches a held file? | Startable now? |
|---|---|---|---|
| **G-7** | — | no | **YES** |
| **G-3a** | — | no | **YES** |
| **G-3b** | — | no | **YES** |
| **G-12** | — | no | **YES** |
| **G-6a** | G-12 | no | **YES** (dispatched in parallel; needs G-12's enum only to add `worker_lost`, not to start) |
| G-4 | G-3a, G-3b, G-6a, G-12 | yes | no |
| G-2 | G-3a, G-4 | yes | no |
| G-5 | G-4 | yes | no |
| G-8 | G-3b, G-4, G-6a | yes | no |
| G-9 | G-4, G-7 | yes | no |
| G-13 | G-4, G-12 | no | no — after G-4 |
| G-1 | G-3b, G-4, G-8 | yes | no |
| G-11 | G-4, G-8, G-9 | yes | no |
| G-6b | G-3b, G-4, G-6a, G-1 | yes | no |
| G-10 | G-1, G-6b, G-11 | yes | no |

**The startable-now set is {G-7, G-3a, G-3b, G-6a, G-12}** — derived rather than asserted, with
`ImportController::index` moved wholly into G-3b so the G-6a↔G-3b co-ownership that made r3's claim
false is gone, and with **G-12** added (r4.1): it has no dependencies of its own, and G-6a is
startable alongside it because G-6a's dependency on G-12 is only for adding the `worker_lost` case
to an enum that already exists once G-12 lands, not a blocker to starting work. **G-7, G-3a and
G-12 were dispatched to Codex on 2026-08-29**; G-3b and G-6a follow.

The five-file non-collision proof for those four lanes still holds and is worth keeping explicit:

| Lane | `formatJob`? | `ImportWizardPage.tsx`? | `types.ts`? | `ColumnMapper.tsx`? | `locales/*/import.json`? |
|---|---|---|---|---|---|
| **G-7** (tests + fixtures only) | no | no | no | no | no |
| **G-3a** (migrations, ProductVariantService/VariantLabelService, FormRequests, ProductImage job/service) | no | no | no | no | no |
| **G-3b** (M4, company pin, `ModuleEntitlementCheck`, both controllers, **`index`**) | no | no | no | no | no |
| **G-6a** (M6c, reaper, claim, DELETE, source-file download, purge, adds `worker_lost` to G-12's `ImportErrorCode`) | **no, by construction** — `formatJob` is G-6b's | no | no | no | no |


| Surface | Change | Lane |
|---|---|---|
| Wizard — validation step | duplicate summary card: counts by bucket + the RUL-1 three-way choice (override / skip / cancel); copy states the field-level merge ("blank cells never blank your existing data") and last-row-wins | G-4 |
| Wizard — validation step | error grid sends `page`/`per_page` and renders `meta` (today: silently capped at 50 of 900) | G-10 |
| Wizard — execute step | elapsed time + Cancel affordance (DELETE for a not-yet-started job; for a running one, the copy explains the reaper will resolve it) | **G-6b** |
| Wizard — completion step | **no green check on a `failed` job** — the step is entered on `completed` *or* `failed` and renders the same `CheckCircle` and title regardless (`ImportWizardPage.tsx:379-384,1027-1108`); render `error_message`/`error_code`; unconditional export button (no longer gated on `failed_rows_csv_url`); "re-upload corrected file" link carrying `reimport_of` | G-1 |
| Wizard — resume | route becomes `settings/import/:type/:jobId?`; on mount with a `jobId`, rehydrate from `GET /imports/{id}` and land on the validation step. `selectedFile` cannot be restored (the FE never learns the server path), so the execute summary shows `original_filename` from the job instead of client memory (`ImportWizardPage.tsx:910`). `routes.test.tsx:231` pins the exact path set and must be updated | **G-6b** |
| Wizard — mapping | render the `description`/hint `ColumnMapper` already accepts and never reads (`ColumnMapper.tsx:10`); descriptions move from hardcoded English literals (`ImportWizardPage.tsx:126-202`) into backend-supplied i18n keys (rule 11); `location_code` and `quantity` — the two columns the staging team misread — get real descriptions | **G-11** (moved out of G-10; rebase after F1) |
| Wizard — balance steps | inline explainer per type + per-column sign hints + the preview interpretation echo column (§4.12) | **G-11** |
| History page | pagination from server `meta`; company + operator + duration columns; `error_message`/`error_code` rendered; `validating`/`validated` filter chips added; live refresh for `importing` rows; per-row Resume / Discard for orphaned `pending`/`validated` jobs | **G-6b** |
| History detail page (**new**) | counts (imported / skipped / failed / warned), breakdown **by code**, `warning_summary`, options used, downloads (result workbook, rows export CSV + XLSX, source file) | **G-6b** |
| Dashboard | one "Business partners" card becomes two preset cards, Customers and Suppliers; the `partners` advanced card is removed; `ENTITY_TO_IMPORT_TYPE` (`ImportDashboardPage.tsx:82-91`) keeps mapping `customers`/`suppliers` deep links | G-9 |
| Dead code | remove `ErrorViewer`, the import feature's own `ValidationResults`, `getWizardOrder`/`useWizardOrder`, `getMigrationStatus`/`useMigrationStatus`, `checkDependencies`/`useDependencyCheck` (all zero render sites, Audit C §6.1). **`useDeleteImport` wiring is NOT here — it is G-6b's** (G-R52/G-R56: r3 said G-6a, which has no FE at all) | G-10 |

**i18n.** New namespaces `import.errors.*` (one key per `ImportErrorCode` + `unknown`),
`import.warnings.*` (one per `ImportWarningCode` + `unknown`, including the two read-only legacy cases
`location_unresolved` and `opening_exists` — §3.2.1), `import.duplicates.*`,
`import.history.detail.*`, `import.mapping.columns.*`. **Arabic
parity is mandatory** and is a G-10 deliverable: `ar/import.json` is **15 leaf keys against en/fr's
185** (Audit B §6), so the whole wizard, history page and every status currently falls back to
English for an Arabic operator. The missing `wizard.progressAriaLabel` key — nested one level too
deep under `wizard.execute` in both en and fr, so `ImportProgress.tsx:25` renders the literal key
string in every locale — is fixed in the same lane.

**Design tokens.** The feature directory is already clean (zero hardcoded Tailwind colour classes,
Audit C §7); new components use `tokens`/`textColors`/`borderColors` from `@/lib/designTokens`
exclusively (rule 18).

**TanStack keys.** Every new tenant-data query key uses `tenantScopedKey([...])` (rule 14 addendum),
enforced by `apps/web/tools/audit-tanstack-keys.mjs`.

---

## 7. Locale templates (D8, R8)

### 7.1 Seeded country columns (M7)

`CountryDefaults` has **no number-format concept at all** and `countries.default_locale` is
effectively dead data with exactly one unrelated consumer (`AuthController.php:931`). Two new
**seeded** columns follow the existing per-country presentation precedent
(`currency_decimal_places` was added the same way by
`2026_03_11_100000_add_currency_decimal_places_to_countries.php`), honouring
`feedback_country_accounting_seeded_settings` — never hardcoded:

| Country | Code | `number_decimal_separator` | `csv_delimiter` |
|---|---|---|---|
| France | FR | `,` | `;` |
| Tunisia | TN | `,` | `;` |
| Italy | IT | `,` | `;` |
| Algeria | DZ | `,` | `;` |
| Morocco | MA | `,` | `;` |
| United Kingdom | GB | `.` | `,` |
| United States | US | `.` | `,` |
| **any other / unlisted** | — | `.` | `,` |

Values follow CLDR/ICU number symbols per the seeded `default_locale`, and the Excel rule that the
CSV list separator is `;` exactly when the decimal separator is `,`. Ratification is **OQ-G-15**.

**M7 backfills the existing rows itself (G-R17).** r1 relied on a manual per-tenant `CountriesSeeder`
rerun after the migration. That is unsafe: pushing to `dev` auto-migrates **every** staging tenant,
so FR/TN/IT/DZ/MA companies would generate US-shaped templates from the moment the columns land
until someone remembers the seeder — indefinitely if one tenant is missed. M7 therefore carries the
**explicit country-code table above inside the migration**, applies it with an `UPDATE … WHERE code
IN (…)`, leaves every other country on the `.`/`,` default, and logs a census
(`Log::info('country_number_conventions.backfill', ['fr' => n, 'tn' => n, …, 'default' => n])`) so
the per-tenant deploy log shows what it did. `CountriesSeeder` gains the same values for **future
provisioning only**; correctness never depends on running it. A brownfield migration test covers
FR, TN and US.

**There is no thousands-separator column.** Templates emit **no grouping character at all** —
grouping is the single largest source of parse ambiguity and adds zero value in a machine-readable
file. Grouping stays strictly on the parse side, where the normalizer already handles both families.

### 7.2 `NumberConventionResolver`

New, constructor-injected (rule 13, never `app()`):
`company.country_code` → `tenant.country_code` → default (`.` / `,`). Returns a
`NumberConventionData` DTO (decimal separator, csv delimiter) — a DTO, not an array, per rule 3.

### 7.3 Template generation

`MigrationWizardService::generateTemplate()` today returns a `string`: header `implode(',', …)` with
**no CSV escaping of the header cells**, LF line endings, no BOM, and every sample number a
dot-decimal literal (`:222-233,230,232,258,275,334-335,344,352-353,362-405`).

**New:**
- **CSV** — UTF-8 **BOM**, CRLF, the country's `csv_delimiter`, every cell properly escaped
  (headers included), sample numbers written with the country's decimal separator and **no grouping**.
- **XLSX** — PhpSpreadsheet's `Xlsx` writer (already a dependency via `ResultWorkbookService.php:11,49`).
  **Money and quantity sample cells are written as TEXT holding the exact decimal string**
  (`"29.990"`, `"4.0000"`) — explicitly **not** numeric cells with a display format, which is what
  r1 specified and what would have guaranteed a PHP float on the way back in (§4.11, G-R4). Non-money
  cells are ordinary text. The hint row explains the "number stored as text" nudge Excel shows.
- **Hint row** — the R10 per-column hints ride the template: CSV as **physical row 2 whose column 1
  is exactly `#__AUTOERP_HINT_V1__`** (the exact positional sentinel of §4.12c — never a generic `#`
  comment rule), XLSX as header cell comments.
- Served with a `?format=` param; the FE's hardcoded `.csv` filename (`ImportWizardPage.tsx:645`)
  becomes format-aware.
- **A third example row** for products demonstrating a **blank SKU with opening stock**
  (`quantity`, `location_code`), because both current example rows fill `sku`, which reads as
  "mandatory" and directly contradicts R2 (Audit C §9.8 / OQ 8). The same row also fills
  `location_code` and `placement_path`, which no example row demonstrates today.

### 7.3a Rule-19 validation ceilings — the missing half (G-R40, closes LEDGER D-T9-6)

Rule 19 has two halves: values must not lose precision *and* FormRequests must carry a **regex
ceiling** per column scale. r2 fixed only the first. A bare `numeric` accepts `19.99999` and the
value is then silently truncated by `CurrencyScale::bcformatStrict` at staging — the exact failure
mode the `debit`/`credit` rules already document at `ImportType.php:229-236`.

Audited state of every live numeric import column (`ImportType.php:189-245`):

| Type | Column | Today | r3 |
|---|---|---|---|
| products | `sale_price_incl_tax`, `sale_price_excl_tax` | `{1,3}` ✓ | unchanged |
| products | `margin` | `{1,2}` ✓ (percent, signed) | unchanged |
| products | `quantity` | `{1,4}` ✓ | unchanged |
| products | **`sale_price`** | `['nullable','numeric','min:0']` — **no ceiling** | add money `/^\d+(\.\d{1,3})?$/` |
| products | **`purchase_price`** | **no ceiling** | add money `{1,3}` |
| products | **`tax_rate`** | `numeric, min:0, max:100` — **no ceiling** | add percent `/^\d+(\.\d{1,2})?$/` |
| opening_balances | `debit`, `credit` | `{1,3}` ✓ | unchanged |
| parties | the three balance columns | `{1,3}` ✓ | unchanged |
| composite_items | **`base_price`** | **no ceiling** | add money `{1,3}` |
| composite_items | **`manual_cost`** | **no ceiling** | add money `{1,3}` |
| composite_items | **`tax_rate`** | **no ceiling** | add percent `{1,2}` |
| stock_levels (retired) | `quantity` | no ceiling | add quantity `{1,4}` for completeness; the type is refused at upload anyway |

Signedness matches each field: prices and quantities unsigned (they already carry `min:0`), `margin`
signed. **This closes LEDGER row D-T9-6** ("tax_rate percent ceiling on ImportType"), which this
program inherits — say so in the lane's LEDGER update.

Each added ceiling gets a **boundary test** (max-scale value accepted) and an **excess-scale
rejection test** (one decimal too many refused with its row number). **G-7's fixtures use in-scale
values** so the Wave-0 baseline does not go red when the ceilings land in Wave 3.

### 7.4 Parser

`NumericFieldNormalizer` already converts decimal-comma, EU `1.234,56` and US `1,234.56` correctly
(`:71-83`, tests at `NumericFieldNormalizerTest.php:28,38,48`). **Only the ambiguous case changes**:
`1,234` is hardcoded to a European reading (`:18-20,76-78`), which is a silent **1000× money error**
for a US/GB company with no warning and nothing in the result workbook.

The normalizer gains a **convention parameter used only for that tiebreak**. All other formats parse
exactly as today. Its call sites (`ImportService.php:84,122`) pass the **job's pinned company**
convention **explicitly**. Whichever convention the template was generated in, a hand-edited file in
the other convention still parses: the template is locale-shaped (operator-facing), the parser stays
convention-agnostic except for the tiebreak.

**Which process actually runs which step (G-R43 — r2 stated this wrongly).** Ordinary parse → map →
`addRowsBatch` → validate happens in the **upload HTTP request**, not in a worker
(`ImportController.php:161-171` → `ImportService.php:79-123`); the convention is resolved there from
the job's pinned company. `ProcessImportJob` consumes **already-normalized** rows and already carries
explicit `companyId`/`tenantId` (`:61-66`). The **only** queued call into normalization is
`ProcessProductImageImport`'s error-row `addRowsBatch()` (`:118-124`), which is exactly why G-3a
threads a company into that job. Tests clear `CompanyContext` **only** for the genuinely queued paths
(the two jobs), not for the upload path.

**Provider binding (G-R43).** `MigrationWizardService` is currently bound as a **no-arg singleton** —
`$this->app->singleton(MigrationWizardService::class, fn () => new MigrationWizardService)`
(`ImportServiceProvider.php:55-57`) — so constructor-injecting `NumberConventionResolver` and
`ConventionCopy` into it would fail at resolution. **G-8 must list `ImportServiceProvider`** and
either delete that binding (letting the container autowire it, as the neighbouring `ImportService`
binding does with `$app->make(...)`) or extend it to pass the dependencies. Rule 13 forbids reaching
for `app()` inside the service instead.

Delimiter auto-detection on upload is already correct (`SpreadsheetParserService.php:133-147`) and is
**not** changed to trust the country — a file may come from anywhere.

---

## 8. Parties presets and Partners retirement (D6, as ruled by RUL-3)

**One import type, one writer** (**OQ-G-12**, ruled). `ImportType::Parties` stays as the only live
partner importer — it
is the superset, and the only one that handles `opening_balance` → AR/AP historical documents via
`PartiesBalancesPhase`. **No `customers` / `suppliers` enum case is created.**

**`ImportType::Partners` is retired**, exactly as `stock_levels` was (the rationale for keeping the
case readable is already written down at `ImportType.php:26-45`):
`deprecationMessage()` arm, 422 at upload (`ImportController.php:113-119`), 422 at template
(`MigrationWizardController.php:148-153`), last-ditch throw in the row dispatcher
(`ImportService.php:426-428`), and the FE mirrors (`DEPRECATED_IMPORT_TYPES`, `LiveImportType`,
retired-notice screen `ImportWizardPage.tsx:1119-1146`). Historical `partners` jobs keep rendering in
history. **Long pole:** five unrelated test files use `'type' => 'partners'` as a generic upload
fixture and must move to `parties` — `ImportJobOptionsTest.php:88,103`, `ImportPreviewTest.php:101,162,272,326`,
`ImportInfrastructureTest.php`, `MigrationWizardTest.php:200-201`, `ImportTypesTest.php`.

**Two presets over the one importer.** A `preset` is a template + wizard variant, not a type:

| Preset | Template pre-sets | Balance column carried | Extra column |
|---|---|---|---|
| `customers` | `type = customer` on every example row | `opening_balance_customer` | `customer_category` |
| `suppliers` | `type = supplier` on every example row | `opening_balance_supplier` | — |

The `type` column **stays in the schema and in the file format** (`ImportType.php:91,171`); the
preset's template fills it, and the wizard **hides** it from the mapping step when a preset is
chosen (mapping it to a constant). An operator with an existing mixed file keeps using the generic
parties flow with `type` visible. The dashboard shows two cards; the deep-link alias map
`ENTITY_TO_IMPORT_TYPE` (`ImportDashboardPage.tsx:83-84`) already maps `customers`/`suppliers` onto
`parties`, so those links keep working unchanged.

**The balance columns are NOT collapsed** into one signed `opening_balance`. Sign convention, per
`docs/guides/legacy-migration-accounting-conventions.md` §3, which the importer already implements:
balances are written **from your company's point of view**; a positive customer balance means the
client owes you (historical invoice on `411`), a negative means you owe the client (historical credit
note for the absolute amount); a positive supplier balance means you owe the supplier (`401`), a
negative means the supplier owes you. The sign never flips an account — it selects the **document
type**; the imported amount is always the absolute value, and zero balances are skipped. In the
per-invoice aged file, amounts must be **non-negative** and direction comes from an explicit
`document_type` column — a negative there is a row error by design. `PartiesRowMapper`'s three
ambiguity rules (`:59-77`) therefore **remain** (the generic mixed file still needs them); the presets
simply make them unreachable in practice.

**`customer_category`.** The column, enum, DTO and request rule all exist and are load-bearing for
fiscal B2B escalation, but `PartnerService::upsertWithTypeMerge()`'s update payload never sets it
(`PartnerService.php:88-107`), so every imported customer inherits `null`. The customers preset gains
an optional `customer_category` column (`in:individual,business`); a **blank cell defaults to
`business`**, matching the 2026-03 backfill (`2026_03_09_100001_add_customer_category_to_partners.php:27-30`).
Open as **OQ-G-14**.

**`finalizeImport()` exhaustiveness.** `ImportService.php:466-471` ends in `default => []`: a case that
forgets its arm compiles, runs, imports the partner, and **silently never posts AR/AP opening
balances** — finding `G-F-17`, the highest-severity item in this lane. The match becomes **exhaustive
(no `default` arm)**, with one test per case asserting the finalize phase actually ran. One Parties
arm; the retired cases get explicit no-op arms with a comment.

**R9's field-clobbering risk is closed by D2**, not by a separate mechanism: with the coalescing
merge (§4.4), a partner listed in both a customers file and a suppliers file no longer has its
address blanked by whichever file ran last. The type merge itself is already correct and needs no
code — any type divergence resolves to `both` and can never flip back
(`PartnerService.php:72-80`, pinned by `PartnerCodeUpsertTest.php:115`), and `both` is honoured
everywhere downstream (`Partner::isCustomer()`/`isSupplier()` return true for `Both`,
`Partner.php:216-224`).

---

## 9. Lane plan

**Protocol for every lane** (`docs/sessions/session-F-testing-2026-08-29/HANDOVER.md`
§Operating constraints): worktree under `.worktrees/<lane>` off `dev`; **never stash** (the stash
stack is repo-global); never combine `--force`-anything with `git push` in one Bash call; run
`php tools/feature-lane-manifest-check.php` (from `apps/api`) at **every** merge — new Feature test
classes need manifest ceiling raises; review records committed to `docs/superpowers/reviews/`; merge
to **local** `dev` first and promote to `origin/dev` in verified fast-forward batches (rule 21).
Every lane is **TDD**: the listed tests are written red first. **Every lane updates
`docs/modules/imports.md` for its own drift rows** from the gap matrix §6 table; G-6b additionally
fixes the footer date and the endpoint list.

### 9.1 Dependency graph, and the waves derived from it (G-R52)

Waves are **not** an input — they are the levels of this graph. A dependency exists only where a lane
needs a type, a column, an enum case, a service contract or a behaviour that another lane creates.

| Lane | Depends on | Why (the concrete artifact) |
|---|---|---|
| **G-7** Coverage baseline | — | test-only |
| **G-3a** SKU scope schema + barcode contract | — | migrations + non-Import consumers |
| **G-3b** Job company pin + module entitlement | — | M4, `ModuleEntitlementCheck`, `ImportController::index` |
| **G-12** Units invariant | — | it **declares `ImportErrorCode`** with case `units_not_seeded` (§3.1d, dispatched 2026-08-29 ahead of G-6a) |
| **G-6a** Claim, reaper, DELETE, purge | G-12 | M6c; adds `worker_lost` to G-12's `ImportErrorCode` — until M6c lands, G-12's own worker-refusal path uses the existing `failJob()` leading-token convention |
| **G-4** Row semantics: identity, outcomes, merge, units | G-3a, G-3b, G-6a, G-12 | `(company_id, sku)`; the job's company (a census for A is meaningless if execute lands on B); the enum to add row-level cases to; `UnitCatalogQueryInterface` + the empty-catalogue refusal ahead of the resolver |
| **G-2** SKU generation | G-3a, **G-4** | the unique it catches by name; and it edits `ProductService::upsert` **after** G-4 has reshaped it (G-R52: r3 had G-4 using G-2's resolver without declaring it — r4 folds the **resolver into G-4** and leaves G-2 generator-only) |
| **G-5** Opening fence + correction | **G-4** | `duplicate_policy`, `outcome`, and the row-truth write it commits with |
| **G-8** Locale templates, exact reader, ceilings | G-3b, **G-4**, G-6a | the job's company for the convention; `ColumnMappingData` for `parseRows(type, mapping)`; the enum to add `numeric_cell_is_date` / `formula_not_allowed` / `xls_inexact_value` / `unsupported_file_format` |
| **G-9** Parties presets + Partners retirement | **G-4**, G-7 | the coalescing merge R9 relies on; the round-trip test it retargets |
| **G-1** Error-line export + coded errors | G-3b, **G-4**, **G-8** | company on the job header; outcomes + the coded row channel; **`NumberConventionResolver`, so the export is written in the company's convention by its single owner** (G-R52: r3 double-owned locale formatting — r4 moves G-8 *before* G-1 rather than splitting the writer) |
| **G-11** Point-of-use sign guidance | **G-4**, **G-8**, G-9 | the preview payload + unit resolution; the template writer and copy catalog; the presets the explainer describes |
| **G-6b** Job payload, detail page, resume | G-3b, **G-4**, G-6a, **G-1** | company/outcome/code fields; the by-code breakdown needs G-1's codes |
| **G-13** Unit company scoping | **G-4**, **G-12** | RUL-7 step 2: it changes the visibility predicate **inside `UnitCatalogQuery`**, which G-12 creates, and it must not move under a resolver that is not yet written |
| **G-10** Wizard hygiene | **G-1**, **G-6b**, **G-11** | it lands last on the same FE files |

**Waves = the levels of that graph.** Nothing is scheduled by preference.

| Wave | Lanes | Note |
|---|---|---|
| **0** | G-7, G-3a, G-3b, G-6a | dependency-free **and** F1-free → the startable-now set (§6.1), which as of r4.1 also includes G-12 (below) — see note |
| **1** | G-12 | placed here by the original closure derivation (needed G-6a's enum); **r4.1**: its dependency closure is actually empty (it *creates* the enum — §3.1d), so it is dependency-free like Wave 0 and was in fact dispatched 2026-08-29 alongside G-7/G-3a, ahead of G-6a. Left numbered Wave 1 rather than renumbering every downstream wave, since nothing else in its closure changes |
| **2** | G-4 | the hinge lane; almost everything downstream needs it |
| **3** | G-2, G-5, G-8, G-9, **G-13** | mutually independent; may run in parallel |
| **4** | G-1, G-11 | |
| **5** | G-6b | |
| **6** | G-10 | last by construction |

| Lane | Wave | Size | Decisions | Gates | Held by F1? |
|---|---|---|---|---|---|
| G-7 Coverage baseline | 0 | S | D12 | imports-reviewer | no |
| G-3a SKU scope schema + barcode contract | 0 | M | RUL-2 | imports-reviewer + tenancy-authz + inventory-costing | no |
| G-3b Job company pin + module entitlement | 0 | M | D5 | imports-reviewer + tenancy-authz | no |
| G-6a Claim, reaper, DELETE, purge | 0 | M | D7 | imports-reviewer + tenancy-authz | no |
| **G-12 Units invariant** | 1 | S | R11 refinement | imports-reviewer + tenancy-authz | no |
| G-4 Row semantics (identity, outcomes, merge, units) | 2 | **L** | D2, D3(resolution), D10, RUL-1, R11 | imports-reviewer | **yes** |
| G-2 SKU generation | 3 | S/M | D3 | imports-reviewer | **yes** |
| G-5 Opening fence + correction | 3 | M | D1, RUL-5 | imports-reviewer + inventory-costing + **stock-gl-interaction** | **yes** |
| G-8 Locale templates + exact reader + ceilings | 3 | **L** | D8, RUL-4 | imports-reviewer | **yes** |
| G-9 Parties presets + Partners retirement | 3 | S/M | D6/RUL-3 | imports-reviewer | **yes** |
| **G-13 Unit company scoping** | 3 | M | **RUL-7** | imports-reviewer + tenancy-authz | no |
| G-1 Error-line export + coded errors | 4 | M | D4, D9 | imports-reviewer | **yes** |
| G-11 Point-of-use sign guidance | 4 | M | R10 | imports-reviewer + frontend-conventions + **treasury** (GL echo) | **yes** |
| G-6b formatJob, detail page, resume | 5 | M | D11 | imports-reviewer + frontend-conventions | **yes** |
| G-10 Wizard hygiene | 6 | S | — | frontend-conventions | **yes** |

**"Held by F1" = a precondition line on the lane brief: `rebase onto dev after F1 merges before
touching the hold set` (§6). F1 is not merged (Codex fix round 1 in progress).**

### 9.2 Lane briefs

#### G-7 — Coverage baseline (Wave 0, test-only, runs FIRST as the safety net)

**Scope.** A parameterised `ImportTypeHttpRoundTripTest` driving the **real route pair**
(`POST /api/v1/imports` with an `UploadedFile` CSV fixture → `POST /api/v1/imports/{id}/execute`) per
live type, asserting persisted domain rows. Today only legacy `partners` has a true HTTP E2E
(`ImportTypesTest.php:94-130`); `parties` and `opening_balances` call the service directly, and
**`composite_items` — a live, operator-selectable type — has zero backend coverage of any kind**
(`grep -rln "ImportType::CompositeItems" tests/` → nothing).

**Files.** New `apps/api/tests/Feature/Import/ImportTypeHttpRoundTripTest.php` + fixtures under
`tests/Fixtures/Import/`. No production files. **Migrations:** none.

**Tests (TDD).** Per live type (`products`, `parties`, `opening_balances`, `composite_items`), one
**European-convention** fixture (comma decimal, `;` delimiter) and one **US-convention** fixture;
`parties` asserts **every** mapped field on the persisted partner (`email`, `phone`,
`tax_id`→`vat_number`, `address_line1`→`street_address`, `address_city`, `address_postal_code`,
`address_country` — mapped at `PartiesRowMapper.php:18-27`, asserted nowhere today);
`composite_items` from zero (validation, execution, template); `product_images` ZIP at HTTP level
(`ImportController.php:752`), with its existing service test aliased into `tests/Feature/Import/` so
the suite is discoverable. **Fixtures use in-scale values** so this baseline does not go red when
G-8's rule-19 ceilings land (§7.3a). It must **not** claim entitlement behaviour before G-3b — the
composite-items entitlement assertions are G-3b's.

#### G-3a — SKU scope schema + barcode contract (Wave 0)

**Scope.** M1/M2/M3 (§3.1) plus **every non-Import consumer**. **Files:** the three migrations; the
new `VariantIndexNames` / `ProductIndexNames` / `PartnerIndexNames` constant classes, introduced **in
the same commit as the migrations** (§3.1b); `ProductVariantService.php` (`:131`, `:385`, `:387`
constraint-name branches); `CreateProductRequest.php:177-184` and `UpdateProductRequest.php:162-169`
(`Rule::unique` chains gain `->where('company_id', …)` **and drop `->whereNull('deleted_at')`**,
§3.1c); `ProductImageImportService.php:236-239` (company predicate) and `ProcessProductImageImport`
(`companyId` through the **job payload**, never `CompanyContext` — queued job, rule 20) plus the
relative-storage-key fix (G-R22).

**Explicitly NOT in this lane (single-ownership cut, G-R52):** the `withTrashed()` sweep inside
`ProductService::findExistingProduct` / `PartnerService` resolution and the deleted-holder **row
errors** (`sku_held_by_deleted_product`, `vat_held_by_deleted_partner`) belong to **G-4**, which owns
the resolver and the coded row channel. G-3a owns the DB shape and the **UI** path.

**Deliberately not changed**, verified already company-scoped by Audit D §2a–2g:
`ProductService::findIdBySku`, `InventoryOpeningService.php:144-147`,
`MatchSuggestionService.php:111,272-278`, `ProductController::index()` (`:83,121` — the POS catalog
and scan path), `LineEntryController::findProductByCode()` (`:159-172`), both variant repositories.
Scout/Meilisearch has **zero** `toSearchableArray`/`Searchable` hits; `SalesReportService.php:146,164`
selects `products.sku` as a display label grouped by `product_id` and is unaffected.
`VariantLabelService` keeps its tenant-wide contract **verbatim** — it is pinned, not edited (§3.1a).

**Tests.** A migration test per table pinning **both** paths — collision → `RuntimeException` with the
census logged, clean → repaired shape — on PG **and** SQLite (M2's SQLite path is a no-op and must
assert that); a two-company same-SKU import that today raises 23505 and after the lane
upserts/creates correctly; **the same barcode on two variants in sibling companies is still refused**
while the same variant **SKU** across companies is now allowed; variant create-collision **and
restore-collision** → 422 against the **post-migration** names, for both barcode and SKU;
**`tests/Feature/Catalog/VariantBarcodeRaceTest.php:92-106` updated** — it asserts the literal
`product_variants_tenant_sku_unique` in its `catch (QueryException)` arm and must move to the shared
constant (G-R44); `VariantLabelService::collidesWithProductCode()` still refuses a value equal to a
**sibling company's** product SKU (desired, not a bug); a second-**location** import asserting
**product count unchanged and `stock_levels` rows added** (RUL-2c); a `ProductImageImportService`
test attaching to the right company's product when both companies own the SKU, with `CompanyContext`
**cleared** (rule 20); the UI-path deleted-holder conflict now reported by the FormRequest.
**Gate brief note:** Audit D's census is the input, not the proof — the gate must independently
re-grep for `sku`-keyed lookups, because the precedent's gate found a consumer the implementer had
already declared verified, and gate r1 found two more.

#### G-3b — Job company pin + module entitlement (Wave 0, backend only)

**Scope.** D5 + the `composite_items` module entitlement (G-R20) + **the whole of
`ImportController::index`** (G-R52). **Files:** M4 (with the evidence-based backfill, §3.3);
`ImportController` — `store` (company + `source_hash` stamp), `updateOptions`, `execute`
(company-mismatch refusal), **`index` (`:54-73`: company predicate + unattributed policy +
`page`/`per_page`/`status`/`type`/`q` filters — this method is not touched by any other lane)**;
`ImportService::createJob`; `ImportType::requiredModule()`; a new `ModuleEntitlementCheck` that
constructor-injects `CompanyConfigService`; **and `MigrationWizardController`** — the template lives
there (`:139-160`), not in `ImportController`, so omitting it leaves the gate open (G-R30).
**`ProcessImportJob`'s existing explicit company/tenant contract is preserved and pinned, not
rewritten** (G-R16). **Backend only:** `ImportHistoryPage.tsx` / `queries.ts` company scoping and
`ImportDashboardPage.tsx` card-hiding are **G-6b's**.

**Tests.** A job uploaded under company A and executed after switching to B is refused 409
`IMPORT_COMPANY_MISMATCH`; history lists only the current company's jobs, and **unattributed (NULL)
jobs appear for every company** with the badge; history pagination returns page 2 and each filter
narrows; the backfill resolves a job from its `imported_entity_id` links (products, partners,
composite items, and a GL job via its journal entry), logs `mixed` on disagreement, and leaves only
evidence-free multi-company jobs NULL; a tenant **without** the CompositeItems module is refused 403
on **every** gated surface — `store`, `template` (via `MigrationWizardController`), `preview`,
`execute`, `show`, resume, `updateOptions`, `errors`, `error-summary` and each type-specific download
— and an enabled tenant succeeds on all of them; the queued job resolves scale/currency from the
passed company with **no `CompanyContext` bound** (`app(CompanyContext::class)->clear()` before
dispatch). Old fixtures creating `import_jobs` rows are updated for M4.

#### G-6a — Claim, reaper, DELETE, purge (Wave 0, backend only)

**Scope.** D7's backend half, the G-R38/G-R50 claim and reaper, and the G-R41 purge. **r4.1:**
`ImportErrorCode` is created by **G-12**, dispatched 2026-08-29 ahead of this lane (§3.1d) — G-6a
adds the job-level `worker_lost` case to it and declares `ImportErrorDetailData`. Deliberately
excludes `formatJob`, `index` and every FE file. **Files:** **M6c**; adds `worker_lost` to G-12's
`ImportErrorCode` enum + new `ImportErrorDetailData` DTO/cast;
new `ReapStuckImportsCommand` and `PurgeExpiredImportArtifactsCommand` (both extend
`TenantScopedCommand`) + `routes/console.php` registrations; new `ImportJobClaimService`;
`ImportController::execute` (claim before any row read; remove the `status = Pending`-before-dispatch
dance), `::destroy`, `::downloadSourceFile`; `ProcessImportJob` (re-verify, drop the pre-claim
`canStart()`, CAS terminal writes); `ImportServiceProvider` (the three new routes).

**Tests.** **Two clocks:** a job with `worker_started_at IS NULL` and `claimed_at` 91 minutes old is
reaped; one 89 minutes old is not; **reaper-vs-late-start** — a job claimed 91 minutes ago whose
worker started 2 minutes ago is **left alone**; **reaper-vs-terminal-write on PostgreSQL** — a worker
committing `completed` in the same instant as the reaper's sweep leaves exactly one terminal status
and the loser logs `terminal_write_lost`. The reaper runs **per tenant** (a two-tenant test proving
`forEachTenant`, and that running on the central connection alone finds nothing). **Two concurrent
sync requests** (one 200, one 409 `IMPORT_ALREADY_STARTED`) and **two concurrent async requests**
(one 202, one 409) on PostgreSQL, rows processed exactly once; **worker double delivery** exits
idempotently; **dispatch failure** leaves a claimed job the reaper reclaims;
`ImportReExecutionGuardTest.php:237-273` kept green; a **failed job cannot be resumed or
redispatched** (the one-shot invariant, §4.1.1); DELETE removes job + rows + file and is refused 409
while `importing`; **purge** — 89-day survives, 91-day purged, source download then 410
`source_purged`, tenant-isolated, second run a no-op, ProductImages' immediate-purge exception
asserted (RUL-6).

#### G-12 — Units invariant (Wave 1, backend only, NEW in r4 — G-R49)

**Dispatched 2026-08-29 from brief
`docs/sessions/session-G-imports-hardening-2026-08-29/briefs/LANE-G12-units-invariant-BRIEF.md`;
owns `ImportErrorCode` creation** (r4.1 — dispatched before G-6a, so ownership moved to the
earliest-dispatched lane rather than the earliest-planned one; see §3.1d).

**Scope.** The owner's R11 refinement invariant, **independently of the scope ruling**: a company
never imports against an empty unit catalogue without a coded refusal, and there is exactly **one**
visibility predicate in the codebase.

**Files.** New `App\Shared\Contracts\UnitCatalogQueryInterface` — `visibleActiveUnits(string $companyId): Collection`
and `hasAny(string $companyId): bool` — implemented by a new
`App\Modules\Uom\Application\Services\UnitCatalogQuery` and bound in `UomServiceProvider`. **This
class is the single home of the §4.13.2 visibility predicate**, so G-13's RUL-7 substitution is a
one-file change and every consumer (G-4's resolver, G-8's template list, G-11's hint, G-1's export
message) inherits it. `UomController`'s six hand-rolled scopings (`:40-46,72-79,107,163,217,264-266`)
are re-pointed at it in the same commit so a second predicate cannot survive. **New**
`ImportErrorCode` enum, single case `units_not_seeded` (r4.1 — G-12 creates it, dispatched
2026-08-29 ahead of G-6a; §3.1d); `ImportController::store` (pre-flight 422, returned via the
existing `openingBalancesForbidden()`-style coded envelope); `ProcessImportJob` (worker
re-verification → `failJob`, stamping the code via the existing leading-token convention until
G-6a's M6c lands `import_jobs.error_code`); **M8** (the brownfield census, §3.1 — logs, never refuses, never seeds);
`CompanyController::store` gains the provisioning call in the existing `:167-184` block, next to
`paymentMethodSeeder->run($company)` (`:172`), **as a self-guarding no-op under today's tenant-global
units** — it exists so the guarantee holds unchanged when the scope ruling lands;
`TenantInitializationService::seedUnitsOfMeasure()` (`:238,259-265`) is **pinned, not edited**.

**Tests.** A second company created inside a tenant sees a **non-empty** visible active unit set
(nothing asserts this today); an import uploaded for a company with an empty visible set is refused
**422 `units_not_seeded`** before parsing; the same set emptied between upload and run makes the
**worker** fail the job with `units_not_seeded` stamped via the existing `failJob()` leading-token
convention (r4.1 — `import_jobs.error_code` is M6c, G-6a's, and does not exist yet at G-12's
dispatch); M8 logs the census and does **not**
abort a tenant with an empty set; `UnitCatalogQuery` returns the same rows as the resolver, the
template list and the export message for the same company (the equality asserted in §4.13.4);
`grep -rn "tenant_id IS NULL OR tenant_id" app/Modules` finds the predicate in **one** file.

#### G-4 — Row semantics: identity, outcomes, coalescing merge, units (Wave 2, HELD BY F1)

**Precondition: rebase after F1 merges** (touches `ImportWizardPage.tsx`, `types.ts`, locales).

**This is the hinge lane and it is size L.** r4 folds the **product identity resolver** into it
(G-R52), because the duplicate census must use "the same resolver the writer uses" and r3 had the two
in different lanes with no declared dependency.

**Scope.** D2 + D3's *resolution* half + D10 + RUL-1 + R11's resolver, in the r4 shape (§3.2, §4.2,
§4.4, §4.5.1, §4.13.3). **Files:** **M6a** and **M6b** (same wave, §3.1d); the `ImportRowOutcome`,
`DuplicateBucket`, `DuplicatePolicy` and `ImportWarningCode` enums; the five DTOs + casts of §3.2.3
(`ColumnMappingData`, `ImportJobOptionsData`, `ImportRowSourceData`, `ImportRowWarningData`,
`ImportRowErrorBagData`) and the row-level `ImportErrorCode` cases; new `DuplicateCensusService`; new
`CoalescingAttributeMerger` (signature `(existing, incoming, provided)` — **no `exemptKeys`**); new
`UnitResolver` (consuming `UnitCatalogQueryInterface`); `ImportService::applyColumnMapping` (emit the
`_provided` set), `::validateJob` (census hook), `::getValidRows` (select on `outcome`),
`::executeImport` + `ProcessImportJob` (**re-resolve inside the row transaction, write each terminal
outcome with its own decision — never per chunk**, derive counts from §3.2.2);
`ProductOpeningStockPhase` (selector `outcome = imported`); `ProductService::findExistingProduct`
(the §4.5.1 chain, the arm-3 guard, the barcode **count-not-first** rule, `withTrashed()`) and
`::upsert` (merge branch, skip branch, `unit_id` + canonical `unit` write); `PartnerService`
resolution (`withTrashed()`) and `::upsertWithTypeMerge` (merge); `ImportController::preview`;
`ImportPreviewTable.tsx`, `ImportWizardPage.tsx`, `types.ts`, locales.

**Tests.** Census counts per bucket over a 600-row file (proving it is not a sample) with buckets
persisted per row; **execute re-resolves and wins over a stale bucket**, emitting `preview_drift`
with counts; `skip` writes nothing for matched rows, marks them `duplicate_skipped`, and a **re-run
does not retry them**; `override` updates; **two-layer duplicates** — same SKU at MAIN and ANNEX both
post (`ProductsImportPipelineTest.php:470` green), same SKU **and** same location → later row wins,
loser gets `duplicate_loser` + `duplicate_in_file`; a blank cell never NULLs
`purchase_price`/`barcode`/`unit`/`description` on products nor address/contact fields on partners;
**the three `default_tax_configuration_id` tests stay green** (`:823`, `:869`, `:914`) because each
supplies its governing cell; **the equations of §3.2.2** with
`ProcessImportJobStatusTest.php:163-177` amended deliberately; **the four fault-injection cases of
§4.2.4**, listed there and not restated here; a supplied SKU that misses falls through to **barcode**
but **not** to name; two company-local barcode matches → `barcode_ambiguous` listing candidate SKUs
(brownfield duplicate-barcode fixture); an arm-1/2 hit on a **soft-deleted** product →
`sku_held_by_deleted_product`. **R11:** an exact `kg` resolves and sets `unit_id` **and** normalises
`unit` to `kg`; `" KG "` and `"Kg"` resolve (trim + case-insensitive on **code**) while `"kgs"` and
`"Kilogrammes"` do **not**; **a demo-shaped fixture holding system `kg` and tenant `KG` makes `"kg"` a
coded `unit_ambiguous` row error listing both rows — never an arbitrary pick**; an unknown unit is
`unit_unknown` whose detail carries the supplied value **and** the live accepted-code list; a blank
cell keeps the existing unit on update and, on create, applies the OQ-G-22(ii) default with warning
`unit_defaulted`; a **tenant-added** unit (`sachet`) resolves, proving the list is read live;
**OQ-G-26's `unit_id` backfill** touches only `unit_id IS NULL AND unit IS NOT NULL` rows resolving to
exactly one visible active code, logs its counts, and leaves ambiguous/unknown values untouched.

#### G-2 — SKU generation (Wave 3, HELD BY F1)

**Precondition: rebase after F1 merges** (touches `ImportWizardPage.tsx`).

**Scope.** D3's *generation* half only — the resolver is G-4's. **Files:** M5; new `SkuGenerator`
(constructor-injected); `ProductService::upsert` (call the allocator; **remove `skuFromName` and the
barcode-as-SKU fallback**, `:59-63,282-287`); `CreateProductRequest` (`sku` → nullable) +
`ProductController::store`; the `sku_generated` warning case and the `sku_allocation_exhausted` error
case; `ImportWizardPage.tsx` description for `sku`; `docs/modules/imports.md:147-149,165` corrected —
the matrix calls this "the single most dangerous line of drift for Session G".

**Tests.** Blank SKU → `SKU-000001`, `SKU-000002`, … with `sku_generated` warnings carrying the
values; **two DIFFERENT-name blank-SKU rows get two different generated SKUs**; **two SAME-name
blank-SKU rows resolve to ONE product with ONE generated SKU**, bucketed `existing_name` / `in_file`
and carrying `matched_by_name` (G-4's identity contract, asserted here against the generator); a later blank-SKU **re-import matches by name** and updates; a name with
nothing transliterable (CJK) no longer collapses to the literal `PRODUCT`; a candidate colliding with
an existing product advances the sequence; **exhaustion after 5 attempts** → `sku_allocation_exhausted`;
**concurrent FIRST use on PostgreSQL** — two simultaneous allocations with no sequence row yet produce
distinct values (the `ON CONFLICT DO NOTHING` path); a **manual UI create** racing an import against
the same candidate is caught by the constraint (matched by `ProductIndexNames`, inside a SAVEPOINT)
and retried, never a raw 23505; a candidate held by a **soft-deleted** product is skipped by the
`withTrashed()` check; the UI create path with no SKU returns 201 with a generated value from the
**same allocator**.

#### G-5 — Opening fence + correction service (Wave 3, HELD BY F1)

**Precondition: rebase after F1 merges** (its warning keys live in `locales/*/import.json`). Backend
work may proceed against backend keys only.

**Scope.** D1 in the r4 shape (§4.9) — coordinator-owned transaction, the **fence → reverse → repost →
recompute** order, the reservation precondition, the WAC formula. **Files:** new
`apps/api/app/Modules/Inventory/Application/Services/OpeningCorrectionService.php` exposing
**`correctInCurrentTransaction()`** (asserts `DB::transactionLevel() > 0`, **acquires `ProductCostLock`
itself**, returns an `OpeningCorrectionResult` DTO); `ResetOpeningBalanceService` gains the sibling
`reverseAtLocation()` (the product-wide `reset()` signature and contract are **untouched**);
`BatchStockService` gains `reduceOpeningLot()` (`ensureDefaultBatch()` and the never-shrinks remainder
helper are untouched); the product-cost recompute of §4.9.2b; the `StockReservationService`-backed
precondition; `ProductOpeningStockPhase::correctRow` **owns the outer transaction** and writes the row
outcome inside it. **Migrations:** none.

**Tests.** Opened-but-untouched product + `override` → correction, outcome **`imported`** + warning
**`opening_corrected`**, **contra JE asserted**; opened + **any** downstream movement + `override` →
outcome `opening_locked`, code `opening_locked_has_operations`, with the stock-adjustment direction
and **no** reversal, **no** JE, **no** batch change; opened + `skip` → `opening_skipped_existing`,
nothing written; **the reservation contract of §4.9.2a** — a hold of 8 against a 10 → 5 correction is
refused `opening_locked_reserved` with the RUL-5 message, while a 10 → 9 correction under the same
hold **succeeds**; aggregate-only hold, lot-only hold, and a drifted column-vs-active-sum pair (the
**larger** governs); a **draft order** and an **open counting sheet** do **not** lock (OQ-G-3);
a movement at **another location** of the same company **does** lock (product-scoped, company-wide);
**a correction at ANNEX leaves MAIN's opening movement and stock untouched** (G-R9);
**the cost order** — after correcting ANNEX on a two-location product, `products.cost_price` is the
quantity-weighted blend, so the test **fails if the poster's one-line stamp is the final write**
(G-R48); single-location correction → that location's new unit cost; correction to zero at the only
location → cost cleared; `sum(batch_stocks at location) == stock_levels.quantity` after **lower /
higher / equal** corrections; **GL net effect == new value − old value**; expiry cases — undated lot
filled, dated lot disagreeing → kept + `expiry_conflict_existing_lot`; **fault injection** throwing
after the reversal proves movements, stock levels, JEs, lots and `products.cost_price` are all
unchanged and the row is a coded error, not a warning; a second injection **after** the correction's
inner work but **before** the row write proves both roll back together; `correctInCurrentTransaction()`
called without an open transaction throws; `grep -rEn 'ProductCostLock|Inventory.Domain' app/Modules/Import`
returns nothing (rule 6); the whole phase running with **no `CompanyContext`** and an explicit
currency (rule 20); `ProductsImportPipelineTest.php:470` stays green.

#### G-8 — Locale templates, exact reader, rule-19 ceilings (Wave 3, HELD BY F1)

**Precondition: rebase after F1 merges** (touches `ImportWizardPage.tsx`).

**Scope.** D8, the **exact-lexical XLSX reader and the RUL-4 `.xls` conversion contract** (§4.11), the
**rule-19 ceiling sweep** (§7.3a), and **the template writer the whole program builds on — assigned
here once** (G-R52). **Files:** M7 **with its in-migration backfill** (G-R17); `Country` model
fillable/casts; `CountriesSeeder` (future provisioning only); new `NumberConventionResolver` +
`NumberConventionData` DTO; new `ConventionCopy` service +
`docs/guides/legacy-migration-conventions.copy.yaml` + the `pnpm i18n:conventions` generator and its
CI check (§4.12); new `ExactDecimalXlsxReader` per the §4.11 contract **and the `.xls` float→validated
string path of §4.11(1a)**, with `parse()` split into `parseHeaders()` / `parseRows(type, mapping)`;
new `NumericFieldNormalizerInterface` in `Import/Application/Contracts` + the provider binding +
`ImportService` injecting the interface (§4.11(6));
`ResultWorkbookService::formatValue` (**`float` dropped from the union — this lane only, no other lane
touches this method**); `MigrationWizardService::generateTemplate` + `generateExampleRows`
(`setCellValueExplicit(…, TYPE_STRING)` for money/quantity, the exact sentinel hint row, XLSX cell
comments, third products row with blank SKU + opening stock); `ImportType` numeric rules (§7.3a);
`MigrationWizardController::template` (`?format`, `?preset`); **`ImportServiceProvider`** — the no-arg
`MigrationWizardService` singleton at `:55-57` must change or constructor injection fails (G-R43);
`NumericFieldNormalizer` (convention parameter) and its call sites `ImportService.php:84,122`;
`ImportWizardPage.tsx` download buttons. **Plus R11's template surfaces (§4.13.4):** the `unit` hint
row text + live code list from `UnitCatalogQueryInterface`, and the XLSX `DataValidation::TYPE_LIST`
dropdown with the hidden-`Units`-sheet fallback above 255 characters.

**Tests.** **Every row of the §4.11 case table, one test each** (they are enumerated there and are
not restated here), including the `.xls` conversion cases 16a–16e and the no-float guard double; a
template's money cells are **text**, not numeric; a FR company's CSV template uses `;` and comma
decimals with a BOM and CRLF, a US company's uses `,` and dot decimals, and neither emits a grouping
character; `1,234` parses as **1.234** for a FR company and **1234** for a US company; every other
format parses unchanged; the resolver falls back company → tenant → default; **M7's brownfield
backfill** sets FR/TN comma+`;` and US dot+`,` with no seeder run; the normalizer receives the
convention **explicitly** inside the queued path (rule 20); each added rule-19 ceiling has a
**boundary** test (max-scale accepted) and an **excess-scale rejection** test naming the row; **R11:**
the downloaded template's `unit` hint lists the live codes (a tenant-added `sachet` appears, a
deactivated unit does not), the XLSX carries a `TYPE_LIST` validation on the `unit` column, and a
tenant whose codes exceed 255 characters gets the hidden `Units` sheet.

#### G-9 — Parties presets + Partners retirement (Wave 3, HELD BY F1)

**Precondition: rebase after F1 merges** (touches `ImportWizardPage.tsx`, `types.ts`, locales).

**Scope.** D6 as ruled by RUL-3 (§8). **Files:** `ImportType.php` (deprecate `Partners`;
`customer_category` in the parties optional columns + rule); `ImportService::finalizeImport`
(exhaustive match, no `default` arm) and the deterministic synthetic code (§4.6); `PartnerService`
(write `customer_category`); `MigrationWizardService` (preset templates, order list, metadata, status
keys); `ImportController` (retire `partners` at upload); `MigrationWizardController` (template gate);
FE `types.ts`, `ImportDashboardPage.tsx` (the two preset cards), `ImportWizardPage.tsx`, locales;
**the five `'type' => 'partners'` fixture files** — `ImportJobOptionsTest.php:88,103`,
`ImportPreviewTest.php:101,162,272,326`, `ImportInfrastructureTest.php`,
`MigrationWizardTest.php:200-201`, `ImportTypesTest.php`.

**Tests.** `POST /imports` with `partners` → 422 with the deprecation message; a historical `partners`
job still renders in history; the customers preset template pre-sets `type=customer` and carries
`opening_balance_customer` + `customer_category`; the suppliers preset likewise; a blank
`customer_category` defaults to `business`; **one test per `finalizeImport` case** asserting the phase
ran (the `default => []` regression, `G-F-17`); a partner in both preset files ends `type=both` with
**neither file's address blanked** (the G-4 interaction); re-uploading the same balance-bearing file
with no `code` creates **one** partner, not two (§4.6); the §3 sign convention preserved — positive
customer balance → invoice, negative → credit note for the absolute amount, zero skipped.

#### G-13 — Unit company scoping (Wave 3, backend + UoM FE, NOT held by F1)

**Scope.** Step 2 of RUL-7 (§4.13.2a): shared tenant defaults **plus** per-company additions, with
company rows shadowing shared rows of the same code.

**Files.** **M9**; `Unit` model (`fillable`/`casts` gain `company_id`);
`UnitCatalogQuery` — **the one file that changes the visibility predicate and turns the shadowing
tie-break from a no-op into a real preference** (every consumer inherits it without edits, which is
the whole point of G-12 creating it); `UomController::storeUnit` (stamp `company_id` from
`CompanyContext`, and the six scopings already re-pointed by G-12 need no further change);
`CreateUnitRequest` / `UpdateUnitRequest` gain the missing uniqueness rule —
`Rule::unique('units','code')->where('company_id', …)` — closing the research §1.3 gap where a
duplicate code surfaces as a 500 rather than a 422; `UnitFactory`; `apps/web/src/features/uom/`
picker + its `__tests__/tenantScope.test.tsx`, which becomes a company-scope test.

**Explicitly NOT in scope:** any backfill, any FK re-pointing, any `unit_categories` change, and any
move toward option (a). RUL-7 chose (b) precisely because none of those are needed; a lane brief that
adds them is out of scope.

**Tests.** A company-authored `kg` beside the shared `kg` is **legal** and the **company row
resolves** (import, picker and template all show one entry); a second company in the same tenant does
**not** see the first company's `sachet` but **does** see every shared unit; two shared rows with the
same code are still `unit_ambiguous` (the tier rule of §4.13.3 step 3); the partial indexes reject a
duplicate company code and a duplicate shared code, and the FormRequest returns **422**, not a 500;
M9's census finds zero and the migration repairs cleanly, with the SQLite no-op asserted;
`grep -rn "company_id IS NULL OR company_id" app/Modules` finds the predicate in **one** file; every
pre-existing `products.unit_id` still resolves (no re-pointing happened).

#### G-1 — Error-line export + coded errors (Wave 4, HELD BY F1)

**Precondition: rebase after F1 merges** (touches `ImportWizardPage.tsx`, `types.ts`, locales).

**Scope.** D4 + D9. It **adds cases** to G-12's `ImportErrorCode` (r4.1 — created by G-12, not G-6a;
§3.1d) and G-4's `ImportWarningCode`; it creates neither. **Files:** new `ImportRowExportService` (replacing `FailedRowsExportService`,
whose dead `cleanup()`/`getFilePath()` go with it) — **written in the company's convention using
G-8's `NumberConventionResolver`**, so locale formatting has one owner; `ResultWorkbookService`
(`outcome` predicate, Skipped sheet, job header block — **not** `formatValue`, which is G-8's);
`ImportService`/`ProcessImportJob` (a code on every remaining refusal path);
`ProcessProductImageImport` (coded per-file failures); `ProductOpeningStockPhase` (the
`location_unresolved` split); `ImportController` (`downloadRowsExport`, `reimport_of` on `store`,
**mapping injectivity validation** on `store` and `updateOptions`, and the **one shared portable
warning-row scope** extracted from `countWarningRows()` `:681-691`); wizard completion step; locales.

**Tests.** The **round-trip**: upload → some rows fail → export → re-upload the export with
`reimport_of` → the mapping is pre-applied and those rows import (no such test exists today);
warning-only rows appear with `_status=warning`; headers are the **source** names, unioned across all
rows, with unmapped source columns absent; the CSV starts with a BOM and uses the company's
delimiter and decimal separator; an async (≥100-row) job's export is reachable; a raw `\Throwable`
yields `internal_error` + a translatable message while the raw string stays in `import_error`; the
Imported sheet excludes a validated-but-unexecuted row (today it includes it,
`ResultWorkbookService.php:22-25`); `location_not_supplied` vs `location_code_unknown` each state that
no stock was created; a mapping with two sources on one target is refused **422
`mapping_not_injective`**; the warning-row selection returns the same rows on **SQLite and
PostgreSQL**; a product-image per-file failure carries `image_file_failed`; a `unit_unknown` and a
`unit_ambiguous` row's `_message` each carry the accepted-code list (R11);
**`ImportRowWarningsTest.php:81` amended** — its `assertNull($exportPath)` becomes a non-null path
containing the warning row with `_status=warning`, while the validity/failed-count half stays as-is.

#### G-11 — Point-of-use sign guidance (Wave 4, HELD BY F1)

**Precondition: rebase after F1 merges** — touches `ColumnMapper.tsx`, `ImportWizardPage.tsx`,
`types.ts` and the locales, four of the five hold-set files.

**Scope.** R10 / §4.12, **on the real authorities** (G-R53). **G-11 emits no template, no hint row and
no sentinel — those are G-8's, and G-11 consumes the generated copy.** **Files:**

| R10 surface | What G-11 changes |
|---|---|
| Parties balances | the preview **formats the payload `PartiesRowMapper::toBalancePayloads()` → `balancePayload()` already returns** (`:79-113`) — no second sign implementation, and **no `PartiesRowMapper` work for open items** (r3's regression) |
| AR/AP open items | **extends `ArApOpeningService::getPostPreview()`** (`:431-489`) — which already returns `document_type`, `total` and `open_amount` per row — with the echo text |
| GL openings | **extends `AccountingOpeningService::getPostPreview()`** (`:836-894`) — which already returns `account_code`, `debit`, `credit` per line. **There is no "GL side reader"**; that phrase is deleted |
| FE | `apps/web/src/features/opening-balances/components/BatchPreview.tsx` (`:316-383` renders these payloads), `pages/OpeningBalanceWizardPage.tsx`, and the **generated discriminated-union types** for the three preview variants (they are keyed by the existing `batch_type` discriminator) |
| Import wizard FE | `ColumnMapper.tsx` renders the hint (the W4-1 residual, `:10`); `ImportWizardPage.tsx` renders the explainer; `ImportPreviewTable.tsx` renders the echo column |

Plus `ImportType::explainerKey(): ?string` and `::columnHintKeys(): array<string,string>` (enum-backed
keys, exhaustive matches, no literal strings on the FE — rules 9 + 11);
`ImportController::preview` returns the per-row `interpretation` for balance-bearing types;
`en`/`fr` locales carry the catalog's generated sentences;
`docs/guides/legacy-migration-accounting-conventions.md` gains the back-pointer to the key namespace.
**R11:** the `unit` hint carries the same instruction and live code list, and the preview echoes the
resolved unit per row, flagging `unit_unknown` / `unit_ambiguous` rows with the list (§4.13.4).
**Migrations:** none.

**Tests.** Preview echo per sign for parties — **positive → invoice**, **negative → credit note for
the absolute amount**, **zero → skipped, no payload** (mirroring `balanceOrNull()` at
`PartiesRowMapper.php:86` and the document-type line at `:109`); an open-items **negative amount → row
error**, echoed as such, through `ArApOpeningService::getPostPreview`; a GL row echoing **"in DEBIT of
512 → bank has money"** through `AccountingOpeningService::getPostPreview`, with the bank-inversion
text present verbatim; **`HistoricalOpeningSideReader` used in exactly one parity test**, pinning
payload → staged row → posted document type and magnitude (it reads a **persisted**
`OpeningBalanceImportRow`, `:30-44`, and is not a sign oracle); the exact **sentinel** round trip is
G-8's, but G-11 asserts the hint **text** it renders equals the catalog value; **EN/FR key parity**
(AR deferred per B-7); a **wording-parity test** asserting each EN sentence appears verbatim in the
guide.

#### G-6b — Job payload, detail page, resume (Wave 5, HELD BY F1)

**Precondition: rebase after F1 merges** — this lane changes `ImportController::formatJob`, which F1
owns (`warning_rows` / `warning_summary`).

**Scope.** D11 **plus the FE halves handed over by G-3b and G-6a**. **Files:** `formatJob` additions
(`company_id`, `company_name`, `user_id`, `user_name`, `duration_seconds`, `error_code`,
`skipped_rows`, the `outcome` breakdown, `column_mapping`, `source_purged_at`) behind a typed DTO;
`ImportHistoryPage.tsx` (company scoping, the unattributed badge, filter chips, live refresh, per-row
Resume/Discard); `ImportDashboardPage.tsx` (hide the entitlement-gated card); new
`ImportJobDetailPage.tsx`; the wizard execute step's elapsed time + Cancel affordance and the resume
route; `routes/index.tsx` (+ `routes.test.tsx:231`); `queries.ts` / `importApi.ts` — **`useDeleteImport`
wiring lands here** (`queries.ts:163`), not in G-6a, which has no FE; the FE permission guard aligned
from `moduleKey="settings"` to the `imports.manage` permission; and the **doc-drift block**:
`docs/modules/imports.md` `:66-80`, `:255-270`, `:279-297`, `:302-338`, `:344-378`, `:520-550`,
`:559-601`, `:707-724`, `:783`, `:792`.

**Tests.** The detail page's by-**outcome** and by-**code** breakdown; resume from `/:type/:jobId`
rehydrates to the validation step; a `failed` job renders its `error_message`/`error_code` (today
rendered nowhere) and the completion step shows **no green check**; an **unattributed** job shows the
badge for every company (§3.3); a purged job's source download surfaces the 410 as a sentence, not an
error toast.

#### G-10 — Wizard hygiene (Wave 6, mechanical, Sonnet-class)

**Precondition: rebase after F1 merges** (touches all five hold-set files).

**Scope.** Arabic locale parity (15 → 185 keys), the `wizard.progressAriaLabel` nesting fix,
error-grid pagination + `meta`, dead component removal (`ErrorViewer`, the import feature's own
`ValidationResults`, `getWizardOrder`/`useWizardOrder`, `getMigrationStatus`/`useMigrationStatus`,
`checkDependencies`/`useDependencyCheck` — all zero render sites, Audit C §6.1), the orphaned-key
sweep, the dead `ValidationGrid` `onRowUpdate` prop, and `docs/modules/imports.md:485-518` (the
fix-in-place grid claim) per §1.2. **`ColumnMapper` hint rendering is G-11's and `useDeleteImport`
wiring is G-6b's — neither is here.** **Tests:** Vitest for the paginated grid; the locale JSON parity
check.

### 9.3 File → owning lane

Every production file this program touches, with **one primary owner**. Where a later lane also edits
the file it is listed as a contributor **in dependency order**; a contributor never edits the primary
owner's methods, and no two lanes edit the same file concurrently (their waves differ).

| File | Primary owner | Later contributors (in order) |
|---|---|---|
| `database/migrations/tenant/…enforce_company_scoped_product_skus` (M1) | G-3a | — |
| `…enforce_company_scoped_product_variant_skus` (M2) | G-3a | — |
| `…enforce_company_scoped_partner_vat_numbers` (M3) | G-3a | — |
| `…add_company_and_source_hash_to_import_jobs` (M4) | G-3b | — |
| `…add_lifecycle_columns_to_import_jobs` (M6c) | G-6a | — |
| `…census_companies_without_visible_units` (M8) | G-12 | — |
| `…add_outcome_to_import_rows` (M6a), `…add_error_code_to_import_rows` (M6b) | G-4 | — |
| `…create_sku_sequences_table` (M5) | G-2 | — |
| `…add_number_conventions_to_countries` (M7) | G-8 | — |
| `Catalog/Domain/VariantIndexNames.php`, `ProductIndexNames.php`, `PartnerIndexNames.php` | G-3a | — |
| `Catalog/…/ProductVariantService.php` | G-3a | — |
| `Product/…/CreateProductRequest.php`, `UpdateProductRequest.php` | G-3a | G-2 (`sku` → nullable) |
| `Catalog/…/ProductImageImportService.php`, `Import/…/ProcessProductImageImport.php` | G-3a | G-1 (coded per-file failures) |
| `Import/Presentation/Controllers/ImportController.php` | **G-3b** (`store`, `updateOptions`, `execute` company pin, **`index`**) | G-6a (`execute` claim, `destroy`, `downloadSourceFile`), G-12 (`store` pre-flight), G-4 (`preview`), G-9 (`store` partners 422), G-1 (`downloadRowsExport`, `reimport_of`, injectivity, warning scope), G-6b (`formatJob`) |
| `Import/Presentation/Controllers/MigrationWizardController.php` | G-3b (entitlement) | G-8 (`?format`, `?preset`), G-9 (template gate) |
| `Import/Domain/Enums/ImportType.php` | G-3b (`requiredModule()`) | G-8 (numeric rules), G-9 (deprecate `Partners`, `customer_category`), G-11 (`explainerKey`, `columnHintKeys`) |
| `Import/…/ModuleEntitlementCheck.php` | G-3b | — |
| `Import/Domain/Enums/ImportErrorCode.php` | **G-12** (r4.1 — created here, not G-6a; §3.1d) | G-6a (`worker_lost`), G-4, G-2, G-8, G-5, G-1 (cases only) |
| `ImportErrorDetailData` | **G-6a** | — |
| `Import/…/ImportJobClaimService.php`, `ReapStuckImportsCommand.php`, `PurgeExpiredImportArtifactsCommand.php`, `routes/console.php` | G-6a | — |
| `Import/Providers/ImportServiceProvider.php` | G-6a (routes) | G-8 (the `MigrationWizardService` binding + the normalizer interface binding) |
| `Shared/Contracts/UnitCatalogQueryInterface.php`, `Uom/…/UnitCatalogQuery.php`, `Uom/…/UomController.php` | G-12 | **G-13** (the RUL-7 predicate + tie-break) |
| `…add_company_scope_to_units` (M9), `Uom/Domain/Entities/Unit.php`, `CreateUnitRequest.php`, `UpdateUnitRequest.php`, `apps/web/…/features/uom/` | G-13 | — |
| `Company/…/CompanyController.php` | G-12 | — |
| `Import/Domain/Enums/ImportRowOutcome.php`, `DuplicateBucket.php`, `DuplicatePolicy.php`, `ImportWarningCode.php` | G-4 | G-2, G-5, G-1 (cases only) |
| the five §3.2.3 DTOs/casts | G-4 | — |
| `Import/…/DuplicateCensusService.php`, `CoalescingAttributeMerger.php`, `UnitResolver.php` | G-4 | — |
| `Import/Services/ImportService.php` | **G-4** | G-3b (`createJob`), G-9 (`finalizeImport` exhaustive), G-8 (normalizer call sites), G-1 (coded refusals) — G-3b's edit lands first, in Wave 0 |
| `Import/Application/Jobs/ProcessImportJob.php` | **G-4** (outcome writes) | G-6a (re-verify + CAS, Wave 0), G-12 (units re-verify), G-1 (codes) |
| `Product/…/ProductService.php` | **G-4** (`findExistingProduct`, `upsert` merge/unit) | G-2 (generation branch) |
| `Partner/…/PartnerService.php` | G-4 | G-9 (`customer_category`) |
| `Import/Services/ProductOpeningStockPhase.php` | **G-4** (selector) | G-5 (`correctRow`), G-1 (`location_unresolved` split) |
| `Inventory/…/OpeningCorrectionService.php` (new), `ResetOpeningBalanceService.php`, `BatchStockService.php` | G-5 | — |
| `Import/…/ExactDecimalXlsxReader.php` (new), `SpreadsheetParserService.php`, `NumericFieldNormalizer.php` + its new interface, `NumberConventionResolver.php`, `ConventionCopy.php`, `…conventions.copy.yaml` | G-8 | — |
| `Import/Services/MigrationWizardService.php` | G-8 | G-9 (preset templates) |
| `Import/Services/ResultWorkbookService.php` | **G-8** (`formatValue` only) | G-1 (predicate, Skipped sheet, header block) |
| `Import/…/ImportRowExportService.php` (new, replaces `FailedRowsExportService.php`) | G-1 | — |
| `Document/…/ArApOpeningService.php`, `Accounting/…/AccountingOpeningService.php` | G-11 | — |
| `apps/web/…/import/pages/ImportWizardPage.tsx` | **G-4** | G-2, G-8, G-9, G-1, G-11, G-6b, G-10 (all after F1) |
| `apps/web/…/import/types.ts`, `locales/{en,fr,ar}/import.json` | **G-4** | G-2, G-5, G-8, G-9, G-1, G-11, G-6b, G-10 |
| `apps/web/…/import/components/ImportPreviewTable.tsx` | G-4 | G-11 (echo column) |
| `apps/web/…/import/components/ColumnMapper.tsx` | G-11 | — |
| `apps/web/…/import/components/ValidationGrid.tsx` | G-10 | — |
| `apps/web/…/import/pages/ImportDashboardPage.tsx` | G-9 (preset cards) | G-6b (hide the gated card) |
| `apps/web/…/import/pages/ImportHistoryPage.tsx`, `ImportJobDetailPage.tsx` (new), `queries.ts`, `importApi.ts`, `routes/index.tsx` | G-6b | G-10 (dead-code removal) |
| `apps/web/…/opening-balances/components/BatchPreview.tsx`, `pages/OpeningBalanceWizardPage.tsx` | G-11 | — |
| `docs/modules/imports.md` | G-6b (the drift block + endpoint list + footer) | every lane, for its **own** drift rows only; G-2 `:147-149,165`; G-10 `:485-518` |

**Test files** follow the lane that owns the behaviour they pin; the only shared ones are
`ProductsImportPipelineTest.php` (G-4 amends, G-2/G-5 keep green) and `ProcessImportJobStatusTest.php`
(G-4 amends per §3.2.2).

## 10. Owner questions

Defaults are what the spec implements if the owner says nothing. "If overridden" states the concrete
change. **Every OQ is referenced from the body section that implements it**, and the identifiers run
contiguously OQ-G-1 … OQ-G-26.

| OQ | Question | Default taken | If overridden | Status |
|---|---|---|---|---|
| **OQ-G-1** | Is tenant-wide SKU uniqueness intentional, or should it relax to company scope? (§3.1) | company scope | — | **RULED — RUL-2** |
| **OQ-G-2** | Opening fence: product-scoped or product+location-scoped? (§4.9) | **product, company-wide** | location-scoped: the fence consults movements at the row's location only; a branch that has traded no longer blocks a correction at another branch. G-5 predicate + 2 tests | open |
| **OQ-G-3** | Do non-movement commitments (draft order, stock reservation, open counting sheet) count as "operations"? (§4.9.2a) | **no in general** — the predicate is movement-based — **except an active reservation**, which fences any correction below the held quantity (`opening_locked_reserved`). Implemented in G-5 | yes: `hasDownstreamMovements` gains sibling checks over `stock_reservations` / `inventory_counting_items` / draft `documents`. Larger blast radius (it also fences `ResetOpeningBalanceService` and `postOpening`) | **RULED — RUL-5** (the reservation amendment; the general "no" stands) |
| **OQ-G-4** | Natural key for a parties row with no `code` and no `tax_id`? (§4.6) | bare `name` (today's behaviour), plus a deterministic synthetic code for balance-bearing rows | refuse such rows with `party_key_insufficient`. Cleaner idempotency, rejects the commonest small-business file | open |
| **OQ-G-5** | May a re-import blank a previously-populated column? (§4.4) | **no** — blank never overwrites | a `\N`-style clear token, or per-field stickiness lists. Both add parsing/config surface | open |
| **OQ-G-6** | Pin a job to its company and scope history by company? (§3.3, §5.2) | **yes** — 409 on mismatch, history current-company only + unattributed | keep tenant-wide history with a company **column**; drops the 409 and re-opens silent retargeting | open |
| **OQ-G-7** | Export shape: original source columns, or canonical + saved mapping? (§4.10) | **source columns, reverse-mapped from the saved mapping**; unmapped source columns dropped | persist the pre-mapping row (`import_rows.raw_data` jsonb): full fidelity, at the cost of a migration and ~2× row storage | open |
| **OQ-G-8** | Do warning-only rows belong in the error-line export? (§4.10) | **yes**, with a `_status` column | separate them into a second file/sheet; the wizard then offers two downloads | open |
| **OQ-G-9** | Stuck-job policy: reaper, operator affordance, or both? (§4.7) | **both** | reaper only (no Cancel/DELETE route; the dead hook is deleted instead of wired) | open |
| **OQ-G-10** | Error-code channel: new column + enum, or extend the leading-token convention? (§3.2.1, §4.8) | **column + `ImportErrorCode` enum** | leading-token convention: no migration, but unenforceable and unindexable — no by-code breakdown | open |
| **OQ-G-11** | What counts as "existing" for the duplicate summary — do name-derived matches count? (§4.5.1) | **yes, counted and reported separately** (`matched_by_name`); the name arm is reached **only** when the row supplied neither sku nor barcode | (a) exclude name matches; or (b) let a missed explicit SKU fall through to a name match — which permits a row to rename an existing product | open |
| **OQ-G-12** | Retire the legacy `partners` importer alongside the customers/suppliers work? (§8) | retire `Partners` from the wizard/POST, keep `Parties` as the single writer, split by **presets** | — | **RULED — RUL-3** |
| **OQ-G-13** | R9 field-clobbering: coalescing upsert, or just report it in the preview? (§4.4) | **coalescing**, for products and partners alike | report-only: the preview warns and the upsert keeps blanking | open |
| **OQ-G-14** | Should the customers preset write `customer_category`, and what does a blank cell default to? (§8) | **yes, blank → `business`** (matching the 2026-03 backfill) | blank → `null`: preserves "unknown", but `null` and `individual` stay indistinguishable (`Partner` has `isB2B()` and no `isB2C()`) | open |
| **OQ-G-15** | Ratify the country → decimal/delimiter mapping? (§7.1) | FR/TN/IT/DZ/MA → `,` + `;`; GB/US → `.` + `,`; unlisted → `.` + `,` | different seeded values, or additional countries. Seeder/migration values only, no schema impact | open |
| **OQ-G-16** | SKU generator prefix and format? (§4.5.2) | **per-company prefix, default `SKU-`, zero-padded 6 → `SKU-000001`**, padding grows past 999999, never wraps | a different prefix or width, or an operator-set per-company prefix (adds a settings surface + validation) | open |
| **OQ-G-17** | Reaper threshold N? (§4.7) | **90 minutes on each of the two clocks**, swept every 5 (job `timeout` is 3600 s, so 60 is the longest a live worker can legitimately hold a job; 90 leaves a restart margin). The `claimed_at`-vs-`worker_started_at` question r3 left to this OQ is **not** an owner call and is settled in §4.7: both clocks apply, each to its own state | a lower N reclaims faster but risks killing a slow finalize phase; a higher N leaves the operator staring longer. One threshold governs both clocks unless the owner splits them | open |
| **OQ-G-18** | Retention of uploaded source files and generated exports? (§4.14) | **source files 90 days then purge the FILES; `import_jobs`/`import_rows` rows kept forever**; generated exports ephemeral by construction; **ProductImages ZIP purged immediately** | keep files forever (today's behaviour — uploads are never deleted, so the directory grows without bound); or purge sooner | **RULED — RUL-6** |
| **OQ-G-19** | Should `products.barcode` become tenant-wide unique? (§1.2, §3.1a) | **NO — out of scope.** It has no constraint at any scope today; RUL-2's "barcode = cross-company key" is implemented as a **lookup** concept | yes: a new census-then-refuse migration over every tenant's catalogue plus a duplicate-barcode remediation story. A program of its own | open |
| **OQ-G-20** | An import row whose SKU/VAT is held by a **soft-deleted** record — refuse, or auto-restore? (§3.1c) | **refuse**, coded, "restore or purge it in the UI" | auto-restore: a spreadsheet cell would resurrect a product carrying stock history, movements and possibly sealed fiscal allocations | open |
| **OQ-G-21** | Two distinct products sharing a name, both with no SKU and no barcode, **will merge** into one. Accept? (§4.5.1) | **accept** — today's behaviour via the name-slug SKU, now **visible** (bucket `existing_name`, warning `matched_by_name`) | refuse: a name-only collision becomes a row error demanding disambiguation. Safer, but it rejects the tenant-#1 859-row blank-SKU file this program exists to make work | open |
| **OQ-G-22** | **Unit matching strictness and the blank-cell default on create** (R11, §4.13.3). (i) how strictly is the cell matched? (ii) what does a blank cell do on **create**? (iii) revive the ~90-entry EN+FR alias map? | (i) **trim + case-insensitive, against `code` ONLY** — name/symbol matching is removed so the surfaced list is exactly the accepted vocabulary (§4.13.4), and more than one match **within a visibility tier** is `unit_ambiguous`, never an arbitrary pick; (ii) **`pc` on create**, warning `unit_defaulted`; existing unit kept on update with **no** warning; `unit_default_missing` if `pc` is not visible; (iii) **NO** alias map | (i) strictly case-sensitive on `code` — closest to "exactly as spelled", but `KG` from an Excel autocapitalise becomes a row error; (ii) and (iii) are ruled | **(ii) and (iii) RULED — RUL-7; (i) open** |
| **OQ-G-23** | The `imports:purge-expired` **schedule cadence** (the 90-day window itself is OQ-G-18) (§4.14) | **daily**, `withoutOverlapping()`, with `->onFailure()` logging | hourly (more churn, no benefit — the window is 90 days) or weekly (a purged-file promise the UI keeps up to 6 days late) | **RULED — RUL-6** |
| **OQ-G-24** | How should legacy `.xls` be handled? (§4.11(1)/(1a)) | **ACCEPT via conversion**, with per-cell exactness enforced: money/quantity cells must satisfy the rule-19 ceiling **and** round-trip to the same double, else row error `xls_inexact_value` with "re-save as .xlsx" | reject `.xls` outright (r3's design — simpler, but refuses whole files for a defect that is usually per-cell); or accept it and knowingly round money (rule 19 violation) | **RULED — RUL-4** |
| **OQ-G-25** | **Unit scope (NEW, r4).** (a) company-owned, (b) tenant-shared + nullable per-company additions, or (c) tenant-global? Plus shadowing, sequencing, and the blank-cell default | **(b)**, shadowing **legal with the company row winning**, sequenced as **G-4 + G-12 now / G-13 after**, blank-on-create → `pc` | (a) would cost L (research §5: 6 FK columns re-pointed, 3 through upward joins, a base-unit cycle); (c) leaves units the last operator-editable catalogue bleeding across companies | **RULED — RUL-7** (§4.13.2a, lane G-13) |
| **OQ-G-26** | **Backfill `unit_id` on already-imported products (NEW, r4)?** Products imported before G-4 carry free-text `products.unit` and a NULL `unit_id` (§4.13.1) | **YES**, in G-4: a guarded, logged, forward-only pass over rows where `unit_id IS NULL AND unit IS NOT NULL`, resolving through the **same** resolver and touching only values that match exactly one visible active code. Ambiguous and unknown values are left untouched and counted in the log | NO: those products keep displaying quantities at the fallback precision until an operator edits or re-imports them | open (default yes) |


## 11. Risks, rollback, and staging deploy notes

### 11.1 Per-migration risk

`down()` is a **logged no-op on every file** (§3.1); recovery from a bad deploy is a new migration.
The register (name, lane, wave, guards, test) is §3.1 and is not repeated here — this table carries
**risk only**.

| # | Risk | Recovery |
|---|---|---|
| M1 `products` | The census refuses on a tenant with genuine `(company_id, sku)` duplicates → **`tenants:migrate` aborts for that tenant on deploy**. **Expected clean on any intact database** — the current non-partial `(tenant_id, sku)` unique already forbids such duplicates, deleted rows included, and failed 23505 imports persist no rows (§3.1). The only real sources are **constraint drift** and **manually damaged brownfield data** | Resolve the logged collision groups in that tenant, re-run. The refusal is the safe outcome: nothing was dropped |
| M2 `product_variants` | **SKU only.** The `WHERE deleted_at IS NULL` predicate must be reproduced exactly or the new index rejects rows the old one allowed. SQLite has neither index, so the drop must no-op. The **barcode** index is untouched, so scan safety cannot regress. The constraint-name branches in `ProductVariantService` must change in the same commit or restore-collisions become raw 23505s (§3.1b) | same as M1 |
| M3 `partners` | Same shape as M1, on `vat_number`; rows with NULL `vat_number` are excluded from the census | same as M1 |
| M4 `import_jobs` | Additive + nullable → low. The backfill sets a company **only when all links agree** (§3.3) and leaves mixed/evidence-free jobs NULL by design | re-run: the backfill only touches rows still NULL |
| M5 `sku_sequences` | Additive, new table → low. Its **behaviour** depends on M1 having landed (the catch-and-retry of §4.5.2 matches `products_company_id_sku_unique` by name); the wave order provides that | re-run |
| **M6a** `import_rows` | Additive + an in-migration UPDATE backfill over existing rows (§3.1d). Index on `(import_job_id, outcome)`; rows-per-tenant is bounded by import size, so the brief lock is acceptable. Depends operationally on M4/G-3b for a company-correct census | re-run: each of the three steps (columns, index, backfill) is guarded independently, so a partial repair completes |
| **M6b** `import_rows` | Additive → low. Index on a nullable string column | re-run |
| **M6c** `import_jobs` | Additive → low. Five nullable columns | re-run |
| M7 `countries` | Additive **and self-correcting**: the in-migration backfill (G-R17) sets FR/TN/IT/DZ/MA before the migration returns, so there is no window in which those countries generate US-shaped templates | re-run: the `UPDATE … WHERE code IN (…)` is idempotent |
| **M9** `units` | Adds a nullable column and swaps one unique for two partial uniques. The census before the drop **must** find zero — the shared partition (`unique(tenant_id, code) WHERE company_id IS NULL`) is strictly **stronger** than today's NULL-distinct `unique(tenant_id, code)`, so it can refuse on the §4.13.2 latent duplicate (two `tenant_id = NULL` rows with one code). That refusal is the **desired** outcome: it surfaces corruption before a constraint is dropped | Resolve the logged duplicate rows in that tenant, re-run |
| **M8** `units` (read-only) | **Zero** — it reads and logs. It deliberately does **not** refuse: reference data must never abort a fleet-wide deploy, and G-12's provisioning guarantee plus the coded `units_not_seeded` refusal are what protect the operator | re-run |

M1–M3 are the only files that can abort a tenant's migration run, by design. F-BUG-1's failed inserts
and code-less default locations create no new abort risk. M6a's fleet-wide UPDATE is a lock/duration
risk, not a data refusal.

**Program-level rollback.** Waves are independently revertable except: G-2 and G-4 assume G-3a's
index; G-5 and G-8 assume G-4; G-1 assumes G-4 and G-8; G-6b assumes G-1; G-11 assumes G-8's template
writer and copy catalog. Reverting G-3a after G-2 has shipped would re-expose the cross-company 23505
for generated SKUs — so **G-3a is the one lane that must be considered irreversible** once Wave 3
lands.
### 11.2 Staging deploy notes

- **Push = auto-deploy + auto-`tenants:migrate` across the fleet.** Every migration above states its
  own `hasTable`/`hasColumn`/index-exists guards (§3.1 table), so re-running one against an
  already-correct database is a **no-op**. The stronger word "idempotent" is narrowed to what the
  framework actually provides (G-R45): PostgreSQL runs each migration in a transaction
  (`Migrator.php:426-451`), so a failure **rolls back and can be retried**, and Laravel records the
  migration so it does not run twice at all. The three census migrations are the ones that can
  **abort a tenant's migration run**, by design. Before promoting Wave 0, run the census read-only against staging PG
  (`157.180.71.252:5434`, central `iziposcentral`, tenants `tenant<uuid>`; port churns closed on
  redeploys — reopen via the Dokploy MCP `postgres-saveExternalPort`) and record the group counts per
  tenant in the promotion checklist. A non-zero count is an owner decision, not a lane decision.
- **No seeder rerun is owed (G-R17).** M7 backfills the existing country rows itself and logs a
  census; `CountriesSeeder` carries the same values for **future** provisioning only. Correctness
  never depends on a manual post-deploy action. Confirm the per-tenant census line appears in the
  deploy log.
- **Scheduler (G-6a):** confirm the new `imports:reap-stuck` **and** `imports:purge-expired` entries
  appear in `schedule:list` on staging and that their first runs log per-tenant counts. Under database-per-tenant a command that
  forgets `forEachTenant()` runs on central and silently finds nothing — the exact failure that left
  the fiscal auto-lock dead for ten weeks.
- **Horizon: NO new queue.** Both import jobs already use `onQueue('imports')`
  (`ProcessImportJob.php:66`, `ProcessProductImageImport.php:58`), listed at
  `apps/api/config/horizon.php:209`; the reaper and the purge are **scheduled console commands**, not
  queued jobs, so rule 20's queue-coverage trap (`HorizonQueueCoverageTest`) is not triggered. If any
  lane ever adds an `onQueue('…')`, it adds the config entry in the same commit.
- **POS device version:** unaffected — no device-side contract changes. Gate r1 independently
  confirmed no POS SQLite migration is needed: the device DB is company-selected, local
  product/variant writes key by entity **id** (`apps/pos/src/lib/db/repositories/productRepository.ts:150-231`,
  `variantRepository.ts:101-143`), and the server catalog endpoints are already company-scoped.
- **Units census (G-12 / M8):** after Wave 1 deploys, read the per-tenant
  `units.visibility_census` / `units.empty_for_company` lines. **A non-zero `empty` count is an owner
  decision, not a lane decision** — it means those companies cannot import products with a unit
  column until their catalogue is provisioned, and it is also the evidence OQ-G-25 needs.
- **F1 sequencing:** G lanes touching `formatJob` / `types.ts` / `ImportWizardPage.tsx` /
  `ColumnMapper.tsx` / `locales/*/import.json` rebase after F1 merges (§6).

---

## 12. Verification

### 12.1 Per-lane commands

Run **by path** (never the full suite without permission — `feedback_no_full_test_suite`), from
`apps/api` unless stated. PHPStan needs a live-DB env in a worktree. Listed in wave order.

| Wave | Lane | Commands |
|---|---|---|
| 0 | G-7 | `php artisan test tests/Feature/Import/ImportTypeHttpRoundTripTest.php` |
| 0 | G-3a | `php artisan test tests/Feature/Import/ProductSkuCompanyScopeMigrationTest.php tests/Feature/Catalog/VariantIndexScopeTest.php tests/Feature/Catalog/VariantBarcodeRaceTest.php tests/Feature/Modules/Catalog/Media/ProductImageImportServiceMediaTest.php` |
| 0 | G-3b | `php artisan test tests/Feature/Import/ImportJobCompanyPinTest.php tests/Feature/Import/ImportHistoryQueryTest.php tests/Feature/Import/ImportModuleEntitlementTest.php` |
| 0 | G-6a | `php artisan test tests/Feature/Import/ReapStuckImportsTest.php tests/Feature/Import/ImportJobClaimConcurrencyTest.php tests/Feature/Import/PurgeExpiredImportArtifactsTest.php` |
| 1 | G-12 | `php artisan test tests/Feature/Import/UnitsInvariantTest.php tests/Feature/Uom/UnitCatalogQueryTest.php` |
| 2 | G-4 | `php artisan test tests/Feature/Import/DuplicateCensusTest.php tests/Feature/Import/CoalescingMergeTest.php tests/Feature/Import/UnitResolutionTest.php tests/Feature/Import/ImportRowOutcomeBackfillTest.php tests/Feature/Import/ProcessImportJobStatusTest.php tests/Feature/Import/ProductsImportPipelineTest.php` · `cd apps/web && pnpm test src/features/import` |
| 3 | G-2 | `php artisan test tests/Feature/Import/SkuGenerationTest.php tests/Feature/Product/CreateProductTest.php` |
| 3 | G-5 | `php artisan test tests/Feature/Inventory/OpeningCorrectionServiceTest.php tests/Feature/Inventory/OpeningCorrectionReservationTest.php tests/Feature/Import/OpeningStockFenceTest.php tests/Feature/Import/ProductsImportPipelineTest.php` |
| 3 | G-8 | `php artisan test tests/Feature/Import/LocaleTemplateTest.php tests/Feature/Import/ExactDecimalXlsxReaderTest.php tests/Feature/Import/XlsConversionExactnessTest.php tests/Feature/Import/ImportTypeScaleCeilingTest.php tests/Unit/Import/NumericFieldNormalizerTest.php` · `cd apps/web && pnpm i18n:conventions --check` |
| 3 | G-13 | `php artisan test tests/Feature/Uom/UnitCompanyScopeMigrationTest.php tests/Feature/Uom/UnitCatalogQueryTest.php` · `cd apps/web && pnpm test src/features/uom` |
| 3 | G-9 | `php artisan test tests/Feature/Import/PartiesPresetsTest.php tests/Feature/Import/PartiesImportBalancesTest.php tests/Feature/Import/ImportTypesTest.php` |
| 4 | G-1 | `php artisan test tests/Feature/Import/ImportRowExportTest.php tests/Feature/Import/ImportExportRoundTripTest.php tests/Feature/Import/ResultWorkbookTest.php tests/Feature/Import/ImportRowWarningsTest.php` |
| 4 | G-11 | `php artisan test tests/Feature/Import/SignGuidanceInterpretationTest.php tests/Feature/OpeningBalances/OpeningPreviewEchoTest.php` · `cd apps/web && pnpm test src/features/import src/features/opening-balances` |
| 5 | G-6b | `php artisan test tests/Feature/Import/ImportJobPayloadTest.php` · `cd apps/web && pnpm test src/features/import src/routes` |
| 6 | G-10 | `cd apps/web && pnpm test src/features/import && pnpm lint && pnpm typecheck` |
| every lane | — | `./vendor/bin/pint --test <touched>` · `./vendor/bin/phpstan analyse <touched>` · `php tools/feature-lane-manifest-check.php` |


### 12.2 End-to-end demo script (tenant #1 shape)

Run on a fresh local tenant after Wave 3, and again on staging after promotion. This reproduces the
exact staging shape that produced the F-BUG-1 findings.

1. **Company A, FR/TN country.** Download the products template as **XLSX** and as **CSV** — assert
   `;` delimiter and comma decimals in the CSV, a BOM, and a third example row with a **blank SKU**
   plus `quantity` and `location_code`.
2. **Import the 859-row `model produits.xlsx` with blank SKUs** into company A, main location.
   Expect: 859 imported, 859 `sku_generated` warnings carrying `SKU-000001…SKU-000859`, opening stock
   posted on every row with a cost, **zero** raw SQLSTATE anywhere, and a completion screen that
   states plainly how many rows created stock and how many did not.
3. **Re-import the same file unchanged.** Preview reports `existing_by_name: 859`, `new: 0` (no SKU
   column in the source — §4.5.1's identity contract). Choose **override**. Expect: 859 updated,
   859 `matched_by_name`, opening stock **corrected** where no operations occurred, and
   `opening_skipped_existing` nowhere (policy is override).
4. **Sell one product** at the POS, then re-import again with **override**. Expect: that one row is a
   **row error** `opening_locked_has_operations` with outcome `opening_locked` (counted in
   `failed_rows`, §3.2.2), naming the stock-adjustment path; every other row still corrects with
   outcome `imported` + warning `opening_corrected`; the job completes rather than failing. Then
   **lower** one untouched product's quantity from 10 to 5 by re-import and assert
   `sum(batch_stocks at that location) == stock_levels.quantity == 5` (G-R11).
5. **Re-import a thinner file** (name + sale_price only — no `tax_rate`, no `category_name`, no
   `brand`, no `type`). Expect: `purchase_price`, `barcode`, `unit`, `description` **and**
   `tax_rate`, `default_tax_configuration_id`, `category_id`, `brand_id`, `type` **all preserved** —
   nothing blanked and nothing silently re-derived, because no governing cell was supplied (§4.4.1,
   G-R34). Then re-import with `tax_rate` supplied at a rate no configuration states and assert the
   configuration id is **cleared** — the narrow re-derivation that stays.
6. **Create company B in the same tenant** and import the **same catalogue**. Expect: 859 created in
   B, product count in A unchanged, **no 23505**. Switch to A mid-wizard and try to execute → 409
   `IMPORT_COMPANY_MISMATCH`.
7. **Create a second location in company A** and import an opening-stock file for it. Expect:
   **product count unchanged**, `stock_levels` rows added for the new location, no new product rows.
   Then import a **single file listing the same SKU at both locations** and assert **both** openings
   post (§4.2.1); repeat a location within one file and assert the later row wins with
   `duplicate_in_file` on the loser.
8. **Import a customers file with EU decimals** (`1.234,56`, `;` delimiter) via the customers preset:
   the step shows the **sign explainer** and the preview **echoes each row's meaning**
   ("−300.000 → customer credit note, 300.000 on 411"); `type` never appears in the mapping step,
   `customer_category` blank → `business`, every mapped contact/address field lands on the partner, a
   positive `opening_balance_customer` becomes a historical **invoice** and a negative one a **credit
   note** for the absolute amount, and a zero row is skipped. Re-upload the same file → **no second
   partner**. Then **correct one partner's name** (VAT unchanged, code blank) and re-upload → still
   **one** partner, name updated (G-R14).
9. **Import a GL opening file:** the step shows the bank-inversion warning verbatim, and the preview
   echoes "5 000.000 in DEBIT of 512 → bank has money". A negative in either column is a row error.
10. **Import one product with a blank `unit` cell** and confirm it is created with **`pc`** and a
   `unit_defaulted` warning in the result report; re-import it thinner with the `unit` cell still
   blank and confirm the unit is **kept with no warning**. Then **download both templates** and
   confirm the `unit` hint lists the live codes with the
   "enter exactly as spelled" instruction, that the XLSX `unit` column carries a dropdown of those
   codes, that the `#` hint row is present in the CSV, that re-uploading
   that untouched CSV **does not create a bogus row** (the parser skips it), and that the XLSX money
   cells are **text** carrying exact decimals.
11. **Break 20 rows deliberately** (bad tax rate, unknown location code, **a `Kilogrammes` unit**, and
   — on a demo-shaped tenant — **a `kg` that matches both the system and the tenant row**, expecting
   `unit_ambiguous`),
   import, then **export the
   error lines** as CSV. Assert: the operator's **own** header names, `_status`/`_code`/`_message`
   columns, warning-only rows included, a BOM. Fix them in Excel, **re-upload via the export's
   re-import link** — mapping is pre-applied and the 20 rows import.
12. **Kill the worker mid-import** (`horizon:terminate` during a ≥100-row run). Within 90 minutes the
    reaper flips the job to `failed` with `worker_lost`; the wizard stops polling and shows the
    reason; the history detail page shows it. Then **Discard** an abandoned `validated` job and assert
    the job, its rows and its uploaded file are gone.
13. **History page:** paginate past 20, filter by `validated`, confirm company and operator columns
    and the by-code breakdown on the detail page, and download all three artefacts.
14. **Arabic locale:** switch to `ar` and walk the wizard end to end — no raw key strings, no English
    fallbacks in chrome, error codes translated. (R10's sign copy is **EN/FR only**; AR falls back to
    EN until B-7 — that is expected, not a defect.)
15. **Module gating:** on a tenant **without** the CompositeItems module, the composite-items card is
    absent and a direct `POST /imports` with `type=composite_items` returns **403** (G-R20).
16. **Concurrency:** fire two `POST /imports/{id}/execute` at the same sub-100-row job — one succeeds,
    one returns **409 `IMPORT_ALREADY_STARTED`**, and every row is processed exactly once; repeat
    above the async threshold (one 202, one 409) and redeliver the worker message (second delivery
    exits without reprocessing) (G-R38).
17. **`.xls` conversion (RUL-4):** upload the same catalogue saved as `.xls` → it **imports**, with
    money cells persisting the exact in-scale decimals; then upload an `.xls` whose price cell holds a
    float artefact → that **row** (not the file) fails with `xls_inexact_value` and the "re-save as
    .xlsx" remedy, and the `.xlsx` save of the same file imports every row.
18. **Reservation fence:** reserve 8 of a product's 10 opening units, then re-import it at quantity 5
    with **override** → row error **`opening_locked_reserved`** naming the held quantity, and stock,
    lots and GL unchanged (G-R29).
19. **Retention:** age a completed job's source past 90 days, run `imports:purge-expired`, and assert
    the file is gone, the rows survive, and the source download returns **410 `source_purged`**
    (G-R41).

---

## 13. Gate adjudication

Three adversarial gates have run, all **REWORK**, all findings **ACCEPTED**. The tables below state
**the contract as it now stands** — not the delta of the round that produced it — because a table that
describes a superseded shape is worse than no table (G-R55).

Gate records: `docs/superpowers/reviews/2026-08-29-imports-hardening-spec-codex-review-r1.md`
(`G-R1..G-R24`), `…-r2.md` (`G-R25..G-R45`), `…-r3.md` (`G-R46..G-R56`).

### 13.1 Gate r1 — G-R1..G-R24, final contract

| Finding | The contract now | Where |
|---|---|---|
| G-R1 | Variant **SKU** re-scoped to `(company_id, sku)`; `product_variants_tenant_barcode_unique` untouched; `products.barcode` gets no constraint (OQ-G-19); "barcode = cross-company key" is a **lookup** concept | §3.1a, §4.5.1 |
| G-R2 | One constant class per table for index names, introduced in the migration's own commit; create- and restore-collision → 422 against post-migration names; `VariantBarcodeRaceTest.php:92-106` moved to the constant | §3.1b, G-3a |
| G-R3 | **Lifetime** uniqueness; `withTrashed()` on lookups, validators and the generator pre-check; deleted holders are coded row errors; FormRequests gain `company_id` and drop the soft-delete exclusion | §3.1c, G-3a (UI path) + G-4 (row path) |
| G-R4 | The "rule-19 clean" claim is withdrawn. Exact-lexical XLSX reader; `.xls` **accepted with per-cell exactness** (RUL-4); templates bind money as `TYPE_STRING`; the no-float guard runs against an **injected interface** | §4.11, §7.3, G-8 |
| G-R5 | `import_rows.outcome` is the authority; the preview bucket is advisory and execute re-resolves inside the row transaction; counts derive from `outcome` | §3.2.2, §4.2.2, §4.2.4 |
| G-R6 | **Two-layer** semantics: master attributes coalesce; stock and placement side effects key on `(product_id, location_id)`; `duplicate_in_file` fires only on a repeated product **and** location | §4.2.1 |
| G-R7 | **One identity contract**: with no SKU and no barcode, the normalized name within the company **is** the identity | §4.5.1, OQ-G-21 |
| G-R8 | `INSERT … ON CONFLICT DO NOTHING` then `SELECT … FOR UPDATE`; advisory pre-check; catch **only** `products_company_id_sku_unique` inside a SAVEPOINT, bounded at 5 → `sku_allocation_exhausted`; lock ordering documented | §4.5.2, G-2 |
| G-R9 / G-R10 / G-R11 | The import never calls the product-wide `reset()`. One Import-owned transaction; the Inventory seam acquires the cost lock itself and runs **fence → reverse+reconcile → repost → recompute WAC**; lots may shrink through `reduceOpeningLot()`; any failure rolls everything back and the row is a coded error, never a warning | §4.9.2, §4.9.2a/b, G-5 |
| G-R10a | `HistoricalOpeningSideReader` reads a **persisted** row and is not a sign oracle — it appears in exactly one parity test | §4.12, G-11 |
| G-R12 | Coalescing lives at the row-mapping layer with a `_provided` mask and a **governing-cell** matrix. There is **no** computed-field exemption and no `exemptKeys` parameter | §4.4.1, §4.4.2 |
| G-R13 | Warning enum exhaustive and enum-typed at the emitter; every JSONB column has a DTO/cast with its exact serialized shape; product-image failures coded | §3.2.1, §3.2.3, §4.8 |
| G-R14 | Identity resolved `code → VAT → name` **before** any synthetic code; a found partner keeps its own code; a new balance-bearing row derives a stable code from company+VAT (else company+name, limitation stated) | §4.6 |
| G-R15 | M4's backfill is evidence-based; mixed evidence stays NULL; NULL jobs are shown to **all** companies with an "unattributed" badge | §3.3, §5.2 |
| G-R16 | `ProcessImportJob` already carries `companyId`/`tenantId` — preserved and pinned. The real queued gap is `ProcessProductImageImport`, closed in G-3a | §5.2, G-3a |
| G-R17 | M7 backfills existing country rows **inside** the migration with a logged census; no seeder rerun is owed | §7.1, §11.2 |
| G-R18 | The mapping is validated **injective** (422 `mapping_not_injective`) so the reverse mapping exists; one shared portable warning-row scope | §4.10, G-1 |
| G-R19 | G-6 split into G-6a/G-6b; every held lane carries an explicit rebase precondition; `ColumnMapper.tsx` is in the hold set | §6, §9 |
| G-R20 | Per-type module entitlement through one `ModuleEntitlementCheck` on **both** controllers and **every** surface exposing the job, including `updateOptions`, `errors` and `error-summary` | §5.4, G-3b |
| G-R21 | The controller claims; the worker re-verifies; **all** terminal transitions are compare-and-set; there is **no** ownership token and a failed job is never resumed | §4.1.1, §4.7, G-6a |
| G-R22 | ProductImages passes a **relative** storage key everywhere; its ZIP is purged immediately by documented exception (RUL-6) while other sources follow the 90-day policy | §4.14, G-3a, G-6a |
| G-R23 | The migration rationale is corrected: on an intact database a collision is impossible; census-then-refuse is retained as mandatory | §3.1 |
| G-R24 | The "Deliberately NOT taken" list is adopted | §8, §11.2 |

### 13.2 Gate r2 — G-R25..G-R45, final contract

| Finding | The contract now | Where |
|---|---|---|
| G-R25 | Two-pass mapped parse (`parseHeaders` / `parseRows(type, mapping)`); sheet = the workbook's **active tab**, else the first visible; the full cell-kind table incl. `t="n"` and run aggregation; money-column formula and date-styled refusals; `TYPE_STRING` template binding; an injectable normalizer for the guard test. **RUL-4 replaced the `.xls` ban with per-cell exactness** | §4.11, G-8 |
| G-R26 | Terminal outcomes commit with their decision; `failed` after rollback in its own statement; **no** per-chunk outcome write anywhere | §4.2.4, G-4 |
| G-R27 | M6 → M6a/M6b (G-4) + M6c (G-6a), each immutable, each in its owner's wave; the earliest-lane rule generalises it | §3.1, §3.1d |
| G-R28 | Import owns the outer transaction; the Inventory seam is public **and acquires `ProductCostLock` itself**; the order is fence → reverse → repost → recompute; `cost_price` is the qty-weighted WAC across all remaining active openings | §4.9.2, §4.9.2b |
| G-R29 | An active reservation is a hard precondition at aggregate **and** lot level, with the RUL-5 message; the rule is stated once | §4.9.2a |
| G-R30 | Both controllers, every job-exposing surface | §5.4, G-3b |
| G-R31 | R10 lands on the real authorities: `PartiesRowMapper` payload, `ArApOpeningService::getPostPreview`, `AccountingOpeningService::getPostPreview`, `BatchPreview.tsx`, `OpeningBalanceWizardPage.tsx` | §4.12, G-11 |
| G-R32 | Exact positional sentinel `#__AUTOERP_HINT_V1__` at physical row 2 column 1; nothing else is ever skipped | §4.12c, §7.3 |
| G-R33 | One tracked copy catalog; backend `ConventionCopy`; FE keys generated with a CI equality check; guide parity by containment | §4.12, G-8 |
| G-R34 | Governing-cell provenance replaces the blanket exemption; the `part` default is create-only | §4.4.1 |
| G-R35 | Company set only on unanimous evidence, else NULL; one all-company unattributed policy | §3.3, §5.2 |
| G-R36 | Placement identity is `(product_id, location_id)`; the path is the value moved | §4.2.1 |
| G-R37 | Barcode arm 2 **counts** and refuses `barcode_ambiguous` above one | §4.5.1 |
| G-R38 | Controller claim + worker re-verify, extended by G-R50 into two clocks and CAS | §4.1.1, §4.7 |
| G-R39 | Column-level DTOs + casts with the **exact** serialized shapes and legacy-hydration fixtures | §3.2.3 |
| G-R40 | Full rule-19 ceiling sweep with boundary + excess-scale tests; G-7 fixtures in scale; closes LEDGER D-T9-6 | §7.3a, G-8 |
| G-R41 | `imports:purge-expired`, 90 days, rows kept, 410 `source_purged`, ProductImages exception (RUL-6) | §4.14, G-6a |
| G-R42 | Superseded by G-R52: the graph is re-cut and waves are derived from the dependency closure | §6.1, §9.1 |
| G-R43 | `ImportServiceProvider` is G-8's for the binding; §7.4's process map is correct | §7.4, G-8 |
| G-R44 | `VariantBarcodeRaceTest.php:92-106` in G-3a's literal-name census | §3.1b, G-3a |
| G-R45 | Migration count and forward-only policy reconciled: **ten files, `down()` a logged no-op on every one** | §3.1 |

### 13.3 Gate r3 — G-R46..G-R56 adjudication (all ACCEPTED)

| Finding | Sev | What r4 changed | Where |
|---|---|---|---|
| **G-R46** | BLOCKER | **One typed state model.** `opening_corrected` is a **warning** on an `imported` row, never an outcome. The five terminal outcomes and the equations `processed = imported + duplicate_skipped + duplicate_loser + failed + opening_locked = total`, `successful = imported`, `failed = failed + opening_locked`, `skipped = duplicate_skipped + duplicate_loser` are stated **once** in §3.2.2 and referenced everywhere. Terminal status uses the existing `ImportStatus` cases only — **verified `ImportStatus.php:9-14` has no `completed_with_errors` and none is invented**. "Write outcomes per chunk" is **deleted** from G-4. The four fault tests are listed once in §4.2.4 | §3.2.2, §4.2.4, G-4 |
| **G-R47** | BLOCKER | Sheet selection is the workbook's **`activeTab`** (else the first visible); the invented wizard-recorded sheet is **deleted**. `t="n"` and rich-text run aggregation added to the cell table. The guard is made implementable: new `NumericFieldNormalizerInterface` in `Import/Application/Contracts`, bound in `ImportServiceProvider`, injected into `ImportService`; the test binds a double that **throws on `is_float`**. All eighteen cell-kind/multi-sheet cases are enumerated as G-8 tests. `TYPE_STRING` binding kept; the `.xls` decision was superseded by **RUL-4** | §4.11, G-8 |
| **G-R48** | BLOCKER | `correctInCurrentTransaction()` acquires `ProductCostLock` **internally** — Import touches no Inventory domain class (rule 6, asserted by a grep test). One order: **fence → reverse+reconcile → repost → recompute WAC**, with the poster's line-cost stamp explicitly **not** final and a test that fails if it is. The reservation rule is stated once; G-5's contradictory "reservation does not lock" line is **deleted** | §4.9.2, §4.9.2a, G-5 |
| **G-R49** | BLOCKER | Resolver is deterministic on today's schema: exact **code** match, case-insensitive, over **active visible** rows; **more than one match → `unit_ambiguous`**, never a first-row pick. Name/symbol matching removed so the surfaced list **equals** the accepted vocabulary. Job-level `units_not_seeded` at upload and re-verified by the worker. New lane **G-12** owns the provisioning guarantee, the brownfield census (**M8**) and the single visibility predicate (`UnitCatalogQuery`). **OQ-G-25 was put to the owner during this round and RULED (RUL-7)**, so §4.13.2a now carries the final two-step scope contract instead of a substitution placeholder: option (b), shadowing legal with the company row winning, `UnitCatalogQuery` as the single predicate home, and a follow-on lane **G-13**. **OQ-G-26** (`unit_id` backfill) added | §4.13, §4.13.2a, G-12, G-4, G-13 |
| **G-R50** | BLOCKER | **Two clocks** (`claimed_at` while `worker_started_at IS NULL`; `worker_started_at` once started), all terminal transitions **compare-and-set** with an affected-row check, **no ownership token**, and the one-shot invariant pinned. M6c moved into **G-6a's Wave 0**; the earliest-lane ownership rule is stated once and gives `ImportErrorCode` to G-6a. Reaper-vs-late-start and reaper-vs-terminal-write tests added | §3.1d, §4.1.1, §4.7, G-6a |
| **G-R51** | MAJOR | Exact serialized shape per column, from the code: `data` = string map + `_provided` + nested `_results` (`ImportService.php:473-485,525-549`); `errors` = a **keyed bag** `{field: [messages]}` → `ImportRowErrorBagData` (`ImportRow.php:16-20,62-70`); `warnings` = list of `{code, detail}`. Legacy-hydration fixtures per historical shape. The warning DTO/cast/enum/emitter are **atomically G-4's**; later lanes add cases only | §3.2.3, §3.1d |
| **G-R52** | BLOCKER | Ownership re-cut, then waves **derived from the closure**: `ImportController::index` → **G-3b only**; the **product resolver folds into G-4** and G-2 becomes generator-only depending on G-4; **G-8 moves before G-1** so locale-aware export formatting has one owner; `formatValue` float removal → G-8 only; `useDeleteImport` → G-6b only; G-8 and G-11 declare their G-4/G-1 dependencies. §9.1 carries the dependency table and the derived wave list; §9.3 carries a **file → owning lane** table; §6.1 recomputes the startable set from the closure **and** the F1 hold | §6.1, §9.1, §9.3 |
| **G-R53** | MAJOR | G-11 rewritten to §4.12's authorities: Parties formats the `PartiesRowMapper` payload; AR/AP extends `ArApOpeningService::getPostPreview` (`:431-489`); GL extends `AccountingOpeningService::getPostPreview` (`:836-894`); FE owns `BatchPreview.tsx`, `OpeningBalanceWizardPage.tsx` and the generated union types. **"GL side reader" and the `PartiesRowMapper`-for-open-items text are deleted.** G-8 alone emits template/hint/sentinel | §4.12, G-11 |
| **G-R54** | MAJOR | "**Ten** migration files" (M8 added by G-12). `down()` is a **logged no-op on every file**, so the forward-only label and the rollback rows agree. M6c is in G-6a's wave. §3.1's register gives each file its lane, wave, guards, `down()` and pinning test; M4/M6a/M7 state that backfill and index repair run **independently of column creation** | §3.1, §3.1d, §11.1 |
| **G-R55** | MAJOR | §13 rewritten from the **final** body: 13.1 and 13.2 now state the current contract rather than the round's delta, and 13.4 reconciles every row gate r3 marked PARTIAL/NOT CLOSED | §13 |
| **G-R56** | MINOR | Header is **DRAFT r4**; the lifecycle graph is regenerated from the claim contract (no "async execute writes pending"); §7.3 names the exact sentinel; G-10's handoff says **G-6b**. Sweep run for `r2`, `per chunk`, `shared claim`, `started_at`, `# hint` and stale `G-6a` ownership | header, §4.1, §7.3, G-10 |

### 13.4 Closure of every row gate r3 marked PARTIAL or NOT CLOSED

| Row | r3 status | Closed by |
|---|---|---|
| G-R25 (r2) / G-R4 (r1) | NOT CLOSED | G-R47: active-tab selection, `t="n"`, run aggregation, injectable normalizer; wizard-sheet idea deleted |
| G-R26 (r2) / G-R5 (r1) | PARTIAL | G-R46: per-chunk write deleted from G-4; one equation set in §3.2.2 |
| G-R27 (r2) | PARTIAL | G-R50/G-R54: M6c in G-6a's Wave 0; `ImportErrorCode` owned by G-6a; M6a/M6b in G-4's wave |
| G-R28 (r2) / G-R10 (r1) | PARTIAL / NOT CLOSED | G-R48: lock inside the seam, order fixed to repost-before-recompute, cost-order test |
| G-R29 (r2) / G-R11 (r1) | PARTIAL | G-R48: the reservation rule stated once; G-5's contradictory line deleted and replaced by the allowed/refused pair of tests |
| G-R31 (r2) | PARTIAL | G-R53: G-11 rewritten onto the real authorities and files |
| G-R34 (r2) / G-R12 (r1) | PARTIAL | `exemptKeys` and "computed-exempt" deleted from §4.4, G-4 and §13.1; the merger signature is `(existing, incoming, provided)` |
| G-R38 (r2) / G-R21 (r1) | PARTIAL | G-R50: two clocks, CAS on every terminal write, no token, one-shot invariant |
| G-R39 (r2) / G-R13 (r1) | PARTIAL | G-R51: exact shapes + legacy fixtures; warning channel atomically G-4's |
| G-R42 (r2) / G-R19 (r1) | NOT CLOSED | G-R52: single ownership, dependency table, derived waves, file→lane table |
| G-R45 (r2) | PARTIAL | G-R54/G-R55: ten files, uniform no-op `down()`, §13 rewritten |
| G-R9 (r1) | PARTIAL | G-R48 (order + boundary) and the reservation test pair |
| G-R20 (r1) | closed in r3, extended here | `updateOptions`, `errors`, `error-summary` added to the gated surfaces (the rule-12 note in gate r3's sweep) |
| G-R22 (r1) | PARTIAL | RUL-6 makes the retention split an owner ruling, not a spec choice |
| G-R23 (r1), G-R2, G-R6, G-R15, G-R16 | CLOSED / PARTIAL | carried unchanged into §13.1's final-contract rows |

**Items gate r3 explicitly left open, and how r4 treats them:** the `opening_locked` counting question
is **decided** (it counts as failed, §3.2.2, on the orchestrator's ruling); the `cost_price`
weighted-average question is **decided** (recompute — it is the only value true of the product as a
whole); OQ-G-3/18/23/24 are now **RULED** (RUL-4/5/6); OQ-G-19/20/21/22/25/26 remain owner calls.

**Gate r3's "Deliberately NOT taken" list is honoured in full:** no unit re-scoping migration, no
shadowing policy chosen on the owner's behalf, no objection to the M6 split or M6a's mapping order,
no second sign implementation, no POS migration, no new Horizon queue, no second Parties writer, no
clear token, no interactive mid-run prompt, no contacts import, no B-18 expansion.

### 13.5 Post-gate reconciliation

| Round | Change | Where |
|---|---|---|
| r4.1 (orchestrator) | G-12 enum ownership reconciled with dispatch order; no design change | §3.1d, §9.1, §9.2 (G-6a, G-12) |

---

*Prepared by Session G, 2026-08-29, against `dev` @ `a4ceeb0f5`. Phase 1 record:
`docs/superpowers/audits/2026-08-29-imports-hardening-gap-matrix.md`; unit-scoping research:
`docs/superpowers/research/2026-08-29-unit-scoping-tenant-vs-company.md`; gate records
`…-codex-review-r1.md`, `…-r2.md`, `…-r3.md` in `docs/superpowers/reviews/`. This spec is **r4** and
is not authorization to write code — gate r4 runs first.*
