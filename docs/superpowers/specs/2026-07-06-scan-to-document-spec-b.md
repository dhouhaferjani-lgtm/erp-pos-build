# Spec B: Scan-to-Document — supplier invoices AND delivery notes (BL)

> Status: DRAFT for adversarial review · Date: 2026-07-06 · Session: scan-to-document overnight
> Supersedes the commit layer (§8) of `2026-06-27-supplier-invoice-ocr-capture-design.md` (parent
> spec, on branch `feat/supplier-invoice-ocr`). The parent spec's ingestion-engine design
> (§§3–7, 9–13, 16) is carried forward and restated here where amended.
> Aligns with: P2P entry points (merged `c495f1236`), procurement completeness v1 (merged
> `525e616dc`), media unification (MediaAsset), precision contract (rule 19), db-per-tenant.

## 0. What changed since the parent spec — supersession ledger

| Parent-spec assumption | Reality on dev today | Consequence |
|---|---|---|
| Receipts would become first-class **documents** (`DocumentType::GoodsReceipt`, `GoodsReceiptDocumentService`) | Receipts are **non-document ledger tables** (`goods_receipts` + `goods_receipt_lines`, GRN numbering, written by `GoodsReceiptService`) | The 3 commits on `feat/supplier-invoice-ocr` (Tasks 1–2 of the old 15-task plan) are **SUPERSEDED — do not merge or rebase that branch**. Salvage only its docs. |
| PO-less invoice must spawn a draft GRN document; GL gate on expert-comptable 408-accrual sign-off | **Receipt-first shipped**: standalone receipts create an **auto-PO behind the scenes** at billed prices (zero PPV) through existing PO/GR-IR machinery | The accounting gate is **dissolved** — no new GL path, no bespoke accrual basis. Scanned BL commits through the shipped standalone-receipt service. |
| Month-end reconciliation was an open design question | Shipped: receipt-line-grain FIFO matcher, "Facturer les réceptions", **multi-PO supplier invoices** (`source_document_ids[]`), PPV split | Consolidated month-end invoice = create SI **from uninvoiced receipt lines** via existing surfaces. Spec B adds no matcher. |
| Invoice-before-goods was out of scope | **Invoice-first shipped**: pending SI (`pending_receipt`, policy-gated) + "Associer des réceptions" linking + approval gate | Scanned invoice with goods not yet delivered commits as a **pending SI**. |
| GR-IR redesign coupling = highest risk | Shipped and stable on dev | Risk retired. |

## 1. Problem & goal (unchanged, plus BL)

Suppliers send **invoices** sometimes, but mostly **delivery notes (BL)**; BLs accumulate and are
invoiced monthly with one consolidated invoice. Both must be scannable:
photo/PDF → OCR extract → assisted review → commit, where commit target is
**(a) supplier invoice** or **(b) supplier delivery note** (= goods receipt: stock + GR-IR accrual,
no invoice yet). First real user: parapharmacy pilot, Tunisia-style BL-heavy workflows.

Primary goal: photo → reviewed, committed document with minimal typing, never silently creating
bad master data. Secondary goal: the OCR/validation/review core stays **document-kind-agnostic**
(expenses reuse later via a new committer, zero engine change).

## 2. Non-goals (MVP)

Carried from parent spec: no auto-posting without review; no Arabic tuning / photo preprocessing
(accommodated, not built); no embedding product match; no Factur-X; no expense committer (engine
ready for it); no on-prem extractor. **Added:** no mobile *review* screen in the overnight MVP
(mobile = capture + status only; review is web); no per-tenant provider config (per-deploy env).

## 3. Architecture (carried forward)

```
[erp-mobile / web upload]  photo|PDF ──multipart──▶ [apps/api]
   MediaAsset (owner = DocumentIngestion, collection source_document)
   + document_ingestions row (state machine)
        │ queued job (queue: 'ingestion' — register in horizon.php)
        ▼
   ExtractionClient ── HTTP ──▶ [erp-ml FastAPI] POST /v1/extract
                                  InvoiceExtractor port; ClaudeExtractor (MVP)
                                  stateless; returns strict JSON + per-field confidence
        ▼
   schema-validate (DTO) → bcmath totals/tax reconcile → match-or-suggest
   (supplier pg_trgm / product sku·barcode·cross_references / open POs / uninvoiced receipt lines)
        ▼
   status = needs_review ──▶ [web review UI] correct + choose links ──▶ commit
        ▼                                                            │
   Committer registry:  kind = supplier_delivery_note ─▶ BL committer → standalone receipt (auto-PO)
                        kind = supplier_invoice ───────▶ SI committer → receipt-linked SI | pending SI
```

## 4. `DocumentIngestion` module (carried forward, amended)

New hexagonal module `Modules/DocumentIngestion`.

- `document_ingestions` (tenant DB): `id` uuid PK; `company_id`; `kind`
  (`DocumentKind { SupplierInvoice, SupplierDeliveryNote }` — PHP enum, string-backed);
  `status` (`IngestionStatus { Uploaded, Extracting, NeedsReview, Committing, Committed, Rejected, Failed }`);
  `media_asset_id` FK; `provider` / `provider_model` (audit); `extraction` JSONB typed by
  `ExtractionResultData` DTO; `confidence_summary` JSONB; `suggestions` JSONB (match candidates,
  typed DTO); `committed_type` + `committed_id` (SI document id **or** goods-receipt id — receipts
  are not documents, so a bare documents-FK is wrong; use a type discriminator, no polymorphic DB FK);
  `checksum` (sha256 of source file, **unique per tenant** — duplicate-upload guard);
  `error` JSONB nullable; `created_by`; timestamps.
- State machine enforced in Domain: `Uploaded → Extracting → NeedsReview → Committing → Committed`,
  `Extracting → Failed` (retryable), `NeedsReview → Rejected`. `Committing` is new vs parent spec:
  commit runs the posting transaction; a crash must not strand a half-committed row as `NeedsReview`.
- **Committer seam**: `IngestionCommitterInterface { supports(DocumentKind): bool;
  commit(DocumentIngestion, ReviewedPayload): CommitResult }` — registry keyed by kind.
  MVP registers `SupplierDeliveryNoteCommitter` and `SupplierInvoiceCommitter`.

## 5. Extraction provider (carried forward)

- erp-ml FastAPI `POST /api/v1/extract` — new router under `app/api/routes/` (existing routers:
  health/forecasting/recommendations/churn; **no LLM client exists in erp-ml today** — add
  `anthropic` to requirements + `ANTHROPIC_API_KEY` to `app/core/config.py Settings`).
  Stateless; no tenant data at rest. Existing erp-ml auth is header-based (`X-Tenant-Id`), no
  bearer — because this endpoint triggers paid API calls, add an `X-Service-Token` shared-secret
  header (env on both sides), checked before any provider call.
- Port `InvoiceExtractor` (Python Protocol) + registry keyed by `EXTRACTOR_PROVIDER` env.
  MVP adapter: **ClaudeExtractor** — Claude vision + tool-use structured output (strict schema),
  native multi-page PDF. Model: cheap-first with escalation on low confidence or failed
  reconciliation. All money/qty values returned **as strings** (rule 19).
- Wire contract per parent spec §5: every leaf field = `{ value: string, confidence: 0..1,
  source_bbox?: […] }`; `doc_kind` echoes the hint; `lines[]` includes `supplier_ref`.
  **BL delta:** for `supplier_delivery_note`, header captures `bl_number`, `delivery_date`,
  optional PO reference; totals section optional (many BLs have no prices) — the schema marks
  price fields nullable for BL kind.
- Laravel treats every extracted value as untrusted until schema-validated + reconciled.

## 6. Validation & reconciliation (carried forward, BL amendments)

1. Schema validation via `ExtractionResultData` DTO (no `mixed`).
2. Totals/tax reconciliation in bcmath — **invoice kind only**: `Σ line_total == subtotal`,
   `subtotal + tax_total (+ stamp_duty) == grand_total`, per-line `unit_price × qty` sanity.
   Mismatch → flag in review UI, never silently mutate. French locale numerics (`1 234,56`).
3. **BL kind:** quantity presence is mandatory per line; prices optional. If BL carries prices,
   per-line sanity only (no tax reconcile — BLs are not tax documents).
4. Confidence gating: below-threshold fields highlighted, mandatory review before commit.

## 7. Match-or-suggest (carried forward, one addition)

Supplier: exact `vat_number`/registration → normalized-name exact → `ILIKE` substring candidates.
**Fact check (2026-07-06): no `pg_trgm`/trigram matching exists anywhere in the codebase** — the
parent spec's pg_trgm plan is a hardening item (extension + GIN index migration), NOT MVP; MVP
matching is deterministic + ILIKE. Product per line: `sku` → `barcode` →
`cross_references`/`oem_numbers`; **reuse unified-imports normalizers**
(`Modules/Import/Services`: `ProductPriceResolver`, `PartiesRowMapper`,
`NumericFieldNormalizer`) rather than re-implementing.
No match → explicit user pick-or-create (minimal product; captured supplier ref lands in
`cross_references`). **Addition:** for invoice kind, also suggest **uninvoiced receipt lines** for
the matched supplier (the shipped month-end surface) and open POs.
Never silent-create. All suggestions are recomputed server-side at commit time (client-supplied
IDs are validated for tenant/company scope — api.document.045 class of bug).

## 8. Commit layer (REWRITTEN — targets shipped P2P surfaces)

Governing framework (owner-ratified): money documents change accounting; receipts change stock;
an invoice never moves stock directly.

### 8a. Scanned BL → standalone goods receipt (receipt-first, majority case)
- `SupplierDeliveryNoteCommitter` calls the shipped
  `Procurement\Application\StandaloneReceiptService::execute(StandaloneReceiptInput)` with
  `source: 'standalone_receipt'`, `postImmediately: true`. Per line
  (`StandaloneReceiptLineInput`): `productId`, `?variantId` (scope-validated), `quantity`,
  `freeQuantity`, `unitPrice` (strings), `?batch{batch_number, expiry_date}` where the product is
  batch-tracked (parapharmacy!). Header: `locationId` from review UI; **`externalReference` = BL
  number, `externalDate` = delivery date** (fields already exist on the DTO).
- **Price source order:** BL line price if extracted → else current product purchase price.
  Review UI shows which source each line uses (owner to confirm the fallback default).
- Policy + authz: service already gates on `allowsReceiptFirst()` (fail-closed) and the route
  permission is `goods-receipt.create-standalone`; the committer asserts the same permission for
  the acting user before calling (never trust the UI).
- **Commit posts the receipt** (stock + GR-IR accrual through the auto-PO machinery) — matches
  "committing a BL receives the stock". The scanned image (MediaAsset) is linked for audit.
- Idempotency: `idempotencyKey = 'ing-' . <ingestion uuid>` (fits the 64-char limit) through the
  shipped `procurement_idempotency_keys` claim/replay/compensation semantics — a retry after a
  crash must not double-receive.

### 8b. Scanned invoice → supplier invoice (two forks, user-chosen in review)
- **Goods already received** (typical month-end consolidated invoice): user maps invoice lines to
  the suggested **uninvoiced receipt lines** (possibly spanning many BLs/POs) → committer calls
  `CreateSupplierInvoiceService::create` with `source_document_ids[]` (the receipts' POs) and
  per-line `source_line_id` (the PO line behind each mapped receipt line); receipt-line
  consumption runs through the existing FIFO matcher/snapshot service. Price deltas → shipped PPV
  mechanism. Partial mapping allowed; unmapped receipt lines stay open for the next invoice.
- **Goods not delivered yet** → committer calls `CreateSupplierInvoiceService::create` with
  `pending_receipt: true` (policy-gated by `allowsInvoiceFirst()`; permission
  `supplier-invoices.create-pending`; posting later enforces
  `invoice_first_requires_approval` via `SupplierInvoicePostingService`); linking receipts later
  uses the shipped "Associer des réceptions" surface (`POST /supplier-invoices/{id}/link-receipts`)
  — Spec B builds nothing there. (The third shipped fork, `invoice_first_delivered` via
  `InvoiceFirstOrchestrator::createDelivered`, is intentionally NOT exposed in the scan review UI
  for MVP: a scanned invoice for goods that arrived undocumented should be handled by scanning the
  BL first or by the delivered fork in the normal SI form — one less path to test tomorrow.)
- SI duplicate guard: reuse the shipped `supplier-invoices/{...}/duplicate-reference` check —
  committer rejects commit when an SI with the same `(partner_id, supplier_reference)` exists.
- Posting stays manual/existing: the committer creates the SI **draft** and returns it; the user
  posts from the SI surface (keeps DOA/approval semantics in one place).

### 8c. Common
- Committed ingestion links: `committed_type` = `supplier_invoice | goods_receipt`,
  `committed_id`. The MediaAsset is (re)attached to the committed record for later retrieval.
- Non-PO compensating controls carried forward: duplicate/replay detection = checksum unique (§4)
  + SI supplier-reference guard (§8b) + BL committer idempotency key (§8a). Amount/DOA approval
  routing = shipped invoice-first approval gate; receipts are approval-free by design (they're
  stock events, matching physical reality).

## 9. Mobile capture (erp-mobile — separate repo, owner-run handover)

Carried from parent spec §9, narrowed: capture (expo-camera, already used by expenses) or
file/PDF pick → multipart `POST /api/v1/document-ingestions` (file + `kind`) using the proven
expenses FormData pattern → list screen of my ingestions + status chips; review deep-links say
"finish on web" for MVP. Contract pinned in the handover doc; **mobile is NOT in tonight's
implementation scope** — see `docs/handoff/HANDOVER-mobile-scan-capture.md` (erp-mobile repo).

## 10. Review UI (web, apps/web)

- New feature `document-ingestions`: list (status filter) + review page.
- Review page: source image/PDF viewer beside extracted fields; low-confidence + reconciliation-
  failed fields highlighted; inline correction with `MoneyInput`/`QuantityInput` (strings);
  supplier/product pickers (match-or-suggest, explicit create); for invoice kind a
  receipt-line mapping panel (uninvoiced receipt lines for the supplier) and the
  delivered/not-delivered fork; for BL kind a location picker + batch/expiry entry for
  batch-tracked lines. Commit + Reject actions. Upload also possible from web (drag-drop) —
  mobile is not a dependency for testing tomorrow.
- i18n via `t()` (new namespace), design tokens only, tenantScopedKey on all queries.

## 11. Cross-cutting

- **Tenancy:** ingestion rows in tenant DB; queued extraction job runs with NO CompanyContext —
  pass explicit tenant/company/currency; scale resolution per rule 19 (`getScale($currency)`).
- **Queues:** new named queue `ingestion` added to the `config/horizon.php` supervisor `queue`
  array (currently `['default','fiscal-projections','enrichment','images','imports']` —
  HorizonQueueCoverageTest enforces coverage; an unlisted queue is silently never consumed).
- **Authz:** permissions `document-ingestions.view/create/commit/reject` seeded in
  RolesAndPermissionsSeeder; routes `['api','auth:sanctum',SetPermissionsTeam::class]`;
  committers additionally enforce the target-surface permissions (§8). Module-gate both layers
  if scoped to a vertical — MVP: available to both verticals (procurement is shared), so no
  `module:` gate; revisit at gating audit.
- **Media:** MediaAsset with new `MediaOwnerType::DocumentIngestion` case + `storageSegment()`
  arm (match is exhaustive — both required); upload via existing `MediaUploadService::upload`
  (s3/MinIO disk; PDF already in `config/media.php` allowed mimes; non-image assets go READY
  immediately, no renditions needed for MVP; images get renditions on the existing `images`
  queue automatically). Serve source via the existing signed download route pattern.
- **Residency flag (unchanged):** cloud extraction sends images off-region; provider is per-deploy
  env; Azure-DI/self-hosted adapters remain the hardening path. Document the data flow in the
  deploy notes.
- **Cost guardrails:** cache extraction against `checksum` (re-upload/retry hits cache, no
  re-charge); provider + model recorded per ingestion for metering.

## 12. API surface (`/api/v1`, carried forward)

- `POST /document-ingestions` — multipart `file` + `kind`. Creates MediaAsset + ingestion,
  dispatches extraction. 201 → ingestion resource.
- `GET /document-ingestions` — paginated list (status/kind filters, tenant+company scoped).
- `GET /document-ingestions/{id}` — full review payload (extraction + suggestions + reconcile flags).
- `POST /document-ingestions/{id}/extract` — re-run (idempotent on checksum; rate-limited).
- `POST /document-ingestions/{id}/commit` — reviewed payload + chosen links → routes to committer
  by kind → returns `committed_type` + `committed_id`.
- `POST /document-ingestions/{id}/reject`.
- FormRequests: every money field regex-ceilinged `{1,3}`dp, qty `{1,4}`dp, all strings.
- erp-ml internal: `POST /v1/extract` (bearer service token).

## 13. Phasing — overnight waves (Codex; laptop orchestrator gates)

- **Wave S1 (backend core):** migration + model + enums + state machine + MediaAsset owner type +
  upload/list/detail/reject endpoints + queued extraction job + ExtractionClient (HTTP, fake in
  tests) + validation/reconciliation + suggestions. TDD; recorded extraction fixtures.
- **Wave S2 (erp-ml):** `/v1/extract` + port/registry + ClaudeExtractor + golden-file tests
  (recorded fixtures; no live LLM in CI). Separate repo dir (`apps/erp-ml`) — own task brief.
- **Wave S3 (committers):** `SupplierDeliveryNoteCommitter` + `SupplierInvoiceCommitter`
  (+ commit endpoint wiring, idempotency, duplicate guards, authz/policy re-checks). Gated by
  inventory-costing-reviewer (receipt path) + treasury-reviewer (SI path) before merge.
- **Wave S4 (web UI):** upload + list + review + commit. Vitest on touched suites; Playwright
  smoke next morning against the local stack.
- Mobile capture: separate handover, owner-run, after S1 is deployed locally.

## 14. Testing strategy (carried forward, amended)

Domain: state-machine transitions incl. `Committing` crash-recovery; bcmath reconcile
(decimal-comma). Application: committer routing; BL committer → real standalone-receipt service
(RefreshDatabase, real models, batch-tracked product case); SI committer both forks. Contract:
ExtractionClient against recorded erp-ml fixtures. HTTP: authz matrix, validation envelopes
(`{error:{errors}}`), precision ceilings, checksum-duplicate 422. FE: review component confidence
flags + string payloads. **No live LLM calls in any test.** Tests BY PATH on the laptop.

## 15. Risks & open questions

- **Line-table accuracy on noisy BLs** — known weak spot; mitigation = reconcile + mandatory
  review + budget review time. Need labeled FR/TN samples for eval (still owed).
- **BLs without prices** → auto-PO price source correctness matters for GR-IR value; surfaced
  per line in review UI (§8a) — owner should confirm the default (current purchase price) is right.
- **Batch/expiry extraction** on pharma BLs (lot numbers often present) — extractor schema
  includes optional `batch_number`/`expiry_date` per line; review UI requires them only when the
  matched product is batch-tracked.
- **erp-ml deploy** — the service exists but this is its first production-critical endpoint;
  local testing tomorrow runs it via uvicorn per repo README.
- Expert-comptable countersign of the Tunisia/France BL legal layer (from P2P research) — still
  owed, does not block MVP testing.
