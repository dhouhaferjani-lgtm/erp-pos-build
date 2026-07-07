# Wave S1 Code Review — DocumentIngestion module (feat/scan-to-document)

- **Scope:** `git diff a35c6b9d8..b834076a8` (5 commits, ~3650 lines) vs plan Tasks 1–5 + Global Constraints
  (`docs/superpowers/plans/2026-07-06-scan-to-document-plan.md`) and spec B.
- **Reviewer:** Claude (code review agent), 2026-07-07.
- **Verdict: 1 BLOCKER, 6 MAJOR, 9 MINOR — Wave S3 must NOT start on this base until the BLOCKER
  and M2/M3/M4 are fixed.** The BL (supplier_delivery_note) path — the parapharmacy main path and
  the input to S3's `SupplierDeliveryNoteCommitter` — fails end-to-end in a real worker.

All paths below are relative to `apps/api/` unless noted.

---

## BLOCKER

### B1 — ExtractDocumentJob passes no explicit currency; every BL extraction fails in a real worker

- `app/Modules/DocumentIngestion/Application/Jobs/ExtractDocumentJob.php:81` calls
  `$reconciler->reconcile($result)` with **no currency argument**.
- `app/Modules/DocumentIngestion/Application/Services/ExtractionReconciler.php:19-22` made the
  plan-mandated `string $currency` parameter optional (`?string $currencyCode = null`) and falls
  back to the **extraction header currency**, then calls `$this->scaleResolver->getScale($currency)`.
- A BL has no extracted currency (`tests/Fixtures/document_ingestion/extraction_bl_fr.json` header
  = number/delivery_date/po_reference only), so `getScale(null)` is reached.
- `app/Shared/Infrastructure/CurrencyScaleResolver.php:45-53`: `getScale(null)` resolves via
  `CompanyContext->getCompany()` and **throws `UnboundCompanyContextException` when no company is
  bound** — which is exactly the queue-worker state: `app/Jobs/Concerns/BindsTenantContext.php`
  binds *tenancy* (`$tenant->run($fn)`), never CompanyContext (CLAUDE.md rule 20: "Queued jobs /
  fiscal projections run with NO CompanyContext").
- Result: the exception is swallowed by the generic catch at `ExtractDocumentJob.php:100-103` →
  `markFailed(...'EXTRACTION_FAILED')` → **every supplier_delivery_note ingestion lands in
  `Failed`** with a misleading error, on both retry attempts. Re-extract loops to the same failure.
- **Why tests didn't catch it:** `tests/Unit/DocumentIngestion/ExtractionReconcilerTest.php:55-66`
  injects a fake resolver whose `getScale(null)` returns 3 — masking the real resolver's throw —
  and `tests/Feature/DocumentIngestion/ExtractDocumentJobTest.php` only drives the **invoice**
  fixture (which happens to carry `header.currency=TND`) through the job. There is no BL-through-job
  test. This is precisely the rule-20 trap ("binding context in setUp masks the worker reality"),
  here via a fake instead of setUp.
- **Secondary effect (same root cause):** for invoices, the reconciliation scale is derived from the
  **LLM-extracted** currency string. An unknown/garbled code (e.g. `"DT"`, `"TND "` won't — trim
  helps — but `"DT"`, `"Dinar"`) silently maps to `DEFAULT_SCALE = 2`
  (`app/Shared/Domain/CurrencyScale.php:49,62-64`) → false `*_mismatch` flags on 3dp TND invoices.
- **Plan conformance:** direct violation of plan Task 5 (`reconcile(ExtractionResultData $r, string
  $currency)` — required param) and Task 4 ("NO CompanyContext assumptions — pass company/currency
  explicitly (rule 19/20)").
- **Fix:** job loads the company (it already has `companyId`) and passes `company->currency` into
  `reconcile()`; make the parameter required again; add a BL-fixture job test using the **real**
  resolver with no CompanyContext bound.

---

## MAJOR

### M1 — Reconciler treats untrusted extracted strings as trusted numerics; unparseable value bricks the document

`app/Modules/DocumentIngestion/Application/Services/ExtractionReconciler.php:37,77,80,88-98,112-118`
feed raw extracted `value` strings straight into `CurrencyScale::bcformatStrict` / `bcadd`
(`app/Shared/Domain/CurrencyScale.php:126-144` — `bcadd($trimmed,'0',$scale)` throws `ValueError`
on non-numeric input). OCR/LLM output like `"1 234,560"`, `"N/A"`, `"12,500"` (decimal comma) →
`ValueError` → caught as `\Throwable` in the job → whole ingestion **Failed** instead of a
reconciliation flag; re-extracting the same document reproduces the same crash. The reconciler is
the untrusted-input boundary — unparseable money/qty should produce flags (e.g.
`line_2_total_unparseable`), never mark the extraction Failed.

### M2 — Uninvoiced receipt-line query: LIMIT applied before the uninvoiced filter, and no SQL uninvoiced predicate

`app/Modules/DocumentIngestion/Application/Services/MatchSuggestionService.php:192-200` takes the
**20 most recent** posted receipt lines for the supplier, then filters `matchableQty > 0` in PHP
(:203-207). The plan (Task 5) explicitly required "a NEW per-supplier variant with the same
semantics" as `whereColumn('received_qty','>','quantity_invoiced')` (PurchaseOrderController.php:880-911
precedent). For an active supplier whose last 20 receipt lines are already invoiced, genuinely
uninvoiced older lines are silently invisible to the review UI — S3's invoice committer will offer
no mapping candidates. Push the `received_qty > quantity_invoiced` predicate into SQL (keep
`ReceiptLineConsumptionPlanner::matchableQty` for the exact qty — semantics verified correct:
`ReceiptLineConsumptionPlanner.php:78-84` = `received_qty − quantity_invoiced` at scale 4, free
split tracked separately in `freeMatchableQty`, and `matchable <= 0` lines are skipped so free-only
lines are correctly not paid-invoice suggestions).

### M3 — Full-catalog in-memory matching, re-executed per extracted line

- `MatchSuggestionService.php:105-110`: `Product::query()...->get()` loads **the entire active
  product catalog** (all columns incl. `oem_numbers`/`cross_references` JSONB) — and this runs
  **once per extracted line** via the `array_map` at :34-37. A 30-line invoice against a 20k-product
  parapharmacy catalog = 30 full-table loads inside a queued job (`timeout = 180`).
- `MatchSuggestionService.php:57-63`: same pattern for all partners (once, less severe).
- Plan Task 5 specified indexed lookups (`sku exact → barcode exact → cross_references/oem contains
  → ILIKE '%name%' top 5`). Replace with per-line targeted queries (sku/barcode `where`, JSONB
  containment, `ILIKE` with bound parameters).
- Note: as a side effect the current implementation is **injection-safe** (no user-controlled text
  ever reaches SQL — matching is in-PHP `Str::contains`); when converting to ILIKE, use parameter
  binding and escape `%`/`_` in the extracted term.

### M4 — `reExtract` bypasses the state machine for NeedsReview rows

`app/Modules/DocumentIngestion/Application/Services/IngestionService.php:112-117`: the
NeedsReview→Extracting move is done by raw `$ingestion->status = ...; $ingestion->save();` because
`IngestionStatus::allowedNext()` (`Domain/Enums/IngestionStatus.php:26`) does not declare
NeedsReview→Extracting. The plan is internally contradictory here (Task 1 says transitions
"exactly" exclude it; Task 3 requires re-extract from NeedsReview), but silently bypassing
`transitionTo` is the wrong resolution — it destroys the invariant every other caller relies on and
leaves a non-atomic check-then-set that can interleave with the Wave S3 commit claim (a row could
be flipped to Extracting after a committer has begun). Fix: add `Extracting` to
`NeedsReview->allowedNext()` (documented as the re-extract edge) or make the flip a guarded atomic
`UPDATE ... WHERE status='needs_review'`, and delete the bypass. Also untested: the only re-extract
test drives the Failed path (`tests/Feature/DocumentIngestion/IngestionUploadTest.php:195-203`).

### M5 — `provider_model` is never populated; erp-ml provider metadata discarded

`ExtractDocumentJob.php:87-88` hardcodes `provider='erp_ml'`, `provider_model=null`.
`ErpMlExtractionClient.php:47-56` receives `{provider, model, result}` and drops `provider`/`model`
on the floor because `ExtractionClientInterface::extract()` returns only `ExtractionResultData`.
Plan Task 4 requires storing `provider`/`provider_model`; the migration created the columns
(`2026_07_06_200000_create_document_ingestions_table.php:22-23`) and they will stay NULL forever —
no extraction provenance (which Claude model produced this? was it the escalation model?). The
TASK-LOG records this as a known deviation but it was never resolved. Fix: return a small envelope
(result + provider + model) from the client, or a dedicated `ExtractionResponse` DTO.

### M6 — `confidence_summary` JSONB has no faithful DTO; `ConfidenceSummaryData` is dead code

`Application/DTO/ConfidenceSummaryData.php` is created (Task 2 file list) but has **zero usages**
(grep across app/ and tests/). The job instead persists a hand-rolled array
(`ExtractDocumentJob.php:83-84,135-160`) whose real shape is
`{average_confidence: float|null, low_confidence_fields: list<string>, reconciliation: {...}}` —
`average_confidence` nullable and an extra `reconciliation` key, both contradicting the generated
TS type (`packages/shared/types/generated.d.ts`: `averageConfidence: number`, no reconciliation).
Violates rule 3 / plan constraint "JSONB columns get PHP DTOs" and hands Wave S4 a lying type.
Fix: extend `ConfidenceSummaryData` to the real shape (nullable average + nested
`ReconciliationData`) and build it in the job.

---

## MINOR

1. **Duplicate-check TOCTOU → 500 + orphaned media.** `IngestionService.php:37-47` check-then-insert;
   a concurrent same-checksum upload passes the check, uploads the asset (:57-74), then hits the
   partial unique index at `DocumentIngestion::create` (:77) → `QueryException` 500 (not the 422
   envelope) and an orphaned MediaAsset+MediaAttachment. Catch the unique violation and re-throw the
   ValidationException; consider creating the row before the S3 upload or wrapping in a transaction.
2. **Checksum of `false`.** `IngestionService.php:35` — `(string) file_get_contents(...)` silently
   hashes `""` if the tmp file read fails; use `hash_file('sha256', ...)` and fail loudly.
3. **No cross-company 404 test.** Controller scoping is correct in code — `findScoped`
   (`DocumentIngestionController.php:183-198`) filters tenant+company on show/extract/reject, index
   at :59-61, and `Str::isUuid` + route `whereUuid` (routes.php:26,31,36) — but
   `IngestionUploadTest.php` never asserts that another company's ingestion id returns 404.
   Add it before S3 (commit endpoint will clone this helper).
4. **Partial-index test covers `rejected` but not `failed`.**
   `IngestionStateMachineTest.php:67-83` proves rejected rows don't block re-upload; the `failed`
   carve-out (`WHERE status NOT IN ('rejected','failed')`, migration :40-44 — verbatim per plan) is
   untested.
5. **N+1 despite eager load.** `MatchSuggestionService.php:226` — `$line->poLine()->first()`
   re-queries per line even though :193 did `->with(['poLine'])`; use `$line->poLine`.
6. **VAT match is raw equality.** `MatchSuggestionService.php:70` — no normalization (spaces, case,
   `FR 12 345 678 901` vs `FR12345678901`); OCR output will frequently miss the exact-match tier.
7. **`CurrencyScale::bcformatStrict($qty, 4)` for quantities** instead of `QuantityScale`
   (`ExtractionReconciler.php:113`, `MatchSuggestionService.php:213`) — follows the
   `ReceiptLineConsumptionPlanner` precedent so cosmetic, but new code should use the quantity
   helper. The two `// precision-ok:` exemptions (`MatchSuggestionService.php:92,144,205`) are the
   sanctioned `ForbidHardcodedBcmathScale` mechanism with sound justifications — not suppressions.
8. **Generated TS emits `Array<any>`** for `SuggestionsData` candidates, `ReconciliationData.flags`,
   `sourceBbox`, `lowConfidenceFields` (generated.d.ts diff) — consistent with 74 pre-existing
   `Array<any>` occurrences (transformer doesn't read `@param` array shapes), but Wave S4 will
   consume the suggestion payloads untyped. Consider `@var` annotations or dedicated candidate DTOs.
9. **Suggestion coverage is one mega-test.** `MatchSuggestionServiceTest.php:107-158` (real
   `StandaloneReceiptService`, real seeds — good) but no negative cases: fully-invoiced line
   excluded, other-supplier receipts excluded, free-only line excluded, no-match document.
   `reject()` also discards `$userId` (`IngestionService.php:95` `unset`) — no rejected-by audit.

---

## Verified conform (with citations)

**Task 1 — state machine/migration:** transitions exactly per plan incl. Extracting self-transition
and Committing→[Committed,NeedsReview] (`IngestionStatus.php:22-29`); self-transition tested
(`IngestionStateMachineTest.php:34-35`); partial unique index raw-statement verbatim
(migration:40-44) and behavior-tested (:67-83); indexes `(company_id,status)` /
`(company_id,kind,created_at)` (migration:35-36); `transitionTo` throws `InvalidIngestionTransition`
(`DocumentIngestion.php:68-76`); ServiceProvider with `loadRoutesFrom` registered in
`bootstrap/providers.php:15,68`.

**Task 2 — DTOs:** all money/qty values are strings; `ExtractedFieldData::fromPayload` **rejects**
non-string `value` (`ExtractedFieldData.php:27-29`), tested with a float unit_price
(`ExtractionResultDataTest.php:33-42`); round-trip equality tested for both fixtures; BL fixture
hydrates `unitPrice === null` (:23-31); fixtures use 3dp/4dp strings only, arithmetic internally
consistent (`extraction_invoice_fr.json`: 125.000+93.750+26.000=244.750; 244.750+17.133+1.000=262.883).
`confidence: float` / `sourceBbox: list<float>` are not money — acceptable floats.

**Task 3 — routes/security/media/perms/queue:**
- Middleware `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`
  (routes.php:10-15) — matches the Procurement pattern the plan pointed at.
- `can:` gate on every route (routes.php:17,21,25,30,35); throttle 6/min on extract (:30);
  `whereUuid` on `{id}` routes plus in-controller `Str::isUuid` defense (controller:185).
- Company scoping on list/detail/extract/reject via CompanyContext (controller:59-61,183-198);
  duplicate check company-scoped (`IngestionService.php:37-41`).
- Media wiring exactly per the M4 recipe: pre-generated ingestion uuid → sha256 → duplicate 422 →
  `MediaUploadService::upload` (verified signature `MediaUploadService.php:106-114`) →
  `MediaAttachmentService::attach` (`MediaAttachmentService.php:48-56`) → row created with
  `media_asset_id = $asset->id` → job dispatch (`IngestionService.php:34-88`); asset type by mime
  (:50-52); allowed mimes from `config/media.php` (images+pdf both present, config:15-30).
- Detail `source_url` = `MediaUrlResolver::forAttachment` relative signed URL, TTL 60 min
  (`MediaUrlResolver.php:39,83-88`), tested (`IngestionUploadTest.php:164-177`).
- Re-extract guard Failed|NeedsReview (`IngestionService.php:108-110`) — but see M4.
- Seeder: 4 permissions created (`RolesAndPermissionsSeeder.php:126-129`) and granted to exactly
  admin (Permission::all()), manager (:439) and accountant (:662) — the same roles holding
  `supplier-invoices.create-pending` (:438,661), per plan.
- Horizon queue `'ingestion'` appended to the single supervisor queue array (`config/horizon.php:209`).
- `MediaOwnerType::DocumentIngestion` + `storageSegment()='ingestions'` (enum diff), exhaustive-match
  sweep clean (only the enum itself matches on `MediaOwnerType`), enum test updated.

**Task 4 — client/job:** `ExtractionClientInterface` + `ExtractionHints` (plain readonly DTO, plan
minor-3) + `ExtractionFailedException` with `failureCode()` in `app/Shared/Contracts/`; binding in
`DocumentIngestionServiceProvider.php:15`; `config/services.php:149-152` erp_ml url+token;
`onQueue('ingestion')` in constructor (job:46), `tries=2`, `backoff=[10,60]`, `timeout=180` > client
timeout 120 (`ErpMlExtractionClient.php:35`); tenancy via `BindsTenantContext::withTenantContext` +
explicit tenant/company WHERE on every query (job:55-59,64-67); failure path persists structured
`{code,message}` and rethrows (job:96-104,119-130), tested
(`ExtractDocumentJobTest.php:74-101`); client unit-tested against 200-valid / 200-malformed / 500 /
401 with `Http::fake()` (`ErpMlExtractionClientTest.php`); queue-name asserted (:38-45).

**Task 5 — reconciler/suggestions:** bcmath only, injected `CurrencyScaleResolverInterface`,
`bcmul` intermediate at `$scale+1` then compare at `$scale` (`ExtractionReconciler.php:109-118`);
invoice flags subtotal/grand_total(+stamp_duty)/per-line named `line_{n}_total_mismatch` (:39-41,
80-99), BL kind: qty mandatory (`line_{n}_quantity_missing`, :29-33), price check only when both
unitPrice+lineTotal present (:35), no tax checks for BL (invoiceFlags gated on kind, :45-47);
reconciler+suggestions wired into the job and persisted (job:81-94), suggestion persistence asserted
(`ExtractDocumentJobTest.php:69-71`); receipt-line candidates carry po_line_id + receipt_line_id +
uninvoiced qty + unit price (`MatchSuggestionService.php:209-215`), built through
`ReceiptLineConsumptionPlanner::matchableQty` (not a hand-rolled subtraction) and verified against a
receipt posted by the **real** `StandaloneReceiptService`
(`MatchSuggestionServiceTest.php:124-157`); scores are strings compared with `bccomp` (:92,144).

**Test quality:** real models + `RolesAndPermissionsSeeder` + `RefreshDatabase` in the HTTP suite
(`IngestionUploadTest.php:73-74`), `AssertsApiValidation` for both 422 paths (:127,147), no mocked
domain services (the only fake is the `ExtractionClientInterface` boundary — sanctioned), fixtures
arithmetically real. Gaps are listed in MINOR 3/4/9 and the masking fake in B1.

**Hygiene:** no TODO/FIXME/placeholder/`@phpstan-ignore` anywhere in the new code (grep clean);
TASK-LOG records red→green runs BY PATH, PHPStan L8 clean, Pint, typescript:transform. No scope
creep: every touched file is in the Task 1–5 file lists.

---

## Gate decision for Wave S3

**Fix round required before S3.** Rationale:
- B1 kills the exact artifact S3's `SupplierDeliveryNoteCommitter` consumes (a NeedsReview BL
  ingestion can never exist in production).
- M2 starves S3's `SupplierInvoiceCommitter` mapping UI of candidates.
- M4's non-atomic NeedsReview flip conflicts with S3's atomic `needs_review|committing` claim
  (plan Task 7 step 1).
- M1/M5/M6 can land in the same fix round cheaply; MINORs 1-4 are strongly recommended pre-merge,
  the rest can trail.
