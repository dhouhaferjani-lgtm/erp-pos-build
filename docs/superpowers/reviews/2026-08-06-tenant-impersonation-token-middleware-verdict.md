# Token and Middleware Adversarial Verdict

Date: 2026-08-06  
Scope: subject token topology, claim enforcement, permission intersection, request fail-closed behavior  
Verdict: **APPROVE**

## Evidence

```text
SessionTokenTest                          6 passed, 27 assertions
ImpersonationContextMiddlewareTest        8 passed, 43 assertions (repeated green)
EnforceTokenTenantClaimTest               5 passed
Combined support + identity gate         34 passed, 169 assertions, 1 PG-only skip
Pint second pass                          clean
```

## Claim and Identity Attacks

| Attack | Result |
|---|---|
| Start without a grant | HTTP 403. |
| Pending, expired, revoked, or out-of-window grant | No token; 403 at start or 401 on a live request. |
| Wrong incident operator | Rejected. |
| Wrong tenant or subject | Rejected. |
| Duplicate operator/session claim | `401 IMPERSONATION_ENDED`; never downgraded to an ordinary request. |
| Session/operator/subject/tenant/token-id mismatch | `401 IMPERSONATION_ENDED`. |
| Ended or expired session | `401 IMPERSONATION_ENDED`. |
| Central session lookup throws | `401 IMPERSONATION_ENDED`; probe not reached. |
| Token tenant claim is changed | Existing `EnforceTokenTenantClaim` still returns `TOKEN_TENANT_MISMATCH`. |

The minted PAT's tokenable is the tenant `User`, not `SuperAdmin`. Its security claims occur exactly once: `tenant:<tenant_id>`, `impersonation:<operator_id>`, `impersonation-session:<session_id>`, and `support:read`. Expiry is `min(now + 60 minutes, grant expiry)`.

## Permission-Escalation Attacks

- Read-only effective permissions are configured support scope intersected with the subject's current Spatie permissions.
- A configured support permission absent from the subject never enters the PAT or session evidence table.
- A subject write permission outside read-only scope is absent from the PAT, direct `hasPermissionTo()`, Gate, and `AuthUserData`.
- The middleware discards stored `permission:*` abilities and rebuilds them from live subject grants on every request.
- Removing a subject permission between two requests makes the second Gate check fail even though the database PAT row still contains the old minted ability.
- Malformed permission configuration fails closed to an empty effective set.
- Ordinary user tokens and session-cookie authentication retain the pre-existing Spatie behavior.

## Middleware Ordering

The production priority is:

```text
ResolveTenancy
auth:sanctum
SetPermissionsTeam
EnforceTokenTenantClaim
ImpersonationContext
route authorization / controller
```

This preserves pre-auth tenant selection, applies the existing claim check before impersonation context, and replaces permission abilities before any `can:` middleware runs.

## Residual Check

No token/middleware blocker remains. The existing database-per-tenant PAT regression is PostgreSQL-only and was skipped in the SQLite path gate; it must pass during the required live local tenant-database E2E before final approval.
