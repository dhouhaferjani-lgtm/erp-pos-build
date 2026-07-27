# Treasury Phase ⑤b Gate 2 — mandatory Fable escalation after two Opus REJECTs

You are the senior treasury adjudicator. Start with exactly `UPHOLD`, `OVERRULE`, or `SPLIT` on its own line. Do not edit, commit, merge, tag, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Gate base: `e06d2a831` (`t5b-gate-1`)

Current committed remediation: `abccafa6f`

This escalation is mandatory under `docs/handoff/CODEX-treasury-phase5-2026-07-18.md` because the treasury reviewer returned two consecutive REJECTs. Read the authority chain, spec Rev 2 §§5.1–5.3/6.6/9, all plan reviews, Task 4, CLAUDE.md rules 1–21, both Gate 2 treasury verdicts, the Gate 2 R2 tenancy verdict, and the full diff/current code.

Adjudicate these questions:

1. R2 treasury M1/M2: must Gate 2 block on the unbounded fingerprint `whereIn` and per-line inserts under the repository lock? If upheld, is chunked fingerprint lookup plus chunked bulk insert the correct minimum fix, and what safe chunk/bind constraints must be tested?
2. Void/re-import provenance: the current remediation deletes statement lines on void to free the repository-wide fingerprint unique key. The tenancy reviewer approved but carried a Major asking to preserve lines. Is the preferred resolution to add a `dedupe_active` boolean on statement lines, use a partial unique index where true, exclude inactive rows from fingerprint lookup, and set false on void under the statement lock? This preserves immutable parsed content while allowing exact-file re-import.
3. Migration upgrade path: should the original two create migrations be restored to their Gate 1 definitions and a new corrective tenant migration perform both partial-index conversions, ensuring developer/preview DBs that already ran the branch migrations are upgraded?
4. Spec conflict: R1 required same-file re-import after void despite spec §5.1's unconditional uniqueness wording. Provide the binding interpretation we should record in the handback/deploy docs without editing the locked authority spec.
5. State the exact tests required for approval, including whether an integration test must really parse/confirm more than 65,535 rows or whether a lower-size test plus structural chunk-bound assertions is sufficient.

Known local evidence before escalation:

- SQLite targeted paths: 59 tests, 212 assertions, 18 PostgreSQL-only skips.
- Fresh PostgreSQL Task 4/schema paths: pass.
- Pint and PHPStan clean; `git diff --check` clean.
- No GL or repository movement writes occur in import/void.

Required output: decision; findings ordered BLOCKER/MAJOR/MINOR with code evidence; binding answers to questions 1–5; exact remediation checklist; final line `ESCALATION VERDICT: GATE BLOCKED` or `ESCALATION VERDICT: MAY PROCEED`.
