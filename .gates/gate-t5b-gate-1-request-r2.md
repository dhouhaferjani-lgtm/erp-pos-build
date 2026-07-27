# Treasury Phase ⑤b Gate 1 — statement foundation remediation review (round 2)

You are the **treasury-reviewer**. Review adversarially and code-first. Start with exactly `APPROVE` or `REJECT` on its own line. Cite actual `file:line` evidence for every finding. Do not edit, commit, merge, tag, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Gate base: `5673518eb`

Full gate range: `git diff 5673518eb...HEAD`

Remediation commit: `91d9814b1`

Read the original request and rejection first:

- `.gates/gate-t5b-gate-1-request.md`
- `.gates/gate-t5b-gate-1-verdict.md`

Then read the same authority chain from that request: spec Rev 2, all three plan reviews, Wave 1 Tasks 2–3, CLAUDE.md rules 1–21, the precision contract, and `.claude/agents/treasury-reviewer.md`.

## Required round-2 checks

Re-evaluate all eight original invariants and explicitly resolve every prior finding C1, C2, M1, M2, M3, and m1–m6. In particular verify:

1. All parsed amounts and optional balances now cross `CurrencyScale::bcformatStrict()` with the explicit repository-currency scale before bcmath, fingerprinting, or storage. Leading `+`, unsigned equivalents, and alternate decimal shapes converge to one canonical numeric string/fingerprint.
2. Balance detection occurs before zero-amount rows are dropped.
3. Over-allocation and negative allocation totals are rejected rather than derived as `Partial`.
4. XLSX numeric money is read from exact serialized worksheet XML, not `getFormattedValue()` or a PHP float. Formula cells use serialized evaluated values when present and otherwise remain evaluate-or-unparseable. Inspect the relationship resolution and XML safety, not just the happy test.
5. Every PostgreSQL CHECK family is now directly exercised: profile parser/direction, statement status/period, line amount/number/direction/status/ignore reason/ignore shape, allocation amount/type, execution action/digest/target pair/movements array. Both application and DB update/delete immutability paths are covered.
6. The added `ignore_reason` enum CHECK agrees with `StatementLineIgnoreReason` and the spec.
7. No test was weakened; the new regression tests were observed RED before the implementation changes.

## Fresh evidence after remediation

- SQLite combined parser/schema: 41 tests, 118 assertions, 18 PostgreSQL-only skips.
- Fresh PostgreSQL database aggregate schema: 33 tests, 110 assertions, zero skips.
- Pint `--test` on all remediation PHP paths: pass.
- PHPStan on all remediation production paths: no errors.
- `git diff --check`: pass.

## Required output

After the first-line decision:

1. Findings ordered Critical/BLOCKER → Important/MAJOR → Minor with exact `file:line` evidence.
2. A resolution table for C1, C2, M1, M2, M3, and m1–m6.
3. A fresh pass/fail table for the original eight gate invariants.
4. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and, if rejected, one exact required-fix line.
