# Tenant Impersonation Fix-pass Re-review — Tenancy and Authorization

Date: 2026-08-07

Reviewed implementation commit: `63f14987a07c2198db3162f1e583b1b7f7d0dc60`

Reconfirmed branch head: `c52d0f71faf66352a439e3d834813f96f43406be`

## Findings

No blocker, major, or minor tenancy/authorization findings remain.

## Evidence

- Grant revocation, its authoritative grant event, every open child session's `GrantRevoked` and `SessionEnded` events, session terminal state, and delivery rows commit in one central transaction.
- Mirror delivery occurs only after terminal state commits. A mirror outage cannot leave the grant or session live.
- The retained bearer is powerless because live grant/session state is rechecked on every request. Its first post-revocation request is bound to the exact subject, operator, tenant, session, and PAT; the denial is chained before the PAT is deleted.
- Audit or token-deletion failure returns `IMPERSONATION_AUDIT_UNAVAILABLE`; the grant and session remain terminal and retries remain denied.
- Production permission scope remains constrained by unconditional exclusion of `support-access.*`, `roles.*`, `users.*`, and `auth.*`.
- Support-access, identity, role, password, destructive, fiscal, POS, and Accounting mutations remain hard-blocked.
- Impersonated tenant users cannot create, approve, reject, or revoke grants.
- Live subject permissions are recomputed before authorization. Malformed claims and configuration remain fail-closed.
- Middleware remains ordered tenant claim, impersonation context, write guard, audit, and masking.

Focused verification at the reviewed commit:

```text
BROADCAST_CONNECTION=null php artisan test \
  tests/Feature/SupportAccess tests/Unit/SupportAccess --compact

85 passed, 594 assertions, 1 skipped
```

The sole skip is the separately executed PostgreSQL acceptance test.

The `63f14987a..2f779989` delta changes only `.github/workflows/ci.yml`. Both backend lanes now bind the named central connection to their disposable `autoerp_test` database. The manual gate covers `Unit`, every direct `Feature` test file/directory, `Integration`, and `Architecture`; it continues after a failed partition and reports a final non-zero status. YAML parsing, shell syntax, and `git diff --check` pass. No runtime tenancy or authorization code changed, so the acceptance was reconfirmed at `2f7799890`.

The `2f779989..c52d0f71` delta adds a nine-line lifecycle guard only to the PostgreSQL E2E test. Before PostgreSQL schema migration completes, SQLite's intended skip reaches `parent::tearDown()` without central cleanup. After migration, the original cleanup path is unchanged. Setup exceptions remain failures, so the guard cannot hide a failed acceptance setup. No production or tenancy/authz assertion changed, and acceptance was reconfirmed at `c52d0f71f`.

Final exact-head CI run `31202946159` confirms the live PostgreSQL SupportAccess path and teardown pass and the SQLite full-path SupportAccess partition reports 82 tests, 571 assertions, one intended skip, and no errors or failures.

## Verdict

**ACCEPT.** The revocation changes strengthen atomicity without weakening tenant isolation, permission intersection, or immediate grant/session kill semantics. No tenancy/authorization finding was introduced by either test-infrastructure branch-head delta.
