# Impersonation Audit Adversarial Verdict

Date: 2026-08-06  
Scope: authoritative session chain, central and tenant mirrors, fail-closed writes, verification tooling  
Verdict: **APPROVE**

## Evidence

```text
SessionChainVerifierTest                  4 passed, 23 assertions
ImpersonationAuditTest                    4 passed, 51 assertions
Audit/write/context regression           20 passed, 165 assertions
Pint verification                        clean
```

## Adversarial Results

| Attack | Result |
|---|---|
| Change a canonical event field | Verification fails at the first altered sequence. |
| Change `previous_hash`, event `hash`, or the session head | Verification fails. |
| Remove or alter the central admin mirror | `support-access:audit-verify` exits non-zero. |
| Remove or alter the tenant mirror | `support-access:audit-verify` exits non-zero. |
| Make the admin mirror writer fail | Request returns `503 IMPERSONATION_AUDIT_UNAVAILABLE`; the controller is not called. |
| Make the tenant mirror writer fail | Request returns `503 IMPERSONATION_AUDIT_UNAVAILABLE`; the controller is not called. |
| Update or delete a session-chain event | Database append-only triggers reject the operation. |
| Reuse an event sequence | The `(session_id, sequence)` unique constraint rejects it. |

## Chain and Mirror Review

The authoritative event hash covers the event UUID, session UUID, sequence, event type,
outcome, operator and subject identities, tenant, request/route/method/path/status/error,
typed details, occurrence time, and previous hash. Appends lock the session row, derive the
next sequence from the locked state, write the immutable event, and advance the session head.
Independent fixed vectors protect canonicalization from an implementation-only test oracle.

Both `admin_audit_logs` and tenant `audit_events` store the same event UUID, sequence,
previous hash, hash, `impersonator_id`, and `impersonation_session_id`. The verifier checks
the authoritative chain and exact mirror parity, rather than merely checking that a related
row exists.

## Failure Semantics

Audit runs before the route controller. A central-chain or mirror failure cannot be silently
downgraded and cannot permit the requested tenant action. Because the databases cannot share
one atomic transaction, an outage after the authoritative append can leave detectable partial
evidence; the request still fails closed and the verification command identifies the missing
mirror for reconciliation.

No audit milestone blocker remains.
