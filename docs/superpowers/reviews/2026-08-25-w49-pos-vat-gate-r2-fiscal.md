# W4-9 gate r2 — fiscal-pos + GL-projection lens (fix round)

**Lane** W4-9 (P0) `fix/campaign-w49-pos-vat-gl` · worktree `.worktrees/w49-pos-vat-gl` · HEAD `cae3ecd81`
(fix round `2457e3a1c` F-1+F-5, `1bf113ebc` F-2/F-3/F-4/F-6, handback `cae3ecd81`; merge dev `9534c8889`)
**r1** `docs/superpowers/reviews/2026-08-24-w49-pos-vat-gate-r1-fiscal.md` · **Diff** `git diff d51f94a31...HEAD`
**Reviewer** fiscal-pos-reviewer, adversarial, code-grounded, verified BY EXECUTION on **both** sqlite and a
throwaway PostgreSQL `autoerp_test_w49g2` (`127.0.0.1:5433`).

**Lane state after review: UNTOUCHED.** `git status --porcelain` on the lane worktree = empty (0 lines).
Every tamper/probe ran in a temp `git worktree add … --detach cae3ecd81` under the scratchpad (hard-linked
vendor so `$baseDir` resolves to the temp tree, not the lane — verified `autoload_psr4.php:6`); the temp
worktree was **removed** and pruned. `autoerp_test_w49g2` was **DROPPED**. Reads against the live wave-4
tenant DB were `SELECT`-only.

---

## VERDICT

**spec ❌ · quality CHANGES-REQUESTED**

All six r1 findings I gated on are genuinely closed, and closed well — I red-proved each one independently
against the r1 tree. F-1's fix is the right shape: `posRevenueAndVatLineSpecs()` is now the single
decomposition and all three POS entry shapes consume it; the cheque/effet cancellation reverses net + `4457`
per sealed rate; the split is a **required, typed** argument on the `PosRevenue` arm, asserted before the
entry row exists. The census now compares **amounts** at the receipt's own currency scale in bcmath and
catches partial-leg drift. F-4's contra-revenue `709` treatment is the correct downstream reflection of what
the device actually seals, and it matches what `createPOSChargeEntry` already does on the sibling POS arm.

It is blocked by **R2-1**, a NEW **Critical** the fix round introduced: F-4 taught the *per-leg* guard about
the discount but left the *receipt-level* `vatExceedsTender` guard comparing the sealed VAT against the
**post-discount** tender. Any device-authorable receipt whose transaction discount exceeds its net subtotal —
including **every 100 %-off comp** — is now permanently **refused** by the projection. Proven twice by
execution. Before this lane those receipts booked (wrongly); now they do not book at all.

**R2-2** (Important) is a false positive in the deploy check itself: the census flags a *correctly booked*
instrument-tendered POS refund — the exact receipt F-1 just fixed — as "never reached the GL". Proven by
execution.

The **TAX BASE RULING** is below. Summary: the ledger fix is **correct given the sealed numbers and must not
change**, and a **separate device-side Critical** is opened — the device seals VAT on the pre-discount base.

---

## TAX BASE RULING (F-4)

### (a) What the device seals today — VERIFIED FROM CODE

**The sealed VAT is computed on the PRE-transaction-discount base.** The transaction discount reduces
`total` only; it never touches `vat_total`, `subtotal`, or any `vat_breakdown[]` row.

| Step | Evidence |
|---|---|
| Cart aggregates are LINE-level | `apps/pos/src/lib/offline/receiptService.ts:145-158` — `computeLineTotals()` returns `subtotal = Σ item.line_total` (gross) and `taxAmount = Σ item.tax_amount`. **Line** discounts are already inside `line_total`/`tax_amount`; the transaction discount is not in scope here at all. |
| The discount is applied to the GROSS total only | `apps/pos/src/lib/payment/cartTotals.ts:60-66` — `discount` is clamped to the gross `subtotal`, then `total = subtotal − discount`. No VAT recomputation anywhere in the file. |
| Both are handed to the builder separately | `apps/pos/src/lib/offline/receiptService.ts:306-320`, `:368-370` — `subtotalGross: subtotal`, `taxAmount` (pre-discount), `transactionDiscountAmount` computed by a different call. |
| The signed payload derives net by subtraction from the PRE-discount gross | `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:121` `subtotalNet = subtotalGross − taxAmount`; `:132` `vatTotal = input.taxAmount`. |
| The sealed `vat_breakdown` is a pure line-item roll-up | `SaleReceiptPayload.ts:327-353` — `buildVatBreakdown()` sums `line_subtotal`/`line_vat` per `(rate, category)`. **Zero discount adjustment.** |
| The device's own signing invariant makes it explicit | `SaleReceiptPayload.ts:193-201` — it asserts `subtotal + vat_total == total + transaction_discount_amount`, i.e. the discount is *added back* to reach the taxed base. |
| The server row mirrors it | `PosCoreReceiptProjection.php:261` `$discountAmountNorm = normalize($payload->transactionDiscountAmount)` → `:388` `discount_amount`. The `pos_receipts_totals` CHECK is `total = subtotal + tax_amount − discount_amount + rounding` (`:427-431`). So `pos_receipts.discount_amount` is the **transaction-level** discount only — the lane reads the right column. |

Worked example, verified by probe: subtotal(net) 600.000 + VAT 90.000 = gross 690.000; a 50.000 transaction
discount ⇒ `total = 640.000`, `vat_total = 90.000`, `subtotal = 600.000`. The customer pays 640.000 and the
ticket declares 90.000 of VAT on a 600.000 base they did not pay.

### (b) Does the declaration read sealed VAT, or recompute? — **READS SEALED, VERBATIM**

`apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php:130-158`:

```sql
SUM(CASE WHEN r.receipt_type = 'return' THEN -ABS(prvd.net_amount) ELSE prvd.net_amount END) as base_amount,
SUM(CASE WHEN r.receipt_type = 'return' THEN -ABS(prvd.vat_amount) ELSE prvd.vat_amount END) as vat_amount,
```

`base_amount` and `vat_amount` come straight off `pos_receipt_vat_details` — the sealed rows. There is **no
recomputation** anywhere on the POS arm, and no reference to `pos_receipts.discount_amount`. So the DGI
declaration's taxable base for a discounted receipt is the **pre-discount** `subtotal`.

### (c) Is the ledger fix correct GIVEN the sealed numbers? — **YES**

- The allocator reads the sealed rows through the query builder and only **adds and subtracts**
  (`PosReceiptVatAllocator.php:220-247`, `:146-154`). It never recomputes a rate against a base.
- Net is derived by subtraction (`:171`), so the entry balances by construction.
- `Dr 53 640 / Dr 709 50 / Cr 70x 600 / Cr 4457 90` puts the ledger's revenue base at **exactly** the sealed
  `Σ net_amount`, which is exactly the declaration's `base_amount`. The lane's own test asserts that identity
  against the sealed rows rather than a literal (`PosReceiptVatGlSplitTest.php:389-397`).
- It is the treatment the **same POS** already uses on its ACCOUNT_CHARGE arm:
  `GeneralLedgerService.php:4309-4315` resolves `ProductRevenue` + `VatCollected` + `SalesDiscount` and books
  revenue at the pre-discount base with `709` as contra. Two POS arms, one shape. Verified.
- `SalesDiscount` is already classified `REQUIRED` in the provisioning manifest
  (`ProvisioningRequiredPurposesV1.php:51`), so the new hard dependency is an existing guarantee, not a new
  provisioning risk.

**Ruling: the lane's treatment is right and must not be changed.** The device is the fiscal source of truth;
the ledger's only job is to reflect the sealed fact. Making the ledger book a 556.522 base against a sealed
600.000 base would be the projection re-authoring a device-signed fact — a Critical fiscal-integrity defect
in its own right, and it would put the books back out of step with the filing.

### (d) A SEPARATE device-side finding IS required — **YES**

> **[CRITICAL] D-1 — the device seals output VAT on the pre-discount base, so a transaction discount
> over-declares VAT on an immutable, signed fiscal document.**
> `apps/pos/src/lib/offline/receiptService.ts:145-158` (line-level roll-up) ·
> `apps/pos/src/lib/payment/cartTotals.ts:60-66` (discount applied to the gross total only) ·
> `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:121,132,327-353` (net/VAT/breakdown all
> pre-discount) · `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:193-201` (the invariant that
> *pins* the pre-discount base into the signature) · consumed by
> `EloquentVatDataRepository.php:148-149`.
>
> Under the Tunisian Code de la TVA the taxable base is the price actually charged, **remises et rabais
> consentis sur la facture déduits** — a genuine price reduction granted on the ticket reduces the base and
> therefore the VAT. The device does the opposite: on a 690.000 TTC ticket with a 50.000 discount it seals
> base 600.000 / VAT 90.000 and collects 640.000. The tenant over-declares ≈ `discount × rate/(1+rate)` of
> output VAT per discounted receipt (6.522 in the example), and the printed ticket shows the customer a VAT
> figure that does not correspond to what they paid.
>
> **Reachable in production today.** The transaction-discount UI is live and ungated:
> `apps/pos/src/pages/HomePage.tsx:1670` (`onDiscount`) → `:1805` (`DiscountModal`) → `:1501-1510`
> (`setTransactionDiscount`). Not yet materialised in data: I confirmed read-only on the wave-4 tenant
> (`tenant01a035ba-…`) that **0 of 2** receipts carry a non-zero `discount_amount`.
> Corroborating signal that the discount path is known-underspecified: the device **refuses outright** to
> refund a receipt with a non-zero transaction discount
> (`apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts:164-176`, `:313-317` — "Whole-receipt-discount
> refunds are not available in this launch").
>
> **Severity Critical** because it is wrong money on a sealed, hash-chained document that can never be
> corrected — every receipt authored before a fix is permanently wrong, and the fix is strictly forward-only.
> **NOT a blocker for W4-9**, which is downstream and correct. It needs its own lane plus an owner/fiscal-
> counsel ruling on the TN base, because (i) the legal reading is not verifiable from code, and (ii) changing
> it changes canonical bytes and the aggregate invariant at `SaleReceiptPayload.ts:193-201`, so it is a
> versioned-payload change, not an edit. Interim mitigation while the ruling is pending: gate the
> transaction-discount UI off, or restrict it to line-level discounts (which **do** correctly reduce the
> sealed base — `computeLineTotals` sums `line_total`/`tax_amount`, both already net of the line discount).

---

## Findings

### [CRITICAL] R2-1 — F-4 updated the per-leg net guard but not the receipt-level one; a discount larger than the net subtotal now REFUSES the whole projection

`apps/api/app/Modules/Accounting/Domain/Services/PosReceiptVatAllocator.php:129-131`

```php
if (bccomp($vatTotal, $tenderTotal, $currencyScale) > 0) {
    throw PosVatProjectionRefusedException::vatExceedsTender($receiptId, $vatTotal, $tenderTotal);
}
```

The comment on the sibling per-leg guard explains the premise: "the sealed VAT total exceeds the retained
tender, so at least one revenue credit would be negative." That was true at r1, when `net = tender − vat`.
F-4 changed the arithmetic to `net = tender + discount − vat` (`:171`) and updated the **per-leg** check at
`:172` accordingly — but `:129` still compares against the bare tender. The guard therefore fires whenever
`vat > tender`, i.e. whenever **`discount > net subtotal`**, on receipts whose own arithmetic is perfectly
consistent and whose per-leg net is comfortably positive.

**Proven by execution** (temp worktree at `cae3ecd81`, sqlite, probe on the lane's own fixture):

```
# subtotal(net) 600.000, sealed VAT 90.000, discount 620.000, tender 70.000
PosVatProjectionRefusedException: pos_vat_projection_refused:vat_exceeds_tender:
  receipt=94492e57-…:vat=90.000:tender=70.000
  at PosReceiptVatAllocator.php:130 ← TreasuryReceiptBridge.php:474 ← :1491 ← :481 ← :322

# 100 % comp: discount 690.000, tender 0.000
PosVatProjectionRefusedException: …:vat=90.000:tender=0.000
```

The per-leg guard would have passed: `net = 0 + 690 − 90 = 600 ≥ 0`.

**The receipt is device-authorable and device-signed.** `cartTotals.ts:65` clamps the discount to the
**gross** subtotal (690), so 620 and 690 are both allowed; `SaleReceiptPayload.ts:193-201` then asserts
`600 + 90 == 70 + 620` ✓ and signs. So a cashier comping a ticket produces a valid sealed receipt that the
server permanently refuses.

**Impact.** Nothing catches `PosVatProjectionRefusedException` (grep across `app/`: it is thrown in the
allocator, the DTO and `GeneralLedgerService`, and caught nowhere). It escapes `TreasuryReceiptBridge::apply()`
→ the projection job fails → **no payment, no repository movement, no GL entry, ever**, for a receipt whose
money is real and whose VAT the DGI declaration will still report from the sealed rows. That is a
silently-dropped row and books ≠ filing — the W4-9 shape, reintroduced from the other side. On dev the same
receipt books (wrongly, gross-to-revenue), so this is an availability regression the lane introduces.

**Fix before merge.** Compare against the discount-inclusive base and keep the message honest:

```php
$grossBase = bcadd($tenderTotal, $declaredDiscount, $currencyScale);
if (bccomp($vatTotal, $grossBase, $currencyScale) > 0) {
    throw PosVatProjectionRefusedException::vatExceedsTender($receiptId, $vatTotal, $grossBase);
}
```

Add two cases to `PosReceiptVatGlSplitTest`: `discount > net` (the 620/70 shape — assert it books
`Dr 53 70 / Dr 709 620 / Cr 70x 600 / Cr 4457 90`) and the 100 %-comp shape.

### [IMPORTANT] R2-2 — the census false-positives on a correctly booked instrument-tendered refund, and calls it "never reached the GL"

`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:60` (`POS_SOURCE_TYPES`), `:112-140`

A cheque/effet-tendered POS refund is fully handled by the instrument lane and writes **no**
`pos_receipt_refund` entry at all — `handleMaturityRefundLeg()` cancels the paper and `return true`
(`TreasuryReceiptBridge.php:1635-1647`), and the cancellation entry is
`source_type='instrument'`, `source_id=<instrument id>` (`GeneralLedgerService.php:3320-3336`). The census
only joins `source_type IN ('pos_receipt','pos_receipt_refund')` keyed on `pos_receipts.id`, so that entry —
the very one F-1 just taught to reverse `4457` — is invisible to it.

**Proven by execution** (probe on `PosBridgeInstrumentRefundTest`'s VAT-bearing fixture, lane HEAD, sqlite):

```
PROBE census exit=1
POS output-VAT leg census: 1 receipt(s) whose ledger `vat_collected` does not match their sealed VAT.
FE-POS624-2026-00000002  posted=…  sealed_vat=2.00  ledger_vat=0.00 EUR  pos_entries=0  receipt_id=…
Breakdown: 1 never reached the GL (no pos_receipt entry at all), 0 were booked with a wrong or partial VAT leg.
```

The sale is clean; the **refund** is flagged. The breakdown line then points the operator at the wrong
remedy — "never reached the GL" invites re-provisioning or a correcting entry on a receipt that is booked
correctly. On a tenant that accepts cheques this is systematic noise in the one command that is supposed to
be trusted at deploy time, and it inflates the drift count that gates the deploy.

**Fix.** Extend the ledger/entry derived tables to include `source_type='instrument'` rows reachable from the
receipt — `pos_receipt_payments(receipt_id, payment_id)` →
`payment_instruments.payment_id` → `journal_entries.source_id = payment_instruments.id`
(`database/migrations/tenant/2026_01_08_190640_create_pos_receipt_payments_table.php:25`; the
`payment_instruments.payment_id` link is what `handleMaturityRefundLeg` matches on). Add the case as a
control test.

### [MINOR] R2-3 — `apportion()`'s zero-tender short-circuit is stale now that the discount rides it

`apps/api/app/Modules/Accounting/Domain/Services/PosReceiptVatAllocator.php:287-299`

```php
// A zero tender total can only pair with a zero VAT total (the caller
// already refused vat > tender), so every share is zero …
if (bccomp($tenderTotal, '0', $currencyScale) === 0 || count($legAmounts) === 1) {
```

The premise is a VAT premise. F-4 routes the **discount** through the same helper (`:101`, `:138`), and a
zero tender emphatically *can* pair with a large discount (100 % comp). With `count($legAmounts) > 1` and a
zero tender, every discount share is set to `0`, so `Σ legDiscount ≠ declaredDiscount`, `netRevenue` silently
falls back to the post-discount base — F-4 undone in that corner — and `assertReconciles()` still passes
(`0 + 0 − 0 == 0`), so nothing complains. Reachable only with two or more zero-amount legs, hence Minor, but
it is a **silent** wrong base rather than a refusal. Fix the comment and make the discount's zero-tender
share explicit (single-leg already does the right thing at `:294-296`).

### [MINOR] R2-4 — no control for the opposite drift direction in the census

`apps/api/tests/Feature/Accounting/PosReceiptVatLegCensusCommandTest.php:79-205`

The nine cases cover clean / no-leg / zero-rated / never-posted / tenant filter / multi-entry / partial-leg /
split-tender / refund. There is no case for a ledger `4457` that **exceeds** the sealed figure — the
double-booked or twice-replayed receipt. The amount comparison at
`PosReceiptVatLegCensusCommand.php:196-198` does catch it (magnitude inequality is symmetric), so this is a
missing pin, not a defect.

### [MINOR] R2-5 — VAT-bearing fixture keeps `tax_category_code: 'Z'` at a 25 % rate

`apps/api/tests/Feature/Treasury/PosBridgeInstrumentRefundTest.php:551,557,585`

`vat_rate` is parameterised to `25.00` but the category stays `'Z'` (zero-rated) on both the line and the
`vat_breakdown` row. Nothing in the projector or the allocator reads `tax_category_code` (that was F-6's
point), so the test is sound — but the fixture now describes a receipt that could not exist, which will
mislead the next person who parameterises it. Derive the category from the rate.

### Carried from r1 — still open, unchanged

- **F-8** `apps/api/tests/Feature/POS/ReceiptPaymentServiceToleranceTest.php:399-418` — the only exercise of
  the legacy `ReceiptPaymentService` split is 0 % VAT and its lane does not run on PR→dev
  (`feature-lane-pos/POS`, `runs_on_pr_dev: false`). Route is retired (410). Ledger note.

---

## Verified — r1 findings re-checked BY EXECUTION

| r1 finding | How verified | Result |
|---|---|---|
| **F-1** instrument refund reversed revenue GROSS, no `4457` | Reverted `GeneralLedgerService` + `TreasuryReceiptBridge` + `InstrumentLifecycleService` to `d51f94a31` in the temp worktree, ran `PosBridgeInstrumentRefundTest` at lane tests | **RED**: 1 failure — `debit: 10` on `ProductRevenue`, no `4457` line, exactly the r1 shape. The other 4 (0 %) cases **pass**, proving the fixture change is byte-compatible. **CLOSED** |
| F-1 threading | Read `GeneralLedgerService.php:3288-3308` (required + `assertReconciles` **before** the entry row), `:3342-3376` (`PosRevenue` arm → `posRevenueAndVatLineSpecs`), `InstrumentLifecycleService.php:605-634`, `:819-856`, `TreasuryReceiptBridge.php:1602-1647` | `cancel()` → `performCancellation()` → `createInstrumentCancellationEntry()` threaded; typed refusal when absent; nominal reconciled before any Draft exists. The split is resolved from the **refund** receipt and the instrument is matched on `amount == $line->amount` (`:1630`), so the reconcile can't drift. A replayed refund (`Cancelled`) returns at `:1649` without touching the allocator — redelivery-safe |
| F-1 "single decomposition" | `git show 2457e3a1c`, `1bf113ebc` | `writePosRevenueAndVatLines()` and the `PosRevenue` cancellation arm both consume `posRevenueAndVatLineSpecs()`. No hand-written POS revenue line remains |
| **Every `ProductRevenue` writer on the POS side** | `grep -rn 'SystemAccountPurpose::ProductRevenue' app/` + read each enclosing function | 3 POS writers besides the specs: `createPOSPaymentToleranceEntry` (`:3636`, Dr 658/Cr 70x adjustment), `createPosCashRoundingEntry` (`:4072`), `createPosToleranceWriteoffEntry` (`:4250`) — all write-offs/adjustments, correctly gross. `createPOSChargeEntry` (`:4309`) already net+VAT+709. `TreasuryReceiptBridge:553,631` are `hasAccountForPurpose` **prechecks**, not writers. **No gross POS revenue writer remains** |
| **F-2** cartesian sealed-VAT sum | Reverted only the census command to `d51f94a31` | **RED**: `sealed_vat=180` where the sealed fact is 90; also `sealed_vat=19` unformatted. Now `sealed_vat=19.000  ledger_vat=0.000` at the currency scale. **CLOSED** (incl. the r1 cosmetic rider) |
| **F-3** partial-leg false negative | same revert | **RED**: `Failed asserting that 0 is identical to 1` — pre-fix exit 0 on a receipt with a `4457` line on one leg and not the other. **CLOSED** |
| F-2/F-3 controls | Read `PosReceiptVatLegCensusCommandTest.php:79-205` | Controls present: fully-booked split tender clean (`:178`), correctly-booked refund that **debits** `4457` clean (`:197`), zero-rated not flagged, never-posted separated, tenant filter. Verdict computed in bcmath at the receipt scale (`PosReceiptVatLegCensusCommand.php:172-215`), never in SQL — driver-independent. Command is **SELECT-only** (grep for `update|insert|delete|DB::statement|save(` → none) |
| **F-4** discount untested / base divergence | Tampered `$declaredDiscount` to `'0'` on the HEAD allocator | **RED**: both new cases fail `'50.000' vs '0.000'`. Green at HEAD. **CLOSED** — see the TAX BASE RULING |
| **F-5** dead `ProductRevenue` lookup | `git show 2457e3a1c` | Deleted from `createPOSPaymentEntry`. **CLOSED** |
| **F-6** `PosVatRateAllocation::$taxCategory` | `git show 1bf113ebc`; `grep -rn taxCategory app/Modules/Accounting/` | Field, query column and mapping all removed; zero remaining references. **CLOSED** |
| **F-7** ratchet-regen list understated | Ran `ProvisioningRequiredPurposesRegistrationRatchetTest` and extracted the POS-region diff | Handback §8.1 (`docs/…/2026-08-24-w49-handback.md:267-289`) is **exactly right**: ADD `:3878\|posRevenueAndVatLineSpecs\|ProductRevenue`, `:3895\|…\|SalesDiscount`, `:3907\|…\|VatCollected`; REMOVE `:3223\|createInstrumentCancellationEntry`, `:3558\|createPOSPaymentEntry`, `:3642\|createPOSRefundReversalEntry`; plus the RE-PIN of every other `GeneralLedgerService` entry. **CLOSED** |
| Ratchet failures inherited | Ran both ratchet tests on the lane AND on dev@`96525a55e` in the temp worktree | **2 failures on both**, same drift (`AccountingService.php:412→612`, `AccountingOpeningService.php:314→337`). Genuinely INHERITED, count unchanged |
| `SalesDiscount` is a new hard dependency | `ProvisioningRequiredPurposesV1.php:51` | Already `REQUIRED` (via `createPOSChargeEntry`). No new provisioning risk |

## Verified — gates and invariants

| Item | How | Result |
|---|---|---|
| New/affected tests, sqlite | `PosReceiptVatGlSplitTest` + `PosReceiptVatAllocatorTest` + `PosReceiptVatLegCensusCommandTest` + `PosBridgeInstrumentRefundTest` | **OK (30 tests, 137 assertions)** |
| Same, PostgreSQL | throwaway `autoerp_test_w49g2` @ `127.0.0.1:5433` | **OK (30 tests, 137 assertions)** — identical |
| Affected set, PostgreSQL | `PosBridgeInstrumentTest`, `PosBridgeSpineTest`, `InstrumentReversalCancellerHardeningTest`, `InstrumentLifecycleReceiveTest`, `ReceiptPaymentServiceToleranceTest`, `PaymentGlPostingTest` | **41 tests, 187 assertions, 1 error** — `ReceiptPaymentServiceToleranceTest:296`, `pos_shifts_closed_logic` CHECK. Same single error r1 saw. **INHERITED**, untouched by the lane |
| `AdvanceReversalGlShapeTest` "11 inherited" | Ran on the lane (sqlite), on dev@`96525a55e` (sqlite), and on the lane (**PostgreSQL**) | **11/12 fail on BOTH lane and dev under sqlite — identical failure set, name for name.** Under **PostgreSQL the lane is 12/12 GREEN**. So the 11 are an sqlite decimal-format artifact (`'80'` vs `'80.000'`), NOT a defect — and the lane's edited negative control `test_a1g_negative_…still_cancels_to_product_revenue` is genuinely green on PG: the `PosRevenue` arm with a `vatFree` split debits `ProductRevenue` 80.000 and nothing else. Confirms the r1 rubric "inherited, count unchanged" **and** upgrades it |
| Sealed bytes / hash / immutability, RE-PROVEN after the fix round | `git diff 96525a55e...HEAD` over `apps/api/app` | **Zero migrations** in the fix round and in the whole lane. Zero writes to `pos_receipts`, `canonical_bytes`, `fiscal_hash`, `previous_hash`, `vat_breakdown_hash`. No `->update(`, `insert(`, `DB::statement`. No fiscal Event class renamed/restructured (rule 8 clean). The census is `SELECT`-only |
| Rule 20 — new named queues | `git diff … \| grep onQueue` | **None added.** No horizon change needed |
| Rule 20 — no-arg `getScale()` in a job/console/projection | `git diff … \| grep -E 'getScale\(\)'` | **None** (one docblock mention only). The census constructor-injects `CurrencyScaleResolverInterface` and calls `getScaleSafe((string) $row->currency, 3)` (`PosReceiptVatLegCensusCommand.php:68-73`, `:177`) — explicit currency, correct for a console context with no `CompanyContext` |
| Rule 20 — projection tests clear `CompanyContext` | `PosBridgeInstrumentRefundTest.php:137-138`; `PosReceiptVatGlSplitTest` clears before every `apply()` | Cleared. `PosBridgeInstrumentRefundTest` sets it only to seed (`:79`) then clears for the whole class |
| Rule 19 — precision | PHPStan level 8 on all 7 touched production files | **[OK] No errors.** `grep` for `(float)`/`floatval`/`number_format` in the diff → **none**. bcmath throughout; scale always passed explicitly. `CurrencyScale::bcround` used deliberately in the census (`:210-215`) because sqlite `SUM()` over a decimal returns a float — documented, and it only affects a report-only figure |
| Rule 13 | `PosReceiptVatLegCensusCommand.php:68-73` | Constructor `private readonly` injection. No `app()` in production code (test-only) |
| `unit_price` TTC trap | Read the new assertions | No per-line `line_subtotal == unit_price×qty − discount` anywhere. All new assertions are aggregate-level |
| Refund/void model | `TreasuryReceiptBridge.php:443-445`, `PosCoreReceiptProjection::resolveReceiptType`, `EloquentVatDataRepository.php:148-149` | No parallel refund event invented; REFUND/VOID → `receipt_type='return'` → declaration nets by `-ABS(...)`. Ledger and filing agree on the netting predicate |
| Idempotency under redelivery | `TreasuryReceiptBridge.php:1649-1651` (already-`Cancelled` instrument returns without allocating); `$resolveVatSplit` invoked only in the create branch | A replayed leg never reaches the allocator. Intact |
| Census on the wave-4 tenant | **Re-verified by direct read-only SQL** against `tenant01a035ba-592b-72aa-a12a-1e6b2f7e1d06` @ `127.0.0.1:5433`, replicating the command's derived tables | Exactly the handback's output: `FE-T-MAIN-01-2026-00000001 sealed 52.000 / ledger 0 / 1 entry` and `…-00000002 sealed 2.800 / ledger 0 / 1 entry`, TND, 2 rows. Also confirmed **0 of 2** receipts carry a transaction discount (relevant to D-1) |
| Manifest | `php tools/feature-lane-manifest-check.php` | **EXIT=0**, lane **1404** classes / 74 groups. Merge base `96525a55e` = 1401 (so the lane adds exactly its 3). **Current local dev `df816b701` = 1406**, so the post-re-merge union is **1409** — re-run the check after re-merging dev; the number in the handback (1404) will be stale at merge |
| Deptrac | `php tools/deptrac-ratchet.php` | **PASS**, 182/182 held, no boundary regression |
| Pint | `./vendor/bin/pint --test` over all 10 touched files | `{"result":"pass"}` |

---

## What must change before merge

1. **R2-1 (Critical)** — `PosReceiptVatAllocator.php:129-131`: compare the sealed VAT against
   `tenderTotal + declaredDiscount`, not the bare tender, and pin the `discount > net` and 100 %-comp shapes
   in `PosReceiptVatGlSplitTest`. Today a comped ticket is sealed on the device and **never books**.
2. **R2-2 (Important)** — teach the census about `source_type='instrument'` entries reachable through
   `pos_receipt_payments → payment_instruments`, so the deploy check stops flagging the exact refund F-1
   just fixed as "never reached the GL"; add it as a control.
3. R2-3 / R2-4 / R2-5 can ride the same round.
4. **Open D-1 as its own lane** (device seals VAT on the pre-discount base) with an owner/fiscal-counsel
   ruling on the TN taxable base. Do **not** touch W4-9's treatment for it — W4-9 is the correct downstream
   reflection of whatever the device seals.
5. Re-merge dev before promotion and re-run the manifest check (1404 → expected 1409 against dev
   `df816b701`).
