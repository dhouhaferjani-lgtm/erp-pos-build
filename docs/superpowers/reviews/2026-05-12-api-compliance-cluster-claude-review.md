# api.compliance Cluster — Claude Review

Cluster: `api.compliance`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

NF525 export, fraud alerts, audit log queries (Compliance + Audit controllers + AuditService). Pre-sweep, several export/audit endpoints read company_id from request body rather than the authenticated `CompanyContext`, allowing cross-tenant query through user-supplied input.

Inventory callsite total: **11 / 11 fixed**.

## Implementation summary

Top fix commits: `251c93d2` (5 callsites), `2d7d81d5` (5), `009bd760` (1).

Top files: `FraudAlertController`, `Nf525ExportController`, `AuditController`, `AuditService`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Compliance/ComplianceTenantIsolationTest.php`.

## Per-round review trail

- `2026-05-04-api-compliance-cluster-codex-round3-review.md`
- `2026-05-04-api-compliance-cluster-opus-{review,round2,round3}-review.md`

Three Opus rounds — the highest-scrutiny path because fiscal-chain integrity intersects this cluster.

## Gates evaluated

1. **Body-supplied `company_id` rejected**: NF525 export (`exportJet`, `verifyChains`, `reprintLog`) now derives `company_id` from `CompanyContext::requireCompany()` rather than the request body.
2. **Header-supplied `company_id` rejected**: `AuditController::index` / `anomalies` use authenticated context.
3. **Fiscal-chain non-interference**: hash chains untouched; CI verify-chains stays green.
4. **`AuditService::getEventsForAggregate` scope**: tenant + company predicate applied at service entry (api.compliance.011).

## Non-blocking follow-ups

None.

## Disposition

APPROVE for master PR. Highest review density in the sweep (3 Opus rounds + Codex round-3) reflects the fiscal-compliance criticality.

## Cross-references

- Cluster-level test: `apps/api/tests/Feature/Compliance/ComplianceTenantIsolationTest.php`
