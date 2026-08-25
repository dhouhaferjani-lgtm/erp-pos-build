# C-F0 gate r2 — FRONTEND-CONVENTIONS lens (Blade / i18n / design-system / print)

| | |
|---|---|
| Lane | C-F0 — confirmed-unposted output is a VAT-free proforma (Session C, document-lifecycle-dimensions) |
| Branch / worktree | `feat/sc-f0-proforma-output` · `.worktrees/sc-f0-proforma-output` |
| Reviewed SHA | `1fc42f982` (handback) · code `add2a0a68` — fix round r3 on `cbf3d6af5` · Base `0ae906b0e` |
| Date / round | 2026-08-25 · r2 (conventions) |
| Prior record | `docs/superpowers/reviews/2026-08-25-sc-f0-gate-r1-conventions.md` (ACCEPT-WITH-CONDITIONS, merge-blocking YES on C-1 + C-2) |
| Worktree hygiene | `git status --short` **empty BEFORE and AFTER** every probe. No concurrent gate. Two probes were run by editing and then `git restore`-ing; both restorations verified. |

**VERDICT: ACCEPT — merge-blocking: NO.**

All three r1 conditions are closed, and each was proven closed by a probe that turns
the guard red rather than by reading the fix. F-C4 and F-C5 are closed. The census
scope note landed. Five minors remain, none of them behavioural, none blocking.

---

## 1. Condition-by-condition re-verification, by execution

### Condition 1 (r1 F-C2, BLOCKING) — one partial, two call sites, CN coverage · **CLOSED**

**No duplicated markup remains.** `components/proforma_totals_rows.blade.php` is new
(69 lines) and is the only place the four rows exist. Both call sites are one line:
- `components/totals.blade.php:59` — `@include('documents.components.proforma_totals_rows')`
- `templates/credit_note.blade.php:74` — `@include('documents.components.proforma_totals_rows', ['totalRowStyle' => 'background-color: #dc2626;'])`

The diff removes 22 lines from `totals.blade.php` and 22 from `credit_note.blade.php`
and adds them once. The only thing the two copies ever disagreed about — the credit
note's `#dc2626` band — is now a parameter (`proforma_totals_rows.blade.php:65`), which
is the honest way to keep one copy rather than pretending the divergence did not exist.
The credit note keeps its own totals *block* (its posted arm says `Credit Total`, not
`Total`) but no longer its own copy of these rows; the comment at `:65-73` says exactly
that.

**The partial self-gates.** `proforma_totals_rows.blade.php:47` opens with
`@if($isProforma ?? false)` and closes at `:69`. Both call sites are already inside
their own proforma arm, so it changes nothing today — but it makes the file gated in
its own right, which the census requires the moment the file exists (the docblock emits
`tax_amount` and therefore matches `TAX_EMISSION`). `ProformaTemplateCensusTest` green,
7/7.

**CN-typed fixtures drive the R-8 tests through real providers.** `fiscalTypeProvider()`
(`ProformaOutputTest.php:114-120`) and `localeAndFiscalTypeProvider()` (`:125-137`) feed
`confirmedOfType()` (`:1110-1115`), which dispatches to `dpConfirmedCreditNote()` /
`dpConfirmedInvoice()`. Five R-8 tests now run over both types:
`test_every_proforma_row_closes_on_its_own_face`,
`test_the_proforma_totals_box_reconciles_with_a_stamp_duty_and_a_discount`,
`test_a_proforma_with_a_stamp_duty_and_a_discount_stays_clean` (× 3 locales),
`test_the_duty_and_discount_rows_do_not_reveal_the_vat`,
`test_a_residual_that_runs_the_other_way_is_not_called_a_discount`. The suite went
**32 tests / 389 assertions → 44 / 511**.

**Revert-probe — the include deleted from `credit_note.blade.php:74`:**
```
php vendor/bin/phpunit tests/Feature/Document/ProformaOutputTest.php
  -> Tests: 44, Assertions: 505, Failures: 8
     1-3) test_a_confirmed_unposted_credit_note_renders_as_a_proforma_in_html  en / fr / ar
     4)   test_the_proforma_totals_box_reconciles_with_a_stamp_duty_and_a_discount  [credit note]
     5-7) test_a_proforma_with_a_stamp_duty_and_a_discount_stays_clean  en / fr / ar [credit note]
     8)   test_a_residual_that_runs_the_other_way_is_not_called_a_discount  [credit note]
```
Every failure is credit-note-typed and no invoice case moved — the coverage is
type-specific, not incidental. (`test_the_duty_and_discount_rows_do_not_reveal_the_vat`
and `test_every_proforma_row_closes_on_its_own_face` correctly stay green under this
probe: the first is a negative assertion over absent figures, the second reads the
items table, not the totals box.) Tree restored; `git status --short` empty; the
`@include` re-verified present.

### Condition 2 (r1 F-C3, BLOCKING) — balance sentence gated + dotted · **CLOSED**

`templates/credit_note.blade.php:108-112` — the whole `<div>` is now wrapped in
`@if(! $isProforma)`, and the sentence is `__('documents.credit_note.balance_note')`,
a dotted key present in all three files (`lang/en/documents.php:88-90`,
`lang/fr/documents.php:38-40`, `lang/ar/documents.php:60-62`).

- fr: `Cet avoir réduit votre solde du montant indiqué ci-dessus.` — correct
  (`réduire … de` is the right construction for "reduces by").
- ar: `يخفّض هذا الإشعار رصيدك بالمبلغ المبيّن أعلاه.` — masculine إشعار with يخفّض,
  agreement correct.

**Posted snapshot unchanged, two ways.** The English value is byte-identical to the
retired literal, pinned by `test_a_definitive_credit_note_still_states_the_balance_effect`
(`:629-640`), which asserts the literal string *and* its presence on the posted render;
and `git diff --stat cbf3d6af5..1fc42f982 -- apps/api/tests/Fixtures/` is **empty**, so
neither `posted-credit-note.html` nor `posted-credit-note.pdf.txt` moved. Both posted
snapshot tests are green in the 44/44 run.

The negative side is pinned by `test_a_proforma_credit_note_does_not_claim_it_reduces_your_balance`
(`:609-622`), which checks the retired literal, the new key's value, and the PDF text.

### Condition 3 (r1 F-C1) — real i18n pins · **CLOSED, both halves**

Two independent guards replaced the tautology:

1. `ProformaOutputTest::test_the_proforma_renders_the_literal_strings_of_its_locale`
   (`:659-690`) — `localeLiteralProvider()` carries the fr and ar strings as **literals**
   (`'Proforma — document non fiscal'`, `'مستند مبدئي — غير ضريبي'`, `'Total estimé'`,
   `'المجموع التقديري'`, `'Droit de timbre'`, `'معلوم الطابع'`, `'Remise'`, `'تخفيض'`)
   and asserts them against a rendered discounted proforma.
2. `tests/Unit/Lang/DocumentsProformaLangParityTest.php` (new, 138 lines) — key parity,
   `:placeholder` parity and a "not the English string verbatim" check over the
   `proforma` and `credit_note` subtrees in all three files.

**It genuinely runs on every CI event, as claimed.** `.github/workflows/ci.yml:420`
runs `php artisan test --testsuite=Unit`, and `phpunit.xml:8-10` maps `Unit` →
`tests/Unit`. Unlike the parked Feature lane, this needs no allowlist entry. Verified,
not accepted.

**Tamper-probe — the `proforma` block deleted from `lang/ar/documents.php`:**
```
tests/Unit/Lang/DocumentsProformaLangParityTest.php
  -> Tests: 6, Assertions: 89, Failures: 1
     test_every_locale_carries_the_same_keys [proforma]
     "documents.proforma keys differ in ar: a missing key silently falls back to English"

tests/Feature/Document/ProformaOutputTest.php --filter 'literal_strings|arabic'
  -> Tests: 8, Assertions: 93, Failures: 1
     test_the_proforma_renders_the_literal_strings_of_its_locale [arabic]
     "documents.proforma.title must render its own ar string, not a fallback"
```
Both halves red, which is exactly the defect r1 described (under the r2 assertions this
edit was invisible). Tree restored; `git status --short` empty; `:52` re-verified.

**Parity state re-measured on the restored tree:** en 26 / fr 26 / ar 13 flattened keys;
`proforma.*` and `credit_note.*` complete in all three (`missing: none` × 4); the 13
`ar` gaps are the same pre-existing set as r1, unchanged. The parity test's deliberately
narrow scope is documented at `:28-33` and is the right call — whole-file parity would
have to be baselined against inherited debt, which is how a guard becomes decoration.

### Conditions 4 (r1 F-C4) and 5 (r1 F-C5) — **CLOSED**

- **F-C4**: `lang/ar/documents.php:33-49` is now an English pointer block; the rationale
  moved to `lang/en/documents.php:55-75` where the rest of the repo's rationale lives.
  `grep -nE "\b(le|la|les|des|est|pour|dans|qui|que|balayage|jetons|décision|terme|droit|timbre)\b" lang/ar/documents.php`
  → **no hits**. No French prose remains in the Arabic file.
- **F-C5**: `lang/ar/documents.php:52` — `'مستند مبدئي — غير ضريبي'`. Noun-headed and
  masculine (مستند), so it agrees on a credit note (إشعار, m.) as well as an invoice;
  the r2 bare feminine `مبدئية` is gone. The reasoning is recorded at
  `lang/en/documents.php:66-72`, including why `stamp_duty` deliberately drops `جبائي`.
  The literal now has a test that fails if it silently becomes the English fallback.

### Condition 5 (r1, census note) — **LANDED**

`ProformaTemplateCensusTest.php:204-211` states plainly that
`consultsTheFlagInCode()` is per-FILE, not per-emission, names `line_items` and `totals`
as the files that could grow an ungated cell unnoticed, and says what actually covers
that (`ProformaOutputTest` rendering the two real templates). Accurate.

### Scope · no `apps/web` / `apps/pos` file

`git diff --name-only 0ae906b0e...1fc42f982 | grep -E '^apps/(web|pos)/'` → **empty**,
across the whole lane, r3 included.

---

## 2. Test execution — by path, sqlite `:memory:` (`phpunit.xml:44-45`)

| run | result |
|---|---|
| `tests/Unit/Lang/DocumentsProformaLangParityTest.php` + `tests/Feature/Document/ProformaTemplateCensusTest.php` | **OK (13 tests, 125 assertions)** |
| `tests/Feature/Document/ProformaOutputTest.php` | **OK (44 tests, 511 assertions)** |
| all three together, after both probes were reverted | **OK (57 tests, 636 assertions)** |
| `pint --test` over the six changed/new PHP files | `{"result":"pass"}` |

r1's four failures are gone — they were the concurrent gate's uncommitted probe, as r1
§0 said, and the clean tree confirms it. Worktree `git status --short` empty at the end.

No `apps/web` guardrail applies: the lane has no touched web file for
`pnpm --filter @autoerp/web lint` / `typecheck` / vitest to gate.

---

## 3. Findings (all MINOR — none blocking)

### [MINOR] F-C8 · stray duplicate docblock
`ProformaOutputTest.php:1073-1075` — a `/** @param list<array<string, mixed>> $lines */`
block sits immediately above the real docblock of `bulkNonDividingUnpostedInvoice()`
(`:1079`), a method that takes **no parameters**. PHP attaches only the nearest
docblock, so the stray is dead text, and `pint --test` passes over it. Delete it.

### [MINOR] F-C9 · a docblock promises a carve-out the code does not implement
`DocumentsProformaLangParityTest.php:87-91` — "…with the deliberate exception of a value
that has no words in it." `test_no_locale_silently_ships_the_english_string` (`:93-107`)
compares unconditionally; there is no exception. Either implement it or drop the clause,
because the next person to add a word-free value will read the comment and be surprised.

### [MINOR] F-C10 · the literal pins cover four of the six `proforma.*` keys
`localeLiteralProvider()` (`:659-678`) pins `title`, `estimated_total`, `stamp_duty`,
`discount` in fr and ar. `detail` (the longest customer-facing sentence on the page) and
`adjustment` have no literal assertion in any locale. Their *existence* is pinned by the
parity test, so a silent English fallback still turns something red — this is a gap in
directness, not in coverage. Add the two strings to the provider.

### [MINOR] F-C11 · "not the English string verbatim" is vacuous for a wholly-missing block
Proven by the probe: with the `ar` `proforma` block deleted, only 1 of the parity class's
6 cases went red. `subtree()` (`:112-124`) returns `[]`, so
`test_no_locale_silently_ships_the_english_string`'s `foreach` never executes. The
division of labour is correct — `test_every_locale_carries_the_same_keys` is what
carries the missing-block case — but the class docblock should say which test owns
which failure mode, so a future reader does not trust the wrong one.

### [MINOR] F-C12 · the first mid-line Blade directive in the tree sits in the census's own subject
`proforma_totals_rows.blade.php:65` —
`<tr class="total-row"@if($totalRowStyle ?? '') style="{{ $totalRowStyle }}"@endif>`.
This is precisely the shape `ProformaTemplateCensusTest::test_a_comment_does_not_satisfy_the_gate_check`
(`:268-271`) documents as **unrecognised** by `consultsTheFlagInCode()`. It is harmless
here — the file's gate is the end-of-line `@if($isProforma ?? false)` at `:47`, and the
census is green — but a mid-line conditional attribute in the one file whose whole job
is to be legible to that census is worth avoiding. A `$totalRowClass` variable, or a
`style` attribute that always renders (empty when unset), removes the shape.

### Carried MINORs from r1, unchanged and still not blocking
- **F-C6** (Arabic in an LTR-only print layout): `grep -rn "dir=\|rtl\|direction" apps/api/resources/views/documents/`
  still returns **zero hits**; `layouts/document.blade.php:189-218` still aligns
  physically. The r3 partial slightly *concentrates* the exposure — one file now emits
  the Arabic totals rows for both document types — but does not change its size.
  Residual R-4 owns it.
- **F-C7** (a proforma is visually a definitive document plus a banner): unchanged;
  `#dc2626` is now passed as `$totalRowStyle` rather than hard-coded twice, which is
  strictly better. `getDocumentTitle()` (`DocumentPdfService.php:250-278`) is still a
  hardcoded per-locale map with no `ar` entry. Owner decision, not a lane fix.
- `__('Note')` at `templates/credit_note.blade.php:110` is still a non-dotted key and
  still renders English in fr/ar — but it now appears only on the **definitive** arm,
  so the proforma page is clean of it. Pre-existing; out of scope.

---

## 4. Does the r1 §3.4 FE follow-up lane spec still stand?

**Yes, unchanged — and it gains one line.** Re-verified on this SHA:
- no `apps/web` / `apps/pos` file is in the lane diff;
- `DocumentTotals.tsx:107-163` still renders `Subtotal`, per-rate `TVA 19%` rows, stamp
  duty and `Total` for a confirmed-unposted document, mounted unconditionally by
  `InvoiceDetailPage.tsx:654-659` and `CreditNoteDetailPage.tsx:310-314`;
- `CreditNoteDetail.tsx:92-98` still reads the now-stale
  `sales:creditNotes.postingMarker.title` / `.detail`
  (`locales/en/sales.json:872-877`, `fr:872-877`, `ar:225-230`), whose copy the backend
  retired in r2;
- `CreditNoteDetail.tsx:53-55` is still `window.print()` over the VAT-bearing DOM;
- `CreditNoteDetail.tsx:74-78` still derives the gate from `status`, and
  `grep -rn "fiscal_hash|fiscal_status|is_proforma" apps/web/src` still finds no field —
  so the API prerequisite (expose `is_proforma` computed by `ProformaOutputPolicy`,
  regenerate types via `php artisan typescript:transform`) remains the first step.

**Addendum:** the FE has **no** counterpart to the new
`documents.credit_note.balance_note` (`grep -rn "reduces your balance" apps/web/src` →
nothing), so the FE lane has nothing to gate there — but its backend/FE copy-parity test
should cover `documents.credit_note.*` alongside `documents.proforma.*`, so the two
trees cannot drift the way `postingMarker` just did.

---

## 5. Conditions

**None blocking.** Optional, may ride any later round in this lane or be dropped:

1. Delete the stray docblock at `ProformaOutputTest.php:1073-1075` (F-C8).
2. Drop or implement the "word-free value" exception clause at
   `DocumentsProformaLangParityTest.php:87-91` (F-C9), and name which test owns the
   missing-block failure mode (F-C11).
3. Add `detail` and `adjustment` to `localeLiteralProvider()` (F-C10).
4. Replace the mid-line `@if` at `proforma_totals_rows.blade.php:65` with a class or an
   always-rendered attribute (F-C12).
5. Unchanged and still owed elsewhere: the FE follow-up lane per r1 §3.4 + §4 above
   (fiscal R-1/R-2), and the owner-level items F-C6 (Arabic RTL in print, residual R-4)
   and F-C7 (proforma visual distinctiveness).

**VERDICT: ACCEPT — merge-blocking: NO.**
