# M2 adversarial merge-gate register — round 2

**Range:** `7d85232cc54abd6a6b2135f476205ab434e71a66..fe2035cb8` (M2 = `610a2d6f6`…`fe2035cb8`; repair commit `be6cebe16`).
**Criteria:** brief §5 wave-2 row + SPEC r5 §3(b)/§3(c), §3.b.4/§3.b.5, §4.1 S-4/S-5/S-12, §4.4 S-11, §4.5, §7.1 BT-6/7/12/13/14/15/17, §7.2 FT-4…FT-8/FT-12/FT-13, §6 CL-3/CL-4.
**Lenses:** fiscal-pos, frontend-conventions, treasury, tenancy-authz, general — all applied, all applicable.

**Round-1 blocking set re-verified:** findings 1 (verify-chain scope), 2 (BT-6 enrichment/snapshot), 3 (reason provenance visible), 5 (historical rows hidden) are **closed in code**, not just in prose — see the bypass table. Finding 4 is closed on the axis it named and **opened a new defect on another**, below.

---

### 1. **P1 — CONFIRMED — fiscal-pos / treasury** — the round-1 repair makes the detail payload violate the spec's binding aggregate identity for pre-fiscal returns
`apps/api/app/Modules/POS/Application/DTOs/ReceiptDetailData.php:84-90,156-162` · renders at `apps/web/src/features/pos/pages/ReceiptDetailPage/ReceiptDetailPage.tsx:172-178` · sign source `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:944-963`

Fix #4 projects a pre-fiscal return's `total` to a **magnitude** (`:88-90`, `:162`) but leaves `subtotal` (`:156`), `tax_amount` (`:157`), `discount_amount`, `vat_details[]` and `lines[].line_total` (`:112`) as the stored **negative** values. `ReceiptReturnService` stores every one of those negative for a legacy return (`:946-963` `bcmul(..., '-1')`, `:775-778`). So `show()` now emits a document where

```
subtotal + tax_amount − discount_amount + COALESCE(rounding,0)  ≠  total
```

which is exactly the identity SPEC §3.b.4 declares binding on this payload and pins to the live `pos_receipts_totals` constraint. Two further consequences:
- **Same field, two conventions across endpoints.** `GET /pos/receipts` still emits the signed value for the same receipt (`ReceiptController.php:212`); `GET /pos/receipts/{id}` emits the magnitude. §3.b.5(ii) allowlists `total` as a column read; the transform is builder-invented and is **not declared** in the wave-2 handback's "Decisions, deviations" section — a silent deviation under brief §8.8.
- **BT-14 cannot catch it.** `ReceiptAggregateIntegrityTest.php:19,63-68` runs only SALE fixtures, and the repair's own regression `ReceiptShowResourceTest.php:145-159` asserts `invoice_type_code === 'REFUND'` and `total === '5.250'` while asserting nothing about the components — it freezes the inconsistency instead of detecting it.

**Failure scenario:** a first-tenant tenant with migrated pre-v4 history. The accountant opens `/pos/receipts/refunds`, clicks a legacy return, and the summary grid reads Subtotal **−5.000 TND** · VAT **−0.250 TND** · Discount **0.000** · Rounding **0.000** · Total **5.250 TND**. The lines table underneath shows quantity **−1.0000** and line net **−5.250**. A fiscal register is showing a total that contradicts its own components, on the one row class where the sign era differs — and no backend test fails.

Either magnitude-project the whole payload consistently (and say so, with a BT-14 legacy-return fixture asserting the identity on the emitted shape), or revert the root `total`/`invoice_type_code` transform and carry the presentation flip where round 1's list already carries it. Both are defensible; the current half-transform is not.

### 2. **P3 — CONFIRMED — general** — `show()` indexes the refund-reporting map unguarded after the round-1 narrowing
`apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:600-602` vs `:217`

Fix #13 narrowed `RefundReportingEnricher::isRefundLike()` (`RefundReportingEnricher.php:52-58`) so a `receipt_type='return'` row with `invoice_type_code='SALE'` **and** a `fiscal_event_id` is no longer enriched. The index consumes the map defensively (`:217` `?? null`); `show()` does not (`:602` `$returnReporting[$returnReceipt->id]`), then dereferences it. Any `returnReceipts` member the enricher skips ⇒ `Attempt to read property on null` ⇒ 500 on the **original's** detail page. I could not reach that data shape from any current writer — `resolveReceiptType()` returns `Return` only for REFUND/VOID (`PosCoreReceiptProjection.php:573-579`), `ReceiptReturnService` leaves `fiscal_event_id` null, and exchanges link by `exchange_group_id`, not `original_receipt_id` — so this is a latent coupling, not a live bug. The builder's own fixture at `RefundReportingFieldsTest.php:137-156` constructs precisely that row.

### 3. **P3 — CONFIRMED — fiscal-pos** — BT-13 asserts three of its five named columns; the handback claims all
`apps/api/tests/Feature/POS/ReceiptPdfPrintAuditTest.php:29-41`

BT-13 requires the row to carry the correct `receipt_id`, `terminal_id`, `user_id`, `print_method` and an incremented `copy_number`. The test asserts `print_type`, `copy_number`, `print_method` and filters by `receipt_id`; **`terminal_id` and `user_id` are never asserted**. The production code is correct (`ReceiptController.php:764-769,801-806` pass `$receipt->terminal_id` and `$user->id`), so this is a coverage gap, not a defect — but the handback states the test "proves … the authenticated user, receipt" (wave-2 S-11 bullet), which it does not. Misattributing a reprint to the receipt's cashier instead of the actor would pass this suite.

### 4. **P3 — CONFIRMED — frontend-conventions** — sign handling remains split between layers
`RefundReceiptListPage.tsx:30-32,188` (unconditional `amountMagnitude` on any negative) vs `ReceiptDetailData.php:88-90` (magnitude only for the pre-fiscal predicate). A negative-total `REFUND`/`VOID` row would still show `5.250` on (c) and `-5.250` on (b). SPEC §3.c.2 says v4 refunds store a POSITIVE total, so this shape should not exist in production — but the branch's own fixture uses it (`ReceiptShowRefundLineageTest.php:19`). One rule, one layer.

### 5. **P3 — CARRIED, unchanged** — dispositioned in round 1, re-verified as still present and acceptable
`receipt_type` on the list allowlist (`ReceiptController.php:204`); FT-13's local `<Route>` stubs (`ProvenanceSection.test.tsx:22-34`, `LedgerHistoryTable.test.tsx:64-76`) cannot fail against the pre-build routing table; `refund_policy_alerts: Array<any>` in `packages/shared/types/generated.d.ts:1531` (narrowed at every use); `Receipt::terminal()->withTrashed()` (`Receipt.php:311`) changes every repo-wide consumer.

### 6. **P3 — CONFIRMED** — red-first evidence is still narrative
Brief §8.2 asks for "what its failure looked like". `be6cebe16` bundles tests with implementation and the handback reports the red run in prose ("verify-chain returned 200 instead of 404 …") plus green counts. Same pattern M1 shipped under; recorded for the terminal audit, not blocking.

### 7. **P3 — note** — money bcmath at a literal scale outside the guarded layer
`ReceiptController.php:660-671` (`receiptTotalMagnitude`) uses literal scale `4` on money with no `precision-ok:` marker. `ForbidHardcodedBcmathScale` only scans `Application/Services` and `Domain/Services`, so PHPStan is silent, and 4 ≥ every supported currency scale so nothing truncates — but it is the pattern rule 19 exists to stop, two lines below a `quantityMagnitude()` that does carry the markers.

### 8. **Evidence still outstanding for M3 (declared, not a finding)**
Exact preflight **BLOCKED** (repo-wide Pint drift), scoped vitest **PARTIAL** (3 out-of-lane baseline failures), scoped PHPUnit **PARTIAL** (21 out-of-lane errors), Playwright four-flow run and **all** wave-2 screenshots (detail six sections, three capability banners, reprint dialog) **ENVIRONMENT BLOCKED** on the absent API at `127.0.0.1:8010`. §7.4's live evidence for screens (b)/(c) is unmet; the handback reports this honestly and does not call it green.

---

## Bypasses attempted that FAILED (the implementation held)

| Attack | Result |
|---|---|
| Verify-chain scope bypass with no active membership (`allowed_location_ids = []`) | `isUnrestricted()` is `count(array_diff(allLocationIds, [])) === 0` (`LocationScopeBoundary.php:66-69`) → false whenever any location exists → `whereIn('location_id', [])` → 404. Fails closed. |
| Verify-chain via a NULL-`location_id` terminal as a restricted user | `whereIn` excludes NULL; the `orWhereNull` escape exists only on the unrestricted Z-report arm (`ReportController.php:277-279`). Held. |
| Fix #12 (`event_type === SALE_RECEIPT` gate) silently killing canonical reasons for real refunds | `REFUND_RECEIPT` is `RESERVED_UNREACHABLE` (`FiscalEventCoveragePolicy.php:50`); v4 refunds seal as `SALE_RECEIPT` with `original_receipt_reference`. Canonical extraction still runs on every real refund (`RefundReportingFieldsTest.php:23-69` proves `refund_reason_source = 'canonical'`). Held. |
| Fix #2 vacuity — delete the `quantity_decimals` ternary | `ReceiptShowResourceTest.php:111-143` now creates a `decimal_places = 2` unit, mutates the product SKU/name **after** the line snapshot, and asserts `1.25` / `2` / `SNAPSHOT-001`. Both BT-6 halves now bite. Held. |
| Fix #5 hiding rows on a later page / during load | `showsRegister = hasActiveTerminal \|\| refunds.length > 0` (`RefundReceiptListPage.tsx:206`), pagination gated the same way; covered by a new test at `RefundReceiptListPage.test.tsx:144-155`. Held. |
| Cross-receipt aggregate anywhere in the branch (Addendum A(c) rule 2 / OI-3) | `git diff -U0 7d85232cc..HEAD -- apps/api/app apps/web/src \| grep '^+' \| grep -iE '\bsum\(\|->sum\(\|\.reduce\('` → **zero**. |
| `parseFloat` / `Number(...)` / `: any` / `app()` in new production code | zero additions on all four greps over the same diff. |
| en/fr drift on the new keys | programmatic key-set diff over `pos.json`: 0 missing either way; the only identical value is `receiptReporting.refunds.placeholder` (`—`), which is correct. |
| `canonical_bytes` / `tenant_id` at lineage depth | `ReceiptResourceNoCanonicalBytesRecursiveTest` walks a 3-deep tree with both directions populated. Held. |
| Audit row written before the location check | `ReceiptLocationScopeAuthorizationTest.php:15-26` asserts `pos_receipt_prints` count 0 after 404s on both PDF routes. Held. |
| Exchange receipts entering `returnReceipts` and 500-ing finding 2 | exchanges link via `exchange_group_id` (`ReceiptCreationService.php:610`), never `original_receipt_id`. Held. |
| New named queue / non-additive migration in M2 | none added. Held. |

**Not executed by me:** PHPUnit and vitest were not re-run (no live-DB env, full-suite prohibition). Every finding is derived from source and cited by `file:line`.

**Blocking set:** finding 1 (P1) must close. Findings 2–4 and 6–7 are P3 and may ship with a recorded ticket; finding 3 is worth closing with two `assertSame` lines while the file is open.

VERDICT: CHANGES-REQUIRED

---

## Builder disposition

Implemented in `e15d3e6a9` (`Phase 1.2.14: Preserve legacy return document integrity`).

1. **Closed.** Pre-fiscal returns now cross one explicit reporting boundary for both list and detail. The detail DTO sign-inverts every signed document component together—header subtotal/tax/discount/rounding/total, line quantity/discount/VAT/net, and VAT net/VAT/gross—while leaving rate fields unchanged. The emitted aggregate identity therefore remains true. The frozen fixture proves `5.000 + 0.250 - (-0.500) = 5.750`, positive line/VAT magnitudes, and a negative reversal-discount adjustment. The list emits the same server-derived legacy magnitude.
2. **Closed.** `show()` now filters lineage children to IDs actually returned by `RefundReportingEnricher`; the constructed fiscal-SALE/legacy-return shape is excluded rather than dereferenced. Its regression calls the original detail endpoint and asserts an empty lineage with HTTP 200.
3. **Closed.** Both print rows now assert the exact `terminal_id` and authenticated actor `user_id`, alongside receipt, method, type, and sequential copy number.
4. **Closed.** The browser no longer applies an unconditional sign strip. The server owns the exact pre-fiscal predicate; a malformed signed REFUND remains visibly signed instead of being silently rewritten.
5. **Recorded, unchanged.** The previously accepted P3 set remains explicitly carried.
6. **Closed for this repair.** Exact failing output is now preserved below rather than only summarized.
7. **Closed.** `receiptTotalMagnitude()` uses the receipt currency's resolved scale for every bcmath operation; the presentation-layer literal scale is gone.

### Captured red output before `e15d3e6a9`

```text
ReceiptAggregateIntegrityTest: Failed asserting that '-5.000' is identical to '5.000'.
RefundReceiptListPage.test.tsx: Unable to find an element with the text /-12[.,]345/ because the browser stripped the sign.
ReceiptLocationScopeAuthorizationTest (round 1): Expected response status code [404] but received 200.
ReceiptShowResourceTest (round 1): Failed asserting that 'SALE' is identical to 'REFUND'.
```

Focused green evidence after the fix: 26 backend tests / 221 assertions and 12 frontend tests; touched PHPStan, Pint, changed-file ESLint, and TypeScript all pass.
