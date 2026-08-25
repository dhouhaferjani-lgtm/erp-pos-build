# W4-9 gate r1 — treasury / GL-correctness lens (verify-only)

**Lane** W4-9 (P0) `fix/campaign-w49-pos-vat-gl` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w49-pos-vat-gl` · HEAD `4e561d2f3`
**Sibling gate** fiscal-pos lens r1 CHANGES → r2 CHANGES → **r3 APPROVED** (`docs/superpowers/reviews/2026-08-25-w49-pos-vat-gate-r3-fiscal.md`).
Seal/hash/allocator-arithmetic verification is NOT repeated here. This lens = **GL correctness, account purposes, reconciliation, reporting consequences.**
**Reviewer** treasury-reviewer, adversarial, code-grounded, verified **BY EXECUTION** on sqlite AND a throwaway PostgreSQL `autoerp_test_w49t` (`127.0.0.1:5433`), plus read-only psql against the real wave-4 tenant DB.

**Lane state after review: UNTOUCHED.** `git status --porcelain` on the lane worktree = **0 lines**; HEAD still `4e561d2f32a744e8259ccaad063086f631425c94`. Every probe and tamper ran in temp `git worktree add --detach 4e561d2f3` / `871ef63b0` trees under the scratchpad with hard-linked `vendor/`; both were **removed and pruned**. `autoerp_test_w49t` was **DROPPED**. Never more than one test process at a time; the full suite was never run. The wave-4 tenant DB row counts were re-checked after the census run (`journal_lines 56 / journal_entries 26 / pos_receipts 2`) — unchanged.

---

## VERDICT

**spec ✅ · quality APPROVED · merge-blocking: NO**

The core claim of this lane is **true and I proved it independently, end to end, on PostgreSQL**: after the fix the general ledger and the DGI declaration agree on **both the VAT and the base**, per rate, for a posted sale plus a partial refund — and the trial balance still closes.

| Probe (throwaway PG, sale 3 rates 600.000 HT + 90.000 VAT, then a 19 %-only refund 300.000 + 57.000) | Result |
|---|---|
| Trial balance | `total_debit 1047.000 == total_credit 1047.000`, `is_balanced true` |
| Ledger accounts | `53 Caisse` Dr **333.000** · `707 Ventes de marchandises` Cr **300.000** · `4457 TVA collectée` Cr **33.000** |
| GL `4457` net by rate (credit − debit, rate read off the line description) | `{7.00: 7.000, 13.00: 26.000, 19.00: 0.000}` |
| VAT declaration (`EloquentVatDataRepository::aggregateByRateAndDirection`, reads the **sealed** `pos_receipt_vat_details`) | `7.00 → base 100.000 / VAT 7.000` · `13.00 → 200.000 / 26.000` · `19.00 → 0.000 / 0.000` |
| **Books vs filing** | **EXACT AGREEMENT on VAT by rate AND on the base** (Σ declared base 300.000 == P&L revenue 300.000) |
| P&L (`ProfitLossService`) | `total_revenue 300.0000 · total_expenses 0.0000 · net_income 300.0000` |
| Treasury bridge | `repository_movements` = `in 690.000` / `out 357.000`, `balance_after 333.000`, `payment_repositories.balance = "333.000"`, each linked to its own `journal_entry_id` — **gross tender, unchanged in amount by the split** |

Money precision is clean: no `(float)`, `parseFloat`, `number_format`, or bare no-arg `getScale()` anywhere in the added lines (grep over `git diff dev...HEAD`, 0 hits). Every account is resolved by seeded `SystemAccountPurpose` — grep for literal `'4457' / '707' / '7097' / '709' / '53'` in the three new production files returns nothing. `journal_lines.debit/credit` is `numeric(15,3)` on BOTH the fresh migration and the live wave-4 tenant (`\d journal_lines`), so the historic `decimal(15,2)` drift does **not** truncate a TND millime here — the split-tender residual case survives to the DB intact. `PosReceiptVatLegCensusCommand` uses `getScaleSafe($currency, 3)` (`:257`), correct for a console context; no new `onQueue` in the diff, so no Horizon config change is owed.

Four **Important** findings, none of which produce a wrong number today, and four Minors. Two of the Importants (I-1, I-2) are cheap and I would fix them before this ships, because they degrade the very deploy gate this lane introduces.

---

## Findings

### [IMPORTANT] I-1 — `pos:census-vat-legs` flags TRAINING receipts as drift; the deploy gate is red by design on any tenant that trains

`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:115` (base query) vs `apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php:142-143`.

The census reads `pos_receipts` with **no `is_training` / `is_voided` filter**. The declaration it is meant to reconcile against filters both:

```php
->where('r.is_voided', false)
->where('r.is_training', false)
```

A training receipt is **treasury-contained**: it writes its sealed `pos_receipt_vat_details` rows and **no** journal entry. Proven by execution on PG (probe on `4e561d2f3` in a temp worktree):

```
[TRAINING] is_training=true vat_detail_rows=3 pos_entries=0
[TRAINING census] exit=1
  POS output-VAT leg census: 1 receipt(s) whose ledger `vat_collected` does not match their sealed VAT.
  FE-POS813-2026-00000001 … sealed_vat=90.000 ledger_vat=0.000 TND pos_entries=0
  Breakdown: 1 never reached the GL (no pos_receipt entry at all), 0 were booked with a wrong or partial VAT leg.
[TRAINING decl] []     ← the declaration correctly excludes it
```

**Why it matters.** This is the same class of defect the fiscal r2 gate already made this lane fix once (R2-2: a correctly-booked instrument refund reported as "never reached the GL"). Tenant #1 launches POS-first and trains on the terminals during onboarding; the one command an operator is told to trust at deploy time then returns exit 1 forever and points at re-provisioning receipts that are correct by design. An operator who learns to ignore it will also ignore the real drift.

**Fix.** Mirror the declaration's own filters on the census base query at `:115`:
```php
$query = DB::table('pos_receipts')
    ->where('pos_receipts.is_training', false)
    ->where('pos_receipts.is_voided', false)
```
and add a `PosReceiptVatLegCensusCommandTest` case per flag. (Training is proven above; `is_voided` I could not exercise without a void fixture — it is included on symmetry with the declaration and should be pinned by the same test.)

---

### [IMPORTANT] I-2 — two NEW hard chart dependencies on the tender-leg path, with no precheck and no alert; a chart missing either loses the whole receipt, cash included

`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4105` (`SalesDiscount`) and `:4117` (`VatCollected`), both reached from `posRevenueAndVatLineSpecs()`, which every POS tender leg now goes through.

Before W4-9 a POS tender leg resolved **one** purpose (`ProductRevenue`). It now resolves up to **three**. `getAccountByPurpose()` throws a bare `RuntimeException`, inside `TreasuryReceiptBridge`'s `DB::transaction`. Proven on PG by nulling the purpose and replaying:

```
[NO-DISCOUNT-ACCT] RuntimeException: Missing GL account: no account with system purpose 'sales_discount' …
[NO-DISCOUNT-ACCT payments] 0   entries] 0   movements] 0
[NO-VAT-ACCT]      RuntimeException: Missing GL account: no account with system purpose 'vat_collected' …
[NO-VAT-ACCT entries] 0
```

Fail-closed is the right stance for the ledger, but the **blast radius grew**: the receipt now loses its Treasury `Payment`, its `repository_movements` row and its cash balance too, on a device-signed receipt, with no durable alert — just a Horizon job that retries forever. Pre-W4-9 that same chart booked the sale (wrongly, but the cash landed).

The same file already knows the right pattern and does not use it here. `TreasuryReceiptBridge.php:549-566` and `:629-644` precheck their purposes with `hasAccountForPurpose()` and degrade to `recordTolerancePurposeMissingAlertOrFail()` rather than throwing from the depths — with the comment *"Both halves of the entry are prechecked."* The tender-leg arm this lane changed does not.

Compounding it: the deploy gate this lane ships **checks `vat_collected` and not `sales_discount`** — `PosReceiptVatLegCensusCommand.php:99-112` errors out only when no account carries `SystemAccountPurpose::VatCollected`.

**Mitigations verified, so this is Important and not Critical.** All three purposes are classified `REQUIRED` in the frozen manifest — verified by execution, not by reading: `SystemAccountPurpose::requiredPurposes()` returns 28 entries and contains `product_revenue`, `vat_collected` **and** `sales_discount`. The TN chart seeds `4457 → vat_collected` (`TunisiaChartOfAccountsSeeder.php:207`) and `7097 → sales_discount` (`:324`), `BackfillChartPurposesCommand` covers both FR/TN and generic charts, and the real wave-4 tenant carries all three (psql: `4457 / 707 / 7097`).

**Fix (either is acceptable).** (a) Precheck `VatCollected` and — when `discount_amount > 0` — `SalesDiscount` in `TreasuryReceiptBridge::projectPaymentLineFromCanonical()` before the GL call, and route a miss through the existing durable-alert path; or (b) at minimum, add `SalesDiscount` to the census's purpose preflight at `:99` so the deploy gate names the second missing purpose instead of a silent stuck queue.

---

### [IMPORTANT] I-3 — P&L now reports turnover GROSS with the RRR as an operating expense; the PCG presentation of 709x is not honoured

`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:323-324` (`7097`, `'type' => 'expense'`) · `apps/api/app/Modules/Accounting/Application/Services/Reports/ProfitLossService.php:148-158, :218-220`.

`ProfitLossService` partitions strictly on `accounts.type`: revenue = `credit − debit` over `AccountType::Revenue`, expenses = `debit − credit` over `AccountType::Expense`. `7097` is seeded `expense`, so the new contra-revenue line lands in the expense block. Proven on PG (600.000 HT sale with a 100.000 transaction discount):

```
[DISC PL revenue]  707  Ventes de marchandises        300… → 600.000
[DISC PL expenses] 7097 Rabais, remises et ristournes → 100.000
[DISC PL totals]   rev=600.0000  exp=100.0000  net=500.0000
[DISC account]     code=7097 type=expense
```

Net income is correct (500.000) and the trial balance closes. But the face of the statement now reads *chiffre d'affaires 600 / charges 100* where the PCG-TN presentation is *CA net de RRR 500*. Before this lane a discounted POS sale credited the post-discount gross tender to `707` and posted no `7097` line at all, so this presentation is **materially new for the POS channel** — it will now appear on every discounted receipt, not on the rare `createPOSChargeEntry` arm.

The account typing is a pre-existing chart decision (`709` is likewise seeded `'type' => 'expense'` for `SalesReturn`, `:316-317`), and the lane is right to reuse the seeded purpose rather than invent one. But the reporting consequence is this lane's to declare.

**Fix.** Either classify `709x` so the P&L nets it into revenue (a chart/reporting change, out of this lane), or record the consequence explicitly in the lane's deploy note so the first accountant who reads a P&L is not surprised. Do **not** silently absorb the discount into the revenue credit — that would break the base agreement I-4 discusses.

---

### [IMPORTANT] I-4 — owner ruling D-1 (dev tip `871ef63b0`, dated AFTER this lane's merge base) inverts the premise the discount arm is documented and tested on

`apps/api/app/Modules/Accounting/Domain/Services/PosReceiptVatAllocator.php:130-146, :180-186` · `apps/api/app/Modules/Accounting/Domain/DTOs/PosRevenueVatSplit.php:38-51` · `apps/api/tests/Feature/Treasury/PosReceiptVatGlSplitTest.php:351` (`test_transaction_discount_is_a_contra_revenue_line_so_the_ledger_base_matches_the_declaration`).

The lane's justification is explicit: *"the device seals the VAT on the PRE-discount base (`subtotal + vat_total == total + transaction_discount_amount`), and the declaration reports that same base"*, so `net = tender + legDiscount − legVat` makes the `707` credit equal `base_amount`.

Current dev tip `871ef63b0` records: **"RULED 2026-08-25 (owner): D-1 — POS transaction discounts reduce the VAT base, ventilated PRO-RATA per rate line (largest-remainder), sealed POST-discount; forward-only device lane + POS release."** The lane's merge base is `31a743ff1`; a `.worktrees/d1-pos-vat-base` lane exists but carries no implementation yet.

Once the device seals post-discount, the same server code books, for a 600 HT / 100 discount / 500 taxable sale: `Dr 53 575 · Dr 7097 100 · Cr 707 600 · Cr 4457 75`. Still balanced, still bcmath-exact, and net turnover `707 − 7097 = 500` still equals the declared base. What changes is **which** equality holds: today it is *gross `707` credit == declared base*; after D-1 it becomes *net `707 − 7097` == declared base*. The docblocks and the test name pin the first, and nothing will fail when it flips — pre-D-1 and post-D-1 receipts will be booked by the same code path with two different meanings and the census cannot tell them apart.

Not a defect today; not merge-blocking. But it is a coordination hazard between two live lanes.

**Fix.** Add a line to the lane's handback §8 residuals naming D-1 and stating which invariant the discount arm actually relies on (`assertReconciles`: `net + Σvat − discount == tender`, which is version-agnostic), and hand the D-1 lane the requirement to re-pin `PosReceiptVatGlSplitTest:351` when it lands.

---

### [MINOR] M-1 — the legacy `ReceiptPaymentService` path passes a hardcoded scale to the allocator

`apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:238` — `$this->vatAllocator->allocate($receipt, $legAmounts, self::SCALE)` with `private const SCALE = 3` at `:72`. Rule 19 wants `CurrencyScaleResolverInterface::getScale($receipt->currency)`. Consistent with the ~10 pre-existing `self::SCALE` uses in the same method, and the route is retired (410 at `POS/routes.php:216`), so the exposure is nil — but it is a new money call site on a hardcoded scale. Fix: inject the resolver and pass `getScale((string) $receipt->currency)`.

### [MINOR] M-2 — the census's VAT-account list is not company-scoped

`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:98-102` plucks every `accounts.system_purpose = 'vat_collected'` row on the tenant database, while the receipt arms honour `--company` (`:199-204`). On a multi-company tenant a leg booked to company B's `4457` would be counted as satisfying company A's receipt. Fix: apply the `--company` filter to the `accounts` pluck as well.

### [MINOR] M-3 — the census cannot distinguish "chart unprovisioned" from "drift found"

Same file: `:112` returns `self::FAILURE` when no `vat_collected` account exists, and `:238` returns `self::FAILURE` when drift is found. A deploy gate that branches on the exit code gets one signal for two different remedies. Fix: return a distinct code (e.g. `2`) for the unprovisioned-chart arm, and document it in the command description.

### [MINOR] M-4 — the refund reversal's `line_order = 0` moved from revenue to cash; three consumers key on it and none is cited

`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4186-4198` deliberately writes the cash leg first ("Written FIRST so the cash leg keeps `line_order = 0` on both the sale and its reversal"). Three places read `->where('line_order', 0)->value('account_id')` to identify a POS payment entry's cash/portfolio account: `TreasuryDepositBridge.php:138`, `TreasuryAccountPaymentBridge.php:154`, `TreasuryReceiptBridge.php:1368`. The change is an **improvement** — a replayed maturity refund leg previously read `ProductRevenue` at line 0 and hit the `maturity_payment_unrecognized_debit_account` invariant, and now resolves cleanly to the repository account — but it is an undeclared behaviour change on a replay path. Fix: cite the three readers in the comment at `:4186` so the coupling is discoverable.

---

## Item-by-item against the brief

**1. JE shapes.** All eight verified green on **both** drivers (36 tests / 155 assertions on PG in one process: `PosReceiptVatGlSplitTest`, `PosBridgeInstrumentRefundTest`, `PosReceiptVatLegCensusCommandTest`, `PosReceiptVatAllocatorTest`; the first two also on sqlite).
- Plain sale, mixed rates → `Dr 53 690.000 / Cr 707 600.000 / Cr 4457 ×3 (7.000, 26.000, 57.000)`, ledger-by-rate == sealed-by-rate.
- Transaction discount → `Dr 709x` contra, revenue on the pre-discount base, `4457` from the sealed rows (see I-3/I-4).
- Card sale → debit is the tender repository's `gl_account_id`; the credit-side split is identical. **Note:** the split-tender fixture routes CASH and CARD to the same `CashRegister` repository, so no test distinguishes a card **clearing** account from cash. `SystemAccountPurpose::PosTenderClearing` is resolved only by `createVoucherLedgerEntry`, not by the tender legs — nothing this lane changed, but "card sale → clearing account purpose" is **not** what the code does and I could not find a path where it does.
- Mixed tender → 2 entries, each balances alone; Σ across both == sealed, no millime lost.
- Cash refund → true mirror via the shared `writePosRevenueAndVatLines(onDebitSide: true)`.
- Instrument refund / cancellation → `CancellationShape::PosRevenue` now takes the same decomposition; sale + refund leaves **both** `707` and `4457` flat, and the reversal line names its rate.
- 100 %-comp → pinned at the allocator only, which the r3 fiscal ruling establishes is correct while the PG `pos_receipt_payments_amount` CHECK stands. **Owner ruling D-1 has now closed that gap from the device side** ("100 %-comp receipts carry no zero tender row (schema CHECK kept)"), which retroactively validates the lane's r2 workaround.
- Balance: every posted entry passes the `UnbalancedJournalEntryPostException` chokepoint at `GeneralLedgerService.php:3774`; my PG trial balance confirms `1047.000 == 1047.000`.

**2. Reporting consequences.** Table at the top. Trial balance ✅. P&L revenue is **not** net of `709x` — see I-3. VAT report/declaration is untouched (it reads `pos_receipt_vat_details`, `EloquentVatDataRepository.php:130-144`) and **now agrees with GL 4457 by rate for a posted sale + refund**. Z/X reports are device-side and read `pos_receipts` totals (TTC); GL revenue is now HT, so a "Z total vs GL 707" eyeball comparison that used to tie will no longer — the difference is exactly `4457 + 709x`. Worth one line in the deploy note; no code reads both.

**3. Treasury bridge.** Movement amounts and repository balance are the **gross tender**, unchanged: `in 690.000 / out 357.000 / balance "333.000"`, each row carrying its own `journal_entry_id`. The split touches only the credit side of the GL entry. `TreasuryDepositBridge` is not in the diff; the only coupling is the `line_order = 0` read (M-4), which the lane makes more correct, not less. **Inherited reds confirmed, though I cannot corroborate the figure "9":** what I proved by execution is `AdvanceReversalGlShapeTest` = **12 tests / 11 failures, byte-identical on the lane and at current dev tip `871ef63b0`**, and the two ratchet tests = **13 tests / 2 failures, identical on both**. Both are inherited, and neither count moved.

**4. `pos:census-vat-legs`.** Read-only (grep for `update|insert|delete|DB::statement|->save(` over the file → none; wave-4 tenant row counts unchanged after the run). Exit codes: `0` clean, `1` drift, `1` unprovisioned (M-3). Run against the real wave-4 tenant `tenant01a035ba-592b-72aa-a12a-1e6b2f7e1d06`:

```
POS output-VAT leg census: 2 receipt(s) whose ledger `vat_collected` does not match their sealed VAT.
FE-T-MAIN-01-2026-00000001  sealed_vat=52.000  ledger_vat=0.000 TND  pos_entries=1
FE-T-MAIN-01-2026-00000002  sealed_vat=2.800   ledger_vat=0.000 TND  pos_entries=1
Breakdown: 0 never reached the GL, 2 were booked with a wrong or partial VAT leg.   EXIT=1
```

Hand-checked with psql and it is exact: `pos_receipt_vat_details` holds `7.00/100.000/7.000`, `13.00/200.000/26.000`, `19.00/100.000/19.000` (Σ 52.000) on receipt #1 and `7.00/40.000/2.800` on the return; the ledger holds only `Dr 53 452.000 / Cr 707 452.000` and `Dr 707 42.800 / Cr 53 42.800` — **`4457` has no line at all**. The pre-fix defect and the census output match to the millime. See I-1/I-2/M-2/M-3 for the census's own gaps.

**5. Rule 20.** `PosReceiptVatAllocator` consults no `CompanyContext` (grep: 0 hits outside a docblock) and takes the scale as an argument; `GeneralLedgerService::posTenderAmount()` resolves `getScale((string) $receipt->currency)` explicitly; the census uses `getScaleSafe($currency, 3)`. `TreasuryReceiptBridge` passes `$currencyScale` down. `PosReceiptVatGlSplitTest` calls `CompanyContext::clear()` before **every** `apply()` (10 call sites), and `PosBridgeInstrumentRefundTest:139` does the same. **No new `onQueue` in the diff**, so no `config/horizon.php` change is owed.

**6. Manifest / deptrac / ratchet — against CURRENT dev `871ef63b0`.**
- `feature-lane-manifest-check.php`: lane **EXIT=0, 1409 classes / 74 groups**; dev tip **EXIT=0, 1408 / 74**. The lane adds exactly 3 Feature classes, so the union at merge is **1411 / 74**, reconciling with the r3 fiscal figure.
- `deptrac-ratchet.php`: **183/183 PASS on both** — no boundary regression. (Treasury and POS now import `Accounting\Domain\Services\PosReceiptVatAllocator` and `Accounting\Domain\DTOs\PosRevenueVatSplit` concretely rather than through `Shared\Contracts`; that is the same pre-existing, ratchet-accepted coupling by which `TreasuryReceiptBridge` already calls `GeneralLedgerService`. No new violation.)
- `apps/api/tests/feature-lane-manifest.json` is modified on **both** the lane and dev since the merge base — expect a merge conflict there and resolve by union, not by taking one side.

**Ratchet regen — is it safe to leave stale? YES.** Verified by execution rather than by argument: `ProvisioningRequiredPurposesV1::registeredThrowingCallSites()` has **no runtime consumer** — the runtime accessor is `requiredPurposes()`, read by `SystemAccountPurpose::requiredPurposes()` → `ChartOfAccountsService::validateCompanyAccounts()` / `TemplatePublishingService.php:304` — and all three purposes this lane newly resolves are **already classified `REQUIRED`** (booted the container and asserted it: 28 REQUIRED purposes, containing `product_revenue`, `vat_collected`, `sales_discount`). So leaving the AST list stale weakens no live-tenant validation; only the CI drift detector stays stale, and it is **already red on dev with the identical count** (13 tests / 2 failures on `4e561d2f3` and on `871ef63b0` alike).

> **Deploy note, verbatim for the merge commit / owner sheet:**
> *W4-9 changes only what NEW receipts post; `journal_entries` are immutable (rule 8), so already-posted POS entries stay wrong until an accountant books a correcting entry per period (greenfield → re-provision). Run `php artisan pos:census-vat-legs` per tenant (`tenants:run` loop) after deploy; exit 0 = clean. **Before enabling POS on any tenant, confirm the chart carries the `vat_collected` AND `sales_discount` system purposes** — a chart missing either now silently drops the entire receipt (payment, cash movement and GL) on a stuck queue job, where it previously booked (see I-2). The `ProvisioningRequiredPurposesV1` AST ratchet stays stale and RED at its existing dev-tip count (13 tests / 2 failures); this lane does **not** move it and must not be credited or blamed for it. Its regen is a dedicated CI-hygiene commit on dev that re-runs the scanner and applies **ADD 3** (`posRevenueAndVatLineSpecs` → `ProductRevenue`, `SalesDiscount`, `VatCollected`), **REMOVE 3** (`createPOSPaymentEntry:3558`, `createPOSRefundReversalEntry:3642`, `createInstrumentCancellationEntry:3223`, all `ProductRevenue`) and a **RE-PIN of every other `GeneralLedgerService` line number**. No runtime behaviour depends on it.*

---

## Test quality

Real behaviour, no `assertTrue(true)`, `RefreshDatabase` + real models, real projections through `PosCoreReceiptProjection` + `TreasuryReceiptBridge`, no faked API payloads, `CompanyContext::clear()` before every `apply()`, and — the property that matters most here — **every VAT assertion compares the ledger against the SEALED rows read back out of `pos_receipt_vat_details`, never against a rate recomputed in the test** (`PosReceiptVatGlSplitTest::sealedVatByRate()` / `ledgerVatByRate()`, which parses the rate off each line's description so a mislabelled line fails). The split-tender case uses `400.000 + 290.000` against 7/13/19 % precisely so no share divides evenly, which is the only shape that proves the truncation residual is absorbed rather than dropped. Coverage gaps: no training/voided census case (I-1), no card-vs-cash **repository** distinction in the split-tender fixture, and no end-to-end comp case (correctly deferred to the device lane per D-1).

---

## What to fix before merge

Close **I-1** (two `where()` clauses + a test) and the census half of **I-2** (add `SalesDiscount` to the purpose preflight at `PosReceiptVatLegCensusCommand.php:99`) so the deploy gate this lane ships is trustworthy on day one; everything else can land as-is with the deploy note above.
