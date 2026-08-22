# Ticket: the six production paths that can author a zero-line invoice or credit note

**Filed:** 2026-08-22, by the O-26 lineless-posting phase-out lane
(`fix/o26-lineless-posting-phaseout`), at both gates' request (fiscal P2-2,
treasury P3).
**Supersedes the reachability half of:** `docs/superpowers/tickets/2026-08-05-lineless-document-gl-posting.md`
(whose §"Why the L1 lane did not fix it" ¶1 claimed the shape was "not reachable
through the documented API" — that claim is FALSE and this ticket is the
correction of record).
**Related:** repo `docs/handoff/LEDGER.md` row O-26.

## Why this ticket exists

Owner ruling O-26 (2026-08-21) closed lineless-document POSTING: a zero-line
invoice or credit note is refused by the GL pre-flight inside
`DocumentPostingService::post()`'s transaction, before any seal or GL write.
That refusal is a complete stop — nothing lineless can be posted any more.

It is NOT, on its own, a complete fix for *authoring*. The document still gets
created, still burns a document number, and still has to be cleaned up. The
ruling's letter is "refuse at POSTING"; earlier hardening was permitted only
"where it is a clean guard". Three of the six paths below got such a guard in
that lane (marked **DONE**); the other three did not, and are the content of this
ticket.

Line references are pinned to `4a53bf549` unless noted.

## The six paths

### 1. `POST /documents/auto-save` — creates a lineless invoice AND strips lines off a confirmed one ⚠️ **P1**

- `app/Modules/Document/Presentation/Controllers/DraftController.php:60`
- `app/Modules/Document/Application/Services/DraftPersistenceService.php:70-81`, `:142`, `:462-506`

**This is the item that must not defer indefinitely, and it is not primarily
about linelessness.** The route has, together:

- **no `lines` validation at all** — an empty (or absent) lines array is accepted,
  so it will author a document with zero lines;
- **no document-type or status filter** — it will also REPLACE the line set of an
  already-`Confirmed` document with an empty one, which is how an
  authored-correctly invoice becomes lineless after the fact;
- **no `can:` middleware** — unlike every sibling document route, so it is
  reachable by any authenticated user regardless of document permissions;
- and it **consumes a document number** on the way (`DraftPersistenceService:142`).

The combination means an authenticated user with no document permissions can burn
invoice numbers in a fiscal sequence. That is a numbering-integrity and authz
problem in its own right; the lineless document is the symptom that surfaced it.

**Not fixed in the O-26 lane** and deliberately so: adding validation plus a `can:`
gate to an unvalidated route is a behaviour change on an endpoint the editor UI
calls on every keystroke, and it needs its own blast-radius review with the
frontend. It should NOT wait for a convenient lane.

### 2. Order → invoice conversion with `partial=true` and an empty `line_ids`

- `app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:63-78`
  — `'line_ids' => ['sometimes', 'array']`, no `min:1`
- `app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php:399-411`
  — an explicitly-empty selection filters every source line out

**Not fixed.** `min:1` looks like a one-line fix but is not safe blind: an empty
`line_ids` may be interpreted by an existing frontend caller as "all lines", and
`sometimes` vs an explicit `[]` are different requests. Needs the FE contract
checked first.

### 3. Amount-based credit note with `amount: "0"` — ✅ **DONE in the O-26 lane**

- `app/Modules/Document/Presentation/Controllers/CreditNoteController.php:148`
  (the money regex accepts `"0"`) → `CreditNoteService::createCreditNote()`

Fixed by adding the positivity guard that the converter sibling
(`InvoiceToCreditNoteConverter::convertAmountBased()`) already had:
`\InvalidArgumentException` → 422 `VALIDATION_ERROR`. Pinned at HTTP level by
`tests/Feature/Accounting/LinelessCreditNoteAuthoringGuardsTest.php`.

### 4. Full credit of an already-lineless invoice — ✅ **DONE in the O-26 lane**

- `app/Modules/Document/Domain/Services/RefundService.php` `createFullCreditNote()`,
  line-copy loop

The copy loop reproduces the source's lines one for one, so a lineless original
produced a lineless credit note. Refused at creation now. Only legacy invoices can
reach it (no new invoice can be posted lineless), which is exactly why it matters:
a legacy defect must not mint a fresh unpostable document. HTTP-pinned in the same
test class.

### 5. Work order whose only surviving lines are informational — ✅ **DONE in the O-26 lane**

- `app/Modules/Workshop/WorkOrder/Application/Services/WorkOrderTransitionService.php`
  (the `WorkOrderNoLinesException` guard) vs
  `app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:104,127,168`

The guard counted raw WO lines; the mapper SKIPS `is_bundle_informational` lines.
A WO whose only remaining lines were informational bundle children therefore
passed the guard and the adapter created → confirmed → POSTED an invoice with no
lines, with no HTTP step in between. The guard now counts MAPPABLE lines, so the
refusal is `WORK_ORDER_NO_LINES` at the work order — truthful, and in the
operator's own vocabulary — instead of a GL 422 about a document they never
authored. Pinned in
`tests/Feature/Workshop/WorkOrder/InvoiceZeroLineWorkOrderFailsTest.php`.

**Residual, NOT fixed:** the state is producible because removing a bundle HEADER
line leaves its informational children behind — nothing protects the header. That
orphan-children cleanup is a Workshop-module fix and belongs to whoever owns
bundle expansion.

### 6. POS account-charge draft with empty `lineItems`

- `app/Modules/POS/…/POSAccountChargeDraftService.php:74`

**Not fixed.** Untouched by the O-26 lane; the posting refusal covers it. Needs a
POS-side owner to decide whether an empty charge draft should be refusable at
creation or is a legitimate intermediate state.

## Out of scope — by design, do not "fix"

`ArApOpeningService::postBatch()`
(`app/Modules/Document/Application/Services/ArApOpeningService.php:293-315`)
creates lineless historical AR/AP documents directly at `Posted`
(`is_historical = true`, `FiscalCategory::NonFiscal`), bypassing
`DocumentPostingService::post()` entirely. This is intended: an opening balance
has no lines to carry. It writes no journal entry itself, and the opening ledger
that `AccountingOpeningService::postBatch()` writes for it carries
`source_type = 'opening_balance'`, which `AccountingService::documentLedgerFootprint()`
does not match — so these documents never reach the legacy lineless carve-out in
`reverseDocumentGl()` either.

## Also still open (inherited from the 2026-08-05 ticket)

- **Disposition of lineless posted documents already in tenant data.** Owner-side,
  per tenant (LEDGER O-26). Query:
  `documents d LEFT JOIN document_lines dl ON dl.document_id = d.id WHERE d.type IN ('invoice','credit_note') AND d.status = 'posted' AND d.deleted_at IS NULL GROUP BY d.id HAVING COUNT(dl.id) = 0`.
- N-9 (TN chart books rounding residual into `4375` alongside genuine timbre) and
  the FR/Generic `6581`/`7581` backfill — see the 2026-08-05 ticket's
  §"Related, also open".

## Disclosure — evidence-registry line-anchor drift

`ProvisioningRequiredPurposesV1`'s evidence citations are pinned to LINE NUMBERS in
`AccountingService.php`. This lane inserted documentation above those call sites, so
the anchors drifted further: the `createInvoiceGLEntries|CustomerReceivable` citation
reads `AccountingService.php:412`, while the real call site sat at **552** on `dev`
(i.e. the registry was already stale by +140 before this lane) and sits at **612**
after this lane — **+60 deepened here**.

The anchors were deliberately NOT re-derived, per the registry's own no-partial-fix
convention already recorded for the C-5 lane in `docs/handoff/LEDGER.md`: the registry
owner re-syncs from a LIVE scan, and a lane touching one file must not hand-edit its
own anchors and leave the rest stale.

**Verified inherited, count unchanged.** `ProvisioningRequiredPurposesV1ConformanceTest`
+ `ProvisioningRequiredPurposesRegistrationRatchetTest` report **2 failed / 11 passed,
195 assertions** identically at pre-lane `dev` and at this lane's tip. This joins the
C-5 drift note for the registry owner's live-scan resync.
