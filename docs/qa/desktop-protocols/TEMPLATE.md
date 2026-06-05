# <Feature name> — Manual Test Protocol

**Protocol ID:** <e.g. B2>
**Target application:** IziPOS / Otospex desktop app (`apps/pos/`, Tauri 2)
**Feature source:** <branch / PR, e.g. `feat/xxx` (PR #NN)>
**Status:** 🟡 draft
**Tester:** ____________________
**Test session date:** ____________________

---

## What this verifies
<One paragraph, plain language: what the feature does for the user and what "working" means. Avoid implementation detail the tester doesn't need.>

## Scope
- **In scope:** <the user-facing behaviors this protocol checks>
- **Out of scope:** <related things tested elsewhere — link the other protocol>

---

## Preconditions
Complete the [shared environment setup](README.md#shared-environment-setup) first, then:

- [ ] <feature-specific precondition, e.g. "A customer exists with a charge account enabled">
- [ ] <e.g. "An open shift on the terminal under test">
- [ ] <test data needed>

> If any precondition can't be met, mark dependent scenarios `BLOCKED` and note which precondition failed.

---

## Walkthrough (happy path)
Numbered, observable steps. Each step = one action + what the tester should see.

1. <action> → <expected on screen>
2. <action> → <expected on screen>
3. ...

**Expected end state:** <what proves it worked — a balance change, a printed receipt, a synced row, a success modal>

---

## Edge & negative probes
At least one. These are where bugs hide.

- ⚠️ <e.g. "Enter 0 as the amount → app rejects with a clear message, no event recorded">
- ⚠️ <e.g. "Disconnect network, do the action, reconnect → action persisted locally and synced on reconnect">
- ⚠️ <e.g. "Repeat the exact action twice → no duplicate / idempotent">

---

## Scenario table
Copy into a spreadsheet to record results. Status: `PASS`/`FAIL`/`BLOCKED`/`SKIP`/`N/A`. Severity only if `FAIL`.

| Test ID | Scenario | Steps (short) | Expected result | Status | Actual behavior | Severity | Tester | Date |
|---------|----------|---------------|-----------------|--------|-----------------|----------|--------|------|
| <ID>-01 | Happy path | <…> | <…> |  |  |  |  |  |
| <ID>-02 | <edge> | <…> | <…> |  |  |  |  |  |

---

## Notes for the tester
<Anything environment-specific, known quirks, or "if you see X, that's expected">
