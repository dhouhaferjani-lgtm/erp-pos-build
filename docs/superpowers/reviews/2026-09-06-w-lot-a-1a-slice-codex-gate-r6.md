# Codex slice-plan gate r6 — W-LOT-A-1a rev 6 (gpt-5.6-sol, high, read-only, 2026-09-09)

Input: rev 6 at 350da33a1. Verbatim.

> Orchestrator note on M-E: the gate cites Dokploy's canary source for a `Hash: <sha>` description. The live staging records read on 2026-09-09 via `application.one` show `description = "Commit: <40-hex sha>"` and `title = <commit message>`; the fact sheet is amended with the observed shape and fix round 6 correlates on it.

---
# Adversarial gate result

Audited read-only at HEAD `350da33a16a0d25b797a6271eec2847c138c5fc4`. No edits, tests, or git writes were performed.

Resolved all 168 absolute `path:line` citation occurrences in rev 6, representing 123 unique targets. Zero targets were missing or out of range, and zero cited lines failed to support their stated claim.

The specified round-5 scratchpad prompt is absent at HEAD; the supplied minimum-corrections text was sufficient to complete the audit.

## BLOCKER

### B-B2 — Flag-off boot seeding undoes Push 4 and makes Push 5 and rollback fail

**Plan:** Rev 6 requires flag-off seeding to execute the legacy synchronization path at [rev-6.md:1449](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:1449), acknowledges that each API replacement executes the seeder at [rev-6.md:2273](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:2273), changes canonical role permissions during Push 4 at [rev-6.md:2938](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:2938), and then requires byte-identical snapshots after Push 5 and rollback at [rev-6.md:3044](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3044) and [rev-6.md:3445](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3445).

**Source:** U-1 establishes that `SYNC_PERMISSIONS_ON_BOOT=true` and every API replacement executes the seeder at [U1-resolution.md:28](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:28) and [U1-resolution.md:32](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:32). The entrypoint invokes `tenants:seed RolesAndPermissionsSeeder` at [entrypoint.sh:157](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:157), while the current seeder destructively calls `syncPermissions()` for canonical roles at [RolesAndPermissionsSeeder.php:564](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:564).

**Failure scenario:** Push 4 removes `batches.recall` from manager and applies the new canonical grants. Push 5 then rebuilds the API while enforcement remains false. Boot seeding enters the required legacy branch and restores the pre-delta matrices, so the Push-5 inertness comparison fails. The same process occurs during rollback to `false`, contradicting the required preservation of the post-activation permission state.

**Minimum correction:** Specify a non-destructive flag-off branch for tenants already carrying the rollout marker: fresh/unmarked tenants may receive the legacy matrix, while marked tenants must preserve the applied canonical delta and custom grants. Add named red-first PostgreSQL tests covering flag-off reseeding after Push 4 and after rollback, and make both deployment gates compare their resulting snapshots to committed expectations.

### B-B3a — The fleet proof remains incomplete and does not satisfy the executable-gate contract

**Plan:** The disposable-tenant transition remains prose—“write its one UUID”—at [rev-6.md:2913](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:2913). Registration pause/resume is delegated to unspecified runbook commands at [rev-6.md:3127](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3127) and [rev-6.md:3374](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3374). The activated registration step says to perform a real registration and write the returned UUID, but supplies no request, capture, parser, or host-transfer command at [rev-6.md:3260](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3260).

Several gates also use bare `cmp -s` under `set -e`, rather than the promised explicit failure branch, including [rev-6.md:2910](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:2910), [rev-6.md:3244](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3244), and [rev-6.md:3308](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3308). The Push-5 deployment-record gate has no committed heredoc/template comparison at all.

**Source:** The rev-5 correction explicitly required executable producer and transfer commands, unfiltered marker aggregation, committed templates, and explicit failure paths at [gate-r5.md:24](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r5.md:24). U-1 confirms that host-persisted artifacts must be explicitly returned to every fresh shell/container because only `/root` survives replacement at [U1-resolution.md:28](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:28).

**Failure scenario:** An operator reaches the registration gate without a defined registration request or UUID-producing command. The next block assumes the manifest already exists, so the marker aggregation and comparison cannot be executed end to end. The disposable-tenant transition has the same missing producer. Separately, several §11 gates do not meet rev 6’s own “explicit non-zero exit plus committed heredoc `cmp`” invariant.

**Minimum correction:** Provide complete copy-pasteable commands to pause registration, perform the exact registration request, capture and validate its response, extract exactly one UUID, persist and transfer the manifest, and resume registration. Do the same for the disposable tenant. Give every §11 validation—including the auto-deployment record—an explicit committed heredoc expectation, `cmp`, and `|| fail_gate` or equivalent explicit non-zero branch.

## MAJOR

### M-E — Push 5 polls an impossible auto-deployment description

**Plan:** Push 5 selects `.deployments[0].description` and requires it to equal the raw commit SHA at [rev-6.md:3026](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3026). The emergency fallback is also conditioned on that equality at [rev-6.md:3073](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3073).

**Source:** U-1 confirms that the web application uses automatic deployment at [U1-resolution.md:17](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:17). Current Dokploy auto-deployment code records the description as `Hash: ${deploymentHash}`, not the bare hash. [Dokploy GitHub auto-deploy source](https://github.com/Dokploy/dokploy/blob/canary/apps/dokploy/pages/api/deploy/github.ts#L203-L230)

**Failure scenario:** A valid automatic web deployment completes, but the poll never accepts `Hash: <PUSH5_SHA>` as equal to `<PUSH5_SHA>`. It times out after ten minutes, and the fallback refuses to run for the same reason. Selecting index zero is additionally unsafe if a newer unrelated deployment appears.

**Minimum correction:** Select the deployment created after the push, correlate it using Dokploy’s committed `Hash: ${PUSH5_SHA}` representation or a verified hash field, poll that specific deployment to completion, and compare the selected record against a committed expectation. Retain the prohibition on a colliding normal-path manual redeploy.

### M-F — Task 2’s exact file inventory omits a required named test

**Plan:** The Task-2 “Add tests” inventory at [rev-6.md:1112](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:1112) omits `apps/api/tests/Feature/Identity/RoleIndexResponseContractTest.php`. Later sections nevertheless require that class at [rev-6.md:1781](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:1781), classify it as red-first at [rev-6.md:1874](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:1874), and invoke it during verification at [rev-6.md:3582](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3582).

**Source:** The earlier inventory finding required every named test file to appear in the exact Task-2 inventory at [gate-r3.md:52](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r3.md:52).

**Failure scenario:** An implementer following the authoritative exact-file and Push-3 inventories can omit the file, while later red-first and verification commands assume that it exists.

**Minimum correction:** Add the exact test path to Task 2’s “Add tests” list and explicitly include it in the Push-3 test-file inventory.

## MINOR

None.

## Rev-5 closure table

| Rev-5 finding | Status | Evidence |
|---|---|---|
| B-B2 | NOT CLOSED | All three applications are invoked with `false`, but mandatory flag-off boot seeding semantically reverts the Push-4 delta and breaks Push-5/rollback comparisons. |
| B-B3a | NOT CLOSED | Raw marker aggregation is fixed, but disposable/registration producers remain prose and several §11 gates lack explicit failure branches or committed expectation comparisons. |
| B-B3b | CLOSED | A named Task-2 production symbol writes through `Log::channel('stderr')` at [rev-6.md:1432](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:1432), has a named red-first test at [rev-6.md:1801](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:1801), and aggregates unfiltered marker tokens before validation at [rev-6.md:3277](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3277). |
| M-E | NOT CLOSED | The colliding manual redeploy was removed, but the automatic-deployment completion poll requires the wrong description format. |
| N-B | CLOSED | The A-1b ownership lists at [rev-6.md:168](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:168) and [rev-6.md:3688](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md:3688) are byte-identical. |
| N-C | CLOSED | The census SHA is consistently pinned to `b055…` in both identity and census sections. |

## Rejected false positives

- U-1 is correctly modeled as seven separate Dokploy applications. There is no operative compose branch; remaining `compose` occurrences are explicit negative assertions.
- The initial-OFF and rollback blocks address all three API applications with `false`.
- The protected-role backend rejection and Roles-page contracts have named red-first tests.
- `tenants:run` truthy normalization has named PostgreSQL integration coverage.
- The registration marker’s stderr channel survives `Artisan::call()` buffering.
- Push 5 correctly avoids a normal-path manual web redeploy.
- The shared-manifest checklist is byte-identical to the shared staging manifest.
- A-1b’s four-state shape remains forward-compatible without taking ownership of release/rejection behavior.

## Preserve

Previously accepted scope boundaries, owner-ruling defaults, Q10 four-state schema, location/null/empty-list response behavior, trace DTO and adapter boundaries, middleware matrix, marker schema and constraints, transactional delta contract, team scoping, custom-grant preservation contract, worker/API/scheduler ordering, reviewer assignments, convention-09 boundaries, and the verbatim shared-manifest checklist remain intact.

The production-code baseline has not drifted; current-HEAD movement from the plan’s census commit is documentation-only.

## Owner decisions required

None. The required corrections follow the accepted owner rulings, U-1 fact sheet, current source behavior, and Dokploy’s automatic-deployment contract.

VERDICT: CHANGES-REQUIRED