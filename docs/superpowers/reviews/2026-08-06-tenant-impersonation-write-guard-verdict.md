# Write Guard Adversarial Verdict

Date: 2026-08-06  
Scope: readonly enforcement, four-eyes write elevation, exit, destructive and fiscal hard blocks  
Verdict: **APPROVE**

## Evidence

```text
ImpersonationWriteGuardTest                4 passed, 48 assertions
Write/context/token/claim regression      23 passed, 129 assertions
Pint second pass                          clean
Admin elevation routes                    auth:sanctum-admin + super_admin + SetPermissionsTeam
```

## Adversarial Results

| Attack | Result |
|---|---|
| Read-only GET/HEAD/OPTIONS | Allowed. |
| Unknown unsafe method while read-only | `403 IMPERSONATION_READ_ONLY`. |
| Elevation with blank reason | Rejected. |
| Operator self-approves elevation | Rejected. |
| Unknown or inactive approver | Rejected by the fail-closed configured approver set. |
| Configured partner approves | Session changes to `write_elevated`, approval identity and expiry are stored, PAT mode becomes `support:write`. |
| Expired elevation | Middleware automatically degrades the request to read-only. |
| Arbitrary readonly POST treated as exit | Rejected; only the exact named exit route is safelisted. |
| Subject exits | Session ends, grant is revoked, central PAT is deleted in one central transaction. |

## Absolute Blocks Under Elevation

The test first obtains a real partner-approved elevation, then proves the following still return `403 IMPERSONATION_ACTION_BLOCKED`:

- tenant deletion/deprovisioning;
- protected user password reset;
- public forgot-password and reset-password when an impersonation bearer is supplied;
- secret/key rotation;
- any `/fiscal/*` mutation and any controller in the Fiscal namespace;
- fiscal-event ingestion and fiscal-schema cutover;
- invoice and credit-note posting/cancellation;
- POS receipt void/refund;
- payment void/refund/partial-refund/reverse.

The classifier's default for an unknown unsafe action is `requires_elevation`, never safe. Hard-block route/path patterns are additive and run before elevation checks.

## Ordering and Bypass Review

Production request order is context → write guard → route authorization/controller. The guard also runs in the `web` group for a narrow pre-auth bearer check, closing the public forgot/reset route shape without converting those endpoints into authenticated routes. Ordinary callers with no impersonation bearer retain existing behavior.

No write-guard blocker remains.
