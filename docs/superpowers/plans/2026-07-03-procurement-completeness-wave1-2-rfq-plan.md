# Procurement Completeness — Waves 1–2 (RFQ Groups) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship multi-supplier RFQ ("demande de prix") groups — fan-out create, response recording, comparison, whole-RFQ award-to-PO with sibling auto-close and reopen — per spec Rev 3.1 Gap 1.

**Architecture:** New `DocumentType::PurchaseQuoteRequest = 'purchase_rfq'` on the unified `documents` table; a group = N sibling documents sharing `payload->rfq.group_id`; award/convert runs through the existing `DocumentConverterRegistry` wrapped in an ordered-FOR-UPDATE transaction owned by the RFQ endpoint. No new tables — one pgsql-gated expression index migration.

**Tech Stack:** Laravel 12 / PHP 8.2 strict (PHPUnit, PHPStan L8), React 19 + TanStack Query 5 (Vitest), react-i18next FR/EN/AR.

**Spec:** `docs/superpowers/specs/2026-07-03-procurement-completeness-design.md` — Gap 1 (§1.1–§1.12), Rev 3 decisions table (S7), Rev 3.1 review fixes (R3D-1/2/6/7/8/10). Read Gap 1 + §0 in full before Task 1.

## Program roadmap (context — NOT this plan's scope)

| Waves | Content | Plan doc |
|---|---|---|
| **1–2 (THIS PLAN)** | RFQ groups BE + FE | this file |
| 3–6 | Gap 2 receipt ledger (goods_receipts/lines, price edit, PPV, matcher rebase) — critical path | written at dispatch time |
| 7–8 | Gap 3 SI creation UI, then multi-PO relaxation | written at dispatch time |
| 9 | Presets (Complet/Standard/Léger) + two_way wiring | written at dispatch time |

Waves 1–2 are independent of Gap 2/3 — safe to build first while the receipt-ledger plan is authored.

## Global Constraints

- Branch: `feat/procurement-completeness`, worktree `/Users/houssamr/Projects/syneriva/apps/erp.procurement-v2`. Merge target: `post-demo`. NEVER push `dev`/`origin/dev`.
- TDD non-negotiable: failing test FIRST, then minimal code. Run PHPUnit **by path only** (`vendor/bin/phpunit tests/Feature/Procurement/...`) — NEVER the full suite.
- Strict typing: no `mixed`, DTO for every jsonb payload; enums for status/type (Rule 9); constructor injection with `private readonly` only (Rule 13).
- Routes middleware: `['api', 'auth:sanctum', SetPermissionsTeam::class]` (Rule 12).
- Money/qty precision: strings end-to-end; FormRequest regex ceilings `unit_price` `/^-?\d+(\.\d{1,3})?$/`, `quantity` `/^-?\d+(\.\d{1,4})?$/`; FE `<MoneyInput>`/`<QuantityInput>`, no `parseFloat` on money (Rule 19).
- FE: all strings via `t()` (Rule 11); design tokens (Rule 18); tenant queries via `tenantScopedKey([...])`; `apiGet`/`apiPost` already unwrap — no double-unwrap (Rule 14).
- After PHP DTO changes: `php artisan typescript:transform` (with `CACHE_STORE=array` if cache errors) — never hand-edit `packages/shared/types/`.
- Stored enum value **`purchase_rfq`** (12 chars — MUST fit `documents.type string(20)`; add explicit length assertion test).
- RFQ is NonFiscal, never payable/receivable, never touches stock/GL — invariants §1.12, each pinned by a test.

---

## File structure (Waves 1–2)

```
apps/api/app/Modules/Document/Domain/Enums/DocumentType.php            (modify: add case + helper arms)
apps/api/app/Modules/Procurement/Domain/Dto/RfqPayload.php             (create: typed payload DTO)
apps/api/app/Modules/Procurement/Application/PurchaseQuoteRequestService.php (create: fan-out/respond/send/reopen)
apps/api/app/Modules/Procurement/Application/PurchaseQuoteRequestAwardService.php (create: award txn — locking lives HERE)
apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/PurchaseQuoteRequestToPurchaseOrderConverter.php (create)
apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php    (modify: register converter)
apps/api/app/Modules/Procurement/Presentation/Requests/CreatePurchaseQuoteRequestRequest.php (create)
apps/api/app/Modules/Procurement/Presentation/Requests/UpdatePurchaseQuoteRequestRequest.php (create)
apps/api/app/Modules/Procurement/Presentation/Controllers/PurchaseQuoteRequestController.php (create)
apps/api/app/Modules/Procurement/Presentation/routes.php               (modify: add route group)
apps/api/database/migrations/tenant/2026_07_03_200000_add_rfq_group_index.php (create: pgsql-gated)
apps/api/database/seeders/RolesAndPermissionsSeeder.php                (modify: purchase-quote-requests.{view,create,update,convert,delete})
apps/api/resources/views/documents/templates/purchase_rfq.blade.php    (create: print template)
apps/web/src/features/purchases/quote-requests/{api.ts,types.ts,QuoteRequestListPage.tsx,QuoteRequestCreatePage.tsx,QuoteRequestDetailPage.tsx,QuoteRequestComparisonPage.tsx} (create)
apps/web/src/routes/index.tsx                                          (modify: routes under /purchases gating)
apps/web/src/i18n.ts + locales (fr|en|ar)/purchases.json               (modify: rfq.* keys)
```

---

## WAVE 1 — Backend

### Task 1: `DocumentType::PurchaseQuoteRequest` enum case

**Files:** Modify `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php`; Test `apps/api/tests/Unit/Document/DocumentTypePurchaseRfqTest.php` (create).
**Produces:** `DocumentType::PurchaseQuoteRequest` (value `purchase_rfq`), `getPrefix() = 'DP'`, `label()`, `affectsReceivable() = false`, `canTransitionToPaid() = false`; `FiscalCategory::fromDocumentType(...) === NonFiscal` (via existing `default` arm — pin, don't add an arm).

- [ ] Write failing unit test: case exists; `strlen('purchase_rfq') <= 20`; prefix `DP`; `affectsReceivable()`/`canTransitionToPaid()` false; `FiscalCategory::fromDocumentType(DocumentType::PurchaseQuoteRequest) === FiscalCategory::NonFiscal`.
- [ ] Run: `cd apps/api && vendor/bin/phpunit tests/Unit/Document/DocumentTypePurchaseRfqTest.php` → FAIL (case undefined).
- [ ] Add the case + arms. Then `rg "match \(\$" apps/api --type php -l | xargs rg -l "DocumentType"` and fix EVERY exhaustive match over DocumentType (PHPStan L8 will also catch: run `vendor/bin/phpstan analyse app/Modules/Document`).
- [ ] Re-run test → PASS. PHPStan clean on touched modules.
- [ ] Commit: `feat(procurement): PurchaseQuoteRequest document type (purchase_rfq)`

### Task 2: `RfqPayload` DTO + group index migration

**Files:** Create DTO + migration above; Test `tests/Unit/Procurement/RfqPayloadTest.php`.
**Produces:** `RfqPayload::fromArray(array $payload): self` / `toArray()`, readonly props: `string $groupId`, `?string $validityDate`, `?string $supplierReference`, `?int $leadTimeDays`, `?string $responseRecordedAt`, `?string $sentAt`, `?string $closedReason`. Migration: `CREATE INDEX documents_rfq_group_idx ON documents (tenant_id, ((payload->'rfq'->>'group_id'))) WHERE type = 'purchase_rfq'` **inside `if (DB::connection()->getDriverName() === 'pgsql')`** (R3D-8). Also pre-seed the `purchase_rfq` row in `document_sequences` per company in the same migration's `up()` for existing companies (R3D-7 first-sequence race).

- [ ] Failing DTO round-trip test → implement → PASS. Migration runs green on tenant test DB.
- [ ] Run `php artisan typescript:transform` (CACHE_STORE=array), verify `packages/shared/types/` gains the DTO.
- [ ] Commit: `feat(procurement): RFQ payload DTO + group expression index`

### Task 3: Fan-out create + update/respond/send (`PurchaseQuoteRequestService`)

**Files:** Create service + both FormRequests; Test `tests/Feature/Procurement/PurchaseQuoteRequestServiceTest.php`.
**Interfaces — Produces:**
```php
/** @return Collection<int, Document> the created siblings (one per partner) */
public function createGroup(CreateRfqData $data, string $tenantId, string $companyId): Collection;
public function recordResponse(string $rfqId, UpdateRfqData $data): Document; // sets payload prices + response_recorded_at, status stays Confirmed
public function markSent(string $rfqId): Document;                            // Draft→Confirmed + payload.sent_at
public function reopenGroup(string $groupId): int;                            // R3D-2: only when no live converted PO; restores lost siblings to Confirmed, clears closed_reason; returns count
```
`CreateRfqData`: `partner_ids: string[]` (min 1, each a valid partner uuid — validate `Str::isUuid` before querying, UUID-col 500 pitfall), `lines[]{product_id, variant_id?, quantity(string), unit_price?(string)}`, `validity_date?`, `notes?`. One DB transaction creates all siblings (each its own number via `DocumentNumberingService`); one `group_id = Str::uuid7()` stamped on all; `partner_id` required per document (§1.12).
**FormRequest rules:** `partner_ids` `required|array|min:1|max:10`, `partner_ids.*` `uuid`; `lines.*.quantity` `['required','numeric','regex:/^-?\d+(\.\d{1,4})?$/']`; `lines.*.unit_price` `['nullable','numeric','regex:/^-?\d+(\.\d{1,3})?$/']`. Update request: same line rules + `validity_date` `nullable|date` + `supplier_reference` `nullable|string|max:100` + `lead_time_days` `nullable|integer|min:0`; MUST reject any `group_id` change (R3D-6 immutability).

- [ ] Failing feature tests (RefreshDatabase + RolesAndPermissionsSeeder, real models): fan-out `partner_ids=[A,B,C]` → 3 documents, 3 distinct numbers, 1 shared group_id, all Draft; N=1 behaves identically; response recording persists prices + flips presentation to Responded; group_id immutable via update.
- [ ] Implement → PASS → Commit: `feat(procurement): RFQ fan-out create + response recording`

### Task 4: Award transaction + converter + reopen guard (`PurchaseQuoteRequestAwardService`)

**Files:** Create award service + converter; modify `DocumentServiceProvider` (register converter); Test `tests/Feature/Procurement/PurchaseQuoteRequestAwardTest.php`.
**Interfaces — Produces:** `award(string $rfqId): Document` (returns the created PO). Core shape (R3D-1 — the endpoint OWNS the locking, the converter does not):
```php
return DB::transaction(function () use ($rfqId) {
    $winner = Document::query()->whereKey($rfqId)->lockForUpdate()->firstOrFail();
    $groupId = RfqPayload::fromArray($winner->payload)->groupId;
    $siblings = Document::query()
        ->where('type', DocumentType::PurchaseQuoteRequest)
        ->where('company_id', $winner->company_id)
        ->whereRaw("payload->'rfq'->>'group_id' = ?", [$groupId])
        ->orderBy('id')            // deterministic lock order — R3D-1/R3D-11 pattern
        ->lockForUpdate()->get();
    $this->assertNoLiveAwardedPo($siblings);      // re-check UNDER lock → 422 RFQ_GROUP_ALREADY_AWARDED
    $this->assertResponded($winner);              // converter precondition §1.12
    $po = $this->converterRegistry->convert($winner, DocumentType::PurchaseOrder); // copies lines + responded prices → PO unit_price, sets source_document_id, PO=Draft, fires DocumentConverted
    foreach ($siblings as $sibling) {
        if ($sibling->id === $winner->id) { continue; }
        $sibling->status = DocumentStatus::Cancelled;
        $sibling->payload = ['rfq' => [...($sibling->payload['rfq'] ?? []), 'closed_reason' => 'lost']] + ($sibling->payload ?? []);
        $sibling->save();
    }
    return $po;
});
```
`assertNoLiveAwardedPo`: a sibling is "awarded" iff a non-cancelled `PurchaseOrder` exists with `source_document_id` in the sibling ids. Converter: `sourceType()=PurchaseQuoteRequest`, `targetType()=PurchaseOrder`, reuse `CopiesDocumentData`, rejects non-Responded source.

- [ ] Failing tests: award B → PO Draft with copied lines/prices + `source_document_id=B`; A and C Cancelled + `closed_reason='lost'`; second award → 422 `RFQ_GROUP_ALREADY_AWARDED`; **concurrency test** (two awards, pcntl or sequential-with-stale-read simulation per the credit-note D1 test precedent) → exactly one PO; reopen: rejected while PO live → after PO cancelled, reopen restores lost siblings to Confirmed (Responded presentation) → award A now succeeds; converter rejects wrong source type + non-Responded.
- [ ] Implement → PASS → Commit: `feat(procurement): RFQ award txn (ordered locks) + converter + group reopen`

### Task 5: §1.12 invariant tests (NonFiscal / zero-GL / zero-stock)

**Files:** Test `tests/Feature/Procurement/PurchaseQuoteRequestInvariantsTest.php` only.
- [ ] Tests: creating + responding + converting a group writes ZERO `journal_entries` and ZERO `stock_movements`; RFQ absent from receivable/payable document list filters; `Posted/Paid/Received` unreachable (no endpoint sets them). These should PASS immediately if Tasks 1–4 are correct — a failure here is a real leak, fix in-place.
- [ ] Commit: `test(procurement): RFQ fiscal/stock/GL invariants`

### Task 6: HTTP layer — controller, routes, permissions, blade

**Files:** Create controller; modify `Procurement/Presentation/routes.php` (+ seeder + blade).
**Produces (all rule-12 middleware, per-route `can:`):**
```
GET    /purchase-quote-requests                  can:purchase-quote-requests.view
GET    /purchase-quote-requests/{id}             can:purchase-quote-requests.view
GET    /purchase-quote-requests/groups/{groupId} can:purchase-quote-requests.view    (siblings + per-line responses, correlated by (product_id, variant_id) — R3D-6)
POST   /purchase-quote-requests                  can:purchase-quote-requests.create  (fan-out)
PUT    /purchase-quote-requests/{id}             can:purchase-quote-requests.update
POST   /purchase-quote-requests/{id}/send        can:purchase-quote-requests.update
POST   /purchase-quote-requests/{id}/convert-to-po  can:purchase-quote-requests.convert
POST   /purchase-quote-requests/groups/{groupId}/reopen  can:purchase-quote-requests.convert
```
Permissions seeded in `RolesAndPermissionsSeeder` (owner/manager get all; assistant view-only — mirror the supplier-invoice permission block). Blade `purchase_rfq.blade.php` reuses `components/line_items.blade.php`.

- [ ] Failing HTTP feature tests: permission matrix (403 without), tenant isolation, group endpoint returns the comparison shape `{group_id, siblings: [{id, number, partner, status, validity_date, lead_time_days, responded_at, total, lines: [{product_id, variant_id, quantity, unit_price}]}]}`, validation errors use the `{error:{errors}}` envelope.
- [ ] Implement → PASS → scoped preflight (`vendor/bin/phpstan analyse app/Modules/Procurement`, `vendor/bin/pint app/Modules/Procurement --test`, RFQ test paths) → Commit: `feat(procurement): RFQ HTTP API + permissions + print template`

## WAVE 2 — Frontend

### Task 7: API layer + types (`features/purchases/quote-requests/{api.ts,types.ts}`)

**Produces:** `useQuoteRequests()`, `useQuoteRequest(id)`, `useQuoteRequestGroup(groupId)`, `useCreateQuoteRequestGroup()`, `useUpdateQuoteRequest()`, `useSendQuoteRequest()`, `useAwardQuoteRequest()`, `useReopenQuoteRequestGroup()` — all keys via `tenantScopedKey([...])`; money/qty as strings. Vitest: hooks call the right endpoints with string payloads; tenant-scope key test mirrors `supplier-invoices/api.tenantScope.test.tsx`.
- [ ] Failing tests → implement → PASS → Commit.

### Task 8: List + Create pages

List: reuse `DocumentListPage` pattern; group chip "3 fournisseurs — 2 réponses" (group rows collapsed by group_id). Create: multi-select supplier picker (max 10), shared lines editor (`<QuantityInput>`/`<MoneyInput>`), submit → N=1 routes to detail, N>1 routes to comparison view.
- [ ] Vitest: picker fan-out payload correct; i18n keys present; no parseFloat. → Commit.

### Task 9: Detail page (respond / send / convert)

Price cells editable in "Enregistrer réponse" mode; Envoyer + Convertir en BC actions; after award routes to PO detail; `Clôturée (non retenue)` state for lost siblings.
- [ ] Vitest render + action tests → Commit.

### Task 10: Comparison view + award/reopen

`/purchases/quote-requests/groups/:groupId` — rows correlated by (product_id, variant_id); unmatched rows unhighlighted; best unit price per row highlighted (string compare via existing decimal helpers, NOT Number()); totals + validity + lead time per column; award button per Responded column; reopen button when group closed + no live PO.
- [ ] Vitest: correlation + highlight logic (pure function `correlateGroupLines(siblings): ComparisonRow[]` unit-tested); award confirm dialog. → Commit.

### Task 11: Routes, i18n, Playwright

Routes under gated `/purchases` tree; i18n namespace additions FR (primary), EN, AR (3 places in i18n.ts). Playwright: fan-out 2 suppliers → respond both → compare → award → sibling shows Clôturée; run against local dev stack.
- [ ] `pnpm test` (scoped), `pnpm typecheck`, `pnpm lint` clean → Commit.

---

## Execution protocol (Codex waves)

1. Per wave: session owner (Claude) writes `docs/sessions/CODEX-TASK-<wave>.md` INTO the worktree = this plan's wave section + spec §refs inlined + the constraints block.
2. Dispatch: `cd /Users/houssamr/Projects/syneriva/apps/erp.procurement-v2 && node ~/.claude/plugins/cache/openai-codex/codex/<ver>/scripts/codex-companion.mjs task --write --background --fresh "Read docs/sessions/CODEX-TASK-<wave>.md and execute it fully."` (flags as separate argv tokens BEFORE the prompt — apostrophe trap).
3. Codex CANNOT git-commit in the worktree → it maintains `docs/sessions/TASK-LOG-<wave>.md` (files touched, tests run, results); Claude reviews the diff, runs scoped verification, commits.
4. Review gate per wave: adversarial review (Claude agent; treasury-reviewer for anything touching GL) → findings fixed → commit batch → merge `feat/procurement-completeness` → local `post-demo`.
5. Promotion to demo/`origin/dev`: OWNER decision only, after stability.

## Self-review (done)

- Spec coverage: Gap 1 §1.2–§1.12 all mapped (fan-out §1.4→T3, award+lock+reopen R3D-1/2→T4, invariants §1.12→T5, comparison correlation R3D-6→T10, index+sequence pre-seed R3D-7/8→T2, permissions §1.7→T6, print §1.5→T6, i18n/routes→T11). Waves 3–9 intentionally out (separate plans).
- No placeholders; types consistent (RfqPayload/CreateRfqData names used uniformly; award service is the single locking owner).
