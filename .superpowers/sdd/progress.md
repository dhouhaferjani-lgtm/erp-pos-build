# Treasury burn-down SDD ledger — started 2026-07-28
Task 1: complete (commits 34ae4ceba..446041fc3, Codex APPROVE round 4 after 3 REJECTs; suggestion loading SQL-bounded, all caps fail-safe)
Task 2: complete (commits 091efd459/d0169c741/323cef01a, Codex r1 REJECT -> r2 closed via adjudication + mutation evidence, no code delta)
Task 3: complete (commits 3b11b35af/ef6b86a7e, Codex REJECT adjudicated closed — both findings out-of-scope/false-positive; port clean per review)
Task 4: complete (commits 1729ae6bd..8adc07c80, Codex r1 REJECT -> r2 3/4 pass -> r3 mechanical fix verified; 5 web fixes + hardened formatDate)
Task 5: complete (commits 83efaa89f+6f7e3c3f5, Codex A-W-F closed; live-run evidence SETTLED 2026-07-29 — 7/7 twice on the local db-per-tenant stack, incl. a fr-FR browser locale proving the 5a pin, plus an observed reaper skip and terminal-clean teardown; see task-5-report.md)
Task 6: complete (commits 2145b240a+88d0079aa, Codex A-W-F closed; committed-map freshness now CI-guarded)
Task 7: complete (commits 331b94b14+be95f49bc, Codex A-W-F closed; ar treasury 517-key backfill to parity)
Task 8: complete (commits 6351fd9a3 -> e0802b5af -> cc09a2ffe; Codex r1 REJECT -> r2 REJECT -> all 6 r2 items actioned; 18 tests/121 assertions; 3 r1 findings adjudicated out of scope + ticketed)

GATE — final whole-branch Codex review (origin/dev...cc09a2ffe, 34 commits/48 files):
APPROVE-WITH-FIXES, NO blocking findings. Record: docs/superpowers/reviews/2026-07-29-burndown-whole-branch-codex.md
Both Minor findings fixed: fr `movementsProduced_many` (CLDR `many` category en does not
have — an en-vs-fr key diff structurally cannot catch it), and a dry-run count assertion
strengthened to match its test name (RED-verified). Verified clean: no dangling refs to the
T2-deleted models, fiscal-payload freeze respected, permissions map re-derived and exact,
rules 3/12/13/14/19 sweeps, no migrations, clean merge into local dev.
🎫 Ticketed, NOT fixed here (pre-existing, rule 4): French `_many` plural backfill repo-wide
— 19 remaining bases across 9 other namespaces.
BRANCH IS MERGE-READY.
