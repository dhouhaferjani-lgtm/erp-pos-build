# Scan-to-Document Implementation Plan (Spec B overnight waves)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking. Spec: `docs/superpowers/specs/2026-07-06-scan-to-document-spec-b.md`.

**Goal:** Photo/PDF of a supplier invoice or delivery note (BL) → OCR extraction → assisted web
review → committed goods receipt (BL) or supplier invoice (invoice), reusing the shipped P2P
commit surfaces.

**Architecture:** New hexagonal module `Modules/DocumentIngestion` (state machine + committer
registry) in `apps/api`; stateless extraction endpoint in `apps/erp-ml` (FastAPI + Anthropic
structured output); web review feature in `apps/web/src/features/document-ingestions/`. No new GL
or stock paths — committers call `StandaloneReceiptService` / `CreateSupplierInvoiceService`.

**Tech Stack:** Laravel 12 (PHP 8.2 strict), PostgreSQL (tenant DBs), Redis/Horizon, FastAPI +
`anthropic` SDK, React 19 + TanStack Query 5, Vitest/PHPUnit.

## Global Constraints (apply to every task)

- TDD: failing test first; tests BY PATH only (`php artisan test path/...` / `pnpm vitest run <path>`), NEVER the full suite.
- No `mixed` / no `any`; JSONB columns get PHP DTOs; run `php artisan typescript:transform` after DTO changes (needs `CACHE_STORE=array` in some worktrees).
- Money/qty are strings end-to-end (rule 19): FormRequest regex ceilings — money `/^-?\d+(\.\d{1,3})?$/`, qty `/^\d+(\.\d{1,4})?$/`; bcmath via `CurrencyScale::bcformatStrict` + injected `CurrencyScaleResolverInterface` (queued jobs pass explicit currency).
- Routes: `['api','auth:sanctum',SetPermissionsTeam::class]` group (Procurement/Inventory routes also carry `EnforceTokenTenantClaim` — match the module you're in).
- Cross-module access ONLY via `Shared/Contracts`, events, or a module's public Service class.
- Constructor injection with `private readonly`; never `app()`.
- Frontend: all text via `t()`; design tokens only (new feature dir = ESLint-enforced); `tenantScopedKey([...])` on every tenant query key; `apiGet/apiPost` already unwrap — no double-unwrap; paginated `{data,meta}` → `api.get` + `response.data`.
- Validation error envelope is `{error:{errors}}` — use `Tests\Traits\AssertsApiValidation`.
- UUID params: `Str::isUuid()` before any `where('uuid', $val)`.
- Commit per task with descriptive message; PHPStan L8 + Pint clean on new code.
- Codex: maintain `docs/sessions/TASK-LOG-scan-<wave>.md`; session owner commits.

## File Structure (new/modified)

```
apps/api/app/Modules/DocumentIngestion/
├── Domain/
│   ├── DocumentIngestion.php                 (Eloquent model, tenant DB)
│   ├── Enums/{DocumentKind,IngestionStatus}.php
│   └── Exceptions/InvalidIngestionTransition.php
├── Application/
│   ├── DTO/ExtractionResultData.php          (+ ExtractedFieldData, ExtractedLineData, SuggestionsData…)
│   ├── Services/{IngestionService,ExtractionReconciler,MatchSuggestionService,IngestionCommitterRegistry}.php
│   ├── Committers/{SupplierDeliveryNoteCommitter,SupplierInvoiceCommitter}.php
│   └── Jobs/ExtractDocumentJob.php           (queue: 'ingestion')
├── Infrastructure/
│   └── ErpMlExtractionClient.php             (+ Shared/Contracts/ExtractionClientInterface.php)
└── Presentation/
    ├── Controllers/DocumentIngestionController.php
    ├── Requests/{StoreDocumentIngestionRequest,CommitDocumentIngestionRequest}.php
    └── routes.php
apps/api/database/migrations/tenant/2026_07_06_2*_create_document_ingestions_table.php
apps/api/app/Modules/Media/Domain/Enums/MediaOwnerType.php        (add case)
apps/api/config/horizon.php                                        (add 'ingestion')
apps/api/config/services.php                                       (erp_ml url + token)
apps/api/database/seeders/RolesAndPermissionsSeeder.php            (4 new perms)
apps/erp-ml/app/api/routes/extraction.py + app/services/extraction/{port,registry,claude_extractor,schema}.py
apps/web/src/features/document-ingestions/…                        (list, upload, review)
```

---

## Wave S1 — backend ingestion core (apps/api)

### Task 1: Module skeleton — enums, migration, model, state machine

**Files:**
- Create: `app/Modules/DocumentIngestion/Domain/Enums/DocumentKind.php`, `IngestionStatus.php`
- Create: `app/Modules/DocumentIngestion/Domain/DocumentIngestion.php`
- Create: `app/Modules/DocumentIngestion/Domain/Exceptions/InvalidIngestionTransition.php`
- Create: `database/migrations/tenant/2026_07_06_200000_create_document_ingestions_table.php`
- Test: `tests/Feature/DocumentIngestion/IngestionStateMachineTest.php`

**Interfaces (Produces):**
```php
enum DocumentKind: string { case SupplierInvoice = 'supplier_invoice'; case SupplierDeliveryNote = 'supplier_delivery_note'; }
enum IngestionStatus: string { case Uploaded='uploaded'; case Extracting='extracting'; case NeedsReview='needs_review'; case Committing='committing'; case Committed='committed'; case Rejected='rejected'; case Failed='failed';
  /** @return list<self> */ public function allowedNext(): array; }
final class DocumentIngestion extends Model {
  public function transitionTo(IngestionStatus $next): void; // throws InvalidIngestionTransition
}
```

Migration columns (tenant DB): `id uuid pk`, `tenant_id uuid`, `company_id uuid` (FK companies),
`kind string(40)`, `status string(20) default 'uploaded'`, `media_asset_id uuid` (FK media_assets),
`checksum string(64)`, `provider string(40) nullable`, `provider_model string(80) nullable`,
`extraction jsonb nullable`, `confidence_summary jsonb nullable`, `suggestions jsonb nullable`,
`committed_type string(40) nullable`, `committed_id uuid nullable`, `error jsonb nullable`,
`created_by uuid`, `timestampsTz`. Indexes: `(company_id,status)`, `(company_id,kind,created_at)`;
duplicate-upload guard = **PARTIAL unique index** (raw statement in the migration):
`CREATE UNIQUE INDEX ux_document_ingestions_company_checksum ON document_ingestions (company_id, checksum) WHERE status NOT IN ('rejected','failed')`
— rejected/failed rows must NOT block a re-upload of the same file (adversarial review B2; the
grain is company_id, matching all other scoping here).

Allowed transitions exactly: Uploaded→[Extracting], **Extracting→[Extracting,NeedsReview,Failed]**
(self-transition = Horizon retry of a job that died mid-extraction; adversarial review M3),
Failed→[Extracting], NeedsReview→[Committing,Rejected], Committing→[Committed,NeedsReview].
(Committing→NeedsReview is the in-request failure path; crash recovery in Committing is handled
at the endpoint — Task 7.)
Also create `Providers/DocumentIngestionServiceProvider.php` (routes via `loadRoutesFrom` — copy
`ProcurementServiceProvider.php:28`) and register it in `bootstrap/providers.php` (review minor 2).

- [ ] Step 1: failing test — create ingestion via factory, assert `transitionTo(Extracting)` ok,
  `transitionTo(Committed)` from Uploaded throws `InvalidIngestionTransition`, the full happy
  path Uploaded→Extracting→NeedsReview→Committing→Committed persists each status,
  Extracting→Extracting is ALLOWED (retry re-entry), and a rejected row does not block inserting a
  second row with the same `(company_id, checksum)` while a needs_review row does (partial index).
- [ ] Step 2: `php artisan test tests/Feature/DocumentIngestion/IngestionStateMachineTest.php` → FAIL (class not found)
- [ ] Step 3: implement enums + migration + model (`transitionTo` checks `allowedNext()`, saves).
- [ ] Step 4: rerun → PASS. PHPStan the new dir.
- [ ] Step 5: commit `feat(ingestion): DocumentIngestion module skeleton + state machine`

### Task 2: Extraction DTOs (JSONB contract)

**Files:**
- Create: `app/Modules/DocumentIngestion/Application/DTO/ExtractedFieldData.php`, `ExtractedLineData.php`, `ExtractionResultData.php`, `ConfidenceSummaryData.php`
- Test: `tests/Unit/DocumentIngestion/ExtractionResultDataTest.php`

**Interfaces (Produces):** Spatie Data classes (project convention, TypeScript-transformable):
```php
final class ExtractedFieldData extends Data {
  public function __construct(public string $value, public float $confidence, /** @var list<float>|null */ public ?array $sourceBbox = null) {}
}
final class ExtractedLineData extends Data {
  public function __construct(
    public ExtractedFieldData $description, public ?ExtractedFieldData $supplierRef,
    public ExtractedFieldData $quantity, public ?ExtractedFieldData $unitPrice,
    public ?ExtractedFieldData $taxRate, public ?ExtractedFieldData $lineTotal,
    public ?ExtractedFieldData $batchNumber, public ?ExtractedFieldData $expiryDate) {}
}
final class ExtractionResultData extends Data {
  public function __construct(
    public string $docKind, public int $pages,
    /** @var array<string, ExtractedFieldData> */ public array $supplier,   // name, vat_number, address?, iban?
    /** @var array<string, ExtractedFieldData> */ public array $header,     // number, issue_date, due_date?, currency?, subtotal?, tax_total?, stamp_duty?, grand_total?, delivery_date?, po_reference?
    /** @var list<ExtractedLineData> */ public array $lines,
    public bool $totalsConsistent) {}
}
```
All monetary/qty `value`s are strings. `from()` must reject non-string numeric leaves (cast in a
`MapInputName`-free explicit `from` or via rules) — test that a float value in `unit_price` fails.

- [ ] Step 1: failing test — hydrate from a realistic FR-invoice JSON fixture
  (`tests/Fixtures/document_ingestion/extraction_invoice_fr.json` — create it: 2-page invoice,
  3 lines, decimal-comma-free normalized strings like `"12.500"`); assert round-trip
  `toArray()` equality and a BL fixture (`extraction_bl_fr.json`, no prices) hydrates with
  `unitPrice === null`.
- [ ] Step 2: run BY PATH → FAIL
- [ ] Step 3: implement DTOs.
- [ ] Step 4: run → PASS; `php artisan typescript:transform` and commit generated types too.
- [ ] Step 5: commit `feat(ingestion): extraction result DTOs + fixtures`

### Task 3: MediaOwnerType + upload/list/detail/reject endpoints + permissions + queue registration

**Files:**
- Modify: `app/Modules/Media/Domain/Enums/MediaOwnerType.php` (add `case DocumentIngestion = 'DOCUMENT_INGESTION';` + `storageSegment()` arm `'ingestions'` — match is exhaustive, PHPStan will catch misses)
- Modify: `config/horizon.php` supervisor `queue` array: append `'ingestion'`
- Modify: `database/seeders/RolesAndPermissionsSeeder.php` — add `document-ingestions.view`, `.create`, `.commit`, `.reject` (grant to the same roles that hold `supplier-invoices.create-pending`; follow the file's existing role-mapping pattern)
- Create: `app/Modules/DocumentIngestion/Presentation/routes.php` (+ register in the module/route bootstrap the same way `Modules/Procurement/Presentation/routes.php` is registered)
- Create: `Presentation/Controllers/DocumentIngestionController.php`, `Requests/StoreDocumentIngestionRequest.php`
- Create: `Application/Services/IngestionService.php`
- Test: `tests/Feature/DocumentIngestion/IngestionUploadTest.php`, `tests/Unit/Config/HorizonQueueCoverageTest.php` (existing — exact path per review minor 1; keep green)

**Interfaces (Produces):**
```php
final class IngestionService {
  // NOT the Shared MediaServiceInterface — its attachUpload() returns MediaAttachmentView with NO
  // asset id (adversarial review M4). Follow the ProductImageImportService.php:259-275 precedent:
  public function __construct(
    private readonly MediaUploadService $uploadService,      // Media module public service
    private readonly MediaAttachmentService $attachmentService,
  ) {}
  public function createFromUpload(string $tenantId, string $companyId, string $userId, UploadedFile $file, DocumentKind $kind): DocumentIngestion;
  // flow: PRE-GENERATE the ingestion uuid → sha256 checksum → duplicate check (422
  // IngestionDuplicateException when a non-rejected/failed row matches (company_id, checksum)) →
  // MediaUploadService::upload(tenantId, MediaOwnerType::DocumentIngestion, $preGeneratedId, file,
  // $userId, assetType by mime (Document for pdf, Image otherwise),
  // config('media.documents.allowed_mime_types')) → MediaAttachmentService::attach($asset->id,
  // MediaOwnerType::DocumentIngestion, $preGeneratedId, …) → create the row with media_asset_id =
  // $asset->id → dispatch ExtractDocumentJob (Task 4).
  public function reject(DocumentIngestion $i, string $userId): DocumentIngestion;
  public function reExtract(DocumentIngestion $i): DocumentIngestion; // allowed ONLY in Failed|NeedsReview → transition to Extracting + re-dispatch job (review B2)
}
```
Routes (`/api/v1`, middleware `['api','auth:sanctum',SetPermissionsTeam::class]`):
`POST /document-ingestions` (`can:document-ingestions.create`, multipart `file` + `kind` in
`DocumentKind` values, max size per `config/media.php`), `GET /document-ingestions` (`can:…view`,
paginated, filters `status`,`kind`), `GET /document-ingestions/{id}` (`can:…view` — the detail
payload MUST include `source_url` = `MediaUrlResolver::forAttachment(...)` signed relative URL,
60-min TTL; the review UI iframe cannot send a bearer token, review M4),
`POST /document-ingestions/{id}/extract` (`can:…create`, throttle 6/min, Failed|NeedsReview only —
review B2), `POST /document-ingestions/{id}/reject` (`can:…reject`). `{id}` validated `Str::isUuid()`.

- [ ] Step 1: failing tests — upload png → 201 with `status=uploaded`, MediaAsset row exists with
  owner type `DOCUMENT_INGESTION`; duplicate same-bytes upload → 422 envelope; missing permission
  → 403; bad kind → 422; list filters by status; reject flips NeedsReview→Rejected and 409s from
  Committed. Use `RolesAndPermissionsSeeder` + real models (`RefreshDatabase`), `Storage::fake('s3')`, `Queue::fake()`.
- [ ] Step 2: run BY PATH → FAIL
- [ ] Step 3: implement (enum arm, service, controller, requests, routes, seeder, horizon).
- [ ] Step 4: run BY PATH → PASS; also run the existing Media enum tests BY PATH (exhaustive-match sweep: grep `match ($` usages of `MediaOwnerType` and cover the new case).
- [ ] Step 5: commit `feat(ingestion): upload/list/reject endpoints + media owner type + perms + queue`

### Task 4: ExtractionClient contract + ExtractDocumentJob

**Files:**
- Create: `app/Shared/Contracts/ExtractionClientInterface.php`
- Create: `app/Modules/DocumentIngestion/Infrastructure/ErpMlExtractionClient.php`
- Create: `app/Modules/DocumentIngestion/Application/Jobs/ExtractDocumentJob.php`
- Modify: `config/services.php` — `'erp_ml' => ['url' => env('ERP_ML_URL', 'http://127.0.0.1:8002'), 'service_token' => env('ERP_ML_SERVICE_TOKEN')]`
- Modify: a module ServiceProvider binding interface→client (follow how other Shared/Contracts are bound)
- Test: `tests/Feature/DocumentIngestion/ExtractDocumentJobTest.php`, `tests/Unit/DocumentIngestion/ErpMlExtractionClientTest.php`

**Interfaces (Produces):**
```php
interface ExtractionClientInterface {
  /** @throws ExtractionFailedException */
  public function extract(string $fileContents, string $mimeType, DocumentKind $kind, ExtractionHints $hints): ExtractionResultData;
}
final class ExtractionHints { // plain readonly DTO next to the interface (review minor 3)
  public function __construct(public readonly string $languageHint = 'fr', public readonly ?string $currencyHint = null) {}
}
final class ExtractDocumentJob implements ShouldQueue { // onQueue('ingestion')
  public function __construct(public readonly string $tenantId, public readonly string $companyId, public readonly string $ingestionId) {}
}
```
Job flow: initialize tenancy via `BindsTenantContext::withTenantContext()` (trait
`app/Jobs/Concerns/BindsTenantContext.php`, used by Media `GenerateRenditions.php:31-73`), load
ingestion, transition Uploaded/Failed/Extracting→Extracting (self-transition allowed — retry re-entry, review M3),
read file from `Storage::disk('s3')`, call client, store `extraction` + `confidence_summary` +
`provider`/`provider_model`, run reconciler + suggestions (Tasks 5) then →NeedsReview; on
exception → Failed with structured `error` (`{code, message}`), rethrow-safe (job retries: 2).
NO CompanyContext assumptions — pass company/currency explicitly (rule 19/20).
`ErpMlExtractionClient` uses Laravel `Http::baseUrl(...)->withHeaders(['X-Service-Token'=>…])
->attach('file', …)->post('/api/v1/extract', ['kind'=>…, 'language_hint'=>'fr'])`, 120s timeout;
unit-test with `Http::fake()` (200 valid, 200 malformed→ExtractionFailedException, 500, 401).

- [ ] Step 1: failing tests — happy path via a bound fake client (fixture DTO) ends NeedsReview
  with extraction persisted; client-throw path ends Failed with error payload; job is on queue
  `ingestion` (assert `Queue::fake()` push + `HorizonQueueCoverageTest` still green).
- [ ] Step 2: run BY PATH → FAIL
- [ ] Step 3: implement.
- [ ] Step 4: run BY PATH → PASS. PHPStan.
- [ ] Step 5: commit `feat(ingestion): extraction job + erp-ml HTTP client behind Shared contract`

### Task 5: Reconciler + match suggestions

**Files:**
- Create: `Application/Services/ExtractionReconciler.php`, `Application/DTO/ReconciliationData.php`
- Create: `Application/Services/MatchSuggestionService.php`, `Application/DTO/SuggestionsData.php`
- Test: `tests/Unit/DocumentIngestion/ExtractionReconcilerTest.php`, `tests/Feature/DocumentIngestion/MatchSuggestionServiceTest.php`

**Interfaces (Produces):**
```php
final class ExtractionReconciler { // pure bcmath, scale from injected CurrencyScaleResolverInterface->getScale($currency)
  public function reconcile(ExtractionResultData $r, string $currency): ReconciliationData;
  // invoice kind: sum(line_total)==subtotal; subtotal+tax_total(+stamp_duty)==grand_total; per-line unit_price*qty≈line_total (scale+1 intermediates, compare at scale)
  // BL kind: qty required per line; price checks only when unitPrice && lineTotal both present; NO tax checks
}
final class ReconciliationData extends Data { /** @var list<string> flags like 'subtotal_mismatch', 'line_3_total_mismatch' */ public array $flags; public bool $consistent; }
final class MatchSuggestionService {
  public function suggest(DocumentIngestion $i, ExtractionResultData $r): SuggestionsData;
  // supplier: exact vat_number → normalized-name exact (lower/trim/strip legal suffixes via PartiesRowMapper-style normalization) → ILIKE '%name%' top 5
  // product per line: sku exact → barcode exact → cross_references/oem_numbers contains supplierRef
  // invoice kind extras: open POs for supplier; uninvoiced RECEIPT lines for supplier — matchable
  // window is computed on the RECEIPT line via ReceiptLineConsumptionPlanner::matchableQty
  // (paid: received_qty − quantity_invoiced; free tracked SEPARATELY as free_qty −
  // free_quantity_invoiced — free-only lines are NOT paid-invoice suggestions). Reuse the
  // planner/matcher helpers; do NOT hand-roll a PO-column subtraction (inventory-review MINOR).
  // The existing uninvoiced query is PER-PO only (PurchaseOrderController.php:880-911,
  // whereColumn('received_qty','>','quantity_invoiced')) — write a NEW per-supplier variant with
  // the same semantics. Each receipt-line candidate DTO must carry po_line_id AND receipt_line_id
  // AND uninvoiced qty AND unit price (the FE builds sourceLineId from po_line_id — review minor 7).
}
final class SuggestionsData extends Data { … supplier candidates [{id,name,vat,score:string}], per-line product candidates, po/receipt-line candidates … }
```

- [ ] Step 1: failing reconciler tests — exact 3dp TND invoice passes; off-by-0.001 subtotal
  flags `subtotal_mismatch`; BL with no prices is consistent; per-line mismatch names the line.
  Failing suggestion tests — seeded partner with vat match ranks first; sku beats ILIKE;
  uninvoiced receipt-line suggestion appears after a posted standalone receipt (build via
  `StandaloneReceiptService` in the test — real machinery, no fakes).
- [ ] Step 2: run BY PATH → FAIL
- [ ] Step 3: implement; wire both into `ExtractDocumentJob` (suggestions stored on the row).
- [ ] Step 4: run BY PATH → PASS (rerun Task 4 job test — now asserts suggestions persisted).
- [ ] Step 5: commit `feat(ingestion): bcmath reconciliation + match-or-suggest`

---

## Wave S2 — erp-ml extraction service (repo dir: `/Users/houssamr/Projects/syneriva/apps/erp-ml`)

### Task 6: `/api/v1/extract` + ClaudeExtractor

**Files:**
- Create: `app/services/extraction/__init__.py`, `schema.py` (pydantic models mirroring the wire contract — all money/qty as `str`), `port.py` (`InvoiceExtractor` Protocol), `registry.py` (`get_extractor()` keyed by `EXTRACTOR_PROVIDER` env, default `claude`), `claude_extractor.py`
- Create: `app/api/routes/extraction.py`; register in `app/api/main.py` (`include_router(..., prefix="/api/v1")`)
- Modify: `app/core/config.py` — `ANTHROPIC_API_KEY: str = ""`, `EXTRACTOR_PROVIDER: str = "claude"`, `EXTRACTION_SERVICE_TOKEN: str = ""`, `EXTRACTION_MODEL: str = "claude-haiku-4-5-20251001"`, `EXTRACTION_ESCALATION_MODEL: str = "claude-sonnet-5"`
- Modify: `requirements.txt` — add `anthropic`
- Test: `tests/test_extraction_route.py`, `tests/test_claude_extractor.py` (follow existing erp-ml test layout; if none exists for routes, create `tests/` per FastAPI TestClient convention)

**Endpoint contract (Consumed by Task 4's client):** `POST /api/v1/extract`, multipart `file` +
form fields `kind` (`supplier_invoice|supplier_delivery_note`), `language_hint`, `currency_hint?`.
Auth: header `X-Service-Token` must equal `EXTRACTION_SERVICE_TOKEN` (401 otherwise; 503 if unset).
Response 200: `{provider, model, doc_kind, pages, result: {supplier:{…}, header:{…}, lines:[…], totals_consistent}}`
— every leaf `{value: str, confidence: float, source_bbox?: [floats]}`. 422 on undecodable file.

`ClaudeExtractor`: anthropic SDK, PDF/image as document/image content block, **tool-use forced
structured output** (single tool `record_extraction` whose input schema = the wire schema; strict
strings for money/qty; instruct French invoice/BL context + BL-optional prices). Escalate to
`EXTRACTION_ESCALATION_MODEL` when >30% of leaf confidences < 0.6 or the tool call fails to parse
once. **Tests NEVER call the live API** — inject a fake anthropic client (constructor takes the
client; tests pass a stub returning recorded tool-use blocks from
`tests/fixtures/claude_invoice_response.json`).

- [ ] Step 1: failing route test — valid token + fixture-stubbed extractor → 200 shape-validated; missing token → 401; bad kind → 422.
- [ ] Step 2: `cd apps/erp-ml && python -m pytest tests/test_extraction_route.py -v` → FAIL
- [ ] Step 3: implement schema/port/registry/route.
- [ ] Step 4: failing extractor test with stubbed anthropic client (assert model choice, escalation trigger, string coercion); implement `claude_extractor.py`; pytest → PASS.
- [ ] Step 5: commit (in erp-ml's parent repo — note: this directory belongs to the `syneriva` repo, NOT `apps/erp`; log the diff in TASK-LOG for the session owner to commit there) `feat(erp-ml): document extraction endpoint with Claude structured-output adapter`

---

## Wave S3 — committers (apps/api)

### Task 7-pre: Close the StandaloneReceiptService idempotency crash window (inventory BLOCKER)

**Files:**
- Modify: `app/Modules/Procurement/Application/StandaloneReceiptService.php`
- Test: `tests/Feature/Procurement/StandaloneReceiptIdempotencyRecoveryTest.php`

Pre-existing bug the scan committer would inherit (inventory design review 2026-07-06, BLOCKER):
the `goods_receipt_id` idempotency-row update (`StandaloneReceiptService.php:144-150`) happens
OUTSIDE the receipt-post transaction (:116-137). Crash between them leaves
`{purchase_order_id set, goods_receipt_id NULL}` with a live posted receipt; replay then falls
through `existingResult` (:342), re-enters receipt creation, over-receive throws, and
`compensateFailedReceiptCreation` (:367-382) cancels the PO + deletes the key → the NEXT attempt
double-moves stock. Fix all three legs:
1. Move the `goods_receipt_id` write into the same DB transaction that posts the receipt.
2. In `existingResult`: when `goods_receipt_id` is NULL but a **posted** `GoodsReceipt` exists for
   `purchase_order_id`, heal the row (write the id) and return that receipt — never re-receive.
3. In `compensateFailedReceiptCreation`: refuse to cancel the PO / delete the key when a posted
   receipt exists for the PO (log + rethrow instead).

- [ ] Step 1: failing test — post a standalone receipt via the service, then manually NULL the
  key row's `goods_receipt_id` (simulate the crash), call `execute` again with the same key:
  assert SAME receipt id returned, PO still Confirmed, exactly ONE receipt + one set of stock
  movements, key row healed. Second test: force a receipt-creation failure after a posted receipt
  exists (same PO) and assert compensation does NOT cancel the PO.
- [ ] Step 2: run BY PATH → FAIL (current code double-receives / cancels)
- [ ] Step 3: implement the three legs.
- [ ] Step 4: run BY PATH → PASS; also rerun the existing standalone-receipt + idempotency test
  paths (`grep -rl StandaloneReceipt tests/Feature | xargs -I{} php artisan test {}`).
- [ ] Step 5: commit `fix(procurement): close standalone-receipt idempotency crash window (replay double-receive)`

### Task 7: Commit endpoint + registry + `SupplierDeliveryNoteCommitter`

**Files:**
- Create: `Application/Services/IngestionCommitterRegistry.php`, `Application/Committers/SupplierDeliveryNoteCommitter.php`, `Application/DTO/ReviewedPayloadData.php`, `Application/DTO/CommitResultData.php`
- Create: `Presentation/Requests/CommitDocumentIngestionRequest.php`; modify controller + routes (`POST /document-ingestions/{id}/commit`, `can:document-ingestions.commit`)
- Test: `tests/Feature/DocumentIngestion/CommitDeliveryNoteTest.php`

**Interfaces:**
- Consumes: `StandaloneReceiptService::execute(StandaloneReceiptInput): GoodsReceiptResult` (source `standalone_receipt`, `postImmediately: true`, `idempotencyKey: 'ing-'.$ingestion->id`, `externalReference` = BL number, `externalDate` = delivery date), `StandaloneReceiptLineInput(productId, ?variantId, quantity, freeQuantity, unitPrice, ?batch)`.
- Produces:
```php
interface IngestionCommitterInterface { public function supports(DocumentKind $kind): bool;
  public function commit(DocumentIngestion $i, ReviewedPayloadData $payload, string $actorId): CommitResultData; }
final class ReviewedPayloadData extends Data {
  public string $supplierId; public ?string $locationId; // BL kind: required (FormRequest conditional)
  public ?string $reference; public ?string $documentDate; // invoice kind: BOTH required (issue_date!)
  /** @var list<ReviewedLineData> */ public array $lines;
  // ReviewedLineData: productId, ?variantId, quantity(string), ?unitPrice(string — REQUIRED for
  // invoice kind, optional for BL), ?vatRate(string ≤2dp — REQUIRED for invoice kind; treasury
  // B-1: CreateSupplierInvoiceService reads quantity/unit_price/vat_rate by direct array access,
  // missing vat_rate = bcmul ValueError 500), ?freeQuantity, ?batch{batch_number,expiry_date},
  // ?sourceLineId (invoice kind: the PO line id — mapping grain is PO line, FIFO within it)
  public ?string $currency; // invoice kind: REQUIRED (service reads it unguarded)
  public ?bool $pendingReceipt; // invoice kind fork flag
}
final class CommitResultData extends Data { public string $committedType; public string $committedId; public ?string $goodsReceiptNumber; }
```
FormRequest rules: uuids validated + **tenant/company-scope re-validated in the committer**
(scoped `findOrFail` on partner/product/variant/location — api.document.045 class); qty ≤4dp,
money ≤3dp, percent ≤2dp regex ceilings; lines min 1.

Controller commit semantics (adversarial review M1/M2/minor-6 — implement EXACTLY this):
1. **Atomic claim**: `UPDATE document_ingestions SET status='committing' WHERE id=? AND status IN
   ('needs_review','committing')` — 0 affected rows → if the row is `Committed`, return 200 with
   the stored `committed_*` (endpoint-level idempotency); else 409. A plain
   `transitionTo` load-check-save race would let two clicks create two SIs.
2. Committer runs; it must stamp `committed_type`/`committed_id` on the ingestion row **inside the
   same DB transaction that creates the target record** (SI committer wraps
   `CreateSupplierInvoiceService::create` + the stamp in one `DB::transaction`; BL committer stamps
   right after `execute` returns — its replay is covered by the procurement idempotency key).
3. Success → flip to `Committed`. In-request failure → flip back to `needs_review` + rethrow.
4. Crash recovery: because step 1 accepts `committing` rows, a re-driven commit after a crash
   either finds `committed_*` already stamped (finish: flip to Committed, return it) or re-runs
   the committer (BL: idempotency-key replay returns the same receipt — requires Task 7-pre, a
   hard prerequisite of this task; SI: the stamp is atomic with the create, so absence = safe to re-run).
Committer asserts `$actor` has `goods-receipt.create-standalone` (Gate::forUser) — policy check
happens inside the service.

Inventory-review deltas (2026-07-06 — all mandatory):
- **Batch requiredness:** for every line whose matched product `requires_batch_tracking`, the
  committer requires `batch{batch_number, expiry_date}` and rejects with a clean per-line 422
  (never let `GoodsReceiptService`'s DomainException surface as a 500). There is NO default-expiry
  fallback on the receive path; a BL with no printed expiry is rejected with a message telling the
  user to enter it from the physical goods. Parapharmacy = every product, so this is the main path.
- **Price positivity:** resolve each paid line's price as: extracted BL price, else
  `product.purchase_price`; if the result is null/zero/negative → per-line 422
  (`LINE_PRICE_REQUIRED`). NEVER pass `'0'` or null into `StandaloneReceiptLineInput::unitPrice`
  (zero-cost purchase would silently bias WAC downward; null throws in bcformatStrict).
- **Nullable mapping:** `freeQuantity` defaults `'0'`; `StandaloneReceiptLineInput` fields are
  non-nullable strings.
- **Synchronous constraint:** the commit MUST stay a synchronous HTTP request —
  `WeightedAverageCostService` uses no-arg `getScale()` which throws off-request. Never move the
  committer onto a queue.
- **Currency:** the receipt is booked in company currency (auto-PO ignores payload currency);
  the review UI must not imply the extracted currency is honored on the BL path.

- [ ] Step 1: failing feature test — seed policy `allow_receipt_first=true`, batch-tracked product;
  build a NeedsReview BL ingestion (factory w/ extraction fixture); commit payload with batch →
  201, `goods_receipts` row Posted with GRN number, stock movement exists, auto-PO created,
  ingestion Committed with `committed_type='goods_receipt'`; replayed `POST {id}/commit` on the
  now-Committed row → 200 with the SAME stored `committed_id` (endpoint idempotency, review
  minor-6), no double stock; a row forced to `committing` (simulated crash) re-driven with the same
  payload → completes to Committed with ONE receipt; policy false → 422/403 domain error; missing
  standalone permission → 403; cross-company product id → 404/422 (scoped); batch-tracked line
  WITHOUT batch/expiry → per-line 422 (not 500); line with no extracted price AND null
  `purchase_price` → per-line 422 `LINE_PRICE_REQUIRED`; free-quantity-only line passes with
  `freeQuantity` set and never sends null/zero `unitPrice` for paid qty.
- [ ] Step 2: run BY PATH → FAIL
- [ ] Step 3: implement registry + committer + endpoint.
- [ ] Step 4: run BY PATH → PASS. PHPStan.
- [ ] Step 5: commit `feat(ingestion): BL committer → standalone posted receipt (auto-PO)`

### Task 8: `SupplierInvoiceCommitter` (receipt-mapped + pending forks)

**Files:**
- Create: `Application/Committers/SupplierInvoiceCommitter.php`
- Modify: registry binding; `ReviewedPayloadData` already carries `sourceLineId`/`pendingReceipt`
- Test: `tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php`

**Interfaces:**
- Consumes: `CreateSupplierInvoiceService::create(array $validated, string $tenantId, string $companyId): Document` — build `$validated` exactly like `SupplierInvoiceController::store` does (read it first). REQUIRED keys the service reads by direct array access (treasury B-1): header `currency`, `partner_id`, `issue_date`; per line `quantity`, `unit_price`, `vat_rate` — the FormRequest must make unitPrice/vatRate/currency/documentDate mandatory when `kind=supplier_invoice`. `source_document_ids[]` = unique PO ids from the mapped PO lines; per line `source_line_id` = the PO line id (mapping grain = PO line; FIFO consumes receipt lines within it — documented limitation, spec §8c). `pending_receipt=true` fork skips mapping.
- **Authz/validation parity (treasury M-2/M-4, adversarial M5)** — the committer bypasses the controller and FormRequest, and **the service itself trusts `$validated` blindly** (raw `partner_id` into `Document::create` at `CreateSupplierInvoiceService.php:120-124`; **unscoped** `DocumentLine::find($sourceLineId)` at `:154`). The committer MUST therefore: (a) `Gate::forUser($actor)->authorize('supplier-invoices.create-pending')` on the pending fork; (b) scoped-`findOrFail` the partner (company/tenant scope, partner type Supplier); (c) resolve every `sourceLineId` through a company-scoped query joined to its parent document; (d) **derive `source_document_ids` server-side from those PO lines' documents** — never accept client-sent PO ids — and assert each is a PurchaseOrder, not Cancelled, partner matches `supplierId`, currency matches; (e) map `documentDate→issue_date`, `reference→supplier_reference`. Test each guard, including a cross-company `sourceLineId` → 404/422 case.
- **Duplicate guard (treasury M-3, race-proof):** inside the commit DB transaction, `SELECT pg_advisory_xact_lock(hashtext(?))` with key `"si-ref:{companyId}:{supplierId}:{reference}"`, then query for an existing supplier-invoice Document with same `(company_id, partner_id, external_document_number)` → domain 422 `DUPLICATE_SUPPLIER_REFERENCE` if found. (No schema change tonight; partial unique index = morning follow-up after data audit.)
- Produces: `CommitResultData{committedType:'supplier_invoice', committedId:<document uuid>}` ; SI left in Draft (no GL at create — confirmed; posting stays on the existing SI surface; `balance_due` only materializes at post).

- [ ] Step 1: failing tests — (a) receipts fork: post a standalone receipt (real service), build
  invoice ingestion, payload maps line→that receipt's PO line WITH vat_rate/unit_price/currency/
  issue_date, commit → Draft SI with `source_document_ids` = [auto-PO], match snapshot present,
  receipt line consumption visible via matcher, ingestion Committed; (b) pending fork: policy
  `allow_invoice_first=true`, payload `pendingReceipt=true`, no mapping → Draft SI with
  `payload.supplier_invoice.pending_receipt=true`, match status Unmatched; policy false → domain
  error; (c) duplicate `(partner, external_document_number)` on second commit → 422
  `DUPLICATE_SUPPLIER_REFERENCE` (and the advisory-lock path is exercised); (d) actor without
  `supplier-invoices.create-pending` on pending fork → 403 (committer-level, not route);
  (e) cross-field guards: source PO of another supplier → 422; `source_line_id` not in source POs
  → 422; cancelled PO → 422; (f) missing vat_rate on invoice kind → FormRequest 422 (NOT a 500).
- [ ] Step 2: run BY PATH → FAIL
- [ ] Step 3: implement.
- [ ] Step 4: run BY PATH → PASS; also rerun Task 7 test path (registry regression). PHPStan + Pint on the module.
- [ ] Step 5: commit `feat(ingestion): supplier-invoice committer (receipt-mapped + pending forks)`

---

## Wave S4 — web review UI (apps/web)

### Task 9: API layer + list + upload

**Files:**
- Create: `apps/web/src/features/document-ingestions/api.ts`, `queries.ts`, `types.ts` (re-export generated types), `DocumentIngestionListPage.tsx`, `UploadIngestionDialog.tsx`
- Modify: router + sidebar nav per `docs/conventions/02-NAVIGATION-ROUTING.md` (route `/purchases/scans` under the purchases area, `RequirePermission permission="document-ingestions.view"`)
- Test: `apps/web/src/features/document-ingestions/__tests__/DocumentIngestionListPage.test.tsx`

Key points: multipart upload via the project's axios instance (`api.post(..., formData)` — check
how an existing upload does it, e.g. document attachments); list = paginated `{data,meta}` so use
`api.get` + `response.data`; poll list with `refetchInterval: 5000` while any row is
`uploaded|extracting`; status chips tokenized; all keys `tenantScopedKey(['document-ingestions',…])`;
i18n namespace `documentIngestions` (3-place i18n.ts wiring **+ create the locale JSON files for
en, fr AND ar** — add them to this task's file list; review minor 8).
**REQUIRED (review M6):** add the four `document-ingestions.view/create/commit/reject` keys to the
hardcoded `PERMISSIONS` map in `apps/web/src/hooks/usePermissions.ts`, mirroring the roles granted
in `RolesAndPermissionsSeeder` — without this, `RequirePermission permission="document-ingestions.view"`
is a TypeScript compile error (the `Permission` type derives from that map), and a cast would
silently deny at runtime.

- [ ] Step 1: failing Vitest — list renders fixture rows with status chips + kind labels; upload dialog validates kind required and file size; posts FormData (mock api module).
- [ ] Step 2: `pnpm vitest run src/features/document-ingestions` → FAIL
- [ ] Step 3: implement.
- [ ] Step 4: vitest BY PATH + `pnpm typecheck` + `pnpm lint` (scoped) → PASS
- [ ] Step 5: commit `feat(web): document-ingestion list + upload`

### Task 10: Review page + commit flow

**Files:**
- Create: `ReviewIngestionPage.tsx` (+ `components/`: `SourceViewer.tsx` (image/PDF iframe via signed download URL), `ExtractedFieldsPanel.tsx`, `LineMappingTable.tsx`, `SupplierPicker.tsx`, `CommitBar.tsx`)
- Test: `__tests__/ReviewIngestionPage.test.tsx`

Behavior: low-confidence (<0.75) + reconciliation-flagged fields highlighted (token colors);
supplier suggestions dropdown (explicit "create supplier" navigates to partner create prefilled);
per-line product picker seeded with suggestions; BL kind → location picker + batch/expiry inputs
(required only when matched product is batch-tracked — field comes from product suggestion
payload); invoice kind → delivered/not-delivered fork; delivered shows receipt-line mapping table
(uninvoiced suggestions), not-delivered sets `pendingReceipt`. **Invoice kind: editable per-line
VAT-rate input, prefilled from extraction `taxRate`, else from the matched product's tax rate —
mandatory before commit (review B1: the SI service 500s without it); header currency + document
date mandatory.** The source viewer loads `source_url` from the detail payload (signed relative
URL — do NOT try to iframe an authed endpoint). A "Relancer l'extraction" button calls
`POST {id}/extract` on `failed` rows. All money/qty via `MoneyInput`/`QuantityInput` (string
values). Commit → POST payload → success routes to the committed receipt/SI page; error envelope
surfaced (no swallowed 422s). Reject with confirm dialog.

- [ ] Step 1: failing Vitest — renders extraction fixture with 2 flagged fields highlighted; BL fixture shows location+batch inputs; commit builds the exact `ReviewedPayloadData` shape (assert mock call arg: strings, sourceLineId mapping); 422 from commit renders server message.
- [ ] Step 2: vitest BY PATH → FAIL
- [ ] Step 3: implement.
- [ ] Step 4: vitest BY PATH + typecheck + scoped lint → PASS
- [ ] Step 5: commit `feat(web): ingestion review + commit flow`

---

## Post-wave verification (session owner, morning)

- [ ] `./scripts/preflight.sh` scope-equivalents: PHPStan, Pint, touched PHPUnit paths, web typecheck/lint/Vitest paths, `tools/audit-tanstack-keys.mjs`.
- [ ] Local deploy: `php artisan tenants:migrate` + `tenants:seed --force RolesAndPermissionsSeeder` + `php artisan permission:cache-reset` (tenant-blind cache!).
- [ ] erp-ml: `cd apps/erp-ml && uvicorn app.api.main:app --reload --port=8002` with `ANTHROPIC_API_KEY` + `EXTRACTION_SERVICE_TOKEN` in `.env`; API `.env` gets `ERP_ML_URL=http://127.0.0.1:8002` + matching token; start a Horizon worker consuming `ingestion`.
- [ ] Live E2E: upload a real FR pharmacy BL photo → review → commit → GRN + stock; upload invoice PDF → map to that receipt → Draft SI → post from SI page (GL: 408 clearing per shipped machinery). Playwright pass on the two pages.
- [ ] Reviewer gates BEFORE merge: inventory-costing-reviewer (Task 7 path), treasury-reviewer (Task 8 path), tenancy-authz-reviewer (routes/perms/scoping).

## Self-review notes (spec→plan coverage)

§4 module/state machine→T1; §4 DTOs→T2; §11 media/queue/perms + §12 upload/list/reject→T3;
§5 client + §11 tenancy-job→T4; §6 reconcile + §7 suggest→T5; §5 erp-ml→T6; §8a→T7; §8b+§8c→T8;
§10 UI→T9-T10; §9 mobile→NOT in plan (separate handover, owner-run); §13 waves match. Checksum
duplicate guard (§8c) lands in T1 (partial unique index — rejected/failed carved out) + T8 (SI
reference guard). Re-extract endpoint (§12) RESTORED in T3 after adversarial review B2 (a
transient erp-ml outage or wrong-kind upload must never permanently brick a document). Adversarial
review 2026-07-06 (B1-B2, M1-M6, 8 minors) + treasury + inventory design reviews are all folded
into the task texts above — reviewer gates at merge time should re-verify M1/M2/M5 in code.
