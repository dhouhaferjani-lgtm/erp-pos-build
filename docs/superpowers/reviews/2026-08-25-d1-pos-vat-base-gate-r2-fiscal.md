# D-1 gate r2 — fiscal-pos lens (adversarial, code-grounded, verified by execution)

**Lane** D-1 (CRITICAL, owner-ruled 2026-08-25 option (a)) · branch `fix/campaign-d1-pos-vat-discount-base`
· worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/d1-pos-vat-base` · HEAD `c3fccd340`
**Round under review** fix round `f852f9062` (27 files, +1490/−174) + dev merge `48e6cddd1`.
**r1 records** `…-gate-r1-fiscal.md` (CHANGES: C-1, C-2, I-3, I-4, I-5, I-6, M-7/8/9) ·
`…-gate-r1-treasury.md` (APPROVED, F-1..F-6). **Handback** §13.
**Read first, as instructed** `apps/api/app/Shared/Domain/TransactionRemiseSplit.php` (142 lines, in full).
**Posture** verify, do not trust. Every claim cites a line I opened or a command I ran. Nothing was
merged; the lane worktree is byte-untouched (`git status --short` empty at exit).

---

## VERDICT

**spec ✅ · quality ACCEPT-WITH-CONDITIONS · merge-blocking: NO**

All three r1 CRITICAL/blocking findings and both r1 conditions are **closed, and I closed them by
execution rather than by reading the handback**:

- **C-1** — a v5 `SALE_RECEIPT` declaring `REFUND` or `VOID` is now refused on both sides. I
  red-proved it: neutering the server clause turns exactly the two new pins red, and the VOID case
  goes red too, which independently confirms the v4 VOID prohibition genuinely did **not** fire at v5.
- **C-2** — the net/VAT split is pinned **exactly, with no band**. Brute-forcing all **18 595**
  `(discNet, discVat)` pairs that sum to the 19 % group's allocated 18.594 through the real validator,
  **exactly one is accepted** — `discNet=15.625 / discVat=2.969`, the ruling's own answer. And the pin
  is the *same authority the device seals with*: a 1 200-case differential fuzz (scales 3/2/0, 1–4
  groups, 7 rates, full comps and zero remises included) shows **0 split mismatches across 1 207 groups**
  and **0 allocator-output mismatches**, and all **1 200 device-authored payloads are ACCEPTED** by the
  server validator — the pin adds no false quarantine.
- **I-3 / I-5** — the gate is scoped to `chain_context` and is an `exists()` probe on a new partial
  index; the cross-context matrix is pinned in **both** directions and red-proves at 2.
- **I-6** — both D-1 classes are in the live `backend-test-pgsql` `--filter`, in a job that runs on
  PR→dev.
- Treasury **F-1..F-4** all land, and F-2 turns out to have closed more than a VAT defect (below).

What holds it to *conditions* rather than a clean ACCEPT is three things the fix round did not close,
none of which is a wrong number produced by this lane's own code:

1. the ventilation is pinned **within** a rate group but not **across** them — a device can still seal
   the pre-D-1 VAT total (71.000) at v5 and the server accepts it (finding 1);
2. the new ACCOUNT_CHARGE refusal is **unconditional and retroactive**, which quarantines a real credit
   sale authored by any device that has not yet taken the build (finding 2) — a deploy-ordering hazard
   that must reach the LEDGER row;
3. the four F-2 return pins — the only regression surface for what a v5 refund *pays out* — execute in
   **no live CI filter** (finding 3), which is r1 finding 6 reopened one class over.

---

## What I verified GREEN by execution

| # | Claim | How | Result |
|---|---|---|---|
| V-1 | C-1 server clause at `FiscalPayloadConstraintValidator.php:1244` refuses v5 REFUND/VOID, accepts v5 SALE + TRAINING, leaves v4 alone | `SaleReceiptV5PostRemiseVatBaseTest` on **PG** | **19/19 green**; tamper (`if (false)`) ⇒ **2 red** (`v5 refuses a refund/void invoice type`) |
| V-2 | The VOID hole was real, not covered by the v4 prohibition | same tamper — the VOID test's regex admits `payload_void_authoring_prohibited` and it still went red | **confirmed**: nothing else catches it |
| V-3 | C-1 device clause `FiscalEventEngine.ts:1665` sits BEFORE the `=== 4` block | read + `FiscalEventEngine.test.ts` | **112/112 green**, 3 new pins |
| V-4 | v5 cannot break refunds: the device never authors a REFUND at 5 | `FiscalEventPayloadRegistry.ts:232-250` (SALE/TRAINING→5, REFUND→4, VOID throws) + the **only** production call site `FiscalEventEngine.ts:572` passes the payload | **safe** |
| V-5 | **C-2 exactness** — the split pin admits exactly one pair | brute force over all 18 595 `(discNet,discVat)` pairs for the 19 % group of the golden fixture, each assembled into a full payload and run through `validatePerEventConstraints(…, 5)` | `tested 18595 … accepted 1 -> discNet=15.625 discVat=2.969` |
| V-6 | The r1 mis-split family is refused | probe | `REFUSED: payload_partition_discount_vat_split_out_of_band:rate=13.00:category=:expected=2.031:got=17.656` (subtotal 562.250 / VAT 27.750) |
| V-7 | **Device ↔ server split parity is byte-exact** | 1 200 randomized device ventilations emitted from the real `allocateTransactionDiscount` (scales 3/2/0), re-derived by `TransactionRemiseSplit::split()` | `groups=1207 split_mismatch=0` |
| V-8 | **Full allocator parity** (device vs `TransactionDiscountVatAllocator`) | same corpus, keyed by `(rate,category)`, run in BOTH the canonical-sorted and the raw input order | `sorted_order_mismatch=0  input_order_mismatch=0` |
| V-9 | The pin never false-quarantines a legitimate device payload | those same 1 200 ventilations assembled into v5 payloads and validated | **`accepted=1200 refused=0`** |
| V-10 | One authority, no third implementation | `grep` for `TransactionRemiseSplit` / `TransactionDiscountVatAllocator` / `discNet` across `apps/api/app` and `apps/pos/src` | one PHP kernel + 3 PHP consumers (`FiscalPayloadConstraintValidator:3038`, `TransactionDiscountVatAllocator:186`, `ReceiptReturnService:1287/1292`) + the one unavoidable TS copy (`vatDiscountAllocation.ts:242`). **No third** |
| V-11 | I-3 chain scoping + I-5 `exists()` (`SaleReceiptForwardVersionGate.php:95-102`) | `PosReceiptV5DiscountVatBaseProjectionTest` on **PG** | **10/10 green** incl. both cross-context directions; tamper (drop the `chain_context` clause) ⇒ **2 red** |
| V-12 | The three tenant migrations apply **from scratch** on a fresh PG DB | `autoerp_test_d1g2` created empty, central + tenant migrate | all 3 DONE; `fiscal_events_sale_receipt_v5_watermark_idx` = `(tenant_id, company_id, terminal_id, chain_context) WHERE event_type='SALE_RECEIPT' AND event_version >= 5` — **exactly the gate's predicate**; index COMMENT present |
| V-13 | The CHECKs survive the fresh build | `pg_constraint` | `pos_receipts_totals` = the widened disjunction, `convalidated=t`; `pos_receipt_payments_amount CHECK (amount > 0)`, `convalidated=t`; `discount_allocated numeric(12,3) NULLABLE` |
| V-14 | Migration `090200` self-guards | `:41-44` returns early off pgsql/sqlite; `CREATE INDEX IF NOT EXISTS`; `down()` guarded | **confirmed** |
| V-15 | **F-2**: returns reverse the SEALED rows pro-rata | `ReceiptReturnRefactorTest` — partial (−78.99/−15.01/−94.00), full (exact), pre-remise era control, mixed-era refusal | **4/4 green on PG and on sqlite**; tamper (`return null;` at the head of `postRemiseSealedReversal`) ⇒ **3 red** |
| V-16 | F-2 also closes a **cash over-refund**, not just a VAT one | `$total = bcadd($subtotal, $totalTax)` at `ReceiptReturnService:1160` now sums the reversed post-remise rows; pre-fix it summed the pre-remise line roll-up | on the worked example a full return would have paid out **640.000 against a 590.000 sale**. Bigger than treasury r1 called it |
| V-17 | Mixed-era refusal is a clean 422, not a 500 | `ReceiptController.php:453-459` maps `\RuntimeException` → `422 RETURN_FAILED` with the message | **confirmed** (generic code; see §Notes) |
| V-18 | **F-1 census BASE arm + F-3 P&L** | `PosReceiptVatLegCensusCommandTest` + `PosReceiptVatGlSplitTest` on **PG** | **33 tests / 114 assertions green**, incl. `a receipt whose revenue base drifts from the sealed base is flagged` and `the profit and loss turnover at v5 is the sealed base with no contra` |
| V-19 | **F-4 / I-4** refused on both sides, and the operator is told | server `FiscalPayloadConstraintValidator.php:1913`; device `accountChargeCartMapper.ts:120-123` throws `i18n.t('account_charge.errors.remise_not_supported')`, en + fr present; the message reaches `paymentStore.error` via `formatCheckoutError()` (`paymentStore.ts:465-467`) and is rendered at `AdvancedPaymentsModal.tsx:1130-1133` | **confirmed end to end** |
| V-20 | A **no-remise** on-account charge still works | `accountChargeCartMapper.test.ts` new case + `HomePage.tsx:1514` clears with `setTransactionDiscount(undefined)` (never a zero-valued object), so the `if (input.transactionDiscount)` guard cannot fire on a cleared cart | **9/9 green** |
| V-21 | M-7 registry comment corrected to the per-chain watermark | `FiscalEventPayloadRegistry.php:141-145` | **confirmed** |
| V-22 | M-8 pre-D-1 reprints keep their original Subtotal | `buildReceiptData.ts:169-172` (era off `discount_allocated`) + `:231-240` | **confirmed**; `buildReceiptData.test.ts` **51/51 green** |
| V-23 | I-6 both pins in the LIVE allowlist | `ci.yml:998` parsed in python: 132 anchored names, both present; owning job `backend-test-pgsql` (`ci.yml:578`) `if: … github.base_ref == 'dev' …` | **confirmed — runs on PR→dev** |
| V-24 | Sealed bytes / V3 hash untouched | `git show --name-only f852f9062` ∩ {hash, canonical, Encoder, SaleReceiptPayload, vatDiscountAllocation} | **empty** — the fix round touches no byte-producing file |
| V-25 | Rule 19 / 20 clean | grep of `+` lines in `f852f9062` for `parseFloat` / `Number(` / `(float)` / `number_format` / bare `getScale()` / `onQueue` | **all empty** |
| V-26 | Gates | deptrac ratchet **PASS 183/183**; `feature-lane-manifest-check.php` **EXIT 0**; POS `pnpm lint` **84 warnings / 0 errors == dev's 84/0**; PHPStan L8 on all 7 changed PHP files **No errors**; `pint --test` **pass** | **green** |
| V-27 | The 3 `ReceiptReturnRefactorTest` PG reds are inherited | ran the SAME file on the `dev` checkout: same three names red (`throws daily cap exceeded…`, `daily cap not enforced…`, `out of window voucher only…`), 3 failed / 12 passed | **inherited, not this lane's**; the class is **19/19 green on sqlite** |

### Red-proofs I reproduced myself (throwaway detached worktree, **real copied `vendor`**)

The first attempt with a symlinked `vendor` resolved `App\…` back into the lane worktree
(`ReflectionClass::getFileName()` proved it) — the known trap. Every tamper below ran against a
247 MB copied `vendor` with class resolution re-verified inside the tamper tree.

| Tamper | Expected | Observed |
|---|---|---|
| v5 invoice-type clause → `if (false)` | C-1 pins red | **2 red** |
| split pin `bccomp($discVat, $expectedDiscVat)` → `if (false)` | C-2 pins red | **2 red** (mis-split + 1-ulp nudge) |
| drop `->where('chain_context', …)` | cross-context pins red | **2 red** |
| `postRemiseSealedReversal()` → `return null;` | F-2 pins red | **3 red** (the pre-remise era control stayed green — correct) |
| rename the ACCOUNT_CHARGE refusal string | F-4 server pin red | **1 red** |

The lane is honestly red-proved on every claim it makes.

---

## FINDINGS

### 1. [IMPORTANT] The remise's split is pinned; its **allocation across rate groups** is not — a v5 receipt can still seal the pre-D-1 VAT total, and the server accepts it

`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2980-3057`
(`validateVatPartitionGroupV5`) and `:1600-1639` (the aggregate arm).

Per group the server now pins, exactly: `discNet + discVat == discount_allocated`,
`discVat == TransactionRemiseSplit::split(...)`, `gross == lineGross − discount_allocated`. Across
groups it pins only `Σ discount_allocated == transaction_discount_amount` (`:1635`) and
non-negativity (`:1606`). **Nothing pins each group's SHARE.** The device computes it by
largest-remainder pro-rata (`vatDiscountAllocation.ts:169-234`); the server never checks it.

Because `discNet + discVat == allocated` per group, `subtotal + vat_total == total` is invariant under
any re-allocation — so every aggregate identity survives while the declared VAT moves.

**Proven by execution** on the lane's own worked example (Σ gross 640.000, remise 50.000, total 590.000
in all three rows):

| Allocation | sealed `subtotal` / `vat_total` | verdict |
|---|---|---|
| pro-rata (the ruling's answer) | 524.547 / **65.453** | ACCEPTED |
| all 50.000 onto the **19 %** group | 526.983 / **63.017** | **ACCEPTED** — under-declares **2.436** |
| all 50.000 onto the **exempt** group | 519.000 / **71.000** | **ACCEPTED** — over-declares **5.547** |

The third row is the sharp one: **71.000 is exactly the pre-D-1 VAT figure** — the number the owner
ruling exists to remove. It is sealable at `event_version = 5`, on a ticket whose totals all reconcile,
and the contract validator raises nothing.

The exposure per receipt is bounded by the remise's VAT wedge — under-declaration ≤
`remise × r_max/(1+r_max)`, over-declaration ≤ the correct wedge — so it is ~6× smaller than the hole
r1 finding 2 closed, and an honest device never produces it (I proved that: 1 200/1 200 device
ventilations are the pro-rata answer). But it is the same defect class one level up, and pre-D-1 it was
**impossible**: `payload_partition_vat_mismatch` pinned every group's VAT to the line roll-up exactly.
D-1 traded that away and the fix round bought back only half of it.

The class docblock (`TransactionRemiseSplit.php:10-13`) and the dispatch brief both call this class
"the single authority for the **ventilation**". It is the single authority for the **split**. The
ventilation — the largest-remainder apportionment — is still two independent implementations
(`TransactionDiscountVatAllocator::largestRemainder()` and TS `allocateLargestRemainder()`), server-unpinned.

**Why I am not making this merge-blocking.** Pinning the allocation *exactly* would make the server a
co-author of the ventilation, and any future device/server drift in the largest-remainder tie-break
would quarantine real sales — a materially different risk posture that needs the owner, not a gate.
**The cheap middle ground, which carries no parity risk at all:** bound each group's
`discount_allocated` within ±1 ulp of `discount × gross_r / Σ gross` (a floor/ceil band, not an
equality). That refuses every row in the table above while remaining immune to tie-break drift. Book
it with the bound stated.

---

### 2. [IMPORTANT] The ACCOUNT_CHARGE remise refusal is **unconditional and retroactive** — it quarantines real credit sales from any device that has not taken the build, and inverts the deploy order

`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1844`
(`private function validateAccountChargePayload(array $payload): void` — **no `$eventVersion`
parameter**) and the refusal at `:1907-1917`.

The device-side refusal (`accountChargeCartMapper.ts:120`) only binds devices that have taken the D-1
build. The server refusal binds **everything**, at every version, with no cutover date. Two consequences
the handback does not state:

1. **The deploy window.** The LEDGER row (r1, unchanged in substance) requires **server first**, because
   a v5 receipt from an upgraded device is otherwise quarantined. But the moment the server deploys,
   every *un-upgraded* terminal that charges a discounted cart to account has that `ACCOUNT_CHARGE`
   refused at ingest — stored, never projected: **no `account_charge_receipts` row, no AR balance
   movement, no GL entry**, for a real credit sale the customer has already walked out with. There is
   therefore **no safe single ordering**: SALE_RECEIPT wants server-first, discounted ACCOUNT_CHARGE
   wants device-first.
2. **Retroactive re-validation.** `VerifyEventChainCommand.php:610-619` re-runs
   `validatePayloadKeySet()` + `validatePerEventConstraints()` over **stored** events. After this
   deploy, every historical discounted on-account sale — already accepted, already projected, already
   in the AR ledger — reports as a payload-constraint failure on the integrity command. No pre-flight
   census counts them.

**Fix (either is fine, neither is large).** (a) Gate the refusal on a cutover the way the rest of D-1
gates on the version — e.g. refuse only when `event_time_device` is at/after the fleet cutover, so
already-authored facts stay valid; or (b) keep it unconditional but add the pre-flight census (count
`ACCOUNT_CHARGE` events with `transaction_discount_amount > 0`, must be 0) to the deploy note, **and**
put the "drain every terminal's outbox to zero, and disable the remise on the on-account tender in the
UI, BEFORE the server deploys" instruction on the LEDGER row. I have written (b) into the release
line below because it is the operationally safe reading; (a) is the cleaner engineering answer.

---

### 3. [IMPORTANT] The four F-2 return pins execute in **no** live CI filter — r1 finding 6, reopened one class over

`grep -c "ReceiptReturnRefactorTest" .github/workflows/ci.yml` ⇒ **0**.

r1 finding 6 was closed for `SaleReceiptV5PostRemiseVatBaseTest` and
`PosReceiptV5DiscountVatBaseProjectionTest` — correctly, and I verified both are in the live
`backend-test-pgsql` allowlist (V-23). But the fix round's largest behavioural change is in
`ReceiptReturnService`, and its four pins are the **only** regression surface for what a v5 refund pays
out and reverses — a live cash path (V-16: the pre-fix code would have refunded 640.000 on a 590.000
sale). That class runs in no live filter at all, so those four pins guard nothing in CI.

**The honest obstruction:** the class carries three PG reds inherited from dev (V-27), so it cannot
simply be added to the pgsql allowlist today, and `--filter` is class-anchored so the four green methods
cannot be named alone. **Condition:** book the inherited-red fix and the allowlist entry as one named
follow-up, and state in the handback that D-1's refund arm is CI-unguarded until it lands. Do not
leave it implicit.

---

### 4. [MINOR] M-9 is recorded as done in the commit message and the handback, but the text is **stranded uncommitted in the shared `dev` worktree**

`git diff dev...HEAD -- docs/architecture/` is **empty**; `docs/architecture/precision-contract.md` is
untouched by every commit on this branch. The 37-line "post-remise VAT base: per-group VAT can sit
1 ulp off `base × rate`" section **does exist** — as an **unstaged working-tree modification in the
shared `dev` checkout** (`/Users/houssamr/Projects/syneriva/apps/erp`, `git status` ⇒
`M docs/architecture/precision-contract.md`). It was written into the wrong tree (rule 21), and it will
either be lost to a `checkout`/`reset` or swept into an unrelated session's commit.

The content itself is good and I would keep it verbatim. **Condition:** move it onto this branch (or a
docs-only branch) before promotion, and clean the shared `dev` worktree. Also correct the handback
§13 M-9 row and the `f852f9062` message, which both assert a change that is not in the diff — on a
campaign where commit messages are the record, that matters more than the doc.

---

### 5. [MINOR] `discount_amount` is zeroed on a v5 return, contradicting the comment three lines above it, and nothing tests it

`apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1169-1170`:
`$headerDiscount = $sealedReversal !== null ? bcadd('0','0',$s) : $totalDiscount;`

The comment at `:1156-1163` says *"the line-discount sum must NOT be added back (it is already inside
the sealed base). `discount_amount` **still carries it for audit**."* It does not — it is set to zero.
The arithmetic is fine either way (arm 1 of `pos_receipts_totals` holds: `total = subtotal + tax − 0`),
but a v5 return of lines that carried **line** discounts now stores `pos_receipts.discount_amount = 0`
while the sale row stored the remise, so any net-discount-granted report (sales − returns) overstates.
All four new pins use a fixture whose `ReceiptLine.discount_amount` is `'0.00'`
(`ReceiptReturnRefactorTest:562`), so the branch is **not exercised**. Fix the comment, and add a
line-discount case.

---

### 6. [MINOR] `TransactionRemiseSplit` — a new `Shared/Domain` kernel with three PHP consumers — has no direct unit test

`find tests -name "*TransactionRemiseSplit*"` ⇒ nothing. Its behaviour is covered only transitively
(`TransactionDiscountVatAllocatorTest` 7/7, and the validator suite). The properties that deserve their
own pins are the ones a future refactor will break silently: `scale === 0` (`ulp()` returns `'1'`), the
`transaction_remise_split_negative_input` / `negative_intermediate` refusals, and the "at most one clamp
can bind, and the pair always sums back to `allocated`" invariant the docblock asserts at `:48-50`.
My 1 200-case fuzz says the current behaviour is right; a test says it stays right.

---

### 7. [MINOR] On a **v5 receipt with no remise**, the printed `Subtotal:` now means TTC and equals `TOTAL`, under an unchanged label

`buildReceiptData.ts:169-172` sets the era from *any* non-null `discount_allocated`. A v5 no-remise
receipt seals `'0.000'` on every breakdown row (not `null`), so `isPostRemiseReceipt` is `true` and the
printed subtotal becomes `total + 0 − rounding` = `TOTAL`. The Rust label is still the bare
`"Subtotal:"` (`receipt_template.rs:600`), unchanged by this lane. So the majority of tickets — the
undiscounted ones — now print `Subtotal == TOTAL` where they used to print the HT base.

r1 M-8 offered (a) relabel or (b) branch by era; the lane took (b), which is a legitimate choice and
closes the reprint-fidelity concern I raised. This is the *other* half of (a) that (b) does not cover.
Not fiscal — the ventilation table beside it carries the real base and VAT — but state the choice on
the rollout row rather than discovering it from a customer.

---

### 8. [MINOR] Manifest `gated_ceiling` is stale against current dev — union is **1181**

Lane: `gated_ceiling 1178`, Fiscal `81`, POS `156`; checker reports 1429 Feature classes / 1178 gated,
EXIT 0 **in-lane**. Current dev (`8fc91c860`, re-taken at exit — dev moved twice during this gate,
`40c702340` → `8fc91c860`, manifest unchanged): `gated_ceiling 1179`, Fiscal `80`, POS `155`, 1430
Feature classes / 1179 gated.

**Union at merge: `gated_ceiling` 1179 + 2 = `1181`.** Fiscal `81` and POS `156` are already the correct
union values. Re-run `feature-lane-manifest-check.php` **after** the merge commit, and re-take the union
if dev's manifest moves again before you land.

---

## Notes (not defects — recorded so the next reader does not re-derive them)

- **The brief's phrasing "F-1 census BASE arm catches the 555.250 shape" is not quite right, and the
  code is right.** The census reconciles `Σ pos_receipt_vat_details.net_amount` against the
  `ProductRevenue` credit−debit (`PosReceiptVatLegCensusCommand.php`, the new `revenue_ledger` /
  `instrument_revenue_ledger` joins). That catches **ledger-vs-sealed** drift — which *is* D-1's defect
  class at the GL layer, and is what treasury F-1 asked for. A wrong **sealed** base that the GL
  faithfully mirrors would read clean; that case is now caught upstream, by the C-2 pin (V-5). Both
  arms are needed and both exist.
- The mixed-era return refusal surfaces as `422 RETURN_FAILED` with the full message
  (`ReceiptController.php:453-459`). Fail-closed and diagnosable, but a corrupted sealed set is not a
  client payload fault — the same argument the R-10 narrowing block 40 lines above makes for
  `UnbalancedJournalEntryPostException`. Low priority; noted for whoever does the next catch-narrowing pass.
- The device hardcodes `eventVersion >= 5` (`FiscalEventEngine.ts:1665`) where the server uses
  `self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION`. TS has `SALE_RECEIPT_AUTHORED_EVENT_VERSION = 5` one
  file over; using it would keep the two in lockstep at the next bump.
- The index predicate hardcodes `event_version >= 5` while the gate reads the constant. A future bump
  to 6 keeps the index *usable* (PG: the query predicate implies the index predicate), so this is safe
  in the direction it can move.
- `PosReceiptV5DiscountVatBaseProjectionTest` still clears `CompanyContext` before `apply()`
  (`:345`, rule 20). Confirmed at HEAD.
- Two dev-merged migrations share the timestamp `2026_08_25_120000_`
  (`add_count_movement_markers…`, `retype_sales_discount_accounts…`). Not this lane's, ordered
  deterministically by filename, no action.

---

## POS release-coupling row for the LEDGER

> **D-1 — post-remise VAT base (`SALE_RECEIPT` v5).** MIGRATION-BEARING (×3, all self-guarding,
> verified applying from scratch on PostgreSQL) + POS-BUILD-COUPLED.
> **Order: drain every terminal's outbox to ZERO → apply the three tenant migrations
> (`2026_08_25_090000`, `090100`, `090200`) fleet-wide → deploy the server → deploy the POS build.**
> Reversing server/POS strands every v5 receipt in quarantine; without `090100` a discounted v5 receipt
> fails `pos_receipts_totals` on PostgreSQL.
> **The outbox drain is now MANDATORY, for a second reason:** the ACCOUNT_CHARGE remise refusal
> (`payload_account_charge_transaction_discount_unsupported`) is unconditional and version-less, so from
> the moment the server deploys, a discounted on-account sale authored by any device that has not taken
> the build is refused at ingest — no `account_charge_receipts` row, no AR movement, no GL entry
> (gate r2 finding 2). Also **disable the remise on the on-account tender in the UI before the server
> deploys**, and run the pre-flight census `ACCOUNT_CHARGE` events with `transaction_discount_amount > 0`
> (must be 0) — `verify:event-chain` will report any historical ones as constraint failures after this
> deploy.
> **Operator-visible behaviour change:** an on-account sale can no longer carry a remise at all
> (en/fr message: *"A remise cannot be applied to an on-account charge yet. Clear the discount, or take
> payment now."*). Brief the cashiers; the ACCOUNT_CHARGE ventilation lane is booked and not in this one.
> **A POS rollback after D-1 is NOT a supported operation**: once a chain seals a v5 receipt its
> per-chain watermark is permanent and every later pre-v5 receipt on that same `chain_context` is refused
> as `sale_receipt_version_downgrade` — stored, never projected, so no `pos_receipts` row, no GL entry
> and no stock decrement. The gate is now correctly scoped per `chain_context`, so a queued
> `training_operational` v3 draining after an operational v5 is NO LONGER quarantined (r1 finding 3,
> closed and pinned in both directions).
> **Accounting discontinuity at the cutover:** turnover rises by the VAT half of every remise
> (+5.547 on the worked example: 519.000 → 524.547) and account `709` goes **empty** on the POS — the
> remise now lives only in `pos_receipts.discount_amount` and
> `pos_receipt_vat_details.discount_allocated`. Tell whoever owns discount reporting.
> **Ticket layout:** post-D-1 tickets print `Subtotal:` as the TTC before the remise (so
> `Subtotal − Remise == TOTAL`), including on undiscounted tickets where it now equals `TOTAL`;
> pre-D-1 receipts reprint byte-faithfully (r1 M-8, closed).
> **CI note:** the two D-1 pins run on PR→dev on real PostgreSQL; the four **refund** pins run nowhere
> (gate r2 finding 3).

---

## Merge conditions

**Merge-blocking: NO.** None of the r1 blocking findings survives, and I closed each by execution.

**Conditions of merge (all bookable, none code-blocking):**
1. **Finding 2** — put the ACCOUNT_CHARGE ordering hazard + the pre-flight census on the LEDGER row
   exactly as written above, or version-gate the refusal. This is the only one with a live-tenant
   consequence in the deploy window.
2. **Finding 1** — book the cross-group allocation band (±1 ulp of `discount × gross_r / Σ gross`) as a
   named follow-up with the bound stated; correct the "single authority for the ventilation" wording in
   `TransactionRemiseSplit`'s docblock to "for the split".
3. **Finding 3** — book the `ReceiptReturnRefactorTest` inherited-red fix + allowlist entry together;
   record in the handback that D-1's refund arm is CI-unguarded until then.
4. **Finding 4** — move the precision-contract section onto a branch and clean the shared `dev`
   worktree; correct the M-9 row in the handback and the commit-message claim.
5. **Finding 8** — re-union `gated_ceiling` to **1181** at merge and re-run the checker on the merge
   commit.

**Findings 5, 6, 7** are minors: fold or ticket at the parent's discretion.

---

*Gate run 2026-08-25. Every command ran read-only against the lane worktree; all tampering happened in
a detached throwaway `git worktree` with its own COPIED `vendor` (class resolution re-verified inside
it after the symlink trap was caught), removed afterwards. Throwaway database `autoerp_test_d1g2`
created, migrated from scratch, and DROPPED — verified absent. No vitest worker pools survive
(`ps | grep -c 'node (vitest'` ⇒ 0). Lane `git status --short` empty at exit. No merge performed, no
lane file modified.*

**Hygiene note carried forward from r1:** `autoerp_test_d1t`, `autoerp_test_p1g`, `autoerp_test_p1g_mig`,
`autoerp_test_q7gate`, `autoerp_test_w21` are still present on `127.0.0.1:5433`. None is mine; whoever
owns them should drop them.
