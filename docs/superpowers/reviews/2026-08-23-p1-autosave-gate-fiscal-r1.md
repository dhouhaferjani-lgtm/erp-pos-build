# Adversarial merge gate — round 1, FISCAL lens
## Lane `fix/p1-autosave-route-hardening` — `POST /api/v1/documents/auto-save` hardening

- **Worktree reviewed:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/p1-autosave`
- **Commits:** `d3dc9122b` (RED) → `062464776` (GREEN) → `29aa8f169` (inherited-red repair) → `12b8b0970` (blast-radius notes), base local-dev `fa807a699`
- **Ticket:** `docs/superpowers/tickets/2026-08-22-lineless-authoring-paths-hardening.md` §1 (path #1)
- **Lens:** document numbering, draft-editability vs fiscal immutability, fraud-detection event stream
- **Reviewer posture:** read-only in the worktree; every claim below cites a file:line I opened.

---

## 0. Summary

What the lane claims to have shipped is real, is red-first verified, and is a strict
improvement on a genuinely dangerous endpoint. I found **no defect introduced by this
lane** and **no rule-8 event violation**.

I am nonetheless returning CHANGES-REQUIRED, on the record rather than the code:
two of the lane's own written justifications for *leaving fiscal defects open* are
factually refuted by precedent in the same module (F2, F3), one new production comment
contains two false statements (F5), the residual register that two committed code
comments point at is **gitignored** and carries a live P1 (F6), and the number-burn
pinning test does not actually pin the sequence value (F4). F1 is a real
fiscal-hash-input correctness defect sitting on the exact creation path this lane
hardened, and its 3-line fix also clears the inherited red the lane declined.

---

## 1. Independent re-derivation: number burning

Confirmed, end to end, from the code:

1. `DraftPersistenceService::createNewDraft()` allocates a number **before** the row
   exists — `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:130-134`
   (`$this->numberingService->generateNumber(tenantId:…, companyId:…, type:…)`).
2. `DocumentNumberingService::generateNumber():19-22` → `generateForKey():29-40` →
   `generateForKeyOnce():42-68`, which `lockForUpdate()`s the **single**
   `document_sequences` row keyed `(company_id, type, year)` (`:47-51`) and writes
   `last_number + 1` (`:63-64`).
3. There is **no draft-scoped and no separate fiscal sequence**: `DocumentNumberingService`
   exposes only `generateNumber` / `generateForKey` / `getCurrentNumber`, all on
   `DocumentSequence` (`apps/api/app/Modules/Document/Domain/DocumentSequence.php:24`);
   `grep -rn "fiscal_sequence|FiscalSequence" app/` returns nothing.
4. That draft-time value is the one sealed into the chain, unchanged:
   `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:480-485`
   feeds `FiscalHashService::serializeForHashing()` whose payload is
   `document_number|posted_at|total|currency`
   (`apps/api/app/Modules/Fiscal/.../FiscalHashService.php:56-63`). Nothing renumbers at
   posting — `DocumentPostingService` only *reads* `$document->document_number` (`:481, :522, :542`).

**Containment claim — SOUND, with one correction (F3).** After the gate, the only
principals that can burn a number through this route hold `documents.update`. Verified
against the seeder: `manager` (`RolesAndPermissionsSeeder.php:547`), `cashier` (`:650`),
`operator` (`:752`), `accountant` (`:783`); `viewer` (`:682-721`) and `technician`
(`:722-746`) do **not** hold it. Verified there is no weaker sibling *inside this module*:
every other `Route::post` in `apps/api/app/Modules/Document/Presentation/routes.php`
carries a type-specific `can:` (`:79, :112, :149, :233, :263, :306, :328, :381` …), and
`/documents/auto-save` (`:51-53`) was the only ungated writer. Verified the guarded
service has exactly one caller: `grep -rn "DraftPersistenceService" app/` →
`DraftController.php:10,:41` only.

**Remaining (acknowledged, type-blind) gap:** `documents.update` admits a `cashier` —
who holds `invoices.create` (`:653`) but **not** `purchase-orders.create` or
`credit-notes.create` — to author a `purchase_order` or `credit_note` draft and burn a
number out of those sequences. The lane records this as its Residual 4. Strict
improvement over base (where *any* authenticated user could), so not a blocker, but see F6
about where that residual lives.

**Pinning test — see F4.** It asserts the sequence *row does not exist*, not that
`last_number` is unchanged.

---

## 2. Editability predicate — VERIFIED CORRECT

- The guard: `DraftPersistenceService::assertDraftEditable():100-113`, called only from the
  update branch at `:88`, inside `DB::transaction` (`:68`) and **before** `updateDraftLines()`
  (`:91`). Nothing is written on refusal.
- `Document::isDraft():513-516` is an exact match on `DocumentStatus::Draft`, and
  `DocumentStatus` has exactly six cases (`DocumentStatus.php:9-14`). Therefore
  **Confirmed, Posted, Paid, Received and Cancelled all fall through to
  `statusIsNotDraft()`** — every non-draft state is refused.
- `Document::isFiscallyImmutable():561-564` delegates to `FiscalStatus::isImmutable():16-19`,
  and `FiscalStatus` has only Draft/Sealed/Voided (`:9-11`) — so `!isFiscallyImmutable()`
  is *identical* to `=== FiscalStatus::Draft`, i.e. exactly as strict on that axis as
  `POSAccountChargeDraftService::assertExistingDraftMatches():117`.
- **`is_historical` / ArAp opening documents:** `ArApOpeningService.php:298-310` writes
  `status => DocumentStatus::Posted`, `fiscal_status => FiscalStatus::Draft`,
  `is_historical => true`. So they are **not** caught by the fiscal arm (their fiscal_status
  is Draft) but **are** caught by the status arm (Posted). Auto-save cannot touch them.
  Correct outcome, reached by the status check — worth knowing, because a fiscal-only guard
  would have missed them.
- **Precedent sites — checked, and the lane's characterisation is accurate enough:**
  `DraftPurchaseOrderService::appendLines():82` (`status !== DocumentStatus::Draft`),
  `POSAccountChargeDraftService:113` (status) `+:117` (`fiscal_status !== FiscalStatus::Draft`),
  `CorrectingEntryService:108` and `:247` (status). None calls `isDraft()`/`isFiscallyImmutable()`
  by name — they compare the enums directly — but the *predicate* is the same, and (per the
  FiscalStatus enumeration above) the lane's pair is exactly equivalent to the strictest of them.
  Deliberately **not** `DocumentStatus::isEditable():19-25`, which returns true for Confirmed. Correct.
- Test coverage: Confirmed (`AutoSaveRouteHardeningTest.php:154`), Posted (`:177`),
  Cancelled (`:199`), sealed-Draft (`:220`), each asserting the pre-existing line survives.
  Paid/Received are not covered but are unreachable-by-construction through the same
  exact-match predicate.

**Legitimate flow that edited confirmed documents through auto-save?** See F7 — there is a
divergence with the manual path, but I judge the refusal correct.

---

## 3. Event-stream integrity (rule 8) — CLEAN

`git diff fa807a699..HEAD` touches **no** file under
`apps/api/app/Modules/Document/Domain/Events/`. No event class renamed, restructured or
deleted; no constructor signature changed; no new event introduced.

- `DraftDocumentCreated` is still dispatched with the same eight named arguments
  (`DraftPersistenceService.php:156-165`).
- **The `validated()` narrowing drops nothing that reached an event.** The dropped fields are
  `line_total`, `price_entry_mode`, `discount_percent`, `discount_amount`, `free_quantity`
  (`AutoSaveDraftRequest.php:49-58`). I checked each against the event construction sites:
  `DraftLineModified/V2/V3` (`:432-481`) and `DraftLineAdded/V2/V3` are built from the
  **persisted** `DocumentLine`, and `line_total` is *recomputed* server-side
  (`:415-418` on modify, `:613` in `addLinesBatch`) — never taken from the request.
  `discount_percent`, `discount_amount`, `free_quantity` and `price_entry_mode` appear in
  no event constructor. **No event payload loses a field.**
- **The `partner_id` `ScopedExists` change is data-quality only.**
  `AutoSaveDraftRequest.php:70-74` (`ScopedExists::tenantAndCompany('partners', $tenantId, $companyId)`,
  helper at `app/Shared/Presentation/Validation/ScopedExists.php:28-37`). Before it, a
  foreign-tenant `partner_id` was written to the row and dereferenced through the unscoped
  `Document::partner()` belongsTo at `DraftPersistenceService.php:153-155`, putting a foreign
  tenant's partner name into `DraftDocumentCreated`'s `partnerName` (`:163`). **No event schema
  changed** — only the values that can reach it. Confirmed as the lane's Residual 6 describes.

---

## 4. Residual #1 — independently confirmed, with one correction to the lane's framing

Mechanism confirmed at `DraftPersistenceService.php:181-217`:
`:190` plucks the **server uuids**; `:194-197` collects only incoming lines that HAVE an `id`;
`:200` `array_diff(serverUuids, clientIds)` → **every existing line counts as removed**;
`:203-208` deletes them via `removeLine()`; then `:211-216` takes the
`isset($lineData['id'])` arm, `firstWhere` misses, and **nothing is added** — the `addLine()`
call sits in the `else` arm, reached only when `id` is ABSENT. The FE always sends an id
(`apps/web/src/features/documents/DocumentForm.tsx:295` `id: line.id`, client-minted at
`apps/web/src/features/documents/components/DocumentLineEditor.tsx:328`) and never learns the
server uuids back (`useDraftAutoSave.ts:158` reads only `draft_id` / `saved_at`). Net: the draft
empties on the 2nd auto-save and stays empty. **Confirmed. This is the live production
lineless-draft mechanism.**

**Correction the lane owes its own residual text — the fraud stream is already polluted TODAY.**
`removeLine():488-533` fires `DraftLineRemoved` (`:504-515`) **and** `DraftLineRemovedV2`
(`:518-530`) *before* `$line->delete()` at `:532`. So the deletion burst on the 2nd auto-save
emits real `DraftLineRemoved`/`V2` events **for lines the operator never removed** — right now,
in production, into the fraud-detection stream. It is a one-shot burst, not per-keystroke churn
(from the 3rd save on there are no server lines left to diff). The lane's note ("the churn is a
consequence of one fix option") is correct about the *future* add/remove churn but understates
that **false removal events are already in the stream**. That belongs in the residual text —
it changes the priority calculus for a fraud-detection feature.

`line_count` staleness also confirmed: `DraftController.php:103` reads `$document->lines->count()`
off the relation loaded at `:190`, before the deletes.

**Does it undermine the lane's own tests? — No, but see F8.** The green happy-path
`test_a_permitted_user_can_auto_save_a_new_draft:343-363` sends lines with **no** `id`, so it is a
single-save create through `addLinesBatch` — honest.
`test_the_editors_exact_auto_save_payload_is_accepted:428-456` DOES send a client id, but with
`draft_id: null`, so it also goes down the create branch where `id` is ignored — it passes for a
reason unrelated to the residual, and it is the one save the residual does not break (F8).
The characterisation test `:512-548` is explicitly labelled, asserts the emptying AND the stale
`line_count`, and instructs that the assertion be **inverted, not deleted**, when fixed. That is
honest work.

---

## 5. Inherited red — verified, but mis-diagnosed (see F2)

Ran at HEAD: `tests/Feature/Events/T2EventsV2DualDispatchTest.php` → **1 error / 8**:

```
App\Shared\Exceptions\UnboundCompanyContextException: CurrencyScaleResolver::getScale()
called with no currency code and no CompanyContext bound.
  app/Shared/Infrastructure/CurrencyScaleResolver.php:46
  app/Modules/Document/Domain/Services/DraftPersistenceService.php:592
  app/Modules/Document/Domain/Services/DraftPersistenceService.php:169
  app/Modules/Document/Domain/Services/DraftPersistenceService.php:81
```

**Red-at-base equality: accepted, on code grounds stronger than a re-run.** The failing frame
passes through `:81`, the `if ($document === null)` **create** branch; the lane's only insertion
is `assertDraftEditable()` at `:88`, in the `else` branch. The lane provably added no
context-sensitivity to this path.

But note *what the error says*: "called with **no currency code**". Line `:592` reads
`$this->scaleResolver->getScale($document->currency)` — it already passes an entity currency.
It throws because **`createNewDraft()` never sets one** (F1). See F1/F2.

---

## 6. Red-first spot-verification (my own method)

| Step | Result |
|---|---|
| Class resolution points at the WORKTREE (stale-vendor trap) | `ReflectionClass::getFileName()` → `.worktrees/p1-autosave/apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php` and `…/Domain/Exceptions/DraftNotEditableException.php` ✅ |
| `AutoSaveRouteHardeningTest` at HEAD | **OK (19 tests, 63 assertions)** |
| Same test with the three production files checked out at `fa807a699` | **13 failed / 19** — failures are exactly the 2 authz + 4 status + 7 payload cases |
| Cross-check vs the RED commit's own claim | `d3dc9122b` message says "13 failed, 3 passed"; `git show d3dc9122b:…AutoSaveRouteHardeningTest.php \| grep -c "public function test_"` = **16**. 13 + 3 = 16 ✅ consistent with my 13 of 19 |
| Restore + worktree clean of my changes | `git checkout HEAD -- <3 files>`; `git status --porcelain` shows no modification by me ✅ |

Test-quality: real HTTP, `RefreshDatabase`, real models, `RolesAndPermissionsSeeder`
(`AutoSaveRouteHardeningTest.php:25-27, :51, :84-85`), nothing mocked, no `assertTrue(true)`.
Suite DB is SQLite `:memory:` (`phpunit.xml:44-45`) — no PG-specific aggregate logic in this
lane, so the usual SQLite-masking risk does not bite here.

**Worktree hygiene note (NOT the lane's commits).** `git status --porcelain` was clean when I
started; partway through the review an untracked
`apps/api/tests/Feature/Document/ZzProbeAutoSaveTest.php` (7.5 KB, four `assertTrue(true)`
probes, mtime 15:02) appeared, and `ps` shows other phpunit runs active on this machine. It is
not mine and is in none of the four commits. **Delete it before merge** so an
`assertTrue(true)` file cannot be swept into a commit.

---

## 7. Findings

### F1 [P2] — `createNewDraft()` never sets `currency`; auto-save drafts silently take the DB default `'EUR'`, and `currency` is a fiscal-hash input

**Evidence.**
- `DraftPersistenceService.php:137-150` — the `Document::create([...])` array has **no `currency` key**.
- `database/migrations/tenant/2025_11_30_080000_create_documents_table.php:24` —
  `$table->string('currency', 3)->default('EUR');`
- No `boot()` / `booted()` / `creating()` hook exists on
  `app/Modules/Document/Domain/Document.php` to derive it (grep returns nothing).
- **Every other creator in the module sets it explicitly:** `QuoteController.php:257`
  (`$validated['currency'] ?? $company->currency`), `DraftPurchaseOrderService.php:59`
  (`$company->currency`), `CorrectingEntryService.php:82`, `CreditNoteService.php:877`,
  `ArApOpeningService.php:304`, `POSAccountChargeDraftService.php:60`. `createNewDraft()` is the
  anomaly.
- `currency` is the **4th field of the fiscal hash payload** —
  `DocumentPostingService.php:484` → `FiscalHashService.php:56-63` — and posting does **not**
  re-derive it (contrast `fiscal_category`, which *is* re-derived from the type at
  `DocumentPostingService.php:490-499`, so that one is self-healing).
- Empirically proven by the inherited red's message: `getScale()` "called with **no currency
  code**" at `DraftPersistenceService.php:592` ← `:169` ← `:81`, i.e. `$document->currency` is
  null in-memory immediately after create.

**Why it matters.** For any non-EUR tenant (TND, GBP), a draft authored via
`/documents/auto-save` is persisted with the wrong currency; if that draft is ever confirmed
and posted, `'EUR'` is sealed into the SHA-256 chain input and shown on the document. It also
means the scale used for `line_total` at `:592` is resolved from `CompanyContext` (i.e. the real
company currency) while the row claims EUR — an internally inconsistent record.

**Fix.** `'currency' => $company->currency` in `createNewDraft()`, resolving `Company` by
`$companyId`. In-module precedent for the lookup: `DraftPurchaseOrderService.php:89`
(`Company::query()->whereKey($companyId)->firstOrFail()`) feeding `:59`. ~3 lines. This also
closes F2.

---

### F2 [P2] — the inherited red is a rule-19 conformance fix that IS in scope, and the stated reason for deferring it is refuted by in-module precedent

**Evidence.** The lane's notes §5 say fixing
`T2EventsV2DualDispatchTest::test_draft_line_added_dual_dispatch_includes_v3_with_variant_fields`
"means choosing a currency source for a direct-call test — a precision-contract owner decision,
not this lane's". But:
- `DraftPersistenceService.php:592` **already passes an entity currency**
  (`getScale($document->currency)`); it throws only because F1 leaves that entity currency null.
- The currency source is not an open question — `DraftPurchaseOrderService.php:59` already
  chose `$company->currency` for a draft in this very module, and
  `QuoteController.php:257` chose `$company->currency` as the fallback for the same document type.

**Why it matters.** Rule 19 says queued/console/context-free callers must pass the entity
currency. The reason this one still throws is a missing entity currency, not an unresolved
policy question — so the red is carrying a *production* defect (F1), not a test-harness quirk,
and the honest write-up must say so. Leaving the red is defensible; the *reason of record* is not.

**Fix.** Take F1 (which turns this test green as a side effect), or amend §5 to say the red is
caused by `createNewDraft()` not setting `currency` and is deferred with F1.

---

### F3 [P2] — the number-burning "no alternative exists" conclusion is contradicted by an in-module deferred-numbering precedent

**Evidence.** Notes §3 concludes number-at-draft-creation is "module-wide convention" and that
changing it "means redesigning numbering (allocate at confirm/post; or a draft placeholder
series; …) — out of this lane". But `POSAccountChargeDraftService.php:49-73` creates a
`DocumentStatus::Draft` / `FiscalStatus::Draft` **Invoice on the same `documents` table** with
`'document_number' => null` (`:57`), and `assertExistingDraftMatches():125-127` asserts that a
matching existing draft still has `document_number === null`. A null-numbered draft is therefore
already legal in the schema and already shipping.

**Why it matters.** This paragraph is the justification of record for leaving a fiscal
number-burning defect open. As written it says "we cannot do better without a redesign"; the
truth is "there is a precedent, and we chose not to follow it in this lane". Those are different
statements to whoever picks the follow-on ticket. (The re-derivation itself — §1 above — is
otherwise accurate, including the ticket's `:142` line-drift correction.)

**Fix.** Amend §3 to cite `POSAccountChargeDraftService.php:57` and restate the deferral as a
scope choice, not an impossibility.

---

### F4 [P3] — the number-burn pinning test asserts "no sequence row exists", not "the sequence row is untouched"

**Evidence.** `AutoSaveRouteHardeningTest.php:140-147` asserts
`DocumentSequence::where('company_id', …)->where('type', …)->count() === 0`.

**Why it matters.** It pins the right *outcome* today only because nothing else in that test
creates an invoice. It never reads `last_number`, and it goes red for the wrong reason the moment
sequence rows are pre-seeded at company creation — which is exactly the kind of change that makes
a fiscal pin quietly stop pinning.

**Fix.** Pre-create the sequence row at a known `last_number` (e.g. 7) in the test, then assert
`last_number === 7` after the 403. Two lines, and it survives seeding changes.

---

### F5 [P3] — two load-bearing claims in the new route comment are factually wrong

**Evidence.** `apps/api/app/Modules/Document/Presentation/routes.php:44-45` states
`documents.update` is "the module's cross-type document-write permission (the same one
`documents.revert` and the additional-cost writes use)".
- `documents.revert` — true (`routes.php:62`).
- the additional-cost writes — **false**: `routes.php:350, :354, :358` all use
  `can:purchase-orders.update`.
- and the permission's own seeded annotation is
  `'documents.update',  // Document attachments (Media module)`
  (`database/seeders/RolesAndPermissionsSeeder.php:121`) — its documented semantic is
  *attachments*, not document authoring.

**Why it matters.** The choice is still the best available one and I do not object to it, but a
comment that overstates its precedent is exactly what a future reader will cite when widening the
gate. The seeder annotation is now stale in the opposite direction.

**Fix.** Drop the additional-costs clause from `routes.php:44-45`; update
`RolesAndPermissionsSeeder.php:121` to note the permission now also gates draft authoring via
`/documents/auto-save`.

---

### F6 [P3] — the residual register that two committed code comments point at is gitignored, and it carries a live P1

**Evidence.** `routes.php:50` and `AutoSaveRouteHardeningTest.php:510` both cite
`docs/sessions/2026-08-23-p1-autosave-hardening-notes.md`.
`git check-ignore -v docs/sessions/2026-08-23-p1-autosave-hardening-notes.md` →
`.gitignore:58  docs/sessions/`. The file is untracked and will not travel with the branch. The
notes acknowledge this in their own header, which makes it a known-and-shipped-anyway condition.

**Why it matters.** Residual 1 is a **live P1 production defect** (drafts empty themselves; false
`DraftLineRemoved` events reach the fraud stream — §4 above). Right now its only durable record is
a docblock in a test. Two production-code comments will point at a path that does not exist for
anyone who clones the branch.

**Fix.** Move the residual register to a tracked path — `docs/superpowers/tickets/` alongside the
parent ticket — and repoint `routes.php:50` and `AutoSaveRouteHardeningTest.php:510` at it. File
Residual 1 as its own ticket.

---

### F7 [P3] — the auto-save editability predicate now diverges from the manual-edit path; the divergence is untested and unrecorded

**Evidence.** Auto-save refuses Confirmed. The manual PATCH path **accepts** it:
`InvoiceController.php:415`, `QuoteController.php:341`, `SalesOrderController.php:324`,
`PurchaseOrderController.php:510` all gate on `Document::isEditable()` (`Document.php:577-580`),
which is true for Confirmed via `DocumentStatus::isEditable():19-25`.
On the FE, the **Edit button** is gated on `document.status === 'draft'`
(`apps/web/src/features/documents/components/DocumentActions.tsx:102`,
`components/DocumentActionBar.tsx:127`) — I confirmed the lane's claim — but the **edit route is
not**, and `useDraftAutoSave` is wired unconditionally: `enabled: true`,
`existingDraftId: id || undefined` (`DocumentForm.tsx:319-325`). A hand-typed
`/invoices/{id}/edit` on a Confirmed document therefore now produces a permanent
`autosaveFailed` (`useDraftAutoSave.ts:176-186`) → `shouldWarn` navigation prompt
(`DocumentForm.tsx:344-348`), while manual Save still succeeds.

**Why it matters.** The refusal itself is **correct** — replacing a whole line set from an
implicit background POST is categorically more dangerous than an explicit PATCH, and it is the
ticket's harm. But an operator on that (reachable) URL now gets a stuck "unsaved changes" warning
with no explanation, and no test covers the interaction.

**Fix.** Record the divergence in the residual register, and gate the hook:
`enabled: !isEditing || document?.status === 'draft'` in `DocumentForm.tsx:322`.

---

### F8 [P3] — the "editor's exact payload" test pins only the FIRST save, the one save Residual 1 does not break

**Evidence.** `test_the_editors_exact_auto_save_payload_is_accepted:428-456` posts
`'draft_id' => null` with the client-minted id `'line-1756000000000-a1b2c3d4e'` (`:441`). With a
null `draft_id` the call takes the create branch (`DraftPersistenceService.php:79-81`) →
`addLinesBatch()` (`:169`), which never reads `id` — so it passes for a reason unrelated to the
contract it is advertised to pin, and the realistic editor sequence (save → save again with the
same client ids) is never exercised end to end through the endpoint. The characterisation test
(`:512-548`) instead seeds a server line via the `documentWithOneLine()` helper.

**Why it matters.** Not dishonest — the characterisation test does capture the mechanism, and its
docblock is explicit. But a two-POST characterisation would be the faithful pin and would
additionally demonstrate the whole ticket thesis: a document authored **entirely** through this
endpoint ends lineless, with a burnt number.

**Fix.** Add a two-POST case: POST (no `draft_id`) → capture `draft_id` → POST again with the same
client-minted line ids → assert 0 lines and one burnt sequence number.

---

## 8. What to fix before merge

Blocking: **F5** (correct the two false claims in `routes.php:44-45` and the stale seeder
annotation) and **F6** (move the residual register to a tracked path and repoint the two code
comments; file Residual 1 as a ticket). Correct the record for **F2** and **F3** (both are
one-paragraph edits refuted by `DraftPurchaseOrderService.php:59` and
`POSAccountChargeDraftService.php:57`). **F4** is a two-line strengthening of a fiscal pin and
should ride along. **F1** is the one I would actually take in this lane — 3 lines, in-module
precedent, fixes a fiscal-hash-input defect on the exact path being hardened, and turns the
inherited red green as a side effect; if it is deferred instead, it must be added to the
residual register with F2. **F7/F8** are follow-on. Also delete the stray untracked
`ZzProbeAutoSaveTest.php` from the worktree.

VERDICT: CHANGES-REQUIRED
