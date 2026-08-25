# B2-6 residual sweep — gate r1, INVENTORY/COSTING lens (C-14 items only)

**VERDICT: spec ✅ (all three C-14 items do what the brief asked) + quality CHANGES-REQUESTED — MERGE-BLOCKING: NO (4 conditions, none requiring a code revert)**

Per-item: **C-14(ii) ACCEPT** · **C-14(iii) ACCEPT** · **C-14(iv) ACCEPT-WITH-CONDITION (server-side correct, but functionally inert — the FE discards the message it localises)**

Reviewer: inventory-costing-reviewer (adversarial). Branch `fix/sb2-b26-residual-sweep`, worktree
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-b26-residual-sweep`, commits `04031b570`, `92dfb0d3b`,
`a0ecfdf4c` on base `d91886af9`. Tenancy lens already cleared the other six items — not re-reviewed here.
Throwaway PG DBs `autoerp_invgate_test` + `autoerp_invtamper` (5433) created, used one-file-at-a-time, dropped.
Branch worktree never edited (`git status --porcelain` empty at exit); no stash, no merge, no push.

---

## 1. Execution evidence (PostgreSQL 5433, one phpunit file per run, by path)

| file | result |
|---|---|
| `apps/api/tests/Feature/Inventory/CountingTerminalStateGuardTest.php` | **OK 8 tests / 38 assertions** |
| `apps/api/tests/Feature/Inventory/ReconciliationTest.php` | **OK 13 tests / 62 assertions** |
| `apps/api/tests/Feature/Inventory/ReplayFinalizeTest.php` (counting-apply + `manualOverride` at `:368`) | **OK 12 / 40** |
| `apps/api/tests/Feature/Inventory/CountingVarianceAppliedTest.php` (variance→stock, FEFO/lot draw-down) | **OK 17 / 76** |
| PHPStan L8 on the 5 changed PHP files (`CountingTransitionException`, `CountingUnresolvedItemsException`, `CountingItemController`, `InventoryCountingService`, `bootstrap/app.php`) | **[OK] No errors** |

Both reported numbers (8/38, 13/62) reproduce exactly. The override path still posts the right quantities and the
right row unit cost (`ReplayFinalizeTest::test_case_j_manual_override_as_of_is_resolved_at`,
`::test_both_counting_paths_persist_the_row_unit_cost`) and the lot ledger still draws down correctly
(`CountingVarianceAppliedTest::test_a_batch_tracked_shortage_draws_down_the_lot_ledger`).

### Tamper proofs (throwaway detached worktree with a REAL vendor copy — a symlinked vendor silently runs the
### untampered code because `vendor/composer/autoload_classmap.php` resolves `$baseDir` from `__DIR__`; my first
### tamper ran green for exactly that reason, worth recording for the next gate)

- **Tamper A — delete `lockCounting()` + `assertNotTerminal()` from `manualOverride()`:**
  2 failures — `test_probe_d_manual_override_into_a_finalized_counting_is_refused` ("Expected a manual override into a
  FINALIZED counting to be refused") **and** `test_every_mutating_counting_path_emits_the_header_row_lock`
  ("manualOverride() must re-read the counting header FOR UPDATE before it writes"). 2 failed / 6 passed.
- **Tamper B — keep the terminal guard, replace the locking read with a plain `InventoryCounting::findOrFail()`:**
  1 failure — the row-lock sentinel ALONE goes red; probe D stays green. 1 failed / 7 passed.

⇒ The PG-only sentinel really covers `manualOverride`, and it is falsifiable *independently* of the terminal guard.
This is the strongest part of the lane.

---

## 2. C-14(ii) — `manualOverride()` header lock + terminal guard — **ACCEPT**

Verified against the brief's questions:

- **Same lock as the siblings, inside the transaction, first.**
  `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php:1118-1120` — the lock is the
  first statement of the `DB::transaction` closure and calls the same private `lockCounting()`
  (`:816-824`, `SELECT … FROM "inventory_countings" … FOR UPDATE`) that `submitCount` / `finalize` /
  `triggerThirdCount` / `cancel` use. Canonical order preserved: counting header is taken BEFORE any other row lock
  on that path (`submitCount` takes header → `product_placements`; `manualOverride` takes header only). No new
  lock-order edge, no deadlock risk introduced.
- **Which states are terminal.** `assertNotTerminal()` at `:840-846` refuses **only** `Finalized` and `Cancelled`.
  So: a **Finalized** counting is refused ✅, a **Cancelled** counting is refused ✅, every in-progress state
  (`draft`/`scheduled`/`count_N_*`) **and `pending_review`** are allowed ✅. That matches
  `CountingStatus::allowedTransitions()` (`apps/api/app/Modules/Inventory/Domain/Enums/CountingStatus.php:50-51`:
  Finalized and Cancelled are the only states with an empty outgoing edge set), and matches the sibling paths. The
  narrowing is correct, not over-broad — I proved a `pending_review` override still returns **200 / `97.0000`**
  (probe, throwaway worktree), so the production review screen is unaffected.
- **`attemptedStatus = PendingReview` argument.** It is a *label*, not a transition — its only consumers are the
  rendered message and the `error.attempted_status` field. Defensible and documented at `:1098-1099`, but see
  MINOR-3.
- **Rule 19.** No money/quantity arithmetic in any changed line. `$item->final_qty = $quantity` is unchanged and
  still a canonical numeric string; no `(float)`, no `number_format`, no scale change. Clean. (But see IMPORTANT-4 —
  the *entry* to this path is not rule-19 clean, and this lane hardened everything about it except that.)

## 3. C-14(iii) — unresolved-items 500 → typed 422 — **ACCEPT**

- **Envelope matches its two siblings.** `CountingUnresolvedItemsException extends DomainException` with no
  dedicated render callback, so it lands on the generic handler at `apps/api/bootstrap/app.php:1000-1009` →
  `422 {"error":{"code":"BUSINESS_ERROR","message":…}}`. I confirmed `OverlappingCountingException` and
  `OpeningCostRequiredException` likewise have **no** dedicated callback, so "same shape as its siblings" is
  literally true. Registration order is safe: the generic `DomainException` closure sits at `:1000`, after every
  typed one and before the `Throwable` catch-all at `:1038`.
- **Honest pin rewrite.** `apps/api/tests/Feature/Inventory/ReconciliationTest.php:516-558` previously read
  `$response->assertStatus(500); // Service throws InvalidArgumentException` — it pinned the defect. It now asserts
  422 + `error.code` + the count + that the counting stays `pending_review`. Honest.
- **No behavioural change on the resolved path.** The diff touches only the `$unresolvedCount > 0` branch
  (`InventoryCountingService.php:1162-1168`). No production caller catches `\InvalidArgumentException` or
  `\LogicException` around `finalize()` (only `TerminalSyncAcknowledgementRequiredException` at
  `InventoryCountingController.php:468`), so nothing that used to swallow it now leaks. Confirmed green by the four
  files above.
- **Pluralisation** is correctly implemented in the constructor
  (`apps/api/app/Modules/Inventory/Domain/Exceptions/CountingUnresolvedItemsException.php:28-34`). See MINOR-1 for
  the pin.

## 4. C-14(iv) — backend-translated `COUNTING_TRANSITION_REFUSED` — **ACCEPT-WITH-CONDITION**

- **Does `__()` apply to ALL sites?** Yes. There is exactly **one** `CountingTransitionException` render closure
  (`apps/api/bootstrap/app.php:932-949`) and it now builds `error.message` from `TRANSLATION_KEY`. All five throw
  sites route through it: `InventoryCountingService.php:740`, `:843`, `:1220`, `:1300` and
  `InventoryCounting.php:240`. No site is left behind.
- **Missing-key fallback is safe.** `CountingTransitionException::statusLabel()`
  (`apps/api/app/Modules/Inventory/Domain/Exceptions/CountingTransitionException.php:86-93`) compares `trans()`'s
  return to the key and degrades to the raw enum value; `config/app.php:83` sets `fallback_locale = en`, so a locale
  with no `inventory.php` gets the English sentence, never a printed key.
- **FR vs EN completeness — verified programmatically, not asserted.** Flattened both catalogues: **16 keys each,
  zero one-sided keys**, all **11** `CountingStatus` cases present in both, and the EN/FR labels are byte-identical
  to `apps/web/src/locales/{en,fr}/inventory.json` `counting.status.*`. The mirroring claim holds.
- **AR absent and correctly documented.** `apps/api/lang/ar/` contains only `documents.php` + `treasury.php` → AR
  operators fall back to English. Disposition (AR-locale lane) is stated in the commit message and the report. OK.
- **i18n ratchet unmoved — confirmed.** `apps/web/tools/audit-i18n-completeness.mjs` reads only
  `apps/web/src/locales/<locale>/<ns>.json` (`:16`, `:342`); it never looks at `apps/api/lang`. The three C-14
  commits touch **no** web locale file (`git diff --stat d91886af9..3f880783a -- apps/web apps/api/lang` = 2 backend
  lang files + the C-28(i) page/test only). Baseline genuinely unmoved.
- **The condition — the FE consumer path throws the translation away. See IMPORTANT-1.**

---

## 5. Findings (ordered by severity)

### [IMPORTANT-1] `apps/web/src/features/inventory-counting/api/queries.ts:245` (and `:193`) — the localised message never reaches an operator; C-14(iv) is inert on the only surface that raises it — the toast will read "…: Request failed with status code 422"

`onError: (error: Error) => toast.error(t('counting.messages.overrideFailed', { error: error.message }))`. That
`error` is the raw **AxiosError**: `apiPost` (`apps/web/src/lib/api.ts:384-387`) only unwraps the *success* body
(`response.data.data`) and the response interceptor re-rejects the error unchanged
(`apps/web/src/lib/api.ts:361`, `return Promise.reject(error instanceof Error ? error : new Error(String(error)))`).
The counting feature never calls the helper that DOES read the envelope — `getErrorMessage()`
(`apps/web/src/lib/api.ts:83-98`); `grep -rn getErrorMessage apps/web/src/features/inventory-counting/` returns
nothing, and all **nine** `onError` handlers in `queries.ts` use `error.message`.

Reproduced with axios itself (same reject shape as `api.ts:361`, 422 body carrying
`error.message = "Ce comptage est « Finalisé » …"`): **`onError error.message => "Request failed with status code 422"`**.

So today the French operator sees *"Échec de l'application de la correction : Request failed with status code 422"*,
and the same hole swallows C-14(iii)'s new `"Cannot finalize: 1 item still pending resolution."`. The
report's i18n paragraph — *"the FE renders the backend message inside `counting.messages.overrideFailed` /
`finalizeFailed` (`{{error}}`), which is exactly why the backend string had to be the translated one"* — and the
matching sentence in the `a0ecfdf4c` commit body are **factually wrong**; the premise the item was justified on does
not hold.
*Why it matters:* the whole user-visible deliverable of C-14(iv) — and the "reviewer can read and act on it" claim in
the C-14(iii) test comment — is currently undelivered. *Fix:* swap `error.message` → `getErrorMessage(error)` at
`queries.ts:193` and `:245` (one import, two call sites; the other seven are the same bug and can follow), or keep
LEDGER C-14(iv) OPEN with the FE leg named.

### [IMPORTANT-2] `InventoryCountingService.php:811-815` + `CountingTerminalStateGuardTest.php:430` — "EVERY mutating counting path holds this lock" is false; `activate()` / `activateDraft()` still mutate the header unlocked and are NOT in the sentinel

The new `lockCounting()` docblock claims every mutating path now holds the lock, "pinned by
`test_every_mutating_counting_path_emits_the_header_row_lock`". The sentinel's path table
(`CountingTerminalStateGuardTest.php:461, :474, :492, :516, :535`) covers five paths: `submitCount`, `finalize`,
`triggerThirdCount`, `manualOverride`, `cancel`. It does **not** cover `activateDraft()`
(`InventoryCountingService.php:605-660`) or `activate()` (`:665-690`), both of which read status OUTSIDE any
transaction (`:611-613`, `:667-670`) and then `transitionTo()` the header inside a transaction with **no** row lock —
classic check-then-act on the in-memory status (`InventoryCounting::transitionTo()`,
`apps/api/app/Modules/Inventory/Domain/InventoryCounting.php:230-245`, decides from `$this->status`). A concurrent
`cancel()` (which does lock) and `activate()` (which does not) can therefore both commit, resurrecting a cancelled
counting into `count_1_in_progress`.
*Why it matters:* the overclaim is worse than the gap — it tells the next reviewer this surface is closed. Also the
sentinel docblock at `:430` still says "**all four** mutating paths" after a fifth was added.
*Fix:* downgrade both docblocks to name the five covered paths explicitly and state that `activate`/`activateDraft`
are the remaining unlocked writers, and file a LEDGER row for them (they were not in this brief).

### [IMPORTANT-3] `CountingTerminalStateGuardTest.php` / `ReconciliationTest.php` — no test exercises `manualOverride()` while the counting is in `pending_review`, the phase the feature actually runs in

Every override control uses `count_1_in_progress`: `activeCounting()` (`CountingTerminalStateGuardTest.php:606-620`)
and `createCountingSession()` (`ReconciliationTest.php:560-576`) both hardcode `CountingStatus::Count1InProgress`. The
reconciliation screen that offers "manual override" is reached at `pending_review`. I proved by probe that
`pending_review` → override is still **200 / `97.0000`** today, so this is a coverage gap and not a live regression —
but a future widening of `assertNotTerminal()` (e.g. "a counting under review is frozen") would break the real
product flow with every existing test still green.
*Fix:* add a `pending_review` control alongside `test_a_live_counting_item_is_still_manually_overridable`.

### [IMPORTANT-4] `apps/api/app/Modules/Inventory/Presentation/Requests/ManualOverrideRequest.php:26-29, :52-55` — rule-19 regex ceiling missing on the very path this lane hardened: `quantity='1e3'` is a **500**, and a 5-dp quantity is silently truncated at rest

Rules are `['required','numeric','min:0']` with **no** `/^-?\d+(\.\d{1,4})?$/` ceiling, and `quantity()` does
`bcadd($raw, '0', 4)`. Proven end-to-end against `POST /api/v1/inventory/countings/items/{id}/override` on PG:
- `'quantity' => '1e3'` (passes `numeric`) → `bcadd(): Argument #1 ($num1) is not well-formed` → catch-all renderer →
  **HTTP 500**. That is precisely the failure mode C-14(iii) removed one commit earlier, on the sibling endpoint.
- `'quantity' => '12.99999'` → **200**, and `inventory_counting_items.final_qty` stores **`12.9999`** — `bcadd`
  truncates, so the operator's number is silently altered, and that value is what `finalize()` posts as the counted
  quantity.
Pre-existing, not introduced here — but it is the entry gate of the exact method C-14(ii) locked, it is squarely in
this lens (quantity at rest), and the lane touched the file's whole call chain.
*Fix:* add the scale-4 regex to `ManualOverrideRequest` (non-breaking per rule 19) — or file it as a LEDGER row.

### [IMPORTANT-5] `CountingUnresolvedItemsException.php:28-34` — the new operator message is hardcoded English, i.e. the exact defect the NEXT commit in the same lane fixes for its sibling

`"Cannot finalize: {$unresolvedCount} {$noun} still pending resolution."` is rendered verbatim by the generic
handler (`bootstrap/app.php:1006`). C-14(iv) gave `CountingTransitionException` a `TRANSLATION_KEY` +
`translationReplacements()`; the exception born two commits earlier in the same lane did not get one. A French
operator gets an English refusal (once IMPORTANT-1 is fixed and they can actually see it).
*Fix:* `inventory.counting.unresolved_items` in `lang/{en,fr}/inventory.php` + a dedicated render callback (it needs
one, because the generic handler cannot know about a key), or accept and record that `BUSINESS_ERROR` messages are
uniformly untranslated across the codebase.

### [MINOR-1] `ReconciliationTest.php:546-550` — the pluralisation pin is vacuous

`assertStringContainsString('1 item', …)` also passes against the old buggy `"1 items"` string, so the fix the commit
message highlights ("the old string said '1 items'") is not actually pinned.
*Fix:* assert the full sentence, or add `assertStringNotContainsString('1 items', …)`.

### [MINOR-2] `CountingTerminalStateGuardTest.php:571-574` — the sentinel asserts lock PRESENCE, not lock-before-write ordering, despite its own failure message

The scan collects every statement emitted by the traced act and looks for any
`from "inventory_countings" … for update`; a refactor that took the lock *after* the item write would still pass a
message that reads "must re-read the counting header FOR UPDATE **before it writes**".
*Fix:* record the index of the first write against `inventory_counting_items` / `inventory_countings` and assert the
lock statement precedes it.

### [MINOR-3] `InventoryCountingService.php:1120` — `attemptedStatus = PendingReview` yields an operator sentence that describes a transition nobody attempted

The refused override renders as *"Ce comptage est « Finalisé » et ne peut pas passer à « En attente de révision »."*
The reviewer did not try to move the counting to `pending_review`; they tried to correct a line. Deliberate and
documented (`:1098-1099`), but with C-14(iv) now putting this string in front of an operator it reads as a non
sequitur.
*Fix (cheap):* a distinct key for the item-scoped refusal, or drop `:attempted` from the sentence when the refusal
originates from `manualOverride`.

### [MINOR-4] `InventoryCountingService.php:1157-1168` — the unresolved-items check is still outside the header lock

`finalize()` counts pending lines BEFORE opening its transaction, so it remains check-then-act (the authoritative
re-check happens under the lock at `:1199+` only for the *status*). Unchanged by this commit and harmless in practice
(a line cannot go back to `pending`), noted for completeness.

---

## 6. Allowlist append (`ci.yml:1025`) — **ENDORSED for `CountingTerminalStateGuardTest|ReconciliationTest`; no opinion on `HeldOrderTest` (POS, outside this lens)**

Grounds, all verified:
1. **They run in NO live CI job today.** `backend-test` executes `php artisan test --testsuite=Unit` only
   (`ci.yml:420`) — no Feature classes. `feature-lane-inventory` is parked behind
   `vars.SELF_HOSTED_RUNNER_READY == 'true'` (`ci.yml:1537`). The report's claim is accurate.
2. **Without the append the C-14(ii) lock sentinel is unarmed anywhere.** It `markTestSkipped`s on SQLite by design
   (`CountingTerminalStateGuardTest.php:444-447`, `SQLiteGrammar::compileLock()` returns `''`), so the *only* place
   it can execute is a PG job. Both my tampers proved it is the thing that catches a silent lock removal — leaving it
   unexecuted defeats the item.
3. **No filter collision.** The job's filter is `/\\(A|B|…)::/` (`ci.yml:1004`); the leading `\\` is one literal
   backslash, so `ReconciliationTest` matches only `…\ReconciliationTest::` and cannot match
   `…\LocationReconciliationTest::` (already in the list).
4. **Zero inherited reds, measured alone on a virgin PG DB:** 8/38 and 13/62. Runtime cost ≈ 46 s inside an existing
   job.

Caveat to carry: per the implementer's residual #5 (and REPORT-C26), two phpunit processes against one PG database
corrupt each other — this append puts both classes inside a *single sequential* phpunit invocation, which is fine,
but nobody should split the job.

---

## 7. Conditions before merge

- **C1 (blocking the LEDGER row, not the merge).** Either land the two-line FE change
  (`getErrorMessage(error)` at `apps/web/src/features/inventory-counting/api/queries.ts:193` and `:245`) or keep
  LEDGER **C-14(iv) OPEN** with the FE leg explicitly named. C-14(iv) delivers nothing to a human until then.
- **C2.** Correct the false claim in `docs/sessions/session-B-2026-08-23/REPORT-B26-implementer.md` §"i18n finding"
  (and note it against `a0ecfdf4c`): the FE does **not** render the backend message.
- **C3.** Fix the two overclaiming docblocks (`InventoryCountingService.php:811-815`,
  `CountingTerminalStateGuardTest.php:430`) and file a LEDGER row for the unlocked
  `activate()` / `activateDraft()` writers.
- **C4.** File LEDGER rows for IMPORTANT-3 (`pending_review` override control) and IMPORTANT-4
  (`ManualOverrideRequest` rule-19 ceiling → live 500 on `1e3`, silent scale-4 truncation).

Nothing here requires reverting or reworking `04031b570` / `92dfb0d3b` / `a0ecfdf4c`. The server-side work is
correct, tamper-proven and green on PostgreSQL.

**What to fix before merge:** surface the backend message on the FE (`getErrorMessage` at `queries.ts:193/:245`) or
keep C-14(iv) open, and correct the report + the two "every mutating path" docblocks.
