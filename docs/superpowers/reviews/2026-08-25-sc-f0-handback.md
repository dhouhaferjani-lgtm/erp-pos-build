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

---

# Fix round r1 — response to `2026-08-25-sc-f0-gate-r1-fiscal.md`

Gate r1: **spec ✅ + quality ACCEPT-WITH-CONDITIONS, merge-blocking on condition 1.** Same worktree, same
branch, LANE-PROTOCOL unchanged (red-first by path, sqlite + PG throwaway `autoerp_test_scf0`, dropped after;
no stash, no push, not merged). Base for this round is the r1 tip `2974a6e40`.

Conditions 4 (FE follow-up) and 6 (OQ-14 owner ruling) are orchestrator-owned and were not touched — no
`apps/web` file is in this round's diff (`git diff --name-only | grep apps/web` empty).

| condition | finding | status |
|---|---|---|
| 1 [BLOCKING] | F-1 status badge | **CLOSED** — badge gated + 4-status matrix |
| 2 | F-3 CI allowlist | **CLOSED** — both classes named at `ci.yml:983` |
| 3 | F-2 net lines under a gross total | **CLOSED** — implemented per the orchestrator's r11.2 ruling |
| 5 | F-4 census heuristics · F-5 wrong Blade rationale | **CLOSED** |
| 4, 6 | FE lane · OQ-14 | orchestrator-owned, untouched |

## Item 1 — F-1 [BLOCKING]: a proforma states no lifecycle status

**Change.** `apps/api/resources/views/documents/components/header.blade.php:50` —
`@if($document->status)` → `@if($document->status && ! ($isProforma ?? false))`. The whole badge goes, not
just the word: a lifecycle badge has nothing true to say about a document whose own banner states it has not
been entered in the accounts. It returns unchanged the moment the document is sealed.

The gate's probe was reproduced exactly: `Posted`-never-sealed rendered `class="status-badge status-posted"`
and the badge is upper-cased into the PDF by `.status-badge { text-transform: uppercase }`, so the page drew
`POSTED` — token 9 of the lane's own `FORBIDDEN_TOKENS`. `Paid`-never-sealed drew `PAID` on a page from which
this lane had already deleted the Paid and Balance Due rows.

**Matrix extended** — `ProformaOutputTest::unsealedStatusProvider()` now drives all four statuses the
predicate admits (`draft`, `confirmed`, `paid, never sealed`, `posted, never sealed`) through
`test_no_unsealed_status_puts_a_lifecycle_word_on_the_proforma`: full token scan on HTML **and** PDF text,
absence of the `status-badge` markup, and absence of **every** `DocumentStatus` word from the PDF's drawn
lines. That last check is exact-line membership, not substring: `Paid` and `Draft` are not forbidden tokens,
so nothing else would have caught them.

**RED, on `2974a6e40` (before any production edit this round):**
```
1) …test_no_unsealed_status_puts_a_lifecycle_word_on_the_proforma with data set "draft"
2) …with data set "confirmed"
3) …with data set "paid, never sealed"
4) …with data set "posted, never sealed"
   posted invoice HTML must not contain /posted/i — found: posted, Posted
   Failed asserting that 2 is identical to 0.
Tests: 24, Assertions: 246, Failures: 6.
```

## Item 3 — F-2: line figures are now GROSS (orchestrator SPEC ruling r11.2, owner OQ-75 default)

**Ruling implemented as given**: a proforma shows tax-inclusive unit price and line total — the same figures
the estimated total sums to — so the page reconciles on its own face and no tax amount is recoverable by
subtraction. No `HT` / `TTC` / `incl.` / `excl.` wording anywhere (the r1 comment in `totals.blade.php` that
used the word `TTC` was reworded; it was a Blade comment and never reached the page, but the census now scans
for the literal).

| file:line | change |
|---|---|
| `app/Modules/Document/Application/Services/ProformaGrossAmountResolver.php` | **new**, 110 lines — owns the arithmetic |
| `app/Modules/Document/Application/Services/DocumentPdfService.php:32` | resolver injected |
| `app/Modules/Document/Application/Services/DocumentPdfService.php:204-205` | `proformaUnitPrice` / `proformaLineAmount` closures in the view data (typed on `DocumentLine`) |
| `resources/views/documents/components/line_items.blade.php:19` | `$grossRow = ($isProforma ?? false) && isset($proformaUnitPrice, $proformaLineAmount)` |
| `resources/views/documents/components/line_items.blade.php:64` | unit price → `$grossRow ? $proformaUnitPrice($line) : $line->unit_price` |
| `resources/views/documents/components/line_items.blade.php:68` | line amount → `$grossRow ? $proformaLineAmount($line) : $line->line_total` |
| `resources/views/documents/components/totals.blade.php:31-53` | the proforma arm's rationale rewritten to the new rule (the row itself is unchanged: the stored `documents.total`) |

**How a line's tax is known**, in order: (1) the persisted, authoritative `document_lines.tax_amount` the tax
engine wrote (already carrying its share of any document-level discount); (2) `tax_rate` × the net line value,
for rows where that column is NULL — every pre-tax-engine row; (3) neither ⇒ gross == net, because inventing
tax for an untaxed line would print a figure no ledger will agree with. The **unit** price is always derived
from the RATE, never by dividing `tax_amount` by the quantity: the persisted line tax already absorbs the line
discount, so the quotient would be a unit price the customer cannot multiply back. `unit_price × qty == line
amount` is not an identity on a discounted line today either, and this resolver does not pretend otherwise.

**Rule 19**: bcmath on strings throughout, no float anywhere; intermediates at `scale + 1`, rounded **once** at
the boundary with `CurrencyScale::bcround()` (half-up) — never `bcformat()`, which truncates and would drift
the sum off the estimated total. Scale from the **document's** currency via `getScaleSafe($currency, 3)`, not
a bare no-arg `getScale()`, because this also runs under `DocumentEmailService::queue()` where there is no
CompanyContext. The one literal scale (`bccomp($rate, '0', 2)`) carries a `// precision-ok:` marker —
`tax_rate` is a percentage column, which rule 19 exempts from currency scaling.

**Assertions added** (both on a deliberately TWO-line fixture with different amounts, one line resolving its
tax from the persisted `tax_amount` and one from `tax_rate`, so the sum is a real sum over both paths and not
an identity on a single row):
- `test_proforma_line_figures_are_gross_and_sum_to_the_estimated_total` — reads the items table's right-hand
  cells off the rendered page and asserts them equal `[119,000 · 238,000 · 59,500 · 59,500]`, then that
  `238.000 + 59.500 == documents.total == 297.500`, then that the estimated total prints that figure.
- `test_no_figure_on_a_proforma_yields_the_vat_by_subtraction` — the totals box prints exactly one figure, and
  neither the HTML nor the PDF prints the VAT (`47.500`), the net subtotal (`250.000` — estimated total minus
  this **is** the VAT), either net line amount, the net unit price, or either line's VAT.
  Membership is **exact-string, never substring**: `238,000 DT` contains `38,000 DT`, and a substring search
  would have reported line 1's VAT as printed when it is not. That false positive was caught during this
  round and is why both checks compare against the extracted cell/line lists.

## Item 2 — F-3: the guards now execute on a real CI event

`.github/workflows/ci.yml:983` — `ProformaOutputTest` and `ProformaTemplateCensusTest` appended to the
`backend-test-pgsql` `--filter` allowlist, append-only, at the end of the existing alternation, per the
precedent the manifest note already records for `CorrectingEntryEndpointTest`, `SupplierGoodsReturnNoteTest`
and `PurchaseOrderUnpricedLineConfirmTest`. `tests/feature-lane-manifest.json` (Document group note) records
the pair, the reason, and **remove-on-gate-flip**. `feature-lane-manifest-check.php` re-run:
`OK — 1420 Feature classes in 74 groups; … every --filter entry is anchored and uniquely matched against 1815
test classes` — so both new entries resolve to exactly one class each (the checker's condition C/D).

> ⚠️ **This round touches `.github/workflows/ci.yml`.** The S-14 dispatch leg is therefore owed at promotion:
> the pgsql lane's filter changed, so the first PR carrying this branch runs two additional classes on the
> shared `backend-test-pgsql` job. Ceilings are unaffected (the classes were already counted in the parked
> `Document` group; the allowlist is orthogonal to `gated_ceiling`).

## Item 5 — F-4 (census heuristics) and F-5 (wrong Blade rationale)

**F-4a — emission regex widened.** `ProformaTemplateCensusTest::TAX_EMISSION` was
`__\('(Tax|VAT|Tax ID)'\)|tax_amount|tax_rate|tax_id|vat_number|showTax`; it now also matches `__('TVA')`,
`__('TTC')`, `__('HT')`, bare `\bTTC\b` / `\bHT\b` literals, `stamp_duty_amount` (the TN timbre — a
document-level tax living outside every per-line field the r1 regex named), `line_tax_amount`,
`document_tax_details`, `taxBreakdown` and `tax_details`. `\bHT\b` is anchored and case-sensitive for the same
reason it is in the token scan: unanchored, it matches `height` and `right` in every style attribute in the
tree.

**F-4b — the gate check is no longer satisfiable by a comment.** r1 asked
`str_contains($contents, 'isProforma')`, which the docblock *explaining* the gate satisfies just as well as
the gate does — the ordinary shape of a bad merge (gate deleted, comment survives) passed. The new
`consultsTheFlagInCode()` strips Blade comments, `/* */` and `//` comments, then looks for the flag only
inside constructs Blade evaluates: an `@php … @endphp` block, a parenthesised `@if` / `@elseif` / `@unless` /
`@include` / `@php(…)` directive, an echo, or a multi-line `@include` array. Both the group check and the
country-template check use it. Directives are recognised at end of line — how every one in this tree is
written — so a mid-line directive reads as UNGATED: the census **fails closed**, never open, and
`test_a_comment_does_not_satisfy_the_gate_check` pins both directions.

**RED for F-4b**, captured by temporarily restoring the r1 one-liner (tree restored immediately after; the
class is 7/7 green now):
```
1) …ProformaTemplateCensusTest::test_a_comment_does_not_satisfy_the_gate_check
Failed asserting that true is false.
Tests: 1, Assertions: 1, Failures: 1.
```

**F-5 — the rationale was wrong, and the reviewer is right.** Verified first-hand, not taken on trust:
`vendor/laravel/framework/src/Illuminate/View/Compilers/Concerns/CompilesLayouts.php:24` compiles `@extends`
into a **trailing** `$__env->make($layout, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))`
that runs after the section body **in the same function scope** — so a `@php` reassignment inside
`@section('content')` does reach the layout. `layouts/document.blade.php:347-364` now states the real reason
for the `?? false` / `?? true` asymmetry: this layout wraps all eight templates, six of which never resolve the
flag, so a fail-safe default *here* would strip the seller identifier from a directly-rendered purchase order
or quote. The two fiscal templates own the fail-safe because they are the only ones the rule applies to. The
code is unchanged; only the explanation was wrong.

## Fix round r1 — verification

Red-first, by path, one process at a time; full suite never run.

| test file | RED (at `2974a6e40`, pre-fix) | GREEN sqlite | GREEN PG |
|---|---|---|---|
| `tests/Feature/Document/ProformaOutputTest.php` | `Tests: 24, Assertions: 246, Failures: 6` | `OK (24 tests, 296 assertions)` | `OK (24 tests, 296 assertions)` |
| `tests/Feature/Document/ProformaTemplateCensusTest.php` | `Tests: 1, Assertions: 1, Failures: 1` (F-4b probe) | `OK (7 tests, 24 assertions)` | `OK (7 tests, 24 assertions)` |
| `tests/Feature/Document/PostingMarkerPrintTest.php` | — | `OK (10 tests, 20 assertions)` | `OK (10 tests, 20 assertions)` |
| `tests/Feature/Modules/Document/DocumentPdfSellerTaxIdTest.php` | — | `OK (3 tests, 5 assertions)` | `OK (3 tests, 5 assertions)` |
| `tests/Feature/Modules/Document/DocumentPdfRenderTest.php` | — | `OK (6 tests, 20 assertions)` | `OK (6 tests, 20 assertions)` |
| `tests/Feature/Compliance/FacturXWorkOrderInvoiceTest.php` | — | `OK (2 tests, 7 assertions)` | — |
| `tests/Feature/Document/FacturXBranchSellerTest.php` | — | `OK (2 tests, 7 assertions)` | — |
| `tests/Feature/Modules/Document/FacturXDescriptionTest.php` | — | `OK (3 tests, 7 assertions)` | — |

PG leg: throwaway `autoerp_test_scf0` on `127.0.0.1:5433`, created and **dropped** at the end of the round.

### Posted-pin proof — still green, and still not vacuous

`test_a_posted_invoice_renders_identically_to_the_pre_change_snapshot` and its credit-note sibling are inside
the `OK (24 tests, 296 assertions)` above, on sqlite **and** PG. They compare against the fixtures captured on
`0ae906b0e` in the RED commit `394aecc0d` — unchanged this round (`git diff 394aecc0d -- tests/Fixtures/proforma`
is empty). Both of this round's production changes are inside the proforma branch by construction:

- the badge is gated on `! $isProforma`, and a posted invoice has `isProforma === false`;
- the gross figures are reached only through `$grossRow`, which is `false` for a posted document, so
  `line_items` evaluates `$line->unit_price` / `$line->line_total` exactly as before — the closures are never
  even called.

The stylesheet is untouched this round as well, so the strict `<style>`-block comparison inside the pins still
holds. The gate's own tamper-probe (Leg 4) showed those pins catch a value change and a CSS change, so their
staying green is evidence, not silence.

### Guards, re-run after the fix round

| gate | result |
|---|---|
| `./vendor/bin/pint` on all touched paths | `{"result":"pass"}` |
| `./vendor/bin/phpstan analyse` (level 8) on the 3 app files + 2 test files | `[OK] No errors` |
| `php tools/deptrac-ratchet.php` | `TOTAL 183 183` · `RESULT: PASS — no boundary regression` |
| `php tools/feature-lane-manifest-check.php` | `OK — 1420 Feature classes in 74 groups` |

Ceilings are unchanged this round: `Document` **86**, `gated_ceiling` **1175** (no new test class — both new
tests are methods on the two existing classes).

### Files changed in fix round r1 (`git diff 2974a6e40`)

```
.github/workflows/ci.yml                                                   (F-3, +2 filter entries)
apps/api/app/Modules/Document/Application/Services/ProformaGrossAmountResolver.php   (new)
apps/api/app/Modules/Document/Application/Services/DocumentPdfService.php
apps/api/resources/views/documents/components/header.blade.php             (F-1)
apps/api/resources/views/documents/components/line_items.blade.php         (F-2)
apps/api/resources/views/documents/components/totals.blade.php             (F-2, comment)
apps/api/resources/views/documents/layouts/document.blade.php              (F-5, comment)
apps/api/tests/Feature/Document/ProformaOutputTest.php                     (F-1, F-2)
apps/api/tests/Feature/Document/ProformaTemplateCensusTest.php             (F-4)
apps/api/tests/feature-lane-manifest.json                                  (F-3 note)
```

Still **migration: NONE**. No route, no controller, no `apps/web` / `apps/pos` file.

## New residual from this round

### R-8 — a document-level charge or discount breaks the line/total reconciliation (not the VAT property)

`ProformaGrossAmountResolver` never recomputes `documents.total`; the estimated total stays the stored figure.
Σ gross lines is `subtotal + Σ line tax`, while
`total = subtotal − discount_amount + line_tax_amount + stamp_duty_amount`. So the lines sum exactly to the
estimated total **iff** `discount_amount` and `stamp_duty_amount` are both zero — the case the r11.2 ruling
describes and the case the new tests pin.

When a document carries the **TN timbre** (`stamp_duty_amount`, written by
`TaxCalculationService::calculateDocumentTaxes()` → `DocumentTotalsCalculator:53`) or a document-level
discount, the residual a reader can compute is `stamp_duty − discount`. That residual is **not** the VAT, so
the security property F-2 exists to buy still holds — but the page no longer adds up on its face, which is the
cosmetic half of the ruling. Options for the orchestrator, none of them free:
(a) print the timbre as its own row — reconciles, but puts a named tax back on the page;
(b) spread the residual across the lines — reconciles and names nothing, but distorts per-unit prices the
customer will compare against the final invoice;
(c) accept it — the estimated total remains authoritative and the difference is a fixed statutory stamp.
Recorded rather than chosen: it is a §2.4 question of the same kind as F-2, and choosing (b) silently would
have been the worst of the three. The behaviour is documented at `totals.blade.php:31-53` and in the
resolver's docblock so the next reader meets it before a tenant does.

---

# Fix round r2 — response to `2026-08-25-sc-f0-gate-r2-fiscal.md`

Gate r2: **spec ✅ + quality ACCEPT-WITH-CONDITIONS, merge-blocking on conditions 1 (F-7) and 2 (F-8).** Same
worktree, same branch, LANE-PROTOCOL unchanged (red-first by path, sqlite + PG throwaway `autoerp_test_scf0`,
dropped after; no stash, no push, not merged). Base for this round is the r1 tip `1cd09ee9c`.

| condition | finding | status |
|---|---|---|
| 1 [BLOCKING] | F-7 — two bases, rows contradict themselves under a document discount | **CLOSED** |
| 2 [BLOCKING] | F-8 — the rate-derivation invariant unpinned | **CLOSED** (superseded and re-pinned) |
| 3 | F-10 — unreproduced PG error, ≥5 runs each | **CLOSED with a disclosure** — see R-9 |
| 4 | R-8 ruling — derived reconciling row, duty words, 3 locales | **CLOSED** |
| 5 | F-9 — wrong `getScaleSafe()` rationale | **CLOSED** |
| 6 | FE lane (R-1/R-2), OQ-14 owner ruling | orchestrator-owned, untouched |

## Item 1 — F-7 [BLOCKING]: one basis, so every row closes on its own face

r1 fixed the page and broke the row. `unitPrice()` was rate-derived while `lineAmount()` took the persisted,
post-document-discount `tax_amount`. Those two bases **cannot** agree once a document-level discount exists:
the tax engine prorates that discount into every rate bucket before taxing it
(`TaxCalculationService:171-183`) and a rate-derived unit price knows nothing about it. The gate's probe
printed `Qty 1,00 · Unit Price 59,500 DT · Amount 59,120 DT` — the customer-facing document contradicting
itself on one line, which is r1's own F-2 defect one level down. The population is production data:
`POSAccountChargeDraftService:62,164` writes that document-level discount.

**Change** — `app/Modules/Document/Application/Services/ProformaGrossAmountResolver.php:114-134`:

```php
return CurrencyScale::bcround(bcdiv($this->lineAmount($line, $currency), $quantity, $scale + 4), $scale);
```

The gross line amount is still built from the persisted post-discount facts; the unit price is now that
**amount** divided by the quantity. This keeps the rule that actually mattered — the persisted `tax_amount` is
never divided on its own, because what is divided is a whole-line gross that already absorbs every discount —
and it makes `unit × qty == amount` an identity the customer can check with a calculator. A zero quantity
short-circuits to the line amount rather than dividing by zero (`:122`, guarded with a
`precision-ok:` pragma — `quantity` is `decimal(N,4)`, not currency).

**Pinned** by `test_every_proforma_row_closes_on_its_own_face` over `discountedUnpostedInvoice()`, which
carries `discount_amount = 10.000`, `stamp_duty_amount = 1.000`, per-line persisted post-discount taxes, and a
**line-level** discount on row 3:

| row | qty | printed unit | printed amount | `unit × qty` |
|---|---|---|---|---|
| 1 — persisted tax ≠ rate × net (36.480 vs 38.000) | 2 | 118.240 | 236.480 | 236.480 ✓ |
| 2 | 1 | 59.120 | 59.120 | 59.120 ✓ |
| 3 — line-level discount, `line_total ≠ unit_price × qty` | 4 | 26.604 | 106.416 | 106.416 ✓ |

The assertion is exact at currency scale, with no tolerance: if it ever needs one, the two figures have
stopped coming from one basis.

## Item 2 — F-8 [BLOCKING]: the derivation invariant is now falsifiable

r1's docblock called "never from `tax_amount`" load-bearing and no test held it — the gate rewrote
`unitPrice()` to the forbidden form and all 24 tests passed, because line 1 had a NULL `tax_amount` (forcing
the rate path) and line 2 had `quantity = 1` (where every derivation coincides). The invariant itself changed
in item 1, so what is pinned now is the *single-basis* property, and the same fixture falsifies **every**
competing derivation. Four tamper probes, each applied to the resolver, run, and reverted (tree verified
clean, 32/32 green after each):

| tamper | result |
|---|---|
| A · `unitPrice()` rate-derived (the exact r1 code) | `Tests: 32, Failures: 1` — `test_every_proforma_row_closes_on_its_own_face` |
| B · `unitPrice()` = `unit_price + tax_amount ÷ qty` (the gate's F-8 probe) | `Tests: 32, Failures: 3` |
| C · `unitPrice()` = `tax_amount ÷ qty` | `Tests: 32, Failures: 4` |
| D · reconciling row assembled as `stamp − discount` instead of derived | `Tests: 32, Failures: 3` |

Row 1 (qty 2 **and** persisted `tax_amount` ≠ `rate × line_total`) kills A; row 3 (line-level discount, so
`line_total ≠ unit_price × qty`) kills B and C. One fixture, every forbidden derivation red.

## Item 4 — R-8 implemented per the gate's §3 ruling

The totals box now explains the residual instead of leaving the page not adding up.

| file:line | change |
|---|---|
| `app/Modules/Document/Application/DTOs/ProformaTotals.php` | **new** — `grossLines`, `stampDuty`, `discount`, `surcharge`; every field a display decision already made, `null` = print no row |
| `…/Services/ProformaGrossAmountResolver.php:140-163` | `totals()` — Σ gross lines, the stored stamp, and the **derived** residual |
| `…/Services/DocumentPdfService.php:212` | `'proformaTotals' => $isProforma ? … : null` |
| `resources/views/documents/components/totals.blade.php:60-76` | stamp-duty row, then discount **or** adjustment row, above the estimated total |
| `resources/views/documents/templates/credit_note.blade.php:70-86` | the same rows in the credit note's own totals block |
| `lang/{en,fr,ar}/documents.php` | `documents.proforma.{stamp_duty,discount,adjustment}` |

**Derived, not assembled** (the ruling's first and most important condition):

```php
$residual = bcsub($total, bcadd($grossLines, $stamp, $scale), $scale);   // :155
```

and it is **pinned by a fixture on which the two differ**, which is the only way the requirement is real:
`legacyTaxRowUnpostedInvoice()` has one line with a NULL `document_lines.tax_amount` — every row written
before the tax engine existed — under a document-level discount. The NULL fallback taxes the **pre**-discount
net (19% of 200.000 = 38.000) while the document's `line_tax_amount` was taken on the discounted base (19% of
190.000 = 36.100):

```
Σ printed gross = 238.000 ; stored total = 226.100
derived   : 238.000 − 226.100 = 11.900  → the page closes
assembled : stamp − discount  = 10.000  → 238.000 − 10.000 = 228.000, a silent 1.900 remainder
```

`test_the_reconciling_row_is_derived_and_not_assembled_from_the_stored_discount` asserts the printed row is
`-11.900`. Without it the "compute, don't assemble" requirement would have been unpinned exactly the way F-8's
was, which is the mistake this round exists to not repeat.

**Duty words, never tax words** — en `Stamp duty` / `Discount`, fr `Droit de timbre` / `Remise`, ar
`معلوم الطابع` / `تخفيض`. The Arabic is a deliberate human choice, as the gate required: the token scan is
Latin-script and cannot catch an Arabic tax word, and `معلوم الطابع` is the Tunisian term for the stamp duty
**without** the adjective `جبائي` (fiscal) — a named duty, not a VAT mention. The rationale is written into
`lang/ar/documents.php` so the next translator meets it. A `documents.proforma.adjustment` key
(`Adjustment` / `Ajustement` / `تعديل`) covers a residual that runs the other way; calling an increase a
discount would be a lie, and `test_a_residual_that_runs_the_other_way_is_not_called_a_discount` pins it.

**Token scan and no-VAT-derivable extended over the `stamp > 0 ∧ discount > 0` fixture**, in all three
locales: `test_a_proforma_with_a_stamp_duty_and_a_discount_stays_clean` (en/fr/ar, HTML **and** PDF text) and
`test_the_duty_and_discount_rows_do_not_reveal_the_vat`, which asserts that none of the VAT total (62.016),
net subtotal (340.000), discounted net (330.000), any line net, any net unit price or any line VAT appears —
by **exact membership**, because `236,480 DT` contains `36,480 DT`.

Reconciliation on that fixture: `402.016 (Σ gross lines) + 1.000 (stamp) − 10.000 (discount) = 393.016`
(the estimated total), asserted arithmetically in the test as well as read off the page.

## Item 5 — F-9: the `getScaleSafe()` rationale was wrong

`ProformaGrossAmountResolver.php:36-53`. r1 justified `getScaleSafe()` with "this runs inside
`DocumentEmailService::queue()` too, where there is no CompanyContext". Re-read `DocumentEmailService:48,106`:
both `send()` and `queue()` call `generateContent()` **in-request**, before the mailable reaches the mailer, so
the resolver never executes in a worker. The choice stands and the comment now says why on its own merits:
passing the entity currency is what rule 19 asks for whenever the entity is in hand, it is correct for a
document denominated in something other than the company currency (`DocumentPdfService:173`), and it avoids
silently acquiring a dependency on request state that a future queued caller would break.

## Item 3 — F-10: repeat PG runs, and a disclosure

Requested ≥5 runs of each class on PostgreSQL. Delivered, on throwaway `autoerp_test_scf0` (127.0.0.1:5433),
dropped at the end:

```
ProformaOutputTest        PG run 1: OK (32 tests, 395 assertions)     [freshly created database]
ProformaOutputTest        PG run 2: OK (32 tests, 395 assertions)
ProformaOutputTest        PG run 3: OK (32 tests, 395 assertions)
ProformaOutputTest        PG run 4: OK (32 tests, 395 assertions)
ProformaOutputTest        PG run 5: OK (32 tests, 395 assertions)
ProformaOutputTest        PG run 6: OK (32 tests, 395 assertions)

ProformaTemplateCensusTest PG run 1: OK (7 tests, 24 assertions)
ProformaTemplateCensusTest PG run 2: OK (7 tests, 24 assertions)
ProformaTemplateCensusTest PG run 3: OK (7 tests, 24 assertions)
ProformaTemplateCensusTest PG run 4: OK (7 tests, 24 assertions)
ProformaTemplateCensusTest PG run 5: OK (7 tests, 24 assertions)
ProformaTemplateCensusTest PG run 6: OK (7 tests, 24 assertions)
ProformaTemplateCensusTest PG run on a FRESH database 1: OK (7 tests, 24 assertions)
ProformaTemplateCensusTest PG run on a FRESH database 2: OK (7 tests, 24 assertions)
```

The two fresh-database runs reproduce the condition the gate's single failure occurred under (the class that
runs the migrations). **6/6 and 8/8 green.** Neither lane class flaked once.

**Disclosure — the intermittent recurred, on a different class.** During this round's PG sweep,
`tests/Feature/Modules/Document/DocumentPdfRenderTest.php` returned `Tests: 6, Assertions: 2, Errors: 5` on
one run. That class is **not owned by this lane**, was green on PG in both the r1 and r2 gates, and is
untouched by every round. I could not reproduce it: 8 further runs (fresh database ×2, the exact preceding
class sequence replayed once, then 4 consecutive runs against the dirty database) were all
`OK (6 tests, 20 assertions)`. **I did not capture the message** — the sweep grepped only the summary line —
and I will not reconstruct it from memory. Recorded as **R-9**.

What this changes about F-10's question: the gate asked whether the two classes are safe to put in the live
`backend-test-pgsql` job. The evidence says the intermittency is not a property of them — it has now been seen
on two different classes (`ProformaTemplateCensusTest` once for the gate, `DocumentPdfRenderTest` once here),
neither reproducible, while the lane's own classes are 14/14 green across this round. That points at the local
PG environment rather than at either class. It is not proof, which is why it is a residual and not a closure.

## Fix round r2 — verification

| test file | RED (at `1cd09ee9c`, pre-fix) | GREEN sqlite | GREEN PG |
|---|---|---|---|
| `tests/Feature/Document/ProformaOutputTest.php` | `Tests: 31, Assertions: 387, Failures: 6` | `OK (32 tests, 395 assertions)` | `OK (32 tests, 395 assertions)` ×6 |
| `tests/Feature/Document/ProformaTemplateCensusTest.php` | — (unchanged this round) | `OK (7 tests, 24 assertions)` | `OK (7 tests, 24 assertions)` ×8 |
| `tests/Feature/Document/PostingMarkerPrintTest.php` | — | `OK (10 tests, 20 assertions)` | `OK (10 tests, 20 assertions)` |
| `tests/Feature/Modules/Document/DocumentPdfSellerTaxIdTest.php` | — | `OK (3 tests, 5 assertions)` | `OK (3 tests, 5 assertions)` |
| `tests/Feature/Modules/Document/DocumentPdfRenderTest.php` | — | `OK (6 tests, 20 assertions)` | `OK (6 tests, 20 assertions)` — see R-9 |
| `tests/Feature/Compliance/FacturXWorkOrderInvoiceTest.php` | — | `OK (2 tests, 7 assertions)` | — |
| `tests/Feature/Document/FacturXBranchSellerTest.php` | — | `OK (2 tests, 7 assertions)` | — |
| `tests/Feature/Modules/Document/FacturXDescriptionTest.php` | — | `OK (3 tests, 7 assertions)` | — |
| `tests/Unit/Document/FacturXServiceTest.php` | — | `OK (12 tests, 19 assertions)` | — |

The six red were: the row-closure test, the totals-box reconciliation, the three-locale token scan over the
new fixture, and the other-sign adjustment test. The derived-not-assembled test was added after the
implementation and is proven non-vacuous by tamper D above rather than by a red run — stated plainly rather
than presented as red-first.

### Posted pins — still byte-identical

`--filter 'snapshot|still_shows_the_tax_breakdown'` → `OK (3 tests, 10 assertions)`, on sqlite and inside the
PG runs above. `git diff --name-only 394aecc0d -- apps/api/tests/Fixtures/proforma` is **empty** — the
snapshots have not been touched since they were captured on the base commit, now across three rounds. Every
r2 change is inside the proforma branch by construction: `proformaTotals` is `null` when `isProforma` is
false, both new blade blocks are guarded on it, and `unitPrice()` is reached only through `$grossRow`.

### Guards

| gate | result |
|---|---|
| `./vendor/bin/pint` on all touched paths | `{"result":"pass"}` |
| `./vendor/bin/phpstan analyse` (level 8) on the 4 app files + 2 test files | `[OK] No errors` |
| `php tools/deptrac-ratchet.php` | `RESULT: PASS — no boundary regression against baseline` |
| `php tools/feature-lane-manifest-check.php` | `OK — 1420 Feature classes in 74 groups` |

Ceilings unchanged: `Document` **86**, `gated_ceiling` **1175**. `ProformaTotals` is a DTO, not a Feature test
class. Still **migration: NONE**; no route, no controller, no `apps/web` / `apps/pos` file.

### Files changed in fix round r2 (`git diff 1cd09ee9c`)

```
apps/api/app/Modules/Document/Application/DTOs/ProformaTotals.php            (new)
apps/api/app/Modules/Document/Application/Services/ProformaGrossAmountResolver.php
apps/api/app/Modules/Document/Application/Services/DocumentPdfService.php
apps/api/lang/{en,fr,ar}/documents.php
apps/api/resources/views/documents/components/totals.blade.php
apps/api/resources/views/documents/templates/credit_note.blade.php
apps/api/tests/Feature/Document/ProformaOutputTest.php
```

## New residuals from this round

### R-9 — one unreproduced PostgreSQL error, on a class this lane does not own

See item 3. `DocumentPdfRenderTest` returned `Errors: 5` once and was green on 8 subsequent runs; message not
captured. Together with the gate's own single unreproduced failure on a different class, this looks
environmental rather than class-specific — but it is unproven, and both lane classes now run on **every** CI
event, so it stays on the record until someone catches a message.

### R-10 — a quantity that does not divide its line amount leaves a sub-unit rounding residual

`unitPrice()` rounds `lineAmount ÷ qty` once at currency scale, so where the quantity does not divide the
amount exactly — `236.480 ÷ 3`, say — `printed unit × qty` differs from the printed amount by less than half a
currency unit per line item. The printed **amount** is authoritative and is what the estimated total sums, so
the page still reconciles; only the per-row multiplication does not, and by a sub-millime margin. Every row of
`DISCOUNTED_ROWS` divides exactly so the row-closure assertion carries no tolerance and stays falsifiable.
The alternative — printing unit prices at a wider scale than the currency — trades a visible oddity for an
invisible one and was not taken unilaterally. Recorded so the choice is the orchestrator's; the behaviour is
documented at `ProformaGrossAmountResolver.php:107-113`.

---

# Fix round r3 — response to the conventions gate r1 and the fiscal gate r3

Two records on `cbf3d6af5`: `2026-08-25-sc-f0-gate-r1-conventions.md`
(**ACCEPT-WITH-CONDITIONS, merge-blocking on F-C2 and F-C3**) and
`2026-08-25-sc-f0-gate-r3-fiscal.md` (**ACCEPT-WITH-CONDITIONS, merge-blocking: NO**). Same worktree, same
branch, LANE-PROTOCOL unchanged. The worktree was confirmed clean at `cbf3d6af5` before starting — the
conventions gate's §0 warning about an uncommitted `// TAMPER B` probe from the concurrent fiscal r3 gate was
real, and it was gone by the time this round began (`git status --short` empty, `git log -1` = `cbf3d6af5`).

**Code SHA: `add2a0a68`.** This handback section is the commit after it.

| item | finding | status |
|---|---|---|
| 1 [BLOCKING] | F-C2 / F-12 — duplicated CN totals block, no coverage | **CLOSED** — one partial + both types tested |
| 2 [BLOCKING] | F-C3 — CN closing sentence contradicts the banner | **CLOSED** |
| 3 | F-C1 — three-locale assertions tautological | **CLOSED** — literals + a parity test, both proven red |
| 4 | F-11 / R-10 — drift bound wrong and unpinned | **CLOSED** |
| 5 | F-13 — fixture line taxes vs the engine's proration | **CLOSED** — re-labelled synthetic |
| 6 | F-C4, F-C5, per-file census note | **CLOSED** |
| 7 | conventions cond 6 — clean-checkout re-run | **DONE** — outputs below |
| — | fiscal cond 4 (LEDGER R-9), FE lane (C-4/R-1/R-2), OQ-14 | orchestrator-owned, untouched |

## Item 1 — F-C2 / F-12 [BLOCKING]: one partial, and both types tested

Two halves, both landed.

**Structural.** `resources/views/documents/components/proforma_totals_rows.blade.php` (new, 69 lines) holds the
four rows once. `components/totals.blade.php:59` includes it; `templates/credit_note.blade.php:74` includes it
with `['totalRowStyle' => 'background-color: #dc2626;']` — the colour band was the *only* thing the two
copies disagreed about. The credit note keeps its own totals **block** (its posted arm says `Credit Total`,
not `Total`) but no longer its own copy of these rows.

**The partial gates itself**, at `:47` — `@if($isProforma ?? false)` wrapping the whole body. That is not
decoration: `ProformaTemplateCensusTest` went **red the moment the file appeared** —

```
these blade files put a tax mention on the page without consulting $isProforma: components/proforma_totals_rows.blade.php
```

— because the partial's own docblock names `stamp_duty_amount` and `tax_amount` while its markup consulted
only `$proformaTotals`. The census doing exactly its job on a file created in the same round is the best
evidence available that F-C4's widened heuristics work. The honest fix was to make the file gated in its own
right rather than by the accident of who includes it: a third call site that forgets the arm now renders
nothing instead of printing an estimate on a definitive document.

**Coverage.** `fiscalTypeProvider()` (invoice · credit note) and `localeAndFiscalTypeProvider()` (3 locales ×
2 types) now drive **five** R-8 tests that r2 ran on invoices only:
`test_every_proforma_row_closes_on_its_own_face`,
`test_the_proforma_totals_box_reconciles_with_a_stamp_duty_and_a_discount`,
`test_a_proforma_with_a_stamp_duty_and_a_discount_stays_clean`,
`test_the_duty_and_discount_rows_do_not_reveal_the_vat`,
`test_a_residual_that_runs_the_other_way_is_not_called_a_discount`. The three R-8 fixtures take a
`DocumentType` and dispatch through `confirmedOfType()`.

The credit-note cases were **green before the extraction** — as the fiscal gate's own probe found, the
hand-copy was correct on the day. That is precisely why this is a coverage finding: `OK (39 tests, 492
assertions)` before the partial and `OK (39 tests, 492 assertions)` after it is the evidence that the
extraction is behaviour-preserving, and the type provider is what stops the next edit diverging silently.

## Item 2 — F-C3 [BLOCKING]: a proforma credit note does not reduce anything

`templates/credit_note.blade.php:108-112`. The closing sentence is now wrapped in `@if(! $isProforma)` and
reads `__('documents.credit_note.balance_note')` instead of a non-dotted English literal.

It had been printing *"This credit note reduces your balance by the amount shown above."* under a banner
saying *"Proforma — non-fiscal document … it confers no right of deduction"*, on a page from which this lane
had deliberately deleted the Paid and Balance Due rows because a proforma is not a statement of account — and
it used the definitive noun in the one place the lane took care not to. Being non-dotted with no `lang/*.json`
in the repo, a French or Tunisian customer read it in English as well.

The English value of the new key is **byte-identical** to the old literal, on purpose: the posted credit-note
snapshots must not move, and they did not.

**RED on `cbf3d6af5`:**
```
1) …test_a_proforma_credit_note_does_not_claim_it_reduces_your_balance
2) …test_a_definitive_credit_note_still_states_the_balance_effect
Tests: 44, Assertions: 505, Failures: 3.
```
(the third was item 6's Arabic title). Both tests are green now — the first asserts the sentence is absent
from a proforma credit note's HTML **and** its PDF text; the second asserts the key's English value is
unchanged and still renders on a definitive credit note.

## Item 3 — F-C1: the three-locale claim, pinned for real

The old form was `assertStringContainsString(__('documents.proforma.title'), $html)` — both sides resolve
through the same Translator at the same locale, so a missing `ar` block falls back to `en` for the assertion
**and** for the blade, and the test passes on a page that renders English to an Arabic tenant.

Fixed two ways:

1. **Literal strings** — `localeLiteralProvider()` +
   `test_the_proforma_renders_the_literal_strings_of_its_locale` assert the actual `fr` and `ar` values
   (`Proforma — document non fiscal`, `Total estimé`, `Droit de timbre`, `Remise`;
   `مستند مبدئي — غير ضريبي`, `المجموع التقديري`, `معلوم الطابع`, `تخفيض`) against the rendered page, the way
   `en` already was.
2. **`tests/Unit/Lang/DocumentsProformaLangParityTest.php`** (new, 138 lines) — key sets, `:placeholder` sets
   and "no locale silently ships the English string" over `documents.proforma.*` and
   `documents.credit_note.*`. It is a **Unit** test on purpose: `backend-test` runs `--testsuite=Unit` on
   every CI event, unlike the parked Feature lane. Scope is deliberately these two subtrees — `lang/ar` is
   missing 13 keys that predate C-F0, and asserting whole-file parity would have to be baselined, which is how
   a guard becomes decoration. The class says so.

**RED PROOF — `proforma` block deleted from `lang/ar/documents.php`:**
```
--- parity test
1) …DocumentsProformaLangParityTest::test_every_locale_carries_the_same_keys with data set "proforma"
documents.proforma keys differ in ar: a missing key silently falls back to English
Tests: 6, Assertions: 89, Failures: 1.
--- literal-string test
1) …test_the_proforma_renders_the_literal_strings_of_its_locale with data set "arabic"
documents.proforma.title must render its own ar string, not a fallback
Tests: 2, Assertions: 5, Failures: 1.
--- the OLD tautological assertions, for contrast, on the SAME broken lang file
OK (6 tests, 72 assertions)
```
That last line is the finding, reproduced: the r2 assertions stay green on a page that has lost its Arabic.
`lang/ar/documents.php` restored immediately; parity green again.

## Item 4 — F-11 / R-10: the drift bound is linear in quantity

`ProformaGrossAmountResolver.php:106-124`. The docblock claimed `unit × qty` differs from the printed amount
"by less than half a currency unit per line item". Wrong: the unit price is rounded once and *then multiplied
by the quantity*, so the bound is

```
qty × 0.5 × 10^-scale
```

At 10 000 units of a 0.333 part that is 5.000 DT, and the actual drift is 2.700 DT — five times the claimed
ceiling and plainly visible. The docblock now states the linear bound, cites the measurement, and records why
the scale nevertheless stays at the currency's own (gate r3 ruling R-10: `unit × qty == amount` is
unattainable at any finite scale for a non-terminating quotient, and a scale-5 unit price would be the only
figure on the page off convention). The posted invoice has no such drift because its unit price is stored,
not derived — that sentence is in the docblock too.

`test_a_bulk_non_dividing_row_drifts_within_the_stated_bound` over `bulkNonDividingUnpostedInvoice()`
(qty 10 000 × net 0.333 @ 19%) asserts: printed cells `[0.396, 3962.700]`; the totals box prints exactly the
authoritative amount `3962.700`; the drift is `-2.700` **exactly** (so the test fails if it ever changes size,
in either direction); and `|drift| ≤ 5.0000`. Stated plainly: this test was **green on first run** — the
implementation always behaved this way, only the claim about it was false — so it is a characterisation pin,
not a red-first fix.

## Item 5 — F-13: the discounted fixture is labelled synthetic

`ProformaOutputTest::discountedUnposted()`'s docblock now says outright that `36.480 / 9.120 / 16.416` are
**not** the tax engine's proration of the fixture's own `discount_amount` — they imply a 4% reduction where
the stated discount is 2.94% — that they were chosen so every row divides its quantity exactly and the
row-closure assertion can be exact with no tolerance, that the document is internally consistent
(`340.000 − 10.000 + 62.016 + 1.000 = 393.016`), and that the SHAPE (persisted post-discount line taxes that
are not `rate × line_total`) is what the tests need while the exact proration is not. A reader who tries to
reconcile it against `TaxCalculationService:171-183` is told, in the fixture, not to.

## Item 6 — F-C4, F-C5, and the per-file census note

- **F-C4** — the six-line French rationale that lived inside `lang/ar/documents.php` (the one file an Arabic
  translator opens) is gone. `lang/en/documents.php` now carries it in English, next to the rule it belongs
  to, covering both the `stamp_duty` wording and the `title` form; `lang/ar/documents.php:32-49` keeps a short
  **English** pointer back to it plus the two things an editor must know before touching those strings.
- **F-C5** — `lang/ar/documents.php:52`: `مبدئية — مستند غير ضريبي` → **`مستند مبدئي — غير ضريبي`**. The old
  string was a bare feminine adjective agreeing with an elided فاتورة (invoice, f.), and the *same key* titles
  a credit note (إشعار, m.), where it disagreed. The new form is noun-headed and type-neutral — `مبدئي`
  agrees with the masculine `مستند` already in the line — which is how `en` and `fr` avoid the problem with
  the noun "Proforma". Pinned by the literal-string test, so it cannot silently revert.
- **Per-file census note** — `ProformaTemplateCensusTest.php:204-212` now records that
  `consultsTheFlagInCode()` is a **per-FILE** check, not a per-emission one: a file that consults the flag
  anywhere satisfies it everywhere, so `line_items` and `totals` could grow a new ungated tax cell without
  this census noticing. What actually covers that is `ProformaOutputTest` rendering the two real templates and
  scanning the output; this class's narrower job is catching a file that emits a tax mention and never
  mentions the flag at all — which is the shape a NEW template arrives in, and which is exactly what it did to
  the new partial this round.

## Item 7 — clean-checkout re-run (conventions condition 6)

A second, detached worktree was created at the code SHA, given its own real `vendor` copy and `.env`, verified
clean and verified to resolve classes from inside itself, then removed (and its throwaway database dropped).

```
CLEAN CHECKOUT AT add2a0a68            git status --short: (empty)
class resolution: .worktrees/sc-f0-cleanverify/apps/api/app/Modules/Document/Application/Services/ProformaGrossAmountResolver.php

=== CLEAN CHECKOUT add2a0a68 · sqlite ===
--- tests/Feature/Document/ProformaOutputTest.php
OK (44 tests, 511 assertions)
--- tests/Feature/Document/ProformaTemplateCensusTest.php
OK (7 tests, 24 assertions)
--- tests/Unit/Lang/DocumentsProformaLangParityTest.php
OK (6 tests, 101 assertions)

=== CLEAN CHECKOUT add2a0a68 · PostgreSQL 16 (autoerp_test_scf0_clean) ===
--- tests/Feature/Document/ProformaOutputTest.php
OK (44 tests, 511 assertions)
--- tests/Feature/Document/ProformaTemplateCensusTest.php
OK (7 tests, 24 assertions)
--- tests/Unit/Lang/DocumentsProformaLangParityTest.php
OK (6 tests, 101 assertions)
```

The conventions gate's four failures are accounted for: they were the uncommitted `// TAMPER B` probe, and on
a clean tree at the r3 SHA nothing fails.

## Fix round r3 — verification

| test file | RED (at `cbf3d6af5`) | GREEN sqlite | GREEN PG |
|---|---|---|---|
| `tests/Feature/Document/ProformaOutputTest.php` | `Tests: 44, Assertions: 505, Failures: 3` | `OK (44 tests, 511 assertions)` | `OK (44 tests, 511 assertions)` |
| `tests/Unit/Lang/DocumentsProformaLangParityTest.php` (new) | `Tests: 6, Assertions: 89, Failures: 1` (ar block deleted) | `OK (6 tests, 101 assertions)` | `OK (6 tests, 101 assertions)` |
| `tests/Feature/Document/ProformaTemplateCensusTest.php` | `Failures: 1` — the new partial, ungated | `OK (7 tests, 24 assertions)` | `OK (7 tests, 24 assertions)` |
| `tests/Feature/Document/PostingMarkerPrintTest.php` | — | `OK (10 tests, 20 assertions)` | `OK (10 tests, 20 assertions)` |
| `tests/Feature/Modules/Document/DocumentPdfSellerTaxIdTest.php` | — | `OK (3 tests, 5 assertions)` | `OK (3 tests, 5 assertions)` |
| `tests/Feature/Modules/Document/DocumentPdfRenderTest.php` | — | `OK (6 tests, 20 assertions)` | `OK (6 tests, 20 assertions)` — green again, see R-9 |
| `tests/Feature/Compliance/FacturXWorkOrderInvoiceTest.php` | — | `OK (2 tests, 7 assertions)` | — |
| `tests/Feature/Document/FacturXBranchSellerTest.php` | — | `OK (2 tests, 7 assertions)` | — |
| `tests/Feature/Modules/Document/FacturXDescriptionTest.php` | — | `OK (3 tests, 7 assertions)` | — |

PG leg: throwaway `autoerp_test_scf0` (and `autoerp_test_scf0_clean` for item 7), both **dropped**.

### Posted pins — byte-identical, fourth round running

Inside the `OK (44 …)` above on sqlite and PG. `git diff --name-only 394aecc0d..add2a0a68 -- apps/api/tests/Fixtures/proforma`
is **empty** — the snapshots have not been touched since they were captured on the base commit. The two r3
changes that touch a shared surface are structurally confined to the proforma arm: the extracted partial is
included only from inside each template's `@if($isProforma)` branch *and* gates itself again at `:47`, and the
credit-note sentence is wrapped in `@if(! $isProforma)` with an English value byte-identical to the literal it
replaced.

### Guards

| gate | result |
|---|---|
| `./vendor/bin/pint` on all touched paths | `{"result":"pass"}` |
| `./vendor/bin/phpstan analyse` (level 8) on the 4 app files + 3 test files | `[OK] No errors` |
| `php tools/deptrac-ratchet.php` | `RESULT: PASS — no boundary regression against baseline` |
| `php tools/feature-lane-manifest-check.php` | `OK — 1420 Feature classes in 74 groups … every --filter entry is anchored and uniquely matched against 1816 test classes` |

**Ceilings unchanged: `Document` 86, `gated_ceiling` 1175.** `DocumentsProformaLangParityTest` is in
`tests/Unit`, which the feature-lane manifest does not govern (it enumerates `tests/Feature` top-level groups
only — the same reason N-6's `DocumentStatusMachineTest` needed no raise). The all-suite class count in the
checker's summary moves 1815 → 1816, which is that one Unit class and is not a ceiling.
`proforma_totals_rows.blade.php` is a view, not a test class. Still **migration: NONE**; no route, no
controller, no `apps/web` / `apps/pos` file (`git diff --name-only cbf3d6af5..add2a0a68 | grep -E 'apps/(web|pos)|migrations'`
is empty).

### Files changed in fix round r3 (`git diff --stat cbf3d6af5 add2a0a68`)

```
apps/api/resources/views/documents/components/proforma_totals_rows.blade.php   (new, 69)
apps/api/tests/Unit/Lang/DocumentsProformaLangParityTest.php                   (new, 138)
apps/api/app/Modules/Document/Application/Services/ProformaGrossAmountResolver.php
apps/api/lang/{en,fr,ar}/documents.php
apps/api/resources/views/documents/components/totals.blade.php
apps/api/resources/views/documents/templates/credit_note.blade.php
apps/api/tests/Feature/Document/ProformaOutputTest.php
apps/api/tests/Feature/Document/ProformaTemplateCensusTest.php
10 files changed, 616 insertions(+), 92 deletions(-)
```

## New residuals from this round

### R-11 — the estimated-total row still wears the definitive brand band (conventions F-C7)

`proforma_totals_rows.blade.php:65` keeps `class="total-row"`, and the credit-note call site still passes the
`#dc2626` band its definitive `Credit Total` row uses. With `getDocumentTitle()` printing `Invoice` /
`Facture` / `Avoir` and the document number unchanged, the only visual difference between a proforma and a
definitive document is the banner. Spec-conformant — the brief asked for the banner and the lane delivered it
— but the owner's *one main element* rule argues the banner, not the total band, should dominate. A watermark
or title treatment is an owner decision, not a lane fix. Recorded, not taken.

### R-12 — this lane put the first real Arabic text into an LTR-only print table (conventions F-C6)

`grep -rn "dir=\|rtl\|direction" apps/api/resources/views/documents/` returns **zero hits**, and the totals
table aligns physically (`text-align: left/right`, not `start/end`). Before this lane the proforma totals box
contained only non-dotted keys that never translate, so it was Latin text in every locale; the four dotted
keys are the first Arabic content ever placed there. The discount row also prints a manual ASCII `-` in front
of an ICU-formatted amount (`proforma_totals_rows.blade.php:56`), copied verbatim from the pre-existing posted
discount row — under `ar` the sign should come from the formatter's negative pattern. This raises the priority
of residual **R-4** (Arabic in the PDF stack) rather than creating a new problem; the owning lane is still the
Arabic-PDF one.

### R-13 — `getDocumentTitle()` has no `ar` entry and no `__()`

`DocumentPdfService.php:250-278` is a hardcoded per-locale PHP map with `en` and `fr` only, so an Arabic
proforma's headline reads `Invoice` in Latin above an Arabic banner. Pre-existing and out of this lane's
scope, recorded because it bounds what "the proforma renders in Arabic" can mean today.

---

## ⚠️ MERGE-TIME ARITHMETIC — `gated_ceiling` must be re-derived (dev moved again)

At this lane's base `0ae906b0e`, dev carried `gated_ceiling 1173` / `Document 84`, so the branch sets
**1175 / 86** (+2 Document classes, +2 ceiling). **dev has since moved to `a7776dda9`, where
`gated_ceiling` is 1176 and `Document` is still 84.**

Therefore, at the moment of merge:

- `groups.Document.classes` → **86** — correct as committed (dev's 84 + this lane's 2).
- `gated_ceiling` → **dev's value at merge + 2**, i.e. **1178** against `a7776dda9`, **not** the 1175 on this
  branch. Taking the branch value verbatim would set the ceiling BELOW the real parked count and turn
  `feature-lane-manifest-check.php` red on the merge commit.

Left un-guessed on purpose: dev has moved three times during this lane, and the manifest's own convention
(recorded in the `Document` group note) is that the union is a merge-time value re-derived by whoever
squashes. Verify with `git show dev:apps/api/tests/feature-lane-manifest.json` immediately before the merge and
set `gated_ceiling` to that number plus 2.

`DocumentsProformaLangParityTest` does **not** enter this arithmetic — it is `tests/Unit`, which the
feature-lane manifest does not govern.
