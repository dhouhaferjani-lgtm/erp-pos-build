GATE VERDICT: APPROVE

# Gate t5a-gate-3 combined decision

## Treasury lane — APPROVE

- Required model invocation: `claude-fable-5` directly on `.gates/gate-t5a-gate-3-request-treasury.md`.
- Raw verdict: `.gates/gate-t5a-gate-3-verdict-treasury.md`.
- Result: no Critical or Important findings; deferred-supplier legacy JE and movement suppression, replacement issue posting, regressions, outbound lifecycle delegation, liability-normal reconciliation, and maturity alerts verified.

## Tenancy/authz lane — APPROVE after one fix round

- Required model invocation: `claude-opus-4-8`.
- Round 1 raw verdict: `.gates/gate-t5a-gate-3-verdict-tenancy-authz.md` — REJECT on missing cross-company endpoint coverage and incomplete manager-deny coverage; shipped scoping and permissions were verified correct.
- Fix commit: `3276b1b13` adds real second-company 404 assertions for all four actions, proves no state mutation, and covers manager 403 on all four actions.
- Fresh fix evidence: SQLite 6 tests / 23 assertions; PostgreSQL 6 / 23; PHPStan level 8 clean; Pint pass.
- Round 2 raw verdict: `.gates/gate-t5a-gate-3-verdict-tenancy-authz-r2.md` — APPROVE, both findings closed with no permission/middleware weakening.

VERDICT: spec ✅ + quality APPROVED

Nothing remains before Wave 4.
