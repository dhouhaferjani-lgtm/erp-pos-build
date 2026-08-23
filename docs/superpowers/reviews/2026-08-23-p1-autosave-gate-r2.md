# Adversarial merge gate — ROUND 2 (fix-round verification, tenancy/authz + fiscal lenses merged)

**Lane:** `fix/p1-autosave-route-hardening`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/p1-autosave` — reviewed READ-ONLY, left clean
**Range verified:** `12b8b0970` (round-1 tip) → `1bf8d8eba` (3 commits: `5ca715f49` RED, `18c1ffb73` GREEN, `1bf8d8eba` records)
**Round-1 inputs:** `docs/superpowers/reviews/2026-08-23-p1-autosave-gate-authz-r1.md`, `…-gate-fiscal-r1.md`
**Scope of this round:** verify the closures of P1-1 / P1-2 / P2-3 / P2-4 / P3-5..P3-9 / F1..F8, plus the fix round's OWN new surface. Round-1's already-verified-clean areas were not re-litigated.

---

## 0. Environment provenance

`ReflectionClass::getFileName()` before any test was trusted — all three resolve to the **WORKTREE**:

```
AutoSaveDraftRequest      => .worktrees/p1-autosave/apps/api/app/Modules/Document/Presentation/Requests/AutoSaveDraftRequest.php
DraftPersistenceService   => .worktrees/p1-autosave/apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php
Document                  => .worktrees/p1-autosave/apps/api/app/Modules/Document/Domain/Document.php
```

`vendor/` is a real directory (not a symlink to the main checkout); `.env` is symlinked. Tests run with `CACHE_STORE=array`, by path.
`git status --porcelain --untracked-files=all` was **empty at start and at end**; round-1's stray `ZzProbeAutoSaveTest.php` is gone. My one probe (below) was written to and run from the scratchpad — nothing was written into the worktree.

---

## 1. Closure verification — per finding

### AUTHZ P1-1 (null partner → silent 200) — **CLOSED** ✅

- `DraftPersistenceService.php:207-209`:
  ```php
  $document->load('partner');
  $partner = $document->getRelationValue('partner');
  $partnerName = $partner instanceof Partner ? $partner->name : null;
  ```
  `getRelationValue()` is honestly typed `mixed`, so the `instanceof` is a real runtime narrowing, not an analyser silencer. Rule 8 clean: `DraftDocumentCreated.php:26-27` declares both `$partnerId` and `$partnerName` as `?string`, so the `partnerId: $document->partner_id` at `:216` also survives a null. The single consumer, `Compliance/Listeners/DomainEventSubscriber.php:411-421`, just forwards `getAuditPayload()` — no null dereference downstream.
- **Both branches:** `grep -n 'partner' DraftPersistenceService.php` shows partner is read ONLY at `:207-209`/`:216` inside `createNewDraft()`. `updateDraftLines()` (`:235-280`) never touches the partner relation. No unguarded `$partner->` survives in the touched paths.
- **The test now sends the previously-broken value:** `AutoSaveRouteHardeningTest.php:611` `'partner_id' => null` inside `test_the_editors_exact_auto_save_payload_is_accepted`, with `assertJsonMissingPath('error')` at `:632` and `Document::count() === 1` at `:635`. Plus the isolated case `:652-672`, which additionally asserts the LINE was persisted (`:674-678`) — i.e. it pins that the rollback is gone, not just that a header row appeared.

### AUTHZ P1-2 (`correcting_entry` bypass) — **CLOSED on the create branch, NOT closed on the update branch** ⚠️ (see R2-1)

- `AutoSaveDraftRequest.php:157` → `Rule::enum(DocumentType::class)->only(self::autoSavableTypes())`.
- `DocumentType.php` has exactly 13 cases (`:9-20`, `:48`). 7 accepted; the other 6 are refused by the validator and **all 6 are tested**: `correcting_entry` at `:274-289`, and `expense`/`income`/`supplier_invoice`/`supplier_credit_note`/`purchase_rfq` at `:296-317`. Complete coverage of the complement — no gap.
- BUT a `correcting_entry` **draft already in the DB** (`CorrectingEntryService.php:72-73` creates them `DocumentStatus::Draft` / `FiscalStatus::Draft`) can still have its lines stripped through the update branch by spoofing `type` — see R2-1.

### AUTHZ P2-3 (per-type create) — **CLOSED on the create branch, COSMETIC on the update branch** ⚠️ (see R2-1)

**The 7-type set vs `useDraftAutoSave.ts:83` — I checked it myself, character by character.**

`apps/web/src/hooks/useDraftAutoSave.ts:83`:
```ts
type: 'quote' | 'sales_order' | 'invoice' | 'purchase_order' | 'delivery_note' | 'credit_note' | 'return_note'
```
`AutoSaveDraftRequest.php:91-99` keys: `quote, sales_order, invoice, credit_note, purchase_order, delivery_note, return_note`. **Exactly matching, no extra, no missing.** ✅

**The 7 values vs the sibling store routes — each opened and read** (note: the docblock's line numbers are stale, see R2-2; the *abilities* are all correct):

| type | map value | actual sibling store route |
|---|---|---|
| quote | `quotes.create` | `routes.php:95-96` ✅ |
| sales_order | `orders.create` | `:128-129` ✅ |
| invoice | `invoices.create` | `:165-166` ✅ |
| credit_note | `credit-notes.create` | `:249-250` ✅ |
| purchase_order | `purchase-orders.create` | `:279-280` ✅ |
| delivery_note | `deliveries.create` | `:322-323` ✅ |
| return_note | `deliveries.create` | `:344-345` ✅ |

**Attack — a type in the enum set but missing from the map: STRUCTURALLY IMPOSSIBLE.** `autoSavableTypes()` (`:200-206`) is `array_map(DocumentType::from(...), array_keys(self::AUTO_SAVABLE_CREATE_ABILITY))`. One constant is the sole source of both the accepted set and the ability map; they cannot diverge. The two `return true` fail-open arms in `authorize()` (`:131-133` non-string type, `:135-137` unmapped type) are safe because Laravel runs `passesAuthorization()` before `getValidatorInstance()`, and the validator then 422s every unmapped type — proven by the 6 refusal tests above, which all assert 422 (not 403) and `Document::count() === 0`.

**Silent-403 sweep (the real risk of adding a `can:`): CLEAN.** All six abilities are pre-seeded — I called `RolesAndPermissionsSeeder::permissionNames()` directly: `quotes.create`, `orders.create`, `invoices.create`, `credit-notes.create`, `purchase-orders.create`, `deliveries.create` all `YES`. **No new permission is introduced, so existing tenants need no seeder re-sync.**
I then re-derived the lane's no-regression claim from `RolesAndPermissionsSeeder::rolePermissionGrants()` rather than trusting it:

```
admin       q[c1u1] o[c1u1] i[c1u1] cn[c1u1] po[c1u1] del[c1u1]
manager     q[c1u1] o[c1u1] i[c1u1] cn[c1u0] po[c1u1] del[c1e1]
cashier     q[c1u0] o[c0u0] i[c1u0] cn[c0u0] po[c0u0] del[c0u0]
operator    q[c1u1] o[c1u1] i[c1u0] cn[c0u0] po[c1u1] del[c1e1]
accountant  all zero, docs.update=1
```
**No seeded role holds `<family>.update`/`.edit` without `<family>.create`** — the lane's claim holds.
I also chased the FE side the lane did not: the "new document" routes are gated on the **UI-alias** permissions `sales.create` / `purchases.create` / `inventory.create` (`apps/web/src/routes/index.tsx:649, :691, :733, :777, :940, :1248`), which are **not seeded permissions** (verified) and resolve through `uiAliasPermissions.ts:5-11` to roles `admin | sales | manager` / `admin | purchases | manager` / `admin | inventory | manager`. Of the seeded roles only **admin** and **manager** match, and both hold all six `<family>.create`. The edit routes are gated on `<family>.update` (`:669, :711, :755, :960` — these citations ARE accurate) whose holders all hold `.create` by the invariant above. **Conclusion: no seeded role that can reach a `DocumentForm` route now receives a 403 from auto-save.** No silent-403 on a prod path.

### AUTHZ P2-4 (no row lock) — **CLOSED** ✅

`DraftPersistenceService.php:84-90`, inside `DB::transaction()` opened at `:71`:
```php
$document = $draftId !== null
    ? Document::query()->where('tenant_id', …)->where('company_id', …)->lockForUpdate()->find($draftId)
    : null;
```
The lock is on the only fetch of an existing row, and it precedes `assertDraftEditable()` (`:99`) and `updateDraftLines()` (`:102`). The create branch (`:92-94`) inserts a fresh row — no concurrent mutator exists, so no lock is required there. Both mutating paths covered. The structural test (`:743-761`) is honest about why it is structural (SQLite compiles `lockForUpdate()` to nothing) and cites the in-repo precedent — minor fragility noted as R2-4.

### FISCAL F1 (EUR default into the fiscal hash) — **CLOSED** ✅

- `DraftPersistenceService.php:174` `'currency' => $company->currency`, from `Company::query()->whereKey($companyId)->firstOrFail()` at `:161` — same shape as `DraftPurchaseOrderService.php:89 → :59`.
- The red test uses a **non-EUR (TND)** company: `test_auto_save_persists_the_companys_currency_not_the_column_default` (`:695-715`) asserts `'TND'`, and the class's default company is EUR (`setUp` `:78`) so the assertion cannot pass by accident.
- **Nothing re-derives or overwrites currency later in the draft lifecycle** — I grepped every `'currency' =>` / `->currency =` write in `app/Modules/Document/`: the only Document-row writers are the six sibling creators plus this one; `DocumentPostingService.php:484` is a **read** into the hash payload, not a write. The auto-save update branch never touches the header (`updateDraftLines()` writes lines + totals only, `:277-279`). Contrast `fiscal_category`, which posting *does* re-derive — currency does not self-heal, which is exactly why this mattered.

### FISCAL F2 (inherited red mis-diagnosed) — **CLOSED** ✅ (re-run by me)

`php artisan test tests/Feature/Events/T2EventsV2DualDispatchTest.php` on the lane tip → **8 passed (31 assertions)**, including `draft line added dual dispatch includes v3 with variant fields`, the case that errored at round 1. The lane's "zero inherited red" claim is true, and the ticket records the corrected causation (`…-autosave-residuals.md:246-253`).

### FISCAL F4 (weak burn pin) — **CLOSED** ✅ (re-run by me)

`AutoSaveRouteHardeningTest.php:145-172` now `DocumentSequence::create([... 'last_number' => 7])` and asserts `=== 7` after the 403. Green in my run.
**Allocation ordering traced, not assumed:** `generateForKeyOnce()` is reached only from `createNewDraft():143` ← `saveDraft()` ← `DraftController::autoSave()`. The 403 in this test comes from route middleware `can:documents.update` (`routes.php:68`), which terminates before the controller is constructed. The per-type 403s come from `FormRequest::authorize()`, and the type 422s from the validator — both resolve during controller-method argument resolution, i.e. still before `autoSave()` executes. **No refusal path can reach the allocator.** The per-type tests independently pin this with pre-seeded sequences (`:167-172` PO `last_number` 3 → 3).

### FISCAL F5 / AUTHZ P3-5 (false precedent claims) — **substantively CLOSED, coordinates stale** ⚠️ (R2-2)

`routes.php:44-66` no longer claims the additional-cost writes as precedent and states the seeder's `documents.update` annotation accurately; `RolesAndPermissionsSeeder.php:121-126` now records that the permission is also the coarse auto-save gate (comment-only — the returned array is unchanged, so `permissionNames()` is byte-equivalent). The *claims* are true. The *line numbers* in the same comment are not (R2-2).

### FISCAL F6 / AUTHZ P3-6 (gitignored residual register) — **CLOSED** ✅

- `git check-ignore -v docs/superpowers/tickets/2026-08-23-autosave-residuals.md` → exit 1 (**not ignored**); `git ls-files` lists it (**tracked**).
- `grep -rn 'docs/sessions/2026-08-23-p1-autosave' apps/ docs/` → **no matches**; the gitignored notes file is deleted from disk and no pointer survives.
- Content check: **R-1** carries the fiscal gate's correction that `removeLine()` fires `DraftLineRemoved` + `V2` *before* `$line->delete()`, so false removal events reach the fraud stream **today** (`…-residuals.md:39-47`) — the round-1 framing is explicitly retracted. **R-2** carries the F3 refutation with the `POSAccountChargeDraftService.php:57` citation and restates the deferral as a scope choice (`:67-98`). **R-10** is present (`:209-238`).

### FISCAL F7 / F8 / AUTHZ P3-7 / P3-8 — **CLOSED** ✅

- **F7** recorded as R-3 with the FE consequence and a concrete suggested fix; correctly not taken (no FE file touched).
- **F8** — `test_characterisation_two_editor_saves_end_lineless_with_a_burnt_number` (`:875-926`) drives POST → capture `draft_id` → POST with the same client ids → asserts **0 lines** and `last_number === 1`. Explicit marker at `:872`: *"Fails-to-red when Residual 1 is fixed — invert, do not delete."* Honest.
- **P3-8** — `test_characterisation_a_foreign_tenant_draft_id_authors_a_new_document` (`:940-1018`) builds a real foreign tenant/company/partner/draft, asserts the foreign line **survives** (isolation), asserts the foreign id is not adopted, and characterises the new-document authoring at `:1012-1017` with "Characterisation … instead of 404ing". Its `assertStatus(200)` will go red the moment R-7 is fixed, which is the correct must-flip shape.
- **P3-7** — renamed to `test_a_client_minted_non_uuid_line_id_is_accepted_by_the_validator` (`:771`) with a cross-reference to the characterisation test at `:768-769`.
- **P3-9 / R-8, R-9** recorded verbatim in the ticket.

### The PG-caught fixture defect — **CLOSED** ✅

`documentWithOneLine()` (`:1135-1170`) now sets `fiscal_hash` + `chain_sequence` whenever `fiscal_status !== Draft`, with a docblock (`:1122-1132`) naming `chk_fiscal_mandatory_core` (`2026_03_10_300000_fix_fiscal_constraints_for_drafts.php:22-35`) and stating plainly that **SQLite does not enforce it, so the missing fixture passed the default `:memory:` gate and only failed on PG**. Exactly the SQLite-masking note this repo's rules ask for.

---

## 2. Runs I executed myself

| Check | Claim | My result |
|---|---|---|
| New class, SQLite | 29 / 118 | **29 passed (118 assertions)** ✅ |
| Class + 4 neighbours, SQLite | 71 / 238 | **71 passed (238 assertions)** ✅ (`AutoSaveRouteHardeningTest`, `RefundResidualTenantIsolationTest`, `T2EventsV2DualDispatchTest`, `DraftLineEventV2Test`, `DraftPersistenceServiceTest` — the exact blast radius by grep) |
| Same 5 classes, **PostgreSQL 16** | identical | **71 passed (238 assertions)** ✅ — run against `autoerp_postgres` (127.0.0.1:5433) in a scratch DB `autoerp_r2gate_autosave`, **created and dropped by me**; 202s |
| `T2EventsV2DualDispatchTest` alone | 8 / 8 | **8 passed (31 assertions)** ✅ |
| PHPStan `app/Modules/Document/` | clean | **[OK] No errors** ✅ |
| Deptrac | 182 unchanged | **Violations 182** on the lane tip; **Violations 182** in the main checkout — unchanged ✅ |

**TDD ordering verified by commit stat:** `5ca715f49` = 1 file, tests only; `18c1ffb73` = 4 files, production only; `1bf8d8eba` = 1 file, the ticket. Clean red→green→records. (I did **not** independently reproduce the "8 failed, 21 passed" red count — reproducing it requires checking production files out inside the worktree, and this round is read-only. The ordering is structurally proven; the count is taken on the lane's word.)

**Scope (item 10):** `git diff 12b8b0970..1bf8d8eba --stat` = 6 files — `DraftPersistenceService.php`, `AutoSaveDraftRequest.php`, `routes.php`, `RolesAndPermissionsSeeder.php`, `AutoSaveRouteHardeningTest.php`, and the new ticket. **No FE file, no ratchet, no manifest, no migration, no event class.** Exactly the findings' files + the record. ✅

---

## 3. Findings

### R2-1 [CRITICAL] `AutoSaveDraftRequest.php:129` — on the UPDATE branch the per-type `*.create` gate is decided by the **client-supplied** `type`, not the target document's persisted type. One string defeats the control this fix round exists to add.

**Mechanism.** `authorize()` reads `$this->input('type')` (`:129`) and looks the ability up in the map (`:135`). The service then loads the draft by `draft_id` and **never reads `type` again**: `grep -n "\$data\['type'\]"` on `DraftPersistenceService.php` returns exactly one hit, `:140`, inside `createNewDraft()`. `updateDraftLines()` (`:235-280`) does not compare `$data['type']` with `$document->type`, and the header is not updated. So on the update branch the authorization decision is made against a field the attacker controls and the code then ignores.

**Executed** (probe written to and run from the scratchpad; nothing written into the worktree). Principal = the lane's own cashier shape, holding `documents.view` + `documents.update` + `quotes.create` only; target = a `Draft`/`FiscalStatus::Draft` **invoice** with one line, same tenant + same company:

```
[HONEST type=invoice] status=403 lines=1      ← the lane's own test :241-260
[SPOOF  type=quote  ] status=200 body={"draft_id":"01a02f38-…","line_count":1} lines=0
```

Same principal, same target, one string changed: **every line stripped off an invoice draft by a caller with no `invoices.create`.** That is precisely the harm the parent ticket exists to prevent, and precisely what `test_auto_save_refuses_an_existing_draft_of_a_type_the_caller_cannot_create` (`:241-260`) claims to have closed — it passes only because it sends the honest type. This is the same failure shape round 1 caught as P1-1: a test that substitutes away the one value that breaks it.

**It also re-opens P1-2 on the update branch.** `CorrectingEntryService.php:72-73` creates correcting entries as `DocumentStatus::Draft` / `FiscalStatus::Draft`, so `assertDraftEditable()` waves them through. A `documents.update` + `quotes.create` holder can therefore strip the lines off a **correcting-entry draft** by sending `type: quote` — through the endpoint whose own comment (`routes.php:60-62`) says correcting entries are admin-tier by owner ruling.

**Why this blocks.** It is not a regression against `12b8b0970` (there, any `documents.update` holder could do it with the honest type too). But this round's job is to verify the closures, and the update-branch half of P2-3/P1-2 is **not closed** — it is decorative. Shipping it as closed puts a false "per-type authorization enforced on both branches" claim into `AutoSaveDraftRequest.php:116-117`, the `routes.php:56-62` comment, and the commit message, where the next reader will rely on it.

**Fix.** Authorize the update branch against the **persisted** type. Either (a) in `DraftPersistenceService::saveDraft()`, after the locked fetch and before `assertDraftEditable()`, refuse when `DocumentType::from($data['type']) !== $document->type` (a `DraftNotEditableException`-style 422 `TYPE_MISMATCH` — cheapest, and it also makes the client's `type` meaningful on the update branch instead of silently ignored); or (b) resolve the ability from `$document->type` rather than the request. Then extend `:241-260` with the spoof payload asserting the draft's lines survive — that test is the one that must exist.

---

### R2-2 [Minor] Systematic line-number drift in every citation the fix round newly wrote — including inside the comment written to fix F5

All of these state something **substantively true** but point at the wrong lines, because they were written against the pre-edit file and the same commit grew `routes.php` by 15 lines:

- `AutoSaveDraftRequest.php:75-77` cites the sibling store routes as `quotes :80, orders :113, invoices :150, credit-notes :234, purchase-orders :264, delivery-notes :307, return-notes :329`. Actual: `:95, :128, :165, :249, :279, :322, :344` — **off by exactly 15, all seven.**
- `routes.php:48` cites `documents.revert` at `:62`; actual `:76-79`.
- `routes.php:50-52` cites the additional-cost writes at `:350, :354, :358`; actual `:365, :369, :373`. The cited lines are now `return-notes.update` / `.destroy` / `.confirm` — i.e. the F5-correcting sentence points at the wrong routes.
- `routes.php:61` and `AutoSaveRouteHardeningTest.php:268` cite the correcting-entry block as `:365-388`; actual `:381-425`. `:365` is now the additional-costs store.
- The ticket says "Line references pinned to the lane tip" (`…-autosave-residuals.md:14`) but: R-7 cites the scoped lookup at `:73-79` (actual `:84-90`) and the create fall-through at `:88-90` (actual `:91-93`); R-1 cites `removeLine():504-530` with `$line->delete()` at `:532` (actual `removeLine()` at `:542`, events at `:558`/`:572`); R-4 cites `DraftController.php:100` (actual `:102`); R-6 cites `:112-124` (actual `:110-125`).

Round 1 blocked partly on F5/P3-5 — a comment whose precedent claim was wrong. The replacement comment fixes the claim and reproduces the same class of error in its coordinates. Since this comment plus the ticket are the entire navigational apparatus for a **live P1 residual**, the pointers need to be right.
Verified accurate and worth keeping: `DraftDocumentCreated` `:26-27`, `routes/index.tsx:669/:711/:755/:960`, `create_documents_table.php:24`, `useDraftAutoSave.ts:83`, `POSAccountChargeDraftService.php:57`, `2026_03_10_300000_fix_fiscal_constraints_for_drafts.php:22-35`.

**Fix.** Re-derive every `file:line` in the three new comment blocks and the ticket against the tip, or drop the numbers and cite by symbol name.

---

### R2-3 [Minor] The `.create`-on-update semantic diverges from the sibling PATCH routes and is not recorded anywhere

The sibling manual edits gate on `<family>.update` (`routes.php:99-100, :132-133, :169-170, :283-284`) / `deliveries.edit` (`:348-349`). Auto-save's update branch now demands `<family>.**create**`. The docblock (`AutoSaveDraftRequest.php:114-122`) only argues the no-regression direction (no seeded role holds `.update` without `.create` — I confirmed that) and never addresses the converse: a `.create`-only principal may now line-replace an **existing** draft that PATCH would refuse them. Concretely, from the grants table above: `cashier` (`quotes.create`, `invoices.create`, no `.update` on either) and `operator` (`invoices.create`, no `invoices.update`) can auto-save-mutate existing quote/invoice drafts that `PATCH /quotes/{id}` and `PATCH /invoices/{id}` deny them.

Not a regression, not FE-reachable (the edit routes are `<family>.update`-gated), and not harmful on its own — but it is the deliberate-semantics question round 1 asked to be answered, and the answer is currently unwritten. **Fix:** add a paragraph to `…-autosave-residuals.md` (or resolve it together with R2-1, since fixing R2-1 by option (b) naturally raises "which ability, `.create` or `.update`?").

---

### R2-4 [Minor] `AutoSaveRouteHardeningTest.php:748-753` — the lock test's `substr($source, $start, 1600)` window is a magic constant

The structural assertion scans a fixed 1600-character window from `public function saveDraft`. The `Document::query()…lockForUpdate()` chain currently sits inside it only because the surrounding comment is 14 lines long; one more paragraph of comment pushes it out and the test goes red for a reason that has nothing to do with the lock. The failure direction is safe (red, not silently green), but it is a maintenance trap in a test whose whole purpose is to survive.
**Fix.** Bound the window by the next `private function` / `public function` after `saveDraft`, or match on the whole file with a `saveDraft`-anchored non-greedy pattern terminated at the closing of the transaction closure.

---

### R2-5 [Informational, not a defect] R-10's "11 errors" count is not independently verified

`…-autosave-residuals.md:225-236` claims that correcting `Document.php:44` / `:89` takes `phpstan analyse app/Modules/Document/` from 0 to 11 errors. Reproducing that requires editing `Document.php` inside the worktree, which this round's read-only mandate forbids — **cannot verify the count of 11.** What I did verify:
- the lane left both annotations untouched (`git diff 12b8b0970..1bf8d8eba` does not include `Document.php`; `:44` still `@property string $partner_id`, `:89` still `@property-read Partner $partner`);
- `phpstan analyse app/Modules/Document/` is **[OK] No errors** at the tip, so the lane trips no gate;
- the two named exemplars are genuine: `SalesOrderService.php:241` and `:255` pass `$salesOrder->partner_id` into `SalesOrderConfirmed.php:32` / `SalesOrderConfirmedV2.php:30`, both declared `public readonly string $partnerId` — a rule-8-frozen constructor fed from a column that `2026_06_27_110000_make_documents_partner_id_nullable.php` made nullable; and `CreditNoteController.php:430-431` dereferences `$creditNote->partner->id` / `->name` unguarded next to a `?->` on the sibling relation at `:428`.
- the lane's own code is null-correct **without** the annotation fix: the `instanceof` narrowing is a real runtime check, the event params are `?string`, and the null-partner path is green on both SQLite and PostgreSQL.
The residual's shape is credible and the deferral is defensible; only the number is on trust.

---

## 4. Answers to the round-2 checklist

1. **P1-1** — closed; editor-payload test sends `partner_id: null`, isolated case asserts the line persists, narrowing covers both branches (update branch never reads partner), no unguarded `$partner->` survives. ✅
2. **P1-2 / P2-3** — 7-type set matches `useDraftAutoSave.ts:83` exactly; all 7 abilities match their sibling store routes; enum-set/map divergence is structurally impossible; fail-open arms are safe because the validator refuses first. All six abilities pre-seeded → no silent-403 trap, no seeder re-sync needed. **Update branch is bypassable by spoofing `type` → R2-1.** Semantic divergence from PATCH unrecorded → R2-3. ❌
3. **P2-4** — `lockForUpdate()` present on the only existing-row fetch, inside the transaction, before the guard; create branch needs none. ✅
4. **F1** — `$company->currency` set; TND test; nothing re-derives or overwrites currency downstream (verified by grep over every Document-module currency write). ✅
5. **F2** — `T2EventsV2DualDispatchTest` 8/8 re-run by me; zero inherited red. ✅
6. **F4** — seeds `last_number = 7`, asserts 7; every refusal path (route `can:`, `authorize()`, validator) terminates before the controller, so the allocator is unreachable. ✅
7. **Records** — ticket tracked (`check-ignore` negative, `ls-files` positive), R-1 carries the events-fire-today correction, R-2 the F3 refutation, R-10 present; `routes.php` + seeder annotation corrected; gitignored notes file deleted with no surviving pointer. Coordinates stale → R2-2; R-10 count on trust → R2-5. ✅ / ⚠️
8. **New-test honesty** — F8 two-POST and P3-8 cross-tenant both pin current behaviour with explicit must-flip / "characterised, not endorsed" markers; sealed fixture carries `fiscal_hash` + `chain_sequence` with the SQLite-masking note. ✅
9. **Runs** — 29/118 and 71/238 reproduced on SQLite **and** on PostgreSQL 16 (I ran the PG leg myself); PHPStan Document module clean; deptrac 182 = baseline 182. ✅
10. **Scope** — 6 files, exactly the findings' surfaces plus the ticket; no FE, no ratchet, no manifest, no new surface. ✅

---

## 5. What to fix before merge

Close **R2-1** — authorize the update branch against the persisted `$document->type` (or refuse a `type` that disagrees with the loaded draft), and extend `AutoSaveRouteHardeningTest.php:241-260` with the spoof payload asserting the invoice draft keeps its lines; that one test is what makes the "both branches" claim true. Take **R2-2** in the same commit (re-derive the citations — they are the map to a live P1). **R2-3** is a paragraph in the residuals ticket; **R2-4** is a two-line test tidy.

VERDICT: CHANGES-REQUIRED
