# C-F0w handback — web proforma parity

| | |
|---|---|
| Lane | C-F0w — web detail pages render the proforma the PDF renders (Session C, document-lifecycle-dimensions; LEDGER C-26 (i)) |
| Branch | `feat/sc-f0w-web-proforma-parity` |
| Worktree | `.worktrees/sc-f0w-web-proforma` |
| Base | `eaf80a112` (local `dev`) |
| Commits | `17a2e0fad` (lane — all three locale files in this ONE commit, per `.claude/context/i18n.md`) · `234f85303` (this handback) · `eb6dc752a` (the `proformaTotals: null` fail-open probe, added after the first green) |
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
$ npx pnpm lint                              → exit 0, whole chain (eslint · audit:keys ·
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

---

# Fix round r1

Against `2026-08-25-sc-f0w-gate-r1-conventions.md` (ACCEPT-WITH-CONDITIONS, merge-blocking
W-1 + W-2). Same branch and worktree; **no `apps/api` or `packages/` file was touched this
round** (`git diff --name-only <r1 base> -- apps/api packages` → 0), so every backend
evidence line in §4.1 and §5 above still stands unchanged.

| Item | Verdict | Status |
|---|---|---|
| W-1 status + payment chip on a proforma invoice | MAJOR · blocking | **CLOSED** |
| W-2 no test renders either live page | MAJOR · blocking | **CLOSED** |
| W-3 hand-written mirrors of generated DTOs | MAJOR | **CLOSED** |
| W-6 `!= null` on inner projection fields | MINOR | **CLOSED** |
| W-7 `CreditNoteDetail` docblock overstates reachability | MINOR | **CLOSED** (docblock only; mount-or-delete stays a ticket) |
| W-4 / W-5 / W-8 | MINOR · fiscal lens + owner | **NOT TOUCHED**, by instruction |
| W-9 stale evidence in the handback | MINOR | **CLOSED** — every number below is from a fresh run at the fix-round tip |

## W-1 — the chips come off (`InvoiceDetailPage`)

The credit-note page already did this; the invoice page now matches it.

- `apps/web/src/features/documents/components/DocumentHeader.tsx:61-71,95,137-142` —
  new `suppressStatusBadge?: boolean`, **default `false`**, so the other three callers
  (`QuoteDetailPage:240`, `SalesOrderDetailPage:386`, `PurchaseOrderDetailPage:334`) are
  untouched and pass nothing. The header stays ignorant of fiscal semantics: the caller
  owns the `is_proforma` decision and passes the consequence.
- `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:473` —
  `suppressStatusBadge={isProforma}`.
- `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:504-510` — the
  payment-status chip gains `&& !isProforma`, with the blade's own reason recorded
  above it ("a proforma is not a statement of account").

## W-2 — both live pages, scanned whole, with the real header

Two new files, and the scanner they use was widened first.

- `apps/web/src/test/proformaTokens.ts:14-38` — `FORBIDDEN_PROFORMA_TOKENS` is now
  explicitly two halves: the TAX half (the backend list verbatim, `/chain/i` restored)
  and a SEAL-AND-SETTLEMENT half — `/sealed/i`, `/posted/i`, `/\bunpaid\b/i`,
  `/\bpaid\b/i`. The last two are new. A blade has no status chip to emit, so
  `ProformaOutputTest` never had to name them; a detail page does, which is the whole
  of W-1.
- `apps/web/src/features/documents/invoices/__tests__/InvoiceDetailPage.proforma.test.tsx`
  (new, 6 cases) and
  `apps/web/src/features/documents/credit-notes/__tests__/CreditNoteDetailPage.proforma.test.tsx`
  (new, 6 cases).

Both render the LIVE page with the **real** `DocumentHeader`, `DocumentTotals`,
`StatusBadge`, `ProformaBanner`, items table **and** the real `CompanyConfigProvider`
(which renders its children unconditionally, so nothing is hidden from the scan). Only
network-bound children are mocked, and each mock is commented with why. Copy is the real
`en` bundle via `translateFrom` — an identity `t` renders
`sales:documents.statuses.posted` and hides the exact word the scan hunts, which is the
second half of why W-1 survived round 1 (the first half being
`InvoiceDetailPage.tenantScope.test.tsx:50-65`, which mocks `DocumentHeader` away).

Fixture is the worst shape on purpose: `status: 'posted'` + `payment_status: 'unpaid'`
on a document the chain never sealed.

Cases per file: whole-page token scan · no lifecycle/payment/sealed chip · gross line
figures present and the net basis (`100.000` / `200.000`) absent · `Estimated total`
present and `Subtotal` absent · **the projection-less fail-open path scanned clean** ·
and a DEFINITIVE posted-sealed case asserting the chips, `Subtotal`, `TVA 19%` and the
net line figures ARE back and the banner is not.

### RED → GREEN

RED, both files at the pre-fix tip `ddee0a195`:
```
$ npx vitest run src/features/documents/invoices/__tests__/InvoiceDetailPage.proforma.test.tsx
 × scans clean over the WHOLE page, real header included
   → the LIVE invoice page WITH its real header must not contain /posted/i — found: Posted
 × wears no lifecycle chip and no payment chip
   → expect(element).not.toBeInTheDocument()
 × still hides everything when the server ships NO proforma projection
   → a proforma invoice page with no projection must not contain /posted/i — found: Posted
 ✓ prints GROSS line figures and never the net basis
 ✓ heads the totals box with the estimated total
 ✓ leaves a DEFINITIVE posted invoice exactly as it was
      Tests  3 failed | 3 passed (6)

$ npx vitest run src/features/documents/credit-notes/__tests__/CreditNoteDetailPage.proforma.test.tsx
      Tests  6 passed (6)
```
The credit-note page is **green from the start** — it already suppressed its chips — and
that is the control: it proves the invoice failures are a real defect in that page, not
an artefact of the new harness. The three invoice cases that passed red are the ones
pinning behaviour W-1 does not affect (gross figures, estimated total, and the definitive
document), so they had to be green before the fix.

GREEN after W-1:
```
$ npx vitest run …/InvoiceDetailPage.proforma.test.tsx …/CreditNoteDetailPage.proforma.test.tsx
 ✓ CreditNoteDetailPage.proforma.test.tsx (6 tests)
 ✓ InvoiceDetailPage.proforma.test.tsx   (6 tests)
      Tests  12 passed (12)
```

## W-3 — aliases, not mirrors

`apps/web/src/types/document.ts:53-72` — both bodies replaced by
`export type ProformaLineAmounts = App.Modules.Document.Application.DTOs.ProformaLineAmounts`
and `export type ProformaPresentation = App.Modules.Document.Application.DTOs.ProformaPresentationData`,
docblocks kept and extended with the reason. House pattern per
`features/admin/country-defaults/types.ts:1-4`. A DTO field rename is now a compile
error rather than silent drift. The optional `is_proforma?` / `proforma?` stay on the
pre-existing hand-written `Document` mirror, as the gate directed.

## W-6 / W-7

- `apps/web/src/features/documents/components/DocumentTotals.tsx:108-142` — every inner
  projection guard is `!= null` (the object guard too), with the reason inline: the
  generated type says non-optional, but a payload that OMITS a key would otherwise walk
  into `formatAmount(undefined)`.
- `apps/web/src/features/documents/components/CreditNoteDetail.tsx:69-77` — the docblock
  no longer claims the component "owns the only in-browser document PRINT surface"; it
  records that the component is currently UNMOUNTED (exported from
  `components/index.ts`, rendered by no route; the live screen is
  `CreditNoteDetailPage`), that it was fixed because the brief named it and because a
  surface mounted later must not arrive carrying the defect, and that mount-or-delete is
  a separate ticket.

## Fresh evidence at the fix-round tip (W-9)

```
$ npx tsc --noEmit -p tsconfig.json                     → clean, exit 0
$ npx pnpm lint                                          → exit 0
$ npx eslint .  (inside the chain)                       → ✖ 6449 problems (0 errors, 6449 warnings)
$ node tools/audit-tanstack-keys.mjs                     → Gate C 0 · 0 acknowledged, 0 new, 0 stale
$ node tools/audit-design-system.mjs                     → 807 · 807 acknowledged, 0 NEW, 0 stale
$ node tools/audit-quantity-display.mjs                  → 0 total, 0 new, 0 stale
$ I18N_BASELINE_PROTECTED_BLOB=26a9ae16… node tools/audit-i18n-completeness.mjs
    → OK — 55 ns, authored en=9351 fr=9367 ar=4982 (1986 behind aliases),
      2762 gaps held at the baseline, 1 burned down, 0 new
$ npx vitest run src/features/documents src/locales/__tests__/proformaCopyParity.test.ts
    → Test Files 54 passed (54)   Tests 463 passed (463)
$ pgrep -fl vitest                                       → empty
```

**Warning count:** the gate measured **6448** at `ddee0a195`; the tip reports **6449**.
The delta is one, and it is not in this lane's code — the two new test files lint at
**zero** warnings (`npx eslint` on both → no output), and the four `require-await`
warnings the first draft of them produced were removed by returning
`Promise.resolve(...)` from the `mockImplementation`s instead of declaring them `async`.

**Audit baselines, r1-entry → fix-round tip:** tanstack `0 → 0`; design-system
`807 acknowledged / 0 new → 807 acknowledged / 0 new`; quantity `0 → 0`; i18n
`2762 gaps / 0 new → 2762 gaps / 0 new`, authored counts unchanged (no locale file was
touched this round). **No ratchet moved.**

## Residuals after r1

Residuals 1–8 in §6 above stand, with two updates:

- **§6.5** (`CreditNoteDetail` is an orphan) is now also written into the component's own
  docblock (W-7) and remains an open mount-or-delete ticket for the owner.
- **§6.7** (optional `is_proforma?` / `proforma?`) is narrowed: it now applies only to the
  two fields on the pre-existing `Document` mirror. The two new types are aliases to the
  generated bundle (W-3), so that half of the residual is closed.

New, from this round:

9. **`FORBIDDEN_PROFORMA_TOKENS` is now a superset of the backend list** (`/\bpaid\b/i`,
   `/\bunpaid\b/i`, `/sealed/i` on top of `ProformaOutputTest::FORBIDDEN_TOKENS`). That is
   deliberate and documented in the file, but it means the two lists can now drift in the
   direction the FE has extended. If the fiscal lens wants one canonical list, the FE half
   should be derived rather than re-declared.
10. **W-4 is now load-bearing on the page scan.** The settlement surfaces the gate flagged
    (`DocumentOutstandingCallout`, the payments tab's `OutstandingAmountSection`) currently
    render no forbidden token — the callout says "Amount Due", and the tab body only mounts
    when the user selects it, so the whole-page scan passes today. If the fiscal lens rules
    that those surfaces must go on a proforma, the fix is one more `!isProforma` term and
    the existing tests will not need to change; if it rules the opposite, note that a copy
    change to "Unpaid balance" anywhere in that callout would turn the page scan red.

### react-doctor at the fix-round tip

The pre-commit hook reported "staged regressions" again. Re-derived: this round's hunks
are `DocumentTotals.tsx:108-139` and `InvoiceDetailPage.tsx:473,504-510`
(`git diff HEAD~1 HEAD -U0 | grep '^@@'`). Every react-doctor finding sits outside them —
`DocumentTotals.tsx:213,221` (the untouched `tax_details` map, shifted +3 lines by an
added comment), `InvoiceDetailPage.tsx:71,912-928`, and
`CreditNoteDetailPage.tsx:33,398-423` in a file this commit does not modify at all. All
pre-existing; the tool is reacting to changed files, not changed lines.
