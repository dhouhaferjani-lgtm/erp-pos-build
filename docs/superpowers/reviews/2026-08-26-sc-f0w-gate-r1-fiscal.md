# C-F0w gate r1 — FISCAL lens (web proforma parity)

| | |
|---|---|
| Lane | C-F0w — web detail pages render the proforma the PDF renders (Session C, document-lifecycle-dimensions; LEDGER C-26 (i)) |
| Branch / worktree | `feat/sc-f0w-web-proforma-parity` · `.worktrees/sc-f0w-web-proforma` |
| Reviewed SHA | `79af6c4e3` (5 ahead of `dev` `9d0d08ae5`) · Base `eaf80a112` |
| Date / round | 2026-08-26 · r1 (fiscal) |
| Lens | fiscal-pos-reviewer — predicate parity, wire net figures, W-4/W-8 rulings, fiscal invariants, rule 19/rule 14/i18n |
| Sibling gate | `2026-08-25-sc-f0w-gate-r1-conventions.md` (ACCEPT-WITH-CONDITIONS; W-1/W-2 fixed in `79af6c4e3`). Conventions reasoning is NOT duplicated here except where the fiscal lens overturns or completes it. |
| Tree state | worktree READ-ONLY: no file in `.worktrees/sc-f0w-web-proforma` was modified. `git status --short` empty at entry and exit. No mutation/tamper probe was run for that reason — falsifiability is argued from the shipped control cases (§0). |

**VERDICT: spec ✅ + quality CHANGES-REQUESTED — merge-blocking: YES (F-1 only).**

The fiscal core of this lane is right, and I could not break it. There is exactly ONE
implementation of the proforma predicate in the system; the web reads its answer as a
boolean and decides nothing itself; the gross line figures and the totals rows come
from the same resolver the blade uses, so the screen and the PDF cannot print different
numbers; no float touches money anywhere in the diff; no fiscal invariant is weakened
(no writes, no migration, no numbering/seal/hash path touched, deptrac ratchet PASS at
183 held). W-1 and W-2 are genuinely closed, and I proved it by execution rather than
by reading the handback.

One thing blocks: the **only** test that pins the whole server half of this lane runs on
no CI event. That is the third occurrence of the identical defect in this program
(C-F0 fiscal r1 F-3, C-QR0a fiscal r1 F-3), both of which were ruled merge conditions
and fixed. Consistency and substance agree here.

---

## 0. What I executed (nothing below is quoted from the handback)

| Leg | Command | Result |
|---|---|---|
| web tests by path | `npx vitest run` × the 5 proforma files | **5 files / 42 tests PASSED** (incl. `InvoiceDetailPage.proforma.test.tsx` 6/6 with the REAL `DocumentHeader`) |
| backend test | `./vendor/bin/phpunit tests/Feature/Document/ProformaResourceTest.php` | **OK (20 tests, 40 assertions)**, sqlite in-memory, 15.5 s |
| boundary ratchet | `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` | **PASS — no boundary regression**; 183/183 held (`ModuleDomain on ModuleApplication` 54 → 54, i.e. moving the predicate onto the aggregate added none) |
| deptrac raw | `./vendor/bin/deptrac analyse --no-progress` | Violations 183, Errors 0, Warnings 0 |
| float scan | `git diff base..HEAD -- apps/web apps/api \| grep '^+' \| grep -E 'parseFloat\|Number(\|toFixed\|(float)\|number_format'` | **3 hits, all inside COMMENTS** describing the float bug the lane removed. Zero added float on money. |
| CI reachability | `grep -n Proforma .github/workflows/ci.yml` + job `if:` at `:1796` | `ProformaResourceTest` **absent** from the `:1025` allowlist; `feature-lane-documents` gated off. See F-1. |
| predicate diff | clause-by-clause compare of the moved body | identical (see §1) |
| surface census | `grep -rn 'DocumentTotals\|tax_amount' apps/web/src`, `routes/index.tsx` | only 2 routes render a fiscal document read-only; DN/RN/PO pages that print `tax_amount` are NOT fiscal types (§2) |

**Falsifiability without a tamper probe.** `expectNoForbiddenToken`
(`apps/web/src/test/proformaTokens.ts:41-52`) throws on a match, and each new page test
ships a **control case** that asserts the exact opposite on `is_proforma: false`
(`InvoiceDetailPage.proforma.test.tsx` "leaves a DEFINITIVE posted invoice exactly as it
was" asserts `Posted`, `Unpaid`, `Fiscally Sealed`, `Subtotal`, `TVA 19` all PRESENT).
A blanket suppression bug fails the control; a suppression regression fails the scan.
That pair is non-vacuous by construction. The conventions gate additionally observed the
invoice scan RED before the fix, which I take as recorded evidence rather than re-deriving.

---

## 1. Predicate parity — VERIFIED, no finding

**One implementation.** The body moved verbatim from the Application service onto the
aggregate and the service now delegates:

- `apps/api/app/Modules/Document/Domain/Document.php:628` — `isProformaOutput()`:
  fiscal type (`DocumentPostingService::getFiscalDocumentTypes()`, i.e. Invoice +
  CreditNote only, `DocumentPostingService.php:50-53`) → `fiscal_hash !== null` → not
  (`FiscalStatus::Voided` or `DocumentStatus::Cancelled`) → not
  (`isHistorical()` or `FiscalCategory::NonFiscal`).
- `apps/api/app/Modules/Document/Application/Services/ProformaOutputPolicy.php:46-49` —
  `isProforma()` now returns `$document->isProformaOutput()`.

I compared the five clauses against the pre-move body in the diff: same fields, same
enum values, same order; the final two `if`s were collapsed into one negated `return`
with identical semantics. **No status-string fallback survives anywhere on either side.**

**The PDF and the JSON ask the same question.** `DocumentPdfService::prepareData()`
(`DocumentPdfService.php:180`) calls `$this->proformaPolicy->isProforma($document)`;
`DocumentData::fromModel()` (`DocumentData.php:279`) calls
`$document->isProformaOutput()`. Same function.

**The client decides nothing.** Every proforma/tax branch on the web keys on the server
boolean and on nothing else:
`InvoiceDetailPage.tsx:426` (`invoice.is_proforma === true`),
`CreditNoteDetailPage.tsx:139`, `CreditNoteDetail.tsx:76-77`,
`DocumentTotals.tsx:52,65,107`. The surviving `status` reads on those pages drive a chip,
a tab or a fetch — never a tax decision. The N-6 `isBooked` heuristic and the
"this view cannot tell" comment are deleted.

**Fail direction.** `DocumentData::$is_proforma` is a non-nullable `bool` computed on the
aggregate, so no `fromModel` call site can emit a silent `false`; and
`DocumentData.php:319` re-checks the predicate before attaching the projection, so a
caller cannot bolt a proforma block onto a definitive document. The projection's absence
is safe by construction and is pinned three times
(`DocumentTotals.proforma.test.tsx` "still hides every tax figure when the server ships
NO proforma projection", plus one per live page). The one soft spot is on the TypeScript
side — see F-3.

**Refund/void, chain, numbering, immutability: untouched.** The backend half of the diff
adds one read-only Domain method, two `Data` DTOs, one presenter, one nullable ctor
parameter and two controller overrides. No write, no migration, no change to sealing,
posting, numbering, `fiscal_hash`, `chain_sequence` or the document immutability trigger.
`ProformaPresenter` is read-only and its money is bcmath at the document's currency scale
(`ProformaPresenter.php:40-41,58`, `getScaleSafe($currency, 3)` — correct for a context-free
caller, rule 19/20). `documents.currency` is `NOT NULL DEFAULT 'EUR'`
(`2025_11_30_080000_create_documents_table.php:24`), so the presenter's
`$document->currency` and the PDF's `$document->currency ?? $company->currency`
(`DocumentPdfService.php:173`) cannot diverge.

---

## 2. Scope of the invariant — VERIFIED, no finding

§2.4 binds documents `ProformaOutputPolicy` calls a proforma, i.e. **Invoice and
CreditNote only** (`DocumentPostingService.php:50-53`). The pages that render
`tax_amount` unconditionally —
`DeliveryNoteDetailPage.tsx:292-296`, `ReturnNoteDetailPage.tsx:282-286`,
`PurchaseOrderDetailPage.tsx:534-538` — are all non-fiscal types and are correctly
outside the predicate. `QuoteDetailPage`/`SalesOrderDetailPage` pass no `isProforma` to
`DocumentTotals`, which is right for the same reason. `routes/index.tsx:741,795` are the
only two read-only routes for a fiscal document, and both are covered. `TaxExemptionNotice`
is rendered by nothing.

---

## 3. Findings

### F-1 — [IMPORTANT · MERGE-BLOCKING] `ProformaResourceTest` executes on NO CI event; the entire server half of the lane is unpinned in CI

`apps/api/tests/Feature/Document/ProformaResourceTest.php` is the only thing that pins
(a) `is_proforma` across nine document shapes including the two the policy exists to
refuse, (b) that the projection is attached iff the predicate holds, (c) that the proforma
figures are tax-inclusive and close over the estimated total, and (d) that a definitive
invoice **keeps** `subtotal`/`tax_amount`. It runs nowhere:

- `.github/workflows/ci.yml:1791` `feature-lane-documents` (the job whose step
  `:1894-1895` runs `./vendor/bin/phpunit tests/Feature/Document/`) is gated
  `:1796` — `vars.SELF_HOSTED_RUNNER_READY == 'true' && (workflow_dispatch || base_ref == 'main' || push→main)`.
- `backend-test` runs `--testsuite=Unit` only (`ci.yml:420`).
- The `backend-test-pgsql` `--filter` allowlist (`ci.yml:1025`) names `ProformaOutputTest`
  and `ProformaTemplateCensusTest` (C-F0) and `StagedDeploymentBootTest` /
  `AuthoritySchemaUnactivatedStateTest` (C-QR0a) — but **not** `ProformaResourceTest`.

Why it matters: `DocumentData::fromModel()` is a static factory with ~20 call sites and
is refactored often. With no CI pin, a future change that drops `is_proforma` (or reverts
it to a status heuristic) ships silently, and every unsealed invoice starts rendering VAT
and a `Posted` chip again — the exact defect this lane exists to close. This is the third
identical occurrence in this program; the two prior gates ruled it a condition and the
lane owner closed it both times (`ci.yml:983`→`:1025` history in the comment block at
`:996-1015`).

**Fix directive:** add `ProformaResourceTest` to the `ci.yml:1025` allowlist alongside
`ProformaOutputTest|ProformaTemplateCensusTest`, with a one-line comment in the block at
`:996-1015` naming the reason, and record the **S-14 promotion leg** — `backend-test-pgsql`
does not run on `push→dev`, so the class is armed on PRs and main pushes only.

### F-2 — [IMPORTANT] The security rationale at `DocumentTotals.tsx:62-64` is factually false: the VAT figures ARE in the browser

```
// A proforma never asks for a tax breakdown. Not merely "does not render it":
// the VAT figures never enter the browser at all, so no later refactor can
// surface them from cache.
enabled: … && !isProforma,          // DocumentTotals.tsx:62-65
```

Disabling the per-rate breakdown query is real hardening and I credit it. But the claim
is wrong: the **same page's own document payload** carries the net and the tax. A
proforma response emits `subtotal` and `tax_amount` unconditionally (`DocumentData`
constructor; both are in the generated `DocumentData` shape at
`packages/shared/types/generated.d.ts`), and `lines[]` carries NET `unit_price` /
`line_total` (`DocumentLineData.php:27,31,53,57`). The lane's own fixtures prove it:
`InvoiceDetailPage.proforma.test.tsx` builds `is_proforma: true` with
`subtotal: '200.000', tax_amount: '38.000'` and the page renders green. A "later
refactor" needs `invoice.tax_amount`, not the cache.

**My ruling on the "net figures on the wire" residual (so it stops being re-litigated):
ACCEPT, non-blocking.** The customer-facing artefact is the PDF, and that one is clean
(C-F0, byte-verified there). The web detail page is an internal, authenticated,
permission-gated staff surface, and stripping `subtotal`/`tax_amount` from `DocumentData`
would break the definitive branch and ~20 unrelated consumers of the same DTO for no
fiscal gain. The invariant §2.4 protects is **what is RENDERED**, and that is gated and
now tested on both live pages.

**Fix directive:** correct the comment to state what is true — the per-rate breakdown is
never fetched; the document-level net/tax stay on the payload and are held off-screen by
the `isProforma` branch alone — so the next reader does not inherit a false guarantee.

### F-3 — [IMPORTANT] The security-bearing field is OPTIONAL in the FE mirrors, and its absence fails to the UNSAFE side

`apps/web/src/types/document.ts:100` (`is_proforma?: boolean`) and
`apps/web/src/types/creditNote.ts:90` declare the field optional, while the generated DTO
types it non-nullable (`generated.d.ts`, `is_proforma: boolean`). Both pages then compare
`=== true` (`InvoiceDetailPage.tsx:426`, `CreditNoteDetailPage.tsx:139`,
`CreditNoteDetail.tsx:77`). So a payload that omits the field renders a **definitive**
document: VAT breakdown, `Posted` chip, `Fiscally Sealed` chip, net line amounts — on a
document nothing has sealed.

Contrast the projection, where the lane got the direction right on purpose
(`DocumentTotals.tsx:107-113`: `isProforma` with no projection prints nothing rather than
falling back to the tax box). The predicate deserves the same treatment. Unreachable
today — every producing path emits the field (`DocumentData.php:279`,
`CreditNoteController::formatCreditNote()` `'is_proforma' => $creditNote->isProformaOutput()`)
— which is why this is not blocking. But it is the one place in the lane where a mistake
degrades toward "leak", not toward "print less".

**Fix directive (cheap, one line each):** on the two FISCAL detail pages only, read
`invoice.is_proforma !== false` / `creditNote.is_proforma !== false`. A definitive
document always carries an explicit `false`, so behaviour is unchanged today, and an
omitted field degrades to "show less" instead of "claim a seal". Leave `CreditNoteDetail`
(unmounted) and the optional declarations as they are.

### F-4 — [MINOR] The advertised anti-drift assertion in `ProformaResourceTest` is a tautology after the delegation

`ProformaResourceTest.php:79-83` asserts
`$policy->isProforma($document) === $data->is_proforma`. Since `79af6c4e3` the policy
**delegates** to `Document::isProformaOutput()` and the DTO calls that same method
(`ProformaOutputPolicy.php:48`, `DocumentData.php:279`), so this compares `x` with `x`
and can never fail. The docblock (`:531-537`) claims "every case asserts `is_proforma`
against a live `ProformaOutputPolicy` call rather than against a hand-written
expectation… a fork would fail the suite rather than drift quietly" — there is nothing
left to fork.

The test is NOT worthless: `:78` (`assertSame($expected, $policy->isProforma(...))`) is a
hand-written truth table over nine shapes and is the assertion that actually protects the
predicate. Fix the docblock to say so, and consider asserting the raw JSON of a real HTTP
`GET /invoices/{id}` instead, which would pin the resource → wire → client contract that
the DTO-level call skips.

### F-5 — [MINOR] "The WHOLE page, scanned" is really "the un-mocked page, scanned" — and two of the six mocks are settlement surfaces

`InvoiceDetailPage.proforma.test.tsx` mocks `RelatedDocumentsTab`,
`DocumentAttachments`, `CreditNoteList`, `PaymentHistorySection`,
`CloseWithWriteoffSection`, `DocumentActionBar`. The first three are inert; the last three
render money and settlement wording on a proforma:
`CloseWithWriteoffSection` renders whenever `invoiceStatus === 'posted'` and a
within-tolerance residual exists (`CloseWithWriteoffSection.tsx:43`), which includes a
posted-but-never-sealed invoice; `PaymentHistorySection` is reachable via the payments tab
(`InvoiceDetailPage.tsx:774-793`). Neither is scanned. The scan also only ever sees the
DEFAULT tab, so no tab body other than `related` (itself mocked) is covered.

Not a defect — mocking fetchers is correct — but the docblock's "scanned whole" claim
should be scoped, and one of the two settlement components should be un-mocked (or given
its own token scan) if the token list is going to keep `/\bpaid\b/i` and `/sealed/i` in it.

### F-6 — [MINOR] The new server-computed proforma figures are formatted at the COMPANY's scale, not the document's — and the box's closure is the security property

`ProformaPresenter` scales every figure at the **document's** currency
(`ProformaPresenter.php:40-41`), and the resolver derives its bcmath scale the same way
(`ProformaGrossAmountResolver.php:159`). Both live pages then format them at the
**company's**: `InvoiceDetailPage.tsx:437` and `CreditNoteDetailPage.tsx:155`
(`currentCompany?.currency ?? 'EUR'` inside `displayLineAmount`), and
`InvoiceDetailPage.tsx:706` / `CreditNoteDetailPage.tsx:354` for the totals box.

The pre-existing, page-wide nature of this is honestly documented at
`DocumentTotals.tsx:78-88` and tracked in
`docs/superpowers/tickets/2026-08-05-l4-web-followups.md`, and the argument for not
half-fixing it (two currencies in one viewport) is correct — I am not asking for a fix
here. What is NEW and belongs on that ticket: the proforma box's rows are supposed to
**close** (Σ gross lines + stamp duty − discount = estimated total), and independent
`Big.js` half-up rounding of each row into a coarser company scale can leave a residual
the page does not explain. Note also that the lane fixed exactly this class of bug one
level down — `CreditNoteDetail.tsx:41-42` now formats at `creditNote.currency` — so the
codebase now holds both conventions.

**Fix directive:** append the closure risk (and the `CreditNoteDetail` divergence) to the
tracked ticket. No code change in this lane.

### F-7 — [MINOR] The forbidden-token scan is English-only

`expectNoForbiddenToken` is only ever fed pages rendered through `translateFrom({en …})`
in all four new/updated test files, and `FORBIDDEN_PROFORMA_TOKENS`
(`proformaTokens.ts:15-38`) is a Latin-script list (`VAT`, `TVA`, `\btax`, `TTC`, `\bHT\b`,
`posted`, `sealed`, `paid`). The `fr` and `ar` renderings of these pages are never scanned,
and the Arabic tax vocabulary (`ضريب…`) has no pattern at all. The copy-parity test
(`proformaCopyParity.test.ts`) pins the banner copy in three locales against the backend
`lang/*/documents.php`, which is good and non-tautological ("the three locales say three
different things"), but it scans no rendering. Worth one `fr` case on the live invoice
page next time the file is touched.

### F-8 — [MINOR · pre-existing, out of lane] A VAT breakdown is two clicks from the page this lane just cleaned

`DocumentActionBar.tsx:163` exposes "Create Credit Note" for
`type === 'invoice' && status === 'posted'` — which includes a posted-but-never-sealed
invoice, i.e. a proforma. The modal it opens
(`InvoiceDetailPage.tsx:874` → `CreateCreditNoteForm`) prints the NET unit price beside a
gross line total computed as `subtotal + tax` with `parseFloat` float arithmetic
(`CreateCreditNoteForm.tsx:179-183, 420-424`): a VAT breakdown written as a subtraction,
plus a rule-19 violation. Entirely pre-existing and outside this lane's declared scope
(header / lines / totals), and I am not asking this lane to fix it. But it falsifies the
absolute reading of §2.4 ("no `apps/web` surface renders a VAT figure for a proforma"),
so it should be a ticket rather than an unrecorded belief. Separately worth an owner
question: whether a credit note should be creatable against an invoice the chain has
never sealed at all.

### F-9 — [MINOR] Docblock-only import

`DocumentData.php:9` imports `ProformaOutputPolicy` solely to resolve a `{@see}` in the
`$is_proforma` docblock; nothing in the file references the class. Harmless, but an
Application DTO now names a policy it does not use.

---

## 4. Rulings the conventions gate deferred to this lens

### W-4 — settlement surfaces on a proforma — **RULED: KEEP THEM. Not a fiscal defect, not blocking.**

The surfaces are `DocumentOutstandingCallout` (`InvoiceDetailPage.tsx:495-500`), the
payments tab with `PaymentHistorySection` + `OutstandingAmountSection` (`:774-793`), and
`CloseWithWriteoffSection` (`:562`).

1. **Settling an unsealed invoice is a first-class, supported flow, not an anomaly.**
   `paidNeverSealedInvoice` is one of the nine shapes the predicate is defined over
   (`ProformaResourceTest.php:659-662`), and the C-0a0 applicability classifier admits a
   `confirmed|posted` sales invoice as settleable (SPEC r11.1, rule 7). Suppressing the
   outstanding callout and the payments tab on a proforma would remove the only in-product
   way to record a payment against a confirmed invoice — a strictly worse outcome than the
   claim-consistency wrinkle it fixes.
2. **Nothing there is fiscally derivable.** Every figure on those surfaces is a GROSS
   amount (`total`, `amount_paid`, `outstanding_amount`, `balance_due`). No net basis and
   no rate appears beside them, so no subtraction recovers the VAT. The exposure argument
   that governs the totals box does not reach here.
3. **The two are not inconsistent once you name what each is.** The proforma **totals box**
   is a mirror of the customer-facing PDF and correctly drops the settlement rows
   (`proforma_totals_rows.blade.php` — "a proforma is not a statement of account"). The
   rest of the page is an internal working surface for staff. `showBalanceDue={isPosted && !isProforma}`
   (`InvoiceDetailPage.tsx:708`) is therefore right, and so is the callout above it.

**Action:** none in code. Record the box-is-a-PDF-mirror / page-is-an-internal-surface
split in the `DocumentTotals.tsx:91-106` docblock so the next reviewer does not reopen it.
(F-5 asks separately that one of these components be un-mocked in the scan.)

### W-8 — a DRAFT invoice is now a proforma on screen too — **RULED: CORRECT, do not narrow the predicate. Owner note only.**

1. **Narrowing is the defect.** Excluding `DocumentStatus::Draft` (or `Confirmed`) from
   `isProformaOutput()` would put a status term back into a predicate whose whole point is
   that the SEAL decides — and would immediately re-open the two holes the lane names:
   a `Paid`-but-never-sealed invoice, and a `Posted`-but-never-sealed one. The predicate
   stays as written.
2. **The "staff can no longer check VAT before posting" concern is real but is served by
   the authoring surface, which is out of §2.4's scope.** `/sales/invoices/:id/edit`
   (`routes/index.tsx:752-760`) renders `DocumentForm` → `DocumentLineEditor`, which shows
   a per-line `Tax %` column (`DocumentLineEditor.tsx:1038`) and a Tax total
   (`:1113-1114`) computed with bcmath (`:411-422`). That is where tax is SET, so it is an
   authoring view, not a rendering of a document — the distinction §2.4 is actually about.
   A user who needs the VAT of an unposted invoice opens Edit.
3. **Recommendation to the owner/spec, not to this lane:** narrow §2.4's wording from
   "no `apps/web` surface" to "no read/rendering surface", and record the edit screen as
   the sanctioned pre-posting VAT check. As written, the spec is falsified by the edit
   screen (and by F-8's modal) rather than by any defect.

---

## 5. What I checked and found CLEAN (no finding)

- **Rule 19 (money/quantity).** Zero `parseFloat`/`Number(`/`toFixed`/`(float)`/
  `number_format` added on money anywhere in the diff (scan in §0; the three hits are
  comments). The lane REMOVES a live float bug (`CreditNoteDetail.tsx:41-42`, was
  `parseFloat(amount).toFixed(companyDecimals)` — a truncated millime on TND) and tightens
  the two legacy assertions that encoded it (`CreditNoteDetail.test.tsx:120,297`).
  Backend money is `CurrencyScale::bcformatStrict` at a currency-derived scale
  (`ProformaPresenter.php:58`) and `bcadd/bcsub/bcmul/bccomp` in the shared resolver.
  The pre-existing `parseFloat(invoice.outstanding_amount…)` at `InvoiceDetailPage.tsx:450`
  is untouched by this lane (verified against base).
- **Quantity display precision.** Untouched: both tables still render
  `formatQuantity(line.quantity, getQuantityDecimals(line))`
  (`InvoiceDetailPage.tsx:686`, `CreditNoteDetailPage.tsx:333`). No literal
  `decimalPlaces`, no raw scale-4 string.
- **Rule 14 / tenantScopedKey.** No query key is added or changed. `DocumentTotals.tsx:60`
  keeps `tenantScopedKey(['tax-breakdown', documentId])` and only flips `enabled`
  (`:65`). Both pages fetch with raw `api.get` + a single `.data.data` unwrap
  (`CreditNoteDetailPage.tsx:53`, `InvoiceDetailPage.tsx:115-117`) — unchanged.
- **Rule 11 / i18n.** Seven new dotted keys under `documents.proforma.*` +
  `documents.creditNote.balanceNote`, authored in `en`, `fr` AND `ar` in one commit; two
  retired `creditNotes.postingMarker.*` keys have zero remaining readers. The parity test
  reads the backend `lang/*/documents.php` as text and pins the web copy VERBATIM against
  it in all three locales, with a guard that goes red if the PHP values ever become
  dynamic (`proformaCopyParity.test.ts`) and an anti-fallback case ("the three locales say
  three different things"). Backend/frontend copy cannot drift.
- **Rule 8 (events immutable).** No Event class is renamed, restructured or deleted; no
  event is touched at all.
- **Fiscal invariants.** No migration. No write path. No change to posting, sealing,
  numbering, `fiscal_hash`, `chain_sequence`, `fiscal_status`, the document immutability
  trigger, or the Factur-X gate C-F0 installed. `DocumentData::fromModel()`'s new
  4th parameter is nullable with a `null` default, so all ~20 existing call sites are
  behaviourally unchanged, and `:319` re-gates the projection on the predicate.
- **Boundaries.** `Document` (Domain) now calls `DocumentPostingService`
  (Domain\Services) — same layer. Deptrac ratchet PASS, `ModuleDomain on ModuleApplication`
  54 → 54: moving the predicate onto the aggregate introduced no new Domain→Application
  edge (the `{@see ProformaOutputPolicy}` reference is a docblock, and the class is
  deliberately not imported into `Document.php`).
- **Test quality.** Real models + `RefreshDatabase` + a real fixture builder on the PHP
  side; real `en` bundles, the real `DocumentHeader`, the real `DocumentTotals`, the real
  `StatusBadge` and the real `CompanyConfigProvider` on the web side, with only fetchers
  mocked. Every proforma case has a definitive control case. No `assertTrue(true)`, no
  mocking of the thing under test. The PHP test does no aggregate arithmetic, so the
  sqlite-masks-PostgreSQL hazard does not apply to it.
- **`unit_price` semantics.** Correctly handled and, notably, correctly *documented*:
  `ProformaLineAmounts` is explicit that its `unit_price`/`line_total` are tax-INCLUSIVE
  and that `DocumentLineData`'s are NET, and the page joins the two by `line_id` and
  prints nothing when the projection misses a line. No per-line `line_subtotal == unit_price × qty`
  identity is asserted anywhere; the reconciliation is enforced at the aggregate
  (`ProformaGrossAmountResolver::totals()` derives the residual from
  `total − (Σ gross + stamp)`).
- **W-1 / W-2 closure.** Proven by execution, not by reading: `suppressStatusBadge`
  (`DocumentHeader.tsx:137-141`, default `false`, all other callers unaffected) threaded
  from `InvoiceDetailPage.tsx:473`; payment chip gated at `:507`; sealed chip at `:518`;
  credit-note page chips at `:204-215`. `InvoiceDetailPage.proforma.test.tsx` 6/6 green
  with the real header, asserting `Posted` / `Unpaid` / `Fiscally Sealed` all absent on a
  `status: 'posted'`, `payment_status: 'unpaid'`, `is_proforma: true` invoice, and all
  three present on the definitive control.
- **W-3 / W-6 closure.** `types/document.ts:64,76` are now aliases to
  `App.Modules.Document.Application.DTOs.*`; `DocumentTotals.tsx:115,128,140` use
  `!= null`.

---

## 6. Conditions

1. **[MERGE-BLOCKING]** Close **F-1**: allowlist `ProformaResourceTest` at
   `.github/workflows/ci.yml:1025` with a comment in the block at `:996-1015`, and record
   the S-14 promotion leg (not armed on `push→dev`).
2. Close **F-2** (correct the false rationale comment at `DocumentTotals.tsx:62-64`) and
   **F-3** (`!== false` on the two fiscal detail pages). Both are one-liners and may ride
   the same round; neither blocks on its own.
3. Comment/docblock round, no behaviour: **F-4** (test docblock), **F-5** (scope the
   "whole page" claim; un-mock one settlement component), **W-4** (record the
   box-mirrors-the-PDF / page-is-internal split in `DocumentTotals.tsx:91-106`),
   **F-9**.
4. Recorded for the owner / other lanes, not for this lane: **F-6** (append the closure
   risk to `docs/superpowers/tickets/2026-08-05-l4-web-followups.md`), **F-7** (fr/ar
   rendering scan next time), **F-8** (ticket: VAT-derivable credit-note modal reachable
   from a proforma invoice + `parseFloat` money + the owner question of whether a credit
   note may be raised against a never-sealed invoice), **W-8** (§2.4 wording → "read/
   rendering surface"; the edit screen is the sanctioned pre-posting VAT check), and the
   conventions gate's own W-7 (mount-or-delete `CreditNoteDetail`).

**VERDICT: spec ✅ + quality CHANGES-REQUESTED — merge-blocking: YES (condition 1 only).**

One-line fix-before-merge: allowlist `ProformaResourceTest` in `ci.yml:1025` (+ S-14 leg);
everything else is comment-level or another lane's ticket.

---

## r1 scoped re-review — fix commit `088cb9d74`

Scope: F-1, F-2, F-3, F-6 only. READ-ONLY re-review against
`.worktrees/sc-f0w-web-proforma/.superpowers/sdd/PLAN/review-79af6c4e3..088cb9d74.diff`
and `.superpowers/sdd/PLAN/task-4-report.md`.

**VERDICT: ALL ADDRESSED.**

- **F-1 — ADDRESSED.** `ProformaResourceTest` added to the `backend-test-pgsql`
  `--filter` regex (`.github/workflows/ci.yml`, allowlist block ~`:1053`), with a
  dedicated comment (`:1029-1046`). Independently confirmed the job's real trigger,
  `.github/workflows/ci.yml:588`:
  `if: github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || github.base_ref == 'dev' || (github.event_name == 'push' && github.ref == 'refs/heads/main')`
  — fires on PR→dev, PR→main, push→main, workflow_dispatch; genuinely **not** on
  `push→dev`, exactly as the new comment states (S-14 leg correctly flagged as owed,
  not claimed fixed). Manifest ceiling raise (`apps/api/tests/feature-lane-manifest.json`:
  `Document.classes` 88→89, `gated_ceiling` 1185→1186) is a legitimate single-class
  delta. Independently re-ran `php tools/feature-lane-manifest-check.php` in
  `apps/api` — **PASS**: "1439 Feature classes in 74 groups; every group has a
  disposition; every declared lane is present in ci.yml; every `--filter` entry is
  anchored and uniquely matched." Spot-checked `ProformaResourceTest.php:68-123`:
  2 `#[DataProvider]` methods × 9 shapes + 2 standalone = 20 tests, matching the
  fix report's claim.

- **F-2 — ADDRESSED.** `apps/web/src/features/documents/components/DocumentTotals.tsx:62-68`
  now states the true reason (payload carries `subtotal`/`tax_amount` unconditionally;
  disabling the query only stops the per-rate breakdown fetch), matching the gate's
  directive.

- **F-3 — ADDRESSED, fail-closed direction confirmed correct.** `invoice.is_proforma !== false`
  (`InvoiceDetailPage.tsx:432`) and `creditNote.is_proforma !== false`
  (`CreditNoteDetailPage.tsx:143`). `is_proforma?: boolean` remains optional
  (`types/document.ts:100`, `types/creditNote.ts:90`, left as-is per the gate's own
  instruction); every real producer sets it as a non-nullable bool
  (`DocumentData.php:49,279`). So an omitted/`undefined` field now evaluates
  `undefined !== false → true` → treated as proforma → degrades toward suppressing
  VAT/seal chips ("print less"), never toward falsely claiming a seal. Correct
  fail-safe direction for the property F-3 was raised against.

- **F-6 — ADDRESSED as an acceptable deferral**, per the gate's own fix directive
  ("No code change in this lane"). N7 entry appended to
  `docs/superpowers/tickets/2026-08-05-l4-web-followups.md` accurately recording the
  closure-risk (document-scale server figures vs. company-scale client formatting)
  and the `CreditNoteDetail.tsx` divergence. Matches the gate's own ruling, not a
  scope dodge.

**New Critical/Important breakage introduced by this fix diff: none found.** The
diff is CI config + manifest ceiling + two comment corrections + two one-line
`!== false` changes (behavior-preserving on every real payload, per
`DocumentData::$is_proforma` non-nullable bool) + one ticket-file addition.

**Open items: none within F-1/F-2/F-3/F-6 scope.** (Out of this scope, per the fix
report: the SPEC §2.4 wording narrowing (W-8) and the W-4 ruling note could not be
applied because `SPEC-document-lifecycle-dimensions.md` and
`docs/sessions/session-C-lifecycle-2026-08-24/` do not exist in this worktree —
that is an owner/orchestrator routing item, not a re-review finding.)
