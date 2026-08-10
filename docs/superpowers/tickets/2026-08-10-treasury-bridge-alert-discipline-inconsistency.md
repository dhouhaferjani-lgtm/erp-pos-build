# `TreasuryReceiptBridge` Has Four Operator Alerts and Three Failure Disciplines

Raised by: treasury-reviewer gate verdict (item I, 2026-08-10), finding 5.
Status: needs a deliberate policy, then a unification pass.

## The inconsistency

Four alert writers on the same class, three different behaviours when the alert write itself fails:

| Alert | Wrapper | On write failure | Deliberate? |
|---|---|---|---|
| `recordTolerancePurposeMissingAlertOrFail()` | savepoint + **rethrow** | projection rolls back, retries, eventually dead-letters | Yes — item I, fix round 1 documented it |
| `alertIfShortfallExceedsConfigSafely()` (`:653-672`) | savepoint + **swallow** | alert silently dropped, write-off stands | Yes — the money already moved |
| `recordChangeExceedsCashLegsAlert()` (`:996`) | none | exception propagates, projection rolls back | **By accident** — fail-closed with no savepoint |
| `recordMaturityRefundAlert()` (`:1533`) | none | exception propagates, projection rolls back | **By accident** — fail-closed with no savepoint |

The two uncontained writers land on the right outcome for the wrong reason. Without a savepoint, a failed INSERT on PostgreSQL poisons the enclosing transaction (`25P02`), so everything after it fails too — the rollback happens because the transaction is already unusable, not because anyone decided the alert was load-bearing. That is fragile: it will change behaviour the moment someone wraps the call, reorders it, or adds a `catch` upstream.

## The one that actually matters

`alertIfShortfallExceedsConfigSafely()` swallows a **till-shortage / potential-fraud signal**. The stated justification — "the write-off is POSTED regardless, telemetry must not undo money" — is a good argument for not rolling back the write-off. It is *not* an argument for losing the signal entirely. A cashier till shortage beyond the configured ceiling is exactly the kind of event that should survive a transient database fault.

## What to decide

- Classify each alert: is it **evidence** (must be durable; losing it invalidates the acknowledgement) or **telemetry** (nice to have; never worth undoing committed money)?
- For evidence alerts that must not roll back committed money, the answer is neither swallow nor rethrow — it is a **durable out-of-transaction sink**: log at `critical` with full context and/or an outbox row written on a separate connection, so the signal survives without touching the money transaction.
- Then make the wrapper choice explicit at all four sites and delete the accidental cases. The two savepoint wrappers currently look almost identical and mean opposite things — fix round 1 documented that contrast on the docblocks (I2) but the underlying asymmetry remains.

## Scope note

Fix round 1 deliberately did NOT unify these. The item-I change was scoped to the purpose-missing path only; touching the till-shortage discipline changes fraud-signal behaviour and deserves its own review and its own tests.
