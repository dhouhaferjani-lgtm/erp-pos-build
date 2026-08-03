# Adversarial review gate — credit-note money lane (2026-08-03)

**Scope:** local `dev`, NOT pushed. Commits `b885ae770` (CreditNoteService: largest-remainder
amount allocation + `documentTaxTotal` fold on all 3 creation paths + scale-4 → currency-scale;
new `CreditNoteMoneyLaneTest`; E2E value updates MTP-DOC-16/18/20/23) and `6f802535b`
(`CopiesDocumentData` blank-currency guard).

**Context tickets:** `docs/superpowers/tickets/2026-08-02-credit-note-draft-stamp-and-scale4-totals.md`,
`2026-08-02-orchestrator-smoke-findings.md` (finding 1), `2026-08-03-f2f3-regate-carryovers.md` (R2).

**Ruling in force:** `amount` = VAT-inclusive credit EXCLUDING document-level duties; posted total
= `amount + documentTaxTotal` exactly; internal decomposition reconstructs `amount` byte-exactly at
currency scale.

**Environment:** local stack api :8010, tenant `demo-pharmacy-tn`
(`tenant019fbe86-944a-7252-8a3b-8c341dfa9de9`), owner@pharmabio.tn. All live fixtures created with
the `W3c-` prefix. Nothing pushed, nothing migrated.

---

## VERDICT: **REJECT**

The root-cause analysis is correct and the amount-based reconstruction is genuinely right *when it
succeeds*. But the new code converts a wrong number into a **hard HTTP 500 on 1.7 %–16 % of
legitimate credit-note amounts** (live-reproduced), and the commit's headline claim — "Drafts now
equal their post-confirmation totals" — is **false on 2 of the 3 paths it touches**, also
live-reproduced. Two of the three blockers are in code this commit introduces.

| # | Severity | Finding | Where |
|---|---|---|---|
| A1 | **P0 BLOCKER** | Bounded search throws `RuntimeException` → HTTP 500 for 1.7–16 % of valid amounts | `CreditNoteService.php:342-346`, `CreditNoteController.php:202-209` |
| A2 | **P1** | Sub-tick knob is inert when group line 0 is zero-weight/zero-priced → failure rate 16 % | `CreditNoteService.php:279-298` |
| B1 | **P1 BLOCKER** | Line-based path: draft 24.400 vs confirm 22.020 (10.8 % divergence) — fold certifies a false total | `CreditNoteService.php:718-732, 786-809` |
| B2 | **P1** | `confirm()` never rewrites `subtotal` → persisted fiscal doc where `subtotal + tax_amount ≠ total` | `CreditNoteController.php:275-278` |
| B3 | **P2** | Standalone path: draft 31.168 vs confirm 31.169 (carry-over R1 unfixed, now falsely blessed) | `CreditNoteService.php:886-894` |
| D1 | **P1 BLOCKER** | Over-credit: `amount` (duty-exclusive) compared against duty-inclusive remaining → `balance_due` = **−0.600** live | `CreditNoteService.php:575, 584-590, 1006` |
| C1 | **P2** | Fabricated quantity + **negative `discount_amount`** persisted, out of the API's own contract | `CreditNoteService.php:290-309, 523-536` |
| C2 | **P3** | Fabricated quantity collides with rule-19 unit display precision (0.4244 on a `decimal_places=0` unit) | same |
| E1 | ✅ | Regressions clean: 72 CN/GL tests green, pint pass, phpstan pass, 13 pre-existing `IngressPrecisionTest` errors unchanged | — |
| R2 | ✅ | `6f802535b` blank-currency guard is byte-identical to its sibling | `CopiesDocumentData.php:291-297` |

---

## A. Is the largest-remainder + bounded search guaranteed to terminate and find a valid split?

**Terminates: yes. Finds a split: NO — and it hard-fails when it doesn't.**

The search space is finite and fixed: 21 currency-tick offsets (`CreditNoteService.php:236-241`)
× 19 quantity sub-tick deltas (`:252-259`) = 399 candidates per rate group. There is no loop that
can fail to terminate. On exhaustion it **throws** (`:342-346`):

```php
throw new \RuntimeException(
    'Unable to allocate the credit-note amount exactly across the source invoice lines '
    .'(bounded largest-remainder search exhausted for rate '.$ratePercent.'%).'
);
```

### A1 — P0 BLOCKER: exhaustion is common, and it surfaces as HTTP 500

`CreditNoteController::store()` catches **only** `\InvalidArgumentException`
(`CreditNoteController.php:202-209`). A `RuntimeException` propagates uncaught.

**Live repro** (single line 1 × 99.000 @ 19 %, INV-2026-0507, total 118.810):

```
[control-round] amount=50.000  HTTP=201  total=50.600 subtotal=42.017 tax=8.583
[HOLE]          amount=20.085  HTTP=500  {"message":"Unable to allocate the credit-note amount
                                          exactly ...","exception":"RuntimeException"}
```

Second live repro (single line 1 × 100.000 @ 19 %): `amount=0.093 → HTTP 500`.
Third live repro (multi-rate 100.000 @ 19 % + 50.000 @ 7 %, INV-2026-0508):
`amount=41.117 → HTTP 500`, while 50.000 / 33.333 / 20.085 / 12.345 all succeed.

**Exhaustive characterisation.** Reflection-invoking the real `allocateGroupExactly()` over every
millime target (scale 3) — script kept at
`<scratchpad>/probe_alloc.php`, run as `php probe_alloc.php <mode> <rate> <from> <to>`:

| Line shape (rate 19 %) | Range | Checked | `RuntimeException` | Rate |
|---|---|---|---|---|
| synthetic single line, `unit_price 1.000` (the no-real-lines fallback, `:499-509`) | 0.001–20.000 | 20 000 | 336 | **1.68 %** |
| single real line 1 × 99.000 | 0.001–20.000 | 20 000 | 336 | **1.68 %** |
| 2 real lines (10 × 12.500 + 4 × 25.000 — the MTP-DOC-16 fixture) | 0.001–20.000 | 20 000 | 1 045 | **5.23 %** |
| 2 real lines, **first line zero-priced** | 0.001–20.000 | 20 000 | 3 193 | **15.97 %** |
| synthetic single line | 20.000–120.000 | 100 001 | 1 680 | **1.68 %** |
| 2 real lines | 20.000–120.000 | 100 001 | 5 229 | **5.23 %** |
| 2 real lines, first zero-priced | 20.000–120.000 | 100 001 | 15 966 | **15.97 %** |

The failure rate is **magnitude-independent** (identical at 0.001–20 and 20–120), so this is not a
small-amount edge case — it is a uniform ~1 in 60 (single line) to ~1 in 6 (zero-weight first line)
chance on every amount-based credit note. It is also rate-dependent but never zero:
7 % → 0.94 %, 13 % → 1.77 %, 19 % → 1.68 % (20.000–60.000, synthetic).

**Multi-rate compounds it.** `allocateAmountAcrossInvoiceLines()` (`:410-426`) runs an independent
search per rate group and any group's exhaustion aborts the whole request, so
P(fail) ≈ 1 − Π(1 − pᵢ). The live 19 %+7 % invoice failed at 41.117 with the message naming the
19 % group.

**Answer to "what does the code DO in a hole?"** It does **not** fall back to nearest-reachable and
it does **not** silently drift — it aborts the transaction and returns a 500 with an internal
exception message. That is honest but unusable. There is **no** test covering the throw path, and
the ruling's "reconstructs exactly" clause is satisfied only on the ~98.3 % / ~84 % of inputs that
happen to be reachable.

**Required fix (my read):** the mapping `net → net + trunc(net·r)` genuinely has unreachable
inclusive values under truncation; you cannot close every hole by widening the search. Pick a
declared policy and assert it:
1. **Nearest-reachable + declared 1-tick deviation** — snap to the closest reachable inclusive
   value, surface the delta to the operator ("credited 20.084, requested 20.085 — VAT rounding"),
   and value-assert the bound (`|actual − requested| ≤ 1 tick`); or
2. **422 with an actionable message** naming the nearest reachable amounts, not a 500; or
3. Reject at validation time (compute reachability before doing any work).

Whichever is chosen, the deviation must be bounded at 1 tick **and surfaced** — the ruling's "exactly"
clause cannot be met unconditionally, so the ruling itself needs the escape hatch written into it.

### A2 — P1: the sub-tick knob is inert whenever group line 0 carries no weight

The sub-tick sweep is applied only to index 0 of the group (`:291-298`, `if ($i === 0 && …)`), but
index 0 is `continue`d out before that whenever `targetNet <= 0` (`:279-281`) or
`unitPrice <= 0` (`:285-287`). When that happens all 19 sub-tick deltas produce byte-identical
results and the search collapses to the 21 net offsets — the 16 % column above.

This is reachable with ordinary data: bonus/freebie lines (`unit_price 0`), 100 %-discount
promotional lines (group weight is `calculateTotal()`, `:225`, which nets the discount to 0), and
small amounts where largest-remainder assigns 0 ticks to the first line. Move the knob to the first
line that actually *survives* the skip filters, or sweep all lines.

### Answers to the specific adversarial inputs

| Input | Behaviour | Verified |
|---|---|---|
| amount < 1 tick per group | OK — zero-target groups are skipped (`:413-415`); 0.001 / 0.002 / 0.005 all return 201 | live |
| single line | OK for reachable amounts; **1.68 % throw** | live + probe |
| 0 %-VAT mixed with taxed | OK — 0 % group is exact at offset 0; 50.000 / 33.333 / 0.001 all 201 on a 19 %+0 % invoice | live |
| 3+ rate groups | not fixtured; per-group failure compounds multiplicatively by construction (`:410-426`) | reasoned |
| amount == invoice total exactly | Creates successfully **and over-credits** — see D1 | live |
| 0.001 | 201, `total=0.601` (0.001 credit + 0.600 stamp). Ruling-correct but note the CN costs the company 600× the credit | live |

### A3 — dead-but-unguarded branch (P3, note only)

`largestRemainderAllocate()`'s negative-shortfall branch (`:181-188`) silently *skips* a tick
removal when `bases[idx] < tick`, which would break the sum-exactly invariant. It is unreachable
today (bases are truncations of shares, so `allocatedSum ≤ total` always for non-negative inputs),
but it is the one place where the primitive can return allocations that do not sum to `$total`.
Either assert the invariant or delete the branch.

---

## B. Draft == confirm == posted?

`CreditNoteController::confirm()` (`:272-278`) **recomputes** from the materialised lines and
overwrites — it does not add — so there is **no double-apply of `documentTaxTotal`**. The fold
(`CreditNoteService.php:84-101`) consumes only `documentTaxTotal`, never `totalTax`, so the
unconfigured-rate re-zeroing trap (ticket `2026-08-02-confirm-zeroes-VAT-unconfigured-rates.md`) is
correctly avoided. **The fold itself is sound.**

Structural equality on the amount path is genuine: `TaxCalculationService::calculateSubtotal()`
(`:239-265`) computes `trunc(computeLineTotal(scale+1), scale)`, and because
`discountAmount = gross@scale − targetNet` is exact at `scale`, that re-truncates back to
`targetNet` exactly; `calculateDocumentTaxes()` STEP 1 (`:129-142`) uses the same
`rateFraction(6)` / `scale+1` accumulate / `bcformat` round-once pipeline the search emulates at
`:304-332`. Verified byte-identical by reading, and live.

### Path-by-path

| Path | Draft | Confirm | Verdict |
|---|---|---|---|
| Amount-based, TN repro (1 × 99.000 @19 %, amount 50.000) | 50.600 (sub 42.017 / tax 8.583) | 50.600 | ✅ live |
| Amount-based, multi-rate 19 %+7 % | 50.600 / 33.933 / 20.685 / 12.945 | — | ✅ create; confirm not separately probed for these |
| Amount-based, 19 %+0 % | 50.600 / 33.933 / 0.601 | — | ✅ create |
| Amount-based, amount == invoice total | 120.600 | 120.600 → posted 120.600 | ✅ equal (but see D1) |
| **Line-based, discounted source line** | **24.400** | **22.020** | ❌ **B1** |
| **Standalone, boundary-dirty (2 × 14.285 @7 %)** | **31.168** | **31.169** | ❌ **B3** |
| Non-TN / no stamp config | `documentTaxTotal='0'` → fold is a no-op; confirm's `totalTax == lineItemsTaxTotal`; equality reduces to the same reconstruction | reasoned, not probed (no non-TN tenant on this stack) |

### B1 — P1 BLOCKER: line-based path, 10.8 % draft/confirm divergence

**Live** (INV-2026-0512: 10 × 10.000 with `discount_percent 10` @ 19 % → sub 90.000, total 108.100;
line-based CN for qty 2):

```
create : total=24.400  subtotal=20.000  tax_amount=4.400
confirm: total=22.020  subtotal=20.000  tax_amount=4.020
```

Cause: `createLineBasedCreditNote()` computes `lineSubtotal = bcmul($quantity,
$invoiceLine->unit_price, $scale)` (`:718`) and `line_total` the same way (`:786`) — **ignoring the
discount entirely** — while it *copies* `discount_percent` and `discount_amount` onto the CN line
(`:795-796`). At confirm, `computeLineTotal()` (`DocumentLine.php:272-290`) applies the copied
discount, so the recomputed net is 18.000 against a stored 20.000.

Worse for the flat-amount case: `discount_amount` is copied **wholesale, not prorated by quantity**
(`:796`). Credit 1 of 10 units on a line with `discount_amount 50.000` and the CN line's net goes
negative at confirm.

This is pre-existing arithmetic, but the fold (`:809`) now writes a *confident, stamp-inclusive*
draft total on top of it, and the commit message asserts "Drafts now equal their post-confirmation
totals". The new test never confirms the line-based CN (`CreditNoteMoneyLaneTest.php:330-341` stops
at `assertCreated`) and its fixture has no discount, so the claim is untested where it is false. The
E2E MTP-DOC-20 update (`documents-credit-notes.spec.ts`) does the same.

### B2 — P1: `confirm()` leaves `subtotal` stale → `subtotal + tax_amount ≠ total`

`CreditNoteController.php:275-278` writes only `tax_amount` and `total`. On the B1 document the
persisted row is `subtotal=20.000, tax_amount=4.020, total=22.020` — 20.000 + 4.020 = 24.020 ≠
22.020. A confirmed fiscal document whose own three money columns do not reconcile is a
certification problem independent of B1's magnitude, and it will fire on **any** path where the
recomputed subtotal differs from the stored one. Add `'subtotal' => $taxResult->subtotal` to the
confirm update.

### B3 — P2: standalone path still carries carry-over R1

**Live** (standalone, 2 lines of 14.285 @ 7 %): create `31.168` → confirm `31.169`. Same 1-millime
class as R1 in `2026-08-03-f2f3-regate-carryovers.md`: `createStandaloneCreditNote()` truncates each
line's tax at `$scale` (`:886-888`) instead of accumulating at `scale+1` and rounding once. R1 is
explicitly *not* claimed fixed by this commit — but the fold now makes the draft assert equality it
does not have. Unify both hand-rolled loops (`:718-724`, `:886-891`) onto
`TaxCalculationService`'s STEP 1 pipeline; that closes B1, B3 and R1 in one move.

---

## C. Money purity

**bcmath only — clean.** `grep -n "(float)|floatval|number_format|round\(|intval"` over
`CreditNoteService.php` returns exactly one hit: `:172`
`$ticksToDistribute = (int) bcdiv($shortfall, $tick, 0)` — an integer **tick count**, correctly
commented `precision-ok`. No float touches money or quantity anywhere in the diff.

**Scale literals — justified.** Every literal carries a `precision-ok` comment and each one checks
out: `6` at `:218` / `:395` / `:468` matches `TaxCalculationService.php:130`'s own `rateFraction`
scale; `4` at `:256/:290/:295-300` is the canonical quantity storage scale; `2` at `:468/:472` is
the `tax_rate` column scale; `scale+6` at `:125` and `scale+1` at `:307-315` are intermediates.
Round-once at the boundary is respected (`CurrencyScale::bcformat` at `:332`, one per rate group,
mirroring `TaxCalculationService.php:142`).

Guard gates pass: `pint --test` → `{"result":"pass"}`; `phpstan analyse` on both changed files →
`[OK] No errors`. `ForbidFixedScaleQuantityLiteralRule` is Presentation-scoped
(`app/PHPStan/Rules/ForbidFixedScaleQuantityLiteralRule.php:58-70`) so the Application-layer scale-4
literals are legitimately exempt.

### C1 — P2: the search DOES mutate quantities, and emits an out-of-contract `discount_amount`

Yes — `:290-302` derives the quantity from the target net and then **nudges it by up to
±0.0009** to exploit the truncation asymmetry, and `:309` back-solves a `discount_amount` residual.
Both are persisted verbatim (`:523-536`).

**Live evidence** — CN-2026-0029, the control 50.000 credit against 1 × 99.000 @ 19 %:

```sql
line_number | description | quantity | unit_price | discount_percent | discount_amount | tax_rate | line_total
          1 | W3c A       |   0.4244 |     99.000 |                  |          -0.002 |    19.00 |     42.017
```

- **`discount_amount = -0.002` is negative.** The documents API forbids it:
  `CreateDocumentRequest.php:130` is `['nullable','numeric','min:0', …]`. The system therefore
  authors credit-note lines it will refuse on its own edit/update endpoint — any round-trip through
  the edit form 422s or silently clamps and changes the totals. A negative discount also reads to a
  human (and on `resources/views/documents/templates/credit_note.blade.php`) as a **surcharge**.
- **`0.4244` is a fabricated quantity** the operator never chose. Mitigating: I checked and credit-note
  posting does **not** currently create stock movements (no `DocumentType::CreditNote` reference
  anywhere under `app/Modules/Inventory/`), so the commit message's "(and restock)" parenthetical
  overstates today's behaviour and no fractional stock is created. The exposure is display/print and
  the API contract, not inventory.

### C2 — P3: fabricated quantity vs rule-19 unit display precision

The live product is `Anti-Aging Serum … #10`, unit **Piece, `decimal_places = 0`**. Under rule 19
(quantities surfaced to humans render at `units.decimal_places`), that line displays as
**`0 pc × 99.000 = 42.017, remise −0.002`**. Arithmetically unexplainable to an auditor. This is
inherent to any allocation that expresses money as a quantity against a whole-unit product; the
mitigation is presentational (show the credited *amount*, not a synthetic quantity, on credit-note
lines materialised from an amount-based credit) rather than a change to the search.

---

## D. Over-credit guard

Guards live at `CreditNoteService.php:575` (`amount ≤ invoice.total`) and `:584-590`
(`amount ≤ invoice.total − Σ creditNotes.total`, status-blind).

**Good half (as the commit claims):** with the drift fixed, `Σ total` is now *larger* than before
(previously the drift under-counted), so the cumulative comparison is strictly more conservative
than it was. Confirmed by `CreditNoteMoneyLaneTest::test_over_credit_guard_still_refuses_…`
(268.750 − 100.600 = 168.150 < 200.000 → 422) and by MTP-DOC-18's updated arithmetic. The old drift
edge — where a 100.000 credit only consumed ~99.4 of the invoice's headroom, leaving room for an
over-credit that is now refused — is genuinely closed.

### D1 — P1 BLOCKER: the guard compares a duty-EXCLUSIVE amount to a duty-INCLUSIVE remaining

Both guards test `$amount` — which under the ruling **excludes** the CN's own duty — against a
remaining computed from `sum('total')`, which **includes** every prior CN's duty. And the value that
is actually allocated against the invoice is `$creditNote->total` (`:1006`), duty-inclusive.
Algebraically, the last credit note can always overshoot by exactly one duty:

`amountₙ ≤ invoice.total − Σᵢ<ₙ totalᵢ` ⟹ `Σ totalᵢ ≤ invoice.total + stamp`.

**Live repro** (INV-2026-0510, 1 × 100.000 @ 19 % → total 120.000, unpaid):

```
create  amount=120.000  →  201  total=120.600  subtotal=100.841  tax=19.759
confirm                  →  total=120.600
post                     →  total=120.600
invoice after: sub=100.000 tax=20.000 total=120.000  balance_due=-0.600
```

`balance_due = −0.600` on a fully-credited invoice. It propagates: the PG trigger and
`Document::getOutstandingAmount()` (`Document.php:719-733`) both subtract
`SUM(credit_note_allocations.amount)` from `total`, so the negative balance flows into
`outstanding_amount`, `payment_status`, AR aging (`AgedReceivablesService.php:261`) and treasury
(`PaymentRefundService.php:843`).

The commit message's "if anything it is now MORE conservative than before … never less" is therefore
**not correct at the boundary**, and the new test never exercises the boundary — it refuses 200.000
against a remaining of 168.150 (a 32-unit margin) and accepts 150.000 against the same remaining
(an 18-unit margin). Neither touches `amount == remaining`.

**The "wrongly refuse a legitimate credit" edge you asked about is also real, and it is the same
bug from the other side.** Invoice total 100.000; CN#1 for `amount 40.000` posts at 40.600, so the
invoice's true remaining *balance* is 59.400. The operator credits the rest and enters 59.400 →
accepted, CN#2 posts at 60.000, Σ = 100.600, balance −0.600. If instead the guard were tightened to
compare the *duty-inclusive* CN total against the remaining, the operator entering 59.400 would be
**refused** even though 59.400 is exactly the remaining balance — the only accepted value would be
58.800, which is not a number any operator would derive. So the guard cannot simply be tightened:
the fix needs a decision on whether the CN's own stamp consumes invoice headroom at all (it is a
duty on the credit note, not a reversal of invoice value), and the guard must then compare
like-for-like on both sides. **This needs an orchestrator ruling before a fix is written.**

Also note the two guards are already inconsistent with each other: `createCreditNote()` compares
the duty-exclusive `$amount` (`:588`), while `createLineBasedCreditNote()` compares its
pre-fold `$total` (`:742`) — a third quantity again.

---

## E. Regressions

| Check | Result |
|---|---|
| `CreditNoteMoneyLaneTest` (new) | **OK, 4 tests / 27 assertions** |
| `CreditNoteServiceTest`, `CreditNoteIntegrationTest`, `CreditNoteAllocationTest`, `CreditNoteTenantIsolationTest`, `Types/CreditNoteDocumentTest`, `TaxSnapshotCreditNoteTest` | **OK, 51 tests / 160 assertions** (3 pre-existing skips) |
| `CreditNoteGLIntegrationTest`, `InvoiceAndCreditNoteGLIntegrationTest` | **OK, 17 tests / 134 assertions** |
| `tests/Feature/Document/IngressPrecisionTest` | **13 errors, unchanged** — all `ArgumentCountError: Too few arguments to … CreateDocumentRequest::__construct()`, pre-existing and unrelated to this lane |
| `pint --test` (3 changed files) | pass |
| `phpstan analyse` (2 changed app files) | `[OK] No errors` |
| MTP-DOC-19 (relational) | asserts `balance_due` drop == **posted CN total**, not the entered amount (`documents-credit-notes.spec.ts:165-174`) — stays green |
| MTP-IDEM-03 (relational) | asserts status/idempotency only (`idempotency.spec.ts:150`) — stays green |
| Treasury `allocateCreditNote` consumers | allocation amount is `creditNote->total` (`:1006`); `Document::getOutstandingAmount()` (`Document.php:728`), `DocumentCacheValidationService`, `AgedReceivablesService:261`, `PaymentRefundService:843` all consume `SUM(credit_note_allocations.amount)` — mechanically fine, but they faithfully propagate D1's negative balance |

No regressions introduced. The test *suite* is green; the *invariants* are not.

### Test coverage gaps (all of these would have caught a blocker)

- No case exercising an unreachable target (A1) — the entire throw path is untested.
- Line-based and standalone CNs are created but **never confirmed**
  (`CreditNoteMoneyLaneTest.php:330-357`) — B1/B3 invisible.
- No discounted source line anywhere in the fixtures — B1 invisible.
- No boundary over-credit case (`amount == remaining`) — D1 invisible.
- No multi-rate (19 %+7 %), no 0 %-mixed, no non-TN/no-stamp-config case, despite the fold
  touching all of them.
- No assertion on the materialised line values (quantity / `discount_amount` sign) — C1 invisible.

---

## R2 — `6f802535b` blank-currency guard: ✅ APPROVED

`CopiesDocumentData.php:291-297` now reads

```php
$currency = $document->currency;
$scale = $currency !== ''
    ? $this->scaleResolver->getScale($currency)
    : $this->scaleResolver->getScaleSafe(null, 3);
```

byte-identical in behaviour to the sibling it cites, `TaxCalculationService::scaleFor()`
(`TaxCalculationService.php:41-50`). The rationale checks out against the resolver:
`CurrencyScale::for('')` falls through `SCALE_MAP[''] ?? DEFAULT_SCALE` → 2
(`CurrencyScale.php:62-65`), whereas `getScaleSafe(null, 3)` resolves the bound company's
`country.currency_decimal_places` (`CurrencyScaleResolver.php:36-68`) and only falls back to 3 when
no context is bound — which is exactly the intent. `CreditNoteService::scaleFor()` (`:52-61`)
applies the same guard and `createStandaloneCreditNote()` (`:859-861`) inlines it for the partner
currency. Consistent. No findings.

---

## Required before re-gate

1. **A1** — decide and implement the unreachable-target policy (nearest-reachable + surfaced,
   bounded ≤ 1 tick / 422 / pre-validation). Never a 500. Value-assert it, including the deviation bound.
2. **A2** — move the sub-tick knob to the first *surviving* line (or sweep all lines).
3. **B1 + B3 + R1** — unify `createLineBasedCreditNote()` (`:718-724`) and
   `createStandaloneCreditNote()` (`:886-891`) onto `TaxCalculationService`'s STEP 1 pipeline;
   prorate `discount_amount` by credited quantity (`:796`). Then confirm every path in tests.
4. **B2** — `confirm()` must rewrite `subtotal` alongside `tax_amount` / `total`.
5. **D1** — orchestrator ruling on whether the CN's own duty consumes invoice headroom, then make
   both guards compare like-for-like; add a boundary test (`amount == remaining`, `amount ==
   invoice.total`) asserting `balance_due >= 0`.
6. **C1** — clamp `discount_amount` to ≥ 0 (or drop the residual mechanism) so the system does not
   author lines its own API rejects.
7. **Correct the commit message** — "Drafts now equal their post-confirmation totals" and "the
   over-credit guard … never less [conservative]" are both falsified above.

Nothing merged, nothing pushed. Local `dev` untouched apart from this record (uncommitted).

---

# Re-gate ba7be2ce2

**Scope:** `ba7be2ce2` "credit-note re-gate — never-500 quantization, allocation clamp, discount
proration, subtotal reconciliation", sitting on top of this record (`8b92088eb`). 4 files:
`CreditNoteService.php`, `CreditNoteController.php`, `CreditNoteMoneyLaneTest.php` (+7 tests),
new `tests/Unit/Document/CreditNoteAllocationExhaustiveProbeTest.php`. **No `apps/web` file was
touched** — the E2E specs are byte-identical to the ones reviewed above.

**Method:** every claimed disposition re-verified against code with file:line, plus my own probes
re-run from scratch (not the implementer's). Live stack api :8010, `demo-pharmacy-tn`, `W3c-`
fixtures only. Nothing merged, nothing pushed.

## VERDICT: **APPROVE-WITH-FIXES** — promotable

All three REJECT blockers (A1, B1, D1) are genuinely fixed and independently reproduced as fixed.
A2, B2, B3, C1 likewise. The C2 stop-on is **correct** and **correctly scoped**. Five new
non-blocking findings, one of which (N1) must be ticketed before the credit-note GL goes in front
of a certification reviewer.

| # | Was | Now | Verified by |
|---|---|---|---|
| A1 | P0 — HTTP 500 on 1.7–16 % of amounts | **FIXED** — 0 throws, 0 upward drift, worst deviation 1 millime | my probe (172 k checks) + live |
| A2 | P1 — knob inert, 15.97 % failure | **FIXED** — 15.97 % → 1.775 % inexact, 0 failures | my probe |
| B1 | P1 — draft 24.400 vs confirm 22.020 | **FIXED** — 22.020 == 22.020 == 22.020 | live |
| B2 | P1 — `subtotal + tax ≠ total` persisted | **FIXED** — reconciles on every probed path | live |
| B3 | P2 — standalone 31.168 → 31.169 | **FIXED** — 31.169 == 31.169 == 31.169 | live |
| C1 | P2 — negative `discount_amount` | **FIXED** — 0 negatives in 26 fresh CN lines | DB |
| D1 | P1 — `balance_due = −0.600` | **FIXED** — floors at 0.000; both guard directions correct | live |
| C2 | P3 — display | **STOPPED-ON, correctly** — but re-rate P2, gates the PDF surface | adjudicated below |
| **N1** | — | **NEW P2** — GL AR vs AR subledger diverge by the clamped residue | live + DB |
| **N2** | — | **NEW P2** — quantization is API-only; the web UI never shows it | grep |
| **N3** | — | **NEW P3** — deviation can be 1.000 TND, not 1 millime, on a duty-bearing invoice | live |
| **N4/N5** | — | **NEW P3** — per-CN (not cumulative) qty ceiling; N+1 in the headroom guard | code read |

---

## A1 — never-500 quantization: **VERIFIED FIXED**

`allocateGroupExactly()` (`CreditNoteService.php:301-457`) no longer has a `throw` anywhere. It
tracks `$bestFloor` across the whole bounded search (`:364-365`, `:449-452`) and returns it with
`exact => false` (`:456`). The floor is recorded only when
`actualInclusive <= targetInclusive` (`:449`), so an upward drift is structurally impossible.
`actualNet` is now summed from what was really materialised (`:437-441`) rather than assumed equal
to `candidateNet` — necessary once a line can hit a ceiling, and correct.
`CreditNoteController.php:209-221` adds a `\RuntimeException` → 422 catch as defense-in-depth.

**My own probe, re-run from scratch** (`<scratchpad>/probe_alloc2.php` — rewritten for the new
return shape; asserts no-throw, no-overshoot, non-negative discount, line-net sum == reported net):

| Shape (19 %) | Range | Checked | throw | over target | negative disc | worst deviation |
|---|---|---|---|---|---|---|
| synthetic 1 × 1.000 (no-lines fallback) | 0.001–20.000 | 20 000 | **0** | **0** | **0** | **0.001** |
| 1 real line 1 × 99.000 | 0.001–20.000 | 20 000 | **0** | **0** | **0** | **0.001** |
| 2 real lines (MTP-DOC-16 fixture) | 0.001–20.000 | 20 000 | **0** | **0** | **0** | **0.001** |
| 2 real lines, first zero-priced (A2) | 0.001–20.000 | 20 000 | **0** | **0** | **0** | **0.001** |
| 1 real line 1000 × 0.010 (tiny unit price) | 0.001–11.900 | 11 900 | **0** | **0** | **0** | **0.001** |
| 2 real lines @ 7 % | 0.001–20.000 | 20 000 | **0** | **0** | **0** | **0.001** |
| 2 real lines @ 13 % | 0.001–20.000 | 20 000 | **0** | **0** | **0** | **0.001** |

172 000 independent checks, zero violations, deviation never more than one millime.

**My three original 500 repros, live, on fresh `W3c-` invoices:**

```
INV-2026-0535 (1 × 99.000 @19%, total 118.810)
  amount=20.085  HTTP=201  total=20.684  requested=20.085 credited=20.084
  amount=0.093   HTTP=201  total=0.692   requested=0.093  credited=0.092
  amount=50.000  HTTP=201  total=50.600  (exact — no requested/credited keys emitted)
INV-2026-0536 (100.000 @19% + 50.000 @7%, total 173.500)
  amount=41.117  HTTP=201  total=41.716  requested=41.117 credited=41.116
```

All three: 201, exactly 1 millime down, both figures surfaced, `credited + 0.600 duty == total`
byte-exact. Exactly the disposition claimed.

**The implementer's `CreditNoteAllocationExhaustiveProbeTest` runs green** (447 560 checks across
118.810 + 268.750 + 60.000 millime sweeps; 14 tests / 86 assertions with the money-lane file).
Two honest limitations worth recording, neither of which changes the verdict because my own probe
covers both:
- its fixture `DocumentLine`s have no `product_id`, so `maxQty` resolves to the
  `'999999999999.0000'` synthetic sentinel (`:327`) — **the quantity-ceiling branch is never
  exercised** by it. My `single_real`/`multi`/`zero_first`/`tiny_price` modes set `product_id` and
  do exercise it, clean.
- its deviation tolerance is `0.010` (`CreditNoteAllocationExhaustiveProbeTest.php:114`), 10× looser
  than the 0.001 actually achieved. Tightening it to `0.001` would make the guarantee falsifiable
  at the strength it really holds.

## A2 — sub-tick knob on the first surviving line: **VERIFIED FIXED**

`$deltaSlotClaimed` (`:387`, `:404-411`) hands the knob to the first line that survives the
`targetNet <= 0` / `unitPrice <= 0` filters rather than literal index 0. My zero-priced-first probe
went from **15.97 % hard failures → 1.775 % inexact-but-floored, 0 failures** — the knob is live on
that shape now, and the residual inexact rate matches the single-line baseline exactly, which is
the expected result.

## B1/B2/B3 — draft == confirm == posted: **VERIFIED FIXED**

The structural fix is the right one: `applyConfirmEquivalentTotals()` (`:94-107`) calls
`TaxCalculationService::calculateDocumentTaxes()` — literally `confirm()`'s own call
(`CreditNoteController.php:285`) — and persists all three columns. Both hand-rolled parallel
accumulation loops are deleted; all three creation paths now route through it (`:881`, `:1060`,
`:1212`). There is no second implementation of the money math left to drift. Consuming `totalTax`
(rather than `documentTaxTotal` only) is safe here for the reason the docblock gives and I
re-verified: STEP 1's UNCONFIGURED fallback (`TaxCalculationService.php:161-189`) honours an
explicit line rate with no matching config, so nothing can be silently re-zeroed.
`confirm()` now rewrites `subtotal` (`CreditNoteController.php:294`).

**Live:**

```
B1  INV-2026-0539 (10 × 10.000, discount_percent 10, @19%; sub 90.000 / total 108.100)
    line-based credit of qty 2:
      create   total=22.020  sub=18.000  tax=4.020   (sub+tax == total)
      confirm  total=22.020  sub=18.000  tax=4.020
      post     total=22.020                          ← was 24.400 → 22.020
B1b INV-2026-0540 (10 × 10.000, flat discount_amount 50.000, @19%; sub 50.000 / total 60.500)
    line-based credit of qty 1:
      create/confirm/post  total=6.550  sub=5.000    ← discount prorated 50.000 × 1/10 = 5.000,
                                                       never copied wholesale, net never negative
B3  standalone 2 × 14.285 @7%:
      create/confirm/post  total=31.169 sub=28.570 tax=2.599   ← was 31.168 → 31.169
```

`discount_amount` proration is at `:1021-1030` (ratio at scale 10, applied at currency scale);
`discount_percent` correctly left un-prorated. Carry-over **R1 is closed for the credit-note
paths** (the conversion/invoice paths it also named are out of this lane's scope).

## C1 — no negative `discount_amount`: **VERIFIED FIXED**

`materializeNativePrecisionLine()` (`:469-555`) bumps the quantity *up* (analytic tick count `:511`
plus a bounded `$safety < 20` correction loop `:521-530`) until `gross3 >= targetNet`, and when a
ceiling is hit first it takes the RULING-A floor with `discountAmount = '0'` (`:537-544`) rather
than going negative. Live DB check over all 26 credit-note lines created by my probes in the last
30 minutes: **0 negative `discount_amount`, 0 non-positive quantities.** The documents API's own
`CreateDocumentRequest.php:130` `min:0` contract is now respected by the system's own output.

## D1 / RULING B — over-credit: **VERIFIED FIXED, both directions**

Two independent mechanisms, as claimed:
1. `remainingCreditHeadroom()` (`:136-161`) sums each prior CN's **duty-exclusive** amount
   (`total − that CN's own documentTaxTotal`, `:150-153`) so stamps never compound across notes.
   Status-blind (drafts still reserve headroom → MTP-DOC-18 semantics preserved).
2. `allocateCreditNote()` clamps at the invoice's current `balance_due`, floored at 0
   (`:1267-1277`), under the existing `lockForUpdate()` on a freshly-read invoice row (`:1256-1260`)
   — so the clamp is race-safe.

**Live, my original repro:** INV-2026-0537 total 120.000, `amount=120.000` →
credited 119.000, CN total 119.600, confirm 119.600, post 119.600, **`balance_due = 0.400`** —
never negative. (Was `−0.600`.)

**Both guard directions, live** (INV-2026-0538, total 120.000):

```
cn1 amount=40.000                      → 201, total 40.600
cn2 amount=80.000 (== exact remaining) → 201, total 80.600   ← the legitimate full credit-out is
                                                                ACCEPTED (it was the case that
                                                                would have been wrongly refused
                                                                under a naive tightening)
cn3 amount=0.001  (genuine over-credit)→ 422 "Total credit notes would exceed invoice total"
```

That is precisely the both-directions behaviour my original review said needed a ruling. RULING B
answers it coherently.

**Note (not a defect):** crediting a *fully paid* invoice is already refused upstream —
`Document.status` flips to `paid` on full payment, so `isPosted()` fails (`:802`, live: 422
"Credit notes can only be created for posted invoices"). The clamp therefore cannot destroy a
legitimate post-payment credit through this endpoint. Verified, pre-existing, out of scope.

---

## New findings

### N1 — P2: the clamp desynchronises the AR subledger from the AR control account

The clamp caps `credit_note_allocations.amount`, but `GeneralLedgerService::createFromCreditNote()`
(`GeneralLedgerService.php:252-260`) still credits Accounts Receivable by the **full, unclamped**
`$creditNote->total`. When the clamp fires, the two ledgers disagree about the same number.

**Live repro** (INV-2026-0541, total 120.000, unpaid):

```
cn1 amount=119.000 → total 119.600, posted → balance_due 0.400   (allocation 119.600, no clamp)
cn2 amount=1.000   → total 1.600,   posted → balance_due 0.000   (allocation CLAMPED to 0.400)
```

```sql
document_number | cn_total | allocated | unallocated_residue
CN-2026-0065    |  119.600 |   119.600 |               0.000
CN-2026-0066    |    1.600 |     0.400 |               1.200
```

```
GL entry for CN-2026-0066
  411  Clients                            credit 1.600   ← full CN total
  707  Ventes de marchandises             debit  0.841
  4457 TVA collectée                      debit  0.159
  4375 État - Droit de timbre à reverser  debit  0.600
```

Net effect on this invoice: AR **subledger** says 0.000 outstanding; AR **control account** has been
credited 121.200 against a 120.000 debit — i.e. −1.200. Before `ba7be2ce2` the two agreed (both
went negative); the clamp moves the imbalance out of `balance_due`, where it corrupted
`payment_status` and AR aging, and into the GL, where nothing reconciles it.

This is a strictly better failure mode than the original `−0.600` (no customer-facing figure is
wrong, both numbers are recorded and derivable, and it needs ≥2 credit notes on one invoice), which
is why it is not a blocker. But it is unresolved, and the clamp test
(`CreditNoteMoneyLaneTest.php:487-491`) asserts the residue exists without asserting anything about
the GL side. The underlying question my original review raised is still open and RULING B only
answered half of it: **does the credit note's own stamp duty reduce what the customer owes?** The GL
says yes (it credits AR by it, symmetrically with how the invoice's own stamp is debited to AR); the
headroom guard and the clamp say no. Pick one and make both sides agree — either the CN's duty
consumes headroom (then the clamp never fires) or AR must be allowed to carry it.
**Must be ticketed and resolved before the credit-note GL is signed off for certification.**

### N2 — P2: RULING A's "surfaced" is met at the API only; the operator sees nothing

`requested_amount` / `credited_amount` are stamped as in-memory-only attributes
(`CreditNoteService.php:892-895`) and emitted by `formatCreditNote()`
(`CreditNoteController.php:435-448`). The mechanism is sound — not persisted, not mass-assigned,
and no `save()` runs on the model afterwards (the live 201s prove it).

But `grep -rn "credited_amount|requested_amount" apps/web/src apps/web/e2e` returns **no
credit-note reference at all** (the only hits are an unrelated `RecordDepositModal`). A cashier who
types 20.085 into the credit-note modal gets a 20.084 credit note and **no notice whatsoever**. The
ruling's own wording is "never a silent upward drift" — it is not silent to an API client, but it is
silent to the human. Small FE follow-up: render the two figures when they differ. Not a backend
defect; the backend half of RULING A is met.

### N3 — P3: the deviation is not always "1 millime"

On an invoice that carries its own document duty, the reachable ceiling is the *line-inclusive*
value, not `invoice.total`. Live: INV-2026-0537 total 120.000 (100.000 net + 19.000 VAT + 1.000
STAMP_TAX_INVOICE); requesting the full 120.000 credits **119.000** — a **1.000 TND** floor, 1000×
the millime figure quoted everywhere in the commit message. It is correct (you cannot credit the
invoice's own stamp through a line allocation), downward, and surfaced — but the
`CreditNoteAllocationExhaustiveProbeTest` probes a single rate group in isolation and so never sees
it, and its `> 0.010` tolerance would have flagged it if it did. Either document the ceiling in the
ruling or add an invoice-path case that asserts the 1.000 floor deliberately.

### N4 — P3: the quantity ceiling is per-credit-note, not cumulative

`maxQty` is the source line's **full** quantity (`:327`), so N successive amount-based credit notes
can each fabricate up to the full sold quantity; cumulative fabricated units can exceed units sold.
Money stays bounded by the headroom guard, and credit-note posting still creates no stock movement
(re-verified: no `DocumentType::CreditNote` reference under `app/Modules/Inventory/`), so there is no
inventory impact today. It would become one the moment credit-note restock is wired.

### N5 — P3: N+1 in the headroom guard

`remainingCreditHeadroom()` (`:144-155`) eager-loads every prior credit note *with lines* and runs
`calculateDocumentTaxes()` once per note — each of which issues its own `TaxConfiguration` query.
Linear in the number of prior credit notes, on the hot create path.

### Carried forward unchanged
`largestRemainderAllocate()`'s negative-shortfall branch (`:242-250`) still silently skips a tick
removal when `bases[idx] < tick`. Still unreachable for non-negative inputs. Still worth an assert
or a deletion.

---

## C2 adjudication — the stop-on is CORRECT, and the residual IS a display ticket

**On the stop:** correct, and correctly reasoned. Constraining the fabricated quantity to the
product unit's `decimal_places` cannot work, for a reason that is structural rather than empirical:
on a whole-unit product the set of reachable line nets collapses to the lattice
`{k × unit_price}`, and an arbitrary requested money amount is simply not on that lattice. Checked
independently against the MTP-DOC-16 fixture (10 × 12.500 + 4 × 25.000 @ 19 %): the largest lattice
net reaching at most a 100.000 inclusive target is 75.000 → 89.250 inclusive, a **10.750 TND** floor.
(I could not reproduce the commit's exact 74.975 figure, which presumably reflects a different
flooring in the reverted implementation — but the order of magnitude is real and the conclusion is
unchanged.) A 10–25 TND deviation is categorically worse than the 1-millime contract RULING A
establishes, and it would break this same re-gate's own tests. **Stopping was right**, and it
matches the mitigation my original review recommended verbatim ("the mitigation is presentational
… rather than a change to the search"). Recording the analysis in the `allocateGroupExactly()`
docblock (`:277-294`) rather than a commit message alone is the right disposition.

**On the scoping:** yes, DISPLAY, not money — with one correction to the rating. The money columns
are exact and reconcile (`subtotal + tax_amount == total`, `line_total` exact, allocations exact,
GL balanced); the quantity is a derived allocation artifact, not a physical count; nothing consumes
it. So it is not a money defect. But it is not merely cosmetic either: on the credit-note PDF a
0.4244 quantity on a `decimal_places = 0` Piece unit renders as `0 pc × 99.000 = 42.017`, so an
auditor recomputing the line from the printed document cannot reproduce its own total. That is a
**fiscal-document-presentation** defect, and on a certification path I would rate it **P2, not P3**,
and have it gate the credit-note print/PDF surface (`resources/views/documents/templates/credit_note.blade.php`)
— not this merge. The right fix remains: show the credited amount, and suppress or annotate the
synthetic quantity on amount-based credit-note lines.

---

## Regression sweep — clean

| Check | Result |
|---|---|
| `CreditNoteAllocationExhaustiveProbeTest` + `CreditNoteMoneyLaneTest` | **OK, 14 tests / 86 assertions** (7 new tests, one per finding) |
| `CreditNoteServiceTest`, `CreditNoteIntegrationTest`, `CreditNoteAllocationTest`, `CreditNoteTenantIsolationTest`, `Types/CreditNoteDocumentTest`, `TaxSnapshotCreditNoteTest`, `CreditNoteGLIntegrationTest`, `InvoiceAndCreditNoteGLIntegrationTest` | **OK, 68 tests / 294 assertions**, 3 pre-existing skips, **0 regressions** |
| `tests/Feature/Document/IngressPrecisionTest` | **13 errors — baseline unchanged**, same pre-existing `ArgumentCountError` |
| `pint --test` (4 changed files) | pass |
| `phpstan analyse` (2 changed app files) | `[OK] No errors` |
| MTP-DOC-16 fixture values | 100.000 / 150.000 / 200.000 / 268.750 all still **exact** (`exact=true`) → `'100.600'` assertion holds |
| MTP-DOC-19, MTP-IDEM-03 | `apps/web` untouched by this commit; both still assert relationally — unaffected |
| Original "Also-found" list | negative discount → fixed; `min:0` contract → satisfied; stale subtotal → fixed; C2 → adjudicated above; A3 dead branch → carried forward |

## Required follow-ups (none merge-blocking)

1. **N1** — ruling on whether the CN's own duty reduces AR, then reconcile
   `GeneralLedgerService::createFromCreditNote()` with `allocateCreditNote()`'s clamp. **Ticket
   before certification sign-off on credit-note GL.**
2. **N2** — surface `requested_amount` / `credited_amount` in the web credit-note create flow.
3. **C2** — credit-note template: show credited amount, suppress/annotate the synthetic quantity (P2).
4. **N3** — document or test the invoice-level ceiling (a 1.000 TND floor is reachable and correct).
5. Tighten `CreditNoteAllocationExhaustiveProbeTest` to `0.001` and give its fixture lines a
   `product_id` so the `maxQty` branch is covered by the repo's own probe, not only mine.
6. **N4 / N5 / A3** — backlog.

Nothing merged, nothing pushed. Local `dev` untouched apart from this record (uncommitted).
