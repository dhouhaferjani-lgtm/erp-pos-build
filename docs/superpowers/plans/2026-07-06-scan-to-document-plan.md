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
**unique `(company_id, checksum)`** (duplicate-upload guard).

Allowed transitions exactly: Uploaded→[Extracting], Extracting→[NeedsReview,Failed],
Failed→[Extracting], NeedsReview→[Committing,Rejected], Committing→[Committed,NeedsReview].
(Committing→NeedsReview is the crash/failure recovery path.)

- [ ] Step 1: failing test — create ingestion via factory, assert `transitionTo(Extracting)` ok,
  `transitionTo(Committed)` from Uploaded throws `InvalidIngestionTransition`, and the full happy
  path Uploaded→Extracting→NeedsReview→Committing→Committed persists each status.
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
- Test: `tests/Feature/DocumentIngestion/IngestionUploadTest.php`, `tests/Feature/Console/HorizonQueueCoverageTest.php` (existing — will fail until horizon.php updated if job exists; keep green)

**Interfaces (Produces):**
```php
final class IngestionService {
  public function __construct(private readonly MediaServiceInterface $media /* Shared contract */) {}
  public function createFromUpload(string $tenantId, string $companyId, string $userId, UploadedFile $file, DocumentKind $kind): DocumentIngestion; // sha256 checksum; 422 IngestionDuplicateException on (company, checksum) unique hit; creates MediaAsset (owner=DocumentIngestion, type Document/Image by mime) then row; dispatches ExtractDocumentJob (Task 4)
  public function reject(DocumentIngestion $i, string $userId): DocumentIngestion;
}
```
Routes (`/api/v1`, middleware `['api','auth:sanctum',SetPermissionsTeam::class]`):
`POST /document-ingestions` (`can:document-ingestions.create`, multipart `file` + `kind` in
`DocumentKind` values, max size per `config/media.php`), `GET /document-ingestions` (`can:…view`,
paginated, filters `status`,`kind`), `GET /document-ingestions/{id}` (`can:…view`),
`POST /document-ingestions/{id}/reject` (`can:…reject`). `{id}` validated `Str::isUuid()`.

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
final class ExtractDocumentJob implements ShouldQueue { // onQueue('ingestion')
  public function __construct(public readonly string $tenantId, public readonly string $companyId, public readonly string $ingestionId) {}
}
```
Job flow: initialize tenancy for `$tenantId` (copy the pattern from an existing tenant-aware job,
e.g. the Media `GenerateRenditions` job), load ingestion, transition Uploaded/Failed→Extracting,
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
  // invoice kind extras: open POs for supplier; uninvoiced receipt lines (quantity_received - quantity_invoiced > 0) for supplier — reuse the existing matcher/query helpers (SupplierInvoiceMatcher::matchableQty semantics), do NOT reimplement FIFO
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
  public string $supplierId; public ?string $locationId; // BL: required
  public ?string $reference; public ?string $documentDate;
  /** @var list<ReviewedLineData> */ public array $lines; // productId, ?variantId, quantity, ?unitPrice, ?freeQuantity, ?batch{batch_number,expiry_date}, ?sourceLineId (invoice kind, Task 8)
  public ?string $currency; public ?bool $pendingReceipt; // invoice kind fork flag
}
final class CommitResultData extends Data { public string $committedType; public string $committedId; public ?string $goodsReceiptNumber; }
```
FormRequest rules: uuids validated + **tenant/company-scope re-validated in the committer**
(scoped `findOrFail` on partner/product/variant/location — api.document.045 class); qty ≤4dp,
money ≤3dp regex ceilings; lines min 1. Controller: transition NeedsReview→Committing, call
registry→committer inside try; success → set `committed_type='goods_receipt'`,
`committed_id`, →Committed; failure → →NeedsReview + rethrow (the shipped idempotency
delete-on-compensation makes retry safe). Committer asserts `$actor` has
`goods-receipt.create-standalone` (Gate::forUser) — policy check happens inside the service.

- [ ] Step 1: failing feature test — seed policy `allow_receipt_first=true`, batch-tracked product;
  build a NeedsReview BL ingestion (factory w/ extraction fixture); commit payload with batch →
  201, `goods_receipts` row Posted with GRN number, stock movement exists, auto-PO created,
  ingestion Committed with `committed_type='goods_receipt'`; replay same commit → same
  receipt id (idempotent), no double stock; policy false → 422/403 domain error; missing
  standalone permission → 403; cross-company product id → 404/422 (scoped).
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
- Consumes: `CreateSupplierInvoiceService::create(array $validated, string $tenantId, string $companyId): Document` — build `$validated` exactly like `SupplierInvoiceController::store` does (read it first): `source_document_ids[]` = unique PO ids derived from the mapped receipt lines' POs; per line `source_line_id` = the PO line id behind the mapped receipt line; `pending_receipt=true` fork skips mapping (policy `allowsInvoiceFirst()` enforced by the service). Duplicate guard: same check the shipped `duplicate-reference` endpoint uses (grep `duplicateReference` in `SupplierInvoiceController`) → committer throws domain 422 `DUPLICATE_SUPPLIER_REFERENCE` when `(partner_id, supplier_reference)` already exists.
- Produces: `CommitResultData{committedType:'supplier_invoice', committedId:<document uuid>}` ; SI left in Draft (posting stays on the existing SI surface).

- [ ] Step 1: failing tests — (a) receipts fork: post a standalone receipt (real service), build
  invoice ingestion, payload maps line→that receipt's PO line, commit → Draft SI with
  `source_document_ids` = [auto-PO], match snapshot present, receipt line consumption visible via
  matcher, ingestion Committed; (b) pending fork: policy `allow_invoice_first=true`, payload
  `pendingReceipt=true`, no mapping → Draft SI with `payload.supplier_invoice.pending_receipt=true`,
  match status Unmatched; policy false → domain error; (c) duplicate supplier_reference → 422;
  (d) actor without `supplier-invoices.create-pending` on pending fork → 403.
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
i18n namespace `documentIngestions` (3-place i18n.ts wiring).

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
(uninvoiced suggestions), not-delivered sets `pendingReceipt`. All money/qty via
`MoneyInput`/`QuantityInput` (string values). Commit → POST payload → success routes to the
committed receipt/SI page; error envelope surfaced (no swallowed 422s). Reject with confirm dialog.

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
duplicate guard (§8c) lands in T3 (unique index) + T8 (SI reference guard). Residency/cost: env
provider + checksum cache noted in T4/T6 (cache = extraction stored on row; re-extract endpoint
deferred — YAGNI for MVP, the job retry covers transient failures).
