# P2P Flexible Entry Points — Implementation Plan (Rev 2)

> Rev 2 integrates BOTH adversarial plan reviews (Codex `docs/superpowers/reviews/
> 2026-07-05-p2p-entry-points-plan-codex-review.md`, Claude `...-plan-claude-review.md`
> — both NEEDS-REVISION; every BLOCKER/MAJOR is folded in below, tagged [C-*]/[CL-*]).
>
> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or
> superpowers:executing-plans. **This campaign executes via Codex CLI waves**: the
> orchestrator writes `docs/sessions/CODEX-TASK-w<N>.md` per wave (wave tasks + spec
> §refs inlined + Global Constraints copied verbatim), dispatches
> `cd <worktree> && node ~/.claude/plugins/cache/openai-codex/codex/1.0.4/scripts/codex-companion.mjs task --write --background --fresh "Read docs/sessions/CODEX-TASK-w<N>.md and execute it fully."`
> (flags as separate argv BEFORE the prompt). Codex CANNOT git-commit → per-task evidence
> appended to `docs/sessions/TASK-LOG-w<N>.md`; orchestrator reviews the diff and
> commits. Codex sandbox blocks PHPStan parallel workers → `./vendor/bin/phpstan --debug`.

**Goal / Architecture / Tech Stack:** unchanged from Rev 1 — see spec
`docs/superpowers/specs/2026-07-05-p2p-entry-points-design.md`.

## Global Constraints (copy verbatim into every CODEX-TASK brief)

- Base branch `feat/p2p-entry-points` (worktree `apps/erp.p2p-flow`, base `b3e81a13e`).
  Verify `git rev-parse HEAD` before working.
- **NEVER run the full PHPUnit suite** — tests BY PATH only.
- **No git write commands** — append evidence to the wave TASK-LOG.
- PHP strict types; constructor injection `private readonly` ONLY (no `app()`); DTOs not
  `mixed`; enums for status columns; PHPStan L8 on new code (`./vendor/bin/phpstan --debug`).
- **Precision (rule 19):** money/qty strings end-to-end; FormRequest regexes — money
  `/^-?\d+(\.\d{1,3})?$/`, qty `/^-?\d+(\.\d{1,4})?$/`; `CurrencyScale::bcformatStrict`
  at rest; inject `CurrencyScaleResolverInterface`.
- **JSON payload queries [CL-M4]:** ONLY the portable Laravel APIs —
  `whereJsonContainsKey()` / `whereJsonDoesntContainKey()` for key existence,
  `whereJsonContains()` for array containment (existing pattern:
  `Document::payloadLinkedSupplierInvoiceChildren`). NEVER `LIKE` on payload, never raw
  driver-specific JSON SQL.
- Module boundaries (rule 6); deptrac ratchet must not regress.
- Routes `['api','auth:sanctum',SetPermissionsTeam::class]` (Inventory routes also carry
  `EnforceTokenTenantClaim` — mirror the file you touch). Permissions via
  `RolesAndPermissionsSeeder`; **naming MUST mirror the seeder's existing prefixes for
  the same resource** (e.g. `goods-receipt.edit-price` is singular — inspect and match;
  cite chosen names in TASK-LOG) [CL-m2].
- FE: `t()` (ns `purchases`), design tokens, `tenantScopedKey([...])`,
  `<MoneyInput>`/`<QuantityInput>` (no parseFloat on money).
- Types from backend: `CACHE_STORE=array php artisan typescript:transform` after DTO
  changes; never hand-edit `packages/shared/types/generated.d.ts`.
- Every task: red test FIRST → minimal implementation → green → TASK-LOG entry (task #,
  files, red→green evidence, exact commands).
- SQLite test DB: `FOR UPDATE` and partial-unique-index WHERE clauses are pgsql-verified
  only; write tests meaningful on SQLite and note pgsql caveats in TASK-LOG.

## File map — Rev 1 map still applies, plus:
```
apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatchSnapshotService.php  ▸ W5 [C]
apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptPdfService.php        ▸ W6 [C]
apps/api/database/migrations/tenant/2026_07_06_130000_create_procurement_idempotency_keys.php ▸ W4 [CL-M2]
apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php     ▹ W3 [C-B1]
```

## Reviewer gates — unchanged from Rev 1.

---

# WAVE 1 — PO integrity guard + GR-IR drift report

### Task 1.1: Block PO line mutation once receipt lines exist

As Rev 1, with corrections [C-m]:
- `PurchaseOrderController` ALREADY constructor-injects `GoodsReceiptService`
  (PurchaseOrderController.php:66-76) — add the new public method to the existing
  injection, do not re-inject.
- Test arrangement helpers: follow `tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php`
  (NOT the nonexistent `tests/Feature/Procurement/GoodsReceiptLedgerTest.php`); make the
  new test self-contained if helpers don't transplant.
- Interface produced (unchanged): `GoodsReceiptService::poLineIdsWithReceipts(array $poLineIds): array`.
- Guard behavior, error code `PO_LINES_LOCKED_BY_RECEIPTS`, test matrix (replacement
  rejected 422 / unreceipted editable / safe-header editable): as Rev 1 steps 1-5.

### Task 1.2: `procurement:grir-drift` — MOVEMENT-grain [C-B2/CL-B2 rewrite]

**Ground truth (cited by both reviews):** GR-IR idempotency is
`source_type='goods_receipt'`, **`source_id = stock movement id`**
(`GeneralLedgerService.php:1043-1060, 1101-1109`); `GoodsReceived` carries `movementId`.

**Files:** create `GrirDriftReportCommand.php` + test `tests/Feature/Accounting/GrirDriftReportTest.php`.

**Interfaces:** artisan `procurement:grir-drift {--tenant=}` — for every POSTED goods
receipt, for each receipt-line **movement id** (paid `movement_id` always; free
`free_movement_id` ONLY if the GL method creates entries for zero-cost movements — READ
`createGoodsReceiptGrIrEntry` first: if it skips zero-amount entries, exclude free
movements from the expectation and cite the line in TASK-LOG), left-join
`journal_entries (source_type='goods_receipt', source_id=movement_id)`; report missing,
grouped by receipt_number; exit 0 clean / 1 drift.

- [ ] red test: receive PO (listener posts GR-IR) → exit 0; delete the movement-keyed
  journal entry → exit 1 + output contains receipt_number. Also: a posted receipt whose
  free movement legitimately has no entry does NOT flag.
- [ ] implement (single-tenant-connection pattern per `RematchDraftSupplierInvoicesCommand`)
  → green → PHPStan → TASK-LOG.

---

# WAVE 2 — Policy columns, fail-closed resolver, presets, settings UI

### Task 2.1: Migration + model — as Rev 1 (columns/defaults/casts unchanged).

### Task 2.2: Fail-closed resolver [C-M §12 + CL-m1]

- Resolver public API is **`forCompany()`** (and the default construction lives in
  `ProcurementPolicy::defaultForVertical()` / `firstOrCreateForCompany()`,
  ProcurementPolicy.php:90-119) — test snippets use `forCompany()`, NOT `resolve()`.
- [ ] red tests: (a) missing row → toggles false/false, approval true, regardless of
  vertical; (b) **old-shape policy object** (attributes absent — construct a
  ProcurementPolicy without the new keys, pre-migration simulation): guarded accessors
  treat `allow_receipt_first`/`allow_invoice_first` as FALSE and
  `invoice_first_requires_approval` as TRUE [spec §12 missing-column case]. Implement via
  accessor methods on the model (e.g. `allowsReceiptFirst(): bool` null-safe defaults)
  that ALL consumers use instead of raw attribute reads.
- [ ] implement → green → existing paths `tests/Feature/Procurement/ProcurementPolicyResolverTest.php`,
  `ProcurementPolicyApiTest.php`, `ProcurementPolicyCheckConstraintTest.php` green → TASK-LOG.

### Task 2.3: Preset mapping [C-m]

- The preset enum's mapping methods are **`fields()` / `values()`**
  (ProcurementPreset.php:20-50) — extend those.
- Tests split: enum bundle shape → `tests/Unit/Procurement/ProcurementPresetTest.php`;
  API apply/preservation behavior → `tests/Feature/Procurement/ProcurementPolicyApiTest.php`
  (existing preservation cases at :124-198 — extend, don't duplicate).
- Mapping + not-mapping-approval + hand-tuned-tolerance preservation: as Rev 1.

### Task 2.4: Permissions — as Rev 1, naming per Global Constraints convention rule
(inspect seeder; mirror `goods-receipt.*` / supplier-invoice resource prefixes exactly;
cite final names + role assignments in TASK-LOG).

### Task 2.5: Policy API + settings UI — as Rev 1 (backend =
`ProcurementPolicyController` + `UpdateProcurementPolicyRequest`, both exist).

---

# WAVE 3 — Draft receipts, GRN-at-post, matcher isolation

### Task 3.1: Migration — nullable receipt_number AND nullable line cost columns [CL-BL1]

**Files:** one migration `2026_07_06_110000_goods_receipt_draft_columns.php` + schema
test extension (follow `tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php`).

**Produces:**
- `goods_receipts.receipt_number` nullable; unique → partial
  `UNIQUE (company_id, receipt_number) WHERE receipt_number IS NOT NULL` (raw pgsql
  statement; on SQLite Laravel emits the partial index too — verify with the schema test;
  note driver caveat in TASK-LOG) [CL-m5 wording fixed].
- `goods_receipt_lines.landed_unit_cost`, `accrual_unit_cost`, `effective_unit_cost` →
  **nullable** (currently NOT NULL, migration `2026_07_04_100000...:45-47`): a Draft line
  has no costing until post(); post() fills all three (bcformatStrict). `movement_id`/
  `free_movement_id`/`received_unit_price` already nullable.
- [ ] red (insert draft header w/ NULL number + line w/ NULL costs currently fails) →
  migrate → green → TASK-LOG.

### Task 3.2: Posted-only filters — FULL consumer list [C-B1]

**Files (complete list — both reviews' enumeration):**
- `Procurement/Application/ReceiptLineConsumptionPlanner.php` (base query :30-34)
- `Procurement/Application/SupplierInvoiceMatcher.php` (paid aggregate :151-153, free :414-416)
- `Procurement/Application/SupplierInvoicePostingService.php` (lock query :82-89, sums :174-176, :222-224)
- `Procurement/Application/SupplierCreditNotePostingService.php` (post() lock :151-158,
  `receiptLedgerSum()` :514-518)
- `Document/Presentation/Controllers/PurchaseOrderController.php` — `index()`
  `has_uninvoiced` filter (:230-243) AND `receiptLines()` picker (:805-825)
- `Inventory/.../GoodsReceiptService.php` PO-counter derivations if they read lines

**Implementation:** shared Eloquent scope `GoodsReceiptLine::scopePostedReceipts()`
(whereHas/join `goods_receipts.status = GoodsReceiptStatus::Posted`) applied at EVERY
site; grep `rg -n "goods_receipt_lines|GoodsReceiptLine" apps/api/app --type php` at the
end and justify any remaining unfiltered site in TASK-LOG.

**Interfaces:** Consumes Task 3.1 (NULL-cost draft lines insertable) [CL-m7]. Test
constructs Draft via direct model create (`status=>Draft, receipt_number=>null`, lines
with NULL movement/cost fields, `po_line_id` set — it is NOT NULL).

- [ ] red tests: draft lines create NO matchable window (matcher `matchableQty` '0.0000');
  PO `has_uninvoiced` filter ignores drafts; `receiptLines()` picker excludes drafts;
  supplier-credit-note reversal with only a draft line present stays on the legacy
  PO-line path (assert via its behavior — follow SupplierCreditNoteGlTest arrangement);
  posted-receipt regression twins for each.
- [ ] implement → green → Wave 5 matcher suites BY PATH (`SupplierInvoiceMatcherReceiptBasisTest`,
  `SupplierInvoiceReceiptClearingTest`, `SupplierInvoiceSnapshotTest`,
  `RematchDraftsCommandTest`, `SupplierCreditNoteGlTest`) → TASK-LOG.

### Task 3.3: GoodsReceiptLifecycleService — behavior-preservation pins [C-B2]

**Ground truth to preserve** (cite each in TASK-LOG when pinned):
- Current signature: `receiveGoods(Document $purchaseOrder, array $receivedQuantities,
  array $batchData = [], array $freeQuantities = [], array $receivedUnitPrices = [],
  ?string $priceOverrideReason = null, ?string $actorId = null): GoodsReceiptResult`
  (:59-67) — **unchanged**, including return type.
- Paid branch: movement → `GoodsReceived` dispatch (synchronous, BEFORE
  `GoodsReceiptLine::create`) → line with `movement_id` (:313-389).
- Free branch: SECOND zero-cost movement + its own `GoodsReceived` + `free_movement_id`
  + `free_quantity_received` counter (:275-310, 365-389).
- GL idempotency = movement id (see W1.2). Event ORDER preserved exactly; any deviation
  is a plan change requiring orchestrator sign-off, not an implementation choice.

**Interfaces:**
- `createDraft(Document $po, array $receivedQuantities, array $batchData, array
  $freeQuantities, array $receivedUnitPrices, ?string $priceOverrideReason, string
  $actorId, ?string $externalReference = null, ?string $externalDate = null): GoodsReceipt`
  — includes `priceOverrideReason` [C-B2]; stores requested prices/reason on the draft
  WITHOUT permission check (checked at post); NULL number/costs/movements; no events.
- `post(GoodsReceipt $receipt, string $actorId, bool $failClosedGrir = false): GoodsReceipt`
  — transaction + sorted ProductCostLock + GRN `generateForKey('goods_receipt','GRN')` +
  BOTH branches (paid + free movements, per-movement `GoodsReceived` in today's order) +
  `movement_id`/`free_movement_id` backfill + costs computed (bcformatStrict) +
  price-override permission enforcement + audit stamps + PO counters/status; when
  `$failClosedGrir` call GL directly per movement and RETHROW (spec §2.2).
- `receiveGoods(...)` = `createDraft` + `post` inside its existing single transaction.

- [ ] red: paid+free receipt through refactor produces IDENTICAL end-state to a
  pre-refactor golden (2 movements, 2 GR-IR entries keyed by movement ids, both line
  movement columns set, counters, override audit stamped when reason given, PO status);
  draft-only produces nothing.
- [ ] implement → green → regression BY PATH: `tests/Feature/Inventory`,
  `tests/Feature/Procurement`, `tests/Feature/Accounting/SupplierInvoiceGlTest.php`,
  `tests/Feature/Accounting/SupplierCreditNoteGlTest.php` → TASK-LOG.

### Task 3.4: Draft CRUD + post endpoints & FE — as Rev 1, plus:
- **Draft deletion is a hard DELETE** (no number, no stock, no GL — nothing to preserve);
  therefore **remove `GoodsReceiptStatus::Cancelled`** in this task (currently
  unreachable; Phase 2 corrective receipts re-introduce it) — enum-truth requirement
  spec §9.4 [C-M]. Sweep `rg -n "GoodsReceiptStatus" apps/api` for exhaustive matches.

---

# WAVE 4 — Receipt-first (deps: W2, W3)

### Task 4.1: BL identity columns — as Rev 1; the "picker" is
`PurchaseOrderController::receiptLines()` (:791-848), not a separate serializer — extend
its payload there [C-m].

### Task 4.2: StandaloneReceiptService [+CL-M1, CL-M2, C-M actor]

Rev 1 contract, with corrections:
- DTO gains **`source: string`** (`'standalone_receipt'` | `'invoice_first'`) written
  into `payload->auto_generated.source` — Task 5.1 passes `invoice_first`; the 5.4 gate
  keys on it [CL-M1].
- **`PurchaseOrderService::confirm()` signature change is MANDATORY**:
  `confirm(Document $purchaseOrder, ?string $actorId = null)`, `confirmed_by =
  $actorId ?? auth()->id()`; update `PurchaseOrderController::confirm()` to pass
  `$user->id` [C-M].
- **Idempotency = DB-enforced** [CL-M2]: new migration
  `procurement_idempotency_keys (id uuid pk, tenant_id, company_id, idempotency_key
  varchar(64), purchase_order_id uuid null, goods_receipt_id uuid null, timestamps,
  UNIQUE (company_id, idempotency_key))`. t1 INSERTs the key row FIRST inside its
  transaction; unique-violation → load + return the existing result. No SELECT-then-INSERT.
- Compensation + policy fail-closed + tests: as Rev 1, plus a test that two sequential
  executes with the same key return the same PO/receipt ids and create no duplicates
  (DB-unique makes the concurrent case safe; note pgsql-vs-SQLite unique behavior is
  identical here — plain unique, not partial).

### Task 4.3: Auto-PO exposure [C-M JSON + matrix]

- Queries: default exclusion `whereJsonDoesntContainKey('payload->auto_generated')`;
  inclusion/badge `whereJsonContainsKey('payload->auto_generated')` — Laravel 12
  portable API (Builder.php:2320-2349); NO raw SQL, NO like [C-M].
- Test matrix EXTENDED [C-M/CL-m4/CL-m6]: list default-exclude / opt-in include / detail
  accessible / `is_auto_generated` in `DocumentData`; **document chain readers include
  the auto-PO** (assert `getDocumentChain`/children on an SI created from an auto-PO);
  **RFQ award guard non-interference** (an auto-PO for the same supplier does NOT trip
  `RFQ_GROUP_ALREADY_AWARDED` — arrange an RFQ group + unrelated auto-PO; pin);
  **AgedPayables includes the auto-PO-backed payable — CREATE
  `tests/Feature/Accounting/AgedPayablesAutoPoTest.php`** (no aged-payables API test
  exists today — do not "locate" one).

### Task 4.4: Endpoint + "Nouvelle réception" page — as Rev 1.

---

# WAVE 5 — Invoice-first (deps: W2, W3, W4)

### Task 5.1: InvoiceFirstOrchestrator — as Rev 1, passing `source='invoice_first'`
through StandaloneReceiptService [CL-M1]; delivered-path maps fresh auto-PO lines to
invoice lines explicitly (build the `lines[].source_line_id` map from the created PO's
line ids in order).

### Task 5.2: Parked pending SI — SERVICE-DEPTH changes [C-B3]

`CreateSupplierInvoiceRequest` relaxation as Rev 1, AND `CreateSupplierInvoiceService`
must handle the pending shape end-to-end:
- Accept empty `source_document_ids` → `source_document_id = null` (skip the
  `$sourceDocumentIds[0]` write, :109-114 region).
- Accept null `lines.*.source_line_id` (line data mapping :97-105 and line create
  :153-171 currently assume non-null; DB column is already nullable).
- Skip match-snapshot stamping for pending lines (mirror the bonus-line skip
  :154-156-adjacent) — snapshots stamped later by link-receipts [C-B3].
- Set `match_status = SupplierInvoiceMatchStatus::Unmatched` intentionally.
- **Posting guard BEFORE matcher assertion**: `SupplierInvoicePostingService` checks
  `payload->supplier_invoice->pending_receipt` → domain error `PENDING_RECEIPT_UNLINKED`
  (explicit, not the matcher's generic null-source exception) [C-B3].
- [ ] red: pending create 201 Draft w/ null sources persisted; post → `PENDING_RECEIPT_UNLINKED`;
  policy off → 422; NORMAL SI creation path untouched (matcher suites by path green).

### Task 5.3: link-receipts + SupplierInvoiceMatchSnapshotService [C-M]

- FIRST extract snapshot computation into
  `Procurement/Application/SupplierInvoiceMatchSnapshotService` (injectable), consumed by
  `CreateSupplierInvoiceService` (private `matchSnapshotAttributes` :214-248 →
  delegates), `RematchDraftSupplierInvoicesCommand` (private `snapshotForLine` :148-188 →
  delegates; its tests stay green), and the new link endpoint. Red test: pending lines
  have NULL snapshots before linking; correct `price_match_basis`/`matched_receipt_line_id`
  after [C-M].
- Endpoint behavior as Rev 1 (union write, Wave 8 same-supplier/currency/company
  re-assertion, clears pending, refreshes match_status).

### Task 5.4: Approval gate — signature change [C-M]

- `SupplierInvoicePostingService::post(Document $supplierInvoice, ?string $actorId = null)`
  — controller (`SupplierInvoiceController::post` :224-242) passes `$request->user()->id`;
  direct-call test sites updated (`SupplierInvoiceReceiptClearingTest`,
  `SupplierInvoiceGlTest`, others per `rg "PostingService::class\)->post|postingService->post" apps/api/tests`).
  No `auth()` fallback INSIDE the service beyond the default-param bridge.
- Gate: SI chain includes a PO with `payload->auto_generated.source === 'invoice_first'`
  (query via `whereJsonContainsKey` + payload read on loaded models — portable rule) AND
  policy approval flag → require `approve-invoice-first` permission on the actor.
- Tests as Rev 1 + actor-explicit (no ambient auth in service tests).

### Task 5.5: FE fork + linking UI — as Rev 1 (`SupplierInvoiceCreatePage.tsx` exists).

---

# WAVE 6 — Entry/exit notes + GRN print (deps: W3)

### Task 6.1: `GET /api/v1/entry-exit-notes` — as Rev 1.

### Task 6.2: GRN print — DEDICATED service [C-M]

- `DocumentPdfService::generate()` is typed `Document` (:43-48) — do NOT route
  GoodsReceipt through it. Create
  `Inventory/Application/Services/GoodsReceiptPdfService` (same PDF engine/library as
  DocumentPdfService — inspect its renderer and reuse the underlying
  view-to-PDF mechanism with a `goods_receipt.blade.php` view-data contract:
  {receipt, lines+products, supplier, po_number, external_reference/date, location,
  company header}). Endpoint `GET /api/v1/goods-receipts/{id}/pdf` (posted receipts
  only; existing receipt-view permission — mirror the receive/receipt-read routes).
- Blade template + FE print button + tests: as Rev 1 (document PDF tests as style
  reference only).

---

# WAVE 7 — revert() + cancel() (deps: W1 for the receipts check)

### Task 7.1: revert() — as Rev 1 guard matrix, plus:
- SI-reference check uses `whereJsonContains('payload->supplier_invoice->source_document_ids', $po->id)`
  (the existing chain-reader pattern, Document.php:363-377) + `source_document_id` equality —
  portable-API rule applies [CL-M4].
- Revert emits NO event mutations — past events are immutable (rule 8); revert is a
  status transition + reservation release only; document this in the service docblock [CL-m3].

### Task 7.2: cancel() consolidation — PIN ALL THREE SITES [CL-M3]

The three divergent cancel implementations (status survey + review): `DocumentPostingService::cancel()`,
`SalesOrderService::cancel()` (+ its `releaseBySource` reservation release), and the
refund-path cancel in `RefundService` (locate exact class via
`rg -n "Cancelled" apps/api/app/Modules/Document apps/api/app/Modules/Sales --type php`).
- [ ] red pins BEFORE refactor: (a) paid invoice cancel → 422 `DOCUMENT_HAS_PAYMENTS`
  (new guard); (b) unpaid posted invoice cancel → Cancelled + fiscal Voided (existing);
  (c) SalesOrder cancel releases reservations (existing — pin); (d) refund-path cancel
  behavior pinned as-is (write the assertion from its current behavior, cite lines).
- [ ] consolidate routing through `DocumentPostingService::cancel()` with per-type hooks;
  all four pins green → document lifecycle suites by path → TASK-LOG.

---

## Wave execution protocol — unchanged from Rev 1 (brief → dispatch → verify → review →
reviewer gates → commit per task → merge gate after procurement v1 promotion).

## Rev 2 self-review

- Every BLOCKER/MAJOR from both plan reviews mapped: C-B1→3.2, C-B2→3.3+1.2, C-B3→5.2,
  CL-BL1→3.1, CL-B2→1.2, C-M JSON→4.3+global, C-M snapshot→5.3, C-M post-actor→5.4,
  C-M confirm-actor→4.2, C-M drift-grain→1.2, C-M pdf→6.2, C-M matrix→4.3,
  C-M missing-column→2.2, C-M enum-truth→3.4, CL-M1→4.2/5.1/5.4, CL-M2→4.2,
  CL-M3→7.2, CL-M4→global+5.4+7.1. Minors: CL-m1→2.2, CL-m2→global, CL-m3→7.1,
  CL-m4→4.3, CL-m5→3.1, CL-m6→4.3, CL-m7→3.2, C-m→1.1/2.3/4.1.
- Type consistency re-checked: `poLineIdsWithReceipts` (1.1↔7.1), `createDraft`/`post`
  signatures (3.3↔3.4↔4.2), DTO `source` field (4.2↔5.1↔5.4), snapshot service (5.2↔5.3).
