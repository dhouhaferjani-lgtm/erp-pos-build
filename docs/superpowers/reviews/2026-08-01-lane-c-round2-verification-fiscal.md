# Lane C round-2 — FISCAL scoped verification (dual gate: pos fix-round-2 A–G + api wave-4 & extension)

**Range reviewed:** `6cb629d96..ce06b4005` (5 commits). Worktree
`/Users/houssamr/Projects/syneriva/apps/erp.refund-chain`, branch `feat/v3-refund-chain`,
read-only. Nothing outside the range re-reviewed.

**Contracts:** `docs/sessions/LANE-C-fixwave-rereview-fiscal.md` (items A–G) +
`docs/sessions/LANE-C-wave4-report.md` (W4-1..6 + E1/E2/E3).

**Executed during this pass (not just read):**
- `npx tsc --noEmit -p apps/pos/tsconfig.json` → **clean**.
- `npx vitest run RefundReceiptV4Payload.parity.test.ts` → **1/1 pass** — the golden
  `sale-receipt-v4-refund-golden.json` canonical string AND sha-256 still match. The fixture file
  is **not in the diff** (`git diff --stat` carries no `tests/Fixtures/Fiscal/**`).
- `npx vitest run refundCheckoutStore.reuseOrdering + refundCheckoutStore + refundReportingEndToEnd
  + resolveOriginalFiscalEventLocally.realAuthoring` → **89/89 pass**.
- PHP tests / scoped PHPStan NOT re-run here (no live DB in this pass); the wave-4 report's green
  evidence is taken as claimed, not verified.

---

## Verdict table

| # | Item | Verdict | Evidence (file:line, read) |
|---|---|---|---|
| **A** | One quantity scale (`REFUND_QUANTITY_SCALE=3`), signed bytes unchanged | **RESOLVED** | `RefundReceiptV4Payload.ts:285-286` exports the constant; **all four** entries now consume it — boundary `refundCheckoutStore.ts:135` + `:334` (`bcabs(String(item.quantity), 3)`), cap `:937/:939/:1035`, snapshot/cap repo `refundIntentRepository.ts:27` + `:530`, row mirror `refundReceiptService.ts:40` + `:97` (`bcmul(line.quantity,'-1',3)`), reference array `RefundReceiptV4Payload.ts:469` with the byte-equality assert at `:470`. No scale-4 quantity path remains in the refund graph (`grep REFUND_QUANTITY_SCALE\|QUANTITY_SCALE apps/pos/src` — the only other `QUANTITY_SCALE=4` is `cartStore.ts:15`, a tax-ratio intermediate, not a refund quantity). **Signed bytes proven unchanged by running the golden parity test** (`'1.0000'`→`'1.000'` is a no-op through `bcformat(...,3)`). New end-to-end pin on REAL bytes at `refundReportingEndToEnd.test.ts:300-317` (`signedRefs[0].quantity === signedLines[0].quantity`, mirror `=== '-' + signed`). |
| **B** | Fail-closed `ApprovalIdentityUnreadableError`, no re-minted identity | **RESOLVED** | `posOverrideAuthoring.ts:104-107` (typed error), `:238-241` — `readApprovalIdFromCanonicalBytes(...)` returning `null` now THROWS instead of falling back to `candidateApprovalId`. Throw is inside `withWriteTransaction('fiscal', …)` (`:165`) so the GRANTED append rolls back cleanly; chain head lives in the DB (`FiscalEventEngine.ts:1012` updates `fiscal_event_last_hash` inside the same tx), so rollback leaves no in-memory chain desync. Tests: `posOverrideAuthoring.test.ts` (+56 lines in range). |
| **C** | Reuse resolved BEFORE the cap + full linkage verification + real-repo ordering test | **RESOLVED** | Ordering: `refundCheckoutStore.ts:869-896` runs `computeLineSnapshotFingerprint` + `findActiveRefundIntent` + `resumeReusedIntent` BEFORE the cumulative cap (`:912-948`), the legacy value bound (`:971`) and the discount refusal (`:1026`). The pre-lookup uses the identical key `createOrReuseActiveRefundIntent` scopes (`refundIntentRepository.ts:154-155`), so the row found is the row reused. Linkage (`:1243-1269`): event must EXIST, `source_event_class='refund_intents'`, `source_event_id===intent.id`, receipt keyed by `idempotency_key===intent.id` AND `canonical_bytes` byte-equal to the signed event's. Real-repository test `refundCheckoutStore.reuseOrdering.test.ts` — un-mocks the cap, runs real SQLite + real migrations + real `refundIntentRepository`/`getFiscalEventById`; 4 cases incl. dangling-event and byte-mismatch, plus a non-vacuous "the cap still refuses a genuine over-refund". |
| **D** | Exactly-one-row on ALL THREE ACK flips | **RESOLVED** | `fiscalEventRepository.ts:122-141` — `updateFiscalEventSyncStatus` now returns `rowsAffected`; `syncService.ts:375-380` asserts `=== 1` and throws, alongside the receipt assert `:382-387` and the re-read-confirmed intent transition `:389-402`, all inside one `withWriteTransaction('fiscal')` (`:368`). Duplicate ACK stays benign: both UPDATEs are unguarded on status so `rowsAffected` is 1 (`offlineReceiptRepository.ts:256-269`). |
| **E** | Ceiling = signed byte-bound original, EXACT total (`total − cash_rounding_adjustment`), typed errors | **RESOLVED** | See the arithmetic walk below. `fiscalEventRepository.ts:196-199` surfaces `total`/`cashRoundingAdjustment` from the **signed payload** (`:390-391`), both runtime-validated (`FiscalEventEngine.ts:1618-1638` — `assertMoneyString` on `total`, `signedMoneyRegex` + negative-zero rejection on `cash_rounding_adjustment`), reachable only after `validateSaleReceiptPayload` (`:328`) which for `eventVersion<4` asserts the **30-key V3 set** (`FiscalEventEngine.ts:1540-1544`) — so `cash_rounding_adjustment` can never be `undefined` here. Bound: `refundCheckoutStore.ts:1109-1112` (`bcabs(bcsub(total, adjustment, scale), scale)`), typed `RefundValueBoundExceededError` `:1136` / `RefundValueBoundUnreadableError` `:1156`, copy split at `:978-996`. Tests `refundCheckoutStore.test.ts:1396-1432` (rounded-DOWN full refund ALLOWED; genuine over-value still refused). |
| **F** | Envelope `event_type`/`event_version`/`terminal_id`/`sequence_number` cross-checked to the row | **RESOLVED** | `fiscalEventRepository.ts:260` selects `sequence_number`; `:370-381` compares all four against the row. Meaningful: `FiscalEventEngine.ts:619-636` puts exactly those four in `canonicalPayload`, and `:651-680` inserts the same values into the row columns. 4 new tests incl. a non-vacuous positive case (`resolveOriginalFiscalEventLocally.realAuthoring.test.ts:537-590`). |
| **G** | N-2, N-3, N-5 + §4.4 erratum extension | **RESOLVED** | N-2: `refundCheckoutStore.ts:1031` now `bcabs(discountAmount, getCurrencyDecimals(currencyForBound()))`. N-3: `XReportResource.php:40` emits `refunds_amount`; accessor exists and returns `string` (`Domain/XReport.php:137-140`). N-5: `refundReportingEndToEnd.test.ts:1489` now `bccomp(bcadd(...,2), total)`, no `parseFloat`. Erratum: `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md:412-427` — documents the receipt-level VALUE bound, the `total − cash_rounding_adjustment` derivation and the rounded-DOWN rationale (closes N-12). |
| **W4-1** | `getSalesSummary` net_sales/gross_sales/tax_total | **RESOLVED** | `PosAnalyticsService.php:36-38` via `netOfReturns()` `:429-432`. Safe arms `:39-41` byte-identical. |
| **W4-2** | `getSalesByTimePeriod` period total | **RESOLVED** | `:165`. |
| **W4-3** | `getCashierPerformance` total_sales / average_ticket | **RESOLVED** | `:194-195`. |
| **W4-4** | `getCustomerAnalytics` total_spent | **RESOLVED** | `:312`. |
| **W4-5** | `GrandtotalService::calculatePerpetualTotals` lifetime_sales/lifetime_tax | **RESOLVED** | `GrandtotalService.php:225-234`. Legacy-era byte-identical (`-ABS(negative) == negative`); only v4-era rows change. |
| **W4-6** | `buildExpectedPerMethod` payment SUM + change_due sub-query | **RESOLVED** | `ReportGenerationService.php:528` + `:541`. `ReceiptType` imported `:16`; enum values `'sale'/'return'` match the raw literals (`Domain/Enums/ReceiptType.php:8-9`). See NEW-3 note on reachability. |
| **W4ext-1** | `calculatePeriodTotals` magnitude netting, signed keys untouched | **RESOLVED — keys byte-identical** | `GrandtotalService.php:165-183`. Void branch (`refunds_count`/`refunds_amount`) is character-for-character the old code; `sales_count` still increments for returns; only `gross_sales`/`tax_amount` change, and for a legacy NEGATIVE return `bcsub(g, magnitude(-10))` ≡ the old `bcadd(g, -10)` — byte-identical. **No sealed value is altered**: `verifyChain()` recomputes each hash from the STORED `period_totals`/`perpetual_totals` arrays (`GrandtotalService.php:316-324`), never from a fresh query. |
| **W4ext-2** | `calculateShiftTotals` positive-magnitude refunds_amount + payment_methods netting, with the Z-CASH==expected-cash agreement test | **RESOLVED, with a disclosed live-path consequence** | `ReportGenerationService.php:994-1013`; agreement test `GenerateZReportWithCountsTest.php:602-643` asserts `bccomp(report_data.payment_methods[CASH].total_amount, ZReportCount.expected_amount) === 0` and `perpetual_grand_total = 83.0000`. Z hash also recomputes from stored `report_data` (`ZReportHashService.php:60`), so sealed Zs still verify. Legacy returns provably write no `pos_receipt_payments` rows (`ReceiptReturnService.php:699-700`), so the payment-leg arm is a genuine no-op on legacy data. **But the `refunds_amount` sign flip DOES change live v2-terminal output — see NEW-3.** |
| **W4ext-3** | `OwnerSalesSummaryService` `ABS(SUM)` → `SUM(ABS)` | **RESOLVED** | `OwnerSalesSummaryService.php:106`; mixed-era red test `OwnerSalesSummaryServiceTest.php:64-80` (100.00 vs the cancelling 0.00). |

**Score: 7/7 items A–G RESOLVED; 6/6 W4 sites RESOLVED; 3/3 W4 extension items RESOLVED. 0 NOT RESOLVED.**

---

## Priority (a) — item E arithmetic, walked concretely

**Sign convention, proved from the code, not from memory.** `SaleReceiptV3Payload.ts:120-137` is the
signing-time invariant:

```
subtotal + vat_total == (total − cash_rounding_adjustment) + transaction_discount_amount
```

⇒ `cash_rounding_adjustment = total − exact` = **rounded − exact**. `buildSaleReceiptV3Payload`
confirms it constructively: `:86` feeds `rounding.exactTotal` to the V2 aggregate assert, `:92`
writes `roundedTotal` into the signed `total`, `:93` writes `adjustment` alongside.

**Rounded-DOWN case (the N-1 failure).** Exact 12.31, 0.05 denomination → rounded 12.30,
adjustment `-0.01`. Signed payload: `total='12.30'`, `cash_rounding_adjustment='-0.01'`.
`refundCheckoutStore.ts:1109` computes `|bcsub('12.30','-0.01',2)| = 12.31` — **the exact total,
recovered exactly**, no residue. A full refund's `Σ|line_total| = 12.31`; `bccomp(12.31, 12.31) > 0`
is false ⇒ **allowed**. Pre-fix this compared 12.31 against the rounded 12.30 and refused every
first full refund of a rounded-down receipt.

**Rounded-UP case.** Exact 12.29 → rounded 12.30, adjustment `+0.01`; ceiling = `12.30 − 0.01 =
12.29`, and `Σ|line_total| = 12.29` ⇒ allowed, while a 12.30 claim (the amount actually tendered)
is refused. That asymmetry is the pre-existing over/under-refund-by-the-adjustment question already
ticketed in round 1; it is NOT introduced here.

**Discount interaction is closed structurally.** `total − adjustment = Σ line_total −
transaction_discount_amount`, so a discounted original would make the ceiling smaller than
`Σ|line_total|` — but an original with a non-zero `transaction_discount_amount` is refused outright
at lookup level (`RefundReceiptV4Payload.ts:313-318`, called from `refundCheckoutStore.ts:831`), so
the ceiling and the payout are the same quantity for every refundable original.

**`canonicalMoney` negative-zero collapse** (`SaleReceiptV3Payload.ts:70-74`) means a zero
adjustment is always the unsigned canonical zero, so `bcsub(total, '0.00')` is a strict no-op.

## Priority (b) — the 4th-decimal path is dead at EVERY entry

Verified by grep over `apps/pos/src` and by reading each site: boundary `:334`, cap `:937/:939/
:1035`, snapshot write (`createOrReuseActiveRefundIntent` stores `line.quantity` verbatim,
`refundIntentRepository.ts:173`) and snapshot read `:530`, row mirror `refundReceiptService.ts:97`,
signed reference `RefundReceiptV4Payload.ts:469`. All at 3. The `:470` byte-equality assert against
`v3Skeleton.line_items[i].quantity` makes the alignment provable rather than assumed. **Signed bytes
unchanged** — golden parity test executed and green.

## Priority (c) — wave-4 signed-surface claim

No sealed/signed historical value is altered. Both fiscal verifiers recompute from **stored**
payloads: `GrandtotalService::verifyChain` `:316-324` and `ZReportHashService::calculate…`
`:60`. The void-mislabel keys in `period_totals` (`refunds_count`/`refunds_amount` fed by
`is_voided`) are byte-identical to the pre-diff code and pinned by
`GrandtotalServiceTest.php:167-169` (`refunds_count = 0` over a 2-return fixture). `sales_count`
semantics preserved.

## Priority (d) — item C linkage escape analysis

Branch-by-branch on `resumeReusedIntent` (`refundCheckoutStore.ts:1223-1297`): the
`refund_event_appended` route can only return `refuse/refundAlreadyAppended + showReconciliation`
after event existence + provenance + receipt existence + **byte equality** all hold. The
`approval_authored` route refuses unless `recoverRefundApprovalEvidenceLocally` returns evidence
(itself fail-closed on any `approval_id`/scope/`policy_version`/supervisor/`approval_event_id`
disagreement). `drafted` signs nothing. The `synced` state is deliberately outside `ACTIVE_STATES`
(`refundIntentRepository.ts:42-46`), so a fully-synced original correctly falls through to the
cumulative cap — which counts `synced` rows (`:487`) and refuses with the now-accurate
`refundQuantityExceeded`. **One theoretical escape remains, Minor:** if BOTH `canonical_bytes`
values were `null`/`''` the `!==` comparison passes (see NEW-M3).

---

## New breakage introduced BY this diff

### Important

**NEW-1 — [Important] `apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:63` —
the payment breakdown in the SAME method as W4 site 1 is NOT receipt_type-aware, so post-enable it
ADDS refund payout legs, and `SalesSummaryData` is now internally self-contradictory.**

`getSalesSummary` builds `$payments` with `COALESCE(SUM(pos_receipt_payments.amount), 0) as total`
grouped by `payment_type`, filtered only on `pos_receipts.is_voided` (`:54-64`). A v4 refund
projects a **POSITIVE** `pos_receipt_payments.amount` row under a `receipt_type='return'` receipt —
confirmed at source: `PosCoreReceiptProjection::writePayment` writes `'amount' => $amount` verbatim
from the canonical payload (`PosCoreReceiptProjection.php:1364`), and the v4 payload's single cash
leg is the positive gross (`RefundReceiptV4Payload.ts:440` / `refundCheckoutStore.ts:1503`).
This is exactly the ticket's defect class on exactly the ticket's kind of aggregate, and it was in
neither the six nor the §4 safe list. The diff makes it worse in a specific way: `net_sales` in the
same DTO is now netted (`:37`) while `payment_breakdown` is still blended, so the endpoint's own two
money surfaces disagree by 2× the refund. Contrast `SalesReportService.php:179`, which guards the
identical query with `->where('pos_receipts.receipt_type', ReceiptType::Sale->value)` and is
correctly on the safe list. **Fix:** apply the same
`CASE WHEN pos_receipts.receipt_type = 'return' THEN -ABS(...) ELSE ... END` per row, or scope
sale-only like `SalesReportService`, with a mixed-era red test.

**NEW-2 — [Important] `PosAnalyticsService.php:104` and `:132-133` (and `:243/:257`) — the
line-level aggregates are not receipt_type-aware either, and v4 refund LINES project POSITIVE.**

`getSalesByCategory` sums `pos_receipt_lines.line_total` (`:104`) and `getSalesByProduct` sums
`line_total` **and `quantity`** (`:132-133`), joined to `pos_receipts` with only an `is_voided`
filter. `PosCoreReceiptProjection::writeLines` writes `'quantity' => $line->quantity`,
`'line_total' => $line->lineSubtotal`, `'tax_amount' => $line->lineVat` verbatim from the
positive-magnitude v4 payload (`PosCoreReceiptProjection.php:1170-1175`), whereas legacy returns
wrote them NEGATIVE (`ReceiptReturnService.php:934-975`). Post-enable, category/product revenue and
top-product **quantities** are off by 2× the refund, in the same direction and for the same reason
as the six fixed sites — while the headline `net_sales` on the same dashboard is now netted.
`getDiscountAnalysis`'s `discount_amount`/`quantity` sums (`:243`, `:257`) inherit the same
exposure. **These are hard-pre-enable-gate sites that the wave-4 scope missed**; they should either
be fixed with the same expression or explicitly ruled out with a written rationale before
`EnableV4RefundAuthoringCommand` runs.

**NEW-3 — [Important] `ReportGenerationService.php:996` — the `refunds_amount` sign flip changes
LIVE v2-terminal Z output, and turns `grand_totals.cumulative_refunds` into a mixed-sign
accumulator on any terminal with legacy return history.**

`calculateShiftTotals`/`buildExpectedPerMethod` are reachable ONLY at
`fiscal_schema_version < 3` (`ReportGenerationService.php:84-89`, pinned by
`ServerReportAuthoringUnreachabilityTest`), and v4 refunds require v3+. So the v4 arms of W4-6 and
W4ext-2(b) are defensive-only — harmless. **The `refunds_amount` magnitude change, however, lands
squarely on the live legacy path.** `computeGrandTotals` (`:900-910`) then writes
`cumulative_refunds = prior + shiftRefunds`: on a terminal whose prior Zs accumulated NEGATIVE
refunds, the next Z adds a POSITIVE magnitude on top, producing a value that is neither the old
convention nor the new one (e.g. `−50 + 12 = −38`). `perpetual_grand_total` likewise changes
semantics mid-chain with no backfill (past over-counts stay baked in). Nothing breaks and no sealed
row is touched — but this is a fiscal cumulative counter and the discontinuity is silent. **Needs
either an explicit ruling + release note, or a one-time correction of `cumulative_refunds`, before
this reaches a tenant that has legacy returns.** (Disclosed in the wave-4 report §E5.3; recorded
here as a gate item, not a surprise.)

### Minor

- **NEW-M1 — `refundCheckoutStore.ts:1181-1184`** — `currencyForBound()` resolves the **active
  company's** currency (with a silent `?? 'EUR'` fallback) as the scale for the value bound, where
  the pre-diff code used the ORIGINAL receipt's own currency (`getCurrencyDecimals(originalReceipt.currency)`).
  The original's SIGNED `currency_code` is available in the payload but is never surfaced on
  `OriginalFiscalEventLocalView` nor asserted equal to the refund's currency. Self-consistent with
  the settle path (`:1486` uses the same company currency), so no live defect — but a TND (scale 3)
  original with an unloaded company list would be bounded at EUR scale 2. Surface `currencyCode` on
  the view and assert it matches.
- **NEW-M2 — item E has no signed-source test.** `resolveOriginalFiscalEventLocally.realAuthoring.test.ts:570-590`
  (the new non-vacuous positive case) asserts only `businessDate`; nothing anywhere asserts that
  `view.total` / `view.cashRoundingAdjustment` come from the signed payload. The store tests mock
  the resolver entirely. A future edit could point those two fields at the mutable
  `offline_receipts` scalars — the exact thing item E exists to prevent — with every test still
  green. Add two assertions to that test.
- **NEW-M3 — `refundCheckoutStore.ts:1261`** — `linkedReceipt.canonical_bytes !== linkedEvent.canonical_bytes`
  passes when BOTH are `null`/`''`. Add a non-empty-string guard (the sibling check at
  `fiscalEventRepository.ts:276-281` already does exactly that for the original).
- **NEW-M4 — `refundIntentRepository.ts:477`** — `QUANTITY_STRING_PATTERN = /^\d+(\.\d+)?$/` accepts
  any number of decimals; a snapshot carrying 4 dp is silently TRUNCATED by `bcadd(..., 3)` at
  `:530` (an undercount of the already-refunded quantity — the permissive direction the ⚖️ Q-1
  ruling refuses elsewhere in the same function). Pin the pattern to `\d{1,3}` and refuse instead.
- **NEW-M5 — `PosAnalyticsService.php:36`** — `gross_sales` now applies `-ABS` to the header
  `subtotal`. For a legacy return, `subtotal` is `net + proportional line discount`
  (`ReceiptReturnService.php:1030`), i.e. NOT guaranteed negative when the discount exceeds the
  post-discount net. In that narrow case the value's sign flips versus the old blended SUM, so the
  report's "legacy results are unchanged" claim (§5.6) has an exception on `subtotal` specifically.
- **NEW-M6 — test hygiene** — `GrandtotalServiceTest.php:157-169` and `:270-273` use loose
  `assertEquals` on money strings (should be `assertSame`); `GenerateZReportWithCountsTest.php:632`
  passes a literal `4` as the bcmath scale.
- **NEW-M7 — `XReportResource.php:40`** has no test asserting the new key, and the server X path is
  retired for v3+ terminals, so the field is a type-honesty fix only — nothing exercises it.
- **NEW-M8 — `fiscalEventRepository.ts:127-131`** — a duplicate refund ACK re-writes
  `synced_at = datetime('now')` on an already-synced event (`rowsAffected` is 1 either way, which is
  what keeps item D's assert benign). Cosmetic, but the timestamp is no longer "first sync".
- Round-1 minors **N-6, N-7, N-8, N-10, N-11** are untouched in this range (carry-over ticket).

### Checked and clean (no finding)

- No new `onQueue(...)` anywhere in the range ⇒ no `apps/api/config/horizon.php` change owed.
- No `toISOString()` / Carbon string bound into a SQLite TEXT time comparison in the added lines.
- No float on money/quantity in production code added by this diff (`grep '^+' | grep -E
  'parseFloat|Number\(|\(float\)|number_format'` → the only hit is the N-5 comment describing the
  code it REMOVED). The one `Math.abs` (`RefundReceiptV4Payload.ts:399`) is the parked ruling and is
  still guarded by the `:470` assertion.
- No no-arg `getScale()` reachable from a queue/projection: `GrandtotalService::magnitude()`
  (`:46-51`) and `ReportGenerationService::magnitude()` (`:77-82`) both call `$this->scale()`, but
  `GrandtotalService` has exactly one production consumer — `ReportGenerationService` (`:55`) — and
  `ReportGenerationService` has exactly one — `ReportController` (`:45`). Request scope only.
- No projection/queue code touched; no `CompanyContext` binding introduced in tests.
- No Event class renamed/restructured/deleted; the refund is still `SALE_RECEIPT` +
  `invoice_type_code='REFUND'`; no parallel refund event type; no device-side stock decrement.
- No per-line TTC-vs-HT equality assertion added; the new fiscal identity test
  (`refundReportingEndToEnd.test.ts:1489`) is at the aggregate level.
- `apps/pos` typecheck clean; 89/89 targeted vitest green.

---

## VERDICT

**PASS — conditional on NEW-1/NEW-2 being closed or explicitly ruled before
`EnableV4RefundAuthoringCommand`, and NEW-3 being ruled + release-noted.**

All 7 round-2 items (A–G) and all 9 wave-4 items (6 sites + 3 extensions) are RESOLVED, verified
against code read line by line plus an executed golden-byte parity run and 89 green POS tests. No
new Critical. Three Importants: two are the ticket's own defect class surviving in
`PosAnalyticsService`'s payment- and line-level aggregates (which the diff also makes internally
inconsistent with the headline it just fixed), and one is a disclosed live-path semantic change to a
legacy fiscal cumulative counter. Eight Minors, none gating.
