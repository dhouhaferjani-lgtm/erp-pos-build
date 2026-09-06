# Codex slice-plan gate r1 — W-LOT-A-1a rev 1 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 1 at 0bc84c48f. Verbatim.

---
Changes are required. Review performed read-only at observed HEAD `a1c7e271e36f222ec5b8b5e864263860389cf192`; no edits, tests, or git writes were made. All 70 absolute plan citations resolve to existing files and in-range lines, but several claims are semantically wrong or incomplete.

## BLOCKER — blocks dispatch

### B1. The “full” constructor signatures are not implementable

- Plan: [plan:953](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:953), [plan:1629](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1629), [plan:1784](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1784), [plan:1855](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1855).
- Source:
  - Batch write-off services are `Domain\Services`, not `Application\Services`: [BatchController.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:12), [ReverseWriteOffService.php:5](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/ReverseWriteOffService.php:5).
  - Identity services are under `Modules\Tenant`: [UserController.php:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:23).
  - `CompanyConfigService` is `App\Services`: [RoleController.php:16](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:16).
  - Existing constructors use promoted `private readonly` properties: [BatchController.php:33](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:33), [RolesAndPermissionsSeeder.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:14).
- Failure scenario: literal implementation produces nonexistent class references and removes promoted properties without replacement assignments, causing container resolution or property-access failures.
- Minimum correction: reproduce every constructor with correct FQCNs and explicit `private readonly` promotion or complete assignments. Re-run the named-symbol census afterward.

### B2. Push 3 is not inert because the revised seeder is live during registration

- Plan: the seeder changes in [plan:1629](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1629) ship in Push 3 at [plan:2672](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2672), while the plan promises no role changes before Push 4 at [plan:2874](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2874).
- Source: new registration calls tenant initialization at [TenantProvisioningService.php:222](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:222), which invokes `RolesAndPermissionsSeeder` when roles are missing at [TenantInitializationService.php:199](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:199) and [TenantInitializationService.php:213](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:213).
- Failure scenario: a tenant registered between Push 3 and Push 4 immediately gets `general_manager`, viewer/operator grants, and manager recall removal despite `LOT_ACTION_PERMISSIONS_ENFORCE=false`.
- Minimum correction: make the generic seeder retain its legacy role behavior while enforcement is off; apply existing-tenant changes only through the explicit Push-4 delta. Once activation is on, let fresh provisioning use the new matrix and rerun/census tenants created during the rollout window.

### B3. The Push-5 Playwright gate requires behavior explicitly disabled at that point

- Plan: flag-off preserves legacy duplicate and read behavior at [plan:1031](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1031). The browser journey requires `meta.outcome=already_exists` and location filtering at [plan:2504](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2504), but it runs while the flag remains false at [plan:3014](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:3014) and before the flip at [plan:3077](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:3077).
- Source: the current duplicate response has no `meta.outcome`: [BatchController.php:131](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:131). Stock reads are currently unscoped at [BatchController.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:264).
- Failure scenario: Playwright fails before the deployment can reach the flag flip.
- Minimum correction: split browser coverage into pre-activation web-only gating/fingerprint checks and post-activation duplicate/location checks. Run the latter only after API activation.

### B4. The dispatch SHA gate already fails at HEAD

- Plan: baseline and mandatory equality check are [plan:10](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:10), [plan:24](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:24), and [plan:3362](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:3362).
- Source state: required SHA is `0c7bd49f…`; observed HEAD is `a1c7e271…`. The diff since the baseline is documentation-only, but the plan says any failed comparison invalidates its citations.
- Failure scenario: dispatch stops at step 1.
- Minimum correction: repin to the actual dispatch HEAD and restate that production seams were reverified, or use an explicitly approved production-tree invariant instead of an impossible pre-plan commit equality.

### B5. Fleet and census gates fail open and diverge from the shared manifest

- Plan: “one marker per tenant” is implemented as a bare `grep` at [plan:2895](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2895); rerun requirements at [plan:2937](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2937) contain no executable cardinality check. Census commands are piped through `tee` without a subsequent result gate at [plan:2951](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2951).
- Source:
  - The manifest warns that `tenants:run` discards child exit status: [manifest:36](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:36).
  - Its required captured-ID pattern is [manifest:138](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:138).
  - Phantom dry-run exits success even when findings are reported: [RepairPhantomDefaultBatchesCommand.php:285](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:285), [RepairPhantomDefaultBatchesCommand.php:298](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:298).
  - The manifest requires its checklist verbatim at [manifest:239](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:239); the plan references but does not copy it.
  - The plan activates worker → scheduler → API at [plan:3077](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:3077), contrary to worker → API → scheduler at [manifest:174](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:174).
- Failure scenario: one successful tenant hides missing/failed tenants, or nonzero lot/phantom findings pass through `tee`; activation then proceeds on a partially migrated fleet.
- Minimum correction: capture and assert a nonempty tenant-ID set; require every expected UUID exactly once; reject `FAILED`, `SKIPPED`, missing, duplicate, or unexpected markers; gate every census summary explicitly; copy the manifest checklist verbatim; follow worker → API → scheduler.

## MAJOR — fix before the affected task

### M1. The trace DTO cannot preserve the current response contract

- Plan DTO: [plan:674](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:674); controller is told to compose DTOs only at [plan:1089](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1089).
- Source: current forward responses expose `document_type` and `customer_identifier` at [BatchTraceabilityController.php:60](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:60) and [BatchTraceabilityController.php:76](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:76); backward responses expose expiry and recall/expiry flags at [BatchTraceabilityController.php:141](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:141). Those fields do not exist in the proposed DTO.
- Failure scenario: implementation either silently breaks response shape or reimports module models, defeating the new boundary.
- Minimum correction: define exact forward-document, forward-POS, and backward DTO contracts—or a complete discriminated DTO—and specify the compatibility mapping and assertions field by field.

### M2. The role delta omits its transaction and permission-team boundary

- Plan: [plan:1535](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1535) merely states that a transaction advisory lock is used at [plan:1585](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1585).
- Source: permission teams are enabled and tenant keyed at [permission.php:127](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/permission.php:127), while HTTP team setup is performed by middleware at [SetPermissionsTeam.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22); the console service has no equivalent contract.
- Failure scenario: `pg_advisory_xact_lock` releases after its statement if no encompassing transaction exists, and a fleet command can query/create roles under a null or stale Spatie team.
- Minimum correction: require one `DB::transaction` around lock/read/write/verification; capture, set, and restore `PermissionRegistrar` team ID in `finally`; add concurrent and cross-tenant discrimination tests.

### M3. Dedicated general-manager assignment has no transaction boundary

- Plan: locking is required at [plan:1902](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1902), but no transaction is specified.
- Source: the current writer assigns directly at [RoleController.php:350](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:350).
- Failure scenario: locks are released before role assignment; a concurrent membership narrowing can leave a `general_manager` with restricted membership.
- Minimum correction: define a transaction covering target and ordered membership locks, merged-state validation, assignment, and event emission; add a barrier-controlled PostgreSQL race test.

### M4. Required denied-mutation no-side-effect evidence is absent

- Plan: Task 1 supplies only a 403 as the first action assertion at [plan:1205](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1205).
- Scope contract: unchanged stock/history snapshots are mandatory at [authoring prompt:14](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WLOTA-1-permissions-hold-2026-09-06.md:14).
- Source mutations occur at [BatchController.php:176](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:176) and [BatchController.php:193](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193).
- Failure scenario: middleware returns 403 but an ordering/regression still mutates batch state or evidence before denial without detection.
- Minimum correction: name exact cashier/viewer and manager-denied methods for recall and delete, snapshot batch/stock/reservation/history rows, and assert no changes after denial.

### M5. One of two existing create affordances can remain exposed

- Plan: [plan:2275](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2275) refers to “the create link” singular.
- Source: the header link is at [BatchListPage.tsx:86](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchListPage.tsx:86), with a second empty-state link at [BatchListPage.tsx:132](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchListPage.tsx:132).
- Failure scenario: a no-create user opens an empty list and still sees the Add Batch link.
- Minimum correction: gate both links through one `canCreate` value and test populated and empty-state rendering with zero matching create links.

### M6. Task 6 does not provide complete exact test symbols

- Plan: files and prose cases are listed at [plan:2416](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2416), while the red table uses descriptions instead of exact `describe`/`it` symbols at [plan:2466](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2466).
- Failure scenario: implementation can omit cases in the modified Sidebar/hook files or the browser journey while still claiming the listed three reds.
- Minimum correction: enumerate every exact Vitest and Playwright `describe`/`it` title, including modified test files, with assertion, command, and lane. This is required to close gate-r5 B2.

### M7. CLI behavior is incomplete and has no command-level test

- Plan: signature is at [plan:1671](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1671); only the both-options case is defined at [plan:1711](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1711). The Task-2 test inventory at [plan:1916](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1916) lacks an `ApplyLotActionPermissionDeltaCommandTest`.
- Failure scenario: invoking with neither option can apply, skip, or verify depending on implementer interpretation; marker and exit-code behavior remain unverified.
- Minimum correction: define exactly-one-of `--apply|--verify`, preferably exit 2 for neither/both, define all marker tokens and failure output, and add exact command tests for apply, verify, neither, both, collision, and schema failure.

### M8. Mandatory inventory-costing reviewer gates are missing

- Scope contract: both reviewers are required per task at [authoring prompt:20](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WLOTA-1-permissions-hold-2026-09-06.md:20).
- Plan: Task 1 only names tenancy authorization at [plan:1297](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:1297); Task 2 at [plan:2122](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2122); Task 6 uses frontend plus tenancy at [plan:2560](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2560).
- Failure scenario: scoped totals, reservations, and “no side effects on denial” receive no required inventory-costing review.
- Minimum correction: add `inventory-costing-reviewer` to each task and combined gate, with explicit scoped-decimal totals and unchanged quantity/reservation/history checks.

### M9. Convention-10 matrix is not exact and contains unsupported decisions

- Plan: [plan:362](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:362).
- Convention: exact skeleton and decision semantics are [convention 10:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37) and [convention 10:51](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:51).
- Failures:
  - Header is not the required exact format.
  - `rerun` is marked `DIVERGE` even though the plan intends to match deterministic provisioning.
  - The duplicate row’s own Odoo/ERPNext cells do not establish a meaningful duplicate-retry outcome. The cited [Odoo lot documentation](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/lots.html), [OCA stock lock module](https://github.com/OCA/stock-logistics-workflow/tree/18.0/stock_lock_lot), and [ERPNext Batch documentation](https://docs.frappe.io/erpnext/batch) cover lot identity/locking, not that outcome.
- Minimum correction: copy the exact header, make rerun `MATCH — Task 2`, and mark unsupported competitor cells `NV` with reasons or supply direct evidence.

### M10. The web typecheck command does not exist

- Plan: [plan:3177](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:3177) runs `pnpm type-check`.
- Source: the actual script is `typecheck` at [apps/web/package.json:21](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/package.json:21).
- Failure scenario: the required Task-6 verification stops before lint/browser evidence.
- Minimum correction: use `pnpm typecheck`; add `pnpm typecheck:e2e` if the new Playwright spec must be typechecked.

## MINOR

### N1. Gate-r5 N2 is not actually closed

- Plan: closure claim at [plan:250](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:250).
- Counterexamples:
  - Manager recall is cited at plan line 318 to seeder line 631, but recall is at [RolesAndPermissionsSeeder.php:632](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:632).
  - `RequirePermission` cites the comment at line 55; the check is [RequirePermission.tsx:57](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/auth/components/RequirePermission.tsx:57).
  - The company payload starts at [CreateCompanyTest.php:76](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Company/CreateCompanyTest.php:76), not line 75.
  - Viewer omission needs the complete grant range [RolesAndPermissionsSeeder.php:705](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:705)–742, not only its opening line.
- Failure scenario: reviewers follow evidence that does not perform or prove the claimed behavior.
- Minimum correction: repoint to operative statements/ranges.

### N2. Task-2 second-location evidence does not name the selected location

- Plan: [plan:2034](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:2034).
- Convention: the test must prove behavior on the selected second location at [convention 09:43](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:43).
- Failure scenario: the test creates B2 but restricts the membership to another/arbitrary location, so B2 is decorative.
- Minimum correction: explicitly persist `allowed_location_ids=[B2]` and assert the rejection is based on that exact scope.

## Gate-r5 closure audit

| Item | Result |
|---|---|
| B2 exact files/symbols | FAIL — invalid constructors and incomplete Task-6 symbols |
| B3 mechanical push inventory | PASS for file-set derivation; runtime inertness still fails |
| M3 protected general-manager rename/delete | PASS |
| M4 second-company/location/rerun evidence | PARTIAL — present, but browser timing and denied snapshots fail |
| M5 literal vocabulary line | PASS |
| M6 one reseed marker producer | PARTIAL — producer specified; fleet cardinality gate fails open |
| N1 SHA pin | FAIL |
| N2 operative citations | FAIL |

## Rejected false positives

- No hold, eligibility, POS refusal, transfer refusal, company-recall service, or recall-history UI leaked into A-1a.
- Adding the dormant `batches.recall.request` permission is a prerequisite, not hold behavior.
- The duplicate `meta.outcome` is convention-09 retry evidence, not Q10 behavior.
- RD2, Q4, and final ruled Q10 are quoted verbatim at [plan:211](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:211), [plan:221](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:221), and [plan:229](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:229).
- A-1a correctly contains no Q10 state schema. Its forward-compatible requested/recalled/released/rejected schema must be reviewed in the named W-LOT-A-1b plan; A-1b is explicitly assigned that work at [plan:3330](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-1.md:3330).
- The additive role-marker migration contains type, nullability, default, constraint, SQLite triggers, exact down behavior, and corresponding PHP enums. No JSONB or hold status/type column belongs in A-1a.
- Source-push lists are mechanically complete: migration/schema test in Push 2, all Task-1/2 nonmigration files in Push 3, and every Task-6 file in Push 5. No runtime file is duplicated.
- The manifest reference sentence and all ten per-slice variable rows are present.
- `[]` fail-closed versus `null` unrestricted location semantics match [LocationContext.php:194](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationContext.php:194).

## Preserve in the revision

- Tasks 1/2/6-only scope and the explicit W-LOT-A-1b negative boundary.
- Exact RD2/Q4/Q10 quotations and the named follow-up.
- Permission-route matrix and flag-off compatibility intent.
- Restricted historical visibility, nullable-location rules, and filtered eager-load totals.
- Collision-safe marked `general_manager`, explicit manager recall revocation, and preservation of custom grants.
- Complete additive migration/enum contract and forward-only rollback.
- Existing file-set push ledger, web fingerprint, map regeneration-twice check, and ten manifest variables.
- The two unrelated untracked files listed at plan lines 18–22.

## Owner decisions required

None. Every correction above follows existing rulings, conventions, source behavior, or the shared deployment manifest.

VERDICT: CHANGES-REQUIRED