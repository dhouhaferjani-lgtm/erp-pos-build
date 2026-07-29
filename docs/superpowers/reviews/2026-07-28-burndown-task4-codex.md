# Codex adversarial review — burn-down Task 4 (web fixes)

Diff: e383b5210..f94fa9802 · Reviewer: Codex CLI (via codex-rescue agent) · 2026-07-29
(Codex sandbox cannot write into this worktree or run vitest; verified via live-source
inspection + zero-write Node probes; transcribed by the orchestrator.)

## Round 1 Verdict: REJECT

- **[Important]** `format.ts:165` — date-only branch lets the native Date constructor
  normalize invalid/legacy inputs: `0099-01-01` → 01/01/1999 (century mapping),
  `2026-13-01` → 01/01/2027 (rollover) instead of `''`. Existing invalid-input test only
  covers non-matching garbage.
- **[Minor]** `format.test.ts:32` — TZ restored by assigning `undefined` → literal
  string "undefined"; use delete.
- **[Nit]** `ManualMatchSearch.test.tsx:57` — min/max assertion defaults missing
  attributes to "0"; passes even if attributes removed.
- **[Minor]** `StatementUploadWizard.tsx:241-243` — guards silently drop invalid values
  leaving stale state; make the ignore explicit.
- 4b pluralization, 4d module-key rename, cross-cutting (colors/i18n/ar JSON): clean.

Fix round dispatched with all four findings.

---

# Round 2 (fix 4152bf043): items 2/3/4 PASS; item 1 residual (0099 under-asserted)
# Round 3 outcome (fix 8adc07c80, 2026-07-29): CLOSED — controller-verified
Sub-1000 years rejected as invalid business dates ('' like month>12), removing the
century-mapping question entirely; 0099-01-01 test asserts '' exactly. Reviewer-prescribed
mechanical fix verified by orchestrator diff inspection; final whole-branch review is the
remaining net. Task 4 closed at 1729ae6bd..8adc07c80.
