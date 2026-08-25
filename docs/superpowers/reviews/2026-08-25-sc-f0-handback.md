# C-F0 handback — confirmed-unposted output is a VAT-free proforma

**Lane** C-F0 (Session C, document-lifecycle-dimensions program) · SPEC §2.4 r11 (F-13 / F-64 / F-95; owner OQ-14
fail-safe default) · brief `docs/sessions/session-C-lifecycle-2026-08-24/BRIEF-C-F0-proforma-output.md`

| | |
|---|---|
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sc-f0-proforma-output` |
| Branch | `feat/sc-f0-proforma-output` |
| Base | `0ae906b0e` (local `dev` at lane start — C-0a0 `0558b5a29` and N-6 already merged) |
| Red commit | `394aecc0d` — tests + base-captured snapshots, no production file touched |
| Green commit | `20f62a71b` — implementation |
| Handback commit | see `git log -1` on the branch |
| **Migration** | **NONE.** No file added, changed or removed under `apps/api/database/migrations/`; verified `git diff --name-only 0ae906b0e | grep migrations` is empty. |
| Merged? | **No.** Awaiting the fiscal-pos-reviewer + frontend-conventions-reviewer gates. |

> **Local `dev` moved during the lane** (`0ae906b0e` → `3bbe28480`). The branch was NOT rebased. `git diff dev` is
> therefore misleading; review against `git diff 0ae906b0e`. Manifest arithmetic below was re-checked against the
> new dev tip and is still correct, but re-derive it at merge if dev moves again.

---

## 1. What changed, in one paragraph

N-6 ruled that a confirmed invoice may be printed, and shipped a marker line saying it is not yet booked. The
document *underneath* that marker was untouched: it still carried the VAT rate column, the `Tax` total row, the
seller's VAT number and both parties' fiscal identifiers. Under Code TVA Art. 18 the VAT mentioned on an **issued**
invoice is owed by the act of issuing it, and the recipient can deduct from the paper regardless of a sentence
printed beside the amount. F-95 removes the amount. The same document set N-6's marker arm selected — a **fiscal
type that is not sealed, not voided/cancelled and not historical** — now renders as a proforma: no `VAT` / `TVA` /
`tax` / `TTC` / `HT` label or amount, no rate column, no tax breakdown, no seal/hash/chain/QR block, no
`posted` / `comptabilisée` wording; one `Estimated total` row carrying the gross figure, and the line
`Proforma — non-fiscal document`, in en/fr/ar. **Posted output is unchanged**, pinned against snapshots captured
on the base commit.

## 2. Surface census

Every path that renders a `documents` row, found by `grep -rn "->view('documents\|Pdf::loadView\|resolveTemplate"`
over `app/`, plus a token census (`tax_amount`, `tax_rate`, `tax_id`, `vat`, `tva`, `TTC`, `HT`, `fiscal_hash`,
`qr`) over `resources/views/documents/**`.

### 2.1 Entry points (all of them funnel through one service)

| file:line | surface | status |
|---|---|---|
| `apps/api/app/Modules/Document/Application/Services/DocumentPdfService.php:47-49` | `Pdf::loadView(resolveTemplate($document), viewDataFor($document))` — **the only** renderer of `documents.templates.*` | **gated** (`isProforma` in the view data, `:196`) |
| `apps/api/app/Modules/Document/Application/Services/DocumentPdfService.php:128-140` | `resolveTemplate()` — prefers `documents.country.{code}.{type}` over `documents.templates.{type}` | no country template exists; **pinned** by `ProformaTemplateCensusTest::test_no_ungated_country_template_shadows_a_fiscal_template` |
| `apps/api/app/Modules/Document/Presentation/Controllers/DocumentPdfController.php:28,41,54` | `download` / `preview` / `generatePath` (`GET /documents/{document}/pdf`, `/pdf/preview`, routes.php:451-457) | inherits the service gate — **no controller change needed** |
| `apps/api/app/Modules/Communication/Application/Services/DocumentEmailService.php:48,106` | `send()` / `queue()` → `generateContent()` → attached to `DocumentMail` | inherits the gate, **plus the Factur-X gate below** |
| `apps/api/app/Modules/Document/Application/Services/DocumentPdfService.php:70-90` | `generateContent()` embeds a Factur-X XML (BASIC WL) into the PDF for FR B2B invoices | **newly gated** — see §2.3 |

There is **no separate HTML print controller**. `grep -rn "->view('documents"` over `app/` returns only the PDF
service; the blade tree has exactly one consumer.

### 2.2 Blade tree — where the gate now sits

| file:line | what it emitted | gate |
|---|---|---|
| `resources/views/documents/components/posting_marker.blade.php:86` | N-6's 4th arm (`$isFiscalType && ! $isSealed`) | arm **selection unchanged**; its copy is now `documents.proforma.title` / `.detail` (`:104-105`) |
| `resources/views/documents/templates/invoice.blade.php:13` | — | `$isProforma = $isProforma ?? true` (fail-safe, OQ-14) |
| `resources/views/documents/templates/invoice.blade.php:23` | `'showTax' => true` | `'showTax' => ! $isProforma` |
| `resources/views/documents/templates/credit_note.blade.php:13` | — | same fail-safe resolve |
| `resources/views/documents/templates/credit_note.blade.php:47` | `'showTax' => true` | `'showTax' => ! $isProforma` |
| `resources/views/documents/templates/credit_note.blade.php:63-84` | its **own** totals block — `Subtotal` / `Tax` / `Credit Total` (it does not use the shared component) | `@if($isProforma)` → one `Estimated total` row |
| `resources/views/documents/components/line_items.blade.php:8,17,53` | `<th>Tax</th>` + `{{ $line->tax_rate }}%` | `$showTaxColumn = ($showTax ?? true) && ! ($isProforma ?? false)` — belt **and** braces |
| `resources/views/documents/components/totals.blade.php:31` | `Subtotal` / `Discount` / `Tax` / `Total` / `Paid` / `Balance Due` | `@if($isProforma ?? false)` → one `Estimated total` row |
| `resources/views/documents/components/parties.blade.php:21,22,46` | seller `Tax ID`, seller `VAT`, buyer `Tax ID` | suppressed when proforma |
| `resources/views/documents/layouts/document.blade.php:359` | footer `Tax ID`, repeated on every page | suppressed when proforma |

**No seal/hash/QR markup exists anywhere in `resources/views/documents/**`** (re-verified: `fiscal_hash`,
`previous_hash`, `chain_sequence`, `qr`, `<svg>` return zero hits outside the posting-marker docblock). N-6's
comment claiming this is still accurate. The proforma test asserts absence anyway, so the block a later lane adds
inherits the requirement.

### 2.3 The one non-obvious surface: Factur-X

`DocumentPdfService::generateContent():79` now reads:

```php
if (! $this->proformaPolicy->isProforma($document) && $this->facturXService->isEligible($document)) {
```

`FacturXService::isEligible()` asks only "FR company, B2B partner (partner has a VAT number), type = Invoice, no XML
yet" — it says **nothing** about whether the invoice exists in the ledger. Without this gate, a confirmed-unposted
FR B2B invoice would ship a PDF whose visible page carries no VAT and whose **embedded, machine-readable XML carries
the full tax breakdown** — and `DocumentEmailService` is what sends it to the customer. Same document, two
contradictory statements, one of them structured for automated deduction. `FacturXService` itself is untouched, so
the e-invoicing lanes that call `isEligible()` for submission keep their semantics; once the invoice is posted and
sealed the next `generateContent()` embeds the XML exactly as before.

### 2.4 Out of scope, per the brief

`apps/web` React rendering of document totals is **not** covered — see residual **R-1**.

## 3. The predicate

`apps/api/app/Modules/Document/Application/Services/ProformaOutputPolicy.php` (new, 66 lines, constructor-free,
injected into `DocumentPdfService`):

```
fiscal type (Invoice | CreditNote)   AND
fiscal_hash IS NULL                  AND
NOT (fiscal_status = VOIDED OR status = CANCELLED)   AND
NOT (is_historical OR fiscal_category = NON_FISCAL)
```

This is **exactly** the document set N-6's `@elseif($isFiscalType && ! $isSealed)` arm already selected, which is
why the arm selection did not move — only the copy under it and the page around it did. It is the **seal**, not the
lifecycle status, for N-6 fiscal gate F-4's reasons, and that matters in both directions:

- A **sealed-then-cancelled** invoice keeps its hash. It *was* issued with VAT; hiding the VAT on its reprint would
  make the reprint disagree with both the ledger and the copy the customer holds. It keeps the cancelled marker.
- A **historical opening balance** (`ArApOpeningService`: Posted, NON_FISCAL, no hash) is migrated production data
  that *was* posted — in the previous system. It is not an estimate and is not re-labelled as one.
- A **`Paid`-but-never-sealed** invoice (the INV-2026-0003 shape `documents:repair-paid-never-posted` exists to
  undo) has no GL entry and no VAT anywhere except on the paper. It renders as a proforma. This is OQ-14's
  fail-safe default applied deliberately, and it is what forced the fixture change in §6.

## 4. Tests — red on base, green after, by path

One test process at a time; never the full suite. sqlite = `phpunit.xml` default; PG = PostgreSQL 16 on port 5433,
throwaway `autoerp_test_scf0`, created and **dropped** by this lane.

PG invocation used verbatim:
```
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_scf0 \
DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret php vendor/bin/phpunit <path>
```

| test file | RED on base (`0ae906b0e`) | GREEN sqlite | GREEN PG |
|---|---|---|---|
| `tests/Feature/Document/ProformaOutputTest.php` (new) | `Tests: 18, Assertions: 24, Failures: 15` | `OK (18 tests, 168 assertions)` | `OK (18 tests, 168 assertions)` |
| `tests/Feature/Document/ProformaTemplateCensusTest.php` (new) | `Tests: 6, Assertions: 17, Errors: 1, Failures: 2` | `OK (6 tests, 19 assertions)` | `OK (6 tests, 19 assertions)` |
| `tests/Feature/Document/PostingMarkerPrintTest.php` (N-6, adapted — §5) | n/a (green on base by construction; 3 failed against the new copy before its assertions moved) | `OK (10 tests, 20 assertions)` | `OK (10 tests, 20 assertions)` |
| `tests/Feature/Modules/Document/DocumentPdfSellerTaxIdTest.php` (fixture fix — §6) | n/a (green on base; 2 failed after the gate landed) | `OK (3 tests, 5 assertions)` | `OK (3 tests, 5 assertions)` |
| `tests/Feature/Modules/Document/DocumentPdfRenderTest.php` (untouched, regression) | — | `OK (6 tests, 20 assertions)` | `OK (6 tests, 20 assertions)` |
| `tests/Feature/Compliance/FacturXWorkOrderInvoiceTest.php` (untouched, regression) | — | `OK (2 tests, 7 assertions)` | `OK (2 tests, 7 assertions)` |
| `tests/Feature/Document/FacturXBranchSellerTest.php` (untouched, regression) | — | `OK (2 tests, 7 assertions)` | `OK (2 tests, 7 assertions)` |
| `tests/Feature/Modules/Document/FacturXDescriptionTest.php` (untouched, regression) | — | `OK (3 tests, 7 assertions)` | `OK (3 tests, 7 assertions)` |
| `tests/Unit/Document/FacturXEligibilityTest.php` + `FacturXServiceTest.php` (untouched) | — | `OK (12/12)` each | — (no DB) |

**The 15 red are the whole invariant**: invoice HTML ×3 locales, credit-note HTML ×3, invoice PDF text ×3,
credit-note PDF text ×3, the en verbatim-strings test, the fr translated test, and the draft fail-safe test. The 3
that were green on base are the two posted-snapshot pins and `test_a_posted_invoice_still_shows_the_tax_breakdown`
— they are there to stay green, and a lane that made them red would have broken definitive invoices.

Representative red line (base):
```
draft invoice HTML must not contain /VAT/i — found: VAT, VAT
Failed asserting that 2 is identical to 0.
```

### 4.1 How the PDF is asserted

No PDF parser is installed (`composer.json` has `barryvdh/laravel-dompdf` and nothing else), so
`tests/Traits/ExtractsPdfText.php` (new) extracts the text: inflate each `stream…endstream`, keep only the streams
carrying `BT ` **and** ` Tf ` (font programs inflate too and happen to contain the bytes `TJ`), pull the `[ (…) ]
TJ` arrays, PDF-unescape, transcode UTF-16BE → UTF-8. The inner match is `[^\]]*` and not a lazy `.*?` on purpose:
PCRE exhausts its backtrack limit on an inflated 1.7 MB stream (observed, not theorised). Sample of the base
invoice's extracted text is in `tests/Fixtures/proforma/posted-invoice.pdf.txt`, and it is legible — this is the
document, not a heuristic.

### 4.2 Token scan

```php
'/VAT/i', '/TVA/i', '/\btax/i', '/TTC/i', '/\bHT\b/',
'/fiscal_hash/i', '/chain/i', '/\bQR\b/i', '/posted/i', '/comptabilis/i'
```
`\bHT\b` is anchored and case-**sensitive**: an unanchored `ht` matches `height`, `right`, `white`, and the marker
band is upper-cased by the stylesheet, so the PDF text contains `RIGHT`. The HTML is scanned with `<style>` and
HTML comments stripped first — `layouts/document.blade.php` declares `.status-posted`, and a CSS class name shared
by all eight templates is not a statement about this document. The PDF text needs no such treatment: it contains
only drawn glyphs, and it is scanned raw, in all three locales.

## 5. Snapshot proof for the posted output

Captured **on the base commit, before any production file moved**, by temporarily adding
`test_zzz_capture_base_snapshots()` to `ProformaOutputTest` (it reuses that class's own private fixture builders, so
the snapshot and the later comparison are produced by identical code), running
`php vendor/bin/phpunit tests/Feature/Document/ProformaOutputTest.php --filter capture_base_snapshots`, then
deleting the method. The four files landed in the RED commit `394aecc0d`, whose diff contains **no** production
file:

- `tests/Fixtures/proforma/posted-invoice.html` (11 321 B) · `posted-invoice.pdf.txt` (395 B)
- `tests/Fixtures/proforma/posted-credit-note.html` (11 452 B) · `posted-credit-note.pdf.txt` (429 B)

Fixtures are deterministic by construction: fixed document number, fixed `document_date` / `due_date`, fixed
amounts, fixed party names, both parties carrying real fiscal identifiers (`3E-TAX`, `TN-VAT-1234567`,
`CUST-FISCAL-9`) so that hiding them is observable, and a **non-zero** tax amount (the shared fixture builds
documents at `tax_amount = 0.000`, which would have made the whole invariant vacuous).

### What "unchanged" is asserted to mean — read this, the word *byte-identical* is doing real work

1. **The extracted PDF text is compared byte for byte** and is identical. That is the document: every label, every
   amount, in order, as the customer and the auditor read it. (The PDF *file* is not comparable — dompdf stamps
   `CreationDate`.)
2. **The HTML is compared with runs of whitespace collapsed**, and its `<style>` block byte for byte on top of
   that. Gating a template means adding `@if` / `@php` lines and the comments that explain them; Blade emits the
   indentation in front of each directive, so the HTML *source* gains whitespace that neither a browser nor dompdf
   can see. Collapsing it compares every element, attribute, label and amount and ignores exactly the thing that
   changed. The stylesheet is compared strictly because a CSS edit *would* be visible — and this lane makes none:
   a `.posting-marker--proforma` rule was drafted, found to be byte-for-byte redundant with `.posting-marker`, and
   **removed**, so `layouts/document.blade.php`'s `<style>` block is untouched.
3. `test_a_posted_invoice_still_shows_the_tax_breakdown` is the anti-vacuity pin: the snapshots would also pass if
   the page went blank, so a posted invoice is separately asserted to still show `Tax`, `38,000`, the seller `VAT`
   number and **no** proforma banner.

## 6. Which N-6 / existing assertions changed, and why

**`tests/Feature/Document/PostingMarkerPrintTest.php`** (N-6 item 6). The arm *selection* logic is untouched —
that is the property the class was written to hold, and all ten tests still exercise the same eight document
shapes. What moved:

| assertion | before | after | why |
|---|---|---|---|
| 3 method names | `…prints_the_not_yet_posted_marker` (×2), `…prints_the_marker_too` | `…prints_the_proforma_banner` (×2), `…prints_the_proforma_banner_too` | the arm renders a different thing now |
| 6 `__()` calls | `documents.posting_marker.title` / `.detail` | `documents.proforma.title` / `.detail` | **those two keys were deleted** from `lang/{en,fr,ar}/documents.php` — they had no other reader (grep-verified across `apps/api`, `apps/web`, `apps/pos`). Leaving the old names would have made every `assertStringNotContainsString(__('documents.posting_marker.title'), …)` **vacuously true** against a raw key string, silently disarming the F-4 and R2-F1 regression guards |
| 2 failure messages | "must never be described as unsealed" / "must not be told it has not been posted" | reworded to the new fact (was issued with VAT / is not an estimate) | the old wording described copy that no longer exists |

The class docblock records the supersession in place.

**`tests/Feature/Modules/Document/DocumentPdfSellerTaxIdTest.php`** — a **fixture** fix, not an assertion change.
Its two tax-identity tests assert the seller's fiscal identifier is *on* the page, but built it on a document with
`status = Posted`, `fiscal_category = TAX_INVOICE`, **no `fiscal_hash`** — precisely the never-sealed shape
`DocumentStatusService::wasNeverSealed()` describes, which SPEC §2.4 renders as a proforma. `makeInvoice()` gained
`bool $sealed = false`, and those two tests pass `sealed: true` so their fixture is a definitive invoice. The seal
is applied **at creation via `array_merge`**, not by a later `save()`: on PostgreSQL `trg_document_immutability`
refuses an update to an already-sealed row, which is also why the third test (`purchase_rfq…`, which mutates its
document afterwards) keeps an unsealed fixture. Assertions unchanged; all three green on sqlite and PG.

## 7. i18n keys added (3 locales, existing `documents` namespace, dotted keys)

| key | en | fr | ar |
|---|---|---|---|
| `documents.proforma.title` | `Proforma — non-fiscal document` | `Proforma — document non fiscal` | `مبدئية — مستند غير ضريبي` |
| `documents.proforma.detail` | "This is an estimate issued before the sale has been entered in the accounts. It is not a definitive fiscal document, it carries no seal, and it confers no right of deduction. A definitive document will be issued once the sale is entered." | "Il s'agit d'une estimation établie avant l'enregistrement de la vente dans les comptes. Ce n'est pas un document fiscal définitif, il ne porte aucun scellement et n'ouvre aucun droit à déduction. Un document définitif sera émis une fois la vente enregistrée." | "هذا تقدير صادر قبل إدخال البيع في الحسابات. ليس مستنداً ضريبياً نهائياً، ولا يحمل أي ختم، ولا ينشئ أي حق في الخصم. سيُصدر مستند نهائي بعد إدخال البيع." |
| `documents.proforma.estimated_total` | `Estimated total` | `Total estimé` | `المجموع التقديري` |

**Deleted** (dead after this lane, no other reader): `documents.posting_marker.title`, `documents.posting_marker.detail`
in all three locales. `cancelled_title`, `cancelled_detail`, `cancelled_unsealed_detail`, `historical_title`,
`historical_detail` are untouched in all three.

Every string is deliberately free of the forbidden tokens — the French copy in particular avoids *taxe*, *TVA* and
*comptabilisée*, which is why it says "l'enregistrement de la vente dans les comptes" and "scellement". The token
scan runs against the **rendered fr and ar output**, so a translator who reintroduces one fails
`ProformaOutputTest`. Dotted keys are mandatory here: the rest of the blade tree calls `__()` with English natural
keys and there is no `lang/*.json`, so only dotted keys reach these PHP arrays and actually translate.

## 8. Guards

| gate | result |
|---|---|
| `./vendor/bin/pint` on all touched paths | `{"result":"pass"}` (2 files auto-fixed and committed) |
| `./vendor/bin/phpstan analyse` on the 2 app files + 3 new test/trait files (level 8) | `[OK] No errors` |
| `php tools/deptrac-ratchet.php` | `RESULT: PASS — no boundary regression against baseline` (183/183, every category *held*) |
| `php tools/feature-lane-manifest-check.php` | `OK — 1420 Feature classes in 74 groups` |

**Manifest raised deliberately**: `groups.Document.classes` **84 → 86**, `gated_ceiling` **1173 → 1175**, with the
raise recorded in the group note. Base dev (`0ae906b0e`) sat exactly at both ceilings, so the raise is +2 for the
two new classes and nothing else. The new dev tip `3bbe28480` still carries 84 / 1173, so the arithmetic holds —
**re-derive at merge if dev moves again**. Neither class is named in any live `--filter` allowlist, so both execute
nowhere until the `feature-lane-documents` gate is flipped; they were run by path on sqlite **and** PG here.

## 9. Files changed (`git diff --stat 0ae906b0e`)

Production (11):
```
app/Modules/Document/Application/Services/ProformaOutputPolicy.php   (new, 66)
app/Modules/Document/Application/Services/DocumentPdfService.php     (+17/-2)
lang/{en,fr,ar}/documents.php
resources/views/documents/templates/{invoice,credit_note}.blade.php
resources/views/documents/components/{posting_marker,line_items,totals,parties}.blade.php
resources/views/documents/layouts/document.blade.php
```
Tests + fixtures (8): `ProformaOutputTest.php` (new), `ProformaTemplateCensusTest.php` (new),
`tests/Traits/ExtractsPdfText.php` (new), 4 snapshot fixtures (new), `PostingMarkerPrintTest.php`,
`DocumentPdfSellerTaxIdTest.php`, `tests/feature-lane-manifest.json`.

Nothing outside the brief's scope was touched. No migration, no route, no controller, no frontend file.

## 10. Residuals — seen, NOT touched

*(section continued below)*
### R-1 [BIGGEST] — the WEB UI still renders the full VAT breakdown for a confirmed-unposted invoice

Explicitly out of scope per the brief ("web-side React rendering of totals is OUT of scope; report it"), and the
single largest hole left after this lane. Censused first-hand:

| file:line | what it renders | server- or client-rendered | posted-ness branch? |
|---|---|---|---|
| `apps/web/src/features/documents/components/DocumentTotals.tsx:102-140` | `Subtotal`, then **one row per tax** — `{tax_name} {tax_rate}%` and `{tax_amount}` — from the `taxBreakdown` payload, then the total | **client** — the FE builds this from API JSON, the blade tree is not involved | **none** |
| `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx` | consumes `DocumentTotals` | client | **none** — and unlike the credit note it has **no** N-6 marker either |
| `apps/web/src/features/documents/credit-notes/CreditNoteDetailPage.tsx` | consumes `DocumentTotals` | client | none on the totals |
| `apps/web/src/features/documents/components/CreditNoteDetail.tsx:92-98` | N-6's marker, `t('sales:creditNotes.postingMarker.{title,detail,cancelledTitle,cancelledDetail}')` | client | **yes** — the only FE surface with one |
| `apps/web/src/locales/{en,fr,ar}/sales.json` (`en` at `:872-874`) | the FE copy of that marker | — | — |
| `apps/web/src/features/documents/hooks/useDocumentPdf.ts:9,16,25,64,97` | fetches `/api/v1/documents/{id}/pdf` and `/pdf/preview` as a blob for download/preview | **server** — inherits this lane's gate, no FE change needed | n/a (backend decides) |

Two consequences a follow-up lane owns:

1. **A user can read the VAT of an unposted invoice on screen** (`DocumentTotals`), and screenshot or transcribe it,
   while the PDF says the document is a non-fiscal estimate. The invariant holds on the artefact that leaves the
   building; it does not hold in the product.
2. **The FE marker copy is now stale.** `sales.json:873-874` still reads *"Not yet posted — no fiscal seal"* /
   *"has not been posted to the accounts … no hash-chain entry"* — the exact strings this lane deleted from
   `lang/*/documents.php` and replaced with the proforma wording. The two surfaces now describe the same document
   differently. Aligning them is a `frontend-conventions-reviewer` matter and needs FE i18n keys in all three
   locales, which is why it was not done under a backend-scoped brief.

### R-2 — the email COVERING NOTE still calls a proforma an "Invoice", and labels the gross figure "Total"

`apps/api/app/Modules/Communication/Application/Mail/DocumentMail.php:104-109` builds the subject
`"{Invoice|Facture} {number} from {company}"`, and
`apps/api/resources/views/emails/documents/document.blade.php:79,82,85` (+ the `fr/` sibling, same lines) render
"Please find attached your invoice", an `<h2>` of the document title, and
`Total: {{ number_format((float) $total, 2) }} {{ $currency }}`.

The **attachment** is now a correct proforma. The **email around it** is not: it announces an invoice and states a
`Total`. No token F-95 forbids appears (no VAT/TVA/tax), so this lane's invariant holds on the attachment, and the
brief scopes the lane to "templates + print/PDF controllers/services + lang + tests". Flagging rather than fixing:
the covering note needs the same title/label treatment, and it is a Communication-module surface with its own
locale resolution (`$this->company->locale`, unlike the PDF — see R-3). **Also note** `number_format((float) $total, 2)`
on line 85 of both bodies is a rule-19 float-on-money violation that predates this lane.

### R-3 — the proforma banner follows the REQUEST locale, not the company locale

`app/Http/Middleware/SetLocale.php:33-35` sets the app locale from the `X-Language` / `Accept-Language` header, and
it is the only `setLocale` call in `app/` (`bootstrap/app.php:145`, api group). `DocumentPdfService::prepareData()`
resolves `$locale = $company->locale ?? 'en'` and uses it for **money, dates and the document title** only —
`__('documents.proforma.*')` in the blades resolves against the *app* locale. So a French tenant whose client sends
`Accept-Language: en` gets `Facture`, `238,000 DT` and French date formatting alongside an **English** proforma
banner. This predates the lane (N-6's marker had the identical split) and is why `ProformaOutputTest` drives the
locale with `$this->app->setLocale()` rather than through the company. Not fixed here: making `__()` follow
`company->locale` inside the PDF stack changes every string on every document type, which is its own lane.

### R-4 — Arabic PDFs are still unshaped (N-6 residual R-4, unchanged)

The bundled DejaVu subset does not shape Arabic, so `documents.proforma.*` in `ar` is correct in the HTML and may
render unjoined in a generated PDF. That is the Arabic-PDF font lane's problem, and it is not a reason to leave an
Arabic tenant reading English — so the `ar` strings ship, the `ar` **HTML** is asserted to contain them, and the
`ar` **PDF text** is asserted to contain none of the forbidden tokens (the invariant that matters holds regardless
of shaping). Only the presence assertions on the PDF are restricted to en/fr.

### R-5 — `posting_marker.blade.php` is included by exactly two templates, by hand

`templates/invoice.blade.php:18` and `templates/credit_note.blade.php:18` each `@include` it. A future fiscal
template that forgets the include loses the banner silently. `ProformaTemplateCensusTest` catches the *tax mention*
half of that (a new template emitting a tax token without consulting `$isProforma` fails), and
`test_only_invoice_and_credit_note_are_fiscal_document_types` fails the moment a third fiscal type is declared —
but nothing yet asserts "every fiscal template includes the banner component". Worth a one-line addition if a third
fiscal type ever lands.

### R-6 — `DocumentPdfService::formatMoney()` casts money to float

`app/Modules/Document/Application/Services/DocumentPdfService.php:255-266`: `$amount = is_string($amount) ? (float) $amount : $amount;`
before `NumberFormatter::formatCurrency()`. Every amount on every document PDF — including the new
`Estimated total` — passes through it. Pre-existing, untouched (the brief says "you are only rendering, do not
reformat amounts"), and the reason this lane changed **which** figure is printed and never **how** it is printed.

### R-7 — `tests/Feature/Document/PostingMarkerPrintTest.php:196` has a pre-existing PHPStan `return.type`

`return $document->fresh();` returns `Document|null` against a `Document` return type. Inherited from N-6, in a
method this lane did not touch, and out of CI's PHPStan scope (`phpstan.neon` analyses `app/` only). Left alone
rather than drive-by fixed; the equivalent pattern in the two new test classes was written correctly.

---

## 11. For the gates

**fiscal-pos-reviewer** — the two decisions most worth attacking:

1. **The predicate is the seal.** A `Paid`-but-never-sealed invoice now prints without VAT (§3, §6). That is the
   fail-safe reading of OQ-14 and it matches the document set N-6 already marked, but it means a document a user
   believes is posted loses its VAT on the printout. The alternative — gating on `status IN (Draft, Confirmed)` —
   would leave those documents printing VAT they have no ledger entry for. Ruling wanted.
2. **The Factur-X gate** (§2.3) is a scope judgement: the brief lists "print/PDF controllers/services", and
   `generateContent()` is the PDF service, but the change alters what an FR B2B customer receives. If the gate is
   unwanted, revert `DocumentPdfService.php:79` — nothing else depends on it.

**frontend-conventions-reviewer** — this lane touched no `apps/web` file. What it owes review on is the
**blade/i18n** side: dotted keys under the existing `documents` namespace, all three locales, no hardcoded strings
in the templates, and the deletion of two now-dead keys. R-1 above is the FE follow-up brief in miniature.
