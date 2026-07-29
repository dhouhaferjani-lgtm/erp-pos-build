# Codex adversarial review — burn-down Task 2 (mechanical backend trio)

Diff: c3caeee91..323cef01a · Reviewer: Codex CLI (via codex-rescue agent) · 2026-07-29
(Codex sandbox cannot write into this worktree; transcribed by the orchestrator.)

## Round 1 Verdict: REJECT

- **[Important]** `AllocateStatementLineRequest.php:37` — unsigned regex vs the brief's
  signed `-?` pattern. **Adjudicated (controller): implementation is CORRECT** — the
  allocation domain requires positive amounts (`matched_amount > 0` DB constraint,
  `StatementMatchingService.php:396-405` guard) and 3/4 sibling Treasury FormRequests are
  unsigned. Plan text amended; no code change.
- **[Important]** `OutboundCancelReopenTest.php:308` — the report's "red-first" claim for
  2c is false: the fail-loud guard pre-existed (821bf9d9f), so the regression test was
  never red. Test itself functionally sound (real inconsistent state, correct message,
  zero side-effect writes). **Fix required:** mutation-check evidence proving the test
  bites + honest report correction.
- **[Minor]** 2a red-first chronology unverifiable (test+fix in one commit, no captured
  red output).
- **[Minor/clean]** 2b deletion verified clean by full-tree grep; no live references.
- **ReconciliationStatus enum left in place:** adjudicated acceptable scope discipline,
  not a defect (brief scoped deletion to models + exclusive factories/seeders).
