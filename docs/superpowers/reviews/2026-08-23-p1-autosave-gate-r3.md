# Adversarial merge gate — ROUND 3 (final), lane `fix/p1-autosave-route-hardening`

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/p1-autosave` — reviewed READ-ONLY, left clean
**Range verified:** `1bf8d8eba` (round-2 tip) → `0a932c291` (3 commits: `79db8e9f6` RED, `2f7f8f3b8` GREEN, `0a932c291` records)
**Prior rounds:** `…-gate-authz-r1.md`, `…-gate-fiscal-r1.md`, `…-gate-r2.md`. Everything those rounds cleared was NOT re-litigated. This round verifies only R2-1 … R2-5, the runs, and scope.

---

## 0. Environment provenance

`apps/api/vendor/` in the worktree is a real directory (not a symlink to the main checkout); `.env` is symlinked. Tests run with `CACHE_STORE=array`, by path, from the worktree. `git status --porcelain --untracked-files=all` was **empty at start and at end**. My probe was written to and run from the scratchpad — nothing was written into the worktree; the only file I wrote is this review, in the main checkout.

---

## 1. R2-1 [was CRITICAL] — the update branch is now authorized against the PERSISTED type — **CLOSED** ✅

### The guard, read in place

`DraftPersistenceService.php:96-100` — inside `DB::transaction()` (opened `:70`), on the `else` (existing-document) arm, **after** the locked fetch (`:85-91`) and **before** `assertDraftEditable()` (`:109`) and `updateDraftLines()` (`:112`):

```php
} else {
    // Gate R2-1: the request's `type` must describe the document it
    // is aimed at. It is checked FIRST because it is the
    // authorization-carrying one — see assertTypeMatches().
    $this->assertTypeMatches($document, $data);
```

`assertTypeMatches()` at `:149-161`; it throws `DraftNotEditableException::typeMismatch()` (`DraftNotEditableException.php:86-96`, code `DOCUMENT_TYPE_MISMATCH` declared `:39`). `DraftController.php:104-109` catches `DraftNotEditableException` **before** the blanket `catch (\Throwable) → 200` at `:110`, and answers `validationErrorResponse()` (422). Ordering is correct on all three axes: after the lock (so the compare is against a serialised row), before the editability guard, before any mutation. Nothing is written on the refusal path, and the transaction rolls back regardless.

### I re-executed the spoof myself — and made it harder than the lane's version

Probe written to and run from the scratchpad, own tenant/company/seeder fixtures, four cases. The lane's spoof test sends `lines: []`; mine sends the **attacker's own replacement lines**, which is the realistic shape, and additionally pins line identity, the money column, the persisted type, and the sequence table:

```
[PROBE A spoof+attacker lines] status=422 {"error":{"code":"DOCUMENT_TYPE_MISMATCH",
                                "message":"This draft is a invoice document; auto-save was sent quote."}}
                               original line id unchanged, unit_price still 100.000,
                               document type still invoice, document_sequences count = 0
[PROBE B CE draft spoof]       status=422 DOCUMENT_TYPE_MISMATCH, CE line intact
[PROBE C honest counterpart]   status=200 {"line_count":1}, lines really replaced (product swapped)
[PROBE D leniency arm]         in-process saveDraft() with NO `type` key → lines_after = 0
```

Principal for A and B = the round-2 attacker shape exactly: `documents.view` + `documents.update` + `quotes.create` only. A is the case that returned `200 / lines=0` at the round-2 tip. **It is now 422 with nothing mutated and no number burnt.** C proves the guard is not satisfied by refusing everything — the honest update still replaces the line set.

### The CE half of P1-2 is closed structurally, not just by test

To reach `assertTypeMatches()` a request must first pass the validator, which narrows `type` to the seven auto-savable cases (`AutoSaveDraftRequest.php:183`, `Rule::enum(DocumentType::class)->only(self::autoSavableTypes())`). The guard then requires `supplied === persisted`. Therefore **any persisted type outside the seven is unreachable on the update branch**: an honest match is refused by the validator, a mismatch is refused by the guard. `correcting_entry`, `expense`, `income`, `supplier_invoice`, `supplier_credit_note`, `purchase_rfq` drafts are all out of reach. Probe B demonstrates the CE case end-to-end; `CorrectingEntryService.php:56, :71-73` confirms CE drafts really are created `NonFiscal` / `Draft` / `Draft`, i.e. that `assertDraftEditable()` alone would have waved them through.

### The leniency arm — attacked, and it is NOT a live bypass

`assertTypeMatches()` returns early when `type` is absent from `$data` (`:151-153`). I tested the two ways that arm could be live:

**(a) Can the HTTP surface omit `type`?** No.
- `AutoSaveDraftRequest::rules()` `:183` — `'type' => ['required', …]`.
- Pinned by `AutoSaveRouteHardeningTest::test_auto_save_rejects_a_missing_document_type` (`:573-581`), which posts a payload with no `type` and asserts the `type` validation error — green in my run.
- No `prepareForValidation()`, `validationData()` or `passedValidation()` exists on the class (grepped; zero hits), so nothing can strip or fail to populate the key between validation and `validated()`.
- `DraftController.php:85` passes `$request->validated()` straight through to `saveDraft()` at `:91-97`; a `required` key is always in `validated()`.

**(b) Does ANY production caller omit `type`?** No — there is exactly ONE production caller.
- `grep -rn "saveDraft" app/` → the only non-comment hit outside the service itself is `DraftController.php:91`. Every other hit is in `tests/` (`DraftPersistenceServiceTest`, `DraftLineEventV2Test`, `T2EventsV2DualDispatchTest`).
- `grep -rn "DraftPersistenceService" app/` → the only injection site is `DraftController.php:41`.
- `grep -rn "DraftController" app/` → the only mapping is `routes.php:75`; no second route, no job, no console command, no listener reaches it.

So the skip arm is reachable only from the service's own unit tests, exactly as the docblock (`:137-143`) claims. Probe D confirms the arm is genuinely live in-process (a no-`type` call still strips lines) — which is why I record it below as a latent trap (R3-3, informational) — but it is **not** a bypass of the HTTP gate today.

### Records and TDD ordering

Commit stat is clean red→green→records: `79db8e9f6` = 1 file, tests only; `2f7f8f3b8` = 5 files, production only; `0a932c291` = 1 file, the ticket. The RED's reality is independently corroborated rather than taken on trust: round 2's own executed probe recorded `200 / lines=0` for this exact spoof at `1bf8d8eba`, and my probe D reproduces the underlying mechanism (the update branch ignoring `type`) at the current tip via the skip arm.

The claim in `AutoSaveDraftRequest.php:143-147`, `routes.php:57-61` and `DraftNotEditableException.php:41-59` that the per-type gate is real on both branches is now **true**.

---

## 2. R2-2 [was Minor] citation drift — **substantively CLOSED**, two stragglers remain ⚠️ (R3-1)

**Convention change is the right fix.** Everything inside a file this lane edits is now cited by SYMBOL (`InvoiceController::confirm()`, `DraftPurchaseOrderService::appendLines()`, `DraftPersistenceService::removeLine()`, `DraftController::autoSave()`, the `correcting-entries.*` route block, `quotes.store` … `return-notes.store`). Symbol citations cannot drift. The convention is stated where a reader will hit it: `routes.php:63-65` and the ticket's header (`…-autosave-residuals.md:14-19`).

**Spot-checks — I opened each one (7 checked, mixed same-file symbols and cross-file numbers):**

| # | citation | where it now points | ✅ |
|---|---|---|---|
| 1 | `DocumentForm.tsx:263` defaults `partner_id` to null | `apps/web/src/features/documents/DocumentForm.tsx:263` = `partner_id: null` | ✅ (was `:262`) |
| 2 | `DocumentForm.tsx:290` emits `watchedPartnerId \|\| null` | same file `:290` = `partner_id: watchedPartnerId \|\| null` | ✅ (was `:286`) |
| 3 | the seven `*.store` route names + their `can:` | `routes.php:103-105, 136-138, 173-175, 257-259, 287-289, 330-332, 352-354` — all seven names exist and the abilities match `AUTO_SAVABLE_CREATE_ABILITY` exactly | ✅ |
| 4 | `InvoiceController::confirm()` locks | `InvoiceController.php:573`, pessimistic-lock comment `:592` | ✅ |
| 5 | `DraftPurchaseOrderService::appendLines()` locks | `Document/Application/Services/DraftPurchaseOrderService.php:71`, `lockForUpdate()` `:78` | ✅ |
| 6 | cashier block `RolesAndPermissionsSeeder.php:646-679` | block runs exactly `:646`→`:679` | ✅ (corrected from `:646-678`) |
| 7 | `useDraftAutoSave.ts:227` (debounce needs ≥1 line) | `:227` = `const hasMinimalData = data.lines && data.lines.length > 0` | ✅ |

Also re-checked the untouched-file numbers the ticket carries: `DocumentPostingService.php:481` (the `serializeForHashing()` payload — a READ), `Document.php:577-580` (`isEditable()`), and the four `isEditable()` guards at `InvoiceController.php:415`, `QuoteController.php:341`, `SalesOrderController.php:324`, `PurchaseOrderController.php:510` — all four lines are literally `if (! $documentModel->isEditable()) {`. All land.

**The seeder annotation collapse — verified against BASE `fa807a699`:**

```
git diff --stat fa807a699..0a932c291 -- …/RolesAndPermissionsSeeder.php  →  1 file changed, 1 insertion(+), 1 deletion(-)
BASE line count 820   |   lane tip line count 820
```

The whole delta is one in-place comment on `'documents.update'` (`:121`). **Net line delta zero**, so every external `RolesAndPermissionsSeeder.php:N` citation resolves to the same content as at BASE.

**The 8-other-lanes drift claim is real and is resolved.** At the round-2 tip the file was **825** lines and `:590` held `'workshop.technicians.view', 'workshop.technicians.manage'`; at BASE and at the round-3 tip `:590` holds `'workshop.technicians.manage_time_entries'` — a clean 5-line shift, now gone. Nine other files carry `RolesAndPermissionsSeeder.php:N` citations (`Fiscal/routes.php:42`, `FiscalEventQuarantineResolutionTest.php:137`, `Task33FiscalFullFlowVerificationTest.php:96`, `TaskPhase3AccountChargeFullFlowTest.php:116`, `TaskPhase2AccountPaymentFullFlowTest.php:112`, `FiscalEventIngestionEndpointTest.php:101,:177`, `POS/ReceiptReturnRefactorV3Test.php:159`, `Authorization/RefundFlowPermissionsTest.php:145`) and all of them are byte-identical in target to BASE again. (Separately and NOT this lane's doing: several of those other-lane citations — e.g. `:590`, `:657` in the Fiscal/POS tests — do not land on the POS permissions they claim even at BASE. Pre-existing drift owned by those lanes; this lane neither caused it nor is asked to fix it.)

---

## 3. R2-3 [was Minor] the `.create`-on-update divergence — **CLOSED** ✅

Recorded in **both** places the brief required, and both state both directions:

- `AutoSaveDraftRequest::authorize()` docblock `:127-141` — "⚠️ DELIBERATE SEMANTIC DIVERGENCE, awaiting a ruling (gate R2-3)", names the converse explicitly ("a `.create`-only principal may line-replace an EXISTING draft that the matching `PATCH` would refuse them"), names the **affected roles** (`cashier` — `quotes.create`, `invoices.create`, neither `.update`; `operator` — `invoices.create`, no `invoices.update`), states **why it is not live** (not FE-reachable, edit routes are `<family>.update`-gated; not a regression, there was no per-type gate at all before), gives the rationale for choosing `.create`, and points at R-11.
- `…-autosave-residuals.md` **R-11 [P2]** — same content plus the sibling abilities it diverges from (`quotes.update`, `orders.update`, `invoices.update`, `purchase-orders.update`, `deliveries.edit`), the no-regression invariant, and the explicit statement that the alternative (`.create` to create, `.update` to update) is "the ruling to make". It also now notes the type-spoof escape is closed by `assertTypeMatches()`.

The round-2 grants table (no seeded role holds `<family>.update`/`.edit` without `<family>.create`) is unchanged by this round — the seeder's returned arrays are byte-identical to BASE, so no re-derivation is needed and **no seeder re-sync is required for existing tenants** (this lane still introduces no new permission).

---

## 4. R2-4 [was Minor] the lock test's magic window — **CLOSED** ✅

`AutoSaveRouteHardeningTest.php:852-870`:

```php
$start = strpos($source, 'public function saveDraft');
$rest = substr($source, $start + 1);
$nextMethod = preg_match('/\n    (?:public|private|protected) function /', $rest, $m, PREG_OFFSET_CAPTURE) === 1
    ? (int) $m[0][1]
    : strlen($rest);
$body = substr($rest, 0, $nextMethod);
```

The window is now bounded by the next method declaration instead of `substr(..., 1600)`. The `$start + 1` offset is deliberate and correct — it prevents the regex from matching `saveDraft`'s own declaration. The `strlen($rest)` fallback means a last-method `saveDraft` still scans to EOF rather than silently matching nothing. The next declaration is now `assertTypeMatches()`, and `Document::query()…lockForUpdate()` sits inside the bound. Green in my run. The failure direction remains safe (red, never silently green).

---

## 5. R2-5 [was Informational] the R-10 count — **CLOSED** ✅

- The ticket now tabulates **11 errors across 7 files** (`…-autosave-residuals.md:235-251`) with the method stated: apply the two `Document.php` annotation corrections in a scratch edit, `phpstan --error-format=json`, read `totals.file_errors`, revert.
- **The revert really happened:** `git diff --stat fa807a699..0a932c291 -- app/Modules/Document/Domain/Document.php` is empty; `:44` is still `@property string $partner_id` and `:89` still `@property-read Partner $partner`. Worktree `git status` clean.
- **The tabulation is not fabricated** — I opened three of the seven: `DocumentPostingService.php:524` is `partnerId: $document->partner_id` into an event constructor; `DeliveryNoteService.php:225` is the identical shape; `CreditNoteController.php:430-431` are unguarded `$creditNote->partner->id` / `->name` sitting two lines under a `?->` on the sibling relation at `:428`. Exactly the shapes described.
- **The 12th is disclosed, separated, and fixed.** `…-autosave-residuals.md:253-258` states plainly that a twelfth error surfaced during the scratch run, that it was **this lane's own** (`typeMismatch()` used `$supplied?->value ?? '…'`, flagged as an unnecessary nullsafe), that it is fixed, and that **it is not part of the 11**. The fix is in place — `DraftNotEditableException.php:93` is an explicit `$supplied === null ? 'no document type' : $supplied->value` ternary. Module PHPStan is `[OK] No errors` at the tip, so the lane trips no gate.

---

## 6. Runs I executed myself

| Check | Lane claim | My result |
|---|---|---|
| New class, SQLite | 32 / 131 | **32 passed (131 assertions)** ✅ |
| Class + 4 neighbours, SQLite | 74 / 251 | **74 passed (251 assertions)** ✅ (`AutoSaveRouteHardeningTest`, `RefundResidualTenantIsolationTest`, `T2EventsV2DualDispatchTest`, `DraftLineEventV2Test`, `DraftPersistenceServiceTest`) |
| My independent spoof probe | — | **4 passed (18 assertions)**, results quoted in §1 |
| PHPStan `app/Modules/Document/` | clean | **[OK] No errors** ✅ |
| Deptrac | 182 | **Violations 182** in the worktree; **Violations 182** in the main checkout — unchanged ✅ |
| Pint | clean | **`{"result":"pass"}`** ✅ |

PostgreSQL leg not re-run this round (optional per the brief). Round 2 ran the identical 5 classes on PG 16 at `1bf8d8eba` with matching counts; the round-3 delta adds one enum case, one private method and three feature tests, touches no schema, no CHECK-constrained column and no `MAX(uuid)`-shaped relation, and the new fixture `draftOfType()` correctly uses `FiscalStatus::Draft` (+ `FiscalCategory::NonFiscal` for the CE) so it stays outside `chk_fiscal_mandatory_core`. No PG-specific risk was introduced.

---

## 7. Scope (diff-stat audit)

`git diff --name-only 1bf8d8eba..0a932c291` = **7 files**, exactly the five items' surfaces plus the record:

```
apps/api/app/Modules/Document/Domain/Exceptions/DraftNotEditableException.php   R2-1
apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php       R2-1 (+ R2-2 comment)
apps/api/app/Modules/Document/Presentation/Requests/AutoSaveDraftRequest.php    R2-2 / R2-3
apps/api/app/Modules/Document/Presentation/routes.php                           R2-2
apps/api/database/seeders/RolesAndPermissionsSeeder.php                          R2-2 (collapse to BASE)
apps/api/tests/Feature/Document/AutoSaveRouteHardeningTest.php                   R2-1 / R2-2 / R2-4
docs/superpowers/tickets/2026-08-23-autosave-residuals.md                        R2-2 / R2-3 / R2-5
```

**No frontend file, no ratchet, no manifest, no migration, no event class, no new permission.** ✅

---

## 8. Findings from this round

### R3-1 [Minor] `docs/superpowers/tickets/2026-08-23-autosave-residuals.md:48` and `:178` — two line numbers into lane-edited files survived the convention sweep

The ticket declares at `:14-19` that anything inside a file the lane edits is cited by symbol, never by line. Two citations did not get converted:

- `:48` — "`DraftPersistenceService::removeLine()` fires `DraftLineRemoved` and `DraftLineRemovedV2` *before* `$line->delete()` at **`:532`**". Actual: `removeLine()` at `DraftPersistenceService.php:592`, the events at `:608` and `:622`, `$line->delete()` at `:636`.
- `:178` — R-7: "the miss falls through to `createNewDraft()` (**`:88-90`**)". Actual: `:93-95`.

Everything around them was converted; these two are stragglers, not a systemic repeat. Harm is the same as R2-2's (this ticket is the map to a live P1 residual, and `:532` now points into unrelated code), but it is two tokens. **Fix:** delete the two numbers — the surrounding symbol names already carry the reader.

### R3-2 [Minor] `AutoSaveRouteHardeningTest.php:1231-1244` — the fiscal-fixture docblock was orphaned onto the wrong method

The new `draftOfType()` helper was inserted **between** `documentWithOneLine()`'s docblock and `documentWithOneLine()` itself. The result is two stacked docblocks above `draftOfType()` (`:1231-1243` then `:1244-1251`), and `documentWithOneLine()` (`:1281`) now has none. The stranded block is the `chk_fiscal_mandatory_core` / "SQLite does not enforce CHECK constraints of this shape, so the missing fixture only failed on PG" note — i.e. exactly the round-2 evidence for the PG-caught fixture defect, now attached to a helper that deliberately never seals anything. Doc-only, no behavioural effect. **Fix:** move the `draftOfType()` docblock above its own method and leave the fiscal note attached to `documentWithOneLine()`.

### R3-3 [Informational, not a defect] the `array_key_exists('type')` skip arm is a latent trap for the next in-process caller

Verified above that it is unreachable from HTTP and that `DraftController::autoSave()` is the only production caller. But probe D shows the arm is live in-process: `saveDraft(..., data: ['lines' => []])` on an existing draft strips every line with no authorization-carrying check at all. The guard's safety therefore rests on a fact recorded only in a docblock — "the only routed caller sends `type`". A future queued sync, POS bridge, or console command that calls `saveDraft()` directly with a partial payload would silently bypass the control that closes R2-1, and under db-per-tenant a queued caller is precisely the context where nobody re-checks the request contract. Cheap hardening for a follow-up (not this lane): make `type` mandatory on the update branch and fix the three unit-test call sites, or add a test that pins "the only `saveDraft()` caller in `app/` is `DraftController`" the way the lock test pins the lock.

---

## 9. Answers to the round-3 checklist

1. **R2-1** — guard in place after the locked fetch, before `assertDraftEditable()`, 422 `DOCUMENT_TYPE_MISMATCH`, nothing mutated. Spoof, CE spoof and honest counterpart all re-executed by me with attacker-supplied lines; sequence table untouched. Leniency arm attacked: `type` is `required` (`:183`), pinned by `test_auto_save_rejects_a_missing_document_type` (`:573`), no `prepareForValidation`, and the ONLY production caller is `DraftController.php:91` via `validated()`. **No production caller omits `type` — the skip arm is not a live bypass.** ✅
2. **R2-2** — 7 citations spot-checked (symbols + cross-file numbers), all land; seeder collapsed back to **820 lines = BASE**, net delta one in-place comment; the 5-line drift that hit 9 other files' citations is gone (proved by comparing `:590` at `1bf8d8eba` vs BASE vs tip). Two stragglers → R3-1. ✅/⚠️
3. **R2-3** — R-11 present in both the `authorize()` docblock (`:127-141`) and the ticket, with both directions, the affected roles, and why it is not live. ✅
4. **R2-4** — window bounded by the next method declaration; fallback safe; green. ✅
5. **R2-5** — 11 errors / 7 files tabulated with method, three sites verified genuine, `Document.php` provably reverted, and the 12th (the lane's own nullsafe) disclosed, fixed, and explicitly excluded from the 11. ✅
6. **Runs** — 32/131, 74/251, my 4/18 probe, PHPStan `[OK]`, deptrac 182 = 182, pint pass. ✅
7. **Scope** — 7 files, the five items' surfaces plus the ticket; no FE, ratchet, manifest, migration or event file. ✅

**What to fix before merge:** nothing blocking. Two doc-only tidies to take at leisure — drop the two stale line numbers in the residuals ticket (`:48`, `:178`) and re-attach the fiscal-fixture docblock to `documentWithOneLine()`. Ship it.

VERDICT: ACCEPT
