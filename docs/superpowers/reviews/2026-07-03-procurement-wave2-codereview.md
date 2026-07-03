# Adversarial Code Review — Procurement Wave 2 (RFQ Groups Frontend)

**Date:** 2026-07-03
**Scope:** Uncommitted working tree in `apps/erp.procurement-v2` — untracked `apps/web/src/features/purchases/quote-requests/` (12 files, 1843 lines) + modified `apps/web/src/routes/index.tsx` + `apps/web/src/locales/{fr,en,ar}/purchases.json` (+68 lines each). Implements plan Tasks 7–11 against spec Gap 1 §1.5, on top of committed Wave 1 backend (`556df68ed`).
**Verification run:** scoped Vitest 5 files / 12 tests PASS; `pnpm typecheck` PASS; scoped ESLint (quote-requests + routes/index.tsx) PASS, 0 errors.

## VERDICT: NEEDS-REVISION

The plumbing is genuinely clean — every hook matches the real controller contract with correct unwrapping, every query key is tenant-scoped, money/qty are strings end-to-end, tokens-only styling, i18n key sets are identical across fr/en/ar. But the feature as shipped is not usable or safe: it has no navigation entry, no error handling on any mutation, a reopen button that can never appear in the scenario it was built for, a best-price highlight that a non-responded 0-price draft always wins, and two round-trip data-loss bugs caused by fields the backend omits. Most majors are cheap fixes; two require the backend deltas proposed at the end (§Backend deltas).

---

## What was checked and found CORRECT (attack surfaces 1–3, 6–8)

**Contract fidelity (surface 1)** — all 8 hooks in `api.ts` verified against `PurchaseQuoteRequestController.php` + `Presentation/routes.php:31-60`:

| Hook | Endpoint | Controller returns | FE handling | OK |
|---|---|---|---|---|
| `useQuoteRequests` (api.ts:89-92) | GET `/purchase-quote-requests` | `{data: [...]}` no meta (controller:50) | `api.get` + `response.data`, page reads `data?.data` (ListPage:41) | ✓ |
| `useQuoteRequest` (api.ts:105) | GET `/{id}` | `{data: detail}` (controller:61) | `apiGet` single unwrap | ✓ |
| `useQuoteRequestGroup` (api.ts:117) | GET `/groups/{groupId}` | `{data:{group_id,siblings}}` (controller:79-84) | `apiGet` single unwrap | ✓ |
| `useCreateQuoteRequestGroup` (api.ts:129) | POST `/` | 201 `{data:{group_id,siblings}}` (controller:111-116) | `apiPost` single unwrap | ✓ |
| `useUpdateQuoteRequest` (api.ts:145) | PUT `/{id}` | `{data: detail}` (controller:140) | `apiPut` single unwrap (api.ts lib:221-224 confirmed unwraps `.data.data`) | ✓ |
| `useSendQuoteRequest` (api.ts:166) | POST `/{id}/send` | `{data: detail}` (controller:153) | ✓ | ✓ |
| `useAwardQuoteRequest` (api.ts:187) | POST `/{id}/convert-to-po` | `{data:{id,type,status}}` (controller:166) | ✓ matches `AwardQuoteRequestResponse` | ✓ |
| `useReopenQuoteRequestGroup` (api.ts:206) | POST `/groups/{groupId}/reopen` | `{data:{reopened}}` (controller:179) | ✓ | ✓ |

No double-unwrap anywhere. Payload shapes match `CreatePurchaseQuoteRequestRequest`/`UpdatePurchaseQuoteRequestRequest` (uuid partner/product ids, string quantity/unit_price with regex ceilings, `lead_time_days` integer).

**tenantScopedKey (surface 2)** — every `useQuery` key wrapped (api.ts:83, 104, 116); `enabled` gates on tenant+company (api.ts:80, 101, 113) per the tenantScopedKey doc-comment guidance; all mutations invalidate via tenant/company-suffix-aware predicates (api.ts:25-75). `audit-tanstack-keys` reported 0 new entries (task log). One invalidation *gap* — see W2-7.

**Money/qty (surface 3)** — zero `parseFloat`/`Number()` on money (grep confirmed; the only numeric parse is `parseInt(leadTimeDays,10)` DetailPage:90 on a day count, not money). `MoneyInput`/`QuantityInput` emit raw strings (MoneyInput.tsx:54, :93). Best-price uses `bccomp` (comparisonLogic.ts:66, 72) — Big.js string compare, not float. Payloads carry strings end-to-end (asserted in api.tenantScope.test.tsx:154-187).

**i18n (surface 6)** — all 46 referenced `quoteRequests.*` keys present; programmatic key-set diff fr/ar vs en: 0 missing / 0 extra (48 keys each). Dynamic `status.${status}` covered for draft/confirmed/cancelled. `common:status.loading` / `common:actions.cancel` are existing keys.

**Routes/gating (surface 7)** — 4 routes under `RequirePermission moduleKey="purchases"` (routes/index.tsx:788-826), byte-for-byte the supplier-invoices pattern (routes/index.tsx:884-902); lazy imports consistent with siblings (routes/index.tsx:59-62). `groups/:groupId` registered before `:id` (and v6 ranking handles it regardless).

**Design tokens (surface 8)** — grep for raw Tailwind color classes in the new directory: zero hits. All styling via `tokens`/`textColors`/`borderColors`; `tokens.modal.*`, `badge.blue/green/gray`, `table.header/rowHover`, `heading.section` all exist in `designTokens.ts`.

**correlateGroupLines core (surface 4)** — pure function, correlates by `(product_id, variant_id)` with null variant handled (`key = product::""`, test asserts `product-1::` correlates across siblings with `variant_id: null`, ComparisonPage.test.tsx:92-96); unmatched row (line on one sibling only) gets `bestSiblingIds: []` (comparisonLogic.ts:60-63, test :99-106); diverging quantities → price-only highlight with per-cell quantity display (test: 50 vs 40 qty, best by price alone, :94-96). Ordering is first-encounter Map insertion order over backend-ordered siblings (`orderBy('document_number')`, controller:72) — deterministic, though untested (W2-11).

---

## Findings

### W2-1 — MAJOR — Feature has no navigation entry; unreachable except by typed URL
`apps/web/src/components/organisms/Sidebar/Sidebar.tsx:168-177` — the `purchases` nav group lists suppliers/orders/receipts/supplierInvoices/returnNotes; no `quote-requests` item was added. Convention 02-NAVIGATION-ROUTING requires new pages to be wired into the dashboard nav; the task log does not document this as a deviation.
**Fix:** add `{ key: 'quoteRequests', href: '/purchases/quote-requests', icon: FileQuestion }` to the purchases children + `navigation.quoteRequests` in `locales/{fr,en,ar}/common.json` (sidebar resolves labels via `t('navigation.${key}')`, Sidebar.tsx:367).

### W2-2 — MAJOR — Zero mutation error handling; every 422 is a swallowed unhandled rejection
Every `mutateAsync` call is un-caught and no hook has `onError`:
- `QuoteRequestCreatePage.tsx:76` (create — duplicate suppliers `distinct` 422, non-UUID product 422)
- `QuoteRequestDetailPage.tsx:87, 97, 101` (save response / send / convert — `RFQ_CANCELLED`, `RFQ_GROUP_ALREADY_AWARDED`, "must have a recorded response" all return 422 via `validationErrorResponse`, controller:129-131, 148-150, 161-163)
- `QuoteRequestComparisonPage.tsx:40, 46` (award / reopen — on failure the confirm modal stays open forever, `setPendingAwardId(null)` at :41 never runs)

The user gets no feedback of any kind. The sibling feature uses `sonner` toasts with `onError` extracting `error.error.message` (`SupplierInvoiceDetailPage.tsx:86-114`).
**Fix:** wrap each action in try/catch (or `onError` on the mutations) and `toast.error(...)` mirroring the supplier-invoice pattern; close/keep the award modal deliberately.

### W2-3 — MAJOR — Reopen button can never appear in the scenario it exists for; award buttons survive an award
`QuoteRequestComparisonPage.tsx:34-35`:
```ts
const groupClosed = group.siblings.length > 0 && group.siblings.every((s) => s.status === 'cancelled')
const canReopen = groupClosed && group.has_live_purchase_order !== true
```
Spec (design doc :146): reopen is for an **awarded group whose winning PO was cancelled**. But award leaves the winner `Confirmed` and only losers `Cancelled` (`PurchaseQuoteRequestAwardService.php:57-74`), and no other code path cancels an RFQ — so `every(cancelled)` is unsatisfiable and the reopen button is dead UI. Conversely, since the API omits `has_live_purchase_order` (documented deviation), `undefined !== true` means the flag never suppresses anything: if gating is later loosened without the backend flag, users would fire reopen against a live PO and get a raw 422 (`PurchaseQuoteRequestService.php:168-170`) — invisible per W2-2. Same class of problem on award: after a successful award, the winner column still satisfies `responded_at && status==='confirmed'` (ComparisonPage:94) so its award button remains clickable → guaranteed `RFQ_GROUP_ALREADY_AWARDED` 422.
**Fix (FE, after backend delta b):** `canReopen = group.siblings.some((s) => s.status === 'cancelled') && group.has_live_purchase_order === false`; hide all award buttons when `has_live_purchase_order === true`. Keep the strict `=== false` so a missing flag fails closed, not open.

### W2-4 — MAJOR — Best-price highlight includes non-responded siblings; a 0.000 draft always "wins"
`comparisonLogic.ts:59-75` computes best price over **all** cells on a fully-matched row. Fan-out creates identical lines on every sibling with `unit_price` defaulting to `'0'` when not provided (`PurchaseQuoteRequestService.php` `replaceLines`: `$unitPrice !== null ? $unitPrice : '0'`; CreatePage default `'0.000'` at :26). So in the normal "2 of 3 responded" state, the never-responded sibling's placeholder 0.000 is highlighted as best price on every row — actively wrong procurement guidance. Spec §1.5 (design doc :151ff): "**responded** unit prices side by side with best-price-per-line highlighting". Tests only cover the all-responded case (ComparisonPage.test.tsx:41-80).
**Fix:** pass sibling responded-ness into `correlateGroupLines` (or filter siblings) and compute `bestSiblingIds` over cells whose sibling has `responded_at != null` (require ≥ 2 responded cells to highlight at all); render non-responded cells as a muted "awaiting response" state instead of a price. Add the mixed-response unit test.

### W2-5 — MAJOR — Round-trip data loss: re-saving a response wipes `supplier_reference` and all line descriptions (backend omission + FE overwrite)
Backend `formatDetail` (controller:210-233) omits `supplier_reference` (present in `RfqPayload`) and `formatLine` (controller:243-252) omits `description` (persisted on `DocumentLine`). Consequences in the FE, which correctly round-trips both fields:
- `QuoteRequestDetailPage.tsx:77` initializes `supplierReference` from the (never-present) field → `''` → `saveResponse` (:89) sends `null` → a second "Record response" **erases the previously saved supplier reference**.
- `toEditableLine` (:31) gets `description: undefined → null` → `replaceLines` stores `'RFQ line'` — **every response save destroys the buyer's line descriptions**, and the comparison/detail line labels (`comparisonLogic.ts:26`, DetailPage:252) degrade to raw product UUIDs on real data.
The Vitest suites hide this by mocking a richer contract than the API ships (DetailPage.test.tsx:52-60 puts `description` on lines; ComparisonPage.test.tsx likewise) — see W2-12.
**Fix:** backend delta (c) below; no FE change needed once the fields exist.

### W2-6 — MAJOR — Currency hardcoded to `'TND'` in every money render and input
`QuoteRequestListPage.tsx:116`, `QuoteRequestDetailPage.tsx:184, 268, 271`, `QuoteRequestCreatePage.tsx:194`, `QuoteRequestComparisonPage.tsx:86, 127`. Wrong currency code AND wrong scale for non-TND tenants (`formatCurrency` derives decimals from the code: TND=3, EUR=2 — decimal.ts:179-193), and `MoneyInput currency="TND"` sets a 0.001 step for EUR tenants. The sibling feature threads `invoice.currency` from the API (`SupplierInvoiceDetailPage.tsx:230, 300, 306`). The RFQ API omits `currency` even though the document stores it.
**Fix:** backend delta (c) adds `currency` to list + detail payloads; FE uses `quoteRequest.currency` / `sibling.currency`; CreatePage takes the active company's currency (company store), as no document exists yet.

### W2-7 — MEDIUM — Award never invalidates the group query; 5-minute staleTime makes the stale comparison a 422 trap
`useAwardQuoteRequest` (api.ts:181-197) invalidates list + detail(id) only — no group predicate, and the hook has no groupId. App default `staleTime` is 5 min (`apps/web/src/lib/queryClient.ts:6`), so navigating back to `/groups/:groupId` after awarding shows the pre-award snapshot: all columns still "Responded" with live award buttons → click → `RFQ_GROUP_ALREADY_AWARDED` 422, invisible per W2-2.
**Fix:** give `useAwardQuoteRequest` an optional `groupId` param (both call sites have it: `group.group_id` on the comparison page, `quoteRequest.group_id` on the detail page) and add `quoteRequestGroupInvalidationPredicate(groupId, ...)` to `onSuccess`.

### W2-8 — MEDIUM — List response lacks group metadata: grouping never happens and the chip misinforms (documented deviation — NOT safe as-is)
`formatListItem` (controller:193-208) returns only id/number/partner/status/total. So in `QuoteRequestListPage`: `item.group_id ?? item.id` (:21) puts every sibling in its own "group"; a 3-supplier RFQ renders as 3 unrelated rows, each chip reading "1 supplier — 0 responses" (`responded_at` also absent → `hasResponse` :34-36 always false), and every row deep-links to the detail page — the comparison view is unreachable from the list. Also `useQuoteRequests` forwards `status`/`search` params (api.ts:85-90) that `index()` (controller:39-51) silently ignores. The fallback doesn't crash, but a chip asserting wrong facts is worse than no chip.
**Assessment:** unsafe as a UX (misinformation + hides the flagship comparison view). Minimal backend fix is delta (a) — two fields from the already-loaded payload, zero extra queries.

### W2-9 — MEDIUM — Create page requires hand-typing a product UUID into a free-text input
`QuoteRequestCreatePage.tsx:170-176` renders a bare `<input>` for `product_id`, while validation requires `uuid` + `ScopedExists` on products (`CreatePurchaseQuoteRequestRequest.php` rules `lines.*.product_id`). Any human entry ("Crème solaire SPF50") → 422, silent per W2-2. Suppliers got the proper `PartnerSearchSelect` (:124-129); products have an existing house component (`apps/web/src/components/molecules/line-items/ProductLineSelect.tsx`). Spec §1.5 says "reuse `DocumentForm` where possible". Not release-usable without this.
**Fix:** swap the text input for `ProductLineSelect` (which also fills description/variant), keeping the string qty/price inputs.

### W2-10 — LOW — Sent-but-unanswered RFQs display "Response Recorded"
`markSent` sets status `Confirmed` (`PurchaseQuoteRequestService.php` markSent) without touching `responseRecordedAt`, and the FE maps `status.confirmed` → "Response Recorded"/"Réponse enregistrée" (locales; ListPage:112, DetailPage:129). A merely-sent RFQ is mislabeled.
**Fix:** derive the label: `confirmed && !responded_at` → new `status.sent` key ("Envoyée"); backend delta (c) optionally exposes `sent_at` for exactness.

### W2-11 — LOW — correlateGroupLines edge gaps (test quality)
comparisonLogic.ts:33-76 / ComparisonPage.test.tsx:89-107. Covered: matched, unmatched-on-one-sibling, diverging-qty price-only highlight, null-variant correlation, string-decimal compare (`8.100` vs `8.000`). Missing: (i) duplicate `(product_id, variant_id)` lines within one sibling silently overwrite the cell (:49) — last-wins, no test; (ii) no deterministic-ordering assertion (ordering is de facto stable: Map insertion over `orderBy('document_number')` siblings); (iii) label takes first-seen description (:44) — fine, but untested; (iv) no mixed responded/non-responded fixture (the case that would have exposed W2-4).
**Fix:** add the two unit tests; either sum quantities or suffix keys for duplicate lines (or document last-wins).

### W2-12 — LOW — Page tests mock a contract richer than the real API (masked W2-5/W2-8)
`QuoteRequestDetailPage.test.tsx:52-60` and `QuoteRequestComparisonPage.test.tsx:56-75` fabricate `description` on lines; `QuoteRequestListPage.test.tsx:32` fabricates `group_id`/`responded_at` on list items. All 12 tests pass while the shipped backend serves none of those fields on those endpoints. Once backend deltas land the mocks become true; until then the suite validates fiction.
**Fix:** after the backend deltas, keep the fixtures; add one list-page test with the *current* metadata-free shape pinning the fallback behavior.

### W2-13 — NIT
- `routes/index.tsx:786` — comment above the quote-requests block says `{/* Purchase Orders */}` (copy-paste).
- `groupChip` uses manual `{{suppliers}} suppliers — {{responses}} responses` interpolation, no i18next plural forms — Arabic pluralization will read wrong for 1/2/3-10 counts.
- `key={index}` on dynamic supplier/line arrays (`QuoteRequestCreatePage.tsx:119, 169`) — reorder/removal re-render hazards.
- Spec sketch §1.5 shows Imprimer / Envoyer par e-mail on the detail page; Wave 1 shipped the blade but no FE print/email action exists (plan Task 9 didn't require it — flag for the Wave-2 follow-up list, not a blocker).

---

## Plan coverage (Tasks 7–11) + deviation assessment (surface 9)

| Task | Delivered | Notes |
|---|---|---|
| 7 API+types | ✓ | 8 hooks, tenant-scope + endpoint/payload tests (api.tenantScope.test.tsx, 2 tests) |
| 8 List+Create | ✓ built / ✗ usable | grouping dead without backend metadata (W2-8); product UUID input (W2-9) |
| 9 Detail | ✓ | respond/send/convert + lost-sibling state; data-loss round-trip (W2-5) |
| 10 Comparison | ✓ built / ✗ correct | correlation solid; best-price wrong with drafts (W2-4); reopen unreachable (W2-3) |
| 11 Routes/i18n/Playwright | ✓ routes+i18n | i18n.ts untouched — legitimately reused `purchases` namespace; Playwright skipped |

Documented deviations, assessed:
1. **Playwright skipped** (task log :116) — acceptable as a sequencing call, but NOT as a merge condition: an E2E pass would have immediately caught W2-1 (no nav) and W2-9 (UUID input). Run it after the fix round, before merge to `post-demo`.
2. **Group metadata missing from list** (task log :38) — fallback doesn't crash but actively misinforms (W2-8). Not safe to ship; close with delta (a).
3. **`has_live_purchase_order` optional** (task log :69) — unsafe: combined with the wrong `groupClosed` predicate it produces dead reopen UI now and a fail-open 422 path if gating is loosened later (W2-3). Close with delta (b) and flip FE to fail-closed `=== false`.

---

## Proposed backend deltas (surface 10) — Wave 1 controller, for the session owner

**(a) Group metadata in the RFQ list response** — `PurchaseQuoteRequestController.php:193-208` (`formatListItem`). The full model (incl. `payload`) is already hydrated by `index()`; zero extra queries:

```php
private function formatListItem(Document $document): array
{
    $payload = RfqPayload::fromArray($document->payload ?? []);

    return [
        'id' => $document->id,
        'number' => $document->document_number,
        'partner' => [
            'id' => $document->partner_id,
            'name' => $document->partner->name,
        ],
        'status' => $document->status->value,
        'total' => $document->total,
        'currency' => $document->currency,          // closes W2-6 for the list
        'group_id' => $payload->groupId,            // closes W2-8 grouping
        'responded_at' => $payload->responseRecordedAt, // closes W2-8 chip counts
    ];
}
```
FE needs no change (types already model all three as optional). Optionally honor `status`/`search` in `index()` (controller:39-51) or drop the params from `useQuoteRequests` — currently dead weight either way.

**(b) `has_live_purchase_order` in the group response** — `PurchaseQuoteRequestController.php:64-85` (`group()`), between the `isEmpty()` guard (:76-78) and the response (:79):

```php
$hasLivePurchaseOrder = Document::query()
    ->where('type', DocumentType::PurchaseOrder)
    ->where('tenant_id', $company->tenant_id)
    ->where('company_id', $company->id)
    ->whereIn('source_document_id', $siblings->pluck('id')->all())
    ->where('status', '!=', DocumentStatus::Cancelled)
    ->exists();

return response()->json([
    'data' => [
        'group_id' => $groupId,
        'has_live_purchase_order' => $hasLivePurchaseOrder,
        'siblings' => ...,
    ],
]);
```
This is the identical predicate already duplicated in `PurchaseQuoteRequestAwardService::assertNoLiveAwardedPo()` and `PurchaseQuoteRequestService::reopenGroup()` (:155-166) — a third copy is acceptable for the minimal fix, but extracting a small shared `RfqGroupPoProbe` (or static query scope) is the right follow-up. Add `DocumentStatus` import to the controller. Pair with FE fail-closed gating per W2-3.

**(c) Round-trip completeness (closes W2-5 / W2-6 / W2-10)** — same controller:
- `formatDetail` (:210-233): add `'supplier_reference' => $payload->supplierReference`, `'sent_at' => $payload->sentAt`, `'currency' => $document->currency`.
- `formatLine` (:243-252): add `'description' => $line->description`.
All four fields already exist on the FE types (`types.ts:11, 24`) or are trivial optional additions; the detail page then stops erasing supplier references and line descriptions on re-save.

---

## Fix-round exit criteria

W2-1..W2-9 fixed (W2-3/5/6/8 gated on deltas a–c), scoped Vitest/typecheck/ESLint green, then the deferred Playwright pass (fan-out 2 → respond both → compare → award → sibling Clôturée) against the live stack before merging `feat/procurement-completeness` → local `post-demo`.
