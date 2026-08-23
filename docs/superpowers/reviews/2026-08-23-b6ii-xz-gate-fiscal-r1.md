# B-6(ii) X/Z Refund-VAT — adversarial merge gate, round 1, FISCAL lens

- **Lane:** `fix/b6ii-xz-refund-vat-display`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/b6ii-xz-refund-vat`
- **Range:** `3bc279856..410272b06` (3 commits: `b2ee12f8d`, `799dbc2c7`, `410272b06`)
- **Authority:** `docs/superpowers/specs/2026-08-23-xz-refund-vat-inclusion-scope.md` (Option A)
- **Reviewer:** fiscal-pos-reviewer, 2026-08-23. Read-only against the worktree; the only write is this file (main checkout) plus a sanctioned, fully-restored revert probe (§4).
- **Every claim below is grounded in a file I opened or a command I ran. Where I could not verify, I say so.**

---

## 0. Verdict summary

The lane's central safety claim — **zero hash risk** — is **VERIFIED**, and verified hard: the two signed-content files are absent from the diff, the exact-key-set device test is genuinely exact, and I ran 175 must-stay-green fiscal assertions on PostgreSQL, all green. The A3 red-first claim is **VERIFIED by revert probe**. The A2 derivation and window semantics are sound and well-documented. Regen is a pure regen with zero drift. The lang backfill is purely additive.

But three defects survive, and one of them is a **provable dead branch in the lane's own honesty mechanism**: on the device, `isReconciled === false` is mathematically unreachable whenever the disclosure renders, so the "unreconciled" warning the lane advertises as its legacy/anomaly fallback can never be shown — and in exactly that case the device silently reverts to the pre-fix contradiction. That is a correctness gap in the thing the ruling asked for ("do it properly"), not a style nit.

**CHANGES-REQUIRED**, on F-1/F-2/F-3. Everything else is ACCEPT-quality.

---

## 1. Claim 1 — zero hash risk (the load-bearing claim). **VERIFIED.**

**1a. The two signed-content files are absent from the diff.**

```
git diff --name-only 3bc279856..410272b06 | grep -E 'zReportService|reportApi'
  apps/pos/src/lib/offline/__tests__/zReportService.test.ts   <- test only
  apps/web/src/features/pos/api/reportApi.ts                  <- WEB, not the device file
```
`apps/pos/src/lib/offline/zReportService.ts` — not in the diff. `apps/pos/src/api/reportApi.ts` — not in the diff (the matched path is `apps/web/…`, a different file). Confirmed. The full 36-file diff-stat contains no other signed-content file: `zSessionAuthoring.ts`, `FiscalEventEngine.ts`, `ZReportPayload/XReportPayload/SessionClosePayload`, `Nf525XmlBuilder.php`, `FiscalPayloadConstraintValidator.php`, `cartTotals.ts`, `GrandtotalService` — none present.

**1b. The exact-key-set device test asserts EXACT keys, not `toMatchObject`.**
`apps/pos/src/lib/offline/__tests__/zReportService.test.ts:970-1010` —
`expect(Object.keys(report.report_data).sort()).toEqual([...12 keys...])`,
`expect(Object.keys(closeInput.reportTotals).sort()).toEqual([...7 keys...])`,
and a per-row `expect(Object.keys(row).sort()).toEqual(['gross_amount','net_amount','tax_rate','vat_amount'])` over `closeInput.vatBreakdown`. Fixture is refund-bearing (`makeRefundOfflineReceiptRows()`). This is a real exact-set assertion; a superset fails. Its own docblock states why (`:957-968`).

**1c. I ran the must-stay-green fiscal set myself.**

Device (vitest, worktree `apps/pos`):
```
zReportHashService.test.ts + zReportHashService.legacyStability.test.ts
+ zSessionAuthoring.test.ts + zReportService.test.ts
+ vatDisclosure.test.ts + endOfDayPreview.test.ts
=> 6 files / 106 tests PASSED
```

Server (PHPUnit on a throwaway PostgreSQL `b6ii_gate` @ 127.0.0.1:5433, since the default phpunit.xml is SQLite `:memory:` and SQLite masks numeric-aggregate behaviour):
```
FiscalPayloadConstraintValidatorTest + ZReportProjectionTest
+ Nf525ExportSnapshotTest + Nf525CanonicalZGrandTotalPeriodTotalsTest
+ Nf525ExportCanonicalRoundTripTest + ZReportV3AggregationTest
=> OK (175 tests, 415 assertions)
```
`ZReportProjectionTest`'s canonical-bytes assertion and all three NF525 snapshot/round-trip suites are green. F-4 tripwire unchanged and green.

**Conclusion: the Option A boundary held. No signed byte, no PAYLOAD_KEYS, no canonical shape, no Z hash moved.**

---

## 2. Claim 2 — the derivation identity across the three payload eras. **MOSTLY VERIFIED, with F-1.**

**2a. Is `refund VAT ≡ tax_amount − Σ vat_breakdown[].vat_amount` era-invariant?** For device-authored Zs: **yes**, and I checked this against history rather than assuming it.

The C-2 fix changed only the **net/gross** decomposition; the `vat` accumulator was `+lineVat` on sales and `−lineVat` on refunds in *every* era. At the earliest refund-bearing revision `1ccde450f:apps/pos/src/lib/offline/zReportService.ts`:
```
:806  existing.vat = bcsub(existing.vat, lineVat);   // refund branch
:846  existing.vat = bcadd(existing.vat, lineVat);   // sale branch
:834  taxAmount = bcadd(taxAmount, receipt.tax_amount);  // sale-only headline
```
and at HEAD (`apps/pos/src/lib/offline/zReportService.ts:873, :989-991, :960`) the same three lines. C-6 changed `netSales`, not `taxAmount`. So the "pre-C-2 gross-as-net" era corrupted `net_amount`, **not** `vat_amount` — the derivation reads only `tax_amount` and `vat_amount`, both of which are era-stable. The lane's docblock claim (`apps/pos/src/lib/reports/vatDisclosure.ts:32-43`) is truthful.

**2b. The legacy SERVER-authored Z (the genuinely broken era).** A pre-A3 `z_reports.report_data` row carries a **sale-only** `vat_breakdown`. Server side this is handled correctly and honestly: `refundVatDisclosureFor()` derives `refund_vat` from projections (independent of the stored table), so `net_vat == sales_vat` while `refund_vat > 0` and `is_reconciled` goes **false**. Pinned by `apps/api/tests/Feature/POS/ZReportRefundVatDisclosureTest.php:198-215` (`test_unreconciled_window_is_flagged_rather_than_hidden`). Good — this is an honest fallback, not a wrong number, and the blade prints the warning at `apps/api/resources/views/pos/z-report.blade.php:316-322`.

**2c. Does `is_reconciled` actually gate display? — NO on the device, PARTIALLY on the blade, NOT AT ALL on the web.** See F-1, F-2, F-3.

**2d. One era hazard the lane names but that is in fact unreachable — and the lane is over-cautious in a way that matters (see F-3).** `apps/pos/src/lib/offline/endOfDayPreview.ts:355-366` documents a "positive-signed legacy refund row would make this file add where the other two subtract". I checked: legacy refunds **never write an `offline_receipts` `receipt_kind='refund'` row at all** — their only device write is `local_refund_records` (`apps/pos/src/lib/offline/endOfDayPreview.ts:502-512`, the wave-2 TREASURY-CRITICAL comment). So every `receipt_kind='refund'` row is v4 and negative-signed, and the EOD wedge derivation is era-safe in practice. The residual note is honest but the *real* consequence is different and worse — F-3.

---

## 3. Claim 3 — A2 window semantics. **VERIFIED, with one stated-and-accepted divergence.**

`refundVatDisclosureFor()` (`apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:790-889`) selects:
```php
->where('r.terminal_id', $zReport->terminal_id)
->whereBetween('r.posted_at', [$windowStart, $windowEnd])
->where('r.is_voided', false)
->where('r.is_training', false)
->where('r.receipt_type', ReceiptType::Return->value)
```
This is predicate-for-predicate the same population as `calculateShiftTotals()` (`:1195-1201`, `terminal_id` + `is_training=false` + `posted_at BETWEEN`, with `is_voided` skipped in-loop at `:1222`). Terminal scoping ✅, training exclusion ✅, void exclusion ✅. It is also the G-4 declaration predicate modulo the `tax_rate > 0` filter, which the docblock (`:830-841`) states explicitly as a deliberate wedge (the Z's own table carries its rate-0 group; the identity is claimed only for `r > 0`).

Window resolution (`disclosureWindowFor()`, `:895-925`): `report_data.period_start/period_end` first — I confirmed those are actually stamped for v3 at `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:145-146` — falling back to `Shift::find()->opened_at` / `closed_at ?? generated_at`. `find()` rather than the non-nullable `$zReport->shift` relation is correct and the reason is documented in place.

**Divergence, correctly disclosed rather than glossed:** on a v3 Z, the *signed* `vat_breakdown` was authored on the device from `offline_receipts` scoped by **shift id**, while the disclosure is computed from **server projections** scoped by `posted_at`. Populations can differ transiently (unsynced refund receipts). The design catches this via `is_reconciled` — which is exactly why F-2 matters.

**Disclosure-vs-Z-net pinning:** `ZReportRefundVatDisclosureTest::test_refund_vat_is_derived_per_rate_as_a_positive_magnitude` (`:97-121`) asserts `sales_vat − refund_vat == net_vat` against a hand-built signed table, and `test_detail_endpoint_exposes_the_disclosure` (`:277-310`) asserts the same triple end-to-end through the HTTP resource. So yes, disclosure-vs-Z-net is pinned.

---

## 4. Claim 4 — A3 netting + revert probe. **VERIFIED.**

**Dual-era fixture is honest.** `apps/api/tests/Unit/POS/ReportGenerationServiceTest.php:188-249` puts **both** sign conventions on **one** shift and asserts they collapse into a single 19% group:
- positive-era return `net 30.000 / vat 5.700`,
- legacy negative-era return `net -10.000 / vat -1.900`,
- asserts `vat_amount == '11.400'` (19.000 − 5.700 − 1.900), `net_amount == '60.000'`, `gross_amount == '71.400'`, `assertCount(1, $breakdown)`, and `tax_amount == '19.000'` (headline stays sale-only), `refunds_count === 2`.

The implementation uses `magnitude()`-then-`bcsub` (`ReportGenerationService.php:1272-1274`), not a bare `bcsub` of the raw row — matching `EloquentVatDataRepository`'s `-ABS()`. Correct.

**The `:984` docblock rewrite is truthful.** Old text: *"VAT breakdown stays SALE-ONLY, unchanged: refunds are their own block."* New text (now at `:1179-1185`) states NET, names A3, names the magnitude-then-subtract normalisation, and preserves the "headline `tax_amount` remains SALE-ONLY by design" carve-out. Matches the code I read at `:1226-1276`.

**Revert probe (performed, then fully restored).** I `git checkout 3bc279856 -- ReportGenerationService.php` and re-ran the A3+A4 tests on PostgreSQL:
```
1) ReportGenerationServiceTest::test_return_receipt_vat_details_are_netted_into_vat_breakdown   FAILED
2) ZReportVatDeclarationReconciliationTest::test_z_per_rate_net_vat_equals_the_declaration…      FAILED
3) ZReportVatDeclarationReconciliationTest::test_z_per_rate_net_base_equals_the_declared_base    FAILED
Tests: 4, Assertions: 7, Failures: 3.
```
Red-first claim **confirmed** for A3 and for both A4 identity arms. Worktree restored byte-identically (`diff -q` against a pre-probe copy: identical; `git status --porcelain` empty).

**One honest caveat on the 4th test.** `test_derived_refund_disclosure_equals_the_declarations_deduction` (`ZReportVatDeclarationReconciliationTest.php:206-226`) **passed at base** — because it computes the wedge from the sale-only headline against the declaration arm, which is A3-independent, and it never calls `refundVatDisclosureFor()`. It is a true statement but it is *not* a red-first A3/A2 test. Minor labelling issue only (F-6).

---

## 5. Claim 5 — DO-NOT-TOUCH audit (all nine items). **CLEAN.**

| # | Item | Verdict |
|---|---|---|
| 1 | `apps/pos/src/lib/payment/cartTotals.ts:36` | not in diff ✅ |
| 2 | F-4 tripwire `FiscalPayloadConstraintValidator.php:764-1000` | not in diff ✅; suite green on PG ✅ |
| 3 | C-2's three consumer blocks (`zReportService.ts`, `endOfDayPreview.ts`, `reportApi.ts` sale+refund branches) | `zReportService.ts` and `apps/pos/src/api/reportApi.ts` not in diff ✅; `endOfDayPreview.ts:356-372` — the C-2 decomposition lines are **unchanged**, only a comment block and one new `refundVatAmount` accumulator line were inserted ✅ |
| 4 | C-6 headline derivation (`netSales += subtotal − tax_amount`) | unchanged in `endOfDayPreview.ts:292`; other two files not in diff ✅ |
| 5 | Signed payload emission (`zReportService.ts:1063-1074`, `zSessionAuthoring.ts:363-500`) | neither file in diff ✅; exact-key-set test pins the builder input ✅ |
| 6 | `refunds_amount` positive-magnitude semantics | `ZReportV3AggregationTest` green on PG ✅; no `refunds_amount =` write in the diff outside the new EOD-preview display field, which is documented as positive-magnitude (`endOfDayPreview.ts:112-118`) ✅ |
| 7 | Key-set constants (`ZReportPayload::PAYLOAD_KEYS` et al. + device mirrors) | `grep` over the diff finds `PAYLOAD_KEYS` only in **comments** (2 hits) ✅ |
| 8 | `computeGrandTotals` / grand-total chain | zero hits in the diff ✅ |
| 9 | B-13 manager-gate logic | no gate logic touched ✅ |

**B-13 concealment — the masking claim is real and tested.** `EndOfDayPreviewModal.tsx:322-331` routes the new VAT card through `hideFinancialAmounts`; the new refunds tile at `:337-350` does the same; `VatDisclosureSummary.tsx:47-58` masks via a `masked` prop wired from the same boolean. The SECURITY test (`EndOfDayPreviewModal.test.tsx:918-932`) renders under `require_blind_cash_count: true` with counts uncommitted and asserts the **labels** render while `'7.18'`, `'-2.00'`, `'5.18'` and `'12.00'` are all `not.toBeInTheDocument()`. That is a genuine assertion of the concealment regime, not a smoke test. ✅

---

## 6. Claim 6 — lang-key backfill. **VERIFIED.**

`git diff … -- apps/api/lang/en/pos.php apps/api/lang/fr/pos.php | grep '^-'` → **no removal or modification lines at all**. Purely additive: 26 backfilled pre-existing `z_report_*` keys the blade was already calling (so the fiscal PDF was printing raw keys) plus 5 new B-6(ii) keys, en + fr symmetric (45 / 40 lines).

The pin is present: `ZReportRefundVatDisclosureTest.php:247` — `$this->assertStringNotContainsString('pos.z_report_', $html)`. ✅

i18n ratchet re-run by me: `bash scripts/i18n-baseline-authority.sh` →
`i18n completeness OK — 55 namespaces, en=9234, fr=9250, ar=4864 authored (1986 behind aliases); 2763 known gap(s) held at the baseline.` No new gap. ✅

AR: correctly absent from `apps/api/lang/` (only en/fr exist there) and from `apps/pos/src/locales/` (only en/fr exist there), and correctly **present** in `apps/web/src/locales/ar/pos.json` where the namespace is wired. Consistent with the wired-namespaces rule. ✅ (One gap — F-3's web arm — is that `zReports.detail.vatUnreconciled` exists in no web locale, because the web page never renders it.)

---

## 7. Claim 7 — sign-era divergence left in `endOfDayPreview.ts`. **BOTH HALVES VERIFIED.**

- The divergence is documented **in place** at `apps/pos/src/lib/offline/endOfDayPreview.ts:355-366`, naming the two counterpart files and stating why it is not fixed here (C-2 settled these per-rate figures 72 hours ago; no owed test asks for it).
- The **new** accumulator is era-safe: `refundVatAmount = bcadd(refundVatAmount, bcabs(lineVat, scale), scale)` (`:377`) — magnitude then add — and `refundsAmount = bcadd(refundsAmount, bcabs(receipt.total, scale), scale)` (`:297`). Both match `zReportService.ts:867-869`'s shape.
- I found no owed test in the lane's surface that wanted the divergent line changed, and per §2d the hazard is unreachable anyway.

---

## 8. Claim 8 — disclosed red flags. **VERIFIED HONEST.**

`ZReportPdfTest` on PostgreSQL at **HEAD**: `Tests: 12, Assertions: 0, Errors: 12`, all
`SQLSTATE[23514] … violates check constraint "pos_shifts_closed_logic"` raised at `ZReportPdfTest.php:448` inside the fixture builder — 0 assertions, so the blade is never rendered.
I then reverted `ReportGenerationService.php`, `z-report.blade.php`, `lang/en/pos.php`, `lang/fr/pos.php` to `3bc279856` and re-ran: **same 12 errors, same constraint, same line**. Pre-existing, unrelated to the lane, honestly disclosed. ✅ (It should still be ticketed — F-7.)

The two flaky web tests: I ran `ZReportDetailPage.test.tsx` + `ZReportListPage` → 3 files / 8 tests passed. Not touched adversely. ✅

---

## 9. Claim 9 — PG legs. **RE-RUN, GREEN.**

Throwaway PostgreSQL DB `b6ii_gate` on `127.0.0.1:5433` (created, used, dropped):
```
ZReportRefundVatDisclosureTest                 OK (9 tests, 42 assertions)
ReportGenerationServiceTest                    OK (6 tests, 25 assertions)
ZReportVatDeclarationReconciliationTest       (3 tests, part of the 18 below)
all three together                             OK (18 tests, 78 assertions)
```
Also green on the default SQLite config (18/18), so the `SUM(ABS(...))` aggregate behaves identically on both drivers here. The PG leg is the one that counts: `SUM(ABS(numeric))` is exact on PG, and `numericOrZero()` (`ReportGenerationService.php:952-961`) correctly accommodates the driver disagreement about `SUM()`'s PHP type without ever letting a float into bcmath.

Static/type gates I ran myself: PHPStan on all five changed API files → **`[OK] No errors`**. `tsc --noEmit` in `apps/pos` → clean. `tsc --noEmit` in `apps/web` → clean. ESLint on the six changed POS files → 1 warning, `precision/no-parsefloat-on-money` at `endOfDayPreview.ts:558` — I verified this is **pre-existing and untouched by the lane** (`git diff … | grep parseFloat` → no hits) and it is on a tax **rate**, not money.

---

## 10. Claim 10 — scope and regen purity. **VERIFIED.**

- **Regen purity:** I re-ran `php artisan typescript:transform` with `CACHE_STORE=array` in the worktree → `Transformed 519 PHP types to TypeScript`, then `git status --porcelain` → **empty**. Zero drift; commit `410272b06` is a pure regen. ✅
- **Tickets committed:** `docs/superpowers/tickets/2026-08-23-printed-z-ticket-has-no-vat.md` (45 lines, Q-2 deferral, correct `printing.ts:250-295` citation) and `2026-08-23-z-report-blade-raw-i18n-keys.md` (44 lines). ✅
- **Nothing beyond declared surfaces.** All 36 files map to: server service + 2 DTOs + controller + resource + blade + lang(en/fr) + 3 test files; POS lib(vatDisclosure, endOfDayPreview) + 4 components + 1 new component + locales(en/fr) + 4 test files; web api(2) + 2 pages + locales(en/fr/ar) + 1 test file; 2 tickets; 1 generated types file. No stray file. ✅
- `printing.ts` correctly **not** touched (Q-2 deferral honoured); `apps/pos/src/api/reportApi.ts` correctly not touched — the local-X derivation the spec §4.1 asked for was implemented in `XReportModal.tsx` instead, which is strictly less invasive and achieves the same display outcome. Scope-honest.

---

## FINDINGS

### F-1 — [Important] The device's `isReconciled === false` branch is **provably unreachable**; the "honest fallback" the lane advertises can never render, and in that exact case the device silently reverts to the pre-fix contradiction.

**File:** `apps/pos/src/lib/reports/vatDisclosure.ts:88-100` and `apps/pos/src/components/pos/VatDisclosureSummary.tsx:37-39, :57-61`

The derivation is:
```ts
const wedge = bcsub(salesVat, netVat, scale);
const refundVat = bccomp(wedge,'0') > 0 ? bcformat(wedge, scale) : bcformat('0', scale);
hasRefundVat: bccomp(refundVat, '0') !== 0,
isReconciled: bccomp(bcsub(salesVat, refundVat, scale), netVat) === 0,
```
and the consumer is:
```tsx
if (!disclosure.hasRefundVat) { return null; }          // VatDisclosureSummary.tsx:37
…
{!disclosure.isReconciled && (<p …>{t(`${keyPrefix}.vatUnreconciled`)}</p>)}   // :57-60
```

Algebra, exhaustively:
- `wedge > 0` ⇒ `refundVat = wedge` ⇒ `salesVat − refundVat == netVat` ⇒ `isReconciled = true`, `hasRefundVat = true`.
- `wedge == 0` ⇒ `refundVat = 0` ⇒ `isReconciled = true`, `hasRefundVat = **false**` ⇒ component returns `null`.
- `wedge < 0` ⇒ `refundVat = 0` ⇒ `isReconciled = **false**`, `hasRefundVat = **false**` ⇒ component returns `null`.

So `isReconciled === false` ⟺ `hasRefundVat === false` ⟺ **the component has already returned `null`**. The `!isReconciled` branch, and the `reports.vatUnreconciled` / `reports.endOfDay.vatUnreconciled` strings added in `apps/pos/src/locales/en/pos.json` and `fr/pos.json`, are **dead code on every device surface** (X modal, Z modal, EOD modal).

The lane's own test pins the dead state without noticing it is dead: `apps/pos/src/lib/reports/__tests__/vatDisclosure.test.ts:91-107` asserts `refundVat '0.000'`, `hasRefundVat false`, `isReconciled false` — and its comment says *"Surface it as unreconciled … rather than printing 'VAT on refunds: -3.000'"*. It is not surfaced. It is suppressed.

**Why it matters (fiscal):** the `wedge < 0` case is the net table exceeding the sale-only headline — a corpus anomaly. In that state the modals fall through to `format(report.tax_amount)` (`XReportModal.tsx:64`, `ZReportModal.tsx:176`, `EndOfDayPreviewModal.tsx:326-330`) and render the **sale-only headline beside an inflated net table with no label and no warning** — i.e. the original B-6(ii) contradiction, undisclosed, on precisely the data where it is most likely to be real. The ruling asked for the contradiction to be closed; here it is re-opened silently.

**Fix:** make the warning independent of `hasRefundVat`. Either (a) render `VatDisclosureSummary` when `hasRefundVat || !isReconciled` and, in the `!hasRefundVat && !isReconciled` case, render only the warning plus the two raw figures; or (b) add an explicit `hasAnomaly: bccomp(wedge,'0') < 0` field and hoist the warning out of the early return. Add a component test that renders `{tax_amount:'10.000', vat_breakdown:[{vat_amount:'13.000'}]}` and asserts the `vatUnreconciled` string IS in the document — that test must be red before the fix.

### F-2 — [Important] The blade's unreconciled warning is unreachable in the most likely real-world unreconciled scenario: refund receipts not yet projected.

**File:** `apps/api/resources/views/pos/z-report.blade.php:303, :316-322`

The warning is nested inside `@if($vatDisclosure !== null && $vatDisclosure->has_refund_vat)`. `has_refund_vat` is driven by the **projection query** (`ReportGenerationService.php:884`), while `is_reconciled` compares that against the **signed stored table** (`:885`).

Consider a v3 device Z whose refund receipts have not yet synced/projected (routine offline-first behaviour): projections return zero refund rows ⇒ `has_refund_vat = false`; the signed table is already net ⇒ `net_vat < sales_vat` ⇒ `is_reconciled = **false**`. The blade takes the `@else` branch and prints the single sale-only `tax_amount` line (`:325-328`) directly beneath a VAT table now **labelled** `— net of refunds` (`:376`). Two disagreeing figures, one of them newly mislabelled by this lane, and the `@unless(is_reconciled)` warning is skipped.

This is the same structural mistake as F-1, on the server surface, and here the trigger is an ordinary sync lag rather than a corpus anomaly.

**Fix:** hoist the unreconciled row out of the `has_refund_vat` branch — e.g. render it whenever `$vatDisclosure !== null && ! $vatDisclosure->is_reconciled`, regardless of which VAT-line shape was chosen. Add a test to `ZReportRefundVatDisclosureTest` that builds a Z with a net signed table and **no** projected return receipts and asserts `__('pos.z_report_vat_unreconciled')` IS in the rendered HTML.

### F-3 — [Important] The new EOD refunds block counts **v4 refunds only**, while `expected_cash` on the same screen is reduced by **legacy** refunds — a new "Refunds: 0" beside a drawer figure the refunds moved.

**Files:** `apps/pos/src/lib/offline/endOfDayPreview.ts:295-299` (accumulator) vs `:534-537, :545-550` (`cashRefundImpact` → `expectedCash`); rendered at `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:337-350`.

`refundsCount`/`refundsAmount` accumulate only inside the `offline_receipts` loop, i.e. only `receipt_kind='refund'` rows. The file's own wave-2 TREASURY-CRITICAL comment (`:502-512`) states that **legacy refunds never write an `offline_receipts` row at all** — their sole device write is `local_refund_records` — and that this path is **still live** for every terminal that has not completed its v4 capability rollout (§9.3 coexistence ruling). Those same legacy refunds *are* summed into `cashRefundImpact` (`:536`) and subtracted from `expectedCash` (`:545-550`).

Result on a pre-v4 terminal that took cash refunds: the modal renders no refunds tile at all (`preview.refunds_count > 0` is false) and no VAT disclosure, while `expected_cash` is lower by the full refund amount and the cashier has nothing on screen explaining it. That is a fresh instance of the exact defect class the ruling exists to close, introduced by this lane's new block.

Pre-existing for `vat_breakdown` (which was always blind to legacy refunds); **new** for the count/amount tile, because the tile is new.

**Fix (pick one, but pick one):** (a) fold `getRefundRecordsForShift()` into `refundsCount`/`refundsAmount` — they carry no VAT split, so `refund_vat_amount` correctly stays at the v4-only figure and the disclosure keeps its meaning; or (b) render the tile whenever `refunds_count > 0 || cashRefundImpact != 0`, with a distinct label for legacy refunds; or (c) if deferred, add an explicit in-file comment + a ticket, and add a test pinning the current (blind) behaviour so it is a recorded decision rather than an accident. Option (a) is the honest one.

### F-4 — [Minor] `EndOfDayPreview.refund_vat_amount` is computed, typed, documented and **never consumed by production code**.

**File:** `apps/pos/src/lib/offline/endOfDayPreview.ts:119-125, :377, :582`

`grep -rn refund_vat_amount apps/pos/src apps/web/src packages/shared` returns only the interface, the accumulator, the emission, and **four test fixtures/assertions**. `EndOfDayPreviewModal.tsx:142` instead derives the same figure via `deriveVatDisclosure(preview, decimals)` — the **wedge** path. So the lane built an era-safe accumulator (magnitude-then-add, per F-7's own reasoning) and then had the UI ignore it in favour of the era-dependent derivation.

Two sources of truth for one number, one of them dead. Rule 1 (no dead/placeholder code) and rule 3 territory.

**Fix:** either have `EndOfDayPreviewModal` prefer `preview.refund_vat_amount` (and use the wedge only for `netVat`/`isReconciled`), which would also partially mitigate F-1, or delete the field and its four test references. Do not ship both.

### F-5 — [Minor] The web Z detail page renders the disclosure with **no** unreconciled surface at all.

**File:** `apps/web/src/features/pos/pages/ZReportDetailPage/ZReportDetailPage.tsx:229, :250-262, :337-349`

`grep -rn is_reconciled apps/web/src` → the only hit is a test fixture (`ZReportDetailPage.test.tsx:120`, hardcoded `is_reconciled: true`). The page gates purely on `has_refund_vat`. When the server returns `is_reconciled: false` — the F-2 scenario, which the server *does* compute and *does* ship over the wire (`ZReportResource.php:57`) — the page prints `VAT on sales / VAT on refunds / Net VAT` as three numbers that do not add up, with no warning, and appends per-rate negative refund rows to a table that was not netted.

The blade has the warning (F-2 notwithstanding); the POS component has the string (F-1 notwithstanding); the web has neither the branch nor a locale key in any of en/fr/ar.

**Fix:** add a `zReports.detail.vatUnreconciled` string (en/fr/ar) and render it when `disclosure && !disclosure.is_reconciled`; extend `ZReportDetailPage.test.tsx` with an `is_reconciled: false` case.

### F-6 — [Minor] `test_derived_refund_disclosure_equals_the_declarations_deduction` does not exercise the derived disclosure.

**File:** `apps/api/tests/Feature/POS/ZReportVatDeclarationReconciliationTest.php:206-226`

Despite its name and its docblock (*"The derived disclosure is the BRIDGE between the two"*), the test never calls `refundVatDisclosureFor()`. It asserts `bcsub($totals['tax_amount'], $declared['19.00'], 3) === '38.000'` — a property of `calculateShiftTotals` + the declaration arm only. Confirmed by my revert probe: this is the one test of the four that **passed at base**.

**Fix:** call `$this->reports->refundVatDisclosureFor($z)` and assert `$disclosure->refund_vat === '38.000'`, or rename to `test_the_sale_only_headline_minus_the_declaration_is_the_refund_vat`.

### F-7 — [Minor] `ZReportPdfTest` is 12/12 red on PostgreSQL at base and at HEAD; disclosed but not ticketed.

**File:** `apps/api/tests/Feature/POS/ZReportPdfTest.php:338, :448` — `pos_shifts_closed_logic` CHECK violation in the fixture builder, 0 assertions reached. Pre-existing (I verified at `3bc279856`), and it means the blade's PDF path — which this lane materially changed — currently has **no green PG coverage** outside the lane's own new `ZReportRefundVatDisclosureTest`. The new test does render the blade (`:243`), so coverage is not zero, but the pre-existing suite is silently dark.

**Fix:** raise a ticket alongside the other two the lane filed. Not a merge blocker.

### F-8 — [Minor, pre-existing, adjacent] The web `VatBreakdownEntry` contract does not match what the server emits.

**File:** `apps/web/src/features/pos/api/reportApi.ts:4-9` declares `{rate, net, vat, gross}`, and `ZReportDetailPage.tsx:329-334` renders `entry.rate/net/vat/gross`. The server emits `{tax_rate, net_amount, vat_amount, gross_amount}` — verified at `ZReportProjection.php:154` (v3 pass-through of the device payload) and `ReportGenerationService.php:1284-1290` (legacy). The web test fixture (`ZReportDetailPage.test.tsx:104`, `vat_breakdown: [{rate: 20, net: …}]`) is a **fake payload** that matches the type rather than the server — the "never fake API payloads" rule.

Pre-existing, not introduced here. But this lane **appends** correctly-keyed refund rows to that same table (`ZReportDetailPage.tsx:340-347`, `row.net_amount` etc.), so the shipped table will show populated refund rows next to blank sale rows. Worth a ticket; the lane should not be asked to fix it in-scope.

*(For completeness: the PHP disclosure defensively reads `$row['vat_amount'] ?? $row['vat']` (`ReportGenerationService.php:815`) while the TS helper reads only `vat_amount` (`vatDisclosure.ts:71, :84`). I searched for a writer emitting the `vat` key and found none — the `{rate,net,vat,gross}` shape survives only in stale phpdoc on `ZReport::getVatBreakdown():256` and `XReport.php:153` and in the web type above. So the TS asymmetry is not a live device bug.)*

---

## What is genuinely good here (stated so the fix round does not regress it)

- The Option A boundary is respected to the letter, and proven, not asserted: exact-key-set test + 175 green PG fiscal assertions + two untouched signed-content files.
- `refundVatDisclosureFor()` reuses the declaration's exact source, predicate and `ABS()` normalisation, so §3.1 reconciles by construction — and the dual-sign-era test proves the normalisation is load-bearing, not decorative.
- A3's docblock rewrite is one of the more honest ones I have read in this repo: it names the wedge it does not close and cites the two opposite-signed writers by class name.
- The B-13 concealment cross-check was actually performed and actually tested, not just asserted in a comment.
- No float touches money anywhere in the diff; PHPStan clean; the one ESLint warning is pre-existing and on a tax rate.
- Scope discipline: `printing.ts` deferred with a ticket, `Nf525XmlBuilder` untouched and routed to B-6(i), `apps/pos/src/api/reportApi.ts` solved at the component instead of the source.

---

## What to fix before merge

Make the unreconciled warning reachable on all three surfaces (F-1 device, F-2 blade, F-5 web) with a red-first test on each, and resolve the EOD legacy-refund blind spot (F-3) either by including `local_refund_records` in the new refunds tile or by ticketing it with a pinned test; F-4/F-6/F-7/F-8 can ride the same round or become tickets.

VERDICT: CHANGES-REQUIRED
