# Ticket: `POST /documents/auto-save` — residuals left open by the P1 hardening lane

**Filed:** 2026-08-23, by `fix/p1-autosave-route-hardening` at both merge gates' request
(`docs/superpowers/reviews/2026-08-23-p1-autosave-gate-authz-r1.md` §P3-6,
`…-gate-fiscal-r1.md` §F6 — the first round put this register in the gitignored
`docs/sessions/`, where two production-code comments pointed at a path that does
not exist for anyone who clones the branch).

**Parent ticket:** `docs/superpowers/tickets/2026-08-22-lineless-authoring-paths-hardening.md` §1
(path #1 — closed by that lane: `can:documents.update` + per-type `*.create`,
draft-only status guard with `lockForUpdate()`, `AutoSaveDraftRequest`,
company currency on the authored row, null-safe partner).

**Citation convention (gate R2-2).** Anything inside a file the auto-save lane
edits — `DraftPersistenceService`, `DraftController`, `AutoSaveDraftRequest`,
`Document/Presentation/routes.php` — is cited by SYMBOL, never by line: round 2
found every line number the previous revision wrote had drifted by 15 the moment
those same files grew. Line numbers survive only for files this lane does not
touch, and every one of them was re-checked against the tree.

---

## R-1 [P1, LIVE] — the editor's client-minted line ids empty the draft, and false `DraftLineRemoved` events are already reaching the fraud stream

**This is the mechanism by which `/documents/auto-save` actually authors lineless
documents in production** — not the empty `lines` array the parent ticket
describes.

`DocumentLineEditor.tsx:328` mints `line-<epoch>-<rand>` ids for unsaved lines;
`DocumentForm.tsx:295` forwards them; the response carries only `draft_id` /
`saved_at` / `line_count` (`DraftController::autoSave()`'s success body), so the editor **never
learns the server uuids back**. From the SECOND auto-save on,
`DraftPersistenceService::updateDraftLines()`:

1. it plucks the server uuids off `$document->lines`, then collects the incoming
   client ids (only the entries that HAVE an `id`);
2. `array_diff(serverUuids, clientIds)` → **every existing line counts as
   removed**, and each is passed to `removeLine()`;
3. it then takes the `isset($lineData['id'])` arm for each incoming line,
   `firstWhere` misses, and **nothing is added** — the `addLine()` call sits in
   the `else` arm, reached only when `id` is ABSENT.

Net: the draft empties on the 2nd save and stays empty, with its number spent.

**Correction the fiscal gate added (F-gate §4), and the reason this is P1 rather
than P2:** the pollution is **not** hypothetical or contingent on a future fix.
`DraftPersistenceService::removeLine()` fires `DraftLineRemoved` **and**
`DraftLineRemovedV2` *before* its own `$line->delete()`. So the deletion burst on the 2nd auto-save
emits real removal events **for lines the operator never removed**, into the
fraud-detection stream, **today, in production**. It is a one-shot burst per
document (from the 3rd save on there are no server lines left to diff), not
per-keystroke churn — but it is already there, and it changes the priority
calculus for a fraud-detection feature.

**Pinned by** (must be INVERTED, not deleted, when fixed):
- `AutoSaveRouteHardeningTest::test_characterisation_client_minted_line_ids_empty_the_draft`
- `…::test_characterisation_two_editor_saves_end_lineless_with_a_burnt_number`
  — the whole thesis driven only through the endpoint: two POSTs, zero lines, one
  burnt number.

**Not fixed in the P1 lane** because the repair is a contract decision spanning
`apps/web` and this endpoint:
- (a) echo the server line ids back in the auto-save response and have the editor
  adopt them; or
- (b) treat an unmatched `id` as a new line — which then churns a
  `DraftLineRemoved` + `DraftLineAdded` pair per line per keystroke through the
  same fraud stream.

That is a design call, not a clean guard.

---

## R-2 [P2] — numbering: deferred allocation IS viable; the P1 lane's "no alternative" conclusion was wrong

The P1 lane's first-round notes concluded that number-at-draft-creation is
"module-wide convention" and that changing it "means redesigning numbering …
out of this lane". **The fiscal gate (F3) refuted the premise and it is corrected
here for the record.**

What is true (re-derived and confirmed by the gate):
- `DraftPersistenceService::createNewDraft():141-145` allocates before the row
  exists, via `DocumentNumberingService::generateForKeyOnce():42-68`, which
  `lockForUpdate()`s the single `document_sequences` row keyed
  `(company_id, type, year)`.
- There is no draft-scoped and no separate fiscal sequence
  (`grep -rn "fiscal_sequence|FiscalSequence" app/` → nothing).
- The draft-time value is the one sealed into the chain unchanged:
  `DocumentPostingService.php:481` → `FiscalHashService::serializeForHashing()`
  (`document_number|posted_at|total|currency`). Nothing renumbers at posting.

What was **wrong**: an in-module precedent for a null-numbered draft already
exists and already ships. `POSAccountChargeDraftService.php:49-73` creates a
`DocumentStatus::Draft` / `FiscalStatus::Draft` **Invoice on the same `documents`
table** with `'document_number' => null` (`:57`), and
`assertExistingDraftMatches():125-127` asserts a matching existing draft still
has `document_number === null`. A null-numbered draft is therefore already legal
in the schema.

So deferred numbering (allocate at confirm/post) is a **viable follow-on design
lane**, not an impossibility. The P1 lane's deferral was a scope choice.

Whoever takes it owns: the sequence-gap audit story, the UI's "what do we show
before a number exists" question, and every consumer that assumes
`document_number` is non-null on a draft.

---

## R-3 [P2] — the auto-save editability predicate now diverges from the manual PATCH path

Auto-save refuses `Confirmed`. The manual PATCH path **accepts** it:
`InvoiceController.php:415`, `QuoteController.php:341`,
`SalesOrderController.php:324`, `PurchaseOrderController.php:510` all gate on
`Document::isEditable()` (`Document.php:577-580`), which is true for `Confirmed`
via `DocumentStatus::isEditable():19-25`.

The auto-save refusal is **correct** — replacing an entire line set from an
implicit background POST is categorically more dangerous than an explicit PATCH,
and it is the parent ticket's harm. But the divergence is now real, pre-existing
on the PATCH side, and **needs its own ruling**: should a confirmed document be
line-editable at all through PATCH?

Related FE consequence, same root: the Edit **button** is gated on
`document.status === 'draft'` (`DocumentActions.tsx:102`,
`DocumentActionBar.tsx:127`) but the edit **route** is not, and the hook is wired
unconditionally — `enabled: true`, `existingDraftId: id || undefined`
(`DocumentForm.tsx:319-327`). A hand-typed `/invoices/{id}/edit` on a confirmed
document now produces a permanent `autosaveFailed`
(`useDraftAutoSave.ts:176-186`) → a stuck "unsaved changes" navigation prompt
(`DocumentForm.tsx:344-348`), while manual Save still succeeds.

**Suggested FE fix:** `enabled: !isEditing || document?.status === 'draft'` at
`DocumentForm.tsx:322`. Deliberately not taken in the P1 lane — it is an
`apps/web` change and the lane touched no frontend file.

---

## R-4 [P3] — `line_count` in the auto-save response is stale

`DraftController::autoSave()` returns `$document->lines->count()` off the relation
collection loaded **before** the deletes in `updateDraftLines()`. The endpoint
answers `line_count: 1` while the database holds `0`. Pinned as an explicit
assertion inside the R-1 characterisation test. Trivial in isolation
(`$document->load('lines')` on the update branch) but it is a response-contract
change and belongs with R-1.

---

## R-5 [P3] — absent `lines` is treated as "delete every line"

`DraftPersistenceService::updateDraftLines()` does `$data['lines'] ?? []`. A payload that omits
`lines` entirely wipes the line set, exactly like `lines: []`. The current
frontend always sends `lines`, so nothing is broken today, but "absent" and
"empty" should not mean the same thing on a PATCH-shaped endpoint. Not changed in
the P1 lane because distinguishing them is a contract change, not a guard.

---

## R-6 [P3] — the blanket `catch (\Throwable) → 200` still hides real failures, and mints a phantom `draft_id`

`DraftController::autoSave()`'s blanket arm answers `200 {"error":"silent_failure"}` for any
exception other than `DraftNotEditableException`, **and mints a fresh random
`draft_id`** (`Str::uuid()`) when none was sent. The hook stores that id
(`useDraftAutoSave.ts:167`) and returns it on the next save, where it matches no
row — so a second draft is authored, with a second number burned.

The P1 lane fixed the one failure mode that was actually being hit in the editor
(null partner, gate P1-1 — `$partner?->name`), and left the arm itself intact as
scope discipline. The arm is the endpoint's stated design ("auto-save failures
should be silent from the user's perspective"), so changing it needs a decision,
not a patch.

---

## R-7 [P3] — an unresolvable non-null `draft_id` authors a new document instead of 404ing

Tenant isolation **holds** — the lookup is tenant+company scoped
(the fetch in `saveDraft()`) and the foreign document is never read or
mutated. But the miss falls through to the `$document === null` arm of
`saveDraft()`, which calls `createNewDraft()`, so a
cross-tenant `draft_id` returns **200 with a different `draft_id`** and burns a
number in the caller's own tenant. Given the parent ticket's threat model is
"burn numbers out of the fiscal sequence", an unresolvable non-null `draft_id`
should refuse (404 / 422 `DRAFT_NOT_FOUND`) rather than author.

Now pinned (behaviour characterised, not endorsed) by
`AutoSaveRouteHardeningTest::test_characterisation_a_foreign_tenant_draft_id_authors_a_new_document`,
which also closes the gate's observation that the endpoint's strongest tenancy
property had no test at all.

---

## R-8 [P3] — rule-19 float casts on the change-detection path

`DraftPersistenceService::modifyLine()` compares money/quantity with `(float)`
casts:

```php
if (isset($newData['quantity']) && (float) $newData['quantity'] !== (float) (string) $line->quantity) {
```

A precision-contract violation on the very service the P1 lane hardened, but
untouched by it and outside its declared scope. Flagged by the authz gate (P3-9)
so it is not lost.

---

## R-9 [P3] — a validation 422 is invisible to the operator

A quantity typed with 5+ decimals now correctly 422s (rule 19), but
`useDraftAutoSave.ts:176-186` surfaces any failure as a generic `autosaveFailed`
with a `console.error` and no field-level message — the operator sees auto-save
stop with no explanation. Frontend follow-up.

---

## R-10 [P2] — `Document`'s `partner_id` / `partner` annotations are stale, hiding 11 latent null-dereferences

`documents.partner_id` was made nullable on 2026-06-27
(`2026_06_27_110000_make_documents_partner_id_nullable.php:26` — "expense
documents do not always have a known vendor"), and the live PG schema confirms
`is_nullable = YES`. The model's docblock was never updated:
`Document.php:44` still says `@property string $partner_id` and `:89`
`@property-read Partner $partner`.

Discovered while fixing gate P1-1: PHPStan rejects BOTH honest formulations of a
null-aware partner read — `$document->partner?->name` as `nullsafe.neverNull` and
`$document->partner_id !== null` as `notIdentical.alwaysTrue`. The auto-save fix
therefore narrows `getRelationValue('partner')` (honestly typed `mixed`) with
`instanceof` instead; see `DraftPersistenceService::createNewDraft()` for the reasoning
in place.

**Correcting the two annotations is the right fix**, but doing it in the P1 lane
takes `./vendor/bin/phpstan analyse app/Modules/Document/` from `[OK] No errors`
to **11 errors**, i.e. it trips the CI gate.

**Count RE-VERIFIED in a scratch edit** (gate R2-5 could not reproduce it
read-only, and rightly refused to take it on trust). Method: apply the two
annotation corrections to `Document.php`, run PHPStan with `--error-format=json`,
read `totals.file_errors`, revert. Result — **11**, across 7 files:

| n | file | lines |
|---|---|---|
| 2 | `Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php` | `:581`, `:785` |
| 1 | `Domain/Services/DeliveryNoteService.php` | `:225` |
| 2 | `Domain/Services/DocumentPostingService.php` | `:524`, `:556` |
| 1 | `Domain/Services/PurchaseOrderService.php` | `:114` |
| 1 | `Domain/Services/ReturnNoteService.php` | `:779` |
| 2 | `Domain/Services/SalesOrderService.php` | `:241`, `:255` |
| 2 | `Presentation/Controllers/CreditNoteController.php` | `:430`, `:431` |

Nine of the eleven are the same shape: a `string|null` `partner_id` passed into
an event constructor that declares `string`. Rule 8 freezes those signatures, so
each needs a real decision (guard at the call site, or a V-next event) rather
than a signature widening — e.g. `SalesOrderService.php:241, :255` feeding
`SalesOrderConfirmed` / `SalesOrderConfirmedV2`. The other two,
`CreditNoteController.php:430-431`, are plain unguarded `$partner->id` /
`$partner->name` on a `Partner|null` — sitting next to a `?->` on the sibling
relation two lines above, which is what makes them look accidental.

**A twelfth error surfaced during that scratch run and was this lane's own** —
`DraftNotEditableException::typeMismatch()` used `$supplied?->value ?? '…'`,
which PHPStan flags as an unnecessary nullsafe on the left of `??`. Fixed in the
lane (explicit `=== null` ternary); it is not part of the 11 and the module is
`[OK] No errors` at the tip.

Whoever takes it: correct `Document.php:44` and `:89` first, then work the 11
sites; it is a self-contained lane.

---

## R-11 [P2] — auto-save's update branch requires `<family>.create` where the sibling PATCH requires `<family>.update`

Gate R2-3. A deliberate choice made in the P1 lane, recorded here because it
needs a ruling rather than silence.

The sibling manual edits gate on `<family>.update` (`quotes.update`,
`orders.update`, `invoices.update`, `purchase-orders.update`) or
`deliveries.edit` for return notes. Auto-save's update branch demands
`<family>.**create**` — the same ability as its create branch.

The lane's no-regression argument only covers one direction: no seeded role holds
`<family>.update`/`.edit` **without** `<family>.create`, so nobody loses access.
The converse is real and unaddressed: a `.create`-only principal may line-replace
an **existing** draft that the matching `PATCH` would refuse them. Concretely,
from the seeder — `cashier` (`quotes.create`, `invoices.create`, neither
`.update`) and `operator` (`invoices.create`, no `invoices.update`).

Why it is not a live hole: it is not FE-reachable (the editor's edit routes are
`<family>.update`-gated), it is not a regression (before the lane the endpoint had
no per-type gate at all), and the type-spoof escape is closed by
`DraftPersistenceService::assertTypeMatches()`.

Why `.create` was chosen: auto-save's characteristic act is AUTHORING — it is what
allocates the document number — and one ability keeps the two branches identical,
which is what makes the "authorized for the claimed type ⇒ authorized for the
actual type" argument hold. The alternative (`.create` to create, `.update` to
update) is defensible too and is the ruling to make.

Recorded in place at `AutoSaveDraftRequest::authorize()`'s docblock.

---

## Corrections of record carried by this ticket

Two conclusions the P1 lane published in round 1 were refuted by the gates and are
corrected above so they do not propagate:

1. **"The inherited `T2EventsV2DualDispatchTest` red is a precision-contract owner
   decision."** WRONG. The fiscal gate (F2) identified the cause as the missing
   `currency` on `createNewDraft()` — `DraftPersistenceService::addLinesBatch()` already
   passed an entity currency and threw only because that entity currency was
   null. Setting `'currency' => $company->currency` in the fix round turned the
   case **green**; `T2EventsV2DualDispatchTest` is now 8 passed / 8. There is no
   remaining inherited red in this lane's blast radius.
2. **"No in-module alternative to number-at-draft-creation exists."** WRONG — see
   R-2 and `POSAccountChargeDraftService.php:57`.
