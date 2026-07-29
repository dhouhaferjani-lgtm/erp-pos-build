# Codex adversarial review — burn-down Task 3 (Expense read port)

Diff: 774d37d27..ef6b86a7e · Reviewer: Codex CLI (via codex-rescue agent) · 2026-07-29
(Codex sandbox cannot write into this worktree; transcribed by the orchestrator.)

## Round 1 Verdict: REJECT → CLOSED via adjudication (no code change)

- **[Critical→out-of-scope]** 15 pre-existing `Modules\Treasury` imports remain elsewhere
  in the Expense module (`ExpenseMetadata`, `ExpenseRecurrenceTemplate`, `ExpenseService`,
  `PayExpenseRequest*`, `GenerateRecurringExpensesCommand`). None introduced by this diff;
  none in the targeted listener. **Adjudicated: known SoC debt tracked by the hexagonal
  SoC audit (deptrac baseline, 775 edges) — expanding this task to detangle them is
  forbidden scope creep. The task's target (SyncExpenseOnInstrumentLifecycle) is clean.**
- **[Critical→false positive]** `app(CompanyContext::class)` in the new seam test.
  **Adjudicated: sanctioned test convention — CLAUDE.md rule 20 itself prescribes
  `app(CompanyContext::class)` in projection tests; the constructor-injection rule
  governs production code.**

## Clean per Codex

Port minimal + strictly typed; binding in Treasury's provider, worker-safe (no
request-time context in resolver); behavior parity confirmed; `InstrumentCleared` /
`InstrumentCancelled` byte-identical (SHA-256) across commits; seam test genuinely
discriminating (value divergence); fiscal-freeze compliant.

Task 3 closed at commits 3b11b35af / ef6b86a7e.
