# D-1 gate r1 — treasury / GL-correctness lens (verify-only)

**Lane** D-1 (CRITICAL) `fix/campaign-d1-pos-vat-discount-base` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/d1-pos-vat-base`
**Reviewed at HEAD `f9fc06907`** (allocator `a57f1889f`, addendum `064200346`). Handback `docs/superpowers/reviews/2026-08-25-d1-handback.md` §12.
**Sibling** the fiscal lens reviews the device/seal side. Seal bytes, hash-chain and payload-key drift are NOT re-verified here. This lens = **the ledger consequence of a v5 receipt**: JE shape, revenue base, account purposes, treasury bridge, reporting.
**Reviewer** treasury-reviewer, adversarial, code-grounded, verified **BY EXECUTION** on sqlite AND a throwaway PostgreSQL `autoerp_test_d1t` (`127.0.0.1:5433`, `autoerp`/`autoerp_secret`).

> **⚠ THE LANE MOVED UNDER THIS REVIEW.** At review start HEAD was `f9fc06907`; at review end it is **`b974a722f`** ("Merge branch 'dev' into fix/campaign-d1-pos-vat-discount-base") with **6 uncommitted paths** in the lane worktree — `FiscalPayloadConstraintValidator.php`, `TransactionDiscountVatAllocator.php`, `SaleReceiptV5PostRemiseVatBaseTest.php`, `FiscalEventEngine.ts`, `FiscalEventEngine.test.ts`, and a NEW untracked `apps/api/app/Shared/Domain/TransactionRemiseSplit.php`. **None of that is reviewed here.** This verdict pins `f9fc06907`. `TransactionRemiseSplit` in particular is a new `Shared/` domain class touching the remise split — it needs its own pass before merge.
>
> **Lane state from THIS review: UNTOUCHED.** Every probe, tamper and test ran in a temp `git worktree add --detach f9fc06907` under the scratchpad (`d1tre`) with a hard-linked `vendor/`; it was **removed and pruned** (`git worktree list` no longer names it). `autoerp_test_d1t` was **DROPPED** (`SELECT count(*) FROM pg_database WHERE datname='autoerp_test_d1t'` → `0`). Never more than one test process at a time; the full suite was never run. The 6 dirty paths above are another agent's in-flight fix round, not mine — I wrote nothing into the lane.

---

## VERDICT

**spec ✅ · quality APPROVED · merge-blocking: NO**

**The JE-shape ruling is correct, and it is correct for a better reason than the handback gives.** I verified the whole chain by execution on PostgreSQL, both eras, same economic ticket:

| era | `Cr 70x` | `Dr 7097` | P&L turnover | trial balance | movements |
|---|---|---|---|---|---|
| v1..v4 (W4-9 shape) | **569.000** | **50.000** | **519.000** | 640.000 = 640.000 | `in 590.000`, `balance_after 590.000` |
| v5 (D-1 shape) | **524.547** | — (0 lines) | **524.547** | 590.000 = 590.000 | `in 590.000`, `balance_after 590.000` |

(640.000 TTC ticket, 7/13/19 % + exempt, 50.000 remise, TND scale 3. v5 revenue credit `524.547` == `SUM(pos_receipt_vat_details.net_amount)` == the base the DGI declaration reports. Balanced by construction on both.)

Four **Important** findings, none of which makes a number wrong on the server merge; two of them (**F-2**, **F-4**) become live defects the day the POS build ships and must be booked as LEDGER rows before that. Six Minors.

---

## THE JE-SHAPE RULING

### (a) "No 709 for an on-invoice remise" — **CORRECT**, and it removes an inconsistency rather than creating one

The handback argues from the plan comptable: `709 "Rabais, remises et ristournes accordés"` carries RRR granted **hors facture** (by avoir); an RRR granted **on the invoice** is deducted directly from the sale and never recorded there. That is right. But the codebase argument is stronger and the handback does not make it — I checked it because the brief asked me to grep the `SalesDiscount` consumers, and the answer settles the question:

**The B2B document side has always booked an on-invoice discount by netting it into the revenue credit, with no 709 line at all.**

- `apps/api/app/Modules/Document/Domain/Services/DocumentTotalsCalculator.php:36-44` builds `documents.subtotal` from `$line->calculateTotal($scale)`, documented in place as *"the canonical NET line value: gross (qty × unit_price) minus the line discount (percent, else flat amount), before tax."*
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:184-192` credits **exactly that `subtotal`** to `ProductRevenue`, and `:147-210` posts **no `SalesDiscount` line anywhere** in the invoice entry.

Grep confirms it: the ONLY consumers of `SystemAccountPurpose::SalesDiscount` in production code are `GeneralLedgerService.php:4149` (the W4-9 POS tender arm) and `:4569` (`createPOSChargeEntry`, the ACCOUNT_CHARGE arm). No document path resolves it.

So the account of record is this: **W4-9's `Dr 709` was the outlier**, and it was the correct outlier *only* because the device sealed a pre-remise base — it was a compensating entry, not a doctrinal one, and the lane's own comment at `PosReceiptVatAllocator.php:161-170` says exactly that. D-1 removes the thing that needed compensating, so the compensation must go with it, and the POS lands on the same shape the document side already used. Shape **A** is right.

W4-9's predicted shape **C** (`Cr 70x 574.547 / Dr 709 50.000`) balances and yields the same NET turnover (524.547 — I confirmed the arithmetic), but the handback's objection stands: `574.547` is not the pre-remise HT revenue (that is `569.000`; the 5.547 gap is the VAT half of the remise leaking into an HT account) and a **TTC** debit to a revenue-contra account buries that same 5.547 inside `709`. Shape **B** (`Dr 709 44.453`) is defensible but needs a per-rate HT split the read model does not carry, and still books an on-invoice remise in 709. A is both correct and the only shape where every posted figure is a real one.

**Version-awareness rather than replacement is right.** Each era's ledger shape must follow the base its own device sealed; rewriting the v≤4 shape would break the `70x credit == declared base` identity W4-9 established for every receipt already in the field. The forward-only posture is the same one the payload takes.

### (b) Does the P&L then read turnover net of the remise? **Yes — proven, both eras, but they do not agree**

`ProfitLossService` partitions on `accounts.type`, and `7097` was re-typed `revenue` (contra-revenue) by the W4-9 pre-merge round (`database/migrations/tenant/2026_08_25_120000_retype_sales_discount_accounts_as_contra_revenue.php`, `SystemAccountPurpose.php:281`). So both eras report turnover net of the remise. Executed on PG:

```
[v5]  revenue=524.547  expenses=0.000  net=524.547   discount_account_lines=0   TB 590.000/590.000
[v≤4] revenue=519.000  expenses=0.000                cr_70x=569.000 dr_7097=50.000  TB 640.000/640.000
```

Same economic ticket, **turnover differs by 5.547** — the VAT half of the remise, which the pre-D-1 shape buried in a TTC debit to a revenue-contra account. **The v5 figure is the correct one** (turnover HT, net of remise HT). This is a correction, not a regression — but it is also a reporting discontinuity at the rollout date, and nobody has written it down. See **F-3**.

### (c) Reporting that reads 709 to show "remises accordées" — **it silently loses POS remises, and worse, it becomes channel-asymmetric**

No report resolves `SystemAccountPurpose::SalesDiscount` (grep over `app/`, `apps/web/src`, `apps/pos/src`: zero hits outside the GL writer, the census preflight and the seeders). So there is no dedicated "remises accordées" report to break. What DOES read the account is anything account-level — the trial balance, the GL account listing, the P&L revenue block where `7097` now sits.

After the cutover, for the POS SALE_RECEIPT channel, `7097` receives **nothing**: proven — `discount_account_lines=0` on the v5 probe, and a v5 100 %-comp posts **zero journal entries at all** (F-10). Meanwhile `createPOSChargeEntry` (ACCOUNT_CHARGE) is untouched by D-1 — `FiscalEventPayloadRegistry.php:88` still maps `ACCOUNT_CHARGE` to `[AccountChargePayload::class, 1]` — and keeps posting `Dr 709 = transaction_discount_amount` at `GeneralLedgerService.php:4603-4613`. So `7097` does not go empty; it goes **partial**, carrying credit-sale remises only. An accountant reading it as "remises accordées" gets a figure that is neither the whole nor obviously incomplete. That is worse than either extreme, and it is this lane's to declare. See **F-4**.

The remise itself is not lost from the data: `pos_receipts.discount_amount` keeps the ticket figure and `pos_receipt_vat_details.discount_allocated` keeps the per-rate ventilation (PG column comment verified in place on the migrated database). It is only unrecoverable **from the ledger**.

---

## Findings

### [IMPORTANT] F-1 — `pos:census-vat-legs` reconciles VAT only; it is blind to D-1's own defect class

`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:155-250`. The drift arm joins `SUM(vat_amount)` from `pos_receipt_vat_details` against the `vat_collected` credit−debit and reports a mismatch. The handback (`§12`, and the comment added at `:124-126`) is right that this is version-agnostic — D-1 changes neither side's meaning.

But that is precisely the problem: **D-1's defect class is a wrong revenue BASE with a correct VAT.** The P0 this lane closed booked `574.547` against a sealed base of `524.547` while the VAT matched to the millime — and this census would have returned `exit 0` on it. The one deploy gate the fleet is told to trust cannot see the failure the lane exists to prevent, including the failure mode the lane's own new refusal (`SealedBaseEraAmbiguous`) guards against.

**The fix is cheap and era-agnostic**, and I verified the identity holds on both sides by execution:

```
Σ pos_receipt_vat_details.net_amount  ==  (Σ credit − Σ debit) on the ProductRevenue account, over the receipt's POS entries
   v≤4 : 569.000 == 569.000        v5 : 524.547 == 524.547
```

Note it needs `ProductRevenue` **net of** the `SalesDiscount` debit on v≤4 receipts, i.e. compare against the `70x` line alone, not `70x − 709`. The §4.6 rounding entry moves the cash-rounding adjustment between `70x` and `6580/7580` and lands revenue back on `net` (`PosReceiptVatAllocator.php:44-51`), so the identity survives rounding. Add it as a second drift arm with its own exit-code contribution and a `PosReceiptVatLegCensusCommandTest` case per era.

### [IMPORTANT] F-2 — the LIVE server refund route has no transaction-discount guard, and D-1 turns that from harmless into a misdeclaration

`apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1020-1100` recomputes the return's VAT from `$originalLine->line_total` — the **pre-remise** line gross — extracting `net = lineTotal / (1 + rate)` per line, then re-rounding per rate group. `validateOriginalReceipt()` (`:1122-1132`) refuses only a voided original and a return-of-a-return: **there is no transaction-discount refusal anywhere in the class** (grep for `transaction_discount` in the file: zero hits). It writes its own `pos_receipt_vat_details` rows at `:372-376` with **no `discount_allocated`**, so those rows are NULL — pre-remise era, which is internally consistent with what it computed.

Pre-D-1 that was harmless: the sale sealed VAT on the pre-remise line roll-up too, so sale and return agreed. **From the first v5 receipt they diverge.** A full return of the worked example would declare base `500.000` / VAT `71.000` against a sale that declared base `460.938` / VAT `65.453` — the tenant reverses **5.547 TND of VAT it never collected**, on a receipt that feeds the declaration (`EloquentVatDataRepository.php:130-152` includes `receipt_type = 'return'` rows as `-ABS(...)`).

This is not a theoretical route. It is **the** POS refund path: `apps/pos/src/lib/refundFlow/refundSettlementService.ts:481` POSTs `/pos/receipts/${input.serverReceiptId}/return`, and `apps/api/app/Modules/POS/routes.php:215` is live (unlike its 410'd siblings at `:172`, `:207`, `:216`). The only thing standing between it and the divergence is a **client-side** refusal — `WholeReceiptDiscountRefundRefusedError`, thrown at `apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts:314` — i.e. a fiscal invariant enforced only on the device. (I verified the guard exists and reads the original's own `transactionDiscountAmount`; I did not run the refund flow end-to-end, so I cannot confirm it fires strictly before the `apiPost` on every code path — that itself is worth confirming.)

**Not merge-blocking for the server merge** — with no v5 receipts in existence there is nothing to diverge. **Blocking for the POS build rollout.** Mirror the device refusal server-side in `validateOriginalReceipt()` before the first v5 receipt can exist, and pin it. This is the same class of gap as the handback's own "noted, not touched" item, one layer down and on the server.

### [IMPORTANT] F-3 — the turnover discontinuity at the cutover is real, unpinned and undeclared

Proven above: the same ticket reports P&L turnover `519.000` before the cutover and `524.547` after, differing by the VAT half of the remise. Across a fleet the shift is `Σ(remise × rate/(1+rate))` and it lands mid-period, on the day each terminal takes the build — so a month-over-month or year-over-year turnover comparison spanning the rollout is not comparing like with like, and nothing in the lane says so.

Compounding it: **no test pins the v5 P&L face.** `PosReceiptVatGlSplitTest::test_the_profit_and_loss_reports_turnover_net_of_the_discount` (the W4-9 pre-merge test) pins the v≤4 era at `550.000`, and the lane's new `test_a_post_remise_sale_credits_the_sealed_base_with_no_contra_revenue_leg` asserts the JE lines but never reaches `ProfitLossService`. Add the v5 twin (I ran it as a probe; it asserts cleanly) and put one line in the deploy note.

### [IMPORTANT] F-4 — `709x` becomes channel-asymmetric, and the ACCOUNT_CHARGE arm keeps the defect D-1 just fixed

`GeneralLedgerService.php:4550-4640` books `Dr 411 total / Dr 709 discount / Cr 70x subtotal / Cr 4457 vat_total` for ACCOUNT_CHARGE, balancing on the pre-remise identity `total + discount == subtotal + vat`. `FiscalEventPayloadRegistry.php:88` pins `ACCOUNT_CHARGE` at payload version **1**; D-1 touches neither, so a credit sale is a genuinely separate event type and there is no unbalanced-entry risk from the v5 header flip (I checked this specifically — the two never share a payload).

But two consequences follow that the handback's one-line residual understates:
1. **A remise on a credit sale still over-declares VAT** — the taxable base stays pre-remise on that channel. That is the D-1 defect, alive, on the other half of the same POS.
2. **The two POS arms now book the same commercial gesture two different ways**, and `7097` becomes a partial "remises accordées" figure rather than an empty one (see ruling (c)).

Not this lane's to fix — but it must be a named LEDGER row rather than a paragraph at the bottom of a handback, because after the cutover the asymmetry is invisible from either side.

### [MINOR] F-5 — `loadSealedRows()` reads and validates `net_amount`, then never uses it

`PosReceiptVatAllocator.php:268, :280-283, :304` — `net_amount` is selected, numeric-narrowed and put in the row array; nothing in `allocate()` reads `$row['net_amount']`. The obvious use is the guard this class is missing and F-1 wants at the census: `Σ sealed net_amount == Σ derived net` (era-agnostic; the derivation reproduces it exactly in both eras). Either use it or drop the read — a validated-then-discarded field reads like a half-landed idea.

### [MINOR] F-6 — the mixed-era test asserts the exception class, not the reason

`tests/Feature/Accounting/PosReceiptVatAllocatorTest.php:377-390` uses a bare `$this->expectException(PosVatProjectionRefusedException::class)`. Six other reasons throw that same class from the same call (`nonNumericAmount` on four fields, `sealedVatDisagreesWithReceipt`, `vatExceedsTender`), so a future refactor that refuses this fixture for the wrong reason keeps the test green. Assert `PosVatRefusalReason::SealedBaseEraAmbiguous` off the caught exception, as the sibling suites do elsewhere.

Related, and to the lane's credit: I checked whether a mixed set is even reachable. `FiscalPayloadConstraintValidator::validateVatBreakdownRow()` (`:2727-2740`) enforces the key set **exactly** in both directions — v5 rows MUST carry `discount_allocated`, v≤4 rows are refused for carrying it as an extra key — and `validateSaleReceiptAggregateConsistency()` (`:1602-1610`) requires `Σ discount_allocated == transaction_discount_amount` at v5, so a v5 receipt with a non-zero remise cannot have an empty breakdown either. The refusal is therefore defence-in-depth against direct DB tampering or a mis-aimed backfill, not a live path. That is the right posture; the test should still name it.

### [MINOR] F-7 — the DTO docblock still asserts the pre-D-1 premise as unconditional truth

`apps/api/app/Modules/Accounting/Domain/DTOs/PosRevenueVatSplit.php:41-53` still reads *"The device seals `subtotal + vat_total == total + transaction_discount_amount` — the VAT is computed on the PRE-discount base… Booking the discount as an explicit contra-revenue debit (`SalesDiscount`, 709) is what keeps them equal — and it is exactly what the POS's own ACCOUNT_CHARGE arm already does, so the two POS arms book the same sale the same way."* Every clause of that is now era-conditional and the last one is **false** (F-4). This is the docblock on the very field whose meaning D-1 forked; the allocator's own comments were updated and this one was not.

### [MINOR] F-8 — the widened CHECK admits the exact wedge the P0 was about

`2026_08_25_090100_widen_pos_receipts_totals_check_for_post_remise_base_d1.php:65-67`. The disjunction is necessary and the reasoning is sound — I confirmed on PG that the constraint is present **and VALIDATED** (`convalidated = t`) and that its definition is the two-arm form. But state the consequence with the number: shape C satisfies the OLD arm exactly (`574.547 + 65.453 − 50.000 = 590.000`), so the storage backstop can no longer refuse a v5 row carrying a pre-remise-ish base. The tight identity lives only in `validateSaleReceiptAggregateConsistency()`, which is on the **ingest** path; `ReceiptCreationService` — the other writer of these headers — does not go through it, and the CHECK was its only arithmetic guard. The handback says "enforced upstream"; it should say "enforced upstream *for device-authored receipts only*".

### [MINOR] F-9 — new money arithmetic on a bare no-arg `getScale()`

`ReceiptCreationService.php:87-90` — `private function scale(): int { return $this->scaleResolver->getScale(); }`. Pre-existing (the diff does not introduce the call site), but D-1 routes four new money paths through it: `reconcileAggregatesToGross()`, `ventilateTransactionDiscount()`, `sumVatAggregates()` and the new header derivation at `:492-530`. Rule 19 wants the entity currency. **No production exposure today** — the class is unreachable from any live route (all three §14.2 server-authoring paths return 410: `routes.php:172`, `:207`, `:216`, `routes_orders.php:59`), which also means this 169-line change is exercised only by tests. Worth knowing on both counts: the arithmetic is dead in production, and it will throw the day it is not.

### [MINOR] F-10 — a v5 100 %-comp books nothing at all in the ledger, and nothing at the bridge level tests it

Proven on PG by driving a full-comp v5 event through `TreasuryReceiptBridge::apply()`:

```
[PROBE-COMP] applied OK
[PROBE-COMP] journal_entries=0 journal_lines=0 payments=0 movements=0 receipts=1 vat_details=2
```

With `payments[]` empty the allocator returns zero splits (`allocate()` iterates `$amounts`, which is empty), so no entry, no payment, no movement. Financially correct — revenue 0, VAT 0, cash 0, and strictly better than pre-D-1, which could not project a comp on PostgreSQL at all. But the commercial gesture leaves **no GL trace whatsoever** (pre-D-1 it would have been `Dr 709 / Cr 70x`), and the coverage stops short of it: `PosReceiptV5DiscountVatBaseProjectionTest` covers the projection, `PosReceiptVatAllocatorTest::test_a_post_remise_hundred_percent_comp_is_all_zero_not_refused` covers a single **zero-amount** leg, and neither drives the empty-`payments[]` case through the bridge. Add the bridge case (asserting zero entries deliberately) and one deploy-note line.

---

## Item-by-item against the brief

**1. JE shape for v5** — ruled above. Shape A, correct, and consistent with `createFromInvoice`.

**2. Version discriminator, mixed-set refusal, census across eras, v5 refund mirror, balance, bcmath, seeded purposes.**
- **Discriminator.** `PosReceiptVatAllocator::sealedBaseIsPostRemise()` (`:328-346`) reads `discount_allocated` NULL-vs-present. Sound: the exact key sets make the two eras structurally disjoint (F-6), the projector mirrors verbatim (`PosCoreReceiptProjection.php:1419-1432`), and the migration adds the column **nullable with no default and no backfill** — verified on the migrated PG database: `numeric(12,3)`, `is_nullable = YES`, `column_default` empty, plus a `COMMENT ON COLUMN` spelling out the NULL semantics (read back verbatim from `col_description`). That comment is the right defence against a well-meaning `SET discount_allocated = 0 WHERE ... IS NULL`, which would silently re-date every historical receipt's era.
- **Mixed set ⇒ typed refusal.** Probed by tamper: pinning `$isPostRemiseBase = false` produced **5 failures** across the two ledger suites, including the exact wedge `-'524.547' / +'574.547'` on the GL entry and the mixed-era case going green-when-it-must-refuse. Restored; both suites back to green.
- **Census across eras.** Version-agnostic on VAT, as claimed — and blind on the base (**F-1**).
- **v5 refund / instrument cancellation.** The bridge resolves the split from **the refund receipt's own** sealed rows (`TreasuryReceiptBridge.php:473-476`, `:1485/:1491`, `:1643`), and REFUND stays authored at v4, so a refund's rows are always NULL-era. That mirrors correctly **only because a discounted receipt cannot be refunded at all** — the device refuses it, and the server does not (**F-2**). The instrument-cancellation arm takes the same `$resolveVatSplit($index)` and therefore the same era. `PosBridgeInstrumentRefundTest` green on both drivers.
- **Balanced by construction.** `assertReconciles()` (`PosRevenueVatSplit.php:137-165`) checks `net + Σvat − discount == tender` and is satisfied by both eras; the v5 branch reduces it to `net + Σvat == tender`. Trial balance closes on PG in both eras (590/590 and 640/640).
- **bcmath / TND 3.** Zero `(float)`, `floatval`, `number_format`, `round(` or bare no-arg `getScale()` in the added `apps/api` lines (grep over `git diff dev...HEAD`). `TransactionDiscountVatAllocator` is pure bcmath with intermediates at `scale + 4` and an explicit `roundHalfUp()` because `bcdiv` truncates; no hardcoded scale literal in it. The POS side uses `Big.js` strings.
- **Seeded purposes only.** Zero literal `'4457' / '707' / '7097' / '709' / '53' / '411'` in the four new/changed Accounting + POS domain files.

**3. Migration.** `pos_receipts_totals` verified on a real migrated PG database: present, **VALIDATED**, definition is the two-arm disjunction. `NOT VALID` + savepoint-protected `VALIDATE` with a `23514`-only swallow and everything else re-raised — correct fleet-abort containment, same pattern as `2026_07_28_100200`. `down()` deliberately restores the narrow form `NOT VALID` so a rollback cannot fail on rows the forward migration legitimised — right call. Census query is in the docblock. Additive column migration is `hasColumn`-guarded and idempotent. One consequence to state with its number: **F-8**.

**4. Treasury bridge — PROVEN, era-independent.** Probed on PG after applying a v5 discounted receipt:

```
[PROBE] movements=1  dir=in  amount=590.000  balance_after=590.000  je=01a03856-…
[PROBE] repo_balance=590.000
[PROBE] payment_amount=590.000
```

`TreasuryReceiptBridge.php:1457` sets `Payment.amount = $amount` (the retained leg) with no reference to the split; the split touches only the credit side of the GL entry. Cash movement = **gross tender**, `payment_repositories.balance` unaffected by the era. Exactly as required.

**5. Red-proof, PG counts, deptrac, manifest.**
- **RED reproduced independently** (tamper above): 5 failures, reverted to green.
- **GREEN, executed here, one process at a time:** `PosReceiptVatAllocatorTest` **16/50** (sqlite) · the same + `PosReceiptVatGlSplitTest` **29/116** (PG) · all four ledger suites incl. `PosReceiptVatLegCensusCommandTest` and `PosBridgeInstrumentRefundTest` **50/203** on the **dev-merged** tree (sqlite) · `PosReceiptV5DiscountVatBaseProjectionTest` + `SaleReceiptV5PostRemiseVatBaseTest` **19/50** (PG, merged tree). No failures anywhere.
- **Deptrac ratchet: PASS 183/183** on the lane AND on the dev-merged tree.
- **Manifest — verified against CURRENT dev by doing the merge.** A trial `git merge dev` in the temp worktree conflicts on **exactly one file**, `apps/api/tests/feature-lane-manifest.json`. Resolving it as the union (`Fiscal 81` + `POS 156` from the lane, `Inventory 115` from dev, `gated_ceiling 1176`) gives **EXIT=0, 1424 Feature classes / 74 groups**. The lane at `b974a722f` has since resolved it to precisely those four numbers — correct. Taking either side wholesale would leave the checker red.
- **Rule 20.** `PosReceiptVatGlSplitTest` clears `CompanyContext` before every `apply()`; `PosReceiptV5DiscountVatBaseProjectionTest` routes every projection through a `project()` helper that clears first (`:297-301`) — 1 helper, 1 `apply`. No new `onQueue` in the diff, so no `config/horizon.php` change is owed.

**6. "Promote D-1 + W4-9 together" — moot, and the deploy consequence is the opposite of what it sounds like.** W4-9 merged as `dd67a2c3a`; verified an ancestor of both `dev` and the lane. So **W4-9's shape is already live for every receipt today**, and it is the correct shape for every receipt today, because every device is still authoring v≤4. Concretely:

> **Deploy note, verbatim.** *D-1's ledger arm is INERT for device-authored receipts until the POS build ships: with no v5 receipt in existence, `discount_allocated` is NULL everywhere, `sealedBaseIsPostRemise()` returns false, and every tender leg books W4-9's shape (`Cr 70x` pre-remise base, `Dr 7097` remise TTC) exactly as it does on dev today. Ordering is server-first, then the POS build (a v5 payload at a server without this lane is quarantined by `envelope_event_version_mismatch`). Both tenant migrations must run before the first v5 receipt projects. **The day a terminal takes the build, three things change at once for that terminal: `7097` stops receiving its remises, P&L turnover rises by the VAT half of every remise, and a server-side return of a discounted receipt starts reversing VAT that was never declared (F-2 — close this BEFORE the build ships).** `journal_entries` are immutable (rule 8): receipts already posted keep the shape of their own era, which is correct and must not be "fixed". A POS rollback after D-1 is not a supported operation.*

---

## Test quality

Real behaviour throughout: `RefreshDatabase`, real models, real canonical bytes through the real `PosCoreReceiptProjection` + `TreasuryReceiptBridge` (`projectedThreeRateSale()` builds and encodes an actual payload — no faked API responses), `CompanyContext::clear()` before every projection, no `assertTrue(true)`, no `markTestSkipped`, nothing mocked that is under test. The property that matters most is preserved from W4-9: **every VAT assertion compares the ledger against the SEALED rows read back out of `pos_receipt_vat_details`**, and the new v5 test additionally asserts the revenue credit against `SUM(net_amount)` read from the database rather than a literal. The pre-D-1 test is deliberately left intact as the era control — correct, and better than re-pinning it. Gaps: the reason-less mixed-era assertion (**F-6**), no v5 P&L pin (**F-3**), no bridge-level comp case (**F-10**), and no case at all for a v5 receipt reaching `ReceiptReturnService` (**F-2**).

---

## What to fix before merge

Nothing in the server merge produces a wrong number, so **merge-blocking: NO** — but book **F-2** as a LEDGER row that must close *before the POS build ships* (a server-side transaction-discount refusal in `ReceiptReturnService::validateOriginalReceipt()`), add **F-1**'s base arm to the census, and put **F-3**/**F-4**/**F-10** in the deploy note; then re-gate the six in-flight paths at `b974a722f`, `app/Shared/Domain/TransactionRemiseSplit.php` first.
