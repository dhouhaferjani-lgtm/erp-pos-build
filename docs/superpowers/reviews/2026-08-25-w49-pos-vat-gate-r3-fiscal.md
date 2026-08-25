# W4-9 gate r3 — fiscal-pos + GL-projection lens (verify-only)

**Lane** W4-9 (P0) `fix/campaign-w49-pos-vat-gl` · worktree `.worktrees/w49-pos-vat-gl` · HEAD `4e561d2f3`
(fix round r2 `9efe74f39` R2-1..R2-5, `4e561d2f3` PG-portability + handback; merge dev `349dc5a56` ← dev `31a743ff1`)
**r2** `docs/superpowers/reviews/2026-08-25-w49-pos-vat-gate-r2-fiscal.md` · **r1** `…2026-08-24-w49-pos-vat-gate-r1-fiscal.md`
**Reviewer** fiscal-pos-reviewer, adversarial, code-grounded, verified **BY EXECUTION** on sqlite AND a throwaway
PostgreSQL `autoerp_test_w49g3` (`127.0.0.1:5433`).

**Lane state after review: UNTOUCHED.** `git status --porcelain` on the lane worktree = **0 lines**, HEAD still
`4e561d2f32a744e8259ccaad063086f631425c94`. Every tamper and probe ran in a temp `git worktree add --detach 4e561d2f3`
under the scratchpad with a hard-linked `vendor/` (verified `$baseDir = dirname($vendorDir)` resolves to the temp
tree, `vendor/composer/autoload_psr4.php:5-6`). The temp worktree was **removed and pruned**;
`autoerp_test_w49g3` was **DROPPED**. Never more than one test process at a time; the full suite was never run.

---

## VERDICT

**spec ✅ · quality APPROVED · merge-blocking: NO**

**Manifest value at merge: 1411 Feature classes / 74 groups, EXIT=0.**

All five r2 findings are closed, and closed by the right mechanism. **R2-1 — the Critical — is genuinely fixed
and not over-fixed**: the receipt-level guard now compares the sealed VAT against `tender + discount`, which is
exactly the gross base the device's own signing invariant pins (`subtotal + vat_total == total +
transaction_discount_amount`, `SaleReceiptPayload.ts:193-201`); the 620/70 receipt books
`Dr 53 70 / Dr 709 620 / Cr 70x 600 / Cr 4457 90`; and a genuinely inconsistent sealed row — VAT 90.000 against
tender 10.000 + discount 50.000 — **still refuses**, which I proved with a fresh probe rather than trusting the
existing zero-discount case. **R2-2** is closed and I proved both directions: the correctly-booked
instrument-tendered refund reads clean, and one whose cancellation entry has no `4457` line is still flagged.
**R2-3/R2-4/R2-5** are closed.

The **PG-only comp failure** the lane hit and worked around is **real, pre-existing on dev, and out of this
lane's scope**. I reproduced it end-to-end on PostgreSQL and located it precisely (below). The lane's decision
to pin the comp cases at the **allocator** rather than end-to-end is **correct** — it is the only place W4-9's
own guard can be exercised while the POS-core/schema gap stands, and pinning it end-to-end would have made
W4-9's gate hostage to a defect it did not cause. It is filed here as a separate ruling, not a W4-9 blocker.

Three new findings, all **Minor**, none merge-blocking: two diagnostic-message inaccuracies in the census/
allocator and one bounded attribution observation. Nothing CRITICAL was introduced. Sealed bytes, hash chain and
event immutability are re-proven untouched.

---

## 1. R2-1 — CLOSED (was Critical)

`apps/api/app/Modules/Accounting/Domain/Services/PosReceiptVatAllocator.php:129-146`

```php
$grossBase = bcadd($tenderTotal, $declaredDiscount, $currencyScale);
if (bccomp($vatTotal, $grossBase, $currencyScale) > 0) {
    throw PosVatProjectionRefusedException::vatExceedsTender($receiptId, $vatTotal, $grossBase);
}
```

The base is now symmetric with the arithmetic F-4 introduced at `:186` (`net = amount + legDiscount − legVat`),
and with the device's signed aggregate invariant. `PosVatProjectionRefusedException.php:52-56, :63-72` re-words
the message to `gross_base=` and documents why.

| Check | How | Result |
|---|---|---|
| The 620/70 shape books the r2-specified entry | `PosReceiptVatGlSplitTest::test_a_discount_larger_than_the_net_subtotal_still_books` (`:445-472`) — asserts `Dr 53 70.000`, `Dr 709 620.000`, `Cr 70x 600.000`, ledger VAT-by-rate == **sealed** VAT-by-rate, and debits == credits | **GREEN on sqlite AND PostgreSQL** |
| It is a real fix, not a test that would pass anyway | TAMPER: `$grossBase = $tenderTotal;` in the temp worktree | **RED — 4 errors.** `…:vat=90.000:gross_base=70.000` at `PosReceiptVatAllocator.php:145` ← `TreasuryReceiptBridge.php:474 ← :1491 ← :481 ← :322`. Both allocator comp cases, the multi-leg comp case, and the end-to-end 620/70 case all fail |
| Comps split at the allocator | `PosReceiptVatAllocatorTest::test_a_hundred_percent_comp_is_split_not_refused` (`:117-146`) — tender `0.000`, discount `690.000` → `netRevenueAmount 600.000`, `totalVat 90.000`, `assertReconciles()` | **GREEN both drivers** |
| No-discount behaviour unchanged | The pre-existing `test_vat_above_the_retained_tender_is_refused_rather_than_booked_as_negative_revenue` (`:226-237`, discount `0.000`, tender 10 vs VAT 19) | **GREEN** — with `$declaredDiscount = 0` the new expression is identically `$tenderTotal` |
| **The guard still refuses genuinely inconsistent sealed rows** | **NEW PROBE** written for this gate: sealed VAT `90.000`, tender `10.000`, **discount `50.000`** → gross base `60.000` | **REFUSED**, `PosVatRefusalReason::VatExceedsTender`, message contains `gross_base=60.000`. The fix loosens the guard by exactly the discount and not one unit more |
| No new hole opened | `$declaredDiscount` is read from `pos_receipts.discount_amount`, which the PG CHECK `pos_receipts_totals` ties to `total/subtotal/tax_amount`; the per-leg negative-net guard at `:187-189` is unchanged and still fires | A fabricated discount cannot smuggle VAT past both |
| Refund arm unaffected | The device refuses to refund a receipt with a non-zero transaction discount (`RefundReceiptV4Payload.ts:164-176`), so a refund's `discount_amount` is `0` and `grossBase == tenderTotal` | Unchanged |

## 2. R2-2 — CLOSED (was Important)

`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:64-74` (`INSTRUMENT_SOURCE_TYPE`), `:154-183`
(the `original` join + `instrument_ledger` `leftJoinSub`), `:190-195`, `:263-291`.

The path is exactly the one r2 specified: refund receipt → `pos_receipts.original_receipt_id` → the **original's**
`payments` (`origin = pos`, `fiscal_event_id` not null) → `payment_instruments.payment_id` → `journal_entries`
with `source_type='instrument'` → `journal_lines` on the `vat_collected` accounts, grouped by
`payments.fiscal_event_id` and joined to `original.fiscal_event_id`. Credit/debit and entry count are summed in
alongside the `pos_receipt*` arms at `:267-291`.

| Check | How | Result |
|---|---|---|
| The false positive is gone | `PosBridgeInstrumentRefundTest::test_the_vat_leg_census_reports_an_instrument_tendered_refund_as_clean` (`:301-325`) — applies sale + refund, runs `pos:census-vat-legs`, asserts exit **0** | **GREEN on sqlite AND PostgreSQL** |
| It was a real defect | TAMPER: dropped the two `instrument_*` terms from `:267-291` | **RED**, reproducing r2's finding verbatim: `sealed_vat=2.00  ledger_vat=0.00 … pos_entries=0` / `Breakdown: 1 never reached the GL` |
| **A genuinely unbooked instrument refund is still flagged** | **NEW PROBE**: same fixture, then delete the single `vat_collected` journal line from the instrument cancellation entry (the pre-F-1 gross-reversal shape) | **FLAGGED** — census exit **1**. Detection is intact; the fix did not blanket-whitelist the instrument arm |
| No fan-out onto the sale | Sale rows have `original_receipt_id IS NULL`, so the `original` left-join and therefore `instrument_ledger` are null for them | Sale keeps its own credit; refund gets the debit |
| Command is still read-only | `grep -nE 'update|insert|delete|DB::statement|->save\('` over the file → none | SELECT-only, as r2 verified |

## 3. RULING — the PG-only comp failure is a **pre-existing, out-of-lane Critical**

**Not merge-blocking for W4-9.** Reproduced by restoring the assertion `4e561d2f3` removed and running it on
PostgreSQL in the temp worktree:

```
SQLSTATE[23514]: Check violation: new row for relation "pos_receipt_payments"
violates check constraint "pos_receipt_payments_amount"
DETAIL: Failing row contains (…, CASH, 0.000, …)
```

Stack, verbatim: `PosCoreReceiptProjection.php:1503` ← `:1455` (`writePayments`) ← `:469` ← `DB::transaction` at
`:245`. **The same probe is GREEN on sqlite** — the CHECK is inside the `pgsql`-only branch of the migration.

The gap, end to end:

| Layer | Evidence | Says |
|---|---|---|
| Device totals | `apps/pos/src/lib/payment/cartTotals.ts:64-66`, `:71` | The transaction discount is **clamped to the gross subtotal**, so a full comp yields `total = 0` |
| Device tender leg | `apps/pos/src/pages/HomePage.tsx:1313` | Quick cash is a single leg for the **exact total** — `0.000` on a comp |
| Device payload | `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:356-368` (`buildPayments`) | Maps every leg verbatim; **no zero filter** |
| Server payload contract | `FiscalPayloadConstraintValidator.php:1322-1324` | `payments[]` **must have >= 1 row** — a comp cannot omit the leg |
| Server payload contract | `FiscalPayloadConstraintValidator.php:2463-2524` (`validatePayment`) | `amount` is checked for **format and scale only — there is NO positivity check** |
| Server schema | `database/migrations/tenant/2026_01_08_190640_create_pos_receipt_payments_table.php:62` | `ALTER TABLE pos_receipt_payments ADD CONSTRAINT pos_receipt_payments_amount CHECK (amount > 0)` — **PostgreSQL only** |
| Server projection | `PosCoreReceiptProjection.php:1454-1456`, `:1503` | Inserts **every** leg, zero included |

So the fiscal contract **accepts and seals** a payload the projection can **never** persist on the production
driver. The receipt is signed and hash-chained on the device; `PosCoreReceiptProjection::apply()` then throws
inside its `DB::transaction` (`:245`) — no `pos_receipts` row, no lines, no VAT details, no payments, no stock
movement, forever, on every redelivery. That is the rule-20 silently-dropped-row shape at the POS core.

> **[CRITICAL] G3-A (out-of-lane, pre-existing on dev) — a zero-amount tender leg is a valid sealed SALE_RECEIPT
> that `pos_receipt_payments CHECK (amount > 0)` makes unprojectable on PostgreSQL.**
> `FiscalPayloadConstraintValidator.php:1322-1324` + `:2463-2524` (accepts) vs
> `2026_01_08_190640_create_pos_receipt_payments_table.php:62` (forbids) vs
> `PosCoreReceiptProjection.php:1454-1456`, `:1503` (inserts unconditionally, inside the `:245` transaction).
> Device side: `cartTotals.ts:64-66,:71` → `HomePage.tsx:1313` → `SaleReceiptPayload.ts:356-368`.
> **Needs its own lane + an owner ruling** on which side gives: forbid a zero leg at the payload contract
> (breaks 100 %-comps outright, and is a versioned-payload change), or skip/allow zero legs in
> `pos_receipt_payments` (contract change to the `payment_methods_hash` feed). **Cannot verify** whether the
> cashier UI actually permits confirming a `0.000` total — I did not find a positive-total confirm gate, but I
> also did not prove its absence, so treat production reachability as **open**, not established.

**W4-9's placement is right.** The lane touches neither the projection nor the migration
(`git diff 96525a55e...HEAD -- app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` = empty;
`git diff 349dc5a56..HEAD -- database/migrations` = empty), so the failure is dev behaviour, not lane behaviour.
The comp is a **guard-level** concern for W4-9 — the question W4-9 owns is "does the allocator refuse a comp",
and `PosReceiptVatAllocatorTest:117-146` answers it on both drivers with the refusal RED-proved by tamper. The
removed end-to-end assertion would have added no W4-9 coverage; it would only have imported G3-A's failure into
W4-9's gate. The in-test comment at `:126-133` states the reason honestly and points at the handback residual.

## 4. Sealed bytes, chain, gates

| Item | How | Result |
|---|---|---|
| **New/affected tests, sqlite** | `PosReceiptVatGlSplitTest` + `PosReceiptVatAllocatorTest` + `PosReceiptVatLegCensusCommandTest` + `PosBridgeInstrumentRefundTest` | **OK (36 tests, 155 assertions)** |
| **Same, PostgreSQL** | throwaway `autoerp_test_w49g3` @ `127.0.0.1:5433` | **OK (36 tests, 155 assertions)** — identical, no driver divergence |
| **Sealed bytes / hash / immutability, RE-PROVEN** | `git diff 349dc5a56..HEAD -- apps/api/app \| grep -E 'canonical_bytes\|fiscal_hash\|previous_hash\|vat_breakdown_hash\|->update\(\|->insert\(\|DB::statement\|->save\(\|->delete\('` on added lines | **Zero matches.** Zero migrations in the fix round and in the whole lane. `app/Modules/Fiscal` and `PosCoreReceiptProjection` untouched across `96525a55e...HEAD`. No fiscal Event class renamed/restructured (rule 8 clean) |
| Rule 20 — named queues | `git diff 349dc5a56..HEAD \| grep -c onQueue` | **0.** No `horizon.php` change needed |
| Rule 20 — no-arg `getScale()` | `git diff 349dc5a56..HEAD \| grep -E '^\+.*getScale\(\)'` | **None.** The census constructor-injects the resolver and calls `getScaleSafe((string) $row->currency, 3)`; the allocator takes `$currencyScale` as a parameter and consults no `CompanyContext` |
| Rule 20 — projection tests clear `CompanyContext` | Both new `PosReceiptVatGlSplitTest` cases call `app(CompanyContext::class)->clear()` before `apply()` (`:462`, and the removed comp case did too); `PosBridgeInstrumentRefundTest:137-138` clears for the class | Cleared |
| Rule 19 — precision | PHPStan level 8 on the 3 touched production files | **[OK] No errors.** No `(float)`/`parseFloat`/`number_format` in the diff; bcmath throughout; `apportion()` intermediates at `scale+4` (`:336`) |
| Rule 13 | `PosReceiptVatLegCensusCommand.php:68-73` | Constructor `private readonly`; no `app()` in production code |
| `unit_price` TTC trap | Read every new assertion | All aggregate-level. No per-line `line_subtotal == unit_price×qty − discount` anywhere |
| Refund/void model | `PosBridgeInstrumentRefundTest`, census `:154-183` | No parallel refund event invented; refund/void remains a `SALE_RECEIPT` with `invoice_type_code REFUND/VOID` and an `original_receipt_id` |
| Idempotency under redelivery | `TreasuryReceiptBridge.php:1649-1651` (already-`Cancelled` instrument returns before the allocator); `$resolveVatSplit` only in the create branch | Intact. The R2-3 change is inside `apportion()`, a pure function of the sealed payload + leg amounts — replay-identical |
| **Pint** | `--test` over all 7 touched files | `{"result":"pass"}` |
| **Deptrac** | `php tools/deptrac-ratchet.php` | **PASS — 183/183 held, no boundary regression** (182 at r2; the +1 came in with the dev merge) |
| **Manifest — lane HEAD** | `php tools/feature-lane-manifest-check.php` | EXIT=0, **1409** classes / 74 groups |
| **Manifest — CURRENT dev `05a074699`** (W2-6 `51e6c1ed0` merged) | same tool, temp worktree at dev | EXIT=0, **1408** classes |
| **Manifest — AT MERGE (recomputed)** | temp worktree: `4e561d2f3` + `git merge 05a074699` → **clean, no conflicts** → tool | **EXIT=0, 1411 classes / 74 groups.** The lane adds exactly its 3 (`Accounting/PosReceiptVatAllocatorTest`, `Accounting/PosReceiptVatLegCensusCommandTest`, `Treasury/PosReceiptVatGlSplitTest`); it does not touch `tests/feature-lane-manifest.json`, so there is no manifest conflict with dev's W2-6 edit. **The handback's 1404 and r2's projected 1409 are both stale — 1411 is the number at merge** |
| **The 3 new classes actually RUN in CI** | `feature-lane-manifest.json` → `Accounting` → `treasury-spine-pgsql/feature-accounting`, `Treasury` → `treasury-spine-pgsql/feature-treasury`; both `"runs_on_pr_dev": true`, selector `./vendor/bin/phpunit tests/Feature/{Accounting,Treasury}` on **real Postgres**, laned directories pick up new classes automatically | Neither new class is parked behind the execution gate nor in the coverage-debt set |
| **`AdvanceReversalGlShapeTest` sqlite-only artifact** | Ran on the lane under **both** drivers | **sqlite: 11/12 FAIL** (`'80'` vs `'80.000'` decimal formatting). **PostgreSQL: 12/12 OK (71 assertions).** Confirmed an sqlite artifact, not a defect — and the manifest lanes it on `treasury-spine-pgsql/feature-treasury`, i.e. CI runs it on PG, where it is green |

---

## Findings (all Minor, none merge-blocking)

### [MINOR] R3-1 — the census breakdown mislabels a booked-but-VAT-less instrument cancellation as "never reached the GL"

`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:176`, `:290`, `:233`

The `entries` arm counts `pos_receipt*` entries **unconditionally** (`:131` — `COUNT(*)` with no `journal_lines`
join), but the instrument arm's `COUNT(DISTINCT journal_entries.id)` at `:176` sits **inside the VAT-filtered
subquery**. So an instrument cancellation entry that exists but carries no `vat_collected` line — precisely the
pre-F-1 gross-reversal shape the census exists to catch — contributes `instrument_entry_count = 0`, `:290`
computes `entryCount = 0`, and `:233` prints *"1 never reached the GL (no pos_receipt entry at all)"*.

**Proven by execution**: my R2-2 negative-control probe (delete the single `vat_collected` line from the
cancellation entry) is flagged — correctly — but reported under "never reached the GL", pointing the operator at
re-provisioning rather than at a correcting entry.

Detection is right and the exit code is right, so this is a **remedy-pointer** defect, not a missed drift — a
narrower version of what R2-2 fixed on the false-positive side. **Fix**: give the instrument subquery an
unfiltered sibling entry count (or join the entry count outside the `whereIn($vatAccounts)` filter) so the two
arms mean the same thing. Ledger note; it can ride any later round.

### [MINOR] R3-2 — the per-leg refusal now says `gross_base` but reports the bare leg amount

`apps/api/app/Modules/Accounting/Domain/Services/PosReceiptVatAllocator.php:186-189`

```php
$net = bcsub(bcadd($amount, $legDiscount, $currencyScale), $legVat, $currencyScale);
if (bccomp($net, '0', $currencyScale) < 0) {
    throw PosVatProjectionRefusedException::vatExceedsTender($receiptId, $legVat, $amount);
}
```

R2-1 re-worded the shared factory to print `gross_base=%s` and "the tender plus the transaction discount"
(`PosVatProjectionRefusedException.php:66-68`), but this call site still passes `$amount` while the base actually
used is `$amount + $legDiscount`. On a discounted split-tender receipt the refusal message will understate the
base an operator is asked to reconcile against. Diagnostic-only — the arithmetic and the refusal decision are
correct. **Fix**: pass `bcadd($amount, $legDiscount, $currencyScale)`.

### [MINOR] R3-3 — `instrument_ledger` attribution is 1:N by construction

`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:160-183`

The subquery is grouped by the **sale's** `payments.fiscal_event_id` and joined to `original.fiscal_event_id`, so
if a sale ever has more than one refund/void receipt naming it as `original_receipt_id`, **every** such row picks
up the **same, whole** instrument VAT figure. I did not find this reachable and I am not claiming it is:
`handleMaturityRefundLeg` requires exactly one `Received` instrument matched on **exact** tendered amount
(`TreasuryReceiptBridge.php:1611-1645`), a v4 refund's `payments[]` is forced to a single CASH leg
(`FiscalPayloadConstraintValidator.php:1333`, `:2532-2542`) so partial refunds do not reach the instrument arm at
all, and the per-line over-refund ceiling at `PosCoreReceiptProjection.php:905-926` bounds how many refunds a sale
can carry. I also confirmed the operator-facing `PaymentInstrumentController::cancel` (`:375-386`) defaults to
`CancellationShape::B2b` (`InstrumentLifecycleService.php:611-617`), which books no `4457` line and is therefore
invisible to this VAT-filtered subquery — so that path cannot fan out either. **Recorded as a bounded residual**
so the next person to widen either the instrument-match rule or the refund-count ceiling re-checks this join.

### Carried from r1/r2 — still open, unchanged

- **F-8** `apps/api/tests/Feature/POS/ReceiptPaymentServiceToleranceTest.php:399-418` — the only exercise of the
  legacy `ReceiptPaymentService` split is 0 % VAT and its lane does not run on PR→dev. Route is retired (410).
  Ledger note.
- **D-1 (Critical, device-side)** — the device seals output VAT on the **pre-discount** base. Unchanged by this
  round and correctly unchanged: W4-9 is the faithful downstream reflection of whatever the device seals. Needs
  its own lane + an owner/fiscal-counsel ruling on the TN taxable base. See r2 §TAX BASE RULING.
- **G3-A (Critical, out-of-lane, NEW this round)** — §3 above.

---

## What to do before merge

Nothing. **Merge W4-9 as is**, re-running the manifest check after the dev merge and recording **1411**. Then
open two lanes off the residual list: **G3-A** (zero tender leg vs `pos_receipt_payments CHECK (amount > 0)`) and
**D-1** (device seals VAT on the pre-discount base) — both need an owner ruling, and G3-A gates whether a
100 %-off comp is a supported POS operation at all.
