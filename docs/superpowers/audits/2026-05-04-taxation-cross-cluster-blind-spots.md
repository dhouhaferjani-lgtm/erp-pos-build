# Taxation cross-cluster blind spots (Opus round-1 Finding 5 — DEFERRED)

Audit date: 2026-05-04
Reporter: Opus 4.7 (round-1 adversarial review of api.taxation cluster)
Triage: Claude Opus 4.7 (orchestrator)
Status: DEFERRED — out of api.taxation scope per kickoff brief; tracked here for follow-up sweeps

## Context

The api.taxation cluster closed 13 inventoried callsites + 8 hostile-grep blind spots (5 in WithholdingCertificateController, 2 in SalesWithholdingTrackingController, 4 in WithholdingTaxRuleController) bundled into round-2.

During the round-1 adversarial review, Opus surfaced one additional finding outside the cluster's natural boundary: `StampDutyRuleController` mirrors the `tax_configurations` country-scoped global-reference pattern that the inventory closed, but its show / update / destroy methods still use unscoped `findOrFail`.

Per the kickoff brief: "If you find a cross-cluster blind spot, document and defer." The StampDutyRuleController surfaces fall outside the api.taxation cluster scope (not in inventory, not in same controller as inventoried fixes) and are therefore deferred.

## Finding A — StampDutyRuleController::show / update / destroy unscoped (Opus round-1 Finding 5, NICE-TO-HAVE)

**Severity**: NICE-TO-HAVE (consistency with sibling tax_configurations fix; symmetric global-reference pattern)

**Surface**: `apps/api/app/Modules/Taxation/Presentation/Controllers/StampDutyRuleController.php` lines 49 (show), 82 (update), 101 (destroy).

**Issue**: `stamp_duty_rules` is a country-scoped global reference table (FK to `countries.code`, NO `tenant_id`, NO `company_id` — verified at `database/migrations/2025_12_30_101000_create_stamp_duty_rules_table.php`). Same shape as `tax_configurations`. The api.taxation cluster's inventoried fixes (api.taxation.008-010) applied `country_code` scoping to TaxConfiguration sister methods (show / update / destroy in TaxConfigurationController). For symmetry and consistency with the cluster invariant Codex established, StampDutyRuleController should mirror the pattern.

Without the fix, a tenant-A admin (in country FR) could update a Tunisian stamp-duty rate, producing cross-country fiscal mis-configuration. While not a tenant-isolation breach in the strict sense (the table is by-design shareable across tenants in the same country), the cluster's country-scoped fix established a stricter "company.country_code matches" invariant that StampDutyRuleController violates.

**Recommended fix**: mirror the api.taxation.008-010 pattern:

```php
$company = $this->companyContext->requireCompany();
$rule = StampDutyRule::where('country_code', $company->country_code)
    ->findOrFail($id);
```

Apply to show / update / destroy methods in StampDutyRuleController.

**Future cluster**: belongs to `api.taxation.stampduty` follow-up cluster (or could be a NICE-TO-HAVE remediation in the next api.taxation review round). Acceptable to defer per the kickoff brief.

## Resolution

The api.taxation cluster closes with:
- 13 inventoried callsites closed at e7a2f543 (round-1 fix).
- 8 in-cluster blind spots closed at the round-2 fix (Opus round-1 Findings 1-4 + 6 test honesty).
- Finding 5 (StampDutyRuleController) deferred here for follow-up cluster sweep.

## Follow-up annotation attempt — 2026-05-05

Attempted to annotate StampDutyRuleController callsites in
`docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` as
`structurally_protected_by_country_scoped_reference` per the
session-final hardening directive.

**Bail-out reason**: no inventory rows exist for StampDutyRuleController
or `stamp_duty_rules`. `grep -nE "StampDuty|stamp_duty_rules"` against
the inventory YAML returned no matches — the scanners
(php_presentation_exists / php_ast_find) did not surface this controller
at generation time, presumably because it lives on a country-scoped
global-reference table that the GUARDED_TABLES list (Gate A) does not
include AND the controller's `findOrFail` chains were not flagged by
the Gate B `chainIsScoped` visitor (consistent behaviour with how
`tax_configurations` callsites surfaced through scanner-blind paths
during the api.taxation round-1 sweep).

Schema confirmation (kept here for owner-of-future-cluster):
- `database/migrations/2025_12_30_101000_create_stamp_duty_rules_table.php`
  defines `country_code` (FK to `countries.code`) ONLY. No `tenant_id`,
  no `company_id`. Same shape as `tax_configurations`.

**Owner**: a future `api.taxation.stampduty` cluster (or a scanner
extension that surfaces country-scoped global-reference findOrFails so
they enter the inventory in the first place — preferred). The
recommended-fix sketch in Finding A above remains the actionable
template.
