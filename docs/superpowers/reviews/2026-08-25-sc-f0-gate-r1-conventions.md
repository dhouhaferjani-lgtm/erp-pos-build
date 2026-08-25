# C-F0 gate r1 — FRONTEND-CONVENTIONS lens (Blade / i18n / design-system / print)

| | |
|---|---|
| Lane | C-F0 — confirmed-unposted output is a VAT-free proforma (Session C, document-lifecycle-dimensions) |
| Branch / worktree | `feat/sc-f0-proforma-output` · `.worktrees/sc-f0-proforma-output` |
| Reviewed SHA | `cbf3d6af5` (r2 fix round) · Base `0ae906b0e` |
| Date / round | 2026-08-25 · r1 (conventions) |
| Lens | frontend-conventions-reviewer — Blade templates, i18n en/fr/ar, print/design-system conventions, the `apps/web` contradiction |
| Normative inputs | BRIEF-C-F0 · SPEC §2.4 (r11.2) · `.claude/context/i18n.md` · CLAUDE.md rules 11/18/19 · owner rulings 2026-08-10 |
| Sibling records | `2026-08-25-sc-f0-gate-r1-fiscal.md`, `-r2-fiscal.md` (fiscal lens; r3 running concurrently). Fiscal reasoning is NOT duplicated here. |

**VERDICT: ACCEPT-WITH-CONDITIONS — merge-blocking: YES (C-2 and C-3; both ride the fix round the fiscal gate already mandates).**

The conventions work is real and better than the tree average: every string the lane
adds is a DOTTED key under the existing `documents` namespace, all six keys exist in
all three locale files, placeholder parity is trivially satisfied (no placeholders),
the deleted `posting_marker.title` / `.detail` have zero remaining readers anywhere,
and the census test carries its own falsification test. Three things do not hold: the
three-locale claim is pinned only tautologically, the credit note's hand-copied totals
block has no test that renders it, and the credit note's closing sentence contradicts
the banner the lane just put above it.

---

## 0. ENVIRONMENT WARNING — the reviewed worktree is DIRTY

`git -C .worktrees/sc-f0-proforma-output status --short` →
`M apps/api/app/Modules/Document/Application/Services/ProformaGrossAmountResolver.php`

The working copy carries an **uncommitted tamper probe** left by the concurrently
running fiscal r3 gate: `unitPrice()` has been rewritten to the F-8 forbidden
derivation and the line ends `// TAMPER B`. It is NOT in the commit —
`git grep -n TAMPER cbf3d6af5 -- apps/api` returns nothing in this lane's files, and
`git show cbf3d6af5:…/ProformaGrossAmountResolver.php` has the committed
`lineAmount ÷ quantity` form (F-7's required fix). I did not touch the tree.

Consequence for leg 6 below: my `ProformaOutputTest` run executed against the tampered
`unitPrice()`. **Nobody may build, snapshot or merge from this worktree state.**

---

## 1. Verification legs

### Leg 1 — user-facing strings go through translation keys · PASS

Read the full diff of the seven touched blades and the three new PHP files.

- **New PHP carries no user-facing string at all.** `ProformaOutputPolicy.php`,
  `ProformaGrossAmountResolver.php`, `DTOs/ProformaTotals.php` contain no `__()`, no
  literal copy — every label is decided in blade. Correct layering.
- **Every string the lane adds is dotted**: `documents.proforma.title` / `.detail` /
  `.estimated_total` / `.stamp_duty` / `.discount` / `.adjustment`, used at
  `components/posting_marker.blade.php:88-89`, `components/totals.blade.php:62,68,73,78`
  and `templates/credit_note.blade.php:72,78,83,88`. No hardcoded EN/FR/AR literal is
  introduced anywhere in the diff.
- **Three-locale existence + placeholder parity — verified by execution**, flattening
  the three PHP arrays and diffing key sets:
  ```
  counts en=25 fr=25 ar=25(+13 pre-existing gaps → 12)   proforma.*: en 6 / fr 6 / ar 6
  in en missing fr: (none)
  in en missing ar: 13 keys, ALL pre-existing (discount.*, bonus_quantity.*,
                    guided_delivery.*, stock.insufficient, pre_delivery_invoicing.*,
                    to_bill_queue.*) — none added or worsened by this lane
  placeholder parity over proforma.* + posting_marker.*: no mismatch (no `:placeholders`)
  ```
  The lane added all six of its own keys to `ar`, which is more than the surrounding
  file does.
- **Deleted keys have no readers.**
  `grep -rn "posting_marker\.\(title\|detail\)"` over `apps/api`, `apps/web/src`,
  `apps/pos/src` returns exactly two hits, both COMMENTS
  (`tests/Feature/Document/PostingMarkerPrintTest.php:25`, `lang/en/documents.php:22`).
  `grep -rn "posting_marker" apps/web/src apps/pos/src` → empty. The web's own
  `sales:creditNotes.postingMarker.*` is a separate FE key tree — see leg 4 / C-1.
- **Pre-existing, untouched, recorded**: the surrounding non-dotted `__('Subtotal')`,
  `__('Tax')`, `__('Description')`, `__('Qty')`, `__('Unit Price')`, `__('Amount')`,
  `__('Tax ID')`, `__('Credit Total')` never translate — `ls apps/api/lang/*.json` →
  **no JSON lang files exist**, so these render English in every locale. The lane's own
  header comment states this rule and obeys it. Not a lane finding; it is the reason
  F-C6 below is a *new* Arabic exposure rather than an existing one.

### Leg 2 — Arabic · PARTIAL (pre-existing gap, but this lane is the first to load it)

- **No `dir="rtl"` anywhere.** `grep -rn "dir=\|rtl\|direction" apps/api/resources/views/documents/`
  → **zero hits** across the whole tree. `layouts/document.blade.php:2` sets
  `<html lang="{{ $locale ?? 'en' }}">` and nothing else; the totals table pins
  `text-align: left` / `right` physically (`:199-207`), not logically.
- **Is that worsened here? Yes, measurably — see F-C6.** Before this lane the proforma
  totals box in `ar` contained only Latin text: `__('Subtotal')`, `__('Tax')`,
  `__('Total')` are non-dotted keys with no JSON lang file, so they always render
  English. The lane replaces that arm with four **dotted** keys that genuinely resolve
  to Arabic, so `components/totals.blade.php:60-80` is the first Arabic text ever placed
  in that table, inside an LTR-only, physically-aligned layout. Same for the credit note
  copy at `templates/credit_note.blade.php:69-89`.
- **`معلوم الطابع` (`lang/ar/documents.php:48`) — the wording choice is defensible and
  correctly flagged as unguarded.** The comment states plainly that the token scan is
  Latin-script and cannot catch an Arabic tax word, so the string is a human decision.
  Dropping `الجبائي` from the Tunisian administrative form `معلوم الطابع الجبائي` is
  the deliberate move that keeps a fiscal adjective off the page; `معلوم الطابع`
  remains the duty's own name and is understood. It is also the **first** Arabic
  stamp-duty string in the repository (`grep -rn "الطابع" apps/api/lang apps/web/src/locales apps/pos/src`
  → only this file; `ar/sales.json` has no `stampDuty` at all), so there is no
  competing term to contradict.
- **Gender/number**: `المجموع التقديري`, `تخفيض`, `تعديل`, `مستند غير ضريبي` all agree
  correctly. `مبدئية` does not — see F-C5. No plural forms are involved (no counts).

### Leg 3 — template structure · MIXED

- **Arm separation is readable where it is shared.** `components/totals.blade.php:31`
  `@if($isProforma ?? false) … @else … @endif` is one gate over one box, and the
  fail-safe direction is set once per template
  (`templates/invoice.blade.php:13`, `templates/credit_note.blade.php:13`:
  `$isProforma = $isProforma ?? true;`) with the shared components defaulting `?? false`
  so a non-fiscal template rendered directly is unaffected. The asymmetry is documented
  accurately at `layouts/document.blade.php:344-361`.
- **The census's structural check DOES match the constructs actually used.** I walked
  `consultsTheFlagInCode()` (`ProformaTemplateCensusTest.php:204-224`) against each
  touched file: `header.blade.php:53` and `totals.blade.php:31` are end-of-line
  `@if(...)` → pattern 2; `line_items.blade.php:2-21` and both templates' `@php…@endphp`
  → pattern 1; `parties.blade.php:20-21` and `layouts/document.blade.php:362` are
  end-of-line `@php(...)` → pattern 2 (which lists `php`, so the `@php(` form the
  block pattern excludes is still caught). Nothing in the lane relies on a construct
  the regexes miss. The class's own falsification test (`:254-275`) pins fail-closed
  behaviour including the mid-line-directive case.
- **Duplicated markup that will drift: yes, and it is untested — F-C2.**
  `templates/credit_note.blade.php:69-89` is a hand-copy of
  `components/totals.blade.php:60-80`. The lane's own comment
  (`credit_note.blade.php:63-68`) says "it must stay in step with the component" — and
  nothing makes it. The census cannot see it (the file already consults the flag
  elsewhere, so `consultsTheFlagInCode()` returns true for the whole file), and no test
  renders it.
- **Census granularity, recorded not blocking**: `consultsTheFlagInCode()` is per-FILE.
  A file that consults `$isProforma` once is permanently satisfied, so the two most
  tax-dense files in the tree (`line_items`, `totals`) can grow a new ungated tax cell
  without the census noticing. Real coverage comes from `ProformaOutputTest` rendering
  the two real templates, which is adequate today; worth one sentence in the class
  docblock rather than a silent property.

### Leg 4 — the `apps/web` contradiction · CONFIRMED, and the lane is correctly clean of it

`git -C .worktrees/sc-f0-proforma-output diff --name-only 0ae906b0e...cbf3d6af5 | grep -E '^apps/(web|pos)/'`
→ **empty**. The lane touched zero web and zero POS files, exactly as the brief scopes
it. Full enumeration and the follow-up lane spec are in §3.

### Leg 5 — rule 19 on display formatting · PASS (nothing to check) + R-6 confirmed pre-existing

No TypeScript file is in the diff, so there is no `parseFloat` / `Number(...)` on money
to find. On the PHP side the lane's own arithmetic is bcmath-on-strings throughout and
`formatMoney()`'s float cast is **confirmed untouched**: the diff of
`DocumentPdfService.php` ends at line 218 and `formatMoney` lives at `:282-296`
(`$amount = is_string($amount) ? (float) $amount : $amount;`). Pre-existing residual
R-6, correctly reported as such; the lane neither introduces nor widens it.

Also confirmed pre-existing and untouched: `getDocumentTitle()`
(`DocumentPdfService.php:250-278`) is a **hardcoded per-locale PHP map with no `ar`
entry and no `__()`**, so the Arabic proforma's headline still reads `Invoice` in Latin
above an Arabic banner. Out of lane; recorded because it bounds the lane's Arabic claim.

### Leg 6 — tests, by path, on sqlite (`:memory:` per `phpunit.xml:44-45`)

| test | result | note |
|---|---|---|
| `tests/Feature/Document/ProformaTemplateCensusTest.php` | **OK (7 tests, 24 assertions)** | file-based; unaffected by the dirty worktree |
| `tests/Feature/Document/PostingMarkerPrintTest.php` + `tests/Feature/Modules/Document/DocumentPdfSellerTaxIdTest.php` | **OK (13 tests, 25 assertions)** | |
| `tests/Feature/Document/ProformaOutputTest.php` | **32 tests, 389 assertions, 4 failures — ALL attributable to §0** | |

The four failures are `test_proforma_line_figures_are_gross_and_sum_to_the_estimated_total`,
`test_no_figure_on_a_proforma_yields_the_vat_by_subtraction`,
`test_every_proforma_row_closes_on_its_own_face`,
`test_the_duty_and_discount_rows_do_not_reveal_the_vat` — every diff is a unit-price
cell showing the `tax_amount ÷ qty` figure (`18,240` for `118,240`, `9,120` for
`59,120`, `4,104` for `26,604`), i.e. the signature of the uncommitted probe, not of the
commit. **All 12 locale-provider cases (en/fr/ar × invoice/credit-note × HTML/PDF) and
both posted snapshots are green**, which is the part this lens depends on. The lane
must be re-run clean before merge (fiscal condition 3 already asks for repeated runs).

No `apps/web` guardrail is applicable — `pnpm --filter @autoerp/web lint` /
`typecheck` / vitest have no touched file to gate. The FE follow-up lane in §3 does.

---

## 2. Findings

### [MAJOR — merge-blocking] F-C1 · the three-locale assertions are TAUTOLOGICAL
`ProformaOutputTest.php:111-112, 122-123, 253, 454-455` and the French case at `:168-169`.

```php
$this->assertStringContainsString(__('documents.proforma.title'), $html);
```

Both sides resolve through the same `Translator` at the same locale. Delete
`proforma` from `lang/ar/documents.php` and `Illuminate\Translation\Translator::get()`
(`vendor/laravel/framework/.../Translator.php:171-183`) walks `localeArray($locale)` to
`fallback_locale` (`config/app.php:83` → `'en'`) and returns the ENGLISH string — to the
blade *and* to the assertion. Both sides move together; the test passes on a page that
renders English to an Arabic tenant. If the key vanished from `en` too, both sides get
the literal key string back and the test *still* passes.

There is no second net: **`apps/api` has no lang-parity guard at all.** The only
i18n audit in the repo is `apps/web/tools/audit-i18n-completeness.mjs`, whose baseline
(`apps/web/tools/i18n-completeness-baseline.json`) covers `apps/web/src/locales` only.
Parity is correct **today** (I verified 6/6/6 by execution) — it is simply unpinned.

**Fix:** assert the `ar` and `fr` strings as LITERALS the way `en` already is
(`ProformaOutputTest.php:151-163` does this correctly), or add a
`tests/Unit/Lang/DocumentsLangParityTest` that flattens `lang/{en,fr,ar}/documents.php`
and asserts equal key sets plus equal `:placeholder` sets for the `proforma.*` subtree.

### [MAJOR — merge-blocking] F-C2 · the credit note's hand-copied totals block is rendered by no test
`templates/credit_note.blade.php:69-89` duplicates `components/totals.blade.php:60-80`.

Every R-8 test builds an **invoice**: `:373`, `:416`, `:443`, `:465`
(`discountedUnpostedInvoice()`), `:503` (`surchargedUnpostedInvoice()`), `:537`
(`legacyTaxRowUnpostedInvoice()`). The only credit-note proforma fixture is
`unpostedCreditNote()` (`:915-921`) → `deterministic()` (`:941-968`), which sets
`subtotal 200 / tax 38 / total 238` and **no `stamp_duty_amount`, no
`discount_amount`** — so `ProformaGrossAmountResolver::totals()`
(`ProformaGrossAmountResolver.php:141-166`) returns `stampDuty = null` and residual `0`,
i.e. `discount = surcharge = null`. **The stamp-duty, discount and adjustment rows of
the credit-note copy have never been rendered by anything.** The census cannot help:
`consultsTheFlagInCode()` is per-file and `credit_note.blade.php` satisfies it at `:13`.

That is precisely the drift the lane's own comment at `:63-68` warns about, shipped
unguarded, on one of the exactly two document types this lane exists for.

**Fix:** extract the four rows into `components/proforma_totals_rows.blade.php` and
`@include` it from both call sites (one basis, one gate — the same principle the fiscal
gate applied to the row arithmetic); or, minimally, add a discounted/stamped
**credit-note** fixture and run `test_the_proforma_totals_box_reconciles_with_a_stamp_duty_and_a_discount`
and `test_a_residual_that_runs_the_other_way_is_not_called_a_discount` over both types.

### [MAJOR — merge-blocking] F-C3 · the credit note's closing sentence contradicts the banner
`templates/credit_note.blade.php:109-111`:

```blade
<strong>{{ __('Note') }}:</strong> {{ __('This credit note reduces your balance by the amount shown above.') }}
```

Ungated. On a proforma this prints under a banner reading *"Proforma — non-fiscal
document … it confers no right of deduction"*, on a page from which the lane
deliberately deleted the Paid and Balance Due rows because — its own words,
`components/totals.blade.php:42-43` — *"a proforma is not a statement of account."*
The page then asserts a balance effect the document does not have. It also calls itself
a *credit note*, the definitive noun, in the one place the lane took care not to.
Compounding it: the key is **non-dotted** with no JSON lang file, so a French or
Tunisian customer reads that sentence in English.

This is the owner rule *UI must not overstate system guarantees* on a customer-facing
page. **Fix:** wrap it in `@if(! $isProforma)`, or replace it with a dotted
`documents.proforma.*` sentence that states the estimate, in all three locales; extend
`ProformaOutputTest`'s credit-note cases to assert the definitive sentence is absent.

### [MINOR] F-C4 · the French rationale block lives inside the Arabic file
`lang/ar/documents.php:40-46` — a six-line comment in French explaining the
`معلوم الطابع` choice, in the file an Arabic translator opens. `lang/en` comments are
English, `lang/fr` comments are French; only `ar` is off. Move it to `lang/en` (where
the rule already lives) and leave a one-line Arabic pointer, or write it in Arabic.

### [MINOR] F-C5 · `مبدئية` is a dangling feminine adjective, and the credit note is masculine
`lang/ar/documents.php:38` — `'title' => 'مبدئية — مستند غير ضريبي'`. `مبدئية` is a
bare feminine adjective agreeing with an elided **فاتورة** (invoice, f.). The *same key*
titles the credit note (**إشعار**, m.), where it disagrees. `en` and `fr` avoid this by
using a type-neutral noun ("Proforma"). N-6's deleted `غير مُرحَّلة` had the same
defect, so this is inherited in kind — but the lane is rewriting the string, which is
the moment to fix it. **Fix:** a noun-headed, gender-neutral form (e.g.
`مستند مبدئي — غير ضريبي`, agreeing with the masculine مستند already in the same line),
or two keys resolved per `DocumentType`.

### [MINOR] F-C6 · this lane puts Arabic into an LTR-only, physically-aligned print table
`components/totals.blade.php:60-80` + `templates/credit_note.blade.php:69-89`, against
`layouts/document.blade.php:2` (`<html lang>` with no `dir`) and `:189-218`
(`text-align: left` / `right`, not `start` / `end`). As established in leg 2, the posted
arm's labels never translate, so this proforma arm is the **first** real Arabic content
in that box. The discount cell also prints a manual ASCII sign outside the localized
formatter — `<td>-{{ $formatMoney(...) }}</td>` (`totals.blade.php:69`,
`credit_note.blade.php:79`) — where `formatMoney` is an ICU
`NumberFormatter(locale, CURRENCY)`; under `ar` the sign should come from the
formatter's negative pattern, not be concatenated in front of it. The `-` is copied
verbatim from the pre-existing posted-arm discount row (`totals.blade.php:89`), so it is
consistent rather than novel — but it is now adjacent to Arabic label text for the first
time. Pre-existing R-4 remains the owning lane; this raises its priority, it does not
create it.

### [MINOR] F-C7 · visual hierarchy does not distinguish a proforma from a definitive document
`components/totals.blade.php:77` keeps `class="total-row"` (brand-colour band,
`layouts/document.blade.php:209-212`) and `templates/credit_note.blade.php:87` keeps the
inline `#dc2626` band copied from the definitive `Credit Total` row (`:99`). With
`getDocumentTitle()` still printing `Invoice` / `Facture` / `Avoir` (leg 5) and the
document number unchanged, the only visual difference between a proforma and a
definitive document is one banner. The brief asked for the banner and the lane delivered
it, so this is spec-conformant — recorded because the owner's *one main element* rule
argues the banner, not the total band, should dominate this page. A follow-up decision
(watermark / title suffix), not a fix.

### Observations (recorded, not findings)
- `lang/ar/documents.php` is missing 13 keys present in `en`/`fr` (`discount.*`,
  `bonus_quantity.*`, `guided_delivery.fefo_allocation_failed`, `stock.insufficient`,
  `pre_delivery_invoicing.*`, `to_bill_queue.no_active_location_in_scope`). Entirely
  pre-existing; the lane added all six of its own keys and reduced the ratio of debt.
- `line_items.blade.php:7` (`$showTaxColumn`) is honest belt-and-braces and does not
  defeat the census — it is itself the construct the census matches.
- No indirection, alias table, renamed literal or detector-keyword suppression comment
  appears anywhere in the diff (mechanism audit, protocol step 3): the metric that
  improved (no tax token on the page) improved by deleting the markup, not by hiding it.

---

## 3. The `apps/web` contradiction — enumeration and FE follow-up lane spec

### 3.1 What the browser shows for a **confirmed-unposted invoice**, versus the new PDF

| surface | browser today | PDF after this lane |
|---|---|---|
| `features/documents/components/DocumentTotals.tsx:118-141` | one row per rate from `tax_details`, labelled `TVA 19%` | no tax row, no rate |
| `DocumentTotals.tsx:107-115` | `Subtotal` (the NET basis) | deleted — printing net beside gross is a VAT breakdown by subtraction |
| `DocumentTotals.tsx:143-151` | `Stamp duty` (`tax.breakdown.stampDuty`) | `documents.proforma.stamp_duty` |
| `DocumentTotals.tsx:153-163` | `Total` | `Estimated total` |
| `invoices/InvoiceDetailPage.tsx:654-659` | mounts `DocumentTotals` unconditionally; only `showBalanceDue` is gated on `isPosted` (`:417`) | settlement rows removed entirely |
| `InvoiceDetailPage.tsx:635-643` | line cells print NET `unit_price` and `line_total` | gross, tax-inclusive |
| whole page | **no proforma banner exists on the invoice page at all** | banner is the first thing on the page |

### 3.2 Confirmed-unposted **credit note**

- `credit-notes/CreditNoteDetailPage.tsx:310-314` mounts the same `DocumentTotals` →
  same VAT rows, same `Total`.
- `components/CreditNoteDetail.tsx:80-101` shows an amber marker whose copy is now
  **stale**: `sales:creditNotes.postingMarker.title` / `.detail`
  (`locales/en/sales.json:872-877`, `locales/fr/sales.json:872-877`,
  `locales/ar/sales.json:225-230`) still say *"Not yet posted — no fiscal seal … is not
  a definitive fiscal credit note"*, the exact wording the backend deleted this round.
  The screen and the paper now describe the same document in two different vocabularies.
- `CreditNoteDetail.tsx:53-55` is `window.print()` — **an in-browser PRINT surface that
  prints the DOM**, i.e. a physical, VAT-bearing paper for a confirmed-unposted credit
  note, produced by a code path the lane's census never covered (the census is over
  `resources/views/documents/**`). The brief scopes React totals OUT and asks for a
  report; this is the report.
- The gate is a **status heuristic**: `CreditNoteDetail.tsx:74-78` derives
  `isCancelled` / `isBooked` from `status` and its own comment concedes *"if a credit
  note is ever POSTED without a seal, this view cannot tell."* `ProformaOutputPolicy`
  keys on `fiscal_hash`, and `grep -rn "fiscal_hash|fiscal_status|is_proforma"
  apps/web/src` returns **no field, only that comment** — the FE has no way to compute
  the same predicate. That is a gate standing on a name instead of the fact.

### 3.3 `useDocumentPdf.ts` — already correct, and that is what creates the split

`hooks/useDocumentPdf.ts:22-55` (download), `:60-84` (preview), `:93-146` (print) all
fetch the SERVER PDF, so all three already emit the proforma. The contradiction is
strictly *screen vs paper on the same page*: the user reads `TVA 19% — 38,000 DT` in
the totals panel, clicks Print two divs above, and gets a page with no VAT on it.

### 3.4 FE follow-up lane spec (condition C-4)

**Invariant.** No `apps/web` surface renders a VAT figure, a rate label, a net subtotal
or a settlement row for a document `ProformaOutputPolicy` calls a proforma; every such
surface carries the same banner as the PDF, in the same words.

**API prerequisite (do this first, or the lane fails open).** The document detail
payload must expose the predicate, not its symptoms — add `is_proforma: bool`
(computed by `ProformaOutputPolicy`, so there is exactly one predicate in the system)
to the invoice and credit-note show responses, regenerate types via
`php artisan typescript:transform` (CLAUDE.md rule 7 — do not hand-edit
`packages/shared/types/`). Do **not** let the FE re-derive it from `status`.

**Files.**
- `apps/web/src/features/documents/components/DocumentTotals.tsx` — add an
  `isProforma?: boolean` prop; when true render `estimated total` only, and drop the
  subtotal, the `tax_details` map (`:118-141`), the stamp-duty row as a *tax* label
  (`:143-151`, relabel to the duty wording) and the `noTaxes` notice (`:182-189`).
  `colorClasses` here is deprecated quarantine — new lines use `tokens` / `textColors`.
- `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx` — pass the flag at
  `:654-659`; render the banner above the line table; the line cells at `:635-643`
  must show the same gross figures the PDF shows or show none.
- `apps/web/src/features/documents/credit-notes/CreditNoteDetailPage.tsx:310-314` — same.
- `apps/web/src/features/documents/components/CreditNoteDetail.tsx` — replace the
  status heuristic at `:74-78` with `is_proforma`; the `window.print()` DOM at `:53-55`
  must not carry VAT; re-point `:92-98` at the new keys.
- `apps/web/src/locales/{en,fr,ar}/sales.json` — retire
  `creditNotes.postingMarker.title` / `.detail` and add a `documents.proforma.*` tree
  whose copy is **verbatim** the backend's `lang/{en,fr,ar}/documents.php` strings.
  **All three locales in the same commit**: `tools/audit-i18n-completeness.mjs` is
  shrink-only against an owner-pinned protected blob, so an `en`-only key is a new
  baseline entry and a red CI.

**Tests.**
- `DocumentTotals.test.tsx` — `isProforma` renders no rate label, no `subtotal`, no
  `total_tax_amount`; assert by rendered text, not class names (rule 17).
- `invoices/__tests__/InvoiceDetailPage.test.tsx` (new) and
  `credit-notes/__tests__/CreditNoteDetailPage.test.tsx` — a confirmed-unposted fixture
  shows the banner and no `TVA`/`VAT`/`Tax` string; a posted fixture is unchanged.
- `CreditNoteDetail.test.tsx` — the print DOM of a proforma contains no tax string;
  a posted-never-sealed fixture is treated as a proforma (the hole `:74-78` admits).
- A parity test asserting the FE `documents.proforma.*` strings equal the backend
  `lang/*/documents.php` values, so the two copies cannot drift apart again — this is
  the same defect class as F-C1 and F-C2, one level up.

**Guardrails to run:** `pnpm --filter @autoerp/web lint` (0 errors; includes
`audit:keys`, `audit:design-system`, `audit:i18n`), `pnpm --filter @autoerp/web typecheck`,
`pnpm vitest run src/features/documents`.

---

## 4. Conditions

1. **[MERGE-BLOCKING]** Close **F-C2**: extract the four proforma totals rows into one
   shared partial included by `components/totals.blade.php` and
   `templates/credit_note.blade.php`, or add a stamped/discounted **credit-note**
   fixture and run the R-8 assertions over it. Today no test renders that block.
2. **[MERGE-BLOCKING]** Close **F-C3**: gate or rewrite
   `templates/credit_note.blade.php:109-111` — a proforma may not tell the customer it
   reduces their balance, and the sentence must be a dotted key in all three locales.
3. Close **F-C1**: assert the `ar` and `fr` proforma strings as literals (as `en`
   already is at `ProformaOutputTest.php:151-163`), or add a
   `lang/{en,fr,ar}/documents.php` key+placeholder parity test. Parity is correct today;
   only the guard is missing. May ride the same round.
4. Dispatch the **FE follow-up lane** per §3.4 (this also closes fiscal residual R-1).
   Out of this lane's scope by the brief — the lane is confirmed clean of `apps/web`.
5. Comment/copy round, no behaviour: **F-C4** (French block inside `lang/ar`),
   **F-C5** (`مبدئية` gender/noun), and one docblock sentence recording that
   `consultsTheFlagInCode()` is per-file, not per-emission.
6. **Re-run `ProformaOutputTest` on a CLEAN checkout of `cbf3d6af5`** before any merge —
   the reviewed worktree currently carries the concurrent r3 gate's uncommitted
   `// TAMPER B` probe in `ProformaGrossAmountResolver::unitPrice()` (§0), which is
   responsible for all four failures in my run.
7. Recorded for the owner, not for this lane: **F-C6** (Arabic RTL in the print layout,
   residual R-4 — this lane raises its priority) and **F-C7** (a proforma is visually
   indistinguishable from a definitive document apart from the banner; a watermark or
   title treatment is an owner decision).

**VERDICT: ACCEPT-WITH-CONDITIONS — merge-blocking: YES (conditions 1 and 2).**
