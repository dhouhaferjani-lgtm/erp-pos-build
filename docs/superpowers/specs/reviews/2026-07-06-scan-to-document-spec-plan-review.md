# Adversarial review — Scan-to-Document Spec B + Plan (2026-07-06)

> Reviewer: adversarial design review, pre-dispatch gate.
> Reviewed: `docs/superpowers/specs/2026-07-06-scan-to-document-spec-b.md` (spec),
> `docs/superpowers/plans/2026-07-06-scan-to-document-plan.md` (plan).
> Method: every claimed integration point verified against code on `feat/scan-to-document`
> (== origin/dev + docs). All paths below are relative to `apps/api` unless noted.
> Verdict: **BLOCK dispatch until B1–B2 are amended in the plan; M1–M6 need plan text
> fixes too (cheap — a paragraph each) or the overnight workers will discover them at 3am.**

---

## BLOCKERS

### B1. SI committer cannot build a valid `$validated` — `vat_rate` (and friends) are missing from the reviewed payload

`CreateSupplierInvoiceService::create()` hard-requires, per line, `quantity`, `unit_price`,
**`vat_rate`**, and at header level **`currency`**, **`issue_date`**, `partner_id`:

- `app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:54` — `$currency = $validated['currency'];` (missing key → ErrorException → 500)
- `.../CreateSupplierInvoiceService.php:83` — `$unitPrice = (string) $lineInput['unit_price'];`
- `.../CreateSupplierInvoiceService.php:85` — `$vatRate = (string) $lineInput['vat_rate'];` then `bcdiv($vatRate, '100', 6)` at `:96` — `(string) null` = `''` → **bcmath ValueError → 500**
- `.../CreateSupplierInvoiceService.php:130` — `'document_date' => $validated['issue_date']` (missing key → 500)

The plan's `ReviewedPayloadData` (plan Task 7, lines 301–305) carries lines of
`productId, ?variantId, quantity, ?unitPrice, ?freeQuantity, ?batch, ?sourceLineId` —
**no tax rate field at all**, `unitPrice` nullable, `documentDate` nullable, `currency`
nullable. Task 10's review UI never collects a per-line VAT rate either, even though
the extraction DTO has `?ExtractedFieldData $taxRate` (plan Task 2) — it's extracted,
then dropped on the floor before commit.

For reference, the FormRequest the plan says to mirror requires exactly these
(`app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:104`
currency required, `:105` issue_date required, `:117-135` quantity/unit_price/vat_rate
all `required` with regex ceilings incl. percent `{1,2}`).

**Fix (plan text):** `ReviewedLineData` gets `vatRate` (required for invoice kind, percent
regex `/^\d+(\.\d{1,2})?$/`) and `unitPrice` required for invoice kind; `ReviewedPayloadData.currency`
+ `documentDate` required for invoice kind; committer maps `documentDate→issue_date`,
`reference→supplier_reference`. Task 10 review UI must render an editable per-line VAT rate
(prefilled from extraction `taxRate`). CommitDocumentIngestionRequest adds the percent ceiling.

### B2. `Failed` (and `Rejected`) ingestions are a permanent dead-end — re-extract dropped + checksum unique blocks re-upload

Three plan decisions interlock into a brick:

1. Plan self-review (last paragraph): "re-extract endpoint deferred — YAGNI for MVP, the job
   retry covers transient failures". Spec §12 explicitly lists `POST /document-ingestions/{id}/extract`
   — **spec/plan divergence**, plan silently drops an API the spec commits to.
2. `ExtractDocumentJob` has `job retries: 2` (plan Task 4). erp-ml down for two minutes
   (its **first production-critical endpoint**, spec §15) → status `Failed`, and nothing in the
   shipped surface can ever trigger `Failed→Extracting` (the transition exists in Task 1's table
   but has no caller).
3. Task 1 migration: **unique `(company_id, checksum)`** with no status carve-out → re-uploading
   the same photo 422s forever ("duplicate-upload guard"). Same trap for `Rejected`: user rejects
   (e.g. wrong kind chosen at upload — kind is immutable after create), re-uploads the same file
   with the right kind → 422.

Result: one transient extraction failure or one wrong-kind upload permanently bricks that
document short of DB surgery — the morning-after live E2E (post-wave checklist) will hit this.

**Fix (pick one, both are small):** keep the spec's `POST {id}/extract` (guard: allowed only in
`Failed`/`NeedsReview`, rate-limited, reuses cached checksum semantics) **and/or** make the unique
index partial: `UNIQUE (company_id, checksum) WHERE status NOT IN ('rejected','failed')`
(PG partial index; plan already targets PG).

---

## MAJORS

### M1. Commit endpoint concurrency — `transitionTo` is load-check-save, not an atomic claim

Plan Task 1: `transitionTo` "checks `allowedNext()`, saves" — a plain Eloquent read-then-write.
Plan Task 7 controller: "transition NeedsReview→Committing, call registry→committer". Two users
(or a double-click) both read `needs_review`, both pass, both run the committer:

- **BL path survives** by accident: both calls share `idempotencyKey = 'ing-'.$ingestion->id` and
  `procurement_idempotency_keys` claim/replay handles it
  (`app/Modules/Procurement/Application/StandaloneReceiptService.php:64-113`).
- **SI path does not**: `CreateSupplierInvoiceService::create` has **no idempotency key at all**
  (`CreateSupplierInvoiceService.php:51` — signature is just `(array $validated, $tenantId, $companyId)`)
  → two draft SIs, double receipt-line match snapshots. The duplicate-reference guard only helps
  when `supplier_reference` is non-null (`ReviewedPayloadData.reference` is `?string`) and is
  itself racy (check-then-create).

**Fix (plan text, Task 7):** claim the row atomically —
`UPDATE document_ingestions SET status='committing' WHERE id=? AND status='needs_review'`
and 409 when affected-rows = 0 (or `lockForUpdate()` inside a transaction). One sentence in the
plan prevents a class of duplicate SIs.

### M2. Crash between `Committing` and terminal state strands the row forever

Task 7's failure path ("failure → →NeedsReview + rethrow") only covers in-request exceptions.
A worker/process crash after `Committing` is persisted leaves the row in `Committing` with no
exit: the commit endpoint only accepts `NeedsReview`, and nothing else performs
`Committing→NeedsReview`. Spec §4 explicitly names this risk ("a crash must not strand a
half-committed row") but neither doc ships a recovery mechanism.

**Fix:** allow the commit endpoint to accept rows already in `Committing` **iff** re-driven by the
same payload — BL replay is safe through the idempotency key
(`StandaloneReceiptService.php:103-113` returns the existing result), SI needs a pre-check
"does an SI referencing this ingestion already exist" (cheapest: stamp `committed_*` inside the
committer's own transaction before flipping status, then a `Committing` retry can look it up).
Alternatively a stale-`Committing` sweep, but that's more machinery than the MVP needs.

### M3. `ExtractDocumentJob` retry arrives with status already `Extracting` → `InvalidIngestionTransition`

Task 4 job flow: "transition Uploaded/Failed→Extracting". Attempt 1 persists `Extracting`, then
dies mid-HTTP (timeout is 120s; Horizon retry fires). Attempt 2 loads the row in `Extracting` and
calls `transitionTo(Extracting)` — `Extracting→Extracting` is not in Task 1's transition table →
throws. Whether the row then lands `Failed` (self-healing) or the job just fails depends on
whether the implementer put the transition inside or outside the try/catch — the plan doesn't say.

**Fix (one line in Task 1 or 4):** treat `Extracting` as re-enterable — either add
`Extracting→[Extracting, NeedsReview, Failed]` or have the job skip the transition when already
`Extracting`. Add a test: "retry of a job that crashed mid-extraction completes to NeedsReview".

### M4. Media wiring as sketched cannot populate `media_asset_id`, and the review UI has no source-file endpoint

Verified against the Media module:

- The shared contract the plan injects (`IngestionService.__construct(MediaServiceInterface $media)`,
  Task 3) has **no `upload()`** — only `attachUpload(...)`
  (`app/Shared/Contracts/MediaServiceInterface.php:22-32`), which (a) requires `ownerId` — but the
  ingestion row doesn't exist yet in the plan's "creates MediaAsset ... then row" ordering — and
  (b) returns `MediaAttachmentView`, which exposes **no asset id**
  (`app/Shared/DTOs/Media/MediaAttachmentView.php:10-20` — attachment id, filename, mime, flags only).
  The Task 1 migration FK `media_asset_id` cannot be filled from this contract. The spec's
  "upload via existing `MediaUploadService::upload`" (§11) is a different, module-internal service:
  `upload(tenantId, ownerType, ownerId, file, userId, assetType, allowedMime): MediaAsset`
  (`app/Modules/Media/Application/Services/MediaUploadService.php:106-114`) — it returns the asset
  but creates **no attachment row**, so `listForOwner`/`download`/URL-resolution won't see it.
- Cross-module precedent for the correct recipe exists:
  `app/Modules/Product/Application/Services/ProductImageImportService.php:259-275` —
  `MediaUploadService::upload/uploadForProduct` **then** `MediaAttachmentService::attach(assetId, ownerType, ownerId, role, sort, tenantId)`.
- Task 10's `SourceViewer` ("image/PDF iframe via signed download URL") depends on machinery the
  plan never wires: signed URLs come from `MediaUrlResolver::forAttachment` (HMAC `media.serve`
  URL, 60-min TTL — `app/Modules/Media/Application/Services/MediaUrlResolver.php:52-82`) and
  require an **attachment** row; and no task adds the URL to the `GET /document-ingestions/{id}`
  payload or any download route (Task 3's route list: upload/list/detail/reject only). Note an
  authenticated stream endpoint would NOT work in an `<iframe>` (bearer token can't ride an iframe
  src) — the signed relative URL is the right call, it just has to be returned by the detail endpoint.

**Fix (Task 3 + Task 10 text):** pre-generate the ingestion UUID; call
`MediaUploadService::upload` + `MediaAttachmentService::attach` (owner = the pre-generated id,
assetType by mime — `Document` for PDF, `Image` otherwise; allowlist =
`config('media.documents.allowed_mime_types')`, PDF confirmed present at `config/media.php:22`);
persist `media_asset_id` from the returned `MediaAsset`; detail endpoint returns
`source_url = MediaUrlResolver::forAttachment(...)`.

### M5. SI committer bypasses ALL of `CreateSupplierInvoiceRequest`'s cross-field security validation — the service does not re-check

`CreateSupplierInvoiceService` trusts `$validated` completely:

- `CreateSupplierInvoiceService.php:120-124` — `partner_id` and `source_document_id` written raw
  into `Document::create`, no tenant/company scoping.
- `CreateSupplierInvoiceService.php:154` — `DocumentLine::find($ld['sourceLineId'])` — **unscoped**
  `find` on a client-supplied UUID; feeds description/product into the SI and drives
  `matchSnapshotService->forSourceLine` consumption.

Everything that makes this safe on the HTTP path lives in the FormRequest, which the committer
bypasses: `ScopedExists` on partner/source docs (`CreateSupplierInvoiceRequest.php:75-91`),
PO-type + not-cancelled + partner-match + currency-match + "source_line_id belongs to a referenced
PO" (`CreateSupplierInvoiceRequest.php:151-257`). The plan's only scoping instruction ("scoped
findOrFail on partner/product/variant/location", Task 7) is about the **BL** committer; Task 8
says merely "build `$validated` exactly like `SupplierInvoiceController::store` does" — but
`store` builds nothing, the FormRequest does (`SupplierInvoiceController.php:209-229`).

**Fix (Task 8 text + tests):** committer must (1) scoped-`findOrFail` the partner (type Supplier),
(2) resolve each `sourceLineId` via a company/tenant-scoped query, (3) derive
`source_document_ids` **server-side** from those PO lines' documents and assert PurchaseOrder
type / not Cancelled / partner match / currency match, (4) never accept client-sent PO ids.
Add a cross-company `sourceLineId` → 404/422 test (the plan has one for the BL path only).

### M6. FE permission gating requires editing the hardcoded `usePermissions` map — not in any task

`RequirePermission`'s `permission` prop is `type Permission` derived from the hardcoded
`PERMISSIONS` role map (`apps/web/src/features/auth/components/RequirePermission.tsx:4-7`;
`apps/web/src/hooks/usePermissions.ts:1-9` — "custom roles with granted permissions will be
silently denied" until the map is edited). `document-ingestions.*` keys don't exist, so Task 9's
`RequirePermission permission="document-ingestions.view"` is a **TypeScript compile error**, and
adding a cast instead would produce a silent runtime denial. Task 9's file list only touches
router + sidebar.

**Fix:** Task 9 must add the four `document-ingestions.*` keys to `PERMISSIONS` in
`apps/web/src/hooks/usePermissions.ts` (mirror the roles granted in
`database/seeders/RolesAndPermissionsSeeder.php:433-434`).

---

## MINORS (fix in plan text, don't block)

1. **Wrong test path**: `HorizonQueueCoverageTest` lives at
   `tests/Unit/Config/HorizonQueueCoverageTest.php`, not `tests/Feature/Console/…` (plan Task 3).
   An agent running "BY PATH" on the stated path gets file-not-found and may create a duplicate.
2. **Module bootstrap files missing from the file structure**: registration pattern is a module
   provider + `bootstrap/providers.php` entry
   (`app/Modules/Procurement/Providers/ProcurementServiceProvider.php:28` `loadRoutesFrom`;
   `bootstrap/providers.php:106`). Task 3 hints at it but the plan's file tree omits
   `Providers/DocumentIngestionServiceProvider.php` and the providers.php edit.
3. **`ExtractionHints` referenced but never defined**: it appears in
   `ExtractionClientInterface::extract(...)` (plan Task 4) and in no file list.
4. **Checksum grain inconsistency**: spec §4 says "unique per tenant", plan Task 1 says
   `(company_id, checksum)`. Pick one (and see B2 for the partial-index carve-out).
5. **Receipt-line column names**: real columns are `received_qty` vs `quantity_invoiced`
   (`app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:909-911`,
   `whereColumn('received_qty', '>', 'quantity_invoiced')`), not the plan's
   "quantity_received - quantity_invoiced" (Task 5). `po_line_id` for receipt-line→PO-line
   derivation is real (`app/Modules/Inventory/Domain/GoodsReceiptLine.php:25,65,146`).
   Also: the existing uninvoiced query is **per-PO** (`PurchaseOrderController.php:880-911`);
   the suggestion service needs a new per-supplier variant — reuse the semantics, expect to
   write a new query.
6. **Task 7 replay-test expectation contradicts the state machine**: after a successful commit the
   row is `Committed`; a replayed `POST {id}/commit` dies at `NeedsReview→Committing` with 422
   before ever reaching the idempotent service. Either define endpoint-level idempotency
   (Committed row + same payload → 200 with stored `committed_*`) or fix the test wording.
   (Interacts with M1/M2 — solving those defines this.)
7. **Suggestions DTO must carry ids the FE needs**: `SuggestionsData` sketch says
   "po/receipt-line candidates" — the receipt-line candidate must include `po_line_id` AND
   `receipt_line_id` (+ uninvoiced qty, unit price) or Task 10 cannot build `sourceLineId`.
8. **i18n**: Task 9 mentions the 3-place `i18n.ts` wiring but no locale JSON files appear in any
   file list (`documentIngestions` namespace needs en/fr at minimum).

---

## Verified-true claims (so the implementer doesn't re-litigate)

- `StandaloneReceiptService::execute(StandaloneReceiptInput): GoodsReceiptResult` — exact
  signature and DTO fields as the spec claims
  (`app/Modules/Procurement/Application/StandaloneReceiptService.php:48`;
  `DTOs/StandaloneReceiptInput.php:12-23`; `DTOs/StandaloneReceiptLineInput.php:12-19` incl.
  `batch{batch_number, expiry_date, manufacturing_date?}`).
- Policy gates fail closed in the service: `allowsReceiptFirst()` at
  `StandaloneReceiptService.php:57`, `allowsInvoiceFirst()` for pending SIs at
  `CreateSupplierInvoiceService.php:65`. `DomainException` → 422 `BUSINESS_ERROR` globally
  (`bootstrap/app.php:344-352`).
- Idempotency: 64-char limit enforced (`StandaloneReceiptService.php:167`); `'ing-'+uuid` = 40
  chars — fits; claim/replay/compensation semantics as described
  (`StandaloneReceiptService.php:64-113,331-365,367-391`).
- Permissions exist server-side: `goods-receipt.create-standalone` +
  `supplier-invoices.create-pending` (`database/seeders/RolesAndPermissionsSeeder.php:122-123`;
  route gate `app/Modules/Procurement/Presentation/routes.php:40-41`); pending-SI permission is
  controller-level (`SupplierInvoiceController.php:237-255`) so the committer must indeed
  replicate it, as the plan says. Posting approval gate exists
  (`SupplierInvoicePostingService.php:534`). `InvoiceFirstOrchestrator::createDelivered` exists
  (`InvoiceFirstOrchestrator.php:22`) — correctly out of scope.
- Horizon queue list is exactly `['default','fiscal-projections','enrichment','images','imports']`
  (`config/horizon.php:209`) and the coverage test exists (see Minor 1 for path).
- Queued-job tenancy pattern the plan cites is real: `BindsTenantContext::withTenantContext()`
  in `GenerateRenditions` (`app/Modules/Media/Application/Jobs/GenerateRenditions.php:31-73`;
  trait at `app/Jobs/Concerns/BindsTenantContext.php`).
- Media: tenant-DB media tables (`database/migrations/tenant/2026_06_12_100001*`); PDF in the
  document mime allowlist (`config/media.php:22`); non-image assets go READY immediately
  (`MediaUploadService.php:97-100,155-159`); `MediaOwnerType.storageSegment()` match is exhaustive
  with 4 arms today (`app/Modules/Media/Domain/Enums/MediaOwnerType.php:14-22`) — adding the case
  + arm is the only enum edit; no other exhaustive `match` over the enum found outside the enum.
- Spatie `laravel-data` + typescript-transformer are the project convention
  (`composer.json:26,29`; `#[TypeScript]` e.g. `Procurement/Application/DTOs/ProcurementPolicyData.php:11`).
- Import normalizers exist as claimed: `NumericFieldNormalizer`, `PartiesRowMapper`,
  `ProductPriceResolver` in `app/Modules/Import/Services/`.
- `SupplierInvoiceMatcher::matchableQty` exists (`SupplierInvoiceMatcher.php:149`); match snapshot
  runs at SI create (`CreateSupplierInvoiceService.php:159-166,217-221`).
- Procurement routes carry `EnforceTokenTenantClaim` (`Procurement/Presentation/routes.php:31,80`)
  — the plan's middleware note is right.
- erp-ml: routers = health/recommendations/churn/tenant_forecasting under `app/api/routes/`,
  `include_router` in `app/api/main.py:89-96`, `Settings` in `app/core/config.py:9`, `tests/`
  exists with FastAPI TestClient usage, `anthropic` NOT in `requirements.txt` (must be added),
  auth today is bare `X-Tenant-Id` header (`app/api/routes/recommendations.py:85`) — the
  X-Service-Token addition is justified.
- Web: `features/purchases/` structure + `/purchases` route block exist
  (`apps/web/src/routes/index.tsx:56-64,744`); `tenantScopedKey` exists
  (`apps/web/src/lib/tenantScopedKey.ts:29`); `AssertsApiValidation` exists
  (`apps/api/tests/Traits/AssertsApiValidation.php`).
- Product fallback price for BLs-without-prices exists: `products.purchase_price`, `decimal:3`
  cast (`app/Modules/Product/Domain/Product.php:39,101,157`).
- Precision contract: no violations found in the plan's DTO sketches — money/qty are strings,
  `confidence: float` is not money, regex ceilings specified (B1 adds the missing percent ceiling).

## Suggested gate

Amend the plan for B1, B2, M1–M6 (all are plan-text changes, ~1 hour), then dispatch.
Reviewer gates already named in the plan (inventory-costing / treasury / tenancy-authz) should
re-verify M1/M2/M5 in code at merge time.
