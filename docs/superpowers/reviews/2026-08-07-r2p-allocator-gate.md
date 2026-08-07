# Gate record — `11a3363ff` landed-cost allocator millime drift

- **Scope:** commit `11a3363ff` ONLY, branch `fix/r2p-purchasing`, diff base `a1952aa23`.
  Sibling commits `e9971cf03` / `702f57974` gated separately — their hunks ignored.
- **Spec:** `docs/superpowers/tickets/2026-08-03-w4-purchasing-inventory-defects.md` §#1 (MTP-PUR-17).
- **Reviewer:** inventory-costing-reviewer (adversarial, code-grounded). Gate only — no code modified.
- **Diff surface (numstat):** `ProportionalMoneyAllocator.php` +11/-2, `LandedCostBcmathTest.php` +80/-0,
  `ProportionalMoneyAllocatorTest.php` +32/-0. **No test was deleted or weakened.**

## VERDICT: spec ✅ + quality CHANGES-REQUESTED (one blocking item, non-code)

The allocator fix is correct, conservation is structural, and the red→green claim is
reproducible. The blocking item is the ticket-mandated tripwire update that was not done.

---

## 1. EXACT CONSERVATION — structural, not shape-lucky

Conservation does **not** depend on the arithmetic that changed. It is enforced by the
**absorber**: `ProportionalMoneyAllocator.php:33,40-41` elect the LAST positive base as
absorber; `:53-54` give it `$remaining`; `:72` decrements `$remaining` at the money scale.
So `Σ(shares) ≡ total` by construction for every input — no largest-remainder pass is
needed and none was added.

The commit does not weaken that invariant, because both remaining truncations are
**downward**: `bcmul(...,$workingScale)` (`:66`) and `bcdiv(...,$workingScale)` (`:66`) both
truncate toward zero, and `bcformatStrict` (`CurrencyScale.php:130-145` → `bcadd($v,'0',$scale)`)
truncates too. Every non-absorber share is therefore `<= exact`, so `$remaining` at the
absorber is `>= exact_absorber >= 0`. The absorber can never be driven negative by the new shape.

Empirically verified (throwaway scripts, not committed):

| probe | result |
|---|---|
| 50 000 randomised shapes (n=1..12, scale 3, working 7) | **0 conservation failures** |
| absorber negative | **0** |
| non-absorber share exceeding its exact value | **0** |
| ticket shape `30.000 / [100,50]` | `20.000 / 10.000` ✅ |
| per-part-truncation shapes: 3, 7, 100, 1000 equal bases | `3.333/3.333/3.334`, … all sum exactly |
| negative total, mixed-sign bases, zero-first/zero-last base, JPY scale 0, EUR scale 2, tiny subtotal, huge-vs-dust | all CONSERVED |
| all-zero bases (`total 17.250`) | returns zeros — early return `:45-47`; consumers guard (`LandedCostService.php:307`, `ReceiptBatchCostAllocator.php:62-64`) |

A shape where per-part truncation MUST leave a remainder is pinned by an existing test:
`ProportionalMoneyAllocatorTest.php:12-35` (`100.001` over `1/1/0/1` → `33.333/33.333/0.000/33.335`),
and it is unchanged by the fix (confirmed: the pre-fix simulation yields the same tuple).

**Residual (not introduced here):** the absorber convention concentrates ALL truncation
residue on one line — measured max skew `0.009292` at n<=12; a 30-line PO can push ~0.029
onto a single product's WAC. See finding I-2.

## 2. WAC consumer proof — real path, discriminating assertion

- `LandedCostBcmathTest.php:303-370` builds the ticket shape, calls
  `LandedCostService::allocateCosts()` (`:320`), asserts `allocated_costs` `20.000000/10.000000`
  and `landed_unit_cost` `12.000000/12.000000` (`:331-334`), then calls
  `GoodsReceiptService::receiveGoods()` (`:352-355`) and asserts `Product::cost_price`
  (`:360-369`).
- `receiveGoods` is the real production path, not a test shim:
  `GoodsReceiptService.php:64-98` → `createDraft()` + `post()`; `post()` re-runs
  `reallocateCosts()` (`:226-229`) and `recordPurchase()` (`:578`) with
  `$line->landed_unit_cost` as the basis (`:490`). Production callers:
  `PurchaseOrderController.php:820`, `PurchaseOrderToGoodsReceiptConverter.php:140`.
- **Red proof reproduced** by simulating the pre-fix shape on the same inputs:
  `19.999 / 10.001` → landed unit cost `11.999900 / 12.000200` — byte-identical to the live
  values recorded in the ticket. Both new tests would fail on `a1952aa23`.

Test runs (live PG, by path):

```
Unit/Shared/ProportionalMoneyAllocatorTest + Feature/Inventory/LandedCostBcmathTest
  + Unit/Inventory/LandedCostServiceTest .............. 16 passed (41 assertions)
Feature/Document/DocumentAdditionalCostTest + …TenantIsolationTest
  + Feature/Expense/LinkedCostExpenseTest ............. 24 passed (80 assertions)
Feature/Inventory/ReceiptBatchAllocationTest + …GoodsReceiptPriceOverrideTest
  + Feature/Expense/ExpenseLegacyWriterSpineTest ...... 13 passed (81 assertions)
Feature/Accounting/GoodsReceiptGlTest + GrirDriftReportTest .. 7 passed (51 assertions)
phpstan app/Shared/Domain/ProportionalMoneyAllocator.php ..... [OK] No errors
pint --test (3 changed files) ................................ pass
```

## 3. Blast radius — consumer list verified COMPLETE (3)

`grep -rn ProportionalMoneyAllocator apps/api --include=*.php` (vendor excluded) yields exactly:

1. `LandedCostService.php:51` → `allocatePositiveShares():311`, used by `allocateCosts():115`,
   `allocateCostsAndTaxes():178-179`, `reallocateCosts():254`.
2. `ReceiptBatchCostAllocator.php:20,67` (partial-receipt freight pool, scale 6 / working 10).
3. `LinkedCostApplicationService.php:28,71` (linked expense → WAC adjustment / COGS).

Golden-encoding sweep: the only tests that create `DocumentAdditionalCost` /
`additional_costs` are `DocumentAdditionalCostTest`, `DocumentAdditionalCostTenantIsolationTest`,
`ExpenseLegacyWriterSpineTest`, `LinkedCostExpenseTest`, `LandedCostBcmathTest`,
`LandedCostServiceTest` — all run, all green. One adjacent suite per consumer run
(`ReceiptBatchAllocationTest`, `GoodsReceiptPriceOverrideTest`, `LinkedCostExpenseTest`)
plus the GL/GRIR downstream (`GoodsReceiptGlTest`, `GrirDriftReportTest`). No baseline
encoded the drift. The commit message's sweep claim holds.

## 4. Scale discipline

- No float introduced. Whole path is bcmath strings; `bcformatStrict` used at the boundary.
- Working scale is the caller's `max(currencyScale+4, COST_SCALE+1)` (`LandedCostService.php:74-77`),
  passed through — the fix does not downgrade any scale; it REMOVES an intermediate truncation.
- Truncate-once-at-boundary convention preserved (allocator docblock `:10-13`).
- `LandedCostService::scale():54-56` uses the no-arg `getScale()`. Grep found no
  Job/Console/Listener/Projection caller of `LandedCostService` or `ReceiptBatchCostAllocator`,
  so the HTTP-context assumption still holds. Not introduced by this commit; re-check if
  goods receipt is ever queued.
- `LinkedCostApplicationService.php:66-67` correctly passes the entity currency.

---

## Findings

### [IMPORTANT] BLOCKING — ticket-mandated tripwire left encoding the bug
`apps/web/e2e/money-campaign/purchasing-landed-cost.spec.ts:174,216-219` — the MTP-PUR-17
tripwire still asserts `'19.999000'`, `'10.001000'`, `'11.999900'`, `'12.000200'`, and the
test title still reads "Sigma is exact; the SPLIT drifts 1 millime". The ticket's "When fixed"
clause requires updating it to `20.000 / 10.000 / 12.000000 / 12.000000`.
**Why it matters:** the money campaign now goes red against CORRECT code, and the executable
spec documents the defect as expected behaviour — a direct revert hazard.
**Fix:** update the four expectations and the title/comment block (`:208-219`) in this commit.

### [IMPORTANT] "largest-remainder" is a documentation lie; absorber skew unbounded and untested
`LandedCostService.php:30-31` ("largest-remainder reconciliation"), `LandedCostBcmathTest.php:193`
("the largest-remainder absorber"), ticket §"MTP-PUR-18 … the largest-remainder logic".
The implementation is **last-positive-base absorber** (`ProportionalMoneyAllocator.php:33,40-41,53-54`),
which is honestly documented only in the allocator's own docblock (`:10-13`).
**Why it matters:** the ticket's stated harm is mis-attribution ("one product perpetually
over-costed"); this commit removes the truncated-proportion component but leaves the
absorber concentrating up to `(n-1)` currency units on one product's WAC/COGS
(measured 0.009292 at n<=12). A reader trusting "largest-remainder" would believe the skew
is capped at 1 unit per line.
**Fix:** correct the two docblocks now; ticket a real largest-remainder distribution with a
test pinning the per-line skew bound.

### [MINOR] dead sibling helper still carries the exact pre-fix drift and returns float
`LandedCostService.php:383-398` — `calculateAllocatedCost(float,float,float): float` still does
`bcdiv` proportion (`:395`) then `bcmul` (`:397`), i.e. the shape this commit deleted, and casts
to float at `:397`. Verified callers: tests only (`LandedCostServiceTest.php:30,42,53,91,104,112`) —
no production caller.
**Why it matters:** it is the "preview" of the same figure; the first UI that wires it up will
show `19.999` next to a persisted `20.000`, and the float return breaches rule 19.
**Fix:** delete it, or route it through `ProportionalMoneyAllocator` and return a string.

### [MINOR] numerator still truncated at workingScale before the division
`ProportionalMoneyAllocator.php:66` — `bcmul($formattedTotal, $base, $workingScale)` drops up to
`10^-workingScale` of the numerator before `bcdiv`; the residual error is
`10^-workingScale / subtotal`, so it only becomes a full money unit when `Σ bases` falls below
~`10^(scale-workingScale)` (harmless in all probed real shapes, and conservation is unaffected).
**Fix (optional):** `bcmul(..., $workingScale * 2)` makes the numerator exact for any input.

### [MINOR] mixed-sign bases mis-attribute; no test pins the behaviour
`ProportionalMoneyAllocator.php:38` adds every base (including negatives) to `$subtotal`, while
`:55-56` force a non-positive base to share `0`. Probe:
`allocate('30.000', ['100.000','-50.000','100.000'], 3, 7)` → `['20.000','0.000','10.000']` — two
identical `100.000` bases receive 20 vs 10. Conservation still holds. Reachable only if a PO
line can carry a negative `line_total` — **cannot verify** that from this diff.
**Fix:** exclude negative bases from `$subtotal` (or reject them) and add a unit test.

### [MINOR] WAC proof is a single write, not a running average
`LandedCostBcmathTest.php:342-369` asserts `Product::cost_price` on a FRESH product (owned qty 0),
where WAC trivially equals the landed unit cost. It proves the conserved figure enters the WAC;
it does not prove the running average across purchase→sale→purchase.
**Fix:** extend with a second receipt at a different landed cost and assert the weighted result.

---

**What to fix before merge:** update the MTP-PUR-17 tripwire in
`apps/web/e2e/money-campaign/purchasing-landed-cost.spec.ts:174,208-219` to the conserved
`20.000 / 10.000 / 12.000000 / 12.000000`, and correct the two "largest-remainder" docblocks
(`LandedCostService.php:30-31`, `LandedCostBcmathTest.php:193`) to say last-positive-base absorber.

---

# Fix-round re-verify — `c5aa0eb72` (group A)

**Scope of this re-verify:** commit `c5aa0eb72` ONLY, narrow to gate findings A1 (tripwire flip)
and A2 (largest-remainder mislabel). Later siblings `9762db355` / `857c7a9c5` confirmed to touch
neither `ProportionalMoneyAllocator` nor `LandedCostService` (`git show --name-only` → none).

**Commit surface:** 3 files — `LandedCostService.php` (+comments), `LandedCostBcmathTest.php`
(+docblock), `purchasing-landed-cost.spec.ts`.

## A1 — MTP-PUR-17 tripwire flipped ✅

`apps/web/e2e/money-campaign/purchasing-landed-cost.spec.ts`:

- `:178` title now reads "Sigma is exact and the SPLIT is exact" (was "the SPLIT drifts 1 millime").
- `:206-212` the tripwire comment is **retained and rewritten** as a FIXED note: it still records
  the old truncated-proportion mechanism and the `19.999998 -> 19.999` arithmetic, states the
  one-step fix, and now points at the REAL ticket
  (`2026-08-03-w4-purchasing-inventory-defects.md #1`) instead of the non-existent
  `2026-08-03-w4-landed-cost-proportion-truncation.md` the old comment cited. Provenance preserved.
- `:213-216` assertions now `'20.000000'`, `'10.000000'`, `'12.000000'`, `'12.000000'`.
- `:204` the Σ invariant assertion (`sumMoney(...) === '30.000'`) is untouched.
- `:236` MTP-PUR-18 (`3.333/3.333/3.334`) untouched — correct, that shape never drifted.

**Figures cross-checked three ways:**

1. **PHPUnit golden parity** — `LandedCostBcmathTest.php:331-334` asserts the identical strings
   (`'20.000000'`, `'10.000000'`, `'12.000000'`, `'12.000000'`) and passes on live PG.
2. **Emission format** — `persistedLandedCosts()` (`apps/web/e2e/money-campaign/w4-support.ts:130-146`)
   reads RAW `document_lines.{allocated_costs,landed_unit_cost}` via SQL, `order by line_number`,
   so `rows[0]` is L1. Both columns are scale 6
   (`database/migrations/tenant/2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php:55`), so
   PG renders exactly `20.000000` / `12.000000` — the 6-dp strings are format-correct, matching the
   6-dp shape of the values they replace (`19.999000` / `11.999900`).
3. **Flow arithmetic** — the e2e drives `POST /purchase-orders/{id}/confirm`
   (`allocateCostsAndTaxes()`), not `allocateCosts()`. Both route the additional-cost split through
   the same `allocatePositiveShares()` (`LandedCostService.php:311`). The recorded PRE-fix live value
   `11.999900 = (100.000 + 19.999)/10` proves `non_recoverable_tax = 0` on this flow (VAT 19% is
   recoverable), so post-fix `(100.000 + 20.000)/10 = 12.000000` is the correct expectation.
   The flip is exactly the ±0.001 correction on the same flow — no other term moved.

**Caveat (honest, matches the commit message):** `apps/web/node_modules` does not exist in this
worktree, so Playwright was NOT executed. Confirmation above is by assert-shape review + PHPUnit
golden parity + column-scale/flow arithmetic, not by a live campaign run.

## A2 — "largest-remainder" mislabel corrected ✅

All three sites now say last-positive-base absorber, and the first one additionally states the
skew consequence:

- `LandedCostService.php:27-36` — "last-positive-base absorber (NOT a largest-remainder
  distribution — the entire running remainder is assigned to the last line with a positive base)
  … concentrates all truncation residue on that one line rather than spreading it by remainder size".
- `LandedCostService.php:110-112` — the `->values()` re-key comment retitled to
  "last-positive-base absorber".
- `LandedCostService.php:382-386` — the `calculateAllocatedCost()` preview docblock retitled.
- `LandedCostBcmathTest.php:192-197` — P2-4 regression docblock retitled.

Accurate against the implementation (`ProportionalMoneyAllocator.php:33,40-41,53-54`).

## Mechanism untouched — verified mechanically

`git show c5aa0eb72 -- 'apps/api/*.php' | grep '^[+-]' | grep -v '^[+-]\s*\(\*\|//\|/\*\*\)'`
returns **empty** — every changed PHP line is a comment or docblock line. No executable statement
was altered. The allocator itself (`ProportionalMoneyAllocator.php`) is not in the commit at all.

**Re-run after the fix round:** `ProportionalMoneyAllocatorTest` + `LandedCostBcmathTest` +
`LandedCostServiceTest` → **16 passed (41 assertions)**; PHPStan on `LandedCostService.php`
→ **[OK] No errors**.

## VERDICT (fix round): CLEAR TO MERGE

Both gated findings are closed. Blocking item I-1 is resolved; I-2 is downgraded from a
documentation defect to an accurately-documented known limitation.

## Open (non-blocking, carried forward — none block this merge)

1. **Absorber distribution defect itself** — up to `(n-1)` currency units of truncation residue
   still land on one product's WAC/COGS. Now honestly documented; the commit message says it "is
   being ticketed separately". **Cannot verify**: no ticket file in
   `docs/superpowers/tickets/` mentions the absorber other than the original
   `2026-08-03-w4-purchasing-inventory-defects.md`. Ask the coordinator to confirm the ticket exists.
2. `LandedCostService.php:387-402` `calculateAllocatedCost()` — still the pre-fix two-step shape
   (`bcdiv` proportion → `bcmul`) and still returns `float`. Only its docblock changed. Test-only
   callers; delete or re-route.
3. `ProportionalMoneyAllocator.php:66` — numerator truncated at `workingScale` before the division
   (harmless in all probed shapes; `workingScale * 2` would make it exact).
4. Mixed-sign bases mis-attribute (`ProportionalMoneyAllocator.php:38` vs `:55-56`); no test pins it.
5. `LandedCostBcmathTest.php:342-369` proves the WAC on a first purchase only, not a running average.
