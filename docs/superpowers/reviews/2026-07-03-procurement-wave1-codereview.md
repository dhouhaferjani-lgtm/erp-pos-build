# Adversarial Code Review — Procurement Wave 1 (RFQ Groups Backend)

**Date:** 2026-07-03
**Reviewer:** Claude (adversarial review, uncommitted working tree of `apps/erp.procurement-v2`, branch `feat/procurement-completeness`)
**Scope:** Plan `docs/superpowers/plans/2026-07-03-procurement-completeness-wave1-2-rfq-plan.md` Wave 1 vs spec `docs/superpowers/specs/2026-07-03-procurement-completeness-design.md` Gap 1 (§1.1–§1.12, Rev 3/3.1).
All file paths below are relative to `apps/erp.procurement-v2/apps/api/` unless noted.

## VERDICT: NEEDS-REVISION

Blockers: W1-1 (cross-company mutation), W1-2/W1-3 (regressions to the EXISTING purchase-order create/update path — collateral damage outside RFQ), W1-4 (hardcoded TND currency). The award/reopen concurrency findings (W1-5, W1-6) violate the spec's own R3D-1/R3D-2 hardening. Core design (sibling model, payload DTO, converter, invariants) is sound and matches spec.

---

## Findings

### W1-1 — HIGH — All RFQ mutation endpoints bypass company scoping (and drop `EnforceTokenTenantClaim`)

- `app/Modules/Procurement/Application/PurchaseQuoteRequestService.php:208-211` — `rfqQuery()` filters only `type`, never `company_id`/`tenant_id`. Consumed by `recordResponse` (:81), `markSent` (:105), and `reopenGroup` (:126, :141).
- `app/Modules/Procurement/Application/PurchaseQuoteRequestAwardService.php:26` — `Document::query()->whereKey($rfqId)` is fully unscoped.
- `app/Modules/Procurement/Presentation/Controllers/PurchaseQuoteRequestController.php:115-157` — `update`/`send`/`convertToPo`/`reopen` pass the raw id/groupId straight into the services. Contrast the READ endpoints (:41, :55, :66), which correctly use `baseQuery()` → `Document::forCompany($companyId)` (`Concerns/HandlesDocuments.php:43-47`).
- In a multi-company tenant, any user holding `purchase-quote-requests.update`/`convert` (tenant-scoped permission via `SetPermissionsTeam`) can respond-to, send, award, or reopen ANOTHER company's RFQs by id — the mutation paths never consult `CompanyContext` at all. Tenant boundary survives only because of db-per-tenant.
- Compounding: the new route group (`app/Modules/Procurement/Presentation/routes.php:25-29`) omits `EnforceTokenTenantClaim`, which the adjacent supplier-invoice group in the SAME file carries (:57-62).
- Plan Task 6 explicitly required a "tenant isolation" HTTP test — none exists (see W1-11).

**Fix:** pass `companyId` (from `CompanyContext::requireCompanyId()`) into every service method and add `->where('company_id', $companyId)` to `rfqQuery()` and the award winner fetch; add `EnforceTokenTenantClaim` to the route group; add cross-company 404 tests.

### W1-2 — HIGH — Regression: `documentLinePayloads()` silently drops `is_bonus_line` on PO create/update

- `app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:129-162` — the new whitelist keeps `description/quantity/unit_price/product_id/service_id/tax_rate/tax_configuration_id/discount_percent/discount_amount/notes/free_quantity/price_entry_mode/line_total` and discards everything else.
- `lines.*.is_bonus_line` is a validated input (`CreateDocumentRequest.php:124`, `UpdateDocumentRequest.php:102`, purchase-bonus-gated) and is read downstream at `PurchaseOrderController.php:383` (store) and `:507` (update) — after this change it is ALWAYS `false`. Bonus lines from purchase-bonus tenants are silently persisted as regular lines.
- The suite is blind to this: no test posts `is_bonus_line: true` through the HTTP path (`tests/Feature/Procurement/PurchaseBonusQuantityEntryTest.php:110` only asserts the `false` default; GL/matcher tests set the column via model writes).

**Fix:** add `is_bonus_line` (boolean, not string-cast) to the payload pass-through, and add an HTTP test that posts a bonus line and asserts `is_bonus_line => true` persisted.

### W1-3 — HIGH — Regression: explicit `null` line fields become `""` → uncaught exception (HTTP 500) on PO create/update

- `PurchaseOrderController.php:146-158`: `$payload[$key] = is_scalar($line[$key]) || $line[$key] === null ? (string) $line[$key] : null;` — `(string) null === ""` (verified). `lines.*.discount_percent`/`discount_amount` are `nullable` (`CreateDocumentRequest.php:127-128`), so a client sending `"discount_percent": null` (routine JSON) now yields `""`, and `normalizePurchaseLine` → `numericStringOrNull('')` → `numericString('')` throws `InvalidArgumentException` (`PurchaseOrderController.php:171-186`) → 500. Old code used `isset()` which mapped null → null.
- Same path: `free_quantity: null` → `""` defeats the `?? '0'` default at `:92` → `CurrencyScale::bcformatStrict('')` throws; `notes: null` → stored as `""` instead of `NULL`.

**Fix:** preserve nulls: `$payload[$key] = $line[$key] === null ? null : (is_scalar($line[$key]) ? (string) $line[$key] : null);` and add a test posting nulls for the optional line fields.

### W1-4 — HIGH — Fan-out hardcodes `currency => 'TND'` and bcmath scale 3

- `PurchaseQuoteRequestService.php:51` — every RFQ is created with `'currency' => 'TND'` regardless of company (France=EUR, UK=GBP companies get TND RFQs). The converter then carries it into the PO (`PurchaseQuoteRequestToPurchaseOrderConverter.php:98` copies `$source->currency`), so the awarded PurchaseOrder is also TND. Contrast the PO store path: `$validated['currency'] ?? $company->currency` (`PurchaseOrderController.php:343`).
- `replaceLines` hardcodes scale 3 (`PurchaseQuoteRequestService.php:178, 183-184, 195, 201`) instead of `CurrencyScaleResolverInterface->getScale($currency)` (CLAUDE.md rule 19). Self-consistent only while the currency is hardcoded TND.

**Fix:** the controller already holds `$company` — pass `$company->currency` into `createGroup`, and resolve scale via constructor-injected `CurrencyScaleResolverInterface` with the explicit currency.

### W1-5 — MEDIUM — Award: winner pre-lock defeats the R3D-1 ordered-lock guarantee (deadlock → 500 instead of 422)

- `PurchaseQuoteRequestAwardService.php:26` locks the winner FIRST, then `:35-41` locks all siblings `orderBy('id')`. Two concurrent awards of different siblings: Tx A holds lock(W_A), Tx B holds lock(W_B); each then scans the ordered sibling set and blocks on the other's winner → classic AB/BA deadlock. Postgres aborts one with 40P01 → HTTP 500, not the spec-promised "exactly one PO, one 422" (spec §1.4 R3D-1: "deterministic order → no deadlock").
- To be fair, the plan's own Task-4 skeleton (plan lines ~104-108) contains the same pre-lock; the implementation copied it. The spec's stated guarantee is the contract, and it is not delivered. No double-PO can result (the aborted tx rolls back fully), so this is UX/500 severity, not correctness.
- Otherwise the R3D-1 mechanics ARE correct: ordered `lockForUpdate()` on all siblings happens inside one transaction (:24, :35-41) BEFORE `assertNoLiveAwardedPo` (:43) and `assertResponded` (:44); the sibling query is company-scoped to the winner (:37; tenant implied by db-per-tenant — but see W1-1/W1-10); the `whereRaw` uses a bound parameter (:38) and its expression exactly matches the migration index expression. No sibling can be created after the lock set is taken: `group_id` is generated server-side at fan-out (`PurchaseQuoteRequestService.php:33`) and the PUT path both prohibits `payload.rfq.group_id` (`UpdatePurchaseQuoteRequestRequest.php:22`) and never writes caller payload group ids (`recordResponse` reuses the stored `groupId`, :86) — no path adds a member to an existing group.

**Fix:** fetch the winner WITHOUT `lockForUpdate` (read-only, just to resolve `group_id`), take the ordered `FOR UPDATE` sibling set, then re-read the winner from the locked collection (fail 404/422 if absent) before asserting.

### W1-6 — HIGH — `reopenGroup` has no transaction and no locks (R3D-2 race + partial writes)

- `PurchaseQuoteRequestService.php:124-169` — plain check-then-act: `hasLivePo` exists() (:131-135), then per-row saves (:148-166), all outside any transaction and with zero row locks, while `award()` takes `FOR UPDATE` on the same rows.
- Race: winning PO cancelled → user A calls reopen, passes the `hasLivePo` check; user B concurrently awards a still-`Confirmed` sibling (award's lock set doesn't conflict because reopen holds nothing) and commits a NEW live PO; reopen's saves then proceed (they block on award's row locks, resume after commit) and resurrect the freshly-`lost` siblings to `Confirmed` — a group with a live PO AND open siblings, violating "an awarded group is final" (spec §1.4 Rev 3.1). A midway failure also leaves a partially-restored group.
- What IS correct: "live" correctly means `status != Cancelled` PO with `source_document_id` in the sibling set (:131-135, matching `assertNoLiveAwardedPo`), and only `closed_reason === 'lost'` siblings are restored (:150-152) — a legitimately cancelled RFQ (no `lost` marker) stays cancelled.

**Fix:** wrap `reopenGroup` in `db->transaction()`, take the same `orderBy('id')->lockForUpdate()` sibling set as award, and re-check `hasLivePo` under lock.

### W1-7 — MEDIUM — `recordResponse`/`markSent` resurrect Cancelled ('lost') siblings, bypassing the reopen guard

- `PurchaseQuoteRequestService.php:84` and `:108` set `status = Confirmed` unconditionally. Calling PUT `/purchase-quote-requests/{id}` (or `/send`) on a loser that is `Cancelled` + `closed_reason='lost'` flips it back to `Confirmed` while `closed_reason` stays `'lost'` (:92) — an awarded-and-final group regains a live-looking sibling without going through `reopen`'s live-PO guard. (A later award of it is still blocked by `assertNoLiveAwardedPo`, but the state contradicts §1.12's status mapping and the Rev 3.1 finality rule.)

**Fix:** both methods should reject `DocumentStatus::Cancelled` documents with a 422 `DomainException` (reopen is the only sanctioned resurrection path).

### W1-8 — MEDIUM — Non-UUID `{id}` → PG cast error → 500 instead of 404

- `documents.id` is uuid (`Document.php:108` `use HasUuids`). `show` uses `find($id)` (`PurchaseQuoteRequestController.php:55`), `recordResponse`/`markSent` use `findOrFail` (`PurchaseQuoteRequestService.php:81, :105`), award uses `whereKey` (`PurchaseQuoteRequestAwardService.php:26`) — `GET/PUT/POST .../not-a-uuid` throws `invalid input syntax for type uuid` → 500. This is the documented project pitfall ("UUID cols in PG: validate `Str::isUuid()` … or it 500s").

**Fix:** `Str::isUuid($id)` guard (404 on failure) at the top of the controller actions, or a route `whereUuid('id')` constraint on all four `{id}` routes (and `{groupId}` for symmetry).

### W1-9 — MEDIUM — `lines.*.product_id` accepted with no existence/company check; `partner_ids` allows duplicates

- `CreatePurchaseQuoteRequestRequest.php:40` / `UpdatePurchaseQuoteRequestRequest.php:25` — `product_id` is only `uuid`. `document_lines.product_id` is FK-constrained (`database/migrations/tenant/2025_11_30_080001_create_document_lines_table.php:16`), so a well-formed but nonexistent uuid → FK violation → 500 (should be 422). A product belonging to ANOTHER company in the same tenant passes (FK is tenant-DB-wide) — contrast `partner_ids.*` which correctly uses `ScopedExists::tenantAndCompany` (:37).
- `partner_ids` lacks `distinct` — `[A, A]` fans out two sibling RFQs to the same supplier in one group.

**Fix:** `ScopedExists::tenantAndCompany('products', …)` on `lines.*.product_id` (both requests) and add `'distinct'` to `partner_ids.*`.

### W1-10 — MEDIUM — No query can use the new group index (leading column `tenant_id` never filtered)

- Migration index: `(tenant_id, ((payload->'rfq'->>'group_id'))) WHERE type='purchase_rfq'` (`database/migrations/tenant/2026_07_03_200000_add_rfq_group_index.php:14`) — exactly the spec §1.3 shape. But every group query written filters `company_id` (award, `PurchaseQuoteRequestAwardService.php:37`; controller group view via `forCompany`, `PurchaseQuoteRequestController.php:66-68`) or NOTHING (`reopenGroup`, `PurchaseQuoteRequestService.php:126-127, :141-142`) — never `tenant_id`. A b-tree can't seek without its leading column, so group lookups/awards/reopens are sequential scans over `documents`.

**Fix:** either add `tenant_id` to those queries (documents carry it, and it also tightens W1-1) or index on `(company_id, (payload->'rfq'->>'group_id'))` instead.

### W1-11 — MEDIUM — Promised tests silently skipped: concurrency lock test, tenant isolation, permission matrix, and any HTTP coverage of update/send/convert/reopen

- Plan Task 4 required a **concurrency test** ("two awards, pcntl or sequential-with-stale-read simulation"); spec §1.9 pins "two concurrent awards of different siblings → exactly one PO + one 422 (lock test)". What shipped, `tests/Feature/Procurement/PurchaseQuoteRequestAwardTest.php:91-101`, is a plain sequential second award — the service re-fetches fresh state by id, so not even stale-read staleness is simulated; the `FOR UPDATE` path is never contended and W1-5's deadlock is invisible to it.
- Plan Task 6 required "permission matrix (403 without), tenant isolation" — `PurchaseQuoteRequestHttpTest.php` has ONE permission test (viewer cannot create, :128-134) and zero tenant/company-isolation tests. The `update`/`send`/`convert-to-po`/`reopen` endpoints are never exercised over HTTP at all (only via direct service calls), so their route middleware, `can:` names, error envelope mapping, and W1-8's 500s are all untested.

**Fix:** add the two-connection lock test (the plan cites the credit-note D1 precedent), cross-company 404 tests, a permission matrix over all 8 routes, and HTTP tests for the four uncovered endpoints.

### W1-12 — LOW — `convertToPo` catches only `DomainException`; `InvalidArgumentException` paths → 500

- `PurchaseQuoteRequestController.php:139-143`. `award()` throws `InvalidArgumentException` for a non-RFQ id (`PurchaseQuoteRequestAwardService.php:29`) and `RfqPayload::fromArray` throws it for a malformed/missing `rfq` payload (:32; `RfqPayload.php:31`) — both escape as 500s. Should map to 404/422 in the standard `{error:{code,message}}` envelope.

### W1-13 — LOW — Reopen restores never-sent losers to `Confirmed` (unmapped §1.12 state)

- A sibling that was still `Draft` when the group was awarded gets `Cancelled`+`lost` (award closes ALL non-winners regardless of status, `PurchaseQuoteRequestAwardService.php:48-65`). `reopenGroup` then restores it to `Confirmed` (`PurchaseQuoteRequestService.php:154`) with neither `sent_at` nor `response_recorded_at` — a status outside the spec's Sent/Responded derivation. Spec wording assumed responded losers. **Fix:** restore to `Confirmed` only when `sentAt`/`responseRecordedAt` is set, else `Draft`.

### W1-14 — LOW — Out-of-scope edit to `PurchaseBonusGate` removes a null guard

- `app/Modules/Procurement/Application/PurchaseBonusGate.php:19-23` (diff): previously `tenant === null` (after load) returned `false`; now a possibly-null `$company->tenant` is passed into `getConfigForTenant(Tenant $tenant)` (`app/Services/CompanyConfigService.php:48`, non-nullable) → `TypeError` 500 on a dangling tenant relation where the old code degraded gracefully. Also plain scope creep (rule 4) — nothing in Wave 1 requires touching this file. **Fix:** revert, or keep the `relationLoaded` optimization but restore `if ($company->tenant === null) return false;`.

### W1-15 — LOW — No French PDF title for the RFQ ("Demande de Prix")

- `DocumentPdfService::getDocumentTitle` has no `purchase_rfq` entry in either locale map, so all locales fall through to `label()` = "Purchase Quote Request" — an English title on a French/Tunisian supplier-facing document whose whole feature name is *Demande de prix* (prefix `DP`). Add `fr`/`en` entries.

### W1-16 — LOW — Migration not re-run-safe

- `database/migrations/tenant/2026_07_03_200000_add_rfq_group_index.php:14` — `CREATE INDEX` without `IF NOT EXISTS`; `:20-37` — `updateOrInsert` puts `last_number => 0` in the UPDATE payload, so any re-run after RFQs exist resets live sequence counters → duplicate `DP-YYYY-NNNN` numbers. Migrations run once per tenant DB in practice, so severity LOW, but pre-seed idempotency was an explicit ask (spec R3D-7). **Fix:** `IF NOT EXISTS` on the index; per-company `exists()` check (insert-only) instead of `updateOrInsert`. `down()` is otherwise correct (drops the index; deletes only untouched `last_number=0` seed rows).

---

## Verified-OK (attack surfaces that held)

- **Award lock mechanics** (modulo W1-5): ordered sibling `FOR UPDATE` inside one transaction, guard + Responded assertion under lock, bound-param `whereRaw` matching the index expression, no fan-out-into-existing-group escape path (server-generated immutable `group_id`).
- **Reopen semantics:** "live" = non-cancelled PO; only `closed_reason='lost'` restored (user-cancelled RFQs stay cancelled).
- **Fan-out:** single transaction, one `Str::uuid7()` group id, per-document numbers (nested numbering transaction = savepoint), max 10 partners, `uuid` rule before `ScopedExists` (no partner UUID-500), PUT `payload.rfq.group_id` prohibited AND ignored by the service.
- **Converter:** responded prices carried verbatim into PO `unit_price` (test asserts `8.200`), `source_document_id` set, PO lands `Draft`, rejects wrong type + non-Responded, registered additively in `DocumentServiceProvider` (existing pairs untouched). PO `balance_due=total` is consistent with the payables model (reports require `Posted`, so no Draft leak).
- **§1.12 invariants:** `FiscalCategory::fromDocumentType` default arm → NonFiscal (pinned by unit test); `getPrefix`='DP' unique; `affectsReceivable`/`receivableDirection`/`canTransitionToPaid` default arms correct; no other exhaustive `match` on `DocumentType` exists in `app/`; zero-GL/zero-stock feature test present; payable/receivable reports filter by explicit type whitelists + `Posted`, so RFQs (Draft/Confirmed/Cancelled, `balance_due='0.000'`) cannot appear.
- **HTTP layer:** rule-12 middleware trio present (but see W1-1 re `EnforceTokenTenantClaim`); all `can:` names exactly match seeded permissions; precision regexes exactly `/^-?\d+(\.\d{1,4})?$/` (qty) and `/^-?\d+(\.\d{1,3})?$/` (price); `{error:{errors}}` envelope covered by `AssertsApiValidation`.
- **Group endpoint:** R3D-6 shape `{group_id, siblings:[{…, lines:[{product_id, variant_id, quantity, unit_price}]}]}` asserted; eager-loads `partner`+`lines`, no N+1.
- **Types:** `packages/shared/types/generated.d.ts` delta (DocumentType union + `RfqPayload` namespace) is consistent with `typescript:transform` output of the `#[TypeScript]` DTO — no hand-edit markers.
- **Blade:** reuses the shared `prepareData` pipeline (all referenced vars — `$company`, `$partner`, `$lines`, `$formatMoney`, `$formatNumber`, `$documentTitle` — are always supplied); a draft RFQ renders with `0.000` prices and `showTax=false`; nullable `notes` guarded.
