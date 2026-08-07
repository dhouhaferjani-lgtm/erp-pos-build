# Grant Lifecycle Adversarial Verdict

Date: 2026-08-06  
Scope: consent grants, tenant isolation, four-eyes activation, expiry, revocation kill-switch  
Verdict: **APPROVE**

## Evidence

Focused verification:

```text
GrantLifecycleTest                         8 passed
SessionTokenTest                          6 passed
ImpersonationContextMiddlewareTest        8 passed
Combined support + identity gate         34 passed, 169 assertions, 1 PG-only skip
Repeated context middleware gate          8 passed, 43 assertions
```

The PostgreSQL-only pre-existing `CentralPersonalAccessTokenPhase0bTest` was skipped by the SQLite path gate as designed. The new SQLite token test independently proves the central PAT row has `tokenable_type=User` and the exact subject id. Real database-per-tenant resolution remains a mandatory live-PostgreSQL final gate.

## Adversarial Findings

| Attack | Result |
|---|---|
| Operator submits an empty purpose | Rejected before persistence. |
| Operator binds tenant A to tenant B's subject | Rejected by the tenant-aware subject directory. |
| Tenant B admin decides tenant A grant | Rejected inside the service, independent of route middleware. |
| Ordinary tenant user creates a support window | Rejected without `support-access.manage`. |
| Sensitive tenant activates after tenant consent alone | Remains `pending_internal_approval`. |
| Requesting operator approves their own sensitive grant | Rejected even if their email appears in the configured allow-list. |
| Unknown, inactive, empty, or malformed second-approver configuration | Fails closed. |
| Approval races grant expiry | Locked transition persists `expired` and refuses activation. |
| Duplicate revocation changes retained evidence | Idempotent; first revoker, time, and reason remain unchanged. |
| Live bearer token is reused after tenant revocation | First request succeeds; the immediately following request returns `401 IMPERSONATION_ENDED` before the probe. |

## Tenancy and Race Review

- Every grant transition locks the central grant row before evaluating status and actor identity.
- Tenant decisions derive tenant id from the authenticated tenant user; no request tenant id participates in approval/rejection/revocation.
- Per-incident grants bind a concrete subject after resolving that user in the addressed tenant database. Compatibility tests use the deliberately shared test schema; production uses `Tenant::run()`.
- Pre-granted windows permit a null subject/operator scope, but session start must bind a concrete active subject and authenticated operator.
- Grant identity pointers do not cascade-delete retained evidence.
- Revocation does not trust token deletion as its only control. The request middleware re-reads the grant on every impersonated request, so a still-present bearer token is denied on the first request after revocation.

## Residual Check

No grant-lifecycle blocker remains. Session-row closure and PAT deletion on explicit exit are handled by the session-end slice; the tested grant kill-switch already fails closed independently of that cleanup.
