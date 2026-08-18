# M2 adversarial merge-gate register — round 1

**Range reviewed:** `7d85232cc..1a688283a` (whole branch; M2 = commits `610a2d6f6`…`6d8b84f0e`, evidence commit `1a688283a`).
**Acceptance criteria:** brief §5 wave-2 row + SPEC r5 §3(b)/§3(c), §4.1 S-4/S-5/S-12, §4.4 S-11, §4.5, §7.1 BT-6/7/12/13/14/15/17, §7.2 FT-4…FT-8/FT-12/FT-13, §6 CL-3/CL-4.
**Lenses:** fiscal-pos, frontend-conventions, treasury, tenancy-authz, general — all applied; all applicable.

---

### 1. **P1 — CONFIRMED — tenancy-authz** — S-11's chain-verify arm is unimplemented and undeclared
`apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php:411-490`

SPEC §4.4 rule 3 requires the allowed-location predicate on **six** paths including "the (d) chain-verify **terminal selector**", and §7.5 puts `S-11 (show/PDF/**(d)** half)` in **wave 2** (the brief §5 abbreviates it to "show/PDF half"; §0 rules the spec wins). `verifyReceiptChain` is byte-unchanged in this range: it validates `terminal_id` only through `ScopedExists::tenantAndCompany` (`:415-424`) and re-checks `company_id` (`:429-437`). `LocationScopeResolver`/`LocationScopeBoundary` are injected (`:48-49`) but used only at `:268-269`, never here. BT-12(vi) ("its terminal … rejected by verify-chain") has no counterpart in `ReceiptLocationScopeAuthorizationTest.php`, and the handback §"S-11 show/PDF half" (line 111) claims DONE without naming the residual — a silent deviation under brief §8.8.

**Failure scenario:** a store manager whose `UserCompanyMembership.allowed_location_ids = [A]` and who holds `pos.view_reports` POSTs `/pos/reports/receipts/verify-chain` with a terminal UUID from location B (same company). Response 200 with `chain_length`, `first_receipt` and `last_receipt` — i.e. the receipt numbers and volume of a location they are 404'd from on `show`/`downloadPdf`. That is precisely the enumeration §4.4 rule 4 pins the 404 semantics to prevent. Note A-2 hands `pos.view_reports` to a new role in this very build.

### 2. **P2 — CONFIRMED — fiscal-pos / general** — BT-6's two decisive assertions are absent; the S-4 enrichment path is untested
`apps/api/tests/Feature/POS/ReceiptShowResourceTest.php:16-100`, code at `ReceiptDetailData.php:88-90`

BT-6 requires (a) "every line carries `quantity_decimals` **matching `product.unitOfMeasure.decimal_places`**" and (b) "assert `product_code` equals the **snapshot column**, not the current `products.sku`, **by mutating the product's SKU after the receipt exists and re-reading the endpoint**". The single test fixture has `product_id => null`, so it exercises only the `QuantityScale::SCALE` fallback branch and there is no product to mutate. Neither assertion exists anywhere in the range (`grep unitOfMeasure`/`decimal_places` over `tests/Feature/POS` returns nothing new).

**Failure scenario:** delete the ternary at `ReceiptDetailData.php:90` and hardcode `$quantityDecimals = 4`. The whole M2 backend slice still passes, and a pharmacy tenant whose unit is `decimal_places = 2` silently renders `1.2500` instead of `1.25` on every receipt line — the exact OI-17-adjacent behaviour the brief restates as **required**. Equally, re-sourcing `product_code` from `$line->product->sku` (the r4 defect) passes today's suite.

### 3. **P2 — CONFIRMED — fiscal-pos / frontend-conventions** — `refund_reason_source` never changes what the user reads
`apps/web/src/features/pos/pages/RefundReceiptListPage/RefundReceiptListPage.tsx:170-171`; `apps/web/src/features/pos/pages/ReceiptDetailPage/ReceiptDetailPage.tsx:219`

SPEC §3.c.2 requires `refund_reason` "rendered **per** its `refund_reason_source`", and §3.b.5(v) states the rule as *"A UI that renders a reason without knowing whether it is canonical free text or the projector's constant `"other"` is the exact defect S-12 exists to prevent."* §4.5 calls `'legacy_enum'` "the UI's signal **not to present the value as authored text**". The register puts the source only in a `title=` tooltip (invisible on touch, never in print/screenshot, not announced by the accessible name); the detail-page lineage panel (`:219`) drops the field entirely.

**Failure scenario:** a legacy return row renders the cell `Other`, visually identical to a canonical row rendering `customer damaged the seal`. The accountant reading the monthly refund review records "Other" as the merchant's stated reason for a refund nobody ever authored a reason for.

### 4. **P2 — CONFIRMED — fiscal-pos** — legacy-return detail page contradicts the register that links to it
`ReceiptController.php:194-202` and `:673-680` vs `ReceiptDetailData.php:138`; totals: `ReceiptController.php:212` (list, signed) / `:662-671` (lineage, magnitude) / `RefundReceiptListPage.tsx:180` (client-side magnitude) / `ReceiptDetailPage.tsx:180` (signed)

The builder introduced an unspecced normalisation — a pre-fiscal return (`receipt_type='return'`, `fiscal_event_id IS NULL`, `invoice_type_code='SALE'`) is projected onto the wire as `REFUND` on the **list row** and in the **lineage** block, but the detail root emits the raw column (`ReceiptDetailData.php:138`). Magnitude handling is likewise split three ways: server-side magnitude for lineage, raw signed for the list row with the flip done in the browser, raw signed on detail.

**Failure scenario:** on `/pos/receipts/refunds` a legacy return shows badge **REFUND**, amount **5.250 TND**. Clicking the row lands on `/pos/receipts/:id`, whose header badge reads **SALE** (`ReceiptDetailPage.tsx:167-169` selects `tone: 'success'` for `SALE`) and whose total reads **-5.250 TND**. Two fiscal registers disagree about the type and the sign of the same sealed receipt. The handback declares the list-side projection (line 33) but not the detail-side divergence.

### 5. **P2 — PLAUSIBLE — general** — the refunds register hides rows that exist
`RefundReceiptListPage.tsx:195, 231, 258`

The table and pagination render only when `terminals.some(t => t.v4_refund_authoring_acknowledged_at !== null)`. `v4_refund_authoring_enabled` ships `default(false)`, but **legacy pre-v4 returns and VOIDs are independent of it** — the index query deliberately unions them in (`ReceiptController.php:154-161`). The query still fires; the rows are fetched and discarded. §3.c.2's "ships dark until refund-enable" is a defensible reading, which is why this is PLAUSIBLE rather than CONFIRMED, but the page is titled *Refunds & voids*, and no test covers "rows exist while no terminal is acknowledged".

**Failure scenario:** first-tenant go-live with migrated history containing 40 legacy returns and 3 voids, all terminals at `enabled = false`. The accountant opens `/pos/receipts/refunds`, sees three "Refunds are not enabled on POS-n" banners and no table at all — the 43 existing rows are unreachable from the only register that lists them.

### 6. **P3 — CONFIRMED** — list-row allowlist emits `receipt_type`
`ReceiptController.php:203`; `ReceiptListItemData.php`. §3.b.5(i) is exhaustive ("a field not named here is not emitted") and does not name `receipt_type`. Declared in the handback (line 33) as deliberate legacy-caller compatibility and accepted at M1; recorded here for the terminal audit only.

### 7. **P3 — CONFIRMED** — FT-13's "must fail against the pre-build routing table" is not achievable by the test as written
`ProvenanceSection.test.tsx:22-34`, `LedgerHistoryTable.test.tsx:64-76`. Both declare a **local stub** `<Route path="/pos/receipts/:id">` inside a `MemoryRouter`; they never consult `src/routes/index.tsx`, so they pass identically on the pre-build tree. Real coverage of CL-3 comes from `ReceiptPermissionParity.test.ts:53-70` (source-text scan) plus the environment-blocked Playwright flow 2.

### 8. **P3 — CONFIRMED** — detail-page emphasis deviates from §3(b)
`ReceiptDetailPage.tsx:126-149`. Spec: "receipt number + **total** as the `PageHeader` title/subtitle pair. Nothing else competes" — the subtitle carries date+terminal and the total sits fifth in a 5-column grid. Also `:166-169` renders a type badge on **SALE** rows; §3(b) item 1 says "type badge (**only when ≠ SALE**)".

### 9. **P3 — CONFIRMED** — N+1 in the show() lineage enrichment
`RefundReportingEnricher.php:81` reads `$receipt->originalReceipt?->receipt_number`, but `show()` eager-loads `returnReceipts` with `->with('lines')` only (`ReceiptController.php:589-592`). One lazy query per return receipt. No scope leak (the lazily-resolved original is the receipt being viewed), but BT-17's "one bulk load per page" spirit is broken on the detail path.

### 10. **P3 — CONFIRMED** — "—" placeholders spec'd, `Not available` shipped
§5 item 5 and §4.5 require "—" for the legacy/null refund cases; the code renders `common:notAvailable` ("Not available"/"Non disponible") at `RefundReceiptListPage.tsx:165,171,174` and `ReceiptDetailPage.tsx:219`.

### 11. **P3 — CONFIRMED** — generated contract introduces `any`
`packages/shared/types/generated.d.ts:1531` — `refund_policy_alerts: Array<any>` (from `list<array<string, mixed>>`). The consuming component correctly narrows via `readonly unknown[]` + `isRecord` guards (`RefundReceiptListPage.tsx:34-80`), so no unsafe access ships; the typed alert DTO is the durable fix.

### 12. **P3 — CONFIRMED** — warning-log noise on every register page load
`RefundReportingEnricher.php:69-77` catches and logs for **any** non-`SALE_RECEIPT` fiscal event, which includes every VOID/legacy row that carries a differently-typed event. Each page render emits one WARNING per such row; the degradation is correct, the log volume is not.

### 13. **P3 — CONFIRMED** — a fiscal `receipt_type='return'` row with `invoice_type_code='SALE'` would carry refund keys onto screen (a)
`RefundReportingEnricher.php:52-55` (`isRefundLike` ORs on `receipt_type === Return`) vs `ReceiptController.php:146-151` (the `notLegacyReturn` clause admits such a row into a SALE query when `fiscal_event_id IS NOT NULL`). §3.b.5(i): on SALE/TRAINING rows the five keys are "omitted entirely". Low reachability at launch.

### 14. **P3 — note for the terminal audit** — global relation change carried in from M1
`apps/api/app/Modules/POS/Domain/Receipt.php:311` — `terminal()` now `->withTrashed()`. Correct for the historical register, but it changes **every** consumer of `$receipt->terminal` repo-wide, not just this lane.

### 15. **P3 — CONFIRMED** — red-first evidence is narrative, not demonstrated
Brief §8.2 requires "the failing-test-first evidence (test file + **what its failure looked like** before the fix)". M2 commits bundle test + implementation (`ccbeb1d83`, `6db7701de`), and the wave-2 handback gives prose ("were red first on the absent shape…") plus pass counts, never a pasted red run. Same pattern M1 shipped under.

---

## Bypasses attempted that FAILED (i.e. the implementation held)

| Attack | Result |
|---|---|
| Cross-receipt aggregate anywhere in the diff (Addendum A(c) rule 2 / OI-3) | `git diff -U0 … \| grep -iE '\bsum\(\|->sum\(\|\.reduce\('` → **zero** additions. Clean. |
| `parseFloat` / `Number(...)` / `any` on money or quantity in new FE code | none in `ReceiptDetailPage`, `RefundReceiptListPage`, `ReceiptListPage`, `receiptApi.ts`. Magnitude is a string slice (`RefundReceiptListPage.tsx:31`), not arithmetic. Rule 19 holds. |
| Line-arithmetic assertion (`line_subtotal == unit_price × qty − discount`) | no `bcmul`/`*` on `unit_price` anywhere in the range. BT-14 asserts only the rounding-aware aggregate + the frozen VAT wire names. Clean. |
| `canonical_bytes` leak at depth | `show()` no longer touches `toArray()`; every payload is an explicitly-constructed DTO. `ReceiptResourceNoCanonicalBytesRecursiveTest` walks the whole tree with the correct `assertNotContains(needle, haystack)` argument order and a 3-deep lineage fixture. Clean. |
| Audit row written before the location check (BT-13) | `findOrFail` after `applyAllowedLocationScope` precedes `recordPrint` in **both** `downloadPdf:756-770` and `streamPdf:790-804`; proven by `ReceiptLocationScopeAuthorizationTest:19-25`. Clean. |
| 500 on a `pending_seal` receipt via non-nullable DTO params (`fiscal_hash`, `chain_sequence`, `receipt_year`) | columns are NOT NULL in `2026_01_08_190637_create_pos_receipts_table.php:38-42`. No crash path. |
| `app()` in new backend production code | none; `RefundReportingEnricher`, `LocationContext`, `CurrencyScaleResolverInterface` all constructor-injected (`ReceiptController.php:56-67`). |
| OP-23 FE gate keys drifting from the backend | `permission="fraud-settings.view"` / `"fraud-alerts.view"` match `Compliance/Presentation/routes.php:24,37` exactly. Clean. |
| EN/FR key drift | zero orphans in `pos.json` and `common.json`; only `receiptReporting.detail.subtitle` shares a value (interpolation template). Clean. |
| Company-default currency leaking into a render | every money render passes `{ currency: receipt.currency }` / `{ currency: row.currency }`; `ReceiptShowResourceTest` asserts `cash_rounding_denomination === '0.050'` (TND scale 3) on an **EUR** company — a genuine differing-currency lock. Clean. |
| New named queues without Horizon coverage / non-additive migration | no queues added; the only migration is the two additive `CREATE INDEX IF NOT EXISTS` from M1. Clean. |

**Not executed by me:** backend PHPUnit and vitest were not re-run (no live-DB env here, and the full-suite prohibition). Every finding above is derived from source, cited by `file:line`. The handback's own verification section already reports preflight **BLOCKED** (repo-wide Pint drift), scoped vitest **PARTIAL** (3 out-of-lane failures), and Playwright + all wave-2 screenshots **ENVIRONMENT BLOCKED** — so §7.4's screenshot and live-flow evidence for screens (b)/(c) is outstanding independently of the findings above.

**Blocking set:** finding 1 (P1) must close; findings 2–5 (P2) close before merge.

VERDICT: CHANGES-REQUIRED

---

## Builder disposition

Implemented in `be6cebe16` (`Phase 1.2.12: Repair receipt reporting review findings`).

1. **Closed.** Receipt-chain verification now resolves the authenticated user's effective location scope and applies it to the terminal lookup before any chain data is read. The red test received HTTP 200 before the fix; it now receives non-enumerating HTTP 404, while an unrestricted membership still receives HTTP 200.
2. **Closed.** `ReceiptShowResourceTest` now creates a product whose unit has two decimal places, mutates the live product SKU/name after the receipt line snapshot exists, and proves `quantity_decimals = 2`, `quantity = 1.25`, and the unchanged historical code/name.
3. **Closed.** Canonical and legacy reason provenance is visible text in both the refunds register and detail lineage. Legacy values are explicitly labelled “Legacy classification — not authored text” (with the corresponding French translation), rather than relying on a hover-only title.
4. **Closed.** The detail DTO projects pre-fiscal return rows to `REFUND` and formats their total as a magnitude, matching the list and lineage views. The red contract returned `SALE`; it now returns `REFUND` and `5.250`.
5. **Closed.** Existing historical refund/void rows render even when no terminal can currently author a refund. With no rows, the capability-only dark state remains and does not pretend that an empty table proves no refunds exist.
6. **Recorded, unchanged.** The M1-compatible `receipt_type` field remains the previously accepted legacy-axis deviation.
7. **Recorded, unchanged.** Route-source parity plus the live Playwright flow remain the integration proof; the live flow is still environment-blocked by the unavailable API.
8. **Closed.** The page header now pairs receipt number with the row-currency total, moves date/terminal/location/cashier into metadata, omits the SALE badge, and keeps non-SALE badges.
9. **Closed.** Return lineage now eager-loads `originalReceipt`, removing the per-return lazy query in enrichment.
10. **Closed.** Null/legacy refund fields use the specified translated em-dash placeholder.
11. **Recorded, unchanged.** Alert payloads remain narrowed from `unknown` before access; a typed alert DTO is a non-blocking follow-up.
12. **Closed.** Non-`SALE_RECEIPT` fiscal events take the intentional legacy fallback without invoking the canonical reader or emitting degradation warnings.
13. **Closed.** Refund enrichment now admits only REFUND/VOID rows plus the exact pre-fiscal legacy-return predicate; fiscal SALE rows do not gain refund-only keys.
14. **Recorded, unchanged.** The historical terminal relation behavior remains the M1-reviewed choice.
15. **Closed for this repair.** Red output was captured before implementation: verify-chain returned 200 instead of 404; legacy detail returned SALE instead of REFUND; both UI suites could not find visible provenance/history; the non-sale event emitted one warning; and the SALE row contained `refund_reason`. The focused green rerun completed 13 backend tests/80 assertions and 11 frontend tests.
