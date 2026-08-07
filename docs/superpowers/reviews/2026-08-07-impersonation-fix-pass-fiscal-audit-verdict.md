# Tenant Impersonation Fix-pass Re-review — Fiscal and Audit

Date: 2026-08-07

Reviewed implementation commit: `63f14987a07c2198db3162f1e583b1b7f7d0dc60`

Reconfirmed branch head: `c52d0f71faf66352a439e3d834813f96f43406be`

## Findings

No blocker, major, or minor fiscal/audit findings remain.

## Evidence

- Grant state, grant event, every live session's `GrantRevoked` and `SessionEnded` events, session state, and all delivery rows commit in one central transaction before mirror delivery.
- Forced grant-mirror failure leaves the grant revoked and child session ended with both authoritative events present.
- The first request using an ended token is denied and chained before the token is deleted. Audit failure retains the token for a fail-closed retry.
- Mirror reconciliation runs every minute with overlap protection. Poison rows are isolated, logged, counted, and make the command fail after later rows have continued. Remaining backlog is warned.
- The grant-audit migration refuses rollback once authoritative evidence exists instead of deleting retained audit records.
- Scheduled natural grant/session expiry, authoritative events, session termination, and token revocation remain intact.
- Verification covers duplicate/missing mirrors, linkage, actor, tenant/company/user, timestamps, payload/metadata, and persisted legacy-hash tampering.
- Route-table enumeration hard-blocks every registered POS, Fiscal, and Accounting mutation.
- No POS, Fiscal, or Accounting production file changed in this remediation. `AuditEvent::calculateHash()` remains byte-identical, and impersonation fields remain outside its input.

Verification:

- SupportAccess suite: 85 passed, 594 assertions, one expected local PostgreSQL skip.
- Live PostgreSQL end to end: one passed, 132 assertions.
- Both support-access scheduled commands appear in `schedule:list` at one-minute cadence.
- `git diff --check`: pass.

The `63f14987a..2f779989` delta is CI-only. Both backend lanes now use the disposable `autoerp_test` database for their named central connection. The partitioned manual gate covers all 1,542 PHPUnit files configured by `phpunit.xml`, continues through failed partitions, and aggregates failure. The two `tests/PHPStan/*Test.php` files remain outside `phpunit.xml`, matching the prior unpartitioned command. YAML parsing and `git diff --check` pass. No production, fiscal, audit, or feature-test code changed, so the acceptance was reconfirmed at `2f7799890`.

The `2f779989..c52d0f71` delta changes only the PostgreSQL E2E test lifecycle. Its early return is reachable before tenancy, authentication, tenant creation, or audit writes, and only when PostgreSQL schema setup never completed. PostgreSQL cleanup remains unchanged after migration, setup exceptions remain failures, and the dedicated PostgreSQL path still passes all 132 assertions. No audit evidence is suppressed, so acceptance was reconfirmed at `c52d0f71f`.

Final exact-head CI run `31202946159` confirms the live PostgreSQL SupportAccess path and teardown pass. The SQLite full-path SupportAccess partition cleanly skips only that PostgreSQL test and reports 82 tests, 571 assertions, no errors, and no failures.

## Verdict

**ACCEPT.** No fiscal or audit defect remains in the reviewed scope or in either test-infrastructure branch-head delta.
