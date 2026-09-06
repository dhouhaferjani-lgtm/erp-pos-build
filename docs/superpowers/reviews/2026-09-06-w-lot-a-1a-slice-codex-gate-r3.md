# Codex slice-plan gate r3 — W-LOT-A-1a rev 3 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 3 at 56419d3f5. Verbatim.

---
# Adversarial gate result

Audited read-only at HEAD `44868d82c3d57ffdae61a86d3503b9959b4fce75`. No files were edited, no tests were run, and no git writes occurred. Production code has not changed since the plan’s stated planning SHA; intervening changes are documentation-only.

All 81 unique absolute `path:line` citations in rev 3 resolve and remain in range at HEAD.

## BLOCKER

### B-B1 — The migration success regexes always reject valid output

- Plan: [rev 3:2297](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:2297>) and [rev 3:2300](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:2300>).
- Source: the command emits `Rolling tenant migrations across 1 tenant(s).` at [RollingTenantMigrationCommand.php:73](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:73>) and `Done. 1 tenant(s) migrated, 0 failed.` at [RollingTenantMigrationCommand.php:163](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:163>).
- Failure scenario: the single-quoted ERE contains doubled backslashes. It expects literal backslashes rather than matching the parentheses and periods in the actual output. Both migration passes therefore fail even after a successful migration, making mandatory Push 2 impossible to complete.
- Minimum correction: use exact fixed-string matching, for example:
  - `grep -Fxc 'Rolling tenant migrations across 1 tenant(s).'`
  - `grep -Fxc 'Done. 1 tenant(s) migrated, 0 failed.'`
  Add a literal-output shell proof or equivalent gate test.

### B-B2 — Deployment does not resolve the shared manifest’s live topology branches

- Plan: [rev 3:1995](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:1995>) mandates the Dokploy Environment tab and forbids editing `docker-compose.staging.yml`, while merely postponing U-1 until before Push 3.
- Source: both topology paths must remain live until U-1 is closed at [manifest:14](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:14>). If compose is the actual path, the flag must be added to `x-api-env` in a sixth source push at [manifest:190](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:190>).
- Failure scenario: U-1 resolves to compose deployment. The forbidden compose change means worker/API/scheduler never receive `LOT_ACTION_PERMISSIONS_ENFORCE`; activation evidence cannot become valid without revising the dispatched plan.
- Minimum correction: add an executable U-1 preflight before source-push dispatch. Branch explicitly:
  - Separate applications: update each applicable Dokploy environment.
  - Compose: enumerate `docker-compose.staging.yml` in a sixth source push, add the flag to `x-api-env`, and verify all required service readbacks.
  Update the push inventory, SHA gates, rollback, and per-slice variable row for both outcomes.

### B-B3 — Several purported fail-closed checks are prose or cross-shell references

- Plan:
  - The backup runs on the host at [rev 3:2146](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:2146>) but invokes an API-shell helper only by prose at [rev 3:2190](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:2190>).
  - Flag-off evidence is an unexecuted “require exactly” statement at [rev 3:2355](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:2355>).
  - Push-4 day-one execution is described as renaming an earlier loop at [rev 3:2580](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:2580>).
  - Activation evidence similarly lacks its exact comparison command at [rev 3:2850](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:2850>).
- Failure scenario: shell functions do not cross from the API container to a separate host shell. The backup marker validation therefore has no callable implementation. The OFF/ON evidence requirements do not execute cardinality, order, or unexpected-line checks, despite [rev 3:1998](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:1998>) claiming every gate is executable.
- Minimum correction:
  - Define or source `assert_exact_marker_set` in the host shell and show its exact invocation.
  - Capture OFF and ON records into aggregate files and compare them byte-for-byte with committed heredoc expectations using `cmp`.
  - Supply the complete Push-4 command or invoke a named, versioned helper available in the same shell.
  - Do the same for rollout-window recensus rather than directing the operator to substitute variables manually.

B-B is therefore not closed and still blocks dispatch.

## MAJOR

### M-C — N-A’s “sole writer/read-only Roles page” claim is not implemented by the task contract

- Plan: [rev 3:244](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:244>) declares `LotActionPermissionDelta` the sole role-definition writer and Settings → Roles read-only. Task 2 only promises rename/delete protection at [rev 3:1485](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:1485>), and neither Task 2’s files at [rev 3:1020](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:1020>) nor Task 6’s files at [rev 3:1650](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:1650>) include `RolesPage.tsx`.
- Source:
  - `RoleController::update()` currently accepts and synchronizes arbitrary permissions at [RoleController.php:223](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223>) and [RoleController.php:245](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:245>).
  - The web protects only three legacy names at [RolesPage.tsx:35](</Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/settings/RolesPage.tsx:35>), sends permission edits at [RolesPage.tsx:178](</Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/settings/RolesPage.tsx:178>), and renders edit/delete controls for `general_manager` at [RolesPage.tsx:253](</Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/settings/RolesPage.tsx:253>).
  - This contradicts convention 11’s one-primary-writer rule at [convention 11:37](</Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:37>).
- Failure scenario: after the delta provisions the marked role, an operator can use Settings → Roles—or call its API—to add or remove permissions. The canonical delta is no longer the sole role-definition writer, and the seeded authority can drift immediately after verification.
- Minimum correction:
  - Backend: reject name, permission, and delete mutations for the marker-derived protected role; return a stable error and prove grants remain unchanged.
  - Web: include `RolesPage.tsx` and a colocated test; consume a marker-derived protected/read-only field from the API, suppress edit/delete affordances, and prevent modal submission.
  - Add exact red-first entries, including `GeneralManagerRoleProtectionTest::test_marked_general_manager_permissions_cannot_be_synchronized_through_role_update` and a Roles-page read-only test, with assertions, commands, and lanes.
  - Update file inventories, source-push ledger, reviewer checklist, and rollback.

### M-D — The delta command contract omits `tenants:run` VALUE_NONE normalization

- Plan: direct-mode behavior is specified at [rev 3:1366](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:1366>), but deployment invokes `--option='apply=1'` at [rev 3:2418](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:2418>).
- Source: `tenants:run` parses option values as strings at [Run.php:40](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:40>) and forwards them at [Run.php:50](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:50>). The existing day-one command explicitly casts truthily because strict `=== true` silently ignores staging input at [DayOneCensusCommand.php:83](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/DayOneCensusCommand.php:83>).
- Failure scenario: an implementation following only the direct command tests compares `$this->option('apply') === true`. Direct tests pass, but the staging invocation supplies string `'1'`, selects neither mode, and emits `invalid_mode`.
- Minimum correction: require boolean/truthy normalization for both options and add a PostgreSQL red-first integration test through `tenants:run`, for example `ApplyLotActionPermissionDeltaCommandTest::test_tenants_run_apply_string_one_selects_apply_mode`, asserting the exact `mode=APPLY` marker. Add its exact PHPUnit command and corresponding verify-mode coverage.

## MINOR

### N-B — The two A-1b ownership lists disagree

- Plan: [rev 3:113](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:113>) assigns sale, transfer, delivery, write-off, stock-count, and return eligibility to A-1b. The final “owns only” list at [rev 3:3036](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:3036>) names only POS and transfer eligibility.
- Source contract: the authoring prompt specifically names POS sale, direct batch transfer, and `StockTransferService` allocation at [authoring prompt:13](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WLOTA-1-permissions-hold-2026-09-06.md:13>).
- Failure scenario: A-1b can either omit required StockTransfer allocation or expand into four uncontracted eligibility workflows.
- Minimum correction: use one identical list in both locations: POS sale, direct batch transfer, and StockTransfer allocation. Defer any delivery/write-off/count/return changes to named later lanes unless separately sourced.

## Rev-2 closure table

| Rev-2 item | Rev-3 result | Evidence |
|---|---|---|
| B-A — Q10 boundary | **CLOSED** | A-1b implements only `requested → recalled` while its schema accepts all four states at [rev 3:106](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:106>); A-1c exclusively owns release/reject at [rev 3:120](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:120>). This matches [Q10:151](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151>). |
| B-B — fail-closed deployment | **NOT CLOSED** | B-B1 through B-B3 above. |
| M-A — middleware scope | **CLOSED** | The exact route matrix preserves create/update/transfer/write-off authorization at [rev 3:327](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:327>). |
| M-B — global marked role | **CLOSED** | PostgreSQL CHECK, SQLite guards, and partial team index require a non-null tenant at [rev 3:1058](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md:1058>). |
| N-A — one surface/writer | **NOT CLOSED; escalated to MAJOR** | The prose was corrected, but the API/UI task contract still leaves the protected role editable. |
| Round-1 B1–B4, M1–M6, M8–M10, N1–N2 | **CLOSED; no regression** | Constructors, inert Push 3, browser timing, SHA gates, DTOs, team transactions, denied snapshots, web symbols, reviewers, convention-10 format, typecheck, citations, and selected-B2 coverage remain present. |
| Round-1 B5 | **NOT CLOSED** | Same deployment family as B-B. |
| Round-1 M7 direct CLI signature | **CLOSED, but wrapper integration incomplete** | Full signature and direct mode matrix exist; M-D covers the newly exposed `tenants:run` gap. |

There is no regression in the prior accepted application/schema closures. Rev 3 genuinely closes B-A, M-A, and M-B. B-B remains open, and N-A’s textual change is not carried through to dispatchable files and tests.

## Rejected false positives

- A-1a correctly implements no hold schema, request route, hold behavior, eligibility change, release/reject transition, or hold UI.
- The future A-1b enum/CHECK accepting `requested`, `recalled`, `released`, and `rejected` is required forward compatibility, not premature release/reject behavior.
- A-1b exposes only `requested → recalled`; A-1c is the named sole owner of release/reject authority, reasons, evidence, routes, UI, and the requesting-branch prohibition.
- Dormant provisioning of `batches.recall.request` in A-1a is a prerequisite, not a state-machine leak.
- The marked-role schema contract is complete for PostgreSQL and SQLite and includes PHP enums, constraints, triggers, index cardinality, guarded rollback, and both database lanes.
- Task 1 otherwise has exact files/signatures, DTO response contracts, red-first tests, convention-09 evidence, two-reviewer gate, and rollback.
- Task 2 otherwise has exact schema/enums/service/CLI contracts, red-first tests, convention-09 evidence, reviewers, and forward-only rollback.
- Task 6 otherwise has exact files/symbols, Vitest and Playwright lanes, pre/post-activation split, permission-map regeneration, reviewer gate, and rollback. Schema/enums/Artisan CLI are correctly N/A for Tasks 1 and 6.
- Convention 10 uses the required eight-column header, decision vocabulary, and all nine required rows.
- The glossary row now names Settings → Users as the sole assignment surface and removes `general_manager` as a UI synonym; the finding is implementation incompleteness, not glossary formatting.
- The deployment section contains the required manifest reference, verbatim checklist, and ten-row variable table. The blocker concerns invalid/incomplete executable values and gates, not missing headings.

## Preserve

- Tasks 1/2/6-only A-1a scope and explicit no-hold boundary.
- The corrected Q10 split: A-1b request/hold plus `requested → recalled`; A-1c release/reject.
- Four-state forward-compatible A-1b schema.
- Location-scoped current/historical visibility, `null` versus `[]`, filtered eager loads, and four-decimal totals.
- Complete trace DTOs, owning-module adapters, payload compatibility, and architecture boundary.
- Correct middleware route matrix preserving existing FormRequest checks.
- Non-null team marker constraint, partial unique index, SQLite triggers, guarded down migration, and role-ID preservation.
- Collision-safe delta, custom-grant preservation, transaction/team restoration, assignment locking, and manager recall removal.
- Flag-off legacy seeding, explicit Push-4 delta, permission-map provenance, web fingerprint, and worker → API → scheduler order.
- Convention-09 second-company, second-location, and rerun evidence.
- Both required reviewers per task and combined review.
- Verbatim shared-manifest checklist.
- Preserve the unrelated untracked files:
  - `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md`
  - `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md`

## Owner decisions required

None. All corrections follow existing owner rulings, repository conventions, current code contracts, and the shared deployment manifest.

VERDICT: CHANGES-REQUIRED