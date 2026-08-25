# C-F0 gate r1 — FISCAL lens (adversarial)

| | |
|---|---|
| Lane | C-F0 — confirmed-unposted output is a VAT-free proforma (Session C, document-lifecycle-dimensions) |
| Branch / worktree | `feat/sc-f0-proforma-output` · `.worktrees/sc-f0-proforma-output` |
| Reviewed SHA | `2974a6e40` (red `394aecc0d`, green `20f62a71b`) |
| Base | `0ae906b0e` |
| Date / round | 2026-08-25 · r1 |
| Lens | fiscal-pos-reviewer (adversarial, code-grounded) |
| Normative inputs | SPEC §2.4 r11 · expert ruling 2026-08-10 (Code TVA Art. 18) · owner sheet OQ-14 (fail-safe default, owner column BLANK/unruled) · brief BRIEF-C-F0 · handback 2026-08-25-sc-f0-handback.md |

**VERDICT: spec ✅ (with one conformance gap) + quality ACCEPT-WITH-CONDITIONS — merge-blocking: YES (condition 1 only).**

The lane does the thing it claims: it removes the VAT from an issued-but-unsealed sales
invoice / credit note, and it does not move a single byte of a posted document. Both claims
were re-derived, not taken from the handback. One conformance gap is provable and cheap to
close (F-1); everything else is Important-or-below or a spec-level question.

---

## 1. Verification legs

### Leg 1 — read the diff, first-hand
`git diff 0ae906b0e...2974a6e40` — 23 files, 11 production (2 PHP + 3 lang + 6 blade), 8 test/fixture,
1 manifest, 1 handback. No migration, no route, no controller, no `apps/web` / `apps/pos` file
(`git diff --name-only | grep -E 'migrations|routes|apps/(web|pos)'` empty). Confirms handback §9.

### Leg 2 — predicate ≡ marker arm (the property that makes the design safe)
Compared term by term:
- `ProformaOutputPolicy.php:44-62` — `isFiscalType ∧ fiscal_hash IS NULL ∧ ¬(VOIDED ∨ CANCELLED) ∧ ¬(is_historical ∨ NON_FISCAL)`
- `resources/views/documents/components/posting_marker.blade.php:71,76,81,86` — arms 1..4, where arm 4
  (`$isFiscalType && ! $isSealed`) is reached only when arms 1-3 did not fire, i.e. under exactly the
  same conjunction (`$isVoided` at `:44-46`, `$isHistorical` at `:67-68`).

They are identical. The banner and the stripping therefore cannot disagree — no shape can produce a
"Proforma" banner over a live VAT breakdown, nor a stripped page with no banner. This is load-bearing
and it holds.

### Leg 3 — REVERT-PROBE of the headline (base production files into the lane tree)
```
git restore --source=0ae906b0e --worktree -- \
  apps/api/app/Modules/Document/Application/Services/DocumentPdfService.php \
  apps/api/lang/{en,fr,ar}/documents.php apps/api/resources/views/documents
mv .../ProformaOutputPolicy.php <scratchpad>            # new file, base has none
php vendor/bin/phpunit tests/Feature/Document/ProformaOutputTest.php
  -> Tests: 18, Assertions: 26, Failures: 15
php vendor/bin/phpunit tests/Feature/Document/ProformaTemplateCensusTest.php
  -> Tests: 6, Assertions: 17, Errors: 1, Failures: 2
git restore --source=HEAD --worktree -- <same paths>     # tree restored, `git status --short` empty
```
Representative red: `draft invoice HTML must not contain /VAT/i — found: VAT, VAT`. Matches the
handback's claimed red counts exactly (15/18 and 1E+2F).

**Second, stronger result from the same probe:** the three tests that stayed GREEN under BASE
production code are `..._renders_identically_to_the_pre_change_snapshot` ×2 and
`..._still_shows_the_tax_breakdown`. The posted snapshots therefore pass against **both** the base
renderer and the lane renderer. That is independent proof — not a claim — that the snapshots were
captured on base and that posted output is unchanged.

### Leg 4 — TAMPER-PROBE of the posted pins (are they vacuous?)
1. Changed the POSTED branch amount only: `components/totals.blade.php` `Tax` row
   `$document->tax_amount` → `$document->subtotal` (invisible to a whitespace collapse).
   → `test_a_posted_invoice_renders_identically_to_the_pre_change_snapshot` FAILED, diff shows
   `<td>Tax</td> <td>38,000 DT</td>` → `<td>Tax</td> <td>200,000 DT</td>`. The collapsed-whitespace
   comparison is NOT vacuous: it catches a value change. (The credit-note pin correctly stayed green —
   the CN has its own totals block.)
2. Injected a CSS rule `.gate-probe { color: red; }` into `layouts/document.blade.php`'s `<style>`.
   → both snapshot tests FAILED (`Tests: 2, Assertions: 2, Failures: 2`). CSS edits are caught.
3. Anti-vacuity of the fixtures themselves: `tests/Fixtures/proforma/posted-invoice.pdf.txt` is a
   legible 35-line document (`Tax ID: 3E-TAX`, `VAT: TN-VAT-1234567`, `TAX / 19%`, `Subtotal 200,000 DT`,
   `Tax 38,000 DT`, `Total 238,000 DT`, footer `| Tax ID: 3E-TAX`) — a real rendering, not a heuristic.

Tree restored after each tamper; `git status --short` empty.

### Leg 5 — behavioural probe of shapes the test matrix does NOT cover
Temporary probe class (created, run, deleted; tree verified clean):
```
[PROBE] line_total=200.000 unit_price=100.000 qty=2.0000 subtotal=200.000 tax=38.000 total=238.000
[PROBE] footer contains TN-VAT: no      [PROBE] contains CUST-FISCAL-9: no
[PROBE PAID]   isProforma=YES  /posted/i: (none)   /\bpaid\b/i: PAID,PAID,paid,Paid
[PROBE POSTED] isProforma=YES  /posted/i: POSTED,POSTED,posted,Posted
```
(the two upper-case hits per row are my fixture's document number; the genuine hits are
`class="status-badge status-posted"` and the badge text `Posted` — `header.blade.php:36-38`.)
Rendered proforma body: `Qty 2,00 · Unit Price 100,000 DT · Amount 200,000 DT` then
`Estimated total 238,000 DT`; footer and both parties' fiscal identifiers correctly suppressed.
→ F-1 and F-2.

### Leg 6 — surface census re-run independently
`grep -rn "documents\.templates|documents\.country|documents/templates" app/ routes/` returns ONLY
`DocumentPdfService.php:134,140` (+ one comment). `DocumentPdfService` is constructed nowhere by hand
(`grep -rn "new DocumentPdfService|DocumentPdfService::class =>" app/ config/ tests/` → empty), so the
added constructor param is container-safe. `generateContent()` callers: `DocumentEmailService.php:48,106`
only. `DocumentEmailService` has **no status guard** (read `:30-70`) — an unposted invoice is emailable
today, which is what makes the Factur-X gate live rather than theoretical.

### Leg 7 — tests by path, sqlite AND PostgreSQL
PG: throwaway `autoerp_test_scf0_gate` on 127.0.0.1:5433, **dropped** after the run. Full suite never run.

| test | sqlite | pgsql |
|---|---|---|
| `tests/Feature/Document/ProformaOutputTest.php` | OK (18, 168) | OK (18, 168) |
| `tests/Feature/Document/ProformaTemplateCensusTest.php` | OK (6, 19) | OK (6, 19) |
| `tests/Feature/Document/PostingMarkerPrintTest.php` | OK (10, 20) | OK (10, 20) |
| `tests/Feature/Modules/Document/DocumentPdfSellerTaxIdTest.php` | OK (3, 5) | OK (3, 5) |
| `tests/Feature/Modules/Document/DocumentPdfRenderTest.php` | OK (6, 20) | OK (6, 20) |
| `tests/Feature/Compliance/FacturXWorkOrderInvoiceTest.php` | OK (2, 7) | — |
| `tests/Feature/Document/FacturXBranchSellerTest.php` | OK (2, 7) | — |
| `tests/Feature/Modules/Document/FacturXDescriptionTest.php` | OK (3, 7) | — |

### Leg 8 — guards
- `./vendor/bin/pint --test` on all 7 touched paths → `{"result":"pass"}`
- `phpstan analyse --level=8` on the 2 app files + 3 new test/trait files → `[OK] No errors`
- `php tools/deptrac-ratchet.php` → `TOTAL 183 183` · `RESULT: PASS — no boundary regression`
- `php tools/feature-lane-manifest-check.php` → `OK — 1420 Feature classes in 74 groups`

### Leg 9 — deleted i18n keys, all three apps
`documents.posting_marker.title` / `.detail`: in the lane tree the only remaining occurrences are two
prose mentions (`PostingMarkerPrintTest.php:25`, `lang/en/documents.php:22`). No live reader in
`apps/api`, `apps/web`, `apps/pos`. The FE has its OWN keys (`sales.json` `creditNotes.postingMarker.*`),
untouched and now stale — see R-1.

---

## 2. Findings

### [Important — MERGE-BLOCKING] F-1 · `apps/api/resources/views/documents/components/header.blade.php:35-39`
The status badge is rendered unconditionally, so a proforma states its lifecycle status verbatim.
Probe (Leg 5): a fiscal invoice with `status = Posted`, `fiscal_hash = NULL` — the never-sealed shape
`ProformaOutputPolicy.php:29-34` names in its own docblock, and the shape
`DocumentPdfSellerTaxIdTest` built before this lane — renders `class="status-badge status-posted"`
and the word `Posted`, and the badge is upper-cased into the PDF text by
`.status-badge { text-transform: uppercase }` (proof: the base snapshot
`tests/Fixtures/proforma/posted-invoice.pdf.txt:5` contains `POSTED`). That is the token `/posted/i`
— entry 9 of the lane's own `ProformaOutputTest::FORBIDDEN_TOKENS` (`:65`) and SPEC §2.4's explicit
"no seal, hash, chain sequence or QR ... no 'posted' wording" — printed on the document this lane
certifies as non-definitive.

`status = Paid` renders `PAID` / `Paid` on a page from which the lane has *deliberately deleted* the
`Paid` and `Balance Due` rows (`components/totals.blade.php:33-37`). Those are the real rows:
`RepairPaidNeverPostedDocumentsCommand.php:118-119` selects exactly `type = Invoice ∧ status = Paid`
with no seal — the tenant-#1 INV-2026-0003 population the handback §3 argues about.

**Why the test suite misses it:** `ProformaOutputTest` builds `Confirmed` (`:300`, `:310`) and `Draft`
(`:170`) only. The predicate admits four statuses; two are unasserted.

**Required change:** suppress or relabel the badge when `$isProforma` (the flag already reaches the
header component through `@include`), and add `Paid`-never-sealed and `Posted`-never-sealed rows to
the `ProformaOutputTest` matrix so the token scan covers every status the predicate admits.

### [Important] F-2 · `components/line_items.blade.php:53` + `components/totals.blade.php:38-42`
The proforma prints **net** line amounts under a **gross** "Estimated total". Probe: `2,00 × 100,000 DT
= 200,000 DT`, then `Estimated total 238,000 DT`. The page does not reconcile on its own face, and the
difference — 38,000 DT — is exactly the VAT the lane removed. `totals.blade.php:33-37` states the design
rule ("printing both a net and a gross line is a VAT breakdown written as a subtraction, and a reader
who can subtract has the tax amount back") and then the line-items table above it supplies the net
figure anyway.

Not a *mention* of VAT, so Art. 18 is not engaged and the lane conforms to SPEC §2.4 as written
(§2.4 prescribes "`Estimated total` (the TTC figure, unlabeled as such)"). This is therefore a **spec
defect surfaced by the lane**, not a lane defect — which is why it is not merge-blocking. It still
needs a ruling before a tenant issues proformas to customers: either render the proforma's unit price
and line amount tax-inclusive (what a commercial proforma does, and the page then reconciles and
nothing is derivable), or keep net lines and make the total the net figure. Whichever is chosen, pin
`Σ line amounts == estimated total` in `ProformaOutputTest`.

### [Important] F-3 · `apps/api/tests/feature-lane-manifest.json` (Document group) + `.github/workflows/ci.yml:983`
`ProformaOutputTest` and `ProformaTemplateCensusTest` are not named in the `backend-test-pgsql`
`--filter` allowlist (grep-verified against the full allowlist at `ci.yml:983`), and their group
`Document` is parked behind `vars.SELF_HOSTED_RUNNER_READY`. **The only guards against re-introducing
the Art. 18 exposure execute on no CI event.** The manifest note the lane just edited records the
standing precedent for exactly this case — `CorrectingEntryEndpointTest`,
`SupplierGoodsReturnNoteTest` and `PurchaseOrderUnpricedLineConfirmTest` were each added to that
allowlist because a first-tenant-path guard "is not something to leave unarmed". A VAT-liability guard
on the first tenant's invoice printout is at least as load-bearing as a stock-valuation one.
**Required change:** add `ProformaOutputTest` (preferably both classes) to the `ci.yml:983` allowlist
and record the remove-on-gate-flip note the same way the other three entries do.

### [Minor] F-4 · `tests/Feature/Document/ProformaTemplateCensusTest.php:47,85,139`
The census is a string heuristic with two soft edges. `TAX_EMISSION` =
`/__\('(Tax|VAT|Tax ID)'\)|tax_amount|tax_rate|tax_id|vat_number|showTax/` does not match `__('TVA')`,
hardcoded `TTC` / `HT` literals, `stamp_duty_amount`, `document_tax_details` or `taxBreakdown`; and the
"is it gated" check is `str_contains($contents, 'isProforma')` (`:85`) / `assertStringContainsString`
(`:139`), which a mention inside a comment satisfies. Nothing is wrong today (no country template
exists — pinned by the `glob()` assertion at `:150-158`), but this class is what is supposed to hold
after the lane. Widen the emission regex; require the flag inside a directive.

### [Minor] F-5 · `resources/views/documents/layouts/document.blade.php:347-356`
The comment asserts that "a `@php` reassignment inside `@section('content')` does not reach it", and
uses that as the reason for `?? false` where the two fiscal templates use `?? true`. Blade compiles
`@extends` into a trailing `$__env->make($layout, Arr::except(get_defined_vars(), …))` that executes
**after** the section body in the same function scope, so the reassigned `$isProforma` does reach the
layout. Harmless in production (`prepareData()` always supplies the key — probe confirms the footer
identifier is suppressed) but the stated rationale is wrong and will mislead the next editor.

### [Minor] F-6 · `resources/views/documents/layouts/document.blade.php:2` (residual confirmation, pre-existing)
`<html lang="{{ $locale ?? 'en' }}">` with no `dir` attribute, and `grep -rn "dir=|rtl" resources/views/documents/`
returns zero hits. The new `ar` banner renders inside an LTR layout whose classes are `text-align:left/right`,
and unshaped in the PDF (handback R-4). Pre-existing — N-6's marker had the identical exposure — and the
lane handles it correctly by restricting `ar` PDF assertions to the token scan while asserting the `ar`
strings on the HTML. No change required here; do not read the `ar` strings as "rendering correctly".

### Observations (recorded, not findings)
- The proforma keeps the fiscal document title (`Facture`, `header.blade.php:32`) and its invoice
  number, and still prints `Payment Information / Due Date` (`payment_info.blade.php`). SPEC §2.4
  requires neither to change and neither is a VAT mention; recorded so a later ruling is not surprised.
- `$locale` in the layout is the **company** locale while `__()` follows the **request** locale — the
  probe rendered `<title>Facture …` under app locale `en`. Pre-existing split (handback R-3), N-6 had it.

---

## 3. Rulings on the two flagged items

### RULING 1 — "the predicate is the SEAL, so a legacy `Paid`-but-never-sealed invoice prints as a VAT-free proforma": **UPHELD.**
1. Art. 18 liability attaches to the VAT *mentioned on an issued invoice*. The only server-side fact
   proving a sales invoice entered the fiscal chain is `fiscal_hash`. `status` is a lifecycle cache that
   writers have historically moved without sealing — the `Confirmed → Paid` hole N-6 closed, and
   `RepairPaidNeverPostedDocumentsCommand` exists precisely because those rows are real production data
   on tenant #1 (`:118-119`).
2. The alternative the handback offers — gate on `status IN (Draft, Confirmed)` — is strictly worse:
   it would leave exactly those `Paid`-never-sealed rows printing a VAT amount backed by no GL entry
   and no chain entry. That is the exposure the lane exists to close, on the very rows the campaign
   cares about. So the "regression for tenant #1's campaign rows" framing inverts: losing the VAT on
   those printouts **is** the correct outcome, and the repair command's whole purpose is to move them
   back to `Confirmed` anyway.
3. Safety is structural, not incidental: the predicate is term-for-term identical to N-6's marker arm
   (Leg 2), so banner and stripping can never disagree. The sealed-then-cancelled and
   historical-opening exclusions are correct (a document that WAS issued with VAT must keep saying so)
   and are independently pinned by `PostingMarkerPrintTest`, 10/10 green on sqlite and PG.
4. **The one cost of this ruling is F-1**: those same rows now print a `PAID` badge on a page that calls
   itself an estimate and from which the Paid/Balance rows were removed. Close F-1 and the ruling is clean.
5. OQ-14's owner column is still blank; the lane implements the sheet's fail-safe default, which is what
   an unruled question requires. No owner ruling is needed to merge; one is still owed to close OQ-14.

### RULING 2 — the Factur-X gate at `DocumentPdfService.php:79`: **IN SCOPE. KEEP IT.**
1. Scope: the brief names "print/PDF controllers/services". `generateContent()` **is** the PDF service,
   and its only callers are `DocumentEmailService::send():48` / `queue():106` — the path that puts the
   file in the customer's inbox. `DocumentEmailService` carries **no** status guard (`:30-70`), so an
   unposted invoice is emailable today. The gate is live, not hypothetical.
2. Necessity: `FacturXService::isEligible()` (`FacturXService.php:34-61`) asks only FR company +
   partner VAT number + `type = Invoice` + no XML yet — nothing about the ledger. Without the gate an
   FR B2B proforma would ship a PDF whose visible page carries no VAT and whose embedded XML carries
   the full tax total, structured for automated deduction. That would have made the lane's own
   invariant false on the FR B2B path — i.e. omitting the gate would have been the defect.
3. Blast radius verified nil: `facturx_xml` is written only at `DocumentPdfService.php:83` and read only
   at `FacturXService.php:42` (grep over `app/`). No e-invoicing/submission consumer is starved. All four
   Factur-X classes green (12/12, 2/2, 2/2, 3/3). `FacturXService` itself is untouched.
4. Recorded caveat (pre-existing, unchanged): the XML is produced only on the email path, never on the
   download path, so a posted FR B2B invoice that is downloaded and never emailed still has no XML.

---

## 4. Residual disposition (R-1..R-7) — is any of them a NEW fiscal exposure from this lane?

**No.** None of the seven is a new fiscal exposure. Two are newly-created *inconsistencies*, both
correctly reported by the lane:

- **R-1 — web-side VAT rendering: pre-existing, and yes, the web detail page now contradicts the PDF.**
  Stated plainly, as asked: `apps/web/src/features/documents/components/DocumentTotals.tsx:101-140`
  renders `Subtotal` plus one row per tax (`{tax_name} {tax_rate}%` + amount) with **no posted-ness
  branch**, so a confirmed-unposted invoice shows its full VAT on screen while the same document's PDF
  now carries none. And `apps/web/src/locales/{en,fr,ar}/sales.json` `creditNotes.postingMarker.title/detail`
  (en at `:872`) still read "Not yet posted — no fiscal seal" / "…no hash-chain entry…, is not a
  definitive fiscal credit note" — the exact copy this lane deleted server-side. `InvoiceDetailPage.tsx`
  has no marker at all. Not an Art. 18 exposure: liability attaches to the *issued* document, and
  `useDocumentPdf.ts` fetches the now-gated server PDF, so nothing VAT-bearing leaves the building.
  It is a product-level divergence a FE lane must close (needs FE i18n in three locales).
- **R-2 — email covering note: confirmed verbatim, Important follow-up, not this lane's blocker.**
  `DocumentMail.php:105-107` builds `"{Facture} {number} de {company}"`;
  `resources/views/emails/documents/document.blade.php:79,82,85` renders "Please find attached your
  {invoice}", an `<h2>` of the document title, and `Total: {{ number_format((float) $total, 2) }} {{ $currency }}`.
  The attachment is a correct proforma; the covering note calls it an invoice. No token F-95 forbids
  appears, so the lane's invariant holds on the artefact. The `(float)` cast is a pre-existing rule-19
  violation (2-decimal display of a 3-decimal currency), untouched here.
- **R-3** (request-locale vs company-locale split) — confirmed by probe: app locale `en` produced
  `<title>Facture …`, `Facture`, `238,000 DT`. Pre-existing; N-6's marker had it identically.
- **R-4** — see F-6. Pre-existing.
- **R-5** (`posting_marker` included by hand in two templates) — accurate; `ProformaTemplateCensusTest`
  covers the tax-mention half, not the banner half. Acceptable; see F-4.
- **R-6** (`DocumentPdfService::formatMoney()` casts money to float, `:255-266`) — pre-existing and
  untouched. The lane adds **no** new float cast: `ProformaOutputPolicy` does no money math, and the
  new `Estimated total` row reuses the existing `$formatMoney` closure. Rule-19 posture unchanged.
- **R-7** (`PostingMarkerPrintTest.php:196` `return.type`) — pre-existing, outside CI's PHPStan scope
  (`phpstan.neon` analyses `app/` only); the two new test classes are written correctly.

---

## 5. Manifest note

`groups.Document.classes` **84 → 86**, `gated_ceiling` **1173 → 1175** — deliberate, and recorded in the
group note with the lane, the two class names, the SPEC reference, the sqlite+PG run and the explicit
statement that neither class is in a live `--filter` allowlist. Arithmetic re-derived at the current
dev tip: `git show dev:apps/api/tests/feature-lane-manifest.json` at `3bbe28480` still reports
`gated_ceiling 1173` / `Document classes 84`, so the +2 union holds at merge. `feature-lane-manifest-check.php`
→ `OK — 1420 Feature classes in 74 groups`. deptrac 183/183, PASS. The note's own honesty about
zero CI selection is what produced F-3 — the record is correct; the disposition is what needs changing.

---

## 6. Conditions

1. **[MERGE-BLOCKING]** Close F-1: gate the status badge (`header.blade.php:35-39`) on `$isProforma`,
   and extend the `ProformaOutputTest` matrix to the `Paid`-never-sealed and `Posted`-never-sealed
   shapes so the `/posted/i` token scan covers every status the predicate admits.
2. Add `ProformaOutputTest` (and preferably `ProformaTemplateCensusTest`) to the `backend-test-pgsql`
   `--filter` allowlist at `.github/workflows/ci.yml:983`, per the precedent recorded in the same
   manifest note — otherwise the only guard on this invariant runs on no CI event (F-3).
3. Obtain a ruling on F-2 (net line amounts under a gross "Estimated total": the page does not
   reconcile and the VAT is recoverable by one subtraction) before any tenant issues proformas.
   This is a SPEC §2.4 question, not a lane defect — the lane implemented §2.4 as written.
4. Open the FE follow-up lane for R-1 (posted-ness branch on `DocumentTotals.tsx`; re-word
   `sales.json creditNotes.postingMarker.*` in en/fr/ar to match the server proforma copy) and R-2
   (email covering note title + `Total` label, plus the pre-existing `(float)` cast at
   `emails/documents/document.blade.php:85`). Neither is merge-blocking for C-F0.
5. Fix the incorrect Blade-scoping rationale in `layouts/document.blade.php:347-356` (F-5) and widen
   the census heuristics (F-4) — comment/test-only, may ride the same fix round as condition 1.
6. OQ-14 remains formally unruled (owner column blank). The lane ships the sheet's fail-safe default,
   which is correct; record the ruling when the owner gives it.

**VERDICT: spec ✅ + quality ACCEPT-WITH-CONDITIONS — merge-blocking: YES (condition 1).**
