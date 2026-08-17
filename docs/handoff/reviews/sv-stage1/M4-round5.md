I read the brief's M4 section, reproduced the diff, ran the suites and static checks myself, and re-walked the render paths independently rather than trusting the audit.

## Independent reproduction

- `1eef6fe79..HEAD` (M4's own delta) touches **only** `apps/pos` (4 production `.tsx`, 4 test files, `locales/{en,fr}/pos.json`) + docs/tickets. No backend, no Stage-2+ file, no event, no migration, no queue.
- Suites by path: `Header.test.tsx` (11), `CashReconciliationSection.test.tsx` (16), `EndOfDayPreviewModal.test.tsx` (31), `CashCountTable.test.tsx` (8) = **66/66 green**.
- `npx tsc --noEmit` exit 0. `eslint` on the five touched components: **0 errors**, 1 warning — pre-existing (`CashReconciliationSection.tsx:106`, the E3 verifiedManager effect, outside this diff).
- Standing checks with no surface in M4: Rule 19 (grep of added lines for `parseFloat|Number(|toFixed|*|/100` returns nothing; only `format()` and `'—'`), migrations, Horizon, `app()`, tenant query keys. `config/treasury.php` diff is comment-only — `shift_variance_gl_enabled => env(..., false)` byte-identical.

## Round-4 P1/P2 verification

**Round-4 finding 1 (P2) is genuinely closed, and not just at prop level.** The parent's three commit-boundary atoms are now one object keyed to the policy identity (`EndOfDayPreviewModal.tsx:87-92`, `:121-124`). I verified the production precondition the fix rests on: `Header.tsx:116` derives `fraudSettings` from `fraudSettingsTerminal === terminal`, and **every** write path sets value+terminal together with a **fresh object literal** (`:133-141` online, `:202-210` cache) — there is no path that re-marks the terminal without minting a new object, so a re-resolution can never inherit the prior reveal boundary. The unmount itself is structural: `:253-256` replaces the whole preview subtree, so `CashReconciliationSection`'s `committed` (`:90`) dies while the parent's flag is already invalidated in the same render. The covering test (`EndOfDayPreviewModal.test.tsx:615-672`) is non-vacuous — it commits CASH+CHECK, asserts `430.00` inside the Payments table, cycles `resolved true→false→true` with a distinct settings object, and asserts absence + both buttons disabled.

**Round-4 finding 2 (P3) fixed, not just documented.** A refresh where both the online fetch and the cache read fail now leaves `fraudSettingsTerminal` on the old terminal ⇒ `fraudSettings` null ⇒ `cashCountPolicyUnavailable` ⇒ hard error, not a revived policy. Their own test asserts `true:none` (`Header.test.tsx:459-467`).

**Round-4 finding 3 (P3)** recorded in `2026-08-17-blind-count-policy-unavailable-close.md` §5 with an acceptance clause.

## Register

**1. P3 — CONFIRMED — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:226`, `:233`, `:253-256`**
The policy gates are not phase-aware, so `phase === 'confirming'` + a mid-flight policy re-resolution renders either **nothing** or a **false hard error**. `:226` requires `phase === 'preview'`, so under `confirming`+pending the modal body is empty; under `confirming`+unavailable, `:233` renders "Cannot close this shift: the cash-count policy has not been synced…" with a Cancel button that is inert (`handleClose` early-returns on `isConfirmingRef`), while the close is actually succeeding and will flip to the Z number. **Scenario:** cashier confirms; a sync tick's diff-gated `refreshTerminalRecord` (`terminalStore.ts:1041`) publishes a changed terminal because a manager toggled training mode; the operator sees a blank modal or a synced-policy error, then a successful Z. No disclosure, no data loss — misleading state only. Substantially inside the scope of the existing availability ticket §5; worth one appended line rather than a code round.

**2. P3 — PLAUSIBLE (unreachable today) — `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:135-186` vs `:121-124`**
The parent commit boundary is keyed to the **policy** object only, but the child also unmounts when `phase` leaves `preview|confirming` — including a **preview reload**, whose effect deps are `[isOpen, terminalId, shift.opened_at, shift.opening_cash, shift.id]` and are *not* policy. If any of those primitives ever changes while the modal is open post-commit, the child remounts with `committed = !blindMode` while the parent's flag survives → the round-4 leak class reopens through a non-policy trigger. I could not reach it: `fetchCurrentShift` has no caller outside `terminalStore` init/select flows (`:671`, `:695`, `:722`), `shiftReconcile` is advisory and never mutates `shift`, and a `terminalId` change also flips the policy. Note for hardening, not a blocker; the audit correctly scopes its claim to a *policy* refresh and does not overclaim here.

**3. P3 — CONFIRMED — `docs/superpowers/tickets/2026-08-17-blind-count-policy-unavailable-close.md` References**
Line citations drifted past the round-4 refactor: `EndOfDayPreviewModal.tsx:103-106,195-234` now lands on the `cashCountEnabled` comment and `handleConfirm`; the actual gates are `:113-116` and `:226-256`. The audit's `Header.tsx:121-242` is one line short of the effect (`:246`). Doc hygiene.

## Bypasses I tried that FAILED (defences hold)

- **Same-object policy refresh** (would defeat the identity guard): impossible — both write sites mint a new object literal and no path sets `fraudSettingsTerminal` alone (`Header.tsx:133-141`, `:202-210`, `:239`).
- **Structural non-unmount** (child keeping `committed` across a policy change): the preview subtree is a positional sibling replaced wholesale at `:253` — React must unmount it.
- **Confirm-with-stale-payload**: `cashCountPayload` and `cashCountReady` are keyed identically (`:122-123`), so a stale flow disables the confirm button before the payload can be submitted.
- **Failed-close re-entry** (`phase → 'error'` after `confirming`, child unmounts, parent flag alive): the error block offers only Cancel, and `phase` cannot return to `preview` without the load effect re-running, whose only realistic trigger is `isOpen` — which resets the flow (`:136-149`). No route back to a pre-commit render with the flag set.
- **Second EOD entry point**: `handleOpenEndOfDay` has exactly one call site (`Header.tsx:636`), and it is the fail-closed handler; the EOD button is *not* manager-gated, so the cashier threat model is the right one.
- **Sibling-surface omission**: independent enumeration of `expected_cash|expectedCash|variance` over non-test `.tsx` returns exactly the audit's set; X Report / cash-drawer ops / Z history are manager-filtered at `ReportsMenu.tsx:61-66` (verified, matches the audit's claim).
- **Spot-checks of "clean" verdicts** (M4 antidote): float-disclosure requires `committed` (`CashReconciliationSection.tsx:293`); reason prompt `:312`; manager-PIN `:334`; reveal summary `:301`; expected/variance columns `CashCountTable.tsx:63-64`, `:77-89`, `:116-155`; electronic Actual dashes at `:140`; numpad panel carries no expected value (`:162-183`); thermal-Z refs are written only inside `handleEndOfDayConfirm` after `generateZReport` (`Header.tsx:409-411`, `:425`). All hold.
- **Existing defence weakened?** The legacy expected-cash card's SECURITY comment and `!cashCountEnabled` guard are byte-unchanged across the whole wave diff (`EndOfDayPreviewModal.tsx:316-323`).
- **i18n / DOM residue**: `cash_count.policy_unavailable` present at `en/pos.json:909` and `fr/pos.json:909`; hidden money is substituted with `'—'` and hidden columns are omitted, not CSS-hidden — nothing recoverable from the DOM.

**Rationale.** The round-4 P2 was the last live leak, and it is closed at the mechanism the audit claims — verified against the production caller, not just the test harness. The audit is a genuine render-path enumeration that states its clean verdicts and refuses a whole-device claim, with the two product-level residuals (Today's Sales derivation, static `ShiftClosurePage`) ticketed under R-8 rather than hidden. Everything I could still find is P3, non-disclosing, and does not warrant burning the last fix round; items 1 and 3 are doc/UX lines the orchestrator can fold into the existing availability ticket at M5.

VERDICT: ACCEPT
