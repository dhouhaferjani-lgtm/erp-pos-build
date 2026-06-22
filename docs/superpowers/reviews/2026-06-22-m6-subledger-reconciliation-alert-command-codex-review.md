# M-6 Codex Review — Subledger Reconciliation Alert Command

Date: 2026-06-22
Scope:
- `CheckSubledgerReconciliationCommand`
- accounting command registration
- scheduler registration
- command feature test

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Checks

- The command iterates tenants through `TenantScopedCommand::forEachTenant()` and scopes companies by `tenant_id`, satisfying the per-tenant scheduler pattern.
- The default purpose set covers customer receivable, customer advance, and supplier payable subledgers.
- Discrepancies are reported to command output and logged with structured context including `entries_without_partner`, difference, control balance, subledger total, account code, tenant, company, and purpose.
- The command returns failure when discrepancies are found, giving the scheduler/runbook a clear alert signal.
- Scheduler registration is covered by `schedule:list` assertion.
- The feature test seeds a real posted partnerless AR discrepancy and verifies `entries_without_partner=1`.

## Residual Risk

- Missing chart accounts are skipped rather than alerted. This matches M-6's alerting scope for subledger/control discrepancies, but chart completeness remains a separate operational concern.
