# Codex adversarial review — burn-down Task 7 (Arabic treasury backfill)

Diff: 0a0d9ac69..331b94b14 (+fix be95f49bc) · Reviewer: Codex CLI · 2026-07-29
(Transcribed by the orchestrator.)

## Round 1 Verdict: APPROVE-WITH-FIXES

Mechanical checks all PASS: parity recomputed independently (741 en leaves, 762 ar,
zero missing; 21 ar-only keys = valid CLDR plural variants); additivity byte-clean
(240 pre-existing values untouched); no duplicate keys at any level, single
`repositories` block; ICU token sets exact on all 22 interpolated values; all five
plural families carry the full six Arabic CLDR forms.

- **[Important]** "Write-off" rendered إعدام in 7 tolerance keys — inconsistent with the
  established شطب (`ar/documents.json` tolerance usage).
- **[Minor]** `instruments.markAsBounced` تعليم كمرفوضة unnatural.

## Round 2 outcome (fix be95f49bc): CLOSED — controller-verified
شطب across all 7 keys (grep: 0 إعدام remaining), وضع علامة مرفوضة matching ar/income.json
phrasing; parity still zero-missing; all 8 edited keys were Task-7-added; vitest 8/8.
Reviewer-prescribed fixes verified via evidence; whole-branch review is the net.
Task 7 closed at 331b94b14 + be95f49bc.
