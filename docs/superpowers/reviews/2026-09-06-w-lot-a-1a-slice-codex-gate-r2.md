# Codex slice-plan gate r2 — W-LOT-A-1a rev 2 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 2 at 55bf3d142. Verbatim.

---
# Adversarial gate result

Audited read-only at HEAD `55bf3d14204363ab857738f785a3c405100da9db`. No files were edited, no tests were run, and no git writes occurred.

All 75 local `path:line` citations in rev 2 resolve and remain in range at HEAD. The production drift since the planning SHA is limited to build-fingerprint infrastructure; cited application seams were rechecked.

## BLOCKER

### B-A — Q10 is assigned to the wrong slice boundary

- Plan: [rev 2:86](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:86>), [rev 2:131](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:131>), [rev 2:2205](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:2205>).
- Source: Q10 rules the lifecycle and authority at [OWNER-RULINGS:151](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151>), while the process consequence explicitly names hold release/reject as a later slice at [OWNER-RULINGS:158](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:158>).
- Failure scenario: A-1b is currently assigned requested/recalled/released/rejected behavior and release/reject UI together. There is no named later release/reject slice, so dispatch material authorizes more behavior than the ruled sequencing permits.
- Minimum correction: retain A-1a’s current no-hold boundary; define W-LOT-A-1b as implementing only request/hold and `requested → recalled`; require its initial schema enum/check to accept `requested`, `recalled`, `released`, and `rejected`; name W-LOT-A-1c, or another exact slice ID, as owner of release/reject transitions, GM authority, mandatory reasons, append-only evidence, routes, and UI.

A-1a itself correctly contains no hold implementation. The blocker is its handoff contract.

### B-B — Prior B5 remains open: deployment gates are not executable fail-closed gates

- Plan:
  - Incomplete variables: [rev 2:1867](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1867>).
  - Unsafe tenant extraction: [rev 2:1888](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1888>).
  - Promised but non-executable comparisons: [rev 2:1916](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1916>), [rev 2:1994](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1994>).
  - Push-4 backfill: [rev 2:1970](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1970>).
  - Census prose without commands: [rev 2:1998](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1998>).
- Source:
  - `tenants:list` prints the domain on the same line as the tenant ID at [TenantList.php:39](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/TenantList.php:39>).
  - `tenants:run` emits its separate tenant marker at [Run.php:36](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:36>).
  - Every migration/backfill push requires a verified backup at [manifest:63](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:63>).
  - Command and census variable requirements are explicit at [manifest:298](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:298>).
- Failure scenario:
  - `[0-9a-fA-F-]{36}` can accept malformed UUID-like text or a UUID-shaped domain fragment.
  - `sort -u` destroys evidence needed to detect duplicate directory entries.
  - `comm`, raw/unique/expected cardinality, and census checks are prose, not commands that terminate promotion.
  - Lot and phantom command invocations are absent despite their different `--tenant` signatures.
  - Push 4 mutates every tenant without its own pre-backfill backup.
  - The ten-row variable table exists, but the migration loses its `prerequisite` annotation; commands omit `tenants:run` placement and markers; censuses omit exact signatures and grep gates. These details were more complete in rev 1 at [rev 1:2751](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2751>), so this is a regression.
- Minimum correction:
  - Parse only anchored `[Tenant] …: <UUID>` records.
  - Preserve raw and normalized manifests separately; validate every UUID; require raw count = unique count = expected count.
  - Supply executable `comm` checks for missing and unexpected IDs and make every failed assertion terminate the phase.
  - Provide exact per-tenant lot-drift and phantom invocations and numeric grep/parsing commands.
  - Add and verify a host backup immediately before Push 4.
  - Restore complete values for all manifest variables, adjusted to use the explicit delta rather than generic seeding.

## MAJOR

### M-A — Task 1 adds authorization middleware outside the contracted API scope

- Plan: [rev 2:261](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:261>) adds route middleware to create/update/write-off paths, and [rev 2:392](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:392>) accepts those middleware arguments.
- Scope/source:
  - The authoring contract limits new enforcement to body-less recall/deactivate, traceability, and reads at [authoring prompt:10](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WLOTA-1-permissions-hold-2026-09-06.md:10>).
  - Spec v4 says to preserve existing create/update/transfer/write-off checks at [spec v4:298](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:298>).
  - Create and update already authorize through [CreateBatchRequest.php:22](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRequest.php:22>) and [UpdateBatchRequest.php:13](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:13>).
- Failure scenario: redundant earlier middleware changes denial ordering or response shape and expands the Task-1 diff without closing an actual permission gap.
- Minimum correction: add middleware only to the uncovered reads, traceability, delete, and recall routes. Preserve existing FormRequest authorization for create, update, transfer, and write-off unchanged.

### M-B — The marked-role schema permits an invalid global system role

- Plan: the column has no index at [rev 2:972](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:972>), and the constraint omits team identity at [rev 2:979](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:979>).
- Source: `roles.tenant_id` is nullable at [create_permission_tables.php:37](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:37>), and the existing uniqueness contract includes that nullable column at [create_permission_tables.php:44](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:44>).
- Failure scenario: a row with `tenant_id=NULL`, `name=general_manager`, `guard_name=sanctum`, and `provisioning_source=w-lot-a-1a` satisfies the proposed constraint. PostgreSQL nullable uniqueness can also admit multiple such global rows. Protection and delta code can then treat a non-tenant role as the seeded tenant authority.
- Minimum correction: require the configured team column to be non-null whenever `provisioning_source` is non-null; reproduce that condition in SQLite triggers; add PostgreSQL/SQLite tests for null-team markers and marker cardinality. If a separate partial unique index is used, scope it by team so shared-database tenancy remains valid.

## MINOR

### N-A — The proposed glossary row names two canonical surfaces

- Plan: [rev 2:169](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:169>).
- Convention: a concept has one primary writer and one operator surface at [convention 11:37](</Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:37>) and [convention 11:40](</Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:40>).
- Failure scenario: “Settings → Users and Settings → Roles surfaces” leaves later work free to treat the protected seeded role as editable through the Roles surface. It also lists the code identifier `general_manager` as a UI synonym.
- Minimum correction: name `LotActionPermissionDelta` as the sole role-definition writer and Settings → Users as the assignment surface. If Settings → Roles remains visible, label it read-only inspection rather than a second canonical editing surface; remove the code identifier from UI synonyms.

## Round-1 closure table

| Prior item | Result | Rev-2 evidence |
|---|---|---|
| B1 constructors | CLOSED | [plan:305](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:305>) |
| B2 inert Push 3 | CLOSED | [plan:222](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:222>) |
| B3 browser timing | CLOSED | [plan:1774](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1774>) |
| B4 SHA equality | CLOSED | [plan:42](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:42>); current seams were rechecked |
| B5 fleet gates | **NOT CLOSED** | Current BLOCKER B-B; [plan:1882](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1882>) |
| M1 trace DTO | CLOSED | [plan:409](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:409>) |
| M2 delta transaction/team | CLOSED | [plan:1100](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1100>) |
| M3 assignment transaction | CLOSED | [plan:1355](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1355>) |
| M4 denied snapshots | CLOSED | [plan:868](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:868>) |
| M5 both create links | CLOSED | [plan:1594](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1594>) |
| M6 Task-6 symbols | CLOSED | [plan:1675](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1675>) |
| M7 CLI contract | CLOSED | [plan:1250](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1250>) |
| M8 reviewers | CLOSED | [plan:915](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:915>) |
| M9 convention 10 | CLOSED | [plan:147](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:147>) |
| M10 typecheck | CLOSED | [plan:1792](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:1792>) |
| N1 operative citations | CLOSED | [plan:193](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:193>); all 75 local citations checked |
| N2 selected B2 | CLOSED | [plan:894](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:894>) |

## Rejected false positives

- No hold, eligibility, POS refusal, transfer refusal, company-recall replacement, history, release/reject permission, or hold UI leaks into A-1a. The negative boundary is explicit at [plan:98](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:98>).
- Dormant `batches.recall.request` catalogue provisioning is a prerequisite, not hold behavior.
- `meta.outcome=already_exists` is convention-09 rerun evidence, not a Q10 state.
- A-1a correctly has no hold-state enum, JSONB DTO, or hold migration. Those are not missing Task-1/2/6 artifacts.
- Every task names exact files, signatures, red-first test symbols/assertions/commands/lanes, reviewers, and rollback. Schema/enums/Artisan CLI are correctly N/A for Tasks 1 and 6.
- Convention 09 includes a real second-company path, selected POS-enabled B2, and meaningful rerun assertions at [plan:880](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-2.md:880>).
- Convention 10 uses the exact repository header and decision vocabulary. Unsupported precise competitor behavior is marked `NV`; the ERPNext source supports batch/warehouse filtering and document-linked user restrictions. [ERPNext Batch](https://docs.frappe.io/erpnext/batch), [ERPNext User Permissions](https://docs.frappe.io/erpnext/user-permissions).
- The source-push inventory is mechanically complete; no runtime file is duplicated across pushes.
- The manifest sentence and exactly ten variable rows are present. The finding concerns incomplete values, not missing rows.
- Planning-SHA drift does not invalidate the citations: the named application seams and package scripts remain operative at current HEAD.

## Preserve in the next revision

- Tasks 1/2/6-only A-1a scope and the strict no-hold/no-eligibility boundary.
- Verbatim RD2, Q4, and Q10 quotations.
- Location-scoped current and historical visibility, `null` versus `[]`, filtered eager loads, and four-decimal response totals.
- Complete trace DTOs, owning-module adapters, response compatibility, and architecture boundary.
- Collision-safe seeded `general_manager`, manager recall removal, custom-grant preservation, transaction/team restoration, assignment race coverage, and rename/delete protection.
- Flag-off legacy seeding, explicit Push-4 delta, rollout-window recensus, and worker → API → scheduler activation.
- Exact Task-6 test titles, split pre/post-activation Playwright lanes, generated-map provenance, double-generation comparison, and web fingerprint.
- Both required reviewers per task and combined review.
- Verbatim manifest checklist.
- The two unrelated untracked files:
  - `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md`
  - `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md`

## Owner decisions required

None.

VERDICT: CHANGES-REQUIRED