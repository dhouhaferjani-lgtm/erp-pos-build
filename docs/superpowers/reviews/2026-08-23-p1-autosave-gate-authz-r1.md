# Adversarial merge gate — round 1 (tenancy / authz lens)

**Lane:** `fix/p1-autosave-route-hardening`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/p1-autosave` (reviewed READ-ONLY; left clean)
**Range:** `fa807a699` (local dev base) → `12b8b0970` (4 commits)
**Ticket:** `docs/superpowers/tickets/2026-08-22-lineless-authoring-paths-hardening.md` §1
**Reviewer posture:** verify-don't-trust. Every claim below cites a file:line I read or a command I ran.

---

## 0. Environment provenance (stale-vendor trap cleared)

The worktree carries a real `vendor/` (not a symlink) and a `.env` symlinked to the main checkout.
Class resolution verified before any test was trusted:

```
App\...\AutoSaveDraftRequest      => .worktrees/p1-autosave/apps/api/app/Modules/Document/Presentation/Requests/AutoSaveDraftRequest.php
App\...\DraftPersistenceService   => .worktrees/p1-autosave/apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php
App\...\DraftNotEditableException => .worktrees/p1-autosave/apps/api/app/Modules/Document/Domain/Exceptions/DraftNotEditableException.php
```

All three resolve to the WORKTREE. Tests were run with `CACHE_STORE=array` and by path.
Worktree confirmed clean (`git status --porcelain` empty) after every temporary checkout and after probe deletion.

---

## 1. What the lane got right (verified, not assumed)

| Claim | Verdict | Evidence |
|---|---|---|
| Route group satisfies rule 12 | ✅ | `apps/api/app/Modules/Document/Presentation/routes.php:34` — `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` |
| `can:` evaluates AFTER team context (no wrong-reason 403) | ✅ | `apps/api/bootstrap/app.php:170-176` pins `SetPermissionsTeam` immediately after `AuthenticatesRequests` in the priority list; `Authorize` is unlisted so route-level `can:` keeps its natural post-group position. Empirically confirmed: the permitted user 200s and the viewer 403s in the same suite. |
| `documents.update` is seeded | ✅ | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:121`. **Pre-existing permission — no seeder re-sync needed on existing tenants, so this route carries no silent-403 trap.** |
| Role coverage claim (admin/manager/cashier/operator/accountant hold it; viewer/technician don't) | ✅ | seeder `:541` manager→`:547`, `:646` cashier→`:650`, `:747` operator→`:752`, `:781` accountant→`:783` all grant `documents.update`; `:682` viewer→`:686` grants `documents.view` only; `:722` technician grants neither. Admin gets `Permission::all()` (`:524`). |
| Draft lookup is tenant+company scoped | ✅ | `DraftPersistenceService.php:72-77`. **Attack executed:** tenant-A user POSTing tenant-B's draft uuid — foreign document's lines were untouched (`1` line before, `1` after). No cross-tenant mutation. (Semantics caveat → P3-8.) |
| `ScopedExists::tenantAndCompany` composes BOTH scopes | ✅ | `apps/api/app/Shared/Presentation/Validation/ScopedExists.php:29-37` — `Rule::exists(...)->where('tenant_id',…)->where('company_id',…)` |
| 422 envelope matches the module convention | ✅ | `DraftController.php:107` → `HandlesDocuments.php:298-306` returns `{error:{code,message}}` at 422, identical to `notEditableErrorResponse()` (`:322-328`) |
| `DraftNotEditableException` caught BEFORE the blanket arm | ✅ | `DraftController.php:104` precedes `:114`; PHP matches catch blocks in order. 403s never reach the controller at all (middleware terminates). |
| Blanket `catch (\Throwable) → 200` left intact | ✅ correct scope discipline | `DraftController.php:114-124` unchanged in behaviour; only `\Log`→`Log` and `\Str`→`Str` import hygiene |
| Rule-19 regex ceilings exact | ✅ | `AutoSaveDraftRequest.php:113` qty `{1,4}`, `:114` money `{1,3}`, `:115` percent `{1,2}` with no sign (percent is not currency-scaled — correct) |
| All service-read line fields are declared | ✅ | service reads exactly `id, product_id, service_id, variant_id, description, notes, quantity, unit_price, tax_rate`; all nine have rules (`AutoSaveDraftRequest.php:101-115`). `line_total`/`discount_*`/`free_quantity`/`price_entry_mode` are read by nothing in `addLine()`/`addLinesBatch()`/`modifyLine()` — dropping them is safe. |
| No raw request access survives | ✅ | `grep 'request->'` on `DraftController.php` yields only `:79 $request->user()` and `:85 $request->validated()` |
| FormRequest DI is the canonical pattern, no `app()` | ✅ | `AutoSaveDraftRequest.php:56-60` matches `app/Modules/Coupon/Presentation/Requests/UpdateCouponRequest.php:15-19` |
| DENY path is exercised (not just allow) | ✅ | test `:105` asserts 403 **and** zero documents **and** zero `document_sequences` rows (`:126-148`) — a genuine deny-path test |
| Scope discipline | ✅ | `git diff fa807a699..12b8b0970 --stat` = 7 files: routes, service, controller, FormRequest, exception, 2 test files. No FE files, no ratchet/manifest surfaces, no ticket-file edits. |
| Module gating | n/a | endpoint is not vertical-exclusive; no `module:` guard required |
| Queue/console context | n/a | no job or command touched |

### Red-first honesty — VERIFIED

- **At tip:** `tests/Feature/Document/AutoSaveRouteHardeningTest.php` → **19 passed, 63 assertions**. Matches the claim exactly.
- **At base:** checked the three production files back out at `fa807a699` and re-ran → **13 failed, 6 passed (28 assertions)**. Matches the claimed 13-red pattern exactly.
- The 6 that were already green at base are precisely the six non-hardening regression tests (2 happy paths, empty-lines, editor payload, client-minted id, characterisation). Clean red/green split — no hardening assertion was green before the fix.
- Files restored to `12b8b0970`; worktree clean.

### Inherited-red repair — VERIFIED HONEST

`git show 29aa8f169 --stat` = **1 file, tests only, +7 lines**. The change adds the missing 4th constructor argument
(`app(CurrencyScaleResolverInterface::class)`) to the hand-wired service in `setUp()` — i.e. the **test was moved to match the
current production constructor** (`DraftPersistenceService.php:44-49`), *not* the reverse. Production was not touched.
Independently confirmed the 6 cases were red at base: reverted the test file to `fa807a699` → **6 failed (0 assertions)**,
`ArgumentCountError` at `DraftPersistenceService.php:44`; restored to tip → **6 passed (8 assertions)**.

### Characterisation test for residual #1 — VERIFIED GOOD (item 8)

`AutoSaveRouteHardeningTest.php:485-553`. It exists, it PASSES (pinning the wrong behaviour), the docblock spells out the exact
mechanism, and `:549-551` states *"When this is fixed, this assertion must be inverted, not deleted."* A future fixer cannot
mistake it for desired behaviour. It also pins the second-order defect (`line_count` reports `1` while the DB holds `0`).
This is the standard the rest of the lane should have been held to — see P1-1, which was not given the same treatment.

---

## 2. Findings

### P1-1 — The editor's actual first-keystroke payload (`partner_id: null`) silently saves NOTHING, and the test that claims to pin the editor payload does not pin it

**Evidence.** `DraftPersistenceService.php:153-155`:

```php
$document->load('partner');
$partner = $document->partner;
$partnerName = $partner->name;      // ← null relation when partner_id is null
```

Laravel's error handler converts the resulting `Attempt to read property "name" on null` warning into an `ErrorException`,
which the blanket arm at `DraftController.php:114` swallows into a 200 `silent_failure`. I executed the FE's literal
first-keystroke payload against the tip:

```
[PROBE A null-partner] status=200 body={"draft_id":"37a70295-…","saved_at":"…","error":"silent_failure"}
[PROBE A] documents in db = 0
```

That payload is not hypothetical — it is what the only caller sends: `apps/web/src/features/documents/DocumentForm.tsx:262`
defaults `partner_id: null`, `:286` emits `partner_id: watchedPartnerId || null`, and `:284-299` builds `draftData` as soon as
`effectiveType` is set. Every auto-save before the operator picks a partner authors nothing.

**Why this is P1 and not "pre-existing, out of scope."** The behaviour is pre-existing, but three things make it this lane's
problem. (a) `AutoSaveDraftRequest.php:82-86` *newly and deliberately* declares `partner_id` as `nullable` — the lane codified
as valid a payload the service cannot process. (b) The lane added
`test_the_editors_exact_auto_save_payload_is_accepted` (`:428`) whose docblock (`:416-427`) claims *"The real editor payload,
field for field… This pins that exact shape so the request contract cannot drift away from the only caller."* — but `:433`
sends `'partner_id' => $this->customer->id`. The single field value that is broken is the one the test substitutes away. That
is an unearned green, and it is the kind of false confidence this gate exists to catch. (c) The ticket is *lineless authoring
paths hardening*; a path that returns 200 while persisting nothing is squarely in that family, and the lane demonstrated it
knows the right pattern for such a defect (the residual-#1 characterisation test) but did not apply it here.

**Fix (choose one, do not merge with neither):**
1. Repair it — `DraftPersistenceService.php:155` → `$partnerName = $partner?->name;`. `DraftDocumentCreated.php:27` already
   declares `public readonly ?string $partnerName = null`, so this is a one-character change with no event-contract impact
   (rule 8 safe). Then extend `:428` with a `partner_id => null` case asserting a document **is** created and the response has
   no `error` key.
2. Or, at minimum, characterise it exactly as residual #1 was — a passing test that pins `error === 'silent_failure'` and
   `Document::count() === 0`, with a "must flip when fixed" note — **and** correct the docblock at `:416-427` so it no longer
   claims to pin a payload it does not send.

---

### P1-2 — `documents.update` lets any holder author a `correcting_entry`, defeating the deliberately admin-tier `documents.correct` gate

**Evidence.** `DocumentType.php:48` defines `case CorrectingEntry = 'correcting_entry'`, and `AutoSaveDraftRequest.php:81`
gates `type` with a bare `Rule::enum(DocumentType::class)` — every case is accepted. Meanwhile `routes.php:365-375` gates
**every** correcting-entry route, *including the reads*, on `documents.correct`, with an explicit written rationale:

> "a correcting entry both exposes and writes raw general-ledger accounts and amounts, which is strictly more powerful than
> anything those permissions buy."

I executed it as a user holding only `documents.view` + `documents.update`:

```
[PROBE C correcting_entry] status=200 …
[PROBE C] created doc number = CE-2026-0001
```

A CE-numbered document was authored and a CE sequence number burned by a principal that the module's own ruling says must not
reach correcting entries at all. This is exactly the harm the lane's own route comment (`routes.php:39-42`) names as the
reason the gate exists — left open for the single most privileged document type.

It is *not a regression* (at base, `viewer` could do it too), but it is an incompletely-closed privilege escalation that
survives a P1 hardening whose stated purpose is to close it, and it contradicts an in-repo owner ruling recorded 15 lines
below it in the same file.

**Fix.** Exclude it at the contract boundary — `AutoSaveDraftRequest.php:81`:
`Rule::enum(DocumentType::class)->except([DocumentType::CorrectingEntry])`, plus a deny-path test asserting 422 for
`type: correcting_entry`. (Correcting entries have a dedicated authoring route; they have no auto-save flow to preserve.)

---

### P2-3 — The type-blind gate lets `cashier` and `accountant` author document families they hold no `*.create` permission for, burning their sequences

**Evidence.** `routes.php:46-49` calls the type-blind gate deliberate and asserts *"every seeded role the editor lets in…
holds it."* True — but the converse was not checked. From the seeder:

- `cashier` (`:646-678`) holds `documents.update` but **no** `purchase-orders.*` at all, **no** `orders.create` (only
  `orders.view`, `:651`), and **no** `credit-notes.create`.
- `accountant` (`:781-783`) holds `documents.update` but **no** `quotes.create`, `orders.create`, `invoices.create`
  (only `invoices.post`, `:784`), `credit-notes.create` (only view/post, `:786`) or `purchase-orders.create`.

Executed with a `documents.update`-only principal:

```
[PROBE D purchase_order] status=200 …
[PROBE D] created doc number = PO-2026-0001
```

So the endpoint is a universal document-authoring bypass around the entire per-type `*.create` catalogue, and each bypass
consumes a number from the sequence that feeds the fiscal hash chain.

The lane files this as a residual, but the residual is ~10 lines and the enabling data is already in hand: `type` is now
`required` and enum-validated, and `AutoSaveDraftRequest::authorize()` (`:65-68`) currently just `return true`.

**Fix.** Map `type` → the type-specific create permission in `authorize()` (`quote`→`quotes.create`,
`purchase_order`→`purchase-orders.create`, …), keeping `can:documents.update` on the route as the coarse gate. Add a deny-path
test: a principal with `documents.update` but not `purchase-orders.create` gets 403 on `type: purchase_order`.

---

### P2-4 — The new status guard does not actually serialise: no `lockForUpdate()`, so a concurrent confirm can still have its lines stripped

**Evidence.** `DraftPersistenceService.php:72-77` reads the document with a plain `->find($draftId)` inside
`DB::transaction()`. `config/database.php` sets no isolation level (grep for `isolation|SET TRANSACTION|REPEATABLE READ|
SERIALIZABLE` returns nothing), so PostgreSQL's default **READ COMMITTED** applies. An unlocked `SELECT` under READ COMMITTED
takes no row lock, so:

1. auto-save reads status = `Draft`, `assertDraftEditable()` (`:105-114`) passes;
2. a concurrent session commits `confirm`;
3. auto-save proceeds into `updateDraftLines()` (`:181-227`) and deletes the line set off a now-`Confirmed` document.

That is the precise harm the lane exists to prevent, merely narrowed to a race window.

Both sides of the precedent already exist in-repo, and the lane cites one of them:
- `DraftPurchaseOrderService.php:78` — `Document::query()->lockForUpdate()->find($documentId)`. This is the very method
  (`appendLines()`) that `DraftNotEditableException.php:20-22` names as the pattern being followed; the lane adopted its
  predicate but dropped its lock.
- `InvoiceController.php:591-595` — the confirm path re-fetches with `lockForUpdate()` inside a transaction, commented
  *"Re-fetch with pessimistic lock inside transaction to prevent race conditions."* The confirm side already takes the lock;
  auto-save is the only participant that does not, so the pair does not serialise.

**Fix.** `DraftPersistenceService.php:73` → add `->lockForUpdate()` to the query. One line; composes with both existing
lockers to close the window.

---

### P3-5 — The route comment's precedent claim is factually wrong

`routes.php:44-45` states `documents.update` is *"the same one `documents.revert` and the additional-cost writes use."*
The `documents.revert` half is correct (`routes.php:62`). The additional-cost half is false: all three additional-cost
**writes** are gated on `can:purchase-orders.update` (`routes.php:350`, `:354`, `:358`); only the two additional-cost
**reads** use `documents.view` (`:346`, `:362`). Since this sentence is the load-bearing justification for the permission
choice, it should not overstate the precedent to a future reader.

**Fix.** Drop the additional-costs clause, or replace it with an accurate one.

---

### P3-6 — Every residual pointer aims at a gitignored file

`routes.php:50`, the characterisation docblock at `AutoSaveRouteHardeningTest.php:508`, and the body of commit `29aa8f169`
all direct the reader to `docs/sessions/2026-08-23-p1-autosave-hardening-notes.md`. That path is excluded by
`.gitignore:58` (`docs/sessions/`) and `git ls-files` confirms it is untracked — the file exists only in this session's
working copy. The residual list (including the deferred `T2EventsV2DualDispatchTest` inherited red) is therefore
unreadable to anyone reviewing or fixing this later, which is the whole point of recording a residual.

**Fix.** Move the residual section into the tracked ticket
(`docs/superpowers/tickets/2026-08-22-lineless-authoring-paths-hardening.md`) or into `docs/superpowers/reviews/`, and
re-point the three references.

---

### P3-7 — Misleading test name invites the wrong conclusion

`test_a_client_minted_non_uuid_line_id_is_accepted` (`:463-482`) asserts 200 on a payload that the very next test
(`:512-553`) proves **destroys the draft's entire line set**. Read alone — e.g. by someone grepping for client-id handling —
its name reads as "client-minted ids work." Only the adjacent characterisation test says otherwise.

**Fix.** Rename to something like `…is_accepted_by_the_validator` and add a one-line docblock cross-reference to the
characterisation test.

---

### P3-8 — A foreign-tenant `draft_id` silently authors a new document instead of 404ing

Not a leak — verified: the scoping at `DraftPersistenceService.php:72-77` holds and the foreign document was untouched.
But the miss falls through to `createNewDraft()` (`:79-81`), so the attack returns **200 with a different `draft_id`** and
burns a number in the caller's own tenant:

```
[PROBE B cross-tenant] status=200 body={"draft_id":"01a02eef-…","line_count":0}
[PROBE B] foreign lines remaining = 1        ← isolation intact
[PROBE B] total documents = 2                ← a NEW draft was authored + numbered
```

Given the lane's own threat model is "burn numbers out of the fiscal sequence," an unresolvable non-null `draft_id` should
refuse rather than author. There is also **no test in the suite for the cross-tenant `draft_id` path at all** — the strongest
tenancy property of this endpoint is currently unpinned.

**Fix.** When `$draftId !== null` and the scoped lookup misses, 404 (or 422 `DRAFT_NOT_FOUND`). Add the cross-tenant
regression test.

---

### P3-9 — Informational, pre-existing, out of declared scope

`DraftPersistenceService.php:385` and `:390` compare money/quantity with `(float)` casts
(`(float) $newData['quantity'] !== (float) (string) $line->quantity`). This is a rule-19 violation on the change-detection
path of the very service being hardened. Untouched by this lane and correctly outside its declared scope — flagged so it is
not lost, not as a merge blocker.

Also noted, non-blocking: a quantity typed with 5+ decimals now 422s (correct per rule 19), but
`useDraftAutoSave.ts:180-190` surfaces any failure as a generic `autosaveFailed` with a `console.error` and no field-level
message, so the operator sees auto-save stop with no explanation. An FE follow-up, not a lane defect.

---

## 3. Answers to the eight gate questions

1. **Permission choice** — `documents.update` is seeded, correctly covers admin/manager/cashier/operator/accountant and
   correctly excludes viewer/technician (all verified against the seeder). The `documents.revert` precedent is real; the
   additional-costs precedent is not (P3-5). Middleware ordering is correct — `can:` runs after `SetPermissionsTeam`, no
   wrong-reason 403. But the gate is too coarse in two specific, demonstrated ways (P1-2, P2-3).
2. **Tenancy** — Draft lookup, product/service/variant lookups and the new `ScopedExists` on `partner_id` all compose
   tenant **and** company correctly. Cross-tenant attack executed: no foreign mutation. Semantics and test coverage of that
   path are weak (P3-8).
3. **Status guard** — covers the existing-draft path in all branches (it runs before `updateDraftLines()`, which is the only
   mutation path). 422 + `{error:{code,message}}` is envelope-consistent. But the guard does not serialise: READ COMMITTED
   with no row lock leaves the confirm race open (P2-4).
4. **FormRequest** — rule-19 regexes are exact; all nine service-read line fields are covered; the undeclared-fields
   rationale checks out against the service; `validated()` is used exclusively so nothing unvalidated reaches the service.
   The one legitimate payload that now behaves badly is `partner_id: null` — though the failure is the service's, not the
   validator's (P1-1).
5. **Silent-200 arm** — `DraftNotEditableException` is correctly caught first; middleware 403s never reach the controller;
   leaving the blanket arm is correct scope discipline. ✅ no findings.
6. **Red-first + inherited-red honesty** — both claims independently reproduced and exact (13-red at base / 19-green at tip;
   6-red → 6-green test-only repair). ✅ no findings. This is the strongest part of the lane.
7. **Scope** — clean. 7 backend files, nothing outside the declared surface. ✅ no findings.
8. **Characterisation test for residual #1** — exists, passes, unambiguously marked must-flip. ✅ no findings.

---

## 4. What to fix before merge

Close P1-1 (fix `$partner?->name` or characterise it honestly and correct the false test docblock) and P1-2 (exclude
`correcting_entry` from the accepted enum); take the one-line `lockForUpdate()` for P2-4 and the ~10-line per-type
`authorize()` map for P2-3; the P3s can land as a follow-up commit in the same lane.

VERDICT: CHANGES-REQUIRED
