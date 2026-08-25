# W4-9 gate r1 — fiscal-pos + GL-projection lens

**Lane** W4-9 (P0) `fix/campaign-w49-pos-vat-gl` · worktree `.worktrees/w49-pos-vat-gl` · HEAD `d51f94a31`
(`bd5ad6a22` `a36288bc8` `647de4fd5` `79b4e3225`, merge dev `f15f5773b`, handback `d51f94a31`)
**Reviewer** fiscal-pos-reviewer, adversarial, code-grounded, verified BY EXECUTION.
**Lane state after review:** untouched — `git status --porcelain` empty; all probes were untracked files, deleted; the
red-proof used `git checkout dev -- <3 files>` and was restored with `git checkout HEAD -- <3 files>`.
Throwaway PG `autoerp_test_w49g` created on `127.0.0.1:5433` and **dropped**.

## VERDICT

**spec ❌ · quality CHANGES-REQUESTED**

The core fix is right and well built: the split reads the SEALED `pos_receipt_vat_details` and never recomputes,
net is derived by subtraction so the entry balances by construction, refunds mirror through one shared writer,
the rounding and tolerance legs compose correctly, and the refusal arms are typed and fail-closed. The red proof
reproduces independently.

It is blocked by **F-1**: the lane makes the sale leg NET+VAT but leaves the *maturity-instrument* POS refund arm
(cheque/effet, `CancellationShape::PosRevenue`) debiting revenue GROSS with no `4457` reversal. Proven by execution:
on a 10.00 VAT-bearing cheque sale + same-day refund the ledger ends at **revenue net −2.000 and `4457` credited
2.000 that is never reversed** — the exact W4-9 shape (books ≠ filing, trial balance still closes) reintroduced on a
live path the lane's own tests cannot see because their fixture is 0 % VAT.

---

## Findings

### [CRITICAL] F-1 — maturity-instrument POS refund reverses revenue GROSS while the sale now credits it NET; `4457` is never reversed

`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3327-3332`
`apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:1626-1637`
`apps/api/tests/Feature/Treasury/PosBridgeInstrumentRefundTest.php:197` (blind assertion), `:483` (0 % fixture)

A refund/void whose tender is a maturity instrument does NOT take `createPOSRefundReversalEntry`. It takes
`handleMaturityRefundLeg()` → `instrumentLifecycle->cancel(..., CancellationShape::PosRevenue)` and `return`s
(`TreasuryReceiptBridge.php:1626-1637`), so the leg never reaches the new `writePosRevenueAndVatLines()`. The
cancellation shape is a single hand-written debit:

```php
CancellationShape::PosRevenue => [[
    'account_id' => $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue)->id,
    'partner_id' => null,
    'amount' => $amount,                      // the GROSS instrument face value
    'description' => 'POS revenue reversed',
]],
```

Before this lane both sides were gross and cancelled out. After it, the sale credits `70x` NET and `4457` per rate
while the cancellation debits `70x` GROSS — asymmetric by exactly the VAT, and `4457` is left permanently
overstated for every cheque/effet-tendered POS refund. The DGI declaration nets that refund
(`EloquentVatDataRepository.php:147-149`, `receipt_type='return'` → `-ABS(vat_amount)`), so books and filing diverge
again on precisely the transaction this lane exists to fix.

**Proven by execution.** `PosBridgeInstrumentRefundTest` fixture re-pointed to a VAT-bearing receipt
(total 10.00 = net 8.00 + VAT 2.00 @ 25 %, everything else untouched), lane HEAD, sqlite:

```
PROBE revenue Dr=10.000 Cr=8.000 netCr=-2.000 | VAT Dr=0.000 Cr=2.000 netCr=2.000
1) ...::test_same_day_check_refund_cancels_the_original_instrument_without_cash
   revenue must be flat after sale+refund — Failed asserting that 1 is identical to 0.
```

The shipped test at `:197` asserts `revenueDebit == revenueCredit` and passes ONLY because its payload carries
`'vat_total' => '0.00'` (`:483`) — a fixture that describes a receipt the lane's own refusal arm would call
`isVatFree`. The assertion is real; the fixture makes it blind.

**Fix before merge.** Route the maturity cancellation through the same decomposition: resolve the refund receipt's
`PosRevenueVatSplit` for that leg and post `Dr 70x net + Dr 4457 per sealed rate / Cr <portfolio>` via
`writePosRevenueAndVatLines(onDebitSide: true)`, instead of the single gross `ProductRevenue` debit at
`GeneralLedgerService.php:3327`. Add a VAT-bearing arm to `PosBridgeInstrumentRefundTest` (keep the existing 0 %
case) asserting both revenue AND `4457` flat after sale+refund. If the shape must stay gross for a reason I cannot
see from the code, it needs an explicit owner ruling recorded in the LEDGER, not silence — the current state is a
wrong-money ledger on a supported tender.

### [IMPORTANT] F-2 — census reports 2×/3× the sealed VAT for any receipt with more than one journal entry (cartesian join)

`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:81-101`

`pos_receipts` ⨝ `pos_receipt_vat_details` ⨝ `journal_entries` multiplies the VAT rows by the entry count, and
`SUM(pos_receipt_vat_details.vat_amount)` (`:99`) is computed over that product. The POS books **one entry per
tender leg**, so every split-tender receipt is misreported.

**Proven by execution** (probe copy of `PosReceiptVatLegCensusCommandTest`, 3 sealed rates summing 90.000, two
pre-W4-9 entries):

```
POS output-VAT leg census: 1 receipt(s) carry sealed VAT but no `vat_collected` journal line.
T001-...-00000001  posted=...  sealed_vat=180 TND  pos_entries=2  receipt_id=...
```

180 where the sealed fact is 90.000. The handback's wave-4 run (`pos_entries=1` on both rows) happened to avoid it.
Detection is unaffected (the `COUNT(...)=0` predicate is multiplicity-invariant) — the **money figure** an operator
reads off the deploy check is wrong. Fix: aggregate the sealed VAT in a subquery/derived table keyed on
`receipt_id` and join that, or select it as a scalar sub-select. (Cosmetic rider: `sealed_vat` is printed raw —
`180` on sqlite, `180.000` on PG. Format it at the currency scale.)

### [IMPORTANT] F-3 — census false-negative: a receipt booked with a VAT leg on SOME legs reports clean

`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:92` (`havingRaw('COUNT(journal_lines.id) = 0')`)

A receipt whose leg 0 carries a `4457` line and whose leg 1 does not has `COUNT > 0` and is silently excluded.
**Proven by execution**: fixture with one entry `withVatLeg: true` and one `withVatLeg: false` → `exit 0`, no output.
That state is exactly what a mid-deploy cutover or a partially-replayed multi-leg receipt produces, which is the
population this command exists to find. Fix: compare per receipt `SUM(journal_lines.credit − debit on VAT accounts)`
against `SUM(sealed vat)` and flag any inequality, rather than testing for the absence of a line.

### [IMPORTANT] F-4 — the transaction-discount case is untested (the brief required it) and the ledger base ≠ the declaration base

`apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:137-147` · `apps/api/tests/Feature/Treasury/PosReceiptVatGlSplitTest.php:618`

The device seals `subtotal + vat_total == total + transaction_discount_amount` — i.e. the sealed VAT is computed on
the **pre-discount** base. The lane's whole test matrix pins `'transaction_discount_amount' => '0.000'`
(`PosReceiptVatGlSplitTest.php:618`), so no discounted receipt is exercised anywhere.

**Proven by execution** (probe: subtotal 600.000, sealed VAT 90.000, discount 50.000, tender 640.000):

```
PROBE discount: cashDr=640.000 revenueCr=550.000 vatCr=90.000 | sealedBase(subtotal)=600.000
```

The VAT leg is right (it is the sealed figure). But the revenue credit silently absorbs the discount — no
contra-revenue / discount-granted line — and the ledger's implied base (550.000) does not match the DGI
declaration's `base_amount` for the same receipt (600.000, `EloquentVatDataRepository.php:146-148`). The handback's
claim that the books and the filing now agree holds for the VAT amount only. Fix: add the discount case to
`PosReceiptVatGlSplitTest`, and record the base divergence as an explicit owner question (is the TN taxable base
pre- or post-transaction-discount?) rather than leaving it implicit in a derived subtraction.

### [MINOR] F-5 — dead `ProductRevenue` lookup left behind in `createPOSPaymentEntry`

`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3698-3699`

```php
// Get revenue account by system purpose
$revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);
```

Assigned and never used after the refactor (`writePosRevenueAndVatLines()` resolves its own at `:3789`). It costs a
query per leg and keeps a stale entry in the provisioning ratchet. Delete it.

### [MINOR] F-6 — `PosVatRateAllocation::$taxCategory` can only ever be null

`apps/api/app/Modules/Accounting/Domain/Services/PosReceiptVatAllocator.php:213,225,236` ·
`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1413-1421`

The allocator selects and carries `pos_receipt_vat_details.tax_category`, but `writeVatBreakdown()` never writes
that column (it writes id/receipt_id/tax_rate/net/vat/gross only) and nothing downstream consumes the field. Either
drop it from the DTO/query or have the projector seal the canonical `tax_category_code` into it.

### [MINOR] F-7 — the handback understates the `ProvisioningRequiredPurposesV1` regen

`docs/superpowers/reviews/2026-08-24-w49-handback.md:267-279` (worktree copy)

Verified from the ratchet's own diff: the regen must also **remove**
`GeneralLedgerService.php:3642|GeneralLedgerService::createPOSRefundReversalEntry|getAccountByPurpose|ProductRevenue`
and **re-pin** `createPOSPaymentEntry` `3558 → 3699`, not just add the two `writePosRevenueAndVatLines` sites.
Not a blocker (the ratchet is inherited-red either way, see below) but the follow-up ticket must say so.

### [MINOR] F-8 — the only exercise of the legacy `ReceiptPaymentService` split is CI-unreachable and 0 % VAT

`apps/api/tests/Feature/POS/ReceiptPaymentServiceToleranceTest.php:399-418` ·
`apps/api/tests/feature-lane-manifest.json` (`feature-lane-pos/POS`, `runs_on_pr_dev: false`, `execution_gate: vars.SELF_HOSTED_RUNNER_READY`)

The lane wires `PosReceiptVatAllocator` into `ReceiptPaymentService` and pins it with a fixture that seals a single
`0.00 %` / `vat_amount '0.000'` row — the `vatFree()` path. The VAT-bearing behaviour of that path is unasserted,
and its lane does not run on PR→dev. The route is retired (410), so this is a ledger note, not a blocker.

---

## Verified — claims I checked and found true

| Item | How | Result |
|---|---|---|
| Red proof | independently reproduced: `git checkout dev -- {GeneralLedgerService,TreasuryReceiptBridge,ReceiptPaymentService}.php`, ran `PosReceiptVatGlSplitTest`, restored | **6 of 7 fail** on dev's tree with the handback's exact deltas (`600.000`→`690.000`, `90.000`→`0.000`, refusal absent); the 0 % test passes on dev, correctly |
| New tests, sqlite | `PosReceiptVatGlSplitTest` + `PosReceiptVatAllocatorTest` + `PosReceiptVatLegCensusCommandTest` | **OK (19 tests, 65 assertions)** |
| New tests, PostgreSQL | same three, throwaway `autoerp_test_w49g` @ `127.0.0.1:5433` | **OK (19 tests, 65 assertions)** — matches the handback's 19 |
| Affected set, PostgreSQL | the ten bridge/POS files | **111 tests, 457 assertions, 1 error** — `ReceiptPaymentServiceToleranceTest:296`, `pos_shifts_closed_logic` CHECK (fixture closes a shift with a null `closing_balance`). Untouched by the lane, unrelated to VAT. **Inherited** |
| JE shape, 3 rates | test + read of `GeneralLedgerService.php:3717-3736,3778-3820` | `Dr 53 690.000 / Cr 70x 600.000 / Cr 4457 7.000+26.000+57.000`; VAT read from the sealed rows, net by SUBTRACTION (`PosReceiptVatAllocator.php:159`), balanced by construction, `assertReconciles()` runs before the first line insert (`:3693`, `:3857`) |
| Sealed rates never recomputed | `PosReceiptVatAllocator.php:207-241` | reads `pos_receipt_vat_details` through the query builder (deliberately not the possibly-stale relation), ordered `(tax_rate, id)`; the class only adds and subtracts |
| Split tender exactness | `PosReceiptVatAllocator.php:271-313` + test | truncating division at scale, intermediates at `scale+4`, residual on the largest leg (first on tie) → Σ per-leg == sealed per-rate exactly; each per-leg entry balances |
| Rounding leg unchanged | test `:299-339` | `Dr 53 690.005 / Cr 70x 600.005 / Cr 4457 90.000` + `Dr 70x 0.005 / Cr 7580 0.005`; revenue nets to 600.000, `4457` untouched by the till rounding |
| Tolerance leg unchanged | `GeneralLedgerService.php:3573-3641` | `Dr 658 / Cr 70x`, VAT untouched → revenue lands on `total − VAT` when composed with the split. Correct |
| Stamp duty | grep across `app/` | `SalesStampDutyPayable` is document-arm only (`AccountingService`); **no POS stamp-duty leg exists**. Handback's claim is accurate — nothing to preserve |
| Zero-VAT (exempt) receipt | test `:345-370` + `PosRevenueVatSplit::vatFree()` | whole tender to revenue, **no 0.000 `4457` line**; `VatCollected` is resolved only when a rate carries money (`:3801-3806`), so an exempt sale posts on a chart without the account |
| Sealed-vs-`tax_amount` disagreement | `PosReceiptVatAllocator.php:119-125` + `PosReceiptVatAllocatorTest` | typed `SealedVatDisagreesWithReceipt` refusal — refuses rather than picking a winner. Correct posture |
| Refunds mirror | test `:255-293` | `Cr 53 690.000 / Dr 70x 600.000 / Dr 4457 per rate`; sale+refund leaves cash, revenue and `4457` all flat, through the SAME writer (`$onDebitSide`) |
| Refund/void model | `TreasuryReceiptBridge.php:443-445`, `PosCoreReceiptProjection::resolveReceiptType:642-649` | no parallel refund event invented; `$isRefund` reads `invoice_type_code ∈ {REFUND,VOID}` and REFUND/VOID always projects `receipt_type = return`, which is exactly what the declaration nets on (`EloquentVatDataRepository.php:147-149`) — ledger and filing agree on the netting predicate |
| Design (a): lazy split | `TreasuryReceiptBridge.php:459-477`, `:1481`, `:1490` | `$resolveVatSplit($index)` is invoked ONLY inside the create branch, AFTER the cross-tenant `method_code` gate (`:1219-1226`), the repository resolution (`:1314-1331`) and the `gl_account_id` gate. A VAT refusal cannot mask a security refusal. Bonus: a replayed leg (`$existing`) never reaches the allocator, so redelivery idempotency is intact |
| Design (b): refuse only if `tax_amount > 0` | `FiscalPayloadConstraintValidator.php:1336-1338`, `:1415-1442`; `PosCoreReceiptProjection.php:453-468` | `vat_breakdown` must have ≥1 row and `Σ vat_amount == vat_total == pos_receipts.tax_amount`, for EVERY `event_version`; the receipt row and its VAT rows are written in one transaction (the conflict path returns before either). Device side agrees (`SaleReceiptPayload.ts:139-147`). A taxable receipt with zero sealed rows is unreachable through the normal ingest — the design is sound, not a silent VAT-free fallback |
| Sealed bytes / immutability | `git diff dev...HEAD` | no migrations; no writes to `pos_receipts`, `canonical_bytes`, `fiscal_hash`, `previous_hash`, `vat_breakdown_hash`; the census is `SELECT`-only. No fiscal Event class renamed/restructured (rule 8 clean) |
| Precision (rule 19) | PHPStan L8 on all 9 touched production files | **No errors.** bcmath throughout, no float/`(float)`; scale passed explicitly — `getScale((string) $receipt->currency)` at `GeneralLedgerService.php:3755`; the allocator takes the scale as an argument. **No no-arg `getScale()` reachable from the projection** (rule 20) |
| Rule 13 | constructor `private readonly` injection in `TreasuryReceiptBridge:197`, `ReceiptPaymentService:80` | no `app()` in production code |
| Projection tests clear context | `PosReceiptVatGlSplitTest:162,184,219,258,316,355,382` | `CompanyContext::clear()` before every `apply()`. Real projector + real bridge, no mocks, `RefreshDatabase` |
| `unit_price` TTC trap | `PosReceiptVatGlSplitTest:579` sets `unit_price` TTC; assertions are aggregate-only | no per-line `line_subtotal == unit_price×qty` assertion anywhere. Clean |
| Manifest | `php tools/feature-lane-manifest-check.php` | **EXIT=0**, 1404 Feature classes / 74 groups. The 3 new classes land in `treasury-spine-pgsql/feature-{treasury,accounting}` — whole-directory selectors, `runs_on_pr_dev: true`, **no `execution_gate`**. "No named raises" verified |
| Deptrac | `php tools/deptrac-ratchet.php` | **PASS**, 182/182 held, no boundary regression |
| Provisioning ratchet | ran both tests on the lane AND on dev's main checkout | **2 failures on both** — genuinely INHERITED, count unchanged. `VatCollected` is already classified `REQUIRED` (`ProvisioningRequiredPurposesV1.php:39`), so the new hard dependency is already a provisioning guarantee. Regen must add `GeneralLedgerService.php:3789|writePosRevenueAndVatLines|getAccountByPurpose|ProductRevenue` and `:3806|…|VatCollected` — plus the removal/re-pin in F-7 |
| Census on the wave-4 tenant | not re-run (needs the live tenant DB); logic read + probed | shape and exit codes correct; report-only, no write path of any kind; `exit 1` on drift, `0` on clean. The 2-receipt / 52.000 + 2.800 output is consistent with a single-entry-per-receipt tenant (see F-2 for when it stops being) |
| LEDGER C-7 | `git diff dev...HEAD --stat` | `PosCoreReceiptProjection` untouched; `PosCoreReceiptProjectionTest` is genuinely outside the blast radius. Its known PG nondeterminism does not enter this gate |

---

## What must change before merge

Fix **F-1** — post the maturity-instrument POS refund through `writePosRevenueAndVatLines(onDebitSide: true)` so it
debits `70x` NET + `4457` per sealed rate instead of `70x` GROSS, and add a VAT-bearing fixture to
`PosBridgeInstrumentRefundTest` (its current 0 % fixture is what let the asymmetry through). Then fix the census
join (**F-2**) and its all-or-nothing predicate (**F-3**), and add the transaction-discount case (**F-4**) with an
owner note on the ledger-base vs declaration-base divergence. F-5..F-8 can ride the same round.
