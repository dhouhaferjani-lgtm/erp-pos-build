# B2-6 residual sweep — gate r2 (re-gate), INVENTORY/COSTING lens

**VERDICT: spec ✅ + quality APPROVED (mergeable) — 0 blocking items.**

All five r1 IMPORTANT findings are **ADDRESSED** and every fix is **falsifiable** (proven red against the pre-fix
tree). MINOR-1 addressed, MINOR-2 partially, MINOR-3/MINOR-4 deliberately deferred. One **new Important**
(non-blocking, one-line) and three Minors introduced/left by the fix diff, all listed below for LEDGER.

Reviewer: inventory-costing-reviewer (adversarial). Branch `fix/sb2-b26-residual-sweep`, worktree
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-b26-residual-sweep`, tip `cb8d0290b`.
Fix round reviewed: `git diff fa732eed3~1..cb8d0290b` = 10 files, +696 / −17 (5 commits `fa732eed3`, `1706e0877`,
`c4d8294af`, `c8654d694`, `cb8d0290b`). READ-ONLY: branch worktree `git status --porcelain` empty at exit; no
stash, no merge, no push, no edit. Throwaway PG DBs `autoerp_r2gate` + `autoerp_r2prefix` (5433) created and
dropped; throwaway detached worktree at `fa732eed3~1` (with a **cloned, not symlinked**, `vendor/` — see r1) created
and removed, `git worktree prune` run.

---

## 1. Execution evidence

### Green on the branch tip (PostgreSQL 5433, `phpunit-pgsql.xml`)

| invocation | result |
|---|---|
| `tests/Feature/Inventory/ReconciliationTest.php` | **OK 18 tests / 88 assertions** (r1: 13/62) |
| `tests/Feature/Inventory/CountingTerminalStateGuardTest.php` | **OK 12 / 57** (r1: 8/38) |
| `tests/Unit/Inventory/InventoryCountingServiceTest.php` + `CountingOverlapGuardTest.php` + `LiveCountingScenarioTest.php` | **OK 27 / 82** — no regression from the new activation lock |
| `tests/Feature/Inventory/ReplayFinalizeTest.php` + `CountingVarianceAppliedTest.php` | **OK 29 / 116** — variance→stock and FEFO lot draw-down unaffected |
| PHPStan L8 on the 4 changed PHP source files | **[OK] No errors** |
| Pint `--test` on the 6 changed PHP files | **`{"result":"pass"}`** |
| `apps/web` `npx tsc --noEmit -p tsconfig.json` | **exit 0** |
| `npx eslint queries.ts queries.errorMessage.test.tsx` | **0 errors** (`queries.ts` matches an existing ignore pattern; the new test file lints clean) |
| `npx vitest run …/queries.errorMessage.test.tsx` | **3 passed** |

### Falsifiability — the two changed test files run against the PRE-FIX tree (`fa732eed3~1` = `3f880783a`)

```
1) CountingTerminalStateGuardTest::test_probe_e_activating_a_draft_that_was_cancelled_underneath_is_refused
2) CountingTerminalStateGuardTest::test_probe_f_activating_a_scheduled_counting_that_was_cancelled_underneath_is_refused
3) CountingTerminalStateGuardTest::test_the_activation_paths_emit_the_header_row_lock_before_they_write
4) ReconciliationTest::test_manual_override_refuses_exponent_notation_instead_of_500ing
5) ReconciliationTest::test_manual_override_refuses_a_quantity_beyond_scale_4_instead_of_truncating
6) ReconciliationTest::test_unresolved_items_refusal_is_localised_and_pluralised
Tests: 30, Assertions: 125, Failures: 6.
```

Two of those reds are the r1 defects reproducing verbatim on the pre-fix tree:

- **#4** failed with `bcadd(): Argument #1 ($num1) is not well-formed` raised out of a live
  `POST /api/v1/inventory/countings/items/{id}/override` with body `{"quantity":"1e..."}` → the HTTP 500 r1
  IMPORTANT-4 claimed is now proven, not asserted.
- **#5** failed with `Expected response status code [422] but received 200` → the silent scale-4 truncation path.

The web pin is falsifiable too — same new test file against the pre-fix `queries.ts`:
`2 failed | 1 passed`, with
`Received: "counting.messages.finalizeFailed:{"error":"Request failed with status code 422"}"`.
That is r1 IMPORTANT-1 reproducing exactly.

---

## 2. Per-r1-finding verdicts

### [IMPORTANT-1] FE discards the localised message — **ADDRESSED** (`fa732eed3`)

- `apps/web/src/features/inventory-counting/api/queries.ts:9` now imports `getErrorMessage`, and both named call
  sites use it: `:199` (`useFinalizeCounting`) and `:254` (`useManualOverride`).
- `getErrorMessage` is the right helper, verified at `apps/web/src/lib/api.ts:83-98`: it guards on
  `axios.isAxiosError`, reads `error.response?.data` and falls back
  `data.error.message ?? data.message ?? error.message`. It takes `unknown`, so passing the handler's `Error` is
  type-safe (`tsc --noEmit` exit 0).
- Pinned by `apps/web/src/features/inventory-counting/api/__tests__/queries.errorMessage.test.tsx` — a **real**
  `AxiosError` built with a 422 envelope (`:65-77`), not a fake object, plus a third case at `:150-168` proving the
  `TERMINAL_SYNC_ACKNOWLEDGEMENT_REQUIRED` typed branch still wins over the generic toast. Falsifiable (2 reds
  pre-fix, above).
- **Condition C2 satisfied:** `docs/sessions/session-B-2026-08-23/REPORT-B26-implementer.md:111` carries an explicit
  correction naming the false claim and the commit it belongs to.

### [IMPORTANT-2] "EVERY mutating path holds the lock" overclaim + unlocked `activate()`/`activateDraft()` — **ADDRESSED** (`c8654d694`)

They fixed the gap, not just the docblock:

- `InventoryCountingService.php:627-631` — `activateDraft()` now takes `lockCounting($counting->id)` as the **first**
  statement of its `DB::transaction` closure, then `assertNotTerminal()`.
- `InventoryCountingService.php:694-695` — same for `activate()`, and it is taken **before**
  `assertNoOverlappingActiveCounting()` at `:697`, so the canonical order (counting header first) is preserved on
  both paths. No new lock-order edge against `submitCount`/`finalize`/`cancel`.
- The guard is correctly **terminal-only** (`assertNotTerminal()` at `:879-884` refuses only `Finalized` /
  `Cancelled`), so `Draft → Scheduled → Count1InProgress` stays legal — pinned by
  `CountingTerminalStateGuardTest::test_the_activation_edges_still_work` (`:493-507`).
- Overclaim removed: the docblock at `InventoryCountingService.php:828-853` now **enumerates seven** paths, names
  which sentinel pins which, and explicitly lists `CountingItemController::setOpeningCost()` as NOT covered. The
  test-side twin at `CountingTerminalStateGuardTest.php:621-630` was corrected the same way ("all four" is gone).
- New probes E and F (`:428-457`, `:463-487`) assert the real damage, not just the exception: probe E also asserts
  `0` counting items were generated by the refused activation (`:452-456`) and that the header stayed `Cancelled`.

### [IMPORTANT-3] no `pending_review` override control — **ADDRESSED** (`1706e0877`)

`ReconciliationTest::test_manual_override_is_allowed_while_the_counting_is_pending_review`
(`ReconciliationTest.php:479-503`) drives the real HTTP endpoint at `PendingReview`, asserts `200`, `final_qty`
`'97.5000'`, `ItemResolutionMethod::ManualOverride`, and that the counting **did not move off** `pending_review`.
This is a control (green both pre- and post-fix, as expected) — it is the guard against a future widening of
`assertNotTerminal()`.

### [IMPORTANT-4] rule-19 regex ceiling missing on `ManualOverrideRequest` — **ADDRESSED** (`1706e0877`)

- `ManualOverrideRequest.php:36` —
  `'quantity' => ['bail', 'required', 'numeric', 'regex:/^-?\d+(\.\d{1,4})?$/', 'min:0']`. Exactly the house
  scale-4 ceiling from rule 19; `numeric` is **kept** (non-breaking shape); `bail` means the `bcadd` in
  `quantity()` can no longer be reached by a value it cannot parse. `min:0` still runs after the regex, so a
  negative (which the regex permits) is still refused.
- `:51` adds a `quantity.regex` message.
- `:56-61` rewrites the `quantity()` docblock to state that `bcadd` is a **normaliser, not a rounder**, which is the
  honest characterisation (bcmath truncates).
- Three pins: the two refusals (falsifiable — reds #4/#5 above) and a widening control
  `test_manual_override_still_accepts_quantities_at_or_below_scale_4` (`ReconciliationTest.php:445-468`) that walks
  `'12.3456'`, `'7'` and the JSON float `7.5` and asserts the stored value is `bcadd((string) $quantity, '0', 4)`.
  No float ever touches the payload path; nothing at rest changed scale.

### [IMPORTANT-5] hardcoded-English unresolved-items message — **ADDRESSED** (`c4d8294af`, fixture fix `cb8d0290b`)

- `CountingUnresolvedItemsException.php:36` adds
  `public const string TRANSLATION_KEY = 'inventory.counting.unresolved_items'` and `:54-57`
  `translationReplacements()`; `getMessage()` stays the English developer/log string — the same split the r1-accepted
  `CountingTransitionException::TRANSLATION_KEY` (`:51`) uses. (`public const string` is PHP 8.3; CI runs
  `PHP_VERSION: '8.3'` per `.github/workflows/ci.yml:15` and the platform pin is `8.3.30` —
  `apps/api/composer.json:118`. Safe.)
- A dedicated render callback at `apps/api/bootstrap/app.php:1007-1021` uses `trans_choice`. **Registration order
  verified in vendor, not assumed:** `Illuminate\Foundation\Exceptions\Handler` appends at `:242`
  (`$this->renderCallbacks[] = $renderUsing`) and iterates in order at `:714`, first non-null wins. The typed
  callback sits at `:1007`, the generic `DomainException` one at `:1022`. Correct.
- Envelope is deliberately unchanged (`BUSINESS_ERROR` + `message`, 422) so nothing branching on the code moves.
- `lang/en/inventory.php:17` and `lang/fr/inventory.php:17` both carry the two-segment plural string. FR
  pluralisation is right for this key: Laravel's `getPluralIndex('fr', n)` gives 0 for n≤1 and 1 otherwise.
- **AR is safe, verified in vendor:** `Translator::localeForChoice()`
  (`vendor/laravel/framework/src/Illuminate/Translation/Translator.php:230-235`) returns the **fallback** locale
  when the key is absent for the requested locale, so an `ar` request selects the plural form under `en` rules
  against the `en` line — no `$segments[0]` mis-selection, no printed key.
- Pinned by `ReconciliationTest::test_unresolved_items_refusal_is_localised_and_pluralised`
  (`ReconciliationTest.php:688-737`), which sends `Accept-Language: fr`, asserts the English sentence is **absent**,
  that the count is interpolated, and equality with the `fr` catalogue line. Falsifiable (red #6). The `cb8d0290b`
  follow-up gives the two pending lines **distinct products** because `idx_counting_item_unique` is a PG unique on
  (counting, product, location, variant) — a SQLite-only green would have hidden a 23505. Good instinct, exactly the
  SQLite-masking hazard this lens watches for.

### [MINOR-1] vacuous plural pin — **ADDRESSED**

`ReconciliationTest.php:661-671`: `assertStringContainsString('1 item', …)` is replaced by
`assertSame('Cannot finalize: 1 item still pending resolution.', …)` **plus**
`assertStringNotContainsString('1 items', …)`. The EN wording is byte-identical to the pre-fix hardcoded string,
which is why `test_cannot_finalize_with_unresolved_items` stayed green in the pre-fix run — correct, no user-visible
EN change.

### [MINOR-2] sentinel asserts lock presence, not ordering — **PARTIALLY ADDRESSED**

The **new** activation sentinel does assert ordering, and does it well:
`CountingTerminalStateGuardTest::assertLockPrecedesTheFirstWrite` (`:571-604`) records `$lockAt` and `$writeAt` and
asserts `assertLessThan($writeAt, $lockAt)` (`:603`). It also discriminates the header lock on
`"inventory_countings"."id" = ?` and excludes `counting_number` (`:584-587`) — load-bearing, because
`generateCountingNumber()` (`InventoryCountingService.php:1498-1502`) emits its own
`select … from "inventory_countings" … for update`; the implementer documents at `:565-567` that a presence-only
matcher was **vacuous on `activateDraft()`** and passed against the pre-fix service. That is an honest, verified
self-catch.
The **original five-path** sentinel at `:761-771` is still presence-only (`str_contains($sql, 'for update')`) while
its failure message at `:769` still says "before it writes". Not blocking (none of those five paths calls
`generateCountingNumber()`, so it is not vacuous there) — LEDGER.

### [MINOR-3] `attemptedStatus = PendingReview` reads as a non sequitur — **NOT ADDRESSED**

The fix diff does not touch `manualOverride()`; `InventoryCountingService.php:1120` still passes
`CountingStatus::PendingReview` as the label. Unchanged behaviour, and now that IMPORTANT-1 is fixed the sentence
*is* in front of an operator. LEDGER (cosmetic).

### [MINOR-4] unresolved-items check outside the header lock — **NOT ADDRESSED** (unchanged, harmless — a line cannot go back to `pending`).

### Conditions C1/C3 — satisfied in code (C1 by `fa732eed3`, C3 by `c8654d694`).
### Condition C4 — **cannot verify.** `docs/handoff/LEDGER.md` contains no `C-14(ii)`/`C-14(iv)` rows
(`grep -rln "C-14(iv)\|C-14(ii)" docs/` matches only the session brief/report and the two r1 review files). Filing
the rows is the parent's step, not a code gate; the implementer report `:111` names the deferrals explicitly
("Not taken: the other 7 `onError` handlers in `queries.ts` … → LEDGER").

---

## 3. New findings from the fix diff

### [IMPORTANT] `apps/web/src/features/inventory-counting/api/queries.ts:131` — `c8654d694` turned the activate endpoints into a live `COUNTING_TRANSITION_REFUSED` surface, and that is one of the seven handlers the fix round deliberately left on `error.message`

`useActivateCounting`'s `onError` is still
`toast.error(t('counting.messages.activateFailed', { error: error.message }))` (`:131`). Before `c8654d694`,
`activateDraft()` / `activate()` could not raise `CountingTransitionException` at all — the only refusals were the
controller's untyped 422 (`InventoryCountingController.php:945-949`) and an
`\InvalidArgumentException`. The fix round created a NEW localised refusal on exactly this path, and the FE
handler for it discards the message — i.e. r1 IMPORTANT-1 re-created on a surface the fix round itself introduced.
An operator who loses the activate-vs-cancel race sees *"Échec de l'activation : Request failed with status code
422"*.
*Why it matters:* the fix round's own justification for `fa732eed3` applies verbatim here; the seven-handler
deferral was reasonable when none of the seven raised a newly-localised refusal, and `c8654d694` changed that.
*Fix:* one line — `getErrorMessage(error)` at `:131` (import already present at `:9`). `:151`
(`useCancelCounting`) is the same shape and was already stale in r1.

### [MINOR] `InventoryCountingService.php:627` vs `:1498-1502` — new lock order in `activateDraft()`: header row lock → company-scoped numbering range lock

`activateDraft()` now holds the header `FOR UPDATE` on its own row (`:627`) and then, only when
`$counting->counting_number === null` (`:634`), calls `generateCountingNumber()` which takes
`lockForUpdate()` over every `inventory_countings` row matching `company_id` + `counting_number like 'CNT-YYYY-%'`
(`:1498-1502`). A deadlock cycle needs two sessions each holding an already-numbered header while their
**stale** in-memory handle still reads `counting_number === null` — narrow, and PostgreSQL would abort one with a
deadlock error rather than corrupt anything. It did not exist before this commit (the header lock is new).
*Fix / disposition:* LEDGER note; if it ever bites, mint the number before taking the header lock, or re-read
`$lockedCounting->counting_number`.

### [MINOR] `InventoryCountingService.php:627-631` and `:694-695` — `$lockedCounting` is used ONLY for the terminal guard; everything after still writes through the stale `$counting`

`$counting->save()` (`:643`), `generateCountingItems()` (`:646`) and `transitionTo()` (`:654`/`:661`/`:699`) all
operate on the caller's snapshot. That closes the terminal-resurrection hole r1 named, but a concurrent *non-terminal*
`activateDraft` on the same row can still mint a second `counting_number` off a stale `null` and re-run item
generation (which the unique index would then reject as a 23505). Same shape as before the fix — not a regression.
*Fix:* continue from `$lockedCounting` (or `$counting->refresh()` under the lock).

### [MINOR / not introduced here] `InventoryCountingController.php:403-417` — `POST /countings/{id}/activate` has no pre-check, so a fresh-loaded terminal counting still 500s

`activate()` (controller `:411`) hands a freshly loaded model straight to the service, whose pre-transaction test at
`InventoryCountingService.php:684-687` throws `\InvalidArgumentException`. `bootstrap/app.php:1022` matches the
**global** `\DomainException` (no import), which `\InvalidArgumentException` does not extend, so it reaches the
catch-all → 500. Its sibling `activateDraft()` does pre-check and returns 422
(`InventoryCountingController.php:945-949`). Pre-existing and outside the brief — but `c8654d694` touched this exact
method, and the more likely real-world entry (fresh load, not a race) still 500s. Same family as r1 IMPORTANT-4.
*Fix:* LEDGER row; mirror the `:945` pre-check, or type the service refusal.

**Checked and clean:** no `.github/**` and no migration touched anywhere on the branch
(`git diff --stat d91886af9..cb8d0290b -- .github apps/api/database/migrations` is empty), so the r1 §6 ci.yml
allowlist endorsement remains an un-applied recommendation for the parent. No money/quantity arithmetic was added
anywhere in the fix diff; no float, no `number_format`, no scale change at rest; no stock-movement, WAC, lot/FEFO or
opening-balance code was touched. Every counting→stock pin (`ReplayFinalizeTest`, `CountingVarianceAppliedTest`,
`LiveCountingScenarioTest`, `CountingOverlapGuardTest`) is green on PostgreSQL.

---

## 4. Verdict

**VERDICT: spec ✅ + quality APPROVED — mergeable, 0 blocking items.**

Findings by severity:
1. `[IMPORTANT]` `apps/web/src/features/inventory-counting/api/queries.ts:131` — `useActivateCounting` still shows
   "Request failed with status code 422" for the refusal `c8654d694` just created (one-line `getErrorMessage`).
2. `[MINOR]` `InventoryCountingService.php:627` / `:1498` — new header-lock → numbering-range-lock order (narrow
   deadlock cycle).
3. `[MINOR]` `InventoryCountingService.php:627-631`, `:694-695` — post-lock writes still go through the stale handle.
4. `[MINOR]` `InventoryCountingController.php:403-417` — `activate` still 500s on a terminal counting (not introduced
   here).
5. `[MINOR]` `CountingTerminalStateGuardTest.php:761-771` — the five-path sentinel is still presence-only while its
   message claims ordering (MINOR-2 half-done).
6. `[MINOR]` r1 MINOR-3 / MINOR-4 unchanged, as reported.
7. `[ADMIN]` LEDGER rows for the deferrals (7 `onError` handlers, `setOpeningCost` check-then-act, items 1-6 above)
   are not yet in `docs/handoff/LEDGER.md`.

**What to fix before merge:** nothing blocking — land the one-line `getErrorMessage(error)` at `queries.ts:131`
with this lane if it is cheap, otherwise file it plus the four Minors as LEDGER rows against C-14.
