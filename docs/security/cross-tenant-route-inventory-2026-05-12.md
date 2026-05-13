# CrossTenantRoute Inventory — 2026-05-12

> **Plan reference:** `docs/superpowers/plans/2026-05-12-dev-go-live-remediation-plan.md` §M2.0
> **Companion CSV:** `cross-tenant-route-inventory-2026-05-12.csv`
> **Branch:** `chore/dev-go-live-remediation`.

This is the M2.0 deliverable: a complete enumeration of every `#[CrossTenantRoute(reason: ...)]` annotation in `apps/api/app/`, with an initial classification per the remediation plan. The CSV is the source of truth; this markdown explains how to read it.

## Counts

| Classification | Count | Meaning |
| --- | --- | --- |
| `fix-now` | 24 | The 5 audit-named controllers (M2.1–M2.5). Each method must be fixed before broad multi-tenant launch. |
| `legitimate-platform-candidate` | 61 | Super-admin / webhook / platform-shared catalog / public reference / Spatie-team-scoped routes. Initial classification — must be reviewed and locked in `legitimate-platform` after a one-line justification per row. |
| `TBD-needs-review` | 29 | Annotated routes the heuristic couldn't auto-classify. Human triage required. |
| **Total** | **114** | (Matches the audit's "117 annotations" minus the `CrossTenantRoute.php` attribute class itself and the two sweep-visitor false positives.) |

## Heuristic basis

The auto-classifier in the inventory script applied these rules in order:

1. **`fix-now`** — the controller class name matches one of the M2.1–M2.5 audit-named controllers:
   - `ProductImageController`
   - `DocumentEmailController`
   - `DocumentAdditionalCostController`
   - `RoleController`
   - `PurchaseHubOfferController`
2. **`legitimate-platform-candidate`** when the file path or annotation reason matches any of:
   - `Http/Controllers/Api/Admin/SuperAdmin*`
   - controller class name ends with `Webhook`
   - reason mentions "Public reference data", "Static enum", "load-balancer health probe", "Permissions catalog", "Spatie TeamScope auto-scoping", "PurchaseHub outbound integration", "Marketplace outbound", "super-admin", "platform-level", "platform-defined"
   - controller class is in the known lookup/monitoring set (`BarcodeLookupController`, `VinDecodeController`, `EnrichmentWebhookController`, `MonitoringController`, `StampDutyRuleController`)
3. **`TBD-needs-review-fix-likely`** when the reason starts with `KNOWN TENANT-ISOLATION GAP` (only `fix-now` rows that didn't match the named controllers escape here — none exist on this branch; all 24 KNOWN-GAP rows are in the M2.1–M2.5 controllers).
4. **`TBD-needs-review`** — fallback.

## Per-classification next steps

### `fix-now` (24 rows)

Already covered by §M2.1–M2.5 of the remediation plan:

| Controller | Plan task |
| --- | --- |
| `ProductImageController` | §M2.1 |
| `DocumentEmailController` | §M2.2 |
| `DocumentAdditionalCostController` | §M2.3 |
| `RoleController` | §M2.4 |
| `PurchaseHubOfferController` | §M2.5 |

These tasks ship before broad multi-tenant launch. The first-tenant pilot accepts these gaps as documented risk only if the pilot scope does NOT expose these routes to the operator (e.g., IziPOS retail pilot with no document-email UI surface).

### `legitimate-platform-candidate` (61 rows)

Each row needs a one-line confirmation that:

- The route runs behind `super_admin` middleware or has an equivalent gate, AND
- The reason text in the annotation accurately describes why cross-tenant access is intended.

Once confirmed, the row moves to `legitimate-platform` in the `classification` column and the `notes` field records the reviewer + confirmation date.

Routes that fail confirmation move to `fix-now` (or `accept-with-doc` with a written risk acceptance).

### `TBD-needs-review` (29 rows)

These are the routes whose annotation reasons the heuristic couldn't auto-classify. A reviewer reads the reason text and decides:

- `fix-now` — the annotation flags a real cross-tenant gap.
- `legitimate-platform` — the annotation describes intended cross-tenant behavior (e.g., platform-shared catalog read).
- `accept-with-doc` — pilot scope avoids the route; risk-acceptance written in `fix_pr_or_acceptance`.
- `defer` — route is unreachable in the launch scope; will revisit before broad multi-tenant launch.

The reviewer also fills in `first_tenant_exposed`. Any `first_tenant_exposed=yes` row that lands as anything other than `legitimate-platform` blocks first-tenant launch until either fixed or risk-accepted.

## First-Tenant Gate

Per the remediation plan §M2.0 + First-Tenant Ready:

- ✅ All `CrossTenantRoute` annotations enumerated (114 rows).
- ✅ Initial classification heuristic applied; 85 rows pre-classified.
- ⬜ 29 `TBD-needs-review` rows require human triage.
- ⬜ 61 `legitimate-platform-candidate` rows require one-line confirmation.
- ⬜ Verification step (per remediation plan §M2.0 Step 5):
  ```bash
  awk -F, 'NR>1 && $7 ~ /TBD/ {print}' docs/security/cross-tenant-route-inventory-2026-05-12.csv | wc -l
  # must be 0 before the first-tenant gate closes
  ```

The remediation branch does NOT block on the human-triage rows; they queue against the security owner. The branch DOES require the enumeration to exist (this CSV + this README), which is satisfied here.

## Regeneration

To regenerate the CSV after future codebase changes:

```bash
python3 scripts/generate-cross-tenant-inventory.py
```

(Script lives at `scripts/generate-cross-tenant-inventory.py` — see next commit for the canonical version.)

## Owners

- Security reviewer / tech lead: TBD
- Platform owner (for `legitimate-platform-candidate` confirmations): TBD
- Each `fix-now` controller has its own M2.x task owner in the remediation plan.
