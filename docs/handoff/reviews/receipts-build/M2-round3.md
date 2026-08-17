## M2 adversarial merge-gate register — round 3

**Range:** `7d85232cc54abd6a6b2135f476205ab434e71a66..afb88618a` (M2 = `610a2d6f6`…`e15d3e6a9`; `afb88618a` records the round-2 register).
**Criteria:** brief §5 wave-2 row + SPEC r5 §3(b)/§3(c), §3.b.1–§3.b.5, §4.1 S-4/S-5/S-12, §4.4 S-11, §4.5, §6 CL-3/CL-4, §7.1 BT-6/7/12/13/14/15/17, §7.2 FT-4…FT-8/FT-12/FT-13.
**Lenses:** fiscal-pos, frontend-conventions, treasury, tenancy-authz, general — all applied, all applicable.

**Round-2 blocking set re-verified in code, not prose.** Finding 1 (half-projected legacy return) is **closed**: `ReceiptDetailData.php:87-99` now routes every signed document component through `$documentMoney`/`$documentQuantity` — header `subtotal`/`tax_amount`/`discount_amount`/`cash_rounding_adjustment`/`total` (`:165-171`), line `quantity`/`discount_amount`/`vat_amount`/`line_total` (`:115-121`) and VAT `net`/`vat`/`gross` (`:132-134`) — while leaving rates, `unit_price`, `cash_rounding_denomination` and `change_due` unflipped. Uniform negation preserves `total = subtotal + tax − discount + COALESCE(rounding,0)`, and I re-derived it against the real writer: `ReceiptReturnService::computeReturnTotals()` stores `headerSubtotal = net + discount` with `discount` **positive** and `net`/`tax`/`total` negative (`:944-967,1030-1041`), so the flipped payload is internally coherent and `ReceiptAggregateIntegrityTest.php:72-124` asserts the identity on the emitted shape (not just frozen constants). Findings 2 (`ReceiptController.php:603-604` filters lineage children to enriched ids), 3 (`ReceiptPdfPrintAuditTest.php:37-42` now asserts `terminal_id` + actor `user_id` on both rows), 4 (browser sign-strip removed; `RefundReceiptListPage.tsx:184` renders the server value) and 7 (`receiptTotalMagnitude()` at `ReceiptController.php:666-675` uses the resolved currency scale) are closed. Finding 5 carried.

---

### 1. **P2 — CONFIRMED — frontend-conventions / fiscal-pos** — the Payments section renders 3 of the 6 fields §3(b) freezes, and the omission is undeclared
`apps/web/src/features/pos/pages/ReceiptDetailPage/ReceiptDetailPage.tsx:116-120`

SPEC §3(b) section 4 is an enumeration, not a suggestion: *"**Payments** — `payments`: method, amount, `card_last_four`, `instrument_serial`, `transaction_reference`, `authorization_code`; plus `change_due`."* The page defines exactly three columns — `payment_method`, `transaction_reference`, `amount`. `card_last_four`, `instrument_serial` and `authorization_code` are never rendered anywhere in the file, and there are no i18n keys for them (`locales/{en,fr}/pos.json` carry `receiptReporting.detail.{paymentMethod,reference,amount,changeDue}` only — full key dump run, EN/FR parity clean).

This is not a wire gap: all three fields are emitted (`ReceiptDetailPaymentData`, key-set locked at `ReceiptShowResourceTest.php:91-94`) — they were allowlisted precisely so this section could render them. The handback's "Screen (b), FT-4/5/7 — DONE" bullet claims the page "renders header/summary, lines, VAT, payments…" and the "Decisions, deviations" section names no payments deviation — a silent deviation under brief §8.8.

**Failure scenario:** a customer disputes a card charge on receipt `TN-POS-0042`. The accountant opens `/pos/receipts/<id>` to pull the authorization code and last-four to answer the acquirer's chargeback request — the exact reason those two columns are on the fiscal payload — and the Payments table shows `Cash-of-record label · — · 45.000` and nothing else. The data is one HTTP response away and on screen nowhere. Cheap to close: three columns + three EN/FR key pairs, `card_last_four`/`instrument_serial`/`authorization_code` already typed `string | null` on `ReceiptDetail['payments'][number]`.

### 2. **P3 — CONFIRMED — fiscal-pos** — the refund-lineage panel drops the date §3.b.2 requires
`ReceiptDetailPage.tsx:211-231` vs `ReceiptShowRefundLineageTest.php:27-30`

§3.b.2: *"Render as a small linked panel: refund receipt #, **date**, type badge (REFUND/VOID), `refund_reason` …, `refund_destination`, amount magnitude."* The panel renders number, reason, reason-source, badge and total. `posted_at` is on the lineage allowlist and asserted present in the payload — it is simply never rendered. The reciprocal `original_receipt` block (`:199-208`) renders only the receipt number, also dropping its `posted_at`. Low harm (each row links to a dated detail page), but it is a frozen-section omission of the same class as finding 1.

### 3. **P3 — CONFIRMED — fiscal-pos** — S-4 reads a second live-master-data field and lets it change the emitted quantity digits
`apps/api/app/Modules/POS/Application/DTOs/ReceiptDetailData.php:107,115,122`

The brief's OI-17 ruling authorises **one** current-product enrichment and scopes it exactly: *"widen the eager-load … read **`decimal_places` only**, fall back to **4**"*. The implementation also reads `$unit->rounding_method->value` and passes it into `QuantityScale::formatForUnit()`, which **rounds** the historical value server-side (`QuantityScale.php:70-74` → `round()` → `bcCeil`/`bcFloor`/`bcRoundHalfUp`). The named precedent does neither: `ShiftController.php:305-334` emits the raw scale-4 quantity, attaches `quantity_decimals`, unsets the relation, and leaves formatting to the client.

**Failure scenario:** a unit configured `decimal_places = 0, rounding_method = ceil` (a legal `units` row). A sealed line of `1.2500` is emitted as `quantity = "2"` on a fiscal register; an admin later switching that unit to `floor` changes the same sealed receipt to `"1"`. Display-only and `formatForUnit` is the house-sanctioned backend formatter (CLAUDE.md rule 19), which is why this is P3 and not higher — but it is one field wider than the ruling authorised and is undeclared in §8.6/§8.8. `half_up` (the helper's own default via `?? self::HALF_UP`) is the conservative choice.

### 4. **P3 — CONFIRMED — general / tenancy-authz** — `returned_quantity` is silently under-reported once the lineage relation is location-scoped
`ReceiptController.php:591-594` (scoped `returnReceipts`) feeding `calculateReturnedQuantities()` `:707-743`

S-11 correctly scopes the lineage relation, and `ReceiptShowRefundLineageTest.php:47-69` proves out-of-scope children vanish from `return_receipts[]`. But the same scoped collection is the sole input to the per-line returned tally, so the derived figure narrows with it — and unlike the lineage panel (which is visibly empty), the line table renders an affirmative `0.0000`.

**Failure scenario:** two-location company; a sale rung at A is refunded against a receipt row at B. A manager restricted to A opens the original and reads "Returned quantity **0.0000**" on a line that has been fully returned. Reachability is low (refunds are authored against the original's terminal) and this surface is read-only reporting — `show()` has no `apps/web` write consumer and the device does not read it — so no authoring decision is driven off it today. Worth a caption or a ticket, not a rewrite.

### 5. **P3 — CONFIRMED — general** — `show()` eager-loads three relations it never serialises, and the one that would improve the payload is unused
`ReceiptController.php:580-587` loads `'company'`, `'cashier'` and `'payments.paymentMethod'`. The DTO reads `cashier_name` from the column (`ReceiptDetailData.php:163`) and `payment_method` from the raw stored `$payment->payment_type` (`:139`) — so the eager-loaded `paymentMethod` relation, which carries the tenant's configured method name, is loaded and discarded while the wire ships a device-authored string that reaches the user untranslated (`ReceiptDetailPage.tsx:117`).

### 6. **P3 — CONFIRMED — general** — money formatter used on a quantity
`ReceiptDetailData.php:96` — `CurrencyScale::bcformatStrict($value, QuantityScale::SCALE)` inside `$documentQuantity`. Numerically inert (`bcformatStrict` is a numeric guard + `bcadd($v,'0',$scale)`, `CurrencyScale.php:130-145`, and the value is re-processed by `formatForUnit` two lines later), and `Application/DTOs` is outside the scanned paths of `ForbidHardcodedBcmathScale` / the quantity presentation rules — which is exactly why the convention (rule 19: `QuantityScale` for quantities) is the only thing guarding it here.

### 7. **P3 — CARRIED, re-verified as still present**
- **CL-4 / FT-13 cannot fail against the pre-build routing table.** `ProvenanceSection.test.tsx:26-33` and `LedgerHistoryTable.test.tsx` register a **local** `<Route path="/pos/receipts/:id">` inside their own `MemoryRouter`; neither imports `src/routes/index.tsx`. FT-13's "must fail against the pre-build routing table" is structurally unmet, and the wave-2 handback still asserts *"they failed against the pre-wave routing table"* — that claim is not supportable by these files. Dispositioned R1 #7 / R2 #5; the inaccurate evidence sentence should be corrected rather than the test re-argued.
- `receipt_type` on the list allowlist (`ReceiptController.php:203`); `refund_policy_alerts: Array<any>` (`packages/shared/types/generated.d.ts:1531`, narrowed at every use); `Receipt::terminal()->withTrashed()` (`Receipt.php:311`) changing every repo-wide consumer; the split sign convention for a hypothetical negative-total `REFUND`/`VOID` (server predicate covers only the pre-fiscal shape — `RefundReceiptListPage.test.tsx:182-195` freezes the "stay signed" choice deliberately); red-first evidence still narrative.

### 8. **Evidence outstanding for M3 (declared, not a finding)**
Exact preflight **BLOCKED** (repo-wide Pint drift), scoped vitest **PARTIAL** (3 out-of-lane baseline failures), scoped PHPUnit **PARTIAL** (21 out-of-lane errors), the four Playwright flows and **every** wave-2 screenshot (detail six sections, three capability banners, reprint dialog) **ENVIRONMENT BLOCKED** on the absent API at `127.0.0.1:8010`. §7.4's live evidence for screens (b)/(c) remains unmet; the handback reports this honestly and does not call it green.

---

## Bypasses attempted that FAILED (the implementation held)

| Attack | Result |
|---|---|
| Break the aggregate identity on a legacy return with a non-zero discount | Uniform negation is homogeneous in `S + T − D + R = Tot`; verified against the real writer (`ReceiptReturnService.php:1030-1041`, `headerSubtotal = net + discount`, discount positive). `ReceiptAggregateIntegrityTest.php:118-123` re-derives it from the emitted payload. Held. |
| Find a legacy return the `isLegacyReturn` predicate misses (a return carrying a `fiscal_event_id`) | `ReceiptReturnService` writes neither `fiscal_event_id` nor `invoice_type_code` (grep over the file: zero hits); the fiscal projector only produces `receipt_type='return'` via `resolveReceiptType()` for `REFUND`/`VOID` (`PosCoreReceiptProjection.php:321,386`). The two eras are disjoint under the predicate. Held. |
| Payments contradicting the flipped total on a legacy return | Return receipts carry **no** payment rows (`ReceiptReturnService.php:708`), so the unflipped `$money` on `payments[]`/`change_due` has no legacy-return exposure. Held. |
| Lineage 500 via the round-2 filter (`$returnReporting[$id]` on a skipped child) | `ReceiptController.php:603-604` filters before dereferencing; `RefundReportingFieldsTest.php:137-158` calls the original's detail on exactly that shape and asserts HTTP 200 + empty lineage. Held. |
| Cross-company / out-of-scope terminal enumeration on verify-chain after the 403→404 rewrite | `ReportController.php:427-441` re-scopes on `company_id` + `whereIn(location_id)`; cross-company is already caught upstream as **422** by `ScopedExists` (`ReceiptChainVerificationTest.php:150-158`, `PosStabilizationTenantIsolationTest.php:509-518`), so removing the dead 403 branch breaks no existing contract. Out-of-scope is 404 (`ReceiptLocationScopeAuthorizationTest.php:51-66`). Held. |
| Audit row written before the location/permission check | `applyAllowedLocationScope` + `findOrFail` precede `recordPrint()` in both PDF actions (`:760-774`, `:794-808`); `ReceiptPdfPrintAuditTest.php:47-66` and `ReceiptLocationScopeAuthorizationTest.php:21-25` assert `pos_receipt_prints` count 0 on 403/404/scope-denial. Held. |
| `canonical_bytes` / `vat_breakdown_hash` / `payment_methods_hash` / `tenant_id` at any depth | `ReceiptResourceNoCanonicalBytesRecursiveTest` walks a 3-deep tree with both lineage directions populated and forbidden columns force-filled. Held. |
| Cross-receipt aggregate anywhere in the branch (Addendum A(c) rule 2 / OI-3) | `git diff -U0 7d85232cc..HEAD -- apps/api/app apps/web/src \| grep '^+' \| grep -inE 'sum\(\|->sum\(\|\.reduce\(\|array_sum'` → **zero**. |
| `parseFloat` / `Number(...)` / `(float)` / `number_format` / `toFixed` / `app()` / `: any` in new production code | same diff, `grep -nE "parseFloat\|Number\(\|\bany\b\|[^_]app\(\|\(float\)\|toFixed\|number_format"` → one hit, an English word in a test name (`ComplianceRoutePermissions.test.tsx`). |
| Company-default currency leaking into a render | every money render passes `{ currency: receipt.currency }` / `{ currency: row.currency }` (`ReceiptDetailPage.tsx:87,99,111-119,133,173-177,193,228`; `RefundReceiptListPage.tsx:61,184`); `ReceiptShowResourceTest.php:107` locks TND scale-3 output on a non-TND company. Held. |
| EN/FR drift or a missing dynamic key (`receipts.types.*`, `refunds.destinations.*`, `reasonSources.*`) | programmatic flatten + set-diff over `{en,fr}/pos.json`: 0 missing either way; every dynamic key path used by (b)/(c) resolves in both locales. Held. |
| New named queue, non-additive migration, or `apps/pos/**` change in M2 | `git diff --name-only` over `database/migrations`, `config`, `apps/pos` → one additive `CREATE INDEX IF NOT EXISTS` migration from M1, no config change, zero device paths. Held. |
| Location relation repeating M1's archived-terminal 500 | `Location` does not use `SoftDeletes` and `location_id` is `NOT NULL`, so `$receipt->location->name` has no null path. No finding. |

**Not executed by me:** PHPUnit and vitest were not re-run (no live-DB env in this worktree, full-suite prohibition). Every finding is derived from source and cited by `file:line`.

**Blocking set:** finding 1 (P2) — a frozen §3(b) section rendered at half its specified content, undeclared. Findings 2–7 are P3 and may ship with recorded tickets; finding 2 is worth closing in the same edit as finding 1.

VERDICT: CHANGES-REQUIRED

---

## Builder disposition

Implemented in `59cfb57cb` (`Phase 1.2.16: Complete receipt detail evidence`).

1. **Closed.** The Payments table now renders method, card last four, instrument serial, transaction reference, authorization code, and amount, with three new EN/FR labels. The fixture carries non-null values for every previously omitted field and asserts their column headers and visible values.
2. **Closed.** Both the original→refund row and refund→original panel now render `posted_at` in the active company timezone; the component test locks both dates next to their links.
3. **Closed.** Receipt detail reads only `unit.decimal_places`. It no longer reads `rounding_method`; stable half-up presentation is used. The red regression configured `ceil` on a two-decimal unit and received `1.26` for sealed `1.2510`; it now receives `1.25` while preserving `quantity_decimals = 2` and the historical product snapshot.
4. **Recorded for terminal audit.** The cross-location returned-quantity caveat is low-reachability and read-only; changing its semantics would require a new scoped aggregate contract. No false “complete” claim is made for that cross-location shape.
5. **Closed.** Unused `company`, `cashier`, and `payments.paymentMethod` eager loads were removed. Payment reporting deliberately continues to use the stored receipt-payment snapshot rather than mutable payment-method master data.
6. **Closed.** Quantity normalization now validates and formats through `QuantityScale`; the money formatter is no longer used on quantities.
7. **Corrected.** The handback now distinguishes component click/navigation coverage from the route-source parity test. It no longer claims the local `MemoryRouter` tests fail against the pre-build route table.

Captured red output before the fix:

```text
ReceiptShowResourceTest: Failed asserting that '1.26' is identical to '1.25'.
ReceiptDetailPage.test.tsx: Unable to find columnheader "Card last four".
ReceiptDetailPage.test.tsx: Unable to find an element with the text /08\/18\/2026/.
```

Focused green evidence: 26 backend tests / 221 assertions and 12 frontend tests; touched PHPStan, Pint, changed-file ESLint, TypeScript, and receipt-reporting EN/FR key parity pass.
