# C-F0w handback — web proforma parity

| | |
|---|---|
| Lane | C-F0w — web detail pages render the proforma the PDF renders (Session C, document-lifecycle-dimensions; LEDGER C-26 (i)) |
| Branch | `feat/sc-f0w-web-proforma-parity` |
| Worktree | `.worktrees/sc-f0w-web-proforma` |
| Base | `eaf80a112` (local `dev`) |
| Commit | `17a2e0fad` — single commit (locale files for en/fr/ar included, per `.claude/context/i18n.md`) |
| Normative inputs | `BRIEF-C-F0w-web-proforma-parity.md` · `2026-08-25-sc-f0-gate-r1-conventions.md` §3.4 · SPEC §2.4 (r11.2) · LANE-PROTOCOL |
| Migration | **NONE** |
| Gates owed | frontend-conventions + fiscal-pos (predicate parity) |

---

## 1. The invariant, and how it is enforced

> For a confirmed-unposted Invoice or CreditNote the web detail pages show exactly what
> the PDF shows: no VAT/TVA/tax/TTC/HT label or amount, no per-rate rows, no
> seal/hash/QR/status badge; the proforma banner + `Estimated total` (+ Discount /
> Stamp duty rows when present) with gross line amounts; posted documents unchanged.

The enforcement is a **single server-side predicate** reaching the client as one
boolean, and a client that gates every tax rendering on that boolean alone. There is
no place where the front end re-derives the answer, and no place where an absent
enrichment payload can re-expose a tax figure.

---

## 2. API change (the prerequisite)

### 2.1 The predicate — one implementation, reachable from a static factory

`ProformaOutputPolicy::isProforma()` is an Application service that
`DocumentPdfService` constructor-injects. `DocumentData::fromModel()` is a **static**
factory with ~20 call sites and can inject nothing, so the predicate body moved onto
the aggregate and the policy delegates:

- `apps/api/app/Modules/Document/Domain/Document.php:628` — `isProformaOutput()`,
  clause-for-clause the C-F0 body (fiscal type · no `fiscal_hash` · not
  Voided/Cancelled · not historical/NON_FISCAL). Docblock points back at the policy
  for the reasoning and states why it is here.
- `apps/api/app/Modules/Document/Application/Services/ProformaOutputPolicy.php:48` —
  `return $document->isProformaOutput();` The full C-F0 rationale docblock is
  untouched above it.

Why the move rather than threading the service: any `fromModel()` call site left
unthreaded would emit a silent `false`, i.e. a page rendering VAT on an unsealed
invoice — the exact defect the lane exists to close. The predicate is a pure function
of the model and now sits beside `isSealed()` / `isFiscal()`, which `DocumentData`
already projects the same way. **Deptrac note:** `Document.php` deliberately does NOT
import `ProformaOutputPolicy` (Domain must not depend on Application); the docblock
names it in backticks. Pint's `fully_qualified_strict_types` fixer will add the import
back if anyone writes `{@see \App\…\ProformaOutputPolicy}` there — do not.

### 2.2 The projection

- `apps/api/app/Modules/Document/Application/DTOs/ProformaLineAmounts.php` (new) —
  `line_id`, tax-inclusive `unit_price`, tax-inclusive `line_total`.
- `apps/api/app/Modules/Document/Application/DTOs/ProformaPresentationData.php` (new) —
  `estimated_total`, `gross_lines`, `stamp_duty`, `discount`, `adjustment`, `lines[]`.
  Mirrors `documents/components/proforma_totals_rows.blade.php` row for row.
- `apps/api/app/Modules/Document/Application/Services/ProformaPresenter.php` (new) —
  constructor-injects `ProformaOutputPolicy` + `ProformaGrossAmountResolver` +
  `CurrencyScaleResolverInterface`; returns `null` for anything that is not a
  proforma. It calls the **same** `unitPrice()` / `lineAmount()` / `totals()` the PDF
  calls, so the two surfaces cannot print different numbers.

### 2.3 Wiring

- `DocumentData.php:279` `is_proforma: $document->isProformaOutput()` — always computed,
  on every document response, list or detail.
- `DocumentData.php:319` `proforma: …` — the enrichment; `fromModel()` gained a fourth
  optional `?ProformaPresentationData $proforma = null`.
- `Concerns/HandlesDocuments.php:67,82` — `documentResponse()` passes
  `$this->proformaPresentation($document)`; the hook's default is `null`.
- `InvoiceController.php:93,105` — injects `ProformaPresenter`, overrides the hook.
- `DocumentController.php:48` + `showAny()` — injects and passes it
  (`/documents/{id}` is the credit-note detail page's endpoint).
- `CreditNoteController.php:447` — `'is_proforma' => $creditNote->isProformaOutput()`
  on `formatCreditNote()`.

**On the default-`null` hook.** It is not a fail-open: `is_proforma` is computed on the
aggregate and is correct on every response regardless. A controller that does not
override the hook ships no gross-amount rows — it costs a reader detail and can never
expose a tax figure, because the client's suppression is gated on `is_proforma` alone.
This is asserted, not asserted-about: `DocumentTotals.proforma.test.tsx` →
*still hides every tax figure when the server ships NO proforma projection* renders the
proforma branch with `proformaTotals: null` and asserts no forbidden token, no
`Subtotal`, and no tax-breakdown request.

### 2.4 Generated TypeScript (rule 7)

`CACHE_STORE=array php artisan typescript:transform` → `Transformed 532 PHP types`.
`packages/shared/types/generated.d.ts`, +20/−4:

```
+is_proforma: boolean;                                                  (DocumentData)
+proforma: App.…DTOs.ProformaPresentationData | null;                   (DocumentData)
+export type ProformaLineAmounts = { line_id; unit_price; line_total }
+export type ProformaPresentationData = { estimated_total; gross_lines;
+   stamp_duty|null; discount|null; adjustment|null; lines[] }
```

**Three unrelated hunks came with it** — the generator rewrites the whole file and the
committed one was stale on `dev`. All three are additive union members from other
lanes, no removals:
`PosVatRefusalReason += 'sealed_base_era_ambiguous'`,
`CountingItemFlagReason += 'missing_boundary_marker'`,
`+ FiscalPeriodCloseRefusalCode` (new type). Flagged rather than reverted: reverting
would mean hand-editing a generated file, which rule 7 forbids.

---

## 3. Web change

- `components/ProformaBanner.tsx` (new) — the marker band, `documents.proforma.title`
  / `.detail`, design tokens only. One component for the three surfaces, because the
  credit note hand-copied a marker once already and the copy went stale.
- `components/DocumentTotals.tsx:28,30,52,53,65,107-165` — `isProforma` /
  `proformaTotals` props; the proforma branch returns **before** the loading and error
  arms and renders stamp duty · signed residual · estimated total, nothing else.
  `:65` disables the tax-breakdown query, so the VAT figures never enter the browser
  at all rather than merely not being drawn.
- `invoices/InvoiceDetailPage.tsx:426,436-452,512,635,681,684` — `isProforma` from
  `is_proforma`; banner above the items table; `displayLineAmount()` prints the
  server's tax-inclusive figures on a proforma and **nothing** for a line the
  projection does not cover (never a net figure under a gross total); the
  `fiscallySealed` badge and `showBalanceDue` are both gated off.
- `credit-notes/CreditNoteDetailPage.tsx:139,150-166,209,218,226,336,339,355,356` —
  same, plus the status chip and the `documents.sealed` chip come off on a proforma.
- `components/CreditNoteDetail.tsx:44,77,91,104,172,201` — banner from `is_proforma`
  (`&& !isCancelled`); the `status` heuristic and `isBooked` are gone; no status badge
  and no Status field on a proforma; the balance note renders only on a definitive
  credit note (the blade's F-C3 gating); `formatAmount` is now
  `formatCurrency(amount, { currency: creditNote.currency })` instead of
  `parseFloat(amount).toFixed(companyDecimals)` — rule 19, and on a TND credit note
  viewed from a 2-decimal company the old code silently dropped a millime.
- `types/document.ts` / `types/creditNote.ts` — hand-maintained mirrors of the backend
  DTOs (NOT the generated bundle): `ProformaLineAmounts`, `ProformaPresentation`,
  `is_proforma?`, `proforma?`. Optional only because dozens of existing fixtures
  construct these interfaces; every real response carries the field, and every read
  site compares `=== true`.
- `locales/{en,fr,ar}/sales.json` — `documents.proforma.{title,detail,estimatedTotal,
  stampDuty,discount,adjustment}` and `documents.creditNote.balanceNote`, extracted
  **programmatically** from `apps/api/lang/{en,fr,ar}/documents.php` so the copy is
  verbatim; `creditNotes.postingMarker.title` / `.detail` retired (the `cancelled*`
  arm stays — a sealed-then-cancelled document is not a proforma). All three locales,
  one commit. JSON round-trip verified byte-identical before editing, so the diff is
  +11/−2 lines per file and nothing reformatted.

---

## 4. Red → green, by path

### 4.1 Backend — `apps/api/tests/Feature/Document/ProformaResourceTest.php` (new)

Nine shapes; every case asserts `DocumentData::$is_proforma` against a **live
`ProformaOutputPolicy` call**, not a hand-written expectation, so a fork of the
predicate fails rather than drifts: draft · confirmed · paid-never-sealed ·
posted-never-sealed · confirmed credit note (all `true`) · posted+sealed ·
sealed-then-cancelled · historical/NON_FISCAL · quote (all `false`). Plus:
`proforma` present iff proforma; the totals are gross and close over the estimated
total (`238.000` / `119.000` per unit on a 2 × 100.000 @ 19% line); a definitive
invoice keeps `subtotal` 200.000 / `tax_amount` 38.000.

RED (before `ProformaPresenter` existed):
```
$ ./vendor/bin/phpunit tests/Feature/Document/ProformaResourceTest.php
Illuminate\Contracts\Container\BindingResolutionException:
  Target class [App\Modules\Document\Application\Services\ProformaPresenter] does not exist.
ERRORS! Tests: 20, Assertions: 0, Errors: 20.
```
GREEN:
```
$ ./vendor/bin/phpunit tests/Feature/Document/ProformaResourceTest.php
....................                                              20 / 20 (100%)
OK (20 tests, 40 assertions)
```

Regression, same runner:
```
$ ./vendor/bin/phpunit tests/Feature/Document/ProformaOutputTest.php
OK (44 tests, 511 assertions)
$ ./vendor/bin/phpunit tests/Feature/Document/ProformaResourceTest.php \
    tests/Feature/Document/ProformaTemplateCensusTest.php
OK (27 tests, 64 assertions)
$ ./vendor/bin/phpunit tests/Unit/Document/PaymentStatusCalculationTest.php \
    tests/Feature/Document/DocumentDataReturnDecisionProjectionTest.php \
    tests/Feature/Document/CreditNoteTenantIsolationTest.php \
    tests/Feature/Modules/Document/DocumentLineQuantityDecimalsTest.php
OK (28 tests, 97 assertions)
```
(The four above are every test in the tree that references `DocumentData`, which gained
two required constructor parameters. `grep -rn "new DocumentData("` → no direct
constructions anywhere. The full suite was NOT run — LANE-PROTOCOL.)

### 4.2 Web — three files, by path

RED, all three at once, before any implementation:
```
$ npx vitest run src/features/documents/components/DocumentTotals.proforma.test.tsx \
    src/features/documents/components/CreditNoteDetail.proforma.test.tsx \
    src/locales/__tests__/proformaCopyParity.test.ts
 ❯ src/locales/__tests__/proformaCopyParity.test.ts            (16 tests | 16 failed)
 ❯ src/features/documents/components/CreditNoteDetail.proforma.test.tsx (7 tests | 6 failed)
 ❯ src/features/documents/components/DocumentTotals.proforma.test.tsx   (6 tests | 5 failed)
 Test Files  3 failed (3)
      Tests  27 failed | 2 passed (29)
```
(One further `DocumentTotals` case — the `proformaTotals: null` fail-open probe — was
added after the first green and is counted in the 7/7 below.)
The two that passed red are the two that pin UNCHANGED behaviour on purpose
("leaves a definitive document exactly as it was", "keeps the cancelled marker") — they
are the posted-side half of the invariant and had to be green from the start.

GREEN (with the two legacy files that cover the same components):
```
$ npx vitest run src/features/documents/components/DocumentTotals.proforma.test.tsx \
    src/features/documents/components/CreditNoteDetail.proforma.test.tsx \
    src/locales/__tests__/proformaCopyParity.test.ts \
    src/features/documents/components/DocumentTotals.test.tsx \
    src/features/documents/components/CreditNoteDetail.test.tsx
 ✓ proformaCopyParity.test.ts            (16 tests)
 ✓ CreditNoteDetail.proforma.test.tsx     (7 tests)
 ✓ DocumentTotals.proforma.test.tsx       (7 tests)
 ✓ DocumentTotals.test.tsx               (11 tests)
 ✓ CreditNoteDetail.test.tsx             (15 tests)
 Test Files  5 passed (5)      Tests  56 passed (56)
```
Whole feature, no regression:
```
$ npx vitest run src/features/documents
 Test Files  51 passed (51)     Tests  434 passed (434)
```
`pgrep -fl vitest` → empty after the runs.

**Two pre-existing assertions were changed**, both in
`components/CreditNoteDetail.test.tsx`: `expect(screen.getByText('100.00'))` →
`/100[.,]000/`, and the case renamed from *formats amount with 2 decimals* to *formats
the amount at the document currency scale*. They pinned the float bug
(`parseFloat(total).toFixed(companyDecimals)` on a TND credit note); the comment above
the case records what changed and why.

**Why the token scan is not vacuous.** `src/test/proformaTokens.ts` (new) carries the FE
half of `ProformaOutputTest::FORBIDDEN_TOKENS` **and** a `translateFrom()` that resolves
against the real `en` bundles. A component test that mocks `t` into an identity function
would render `documents.subtotal`, not `Subtotal`, and every forbidden token would be
invisible to the scan. `\bHT\b` is anchored and case-sensitive for the reason the
backend gives.

**The copy-parity test reads the PHP as text** (`apps/api/lang/*/documents.php`) and
asserts `documents.proforma.*` + `documents.creditNote.*` are *verbatim* the backend
strings in all three locales, that all six keys are authored in each locale, that the
three titles differ (no locale silently left on English), and that the retired
`postingMarker.title` / `.detail` are gone. If someone makes those PHP values dynamic
the extractor returns no keys and the `toBeGreaterThan(0)` assertion goes red rather
than passing vacuously.

---

## 5. Guardrails

```
$ npx tsc --noEmit -p tsconfig.json          → clean (no output)
$ npx pnpm lint                              → PASS (whole chain: eslint · audit:keys ·
                                               audit:design-system · audit:quantity ·
                                               audit:i18n:local · test:eslint-rules ·
                                               test:tools → 8 files / 160 tests passed)
$ npx eslint .                               → 0 errors, 6457 warnings, exit 0
$ node tools/audit-tanstack-keys.mjs         → Gate C: 0 · baseline 0 acknowledged,
                                               0 new, 0 stale
$ node tools/audit-design-system.mjs         → 807 acknowledged, 0 NEW, 0 stale
$ node tools/audit-quantity-display.mjs      → 0 total, 0 new
```

**i18n completeness baseline — before / after** (same authority,
`I18N_BASELINE_PROTECTED_BLOB=26a9ae1688d80e0f450215326b19ccd1701c9a8f` from
`docs/handoff/progress/enforcement-p2.progress.yaml:73`; re-confirmed through
`scripts/i18n-baseline-authority.sh`):

| | before | after |
|---|---|---|
| namespaces | 55 | 55 |
| authored `en` | 9346 | **9351** |
| authored `fr` | 9362 | **9367** |
| authored `ar` | 4977 authored (1986 behind aliases) | **4982** authored (1986 behind aliases) |
| gaps held at baseline | 2762 | **2762** |
| NEW findings | 0 | **0** |
| burn-down note | 1 baseline entry now translated | 1 baseline entry now translated |

+5 authored keys per locale in all three (7 added, 2 retired), zero new gaps, zero
ratchet growth. **No regression.**

Backend:
```
$ ./vendor/bin/pint --test app/Modules/Document tests/Feature/Document/ProformaResourceTest.php
{"result":"pass"}
$ ./vendor/bin/phpstan analyse app/Modules/Document/Application \
    app/Modules/Document/Domain/Document.php \
    app/Modules/Document/Presentation/Controllers \
    tests/Feature/Document/ProformaResourceTest.php
 [OK] No errors
$ ./vendor/bin/deptrac analyse --no-progress
  Violations 183          (identical to the same command on `dev`: 183 — no regression)
```

---

## 6. Residuals — seen, NOT touched

1. **`react-doctor` pre-commit hook reported "staged regressions".** Every finding it
   lists in `apps/web/src/features/documents` is pre-existing and none is in code this
   lane wrote: `DocumentTotals.tsx:210` (chained iterations) and `:218` (array index as
   key) are the untouched `tax_details` map, shifted down by the inserted proforma
   branch; `InvoiceDetailPage.tsx:71` (`prefer-useReducer`) is the pre-existing
   `useState` cluster. The hook is advisory and the commit stands.
2. **`packages/shared/types/generated.d.ts` carried three unrelated stale hunks** into
   this commit (§2.4). Someone changed those PHP enums without regenerating. Whoever
   owns those lanes should be told the drift is now committed here, not on their branch.
3. **The proforma payload still ships `subtotal`, `tax_amount` and net
   `DocumentLineData.line_total` over the wire** on an unsealed document. Nothing
   renders them (and `DocumentTotals` no longer even requests the tax breakdown), but
   they are visible in devtools. Suppressing them on the resource is a larger change —
   the editor screens read `line_total` on a *draft* invoice — and was out of this
   lane's scope. Worth a ruling.
4. **`InvoiceDetailPage` still `parseFloat`s `outstanding_amount` / `total` /
   `amount_paid`** (`:450-461`, pre-existing, ESLint-warned). Untouched lines, rule 18/19
   scope. The proforma branch does not read any of them.
5. **`CreditNoteDetail` is an orphan.** `grep` shows it exported from
   `components/index.ts` and rendered by no page — only by its own tests. Fixed as the
   brief required, but its `window.print()` surface is currently unreachable from the
   app; the live credit-note print path is `CreditNoteDetailPage` → PDF endpoint.
6. **`components/index.ts` was not touched** — `ProformaBanner` is imported by path from
   the three call sites rather than added to the barrel, to keep the diff inside the
   files the brief names.
7. **`is_proforma` / `proforma` are optional on the two hand-written FE mirrors**
   (`types/document.ts`, `types/creditNote.ts`) because making them required would
   force edits across dozens of unrelated fixtures. Every read site compares
   `=== true`, so a missing field degrades to "definitive", which matches today's
   behaviour. A follow-up that regenerates the mirrors from `generated.d.ts` would
   close this properly.
8. **Arabic RTL print, the email note (C-26 ii) and the POS app** are out of scope per
   the brief and untouched.
