# Session I — adversarial gate r3: lane briefs I-1 / I-2 (r3) + docs commit `02d4c67e3`

**Reviewer:** tenancy-authz-reviewer (Opus, adversarial, code-grounded)
**Date:** 2026-08-29
**Prior rounds:** `docs/superpowers/reviews/2026-08-29-session-i-briefs-and-rules-gate-r1.md`, `…-gate-r2.md`
**Artifacts reviewed (all revised):**
1. `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I1-onboarding-campaign-BRIEF.md` (r3)
2. `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I2-fresh-tenant-guards-BRIEF.md` (r3)
3. docs commit `02d4c67e3f5adf8169bcc4feb1118df86aece996` in `.worktrees/i-docs` (branch `docs/session-i-process-hardening`, parent `cdc54f9f4`) — 16 files, **513 insertions / 2 deletions**; the r2→r3 delta is exactly 3 hunks (`git diff 0e33e79bf 02d4c67e3`)

**Tree state at review time.** Local `dev` HEAD = **`c3cb8261e`** (unchanged since r2). The main checkout
`/Users/houssamr/Projects/syneriva/apps/erp` is **mid-merge**: `git rev-parse MERGE_HEAD` → `4ee68be5f`
("G-3a company-scoped SKU / variant SKU / partner VAT uniqueness"), and `git status --porcelain` reports
`UU .github/workflows/ci.yml` — an **unresolved conflict on the exact file I-2 must append to**. G-3a is
therefore NOT on dev (`git ls-tree HEAD apps/api/database/migrations/tenant/ | grep company_scoped_product_skus`
→ empty; `git show HEAD:.github/workflows/ci.yml | grep -c ProductSkuCompanyScopeMigrationTest` → 0) but is
landing imminently. This materially affects I-2 (see I2-R3-02).
Both lane worktrees are at base `cdc54f9f4` with dependencies pre-provisioned as the briefs claim
(`.worktrees/i1-campaign/apps/web/node_modules` and `.worktrees/i2-fresh-tenant-guards/apps/api/vendor/autoload.php`
both exist) — verified.

---

## VERDICTS

| Artifact | Verdict |
|---|---|
| **I-1 brief r3** (onboarding campaign) | **CHANGES-REQUIRED** — 1 BLOCKER, 3 MAJOR, 4 MINOR (all brief-text) |
| **I-2 brief r3** (fresh-tenant guards + ratchet) | **CHANGES-REQUIRED** — 1 BLOCKER, 1 MAJOR, 2 MINOR (all brief-text) |
| **docs commit `02d4c67e3`** | **CHANGES-REQUIRED** — 2 MINOR only (one-line fixes; does not block lane dispatch) |

**One line before dispatch:** I-1 states the hash contract one level too shallow — `canonical_bytes` is the
canonicalisation of the **15-key wrapper** `{business_date, chain_context, company_id, event_time_device,
event_type, event_version, operator_id, payload, previous_hash, reference_document_id, reference_event_id,
sequence_number, signature_version, tenant_id, terminal_id}`, **not** of the payload string the goldens contain
(`FiscalEventEngine.ts:620-637`, `StrictCanonicalParser.php:78-95`); and I-2's designated non-green channel does
not exist because `tenants:run` **discards the child exit code** and its `--option=` parser requires `key=value`
(`vendor/stancl/tenancy/src/Commands/Run.php:33-56`, `docs/handoff/RUNBOOK-orphaned-shift.md:130-132`).

---

# A. r2 finding resolution — strict

## I-1 (I1-R2-01..09)

| r2 finding | Status | Resolving line / evidence |
|---|---|---|
| **I1-R2-01** wrong hash file + unbuildable import graph | **RESOLVED** | I-1 `:63-64` — names `canonicalCore.ts` + `FiscalEventCanonicalEncoder.ts` only, explicitly rejects `hashService.ts` as "the LEGACY receipt chain", mandates rewriting `@/` imports to relative, forbids vendoring the payload builders with the 4133-line / device-SQLite reasons. **Sufficiency independently PROVEN** — see B(i)-1. (Residual: the contract sentence is wrong one level up → **I1-R3-01**.) |
| **I1-R2-02** golden test runs in no runner | **RESOLVED** | I-1 `:65` — option (b) taken: leg **L0a**, first in serial order, network-free, own ledger row; the dead-vitest option is explicitly rebutted with `apps/web/vitest.config.ts:12` (re-verified: `include: ['src/**/*.{test,spec}.{ts,tsx}', 'tools/**/*.{test,spec}.{ts,mjs}']` — `e2e/**` still uncollected). Verification step 3 (`:94`) requires the L0a PASS. |
| **I1-R2-03** terminal/genesis prerequisite; no `CASH_COUNT`; wrong payload pointer | **RESOLVED** | I-1 `:57` names `POST /api/v1/pos/terminals/web` (`POS/routes.php:60`), `/pos/terminals` (`:53`), `/pos/terminals/claim` (`:56`), the genesis rule and `OutboxIngestor.php:487-519, 682-708`. `:60` pre-declares L9 `NOT_SCRIPTABLE` and cites the real key-set anchors. Re-verified: `FiscalEventType.php:9-43` has **no** `CASH_COUNT`; `SESSION_OPEN_PAYLOAD_KEYS` at `FiscalEventEngine.ts:1317`, `SESSION_CLOSE_PAYLOAD_KEYS` `:1374`, `Z_REPORT_PAYLOAD_KEYS` `:1405`. (Residual gaps → **I1-R3-02/03**.) |
| **I1-R2-04** "Settings → Companies" does not create | **RESOLVED** | I-1 `:51` — company switcher → `/company-onboarding`, "Settings → Companies only EDITS", "`AddCompanyModal.tsx` is rendered by nothing", both recorded as findings. Re-verified: `CompanySelector.tsx:57-60` `handleAddCompany()` → `navigate('/company-onboarding')`; route `src/routes/index.tsx:544`; `AddCompanyModal` importers are only the two barrels, the `features/company` re-export and two test files. |
| **I1-R2-05** `rows[].repository_code`, bank supported | **PARTIAL** | I-1 `:55` names the 4-call sequence, `rows[].repository_code`, the "take only the shape, not the call" caveat on `OpeningCashFloatSeedsRepositoryTest`, and drops the bank hedge. But it omits the two REQUIRED companions of that column — the batch `type` value and `rows[].account_code` + the GL-account-match rule → **I1-R3-02**. |
| **I1-R2-06** `unit` vs `unit_id` | **RESOLVED** | I-1 `:53` — asserts the `unit` varchar against the file, asserts `unit_id IS NOT NULL` for coded rows and books the failure as ONE product finding. **Scriptability confirmed:** both fields are exposed on the API (`ProductData.php:36-37, 87-88`), so the no-`psql` rule is honoured. |
| **I1-R2-07** stale base | **RESOLVED** | I-1 `:4` — "First action: `git merge dev`", with the G-12 / `CompanyController::store()` consequence spelled out and a STOP-on-conflict instruction. |
| **I1-R2-08** name the goldens and their keys | **RESOLVED** | I-1 `:64` — both filenames, the 4 keys and the `_comment` worked example. Re-verified byte-for-byte: both files exist; keys are exactly `_comment, event_type, event_version, expected_canonical_string, expected_sha256_hex`. |
| **I1-R2-09** (NOTE — no silent 403) | n/a | Re-verified: `Fiscal/routes.php:52-53` `can:pos.operate_terminal`; the POS group tuple `POS/routes.php:42` is rule-12 compliant; login tokens carry the `tenant:<uuid>` ability (`AuthController.php:306-309`, `:504-508`) so `EnforceTokenTenantClaim` passes. No permission work owed. |

## I-2 (I2-R2-01..08)

| r2 finding | Status | Resolving line / evidence |
|---|---|---|
| **I2-R2-01** no DB lifecycle on the live-schema ratchet | **RESOLVED** | I-2 `:43` — "`use RefreshDatabase;` on BOTH the ratchet and the liveness class", plus the central+tenant-union sentence and the `markTestSkipped` PG gate. Union claim independently verified — see B(iv). |
| **I2-R2-02** invariant 7 mis-specified | **RESOLVED** | I-2 `:31` — restated as "writes `products.unit` … and **NEVER `products.unit_id`**, for every row", both rows asserted NULL, `markTestIncomplete` names finding I2-F2 and the desired contract sits in the docblock. Matches `ProductService::importProduct` (`ProductService.php:73`) and the controller-only resolution (`ProductController.php:1173-1228`). |
| **I2-R2-03** liveness lacks the stale direction | **RESOLVED** | I-2 `:47` — three cases, case (3) is an in-memory baseline with a non-existent index name, and the checker is mandated as a pure `(liveIndexes, baseline) → report` function so no schema mutation is needed. Explicitly forbids mutating the committed baseline. |
| **I2-R2-04** `markTestIncomplete` contradiction leaves CI green over a P0 | **PARTIAL** | I-2 `:22` deletes "let it fail" and splits the channel into (a) `DayOneCensus.passed=false`, (b) a named `DayOneCensusCommandTest` asserting exit 1, (c) documentation-only incomplete. (b) is sound — `$this->artisan()` reads the command's own exit code. **But (a)'s stated staging channel is false**: `tenants:run` swallows the exit code → **I2-R3-01**. |
| **I2-R2-05** baseline derived from a stale schema | **RESOLVED** | I-2 `:45` — the 13 entries are labelled "INDICATIVE", "Regenerate from the migrated test schema — **binding**; the reviewer diffs your generated file against the live set", plus the already-company-scoped do-not-baseline list. |
| **I2-R2-06** cite by symbol, not line | **RESOLVED (with note)** | I-2 `:3` — "Line-number citations below were taken before your `git merge dev`; cite by SYMBOL in your own work". The brief itself still carries `:69-186`, `:227-240`, `:161`/`:197`, `:152-176` at `:20`, `:22`, `:29` — disclosed rather than fixed, which is acceptable for a brief but means those numbers are already wrong (`CompanyController::store()` now runs to `:191`). |
| **I2-R2-07** say R1-11/12 are moot | **RESOLVED** | I-2 `:3` — "I2-R1-11/12 (comment decoys, named-index argument) are MOOT under the live-schema design." |
| **I2-R2-08** (NOTE — anchors good) | n/a | Re-verified on dev: `apps/api/tests/Architecture/baselines/` exists with 5 artifacts; `phpunit-pgsql.xml:26-38` includes the `Architecture` testsuite; `EnumCheckParityTest.php:98` is a live precedent for `RefreshDatabase` + PG-only self-skip on an Architecture ratchet with a JSON baseline in that directory — **the brief should cite it as the template** (see I2-R3-04). |

## Docs (DOC-R2-01..05)

| r2 finding | Status | Resolving line / evidence |
|---|---|---|
| **DOC-R2-01** glossary "Company" canonical surface cannot create | **RESOLVED** | `docs/glossary.md:18` now reads "**Create:** the company switcher → `/company-onboarding` (`CompanySelector.tsx` → `POST /api/v1/companies`); **edit:** Settings → Companies (current company only — it cannot create). `AddCompanyModal.tsx` is an orphaned duplicate create surface rendered by nothing (owner ruling owed: delete or wire)." Every clause verified against the tree. |
| **DOC-R2-02** reciprocal platform-glossary link uncommitted | **PARTIAL** | `docs/glossary.md:7` now hedges — "(reciprocal pointer lives in the platform repo and is committed separately)". The r2 required change was *commit it, or mark it owed in the session HANDOVER*; **neither happened**: `git -C /Users/houssamr/Projects/syneriva status --porcelain claude/` → ` M claude/glossary.md` (pointer text at `claude/glossary.md:61`, last commits to that file are `bf92bb2` / `188eda2`, neither containing it), and `grep -n "claude/glossary" docs/sessions/session-I-process-hardening-2026-08-29/HANDOVER.md` returns only the original deliverable line `:11`, no owed-item entry → **DOC-R3-01**. |
| **DOC-R2-03** convention 09 omits `brands(tenant_id, canonical_brand_id)` | **RESOLVED** | `docs/conventions/09-SECOND-OF-EVERYTHING.md:19` now includes `brands(tenant_id,canonical_brand_id)` — confirmed as the second of the three r2→r3 diff hunks. |
| **DOC-R2-04** (NOTE — rows re-verified) | n/a | Spot re-checked on r3 text: `ImportType.php:125` optional-column list is byte-identical to the glossary/I-1 quote; `:39` StockLevels is still the deprecated case; `:10` `Partners` still undeprecated. |
| **DOC-R2-05** (NOTE — round-0 check 6 wired) | n/a | Unchanged by the amend; still wired. |

---

# B. Verification of the NEW r3 claims

## B(i) — the canonicaliser, and the exact request the campaign must POST

### 1. Are the two vendored files alias-free and sufficient? **YES — proven, not asserted.**
- `apps/pos/src/lib/fiscal/canonicalCore.ts` — 108 lines, **zero import statements** (`grep -n "^import\|require(" ` → no output).
- `apps/pos/src/lib/fiscal/FiscalEventCanonicalEncoder.ts` — 163 lines; its only import is `./canonicalCore` (`:23-27`). It also carries its **own** hand-rolled `sha256Hex` (`:38-40`, `:48-52`), so it is self-contained for both halves.
- I ported `encodeCanonicalValue` + the fiscal normalizer verbatim to JS, parsed each golden's `expected_canonical_string` back to an object, re-encoded it and hashed with `node:crypto`:
  - `sale-receipt-v5-golden.json` — canonical round-trip **exact**, sha256 = `4343092af0b38704a2ca5f83cb84006db41c1c8c6d39a2dbc6cfb4aaed012391` = `expected_sha256_hex`. 30 top-level payload keys.
  - `sale-receipt-v4-refund-golden.json` — canonical round-trip **exact**, sha256 = `e768060223ae89db76f7ac9339d3f613ab4c100ac31213720d0a7461465a4a82` = `expected_sha256_hex`. 33 top-level payload keys.
- **Consequence for the brief:** the goldens carry **no separate payload key** — the payload is recovered by `JSON.parse(expected_canonical_string)`. The brief's "encode each golden's **payload**" (`:65`) has no referent as written (MINOR, I1-R3-05). Also, `FiscalEventCanonicalEncoder` already ships `sha256Hex`, so the brief's "plus the 4-line integrity shape … use Node `crypto`" (`:64`) prescribes a redundant second SHA implementation (MINOR, I1-R3-06).

### 2. What else does hashing need? **The wrapper the brief never mentions — this is the BLOCKER.**
`canonical_bytes` is NOT the payload canonical string. Device side, `FiscalEventEngine.ts:620-637`:
```
const canonicalPayload = {
  business_date, chain_context, company_id, event_time_device, event_type, event_version,
  operator_id, payload,  // ← the receipt body, nested
  previous_hash, reference_document_id, reference_event_id, sequence_number,
  signature_version, tenant_id, terminal_id,
};
const canonicalBytes = this.encoder.encode(canonicalPayload);
const currentHash = this.integrityProvider.computeHash(canonicalBytes);
```
Server side, `StrictCanonicalParser.php:78-95` (`ENVELOPE_KEYS`) enforces **exactly** that 15-key set inside
`canonical_bytes`, and `OutboxIngestor::validateSealedCoordinates()` (the `$expected` map at `:433-446`) then
requires 14 of those sealed fields to equal the transport fields byte-for-byte, else
`sealed_coordinate_mismatch:field=…`. `signature_version` is the literal `'hash-chain-integrity-v1'`
(`apps/pos/src/lib/fiscal/HashChainIntegrityProvider.ts:12-14`, mirrored server-side at
`app/Modules/Fiscal/Application/Services/HashChainIntegrityProvider.php:13`).
An implementer following the brief's sentence *"`current_hash = sha256(canonical_bytes)` over the canonical
**payload** string"* will hash the payload and be quarantined on every event with
`canonical_hash_mismatch:sha256(canonical_bytes)!=current_hash` (`OutboxIngestor.php:776`) or, if they wrap but
guess the key set, `envelope_*` parse failures. That is the entire 90-minute budget.

### 3. The exact request shape — put THIS in the brief verbatim
`POST /api/v1/pos/sync/fiscal-events` — outer shape from `IngestFiscalEventsRequest::rules()` (`:44-56`),
inner shape from `FiscalEventEnvelope::fromArray()` (`:120-143`) after
`FiscalEventIngestionController::mergeOuterIntoPayload()` (`:155-165`):
```jsonc
{ "envelopes": [ {                       // required, 1..100
  "envelope_id":      "<any string>",
  "type":             "FISCAL_EVENT",    // literal, in: rule
  "payload_version":  1,                 // integer >= 1
  "idempotency_key":  "<terminal_id>:<sequence_number>",
  "payload": {
    // --- transport-only (NOT inside canonical_bytes) ---
    "id":                    "<uuid v4, lowercase>",   // device-claimed fiscal_events.id
    "current_hash":          "<64 lowercase hex>",     // = sha256(canonical_bytes)
    "canonical_bytes":       "<the canonical STRING below, verbatim>",
    "last_server_time_seen": null,        // optional
    "source_event_class":    null,        // optional
    "source_event_id":       null,        // optional
    // --- the 14 sealed coordinates: MUST equal their canonical twins ---
    "tenant_id": "<uuid>", "company_id": "<uuid>", "terminal_id": "<uuid>", "operator_id": "<uuid>",
    "event_type": "SALE_RECEIPT", "event_version": 5,
    "signature_version": "hash-chain-integrity-v1",
    "sequence_number": 1,
    "event_time_device": "YYYY-MM-DDTHH:MM:SSZ",   // UTC seconds, no fraction
    "business_date": "YYYY-MM-DD",
    "chain_context": "operational",                 // one of operational|z_session|training_operational|training_z_session
    "reference_event_id": null, "reference_document_id": null,
    "previous_hash": "<64 hex — genesis_seed for seq 1, else prior current_hash>"
  }
} ] }
```
and `canonical_bytes = FiscalEventCanonicalEncoder.encode({ business_date, chain_context, company_id,
event_time_device, event_type, event_version, operator_id, payload: <the 30/33-key receipt body>, previous_hash,
reference_document_id, reference_event_id, sequence_number, signature_version, tenant_id, terminal_id })`.
Regex pins that will 422 the whole batch if missed (`FiscalEventEnvelope::assertWireShape()` `:201-213`):
UUIDs lowercase RFC-4122; hashes `^[0-9a-f]{64}$`; `event_time_device` `^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$`;
`business_date` `^\d{4}-\d{2}-\d{2}$`.

Two further facts the brief must carry:
- **Chains are per `(tenant, company, terminal, chain_context)`** (`OutboxIngestor.php:204-211`), and
  `StrictCanonicalParser::validateChainContext()` (`:270-300`) pins `SALE_RECEIPT` to the **operational** set
  (`:96-113`) and `SESSION_OPEN` to the **z_session** set (`:114-125`). So the brief's L6 "`SESSION_OPEN` →
  `SALE_RECEIPT`" is not one chain: they are two chains, each starting at `sequence_number = 1` with
  `previous_hash = genesis_seed`. Moreover `PosCoreReceiptProjection.php` contains **no** reference to `shift`
  (`grep -ni shift` → no output), so **`SESSION_OPEN` is not a prerequisite for the sale to project** — dropping
  it halves L6's hand-authoring.
- **L6/L7 are not synchronous.** `OutboxIngestor::dispatchProjections()` (`:1044`) does
  `ApplyFiscalEventProjectionJob::dispatch($row['id'])`, and that job runs `->onQueue('fiscal-projections')`
  (`ApplyFiscalEventProjectionJob.php:172`). `config/queue.php:16` defaults to a real (non-sync) driver.
  So "receipt projected / stock decremented / GL legs" are only true once a worker consumes the queue — the
  campaign needs a documented poll-with-timeout and a stated precondition that a worker is running on the target.

### 4. Two blockers I looked for and did NOT find (good news, cite in the brief)
- **v5 sale then v4 refund on the same chain is admissible.** `SaleReceiptForwardVersionGate::verdict()`
  short-circuits with `if ($version >= $threshold || $version === 4) return null;` and the docblock says
  "v4 (REFUND) is exempt: it is a sibling fan-out of v3, not a predecessor of v5".
- **`v4_refund_authoring_enabled` is not a server ingest gate.** `grep -rn v4_refund_authoring_enabled app/`
  finds it only in the enable/disable commands, the acknowledgement service, `Terminal`, `TerminalResource:134`
  and the receipt-filter DTO — **nothing under `app/Modules/Fiscal/Application/Services/`**. A web terminal
  (which does not set it, `TerminalController.php:751-826`) can still ingest a v4 refund.

## B(ii) — does `POST /api/v1/pos/terminals/web` return the genesis seed? **YES.**
`TerminalController::getOrCreateWebTerminal()` (`:751`) responds with `TerminalResource::make(...)`, and that
resource exposes `'genesis_seed' => $this->genesis_seed` (`TerminalResource.php:119`) alongside `'id'` (`:25`),
`'last_hash'` (`:120`) and `'current_sequence'` (`:122`). The seed is readable from the create response — no
second lookup, no `psql`. Preconditions the brief omits: `Gate::authorize('pos.operate_terminal')` (`:753`),
a required `location_id` scoped to the caller's company (`:764-766`), and the location must be `pos_enabled`
or it 4xx's (`:773-775`).
**But the brief offers the wrong of the two routes as equal.** `/pos/terminals/web` creates
`fiscal_schema_version = 2`, `type = Web` (`:801-822`, with a load-bearing comment explaining why 2 is
deliberate) and does **not** set `v4_refund_authoring_enabled`. `POST /pos/terminals` (`TerminalController::store()`
`:126-162`) creates `fiscal_schema_version = 3`, `is_active = true`, `activated_at = now()` and
`v4_refund_authoring_enabled = true` — i.e. a v3 terminal, **already active, no `/claim` needed**. The brief's
"`POST /pos/terminals` (`:53`) + `/pos/terminals/claim` (`:56`)" is therefore inaccurate, and the better default
for a device-authored chain is `POST /pos/terminals`.

## B(iii) — the opening-batch sequence and the minimal `rows[]`
Routes (`app/Modules/Accounting/Presentation/routes.php`): `:120` create (`opening-batches.store`), `:140`
`{batchId}/import`, `:144` `{batchId}/validate`, `:152` `{batchId}/post`, `:136` `{batchId}/lock` — all under
`/api/v1/companies/{companyId}/…`. The brief's sequence and the lock path are correct.
- **Create body** (`OpeningBalanceBatchController::store()` `:118-127`): `type` (required, one of
  `ACCOUNTING|INVENTORY|AR_OPEN_ITEMS|AP_OPEN_ITEMS` — `OpeningBatchType.php:9-12`), `name` (required),
  `cutover_date` (required date), `source_system` (nullable). **A cash float or bank opening is
  `type: "ACCOUNTING"`** — the brief never says which type to create.
- **Minimal cash-float row** (`import()` `:398-408`): `{ "account_code": "<the drawer's OWN GL account code>",
  "debit": "1000.000", "repository_code": "<drawer code>" }`. `account_code` is **required**; `debit`/`credit`
  are `nullable|numeric|min:0|regex:/^-?\d+(\.\d{1,3})?$/`; `repository_code` is `nullable|string|max:50`.
- **Minimal bank row:** identical shape with the bank repository's code and its own GL account code.
- **Four per-row refusals the brief must warn about** (`AccountingOpeningService::validateRepositoryColumn()`
  `:312-382`): unknown/inactive code → *"Payment repository '…' was not found or is inactive."*; a **credit**
  line naming a repository → refused (a float is a debit); **the row must debit the repository's OWN linked GL
  account** → *"is linked to GL account X; the opening float must be debited to that account"*; a repository
  that already has movements → refused. Plus `flagDuplicateRepositoryRows()` (`:387-412`): one repository may be
  named by **exactly one** row in a batch.
- The balancing leg is automatic — `AccountingOpeningService.php:776`
  `Account::findByPurposeOrFail($company->id, SystemAccountPurpose::OpeningBalanceEquity)` absorbs the
  difference, so a single debit row is a valid batch. The brief's "opening-balance-equity leg = Σ openings"
  assertion is consistent with the code.
- `POST /api/v1/payment-repositories` exists with `can:repositories.manage`
  (`Treasury/Presentation/routes.php:88-90`), so creating `BANK-01` from the campaign is available.

## B(iv) — `RefreshDatabase` under `phpunit-pgsql.xml`: does the union include `products` / `documents` / `brands`? **YES.**
- `AppServiceProvider::boot()` calls `loadTenantMigrationsInTestingEnvironment()` at `:151`; the method
  (`:233-240`) returns early unless `environment('testing')` and otherwise
  `loadMigrationsFrom(database_path('migrations/tenant'))`.
- `phpunit-pgsql.xml` sets `APP_ENV=testing` (`:52`), forces `DB_CONNECTION=pgsql` (`:56`) and
  `TENANCY_DB_PER_TENANT=false` (`:64`) — so the guard fires and the default connection is the single physical
  test DB.
- Every catalogue table the ratchet reads is under `database/migrations/tenant/` and **absent** from
  `database/migrations/`: `2025_11_30_052910_create_products_table.php`, `2025_11_30_080000_create_documents_table.php`,
  `2026_06_28_100000_create_brands_table.php`, `2025_11_30_052119_create_partners_table.php`,
  `2026_01_09_095045_create_units_table.php`, `2026_06_02_100003_create_product_variants_table.php`,
  `2026_06_02_100001_create_product_attributes_table.php`, `2025_11_30_070000_create_vehicles_table.php`,
  `2026_01_10_100001_create_loyalty_members_table.php` (`ls database/migrations/*.php | grep -E "create_products|create_documents|create_brands"` → empty).
  So `RefreshDatabase` builds the central+tenant union and `pg_index` sees all of them. The brief's claim at
  `:43` is **correct**, and its corollary ("`CATALOGUE_TABLES` is the ONLY scoping") holds.
- Precedent to cite: `tests/Architecture/EnumCheckParityTest.php:98` (`use RefreshDatabase;`) with the
  PG-only self-skip rationale in its docblock (`:90-93`) and JSON baselines in
  `tests/Architecture/baselines/` — the exact shape I-2 is being asked to build.

## B(v) — is anything in the docs amend now false?
The r2→r3 delta is 3 hunks and all 3 are **true against the tree** (verified above for DOC-R2-01/03; DOC-R2-02's
new text is a hedge, not a falsehood). Two time-bombs, both triggered by the G-3a merge that is **already in
progress in the main checkout**:
- `docs/conventions/09-SECOND-OF-EVERYTHING.md:19` — the row is headed *"Live tenant-wide uniques on
  operator-edited tables **today**"* and lists `products(tenant_id,sku)`, `product_variants(tenant_id,sku|barcode)`
  and `partners(tenant_id,vat_number)`. G-3a (`MERGE_HEAD 4ee68be5f`) drops all three tenant-wide forms —
  `2026_08_30_100000_enforce_company_scoped_product_skus.php:52-58` swaps to `(company_id, sku)`, plus the
  variant-SKU and partner-VAT siblings; only `product_variants(tenant_id, barcode)` survives (RUL-2: barcode is
  the cross-company key).
- `docs/glossary.md:36-37` — Product/SKU rows say "today unique per **tenant** … per-company scoping is
  Session G lane G-3a (**pending**)". Same trigger.
Neither is wrong at dev `c3cb8261e`; both must be updated in the same batch that promotes G-3a.

---

# C. Findings

## I-1 — Automated onboarding campaign (r3)

### BLOCKER

#### I1-R3-01 — §2a states the hash contract one level too shallow; following it literally quarantines every event
**Where:** brief `:63` — *"Server contract: `current_hash = sha256(canonical_bytes)` over the canonical **payload**
string"*, and `:64` — *"Hand-author the v5 sale and v4 refund payload objects from the golden fixtures … the
campaign's own amounts are then substituted field-by-field and re-canonicalised."*
**Why it's wrong:** `canonical_bytes` is the canonicalisation of a 15-key wrapper with the payload NESTED under
`payload` (`FiscalEventEngine.ts:620-637`; server key set `StrictCanonicalParser.php:78-95`). The goldens'
`expected_canonical_string` is the payload-only string (30 keys for v5, 33 for v4 — measured), so the golden
proves the ENCODER but is NOT the shape that goes on the wire. Hashing the payload string yields
`canonical_hash_mismatch:sha256(canonical_bytes)!=current_hash` (`OutboxIngestor.php:776`) on every event; if the
implementer wraps but guesses the keys, `validateSealedCoordinates()` (`:433-446`) rejects with
`sealed_coordinate_mismatch:field=…`. Either way the 90-minute budget is gone and L6/L7 land NOT_SCRIPTABLE for a
documentation reason.
**Required change (brief text only):** replace `:63-64` with the full request shape and the canonical wrapper
verbatim from **B(i)-3** above, including `signature_version = 'hash-chain-integrity-v1'`
(`HashChainIntegrityProvider.ts:12-14`), the four regex pins, and the sentence "the golden's
`expected_canonical_string` is the **payload**; the wire `canonical_bytes` wraps it".

### MAJOR

#### I1-R3-02 — L4 names `repository_code` but not the two fields that make the row legal
**Where:** brief `:55`. **Missing:** (a) the batch must be created with `type: "ACCOUNTING"`
(`OpeningBalanceBatchController::store()` `:118-122`, `OpeningBatchType.php:9`); (b) `rows[].account_code` is
**required** (`import()` `:399`) and must be the repository's OWN linked GL account or the row is refused —
*"Payment repository '…' is linked to GL account X; the opening float must be debited to that account so
treasury and the ledger agree."* (`AccountingOpeningService.php:350-361`); (c) the float must be a **debit**
(`:340-347`), the repository must have no movements (`:364-370`), and one repository may be named by exactly one
row (`:387-412`).
**Why it matters:** the campaign will loop on 422s for a rule it cannot see, and the ledger will book a product
finding for a documented refusal.
**Fix:** paste the minimal row shapes from **B(iii)** into L4 and add "read the drawer's GL account code from
`GET /api/v1/payment-repositories` before building the row".

#### I1-R3-03 — L6's chain model is wrong, and the projection is asynchronous
**Where:** brief `:57` — *"Events: `SESSION_OPEN` (14 keys …) → `SALE_RECEIPT`"*, and the L6/L7 "Must assert"
column (`:57-58`) which asserts projected state immediately.
**Two defects:** (i) `SESSION_OPEN` is a **z_session**-context event and `SALE_RECEIPT` an **operational** one
(`StrictCanonicalParser.php:96-125`, enforced `:270-287`); chains are keyed on `chain_context`
(`OutboxIngestor.php:204-211`), so they are two independent chains each starting at `sequence_number = 1` with
`previous_hash = genesis_seed` — not a sequence. And `SESSION_OPEN` is not needed at all: `PosCoreReceiptProjection.php`
never references a shift. (ii) projection is queued — `OutboxIngestor.php:1044`
`ApplyFiscalEventProjectionJob::dispatch(...)`, `ApplyFiscalEventProjectionJob.php:172` `->onQueue('fiscal-projections')`,
default queue driver non-sync (`config/queue.php:16`).
**Fix:** drop `SESSION_OPEN` from L6 (or label it optional and state the separate-chain rule), and add to §2/§3 a
precondition "a queue worker consuming `fiscal-projections` must be running on the target" plus a
poll-with-timeout helper in `journey.ts` for every post-ingest assertion.

#### I1-R3-04 — the terminal-provisioning advice points at the weaker route and invents a `/claim` step
**Where:** brief `:57` — *"`POST /api/v1/pos/terminals/web` … or `POST /pos/terminals` (`:53`) + `/pos/terminals/claim` (`:56`)"*.
`store()` already returns an **active** v3 terminal (`TerminalController.php:147-149`
`'fiscal_schema_version' => 3, 'is_active' => true, 'activated_at' => now()`, and `:154`
`v4_refund_authoring_enabled = true`), so no claim is required; `/pos/terminals/web` returns a
`fiscal_schema_version = 2` Web terminal (`:801-822`).
**Fix:** name `POST /api/v1/pos/terminals` as the campaign default (one call, active, v3), state that the response
carries `data.id` + `data.genesis_seed` (`TerminalResource.php:119`), and drop the `/claim` step.

### MINOR

- **I1-R3-05** — `:65` says "encode each golden's **payload**"; the fixtures have no `payload` key (verified: keys
  are `_comment, event_type, event_version, expected_canonical_string, expected_sha256_hex`). Say
  `JSON.parse(expected_canonical_string)`.
- **I1-R3-06** — `:64` mandates "the 4-line integrity shape … use Node `crypto`", but
  `FiscalEventCanonicalEncoder.ts:38-40` already exposes `sha256Hex`. Either drop the extra shape or say
  explicitly that Node `crypto` is used only as a cross-check of the vendored digest.
- **I1-R3-07** — L7 (`:58`) never names the **event_type** for the refund. The golden is
  `event_type: "SALE_RECEIPT"`, `event_version: 4` (measured), not `REFUND_RECEIPT` (which is a separate
  `FiscalEventType` case at `FiscalEventType.php:31`). Say so, or the implementer will pick the wrong enum.
- **I1-R3-08** — the v4 refund golden is **EUR at `currency_scale` 2** while the campaign default is
  `CAMPAIGN_COUNTRY=TN` (`:45`). Substituting "amounts field-by-field" also means changing `currency_code`,
  `currency_scale`, every amount string to scale 3, and using a `method_code` that exists in the fresh tenant's
  seeded payment methods. One sentence in §2a avoids a debugging round.

*(All eight are brief-text changes. No code change is required of the orchestrator.)*

## I-2 — Fresh-tenant census + ratchet (r3)

### BLOCKER

#### I2-R3-01 — the staging non-green channel does not exist: `tenants:run` discards the exit code, and `--option=fail-on-drift` is not a valid form
**Where:** brief `:22(b)` — *"`php artisan tenants:run tenant:census-day-one --option=fail-on-drift` exits 1 on
any multi-company tenant"*; repeated in Part B `:37` and verification step 5 `:56`.
**Evidence:** `apps/api/vendor/stancl/tenancy/src/Commands/Run.php:33-56` — `handle()` runs
`tenancy()->runForMultiple(..., fn => $this->call($this->argument('commandname'), ...))` and returns nothing;
the child's return value is never captured, so `tenants:run` **always exits 0**. The repo already documents this:
`docs/handoff/RUNBOOK-orphaned-shift.md:130-132` — *"`tenants:run` **discards the child's exit code**, so read the
printed verdict line rather than `$?`."* Separately, `:40` does `[$key, $value] = explode('=', $argument, 2)`, so a
bare `--option=fail-on-drift` destructures a 1-element array → PHP `Undefined array key 1` warning, which Laravel's
`HandleExceptions::handleError` promotes to an `ErrorException`. Every established runbook uses the `key=value`
form for booleans: `--option='dry-run=1'`, `--option='force=1'`, `--option=apply=1`
(`docs/handoff/treasury-phase5b-deploy-checklist.md:41-45`, `RUNBOOK-orphaned-shift.md:127`).
**Why it matters:** I2-R2-04's remedy was accepted on the strength of this channel. Left as written, the
pre-declared P0 ("a second company cannot take cash") is provable only inside PHPUnit, and the staging sweep the
brief sells as the second half of the guard reports nothing actionable.
**Required change (brief text only):** (a) write the flag as `--option='fail-on-drift=1'` everywhere;
(b) state that under `tenants:run` the exit code is meaningless — the operator reads the printed verdict line
(cite `RUNBOOK-orphaned-shift.md:130-132`), and the exit-code contract applies only to the direct,
tenant-bound invocation `php artisan tenant:census-day-one --fail-on-drift`; (c) require
`docs/handoff/RUNBOOK-day-one-census.md` (`:39`) to carry both forms and that caveat.

### MAJOR

#### I2-R3-02 — the G-3a interlock is under-specified, and G-3a is mid-merge with a conflict on the file I-2 must edit
**Where:** brief `:4` (*"G-3a is still unmerged and touches `ci.yml`"*) and `:46` (*"G-3a's SKU migration and any
future fix make the entry stale automatically — that is the point"*).
**State right now:** `MERGE_HEAD = 4ee68be5f` in the main checkout with `UU .github/workflows/ci.yml`.
G-3a's `2026_08_30_100000_enforce_company_scoped_product_skus.php:52-58` (plus the variant-SKU and partner-VAT
siblings) removes **three** of the brief's 13 indicative entries — `products(tenant_id,sku)`,
`product_variants(tenant_id,sku)`, `partners(tenant_id,vat_number)` — leaving 10; `product_variants(tenant_id,barcode)`
survives by RUL-2.
**Why it matters:** the automatic-staleness property is a FAILURE mode, not a fix. If I-2 generates its baseline
before G-3a lands and G-3a merges after, `dev` goes RED on three stale entries with nobody assigned to remove
them — the brief describes the mechanism but never states the remedy or the ordering.
**Required change:** in `:4`, replace "G-3a is still unmerged" with an explicit ordering instruction — *"I-2 lands
AFTER G-3a; if `git merge dev` does not bring `2026_08_30_100000_enforce_company_scoped_product_skus.php`,
STOP and report"* — and add to `:45`: *"if G-3a lands after your baseline is generated, the merge that lands it
must delete the three now-stale entries in the same commit."*

### MINOR

- **I2-R3-03** — `:22(c)`'s `markTestIncomplete` is fine, but say which method: PHPUnit reports an incomplete test
  as **not a failure**, so the brief should add one line — *"`FreshTenantCensusInvariantsTest` must NOT be the
  guard; if `DayOneCensusCommandTest` is deleted or renamed, the P0 goes unwatched"* — so a later lane cannot
  quietly drop the only red channel.
- **I2-R3-04** — cite the in-repo template. `tests/Architecture/EnumCheckParityTest.php:98` is a live
  `RefreshDatabase` + PG-only-self-skip + JSON-baseline-in-`tests/Architecture/baselines/` Architecture ratchet.
  Naming it saves the implementer a design decision and guarantees convention alignment. Note also (for honesty)
  that `EnumCheckParityTest` is **not** in any `ci.yml` filter (`grep -rn EnumCheckParity .github/workflows/` →
  no match) — so copy its structure, not its CI wiring; the brief's `:16` allowlist instruction remains the
  correct wiring.

*(Both blockers/majors are brief-text changes.)*

## Docs commit `02d4c67e3`

### MINOR

- **DOC-R3-01** (carries DOC-R2-02 forward) — `docs/glossary.md:7` hedges the reciprocal link instead of landing
  it. `claude/glossary.md` is still ` M` (uncommitted) in the parent repo and the pointer is not marked owed in
  `docs/sessions/session-I-process-hardening-2026-08-29/HANDOVER.md`. **Fix:** one line in HANDOVER —
  *"OWED: commit `claude/glossary.md:61` (reciprocal ERP-glossary pointer) in the parent `syneriva` repo"* — or
  commit it. Two rounds is enough for a one-line edit.
- **DOC-R3-02** — G-3a staleness (see B(v)): `docs/conventions/09-SECOND-OF-EVERYTHING.md:19` and
  `docs/glossary.md:36-37` become false the moment `4ee68be5f` lands. **Fix:** add them to the G-3a promotion
  checklist, or pre-emptively annotate both with "(G-3a in flight — 3 of these entries go away on its merge)".

### NOTE

- **DOC-R3-03** — additivity holds: `git show --numstat 02d4c67e3` is `N 0` on every file except
  `SPEC-GATE-ROUND0-MECHANICAL-PRECHECK.md` (`13 2`, the two deletions being r2's "checks 1–5" → "checks 1–6"
  fix). Rule 22 still sits at `CLAUDE.md:100` with no renumbering.

---

## What to fix before dispatch

**I-1:** replace §2a's contract sentence with the full wire shape from B(i)-3 (`canonical_bytes` wraps the payload
in the 15-key envelope; `signature_version = 'hash-chain-integrity-v1'`); add `type: "ACCOUNTING"` +
`rows[].account_code` + the GL-account-match rule to L4; drop `SESSION_OPEN` from L6 and add the
`fiscal-projections` worker precondition with a poll helper; switch the terminal step to `POST /api/v1/pos/terminals`
and drop `/claim`; plus the four MINORs (golden payload = `JSON.parse(expected_canonical_string)`, redundant Node
crypto, refund `event_type = SALE_RECEIPT`, EUR/scale-2 golden vs TND/scale-3 campaign).

**I-2:** fix the `tenants:run` claim — `--option='fail-on-drift=1'`, exit code is discarded under `tenants:run`
(`Run.php:33-56`, `RUNBOOK-orphaned-shift.md:130-132`), exit-1 contract applies only to the direct tenant-bound
invocation; state the G-3a ordering + the stale-entry cleanup obligation; cite `EnumCheckParityTest` as the
template and pin `DayOneCensusCommandTest` as the sole red channel.

**Docs:** one HANDOVER line marking `claude/glossary.md` owed, and a G-3a-promotion note on
`09-SECOND-OF-EVERYTHING.md:19` + `glossary.md:36-37`.
