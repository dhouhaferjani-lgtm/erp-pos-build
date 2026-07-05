# P2P Flexible Entry Points — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or
> superpowers:executing-plans. **This campaign executes via Codex CLI waves** (owner
> directive): the orchestrator writes `docs/sessions/CODEX-TASK-w<N>.md` per wave
> (wave tasks + spec §refs inlined + Global Constraints copied verbatim), dispatches
> `cd <worktree> && node ~/.claude/plugins/cache/openai-codex/codex/1.0.4/scripts/codex-companion.mjs task --write --background --fresh "Read docs/sessions/CODEX-TASK-w<N>.md and execute it fully."`
> (flags as separate argv BEFORE the prompt). Codex CANNOT git-commit in worktrees →
> it appends per-task evidence to `docs/sessions/TASK-LOG-w<N>.md`; the orchestrator
> reviews the diff and commits. Codex sandbox blocks PHPStan parallel workers → run
> `./vendor/bin/phpstan --debug`. Steps use `- [ ]` checkboxes.

**Goal:** Configurable P2P entry points — receipt-first (BL, auto-PO) and invoice-first
(delivered → implicit receipt / not-delivered → parked SI) — plus draft goods receipts,
PO integrity guard, entry/exit-note projection, and revert/cancel lifecycle guards, per
spec `docs/superpowers/specs/2026-07-05-p2p-entry-points-design.md`.

**Architecture:** Extends procurement v1 (receipt ledger, receipt-line matcher, SI
creation UI, presets). New orchestration services in `Procurement/Application` call
existing public services (`GoodsReceiptService`, `CreateSupplierInvoiceService`,
`PurchaseOrderService`) as sequential transactions with compensation — never one
umbrella transaction. Policy = 3 new columns on `procurement_policies`, fail-closed.

**Tech Stack:** Laravel 12/PHP 8.2+ (strict), PostgreSQL (SQLite in tests — pgsql-only
behaviors flagged per task), React 19/TS strict/TanStack Query 5, PHPUnit by path.

## Global Constraints (copy verbatim into every CODEX-TASK brief)

- Base branch `feat/p2p-entry-points` (worktree `apps/erp.p2p-flow`, base `b3e81a13e`).
  Verify with `git rev-parse HEAD` before working — expected history root b3e81a13e.
- **NEVER run the full PHPUnit suite** — tests BY PATH only (laptop constraint).
- **No git write commands** (worktree sandbox) — append evidence to the wave TASK-LOG.
- PHP: strict types, constructor injection `private readonly` ONLY (no `app()`), DTOs not
  `mixed`, enums for status columns. PHPStan L8 green on new code: `./vendor/bin/phpstan --debug`.
- **Precision (CLAUDE.md rule 19):** money/qty as strings end-to-end; FormRequest regex
  ceilings — money `/^-?\d+(\.\d{1,3})?$/`, qty `/^-?\d+(\.\d{1,4})?$/`;
  `CurrencyScale::bcformatStrict` at rest; constructor-inject `CurrencyScaleResolverInterface`;
  intermediates at scale+1.
- Module boundaries (rule 6): cross-module ONLY via Shared/Contracts, events, or a
  module's public Service class. Deptrac ratchet must not regress
  (`apps/api/tools/deptrac-ratchet.php`).
- Routes: `['api','auth:sanctum',SetPermissionsTeam::class]` (rule 12). Permissions via
  `RolesAndPermissionsSeeder`. FE: `t()` for ALL strings (ns `purchases`), design tokens,
  `tenantScopedKey([...])` for every tenant query key, `<MoneyInput>`/`<QuantityInput>`
  (no parseFloat on money).
- Types flow from backend: `php artisan typescript:transform` (CACHE_STORE=array) after
  DTO changes; never hand-edit `packages/shared/types/generated.d.ts`.
- Every task: red test FIRST, then minimal implementation, then green, then TASK-LOG
  entry (task #, files touched, red→green evidence, exact test command).
- Test DB uses SQLite: `FOR UPDATE`/partial-index semantics only fully verified on pgsql —
  where a task depends on them, write the test to be meaningful on SQLite AND note the
  pgsql caveat in the TASK-LOG.

## File map (created ▸ / modified ▹)

```
apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php          ▹ W1,W3
apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptLifecycleService.php ▸ W3
apps/api/app/Modules/Inventory/Domain/GoodsReceipt.php                               ▹ W3,W4
apps/api/app/Modules/Inventory/Presentation/Controllers/GoodsReceiptController.php   ▸ W3 (draft CRUD + post)
apps/api/app/Modules/Inventory/Presentation/routes.php                               ▹ W3
apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php   ▹ W1 (guard), W4 (list filter)
apps/api/app/Modules/Document/Application/DTOs/DocumentData.php                      ▹ W4 (is_auto_generated)
apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php             ▹ W7 (revert, cancel guard)
apps/api/app/Modules/Procurement/Application/StandaloneReceiptService.php            ▸ W4
apps/api/app/Modules/Procurement/Application/InvoiceFirstOrchestrator.php            ▸ W5
apps/api/app/Modules/Procurement/Application/ProcurementPolicyResolver.php           ▹ W2
apps/api/app/Modules/Procurement/Domain/ProcurementPolicy.php                        ▹ W2
apps/api/app/Modules/Procurement/Domain/Enums/ProcurementPreset.php                  ▹ W2
apps/api/app/Modules/Procurement/Application/ReceiptLineConsumptionPlanner.php       ▹ W3 (status filter)
apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php              ▹ W3 (status filter)
apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php       ▹ W3 (status filter), W5 (approval gate)
apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php ▹ W5
apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php ▹ W5 (link-receipts)
apps/api/app/Modules/Procurement/Presentation/routes.php                             ▹ W4,W5
apps/api/app/Console/Commands/GrirDriftReportCommand.php                             ▸ W1
apps/api/database/migrations/tenant/2026_07_06_*                                     ▸ W2,W3,W4
apps/api/resources/views/documents/templates/goods_receipt.blade.php                 ▸ W6
apps/web/src/features/purchases/...                                                  ▹ W2,W3,W4,W5,W6 (per-task below)
```

## Reviewer gates (factory Stage 4 — dispatched per wave after Codex + orchestrator review)

| Wave | Reviewers |
|---|---|
| W1 | inventory-costing-reviewer + treasury-reviewer |
| W2 | tenancy-authz-reviewer |
| W3 | inventory-costing-reviewer + treasury-reviewer |
| W4 | inventory-costing-reviewer + treasury-reviewer + tenancy-authz-reviewer |
| W5 | treasury-reviewer + inventory-costing-reviewer + tenancy-authz-reviewer |
| W6 | inventory-costing-reviewer (read-model only) |
| W7 | treasury-reviewer (cancel/unpaid) + tenancy-authz-reviewer (new endpoints) |

---

# WAVE 1 — PO integrity guard + GR-IR drift report (no deps)

### Task 1.1: Block PO line mutation once receipt lines exist

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php`
  (add public query method)
- Modify: `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php`
  (update path, ~`update()` where `lines` handling begins — locate the delete/replace block)
- Test: `apps/api/tests/Feature/Procurement/PurchaseOrderReceiptGuardTest.php` (create)

**Interfaces:**
- Produces: `GoodsReceiptService::poLineIdsWithReceipts(array $poLineIds): array` —
  returns the subset of the given PO-line UUIDs referenced by any `goods_receipt_lines`
  row. Used by W1 guard and W7 revert guard.

- [ ] **Step 1: failing test**
```php
// tests/Feature/Procurement/PurchaseOrderReceiptGuardTest.php
public function test_po_line_replacement_is_rejected_once_a_receipt_line_references_it(): void
{
    // Arrange: seeded confirmed PO (2 lines) + receiveGoods() partial receipt of line 1
    // (reuse the factory/seeder helpers used by tests/Feature/Procurement/GoodsReceiptLedgerTest.php)
    $response = $this->patchJson("/api/v1/purchase-orders/{$po->id}", [
        'lines' => [/* single replacement line — omits the received line */],
    ]);
    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'PO_LINES_LOCKED_BY_RECEIPTS');
    $this->assertDatabaseHas('document_lines', ['id' => $receivedLine->id]); // untouched
}
public function test_unreceipted_po_lines_remain_fully_editable(): void { /* same PATCH on PO with no receipts → 200 */ }
public function test_safe_header_fields_stay_editable_on_receipted_po(): void { /* PATCH notes only → 200 */ }
```
- [ ] **Step 2:** run `php artisan test tests/Feature/Procurement/PurchaseOrderReceiptGuardTest.php` → FAIL (guard missing)
- [ ] **Step 3: implementation** — in `GoodsReceiptService`:
```php
/** @param list<string> $poLineIds @return list<string> */
public function poLineIdsWithReceipts(array $poLineIds): array
{
    return GoodsReceiptLine::query()->whereIn('po_line_id', $poLineIds)
        ->distinct()->pluck('po_line_id')->all();
}
```
In `PurchaseOrderController::update` (constructor-inject `GoodsReceiptService` — Inventory
public service, allowed edge; deptrac ratchet check): before the existing delete/replace
of lines, when the request contains `lines`, collect current PO line ids; if
`poLineIdsWithReceipts($ids)` is non-empty AND the incoming payload removes or alters
(product_id/quantity/unit_price change) any of those ids → abort 422 with error code
`PO_LINES_LOCKED_BY_RECEIPTS` (use the module's existing domain-error response helper).
Header-only updates (no `lines` key) bypass the guard.
- [ ] **Step 4:** re-run test path → PASS; run existing PO suites BY PATH:
  `php artisan test tests/Feature/Documents/PurchaseOrderTest.php tests/Feature/Procurement` → green
- [ ] **Step 5:** TASK-LOG entry (orchestrator commits)

### Task 1.2: `procurement:grir-drift` report command

**Files:**
- Create: `apps/api/app/Console/Commands/GrirDriftReportCommand.php`
- Test: `apps/api/tests/Feature/Accounting/GrirDriftReportTest.php` (create)

**Interfaces:**
- Produces: artisan `procurement:grir-drift {--tenant=}` — prints table
  (receipt_number, received_at, po number, accrued total) of POSTED goods receipts with
  NO GR-IR journal entry; exit 0 when clean, exit 1 when drift found (cron-friendly).

- [ ] **Step 1: failing test**
```php
public function test_reports_posted_receipt_missing_grir_entry(): void
{
    // Arrange: receive a PO normally (listener posts GR-IR) → command exit 0.
    // Then delete the receipt's journal entry rows directly (simulating swallowed failure)
    $this->artisan('procurement:grir-drift')->assertExitCode(1)
         ->expectsOutputToContain($receipt->receipt_number);
}
public function test_clean_tenant_exits_zero(): void { /* received PO, entry intact → 0 */ }
```
- [ ] **Step 2:** run → FAIL (command not found)
- [ ] **Step 3: implementation** — FIRST read
  `GeneralLedgerService` GR-IR method (the one called by `PostGrIrOnGoodsReceipt`) and
  copy its EXACT `source_type` string + source id semantics (expected `goods_receipt` +
  receipt id; do not guess — cite the line in TASK-LOG). Command queries
  `goods_receipts where status='posted'` left-join `journal_entries` on the
  source-type-scoped pair, filters null, prints, sets exit code. Follow
  `RematchDraftSupplierInvoicesCommand` for the single-tenant-connection pattern.
- [ ] **Step 4:** test path green; `./vendor/bin/phpstan --debug` on new file
- [ ] **Step 5:** TASK-LOG entry

---

# WAVE 2 — Policy columns, fail-closed resolver, presets, settings UI (no deps)

### Task 2.1: Migration + model for entry-point policy columns

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_100000_add_entry_point_toggles_to_procurement_policies.php`
- Modify: `apps/api/app/Modules/Procurement/Domain/ProcurementPolicy.php` (fillable/casts + defaults)
- Test: `apps/api/tests/Feature/Procurement/ProcurementPolicyEntryPointsTest.php` (create)

**Interfaces:**
- Produces: `procurement_policies.allow_receipt_first` bool NOT NULL DEFAULT false;
  `allow_invoice_first` bool NOT NULL DEFAULT false;
  `invoice_first_requires_approval` bool NOT NULL DEFAULT true. Model casts `bool`.

- [ ] Steps: red test (existing row gets defaults false/false/true after migrate; casts
  boolean) → migrate → model change → green → TASK-LOG.

### Task 2.2: Fail-closed resolver

**Files:**
- Modify: `apps/api/app/Modules/Procurement/Application/ProcurementPolicyResolver.php`
- Test: extend `ProcurementPolicyEntryPointsTest.php`

**Interfaces:**
- Produces: resolver's synthesized missing-row default policy ALWAYS carries
  `allow_receipt_first=false, allow_invoice_first=false, invoice_first_requires_approval=true`
  regardless of vertical/preset. (Spec §3.2.)

- [ ] **failing test:**
```php
public function test_missing_policy_row_resolves_entry_points_closed(): void
{
    ProcurementPolicy::query()->delete();
    $policy = $this->resolver->resolve($tenantId, $companyId);
    $this->assertFalse($policy->allow_receipt_first);
    $this->assertFalse($policy->allow_invoice_first);
    $this->assertTrue($policy->invoice_first_requires_approval);
}
```
- [ ] red → amend the in-memory default construction (`ProcurementPolicyResolver` +
  `ProcurementPolicy` default-attributes source it uses) → green → existing policy tests
  by path (`tests/Feature/Procurement/ProcurementPolicy*`) stay green → TASK-LOG.

### Task 2.3: Preset mapping + preservation

**Files:**
- Modify: `apps/api/app/Modules/Procurement/Domain/Enums/ProcurementPreset.php`
- Test: extend the EXISTING Wave 9 preset preservation test file (locate:
  `rg -l "preset" apps/api/tests/Feature/Procurement`) — add cases.

**Interfaces:**
- Produces: preset bundle additionally maps `allow_receipt_first`/`allow_invoice_first`:
  Complet false/false · Standard true/false · Léger true/true.
  `invoice_first_requires_approval` NOT preset-mapped (§3.3).

- [ ] red test: applying Léger sets true/true; re-applying preset does NOT clobber a
  hand-tuned tolerance (extend existing preservation assertions) and does NOT touch
  `invoice_first_requires_approval` → implement in the preset enum's bundle method →
  green → TASK-LOG.

### Task 2.4: Permissions

**Files:**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- Test: extend `ProcurementPolicyEntryPointsTest.php` (seeder-driven permission existence)

**Interfaces:**
- Produces permissions: `goods-receipts.create-standalone`,
  `supplier-invoices.create-pending`, `supplier-invoices.link-receipts`,
  `supplier-invoices.approve-invoice-first` (exact strings; consumed W4/W5). Assigned to
  the same roles that hold `goods-receipt.edit-price` / SI-create respectively (mirror
  seeder groupings; cite chosen roles in TASK-LOG).

- [ ] red (permission missing) → seed → green → note deploy step: per-tenant seeder sync
  + `permission:cache-reset` (tenant-blind cache) in TASK-LOG.

### Task 2.5: Policy API payload + settings UI toggles

**Files:**
- Modify: the Wave 9 procurement-policies endpoints (locate controller via
  `rg -l "procurement-policies" apps/api/app/Modules/Procurement/Presentation`) — expose
  + accept the three fields (booleans, validated `boolean`).
- Modify: FE settings page (locate `rg -l "procurement" apps/web/src/features/settings
  apps/web/src/features/purchases --glob '*.tsx'` — the Wave 9 three-card preset picker):
  add two toggle rows + approval toggle, preset cards show implied values.
- Modify: `apps/web/src/locales/{fr,en,ar}/purchases.json` — keys
  `policy.allowReceiptFirst`, `policy.allowInvoiceFirst`, `policy.invoiceFirstApproval`
  (+ descriptions).
- Test: PHPUnit feature (PUT round-trip persists booleans; GET exposes) + Vitest for the
  settings component (toggle renders from query data, mutation payload includes fields —
  follow the existing Wave 9 settings test file pattern).

- [ ] red BE test → controller/FormRequest change → green → `php artisan
  typescript:transform` (if DTO-backed) → red FE test → UI → green →
  `pnpm typecheck && pnpm lint` scoped → TASK-LOG.

---

# WAVE 3 — Draft goods receipts, GRN-at-post, matcher isolation (no deps)

### Task 3.1: Migration — nullable receipt_number + partial unique

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_110000_make_receipt_number_nullable_on_goods_receipts.php`
- Test: `apps/api/tests/Feature/Inventory/GoodsReceiptDraftTest.php` (create; first case)

**Interfaces:**
- Produces: `goods_receipts.receipt_number` nullable; unique index →
  `UNIQUE (company_id, receipt_number) WHERE receipt_number IS NOT NULL` (pgsql partial;
  SQLite ignores WHERE — note caveat). Two drafts with NULL numbers must coexist.

- [ ] red (two NULL-numbered rows violate current unique) → migration (drop unique, add
  partial via raw statement for pgsql + plain index fallback documented) → green → TASK-LOG.

### Task 3.2: Matcher/planner/posting Posted-only filters (BLOCKER pin)

**Files:**
- Modify: `apps/api/app/Modules/Procurement/Application/ReceiptLineConsumptionPlanner.php`
  (base query), `SupplierInvoiceMatcher.php` (receipt-line aggregates — both paid and
  free windows), `SupplierInvoicePostingService.php` (FIFO consumption query),
  `GoodsReceiptService.php` (PO-counter derivation queries if they read receipt lines).
- Test: `apps/api/tests/Feature/Procurement/DraftReceiptMatcherIsolationTest.php` (create)

**Interfaces:**
- Consumes: Task 3.3's `createDraft` (write the test with a direct model-created Draft
  header+lines so 3.2 can land first — construct `GoodsReceipt::create(['status'=>'draft',
  'receipt_number'=>null,...])` + lines with NULL movement ids).
- Produces: every matchable-window / FIFO-consumption / counter query joins
  `goods_receipts.status = GoodsReceiptStatus::Posted`.

- [ ] **failing test:**
```php
public function test_draft_receipt_lines_create_no_matchable_window(): void
{
    // PO confirmed, NO posted receipts; insert Draft receipt + line received_qty=5
    $this->assertSame('0.0000', $this->matcher->matchableQty($poLine));
    // and SI posting against the PO consumes nothing from the draft: attempt → quantity_variance/exception path
}
public function test_posted_receipt_still_matches(): void { /* regression twin */ }
```
- [ ] red → add status joins (planner `:30-34` region, matcher `:151/:414` aggregates,
  posting lockForUpdate query, counter derivations) → green → run Wave 5 matcher suites
  BY PATH (`tests/Feature/Procurement/SupplierInvoiceMatcherReceiptBasisTest.php`,
  `SupplierInvoiceReceiptClearingTest.php`, `SupplierInvoiceSnapshotTest.php`,
  `RematchDraftsCommandTest.php`) → all green → TASK-LOG.

### Task 3.3: GoodsReceiptLifecycleService (createDraft / post) + receiveGoods refactor

**Files:**
- Create: `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptLifecycleService.php`
- Modify: `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php`
- Test: extend `GoodsReceiptDraftTest.php`

**Interfaces:**
- Produces:
  `createDraft(Document $purchaseOrder, array $quantities, array $freeQuantities, array $batchData, array $receivedUnitPrices, ?string $notes, string $actorId): GoodsReceipt`
  (status Draft, receipt_number NULL, lines with movement_id NULL, NO stock/WAC/GL/
  counters/events; price-override permission NOT checked at draft — checked at post);
  `post(GoodsReceipt $receipt, string $actorId, bool $failClosedGrir = false): GoodsReceipt`
  (transaction + ProductCostLock + GRN via `generateForKey('goods_receipt','GRN')` +
  stock movements + WAC recordPurchase + line movement_id backfill + PO counters/status +
  GoodsReceived event; when `$failClosedGrir` — call the GL GR-IR method directly and
  RETHROW on failure, spec §2.2);
  `receiveGoods(...)` keeps its EXACT current signature/behavior, internally
  `createDraft(...)` + `post(...)` in one transaction.
- Consumes: existing `WeightedAverageCostService::recordPurchase`, `ProductCostLock`,
  `DocumentNumberingService::generateForKey`.

- [ ] red tests: draft creates zero stock movements/journal entries/counter changes and
  NULL number; post() assigns GRN, moves stock, accrues 408, updates counters;
  receiveGoods end-state identical to pre-refactor (pin: reuse assertions from the
  existing Wave 3 ledger test) → implement → green → **regression paths:**
  `php artisan test tests/Feature/Inventory tests/Feature/Procurement tests/Feature/Accounting/SupplierInvoiceGlTest.php` → TASK-LOG.

### Task 3.4: Draft CRUD + post endpoints & FE

**Files:**
- Create: `apps/api/app/Modules/Inventory/Presentation/Controllers/GoodsReceiptController.php`
  (store-draft [PO-backed], update-draft, destroy-draft, post) + FormRequests
  (`StoreGoodsReceiptDraftRequest` with rule-19 regexes on prices/qtys)
- Modify: `apps/api/app/Modules/Inventory/Presentation/routes.php`
- Modify FE: goods-receipt workbench (`apps/web/src/features/purchases/GoodsReceiptListPage.tsx`
  area + receive dialog — locate the Wave 6 receive-dialog component): add "Enregistrer
  brouillon" secondary action + Draft badge/filter + "Valider" on draft rows.
- Test: `apps/api/tests/Feature/Inventory/GoodsReceiptDraftEndpointsTest.php` (create;
  perms: draft CRUD under existing `documents.update`-equivalent used by receive route —
  mirror the receive endpoint's permission; post uses the same) + Vitest for dialog
  (draft button fires POST /goods-receipts with post_immediately=false).

- [ ] red API tests (draft create 201 returns BROUILLON display id; edit; delete; post
  → 200 + GRN assigned; draft of OTHER company 404) → implement → green → FE red/green →
  `pnpm typecheck && pnpm lint` → TASK-LOG.

---

# WAVE 4 — Receipt-first: BL columns, StandaloneReceiptService, auto-PO semantics, UI (deps: W2, W3)

### Task 4.1: BL identity columns

**Files:** migration `2026_07_06_120000_add_external_reference_to_goods_receipts.php`
(nullable `external_reference` varchar(100), `external_date` date), `GoodsReceipt.php`
fillable/casts, surface in Wave 7 receipt-line picker payload (locate the endpoint
feeding `GoodsReceiptListPage` receipt-line selection + its serializer) and SI match
preview. Test: extend `GoodsReceiptDraftTest` (columns persist; picker payload includes
`external_reference`).
- [ ] red → migrate/model/serializer → green → TASK-LOG.

### Task 4.2: StandaloneReceiptService (auto-PO saga)

**Files:**
- Create: `apps/api/app/Modules/Procurement/Application/StandaloneReceiptService.php`
- Create: `apps/api/app/Modules/Procurement/Application/DTOs/StandaloneReceiptData.php`
  (strict DTO: supplierId, companyId, locationId, lines[{productId, variantId?, quantity:string,
  freeQuantity:string, unitPrice:string, batch?}], externalReference?, externalDate?,
  actorId, postImmediately:bool, idempotencyKey:string)
- Test: `apps/api/tests/Feature/Procurement/StandaloneReceiptServiceTest.php`

**Interfaces:**
- Produces: `execute(StandaloneReceiptData $data): StandaloneReceiptResult`
  ({purchaseOrder: Document, receipt: GoodsReceipt}). Behavior: (t1) create PO Draft with
  lines at BL prices + `payload->auto_generated = ['source'=>'standalone_receipt',
  'actor_id'=>$actorId, 'created_at'=>now()->toIso8601String()]`, confirm via
  `PurchaseOrderService::confirm` passing EXPLICIT actor (verify confirm() accepts/derives
  actor — if it reads `auth()->id()`, add an optional `?string $actorId` param defaulting
  to auth, cite in TASK-LOG); (t2) `GoodsReceiptLifecycleService` createDraft(+post when
  postImmediately) with `received_unit_prices` = BL prices and **failClosedGrir=true**;
  compensation: t2 throwable → cancel the auto-PO (status Cancelled) then rethrow;
  idempotency: `payload->auto_generated.idempotency_key` unique pre-check → returns
  existing result instead of duplicating.
- Policy: throws `DomainException('RECEIPT_FIRST_DISABLED')` unless resolver's
  `allow_receipt_first` (fail-closed per W2).

- [ ] red tests: happy path (PO auto-flag + confirmed + receipt posted at BL prices +
  408 journal exists — assert journal legs); policy off → exception; forced t2 failure
  (invalid product) → auto-PO cancelled; duplicate idempotency key → single PO/receipt;
  precision (price '12.345' stored exact) → implement → green → PHPStan → TASK-LOG.

### Task 4.3: Auto-PO exposure semantics

**Files:**
- Modify: `DocumentData` DTO (+`is_auto_generated: bool` derived from payload),
  `PurchaseOrderController::index` (default `->where` excluding
  `payload->auto_generated` unless `include_auto_generated=1`), FE PO list toggle +
  "Auto" badge (DocumentListPage PO variant), typescript:transform.
- Test: `apps/api/tests/Feature/Documents/AutoGeneratedPoVisibilityTest.php` — list
  excludes by default / includes with param / detail always accessible /
  `is_auto_generated` true in payload; AgedPayables INCLUDES auto-PO (locate the aged
  payables test file and add the case). Vitest: toggle + badge.
- [ ] red → implement (JSONB containment query `payload->auto_generated` not null —
  pgsql `whereNotNull("payload->auto_generated")`; verify SQLite JSON path support in
  test, else `->where('payload','like','%auto_generated%')` is FORBIDDEN — use
  `whereJsonContainsKey` equivalent both DBs support, cite approach) → green → TASK-LOG.

### Task 4.4: "Nouvelle réception" endpoint + page

**Files:**
- Create: `POST /api/v1/goods-receipts/standalone` route (perm
  `goods-receipts.create-standalone`) + `StandaloneReceiptController` + FormRequest
  (rule-19 regexes; `pending` vs `post_immediately`).
- Create FE: `apps/web/src/features/purchases/receipts/StandaloneReceiptPage.tsx`
  (supplier picker, product line editor w/ `<QuantityInput>`/`<MoneyInput>`, BL ref/date
  fields, Draft/Post buttons) + route + sidebar entry gated on policy
  (`allow_receipt_first` from the policy query) + i18n keys.
- Test: endpoint feature test (403 without perm; 422 policy off; 201 happy; response
  carries receipt + auto-PO ids) + Vitest page test (submit payload shape, strings not
  numbers).
- [ ] red → implement → green → `pnpm typecheck && pnpm lint && pnpm test -- receipts` → TASK-LOG.

---

# WAVE 5 — Invoice-first: fork, parked SI, linking, approval (deps: W2, W3, W4)

### Task 5.1: InvoiceFirstOrchestrator (delivered path)

**Files:** create `InvoiceFirstOrchestrator.php` + DTO (supplier, company, lines with
billed prices/qtys, externalDocumentNumber?, deliveryStatus enum `delivered|pending`,
actorId, idempotencyKey); test `InvoiceFirstOrchestratorTest.php`.
**Interfaces:** `execute(...)` delivered → reuses `StandaloneReceiptService::execute`
(postImmediately=true, prices = BILLED) then `CreateSupplierInvoiceService::create` with
`source_document_ids=[autoPO]` + per-line `source_line_id` mapped from the fresh PO
lines; asserts zero PPV plug (accrual==billed); policy `allow_invoice_first` fail-closed.
- [ ] red (happy: SI Draft created + matched clean + 408 clears exactly on post — assert
  GL; policy off; saga failure → PO cancelled + no SI) → implement → green → TASK-LOG.

### Task 5.2: Parked pending SI (FormRequest relaxation)

**Files:** modify `CreateSupplierInvoiceRequest` (when `pending_receipt=true` AND policy
`allow_invoice_first`: drop `source_document_ids` min:1 + per-line `source_line_id`
requirement; document stays Draft; persist `payload->supplier_invoice->pending_receipt`),
`CreateSupplierInvoiceService` (skip match-snapshot stamping for pending lines — mirror
the bonus-line skip), posting guard (pending SI post attempt → domain error
`PENDING_RECEIPT_UNLINKED`); test `PendingSupplierInvoiceTest.php`.
- [ ] red (create pending w/o sources 201 Draft; post → 422; policy off → 422; matcher
  untouched for normal SIs — run Wave 5 matcher paths) → implement → green → TASK-LOG.

### Task 5.3: link-receipts endpoint

**Files:** `POST /api/v1/supplier-invoices/{id}/link-receipts` (perm
`supplier-invoices.link-receipts`) — payload `{links: [{invoice_line_id, po_line_id,
source_document_id}]}` derived from the Wave 7 receipt-line selector; writes
`source_document_ids` (union, same-supplier/currency/company re-asserted server-side —
reuse Wave 8 validation), per-line `source_line_id`, clears `pending_receipt`, re-stamps
match snapshot (reuse `matchSnapshotAttributes`), refreshes `match_status`; test
`LinkReceiptsEndpointTest.php`.
- [ ] red (link → pending cleared + snapshot stamped + postable; cross-supplier receipt
  → 422; already-linked line → 422) → implement → green → TASK-LOG.

### Task 5.4: Invoice-first approval gate

**Files:** modify `SupplierInvoicePostingService` — when the SI's chain includes an
`invoice_first` auto-PO AND policy `invoice_first_requires_approval`: require actor holds
`supplier-invoices.approve-invoice-first` else domain error `INVOICE_FIRST_APPROVAL_REQUIRED`;
test extends `InvoiceFirstOrchestratorTest`.
- [ ] red (post without perm → 422/403; with perm → posts; toggle off → posts) →
  implement (permission check via injected authenticated user/actor param — no `app()`) →
  green → TASK-LOG.

### Task 5.5: FE fork + pending badge + linking UI

**Files:** SI creation page (Wave 7 — locate `SupplierInvoiceCreatePage` /
create-invoice feature dir): standalone path asks delivery-status radio (delivered →
calls invoice-first endpoint; pending → pending_receipt payload); SI list/detail:
"En attente de réception" badge + "Associer des réceptions" action opening the Wave 7
receipt-line selector filtered to supplier, submitting link-receipts. i18n fr/en/ar.
Vitest: fork renders, payload shapes, badge, linking mutation.
- [ ] red → implement → green → typecheck/lint → TASK-LOG.

New route additions in this wave: `POST /api/v1/supplier-invoices/invoice-first`
(orchestrator endpoint, perm `supplier-invoices.create-pending` for pending vs existing
SI-create perm for delivered — final mapping per seeder groupings, cite in TASK-LOG).

---

# WAVE 6 — Entry/exit notes projection + GRN print (deps: W3)

### Task 6.1: `GET /api/v1/entry-exit-notes`

**Files:** create `EntryExitNoteController` + read-model query service
(`Inventory/Application/Services/EntryExitNoteQueryService.php`) grouping
`stock_movements` by `(reference_type, reference_id)` with direction from
`movement_type`, filters direction/location/date, paginated DTO {direction, source_type,
source_label (GRN/document/transfer number), location, line_count, total_qty, actor,
occurred_at}; permission: reuse the inventory view permission guarding stock-movement
listings (locate + mirror; cite). Test: feature test seeding one receipt + one delivery
+ one transfer → 2 entries in + 2 out (transfer = one in + one out), filters work.
- [ ] red → implement → green → TASK-LOG.

### Task 6.2: GRN print template + FE list page

**Files:** create `apps/api/resources/views/documents/templates/goods_receipt.blade.php`
(follow `delivery_note.blade.php` structure: header GRN + supplier + BL ref/date, line
table qty/price columns, location, signature blocks) + PDF route/controller hook (mirror
how delivery-note PDF resolves; goods receipts are NOT documents — add a dedicated
`GET /api/v1/goods-receipts/{id}/pdf` using the same PDF service); FE: entry/exit list
page (`apps/web/src/features/inventory/EntryExitNotesPage.tsx`) + print button on
posted receipts. Tests: PDF endpoint 200 + contains GRN (follow existing
DocumentPdfService test), Vitest list page.
- [ ] red → implement → green → TASK-LOG.

---

# WAVE 7 — revert() + cancel() unpaid-guard (no deps; merge after W1)

### Task 7.1: `DocumentPostingService::revert()`

**Files:** modify `DocumentPostingService`; controller endpoint
`POST /api/v1/documents/{id}/revert` (perm `documents.update`); test
`DocumentRevertTest.php`.
**Interfaces:** `revert(Document $document, string $actorId): Document` —
Confirmed→Draft. Guard matrix (each a test case):
- Quote: allowed.
- SalesOrder: allowed; releases reservations created by auto-reserve (locate
  `SalesOrderService` reservation creation + its release counterpart; assert reservation
  rows gone).
- PurchaseOrder: allowed ONLY if `GoodsReceiptService::poLineIdsWithReceipts(lineIds)`
  empty AND `source_document_id` doesn't point at a purchase_rfq AND no SI references it
  (`source_document_id` or payload `source_document_ids` containment) → else domain
  errors `PO_HAS_RECEIPTS` / `PO_FROM_RFQ_AWARD` / `PO_HAS_INVOICES`.
- DeliveryNote/ReturnNote/Invoice/CreditNote/SupplierInvoice/SupplierCreditNote/Income/
  PurchaseRfq: rejected `REVERT_NOT_SUPPORTED`.
- [ ] red matrix → implement → green → TASK-LOG.

### Task 7.2: cancel() unpaid-guard + consolidation

**Files:** modify `DocumentPostingService::cancel()` — reject when allocated payments
exist (locate the payment-allocation relation used by PaymentStatus computation; error
`DOCUMENT_HAS_PAYMENTS`); route the scattered cancel implementations (grep
`rg -n "cancel" apps/api/app/Modules/Document --type php` — the 3 sites found in the
status survey) through the service. Test: paid invoice cancel → 422; unpaid posted
invoice cancel → Cancelled+Voided (existing behavior pinned).
- [ ] red → implement → green → run document lifecycle suites by path → TASK-LOG.

---

## Wave execution protocol (orchestrator checklist per wave)

1. Generate `docs/sessions/CODEX-TASK-w<N>.md`: wave tasks verbatim + referenced spec
   sections inlined + Global Constraints block + "no git; TASK-LOG protocol" preamble.
2. Dispatch Codex (pattern at top). Monitor via background until-loop on
   `codex-companion.mjs status <task-id>` leaving `| running |`.
3. On completion: read TASK-LOG + `git -C <worktree> status/diff`; orchestrator runs the
   wave's test paths + `./vendor/bin/phpstan --debug` + scoped preflight independently.
4. General-agent code review (findings file `docs/superpowers/reviews/2026-07-XX-p2p-w<N>-codereview.md`) →
   fix round via Codex if needed → specialized reviewer gate(s) per table → commit per
   task from TASK-LOG with explicit paths.
5. Merge gate: factory Stage 5/6 — scoped preflight, then orchestrator merges wave batch
   to the campaign branch; campaign lands on dev only after procurement v1 promotion
   (owner call) as fast-forward batches.

## Plan self-review notes

- Spec coverage: §3→W2, §4→W1, §5→W3, §6→W4, §7→W5, §8→W6, §9→W7, §10→W1.2+W4.2(fail-closed)+
  existing GL untouched, §11 endpoints distributed to their waves, §12 embedded per task.
- Known intentionally-deferred (Phase 2, spec §13): corrective receipts, FK hardening,
  stored-status normalization, mobile surfaces, ordered-mode GL, auto-suggest linking.
- W4.3 JSON-path querying on SQLite is the riskiest test-portability point — task carries
  an explicit approach-verification instruction.
- W5 route/permission final mapping intentionally resolved at implementation against
  seeder groupings (cited in TASK-LOG) — reviewer gate verifies.
