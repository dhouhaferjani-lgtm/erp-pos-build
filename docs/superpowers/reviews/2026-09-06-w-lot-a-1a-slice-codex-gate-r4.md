# Codex slice-plan gate r4 — W-LOT-A-1a rev 4 (gpt-5.6-sol, high, read-only, 2026-09-09)

Input: rev 4 at d90919398. Verbatim.

---
# Adversarial gate result

Audited read-only at HEAD `d9091939892053893c867cc0f2eee5a538e59c39`. No files were edited, no tests were run, and no git writes occurred. Drift from the plan’s planning SHA `ad1d6ceb1df9428003f7e78ef0a53d6f38fb64db` is documentation-only.

Checked all 129 absolute `path:line` citation occurrences, representing 110 unique targets. All targets exist and are in range. Two citations do not support their claims; both are reported under MINOR.

## BLOCKER

### B-B2 — Neither topology branch is executable end to end

**Plan:** [rev 4:2243](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:2243>), [rev 4:2235](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:2235>), [rev 4:3140](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3140>), [rev 4:3477](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3477>), and [rev 4:3583](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3583>).

**Source:** Both paths must remain live until U-1 is resolved at [manifest:14](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:14>). Separate applications receive variables through their Environment tabs, while compose requires enumerated `x-api-env` inheritance at [manifest:33](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:33>). The compose edit must be a sixth push at [manifest:190](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:190>).

**Failure scenario:**

- U-1 records Docker Compose project labels, not Dokploy application IDs or environment handles. The `separate_applications` branch then provides only prose instructions to update and redeploy worker, API, and scheduler. There is no executable environment mutation or deploy invocation, and the captured project labels cannot be passed as application IDs.
- The preflight assumes exactly one or three Compose project labels. It does not inspect Dokploy application type/configuration as the manifest’s U-1 verifier requires, so it can misclassify or reject a legitimate application-shaped deployment.
- The compose activation block neither changes into the captured workdir nor exports the interpolation value into the `docker compose` shell before recreation.
- `$PUSH5_SHA` and `$PUSH6_SHA` are never assigned. The SHA-selection block also reads a staging-host `/root` artifact from the otherwise laptop-local web workflow.
- Separate-application rollback remains prose and has no exact mutation, deploy, or aggregate-OFF command.

**Minimum correction:** Make U-1 obtain and persist the exact Dokploy application IDs and application types using executable inspection commands. Supply exact environment-update, redeploy, and readback commands for worker, API, and scheduler. For compose, `cd` to the captured workdir, explicitly provide the interpolation value, and run the exact sequential recreation/readback commands. Assign and persist `PUSH5_SHA`/`PUSH6_SHA` when each push is promoted, transfer topology/SHA artifacts to the shell consuming them, and provide executable rollback for both branches.

### B-B3 — §11 still crosses destroyed shell/container state and filters unexpected markers

**Plan:** [rev 4:2678](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:2678>), [rev 4:2884](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:2884>), [rev 4:3155](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3155>), [rev 4:3278](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3278>), [rev 4:3416](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3416>), and [rev 4:3441](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3441>).

**Source:** Every source push rebuilds and restarts the staging API at [manifest:29](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:29>), and the API has no persistence volume for container-local evidence at [manifest:37](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:37>). `tenants:run` discards child exit codes at [Run.php:54](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:54>), making unfiltered marker validation mandatory.

**Failure scenario:**

- Push 2 captures `$EXPECTED_TENANTS` before deployment, copies it to the host, then runs verification in the replacement API container without an executable copy-back or recapture step. The helper functions, variable, and `/tmp` manifest belong to the old shell/container.
- Activation force-recreates the API, but the post-activation block expects `/tmp/...-preactivation-expected-tenants.txt` in a “new API shell.” That file was stored in the replaced container.
- The separate before/after web code fences do not redefine `BEFORE`, `AFTER`, `FINGERPRINT_JSON`, or the working directory. With `set -u`, the after block fails immediately in a fresh shell.
- Delta and rollout helpers append only markers already matching the expected tenant (`grep "^WLOTA1A-PERMISSIONS tenant=${tenant_id} "`). An extra successful marker for an unexpected UUID is discarded before `assert_exact_marker_set`, so the unexpected marker passes undetected.
- The activated-registration `mode=ACTIVATED outcome=APPLIED` requirement is prose only. The executable block validates only the later `ALREADY_APPLIED` reseed.
- Migration and OFF/ON evidence use `cmp`, but the remaining §11 delta, census, and rollout marker gates do not compare their canonical record sets against committed heredoc expectations as required by this audit.

**Minimum correction:** Persist each manifest and required state on the host, then show exact copy-in/redefinition commands for every replacement container and fresh shell. Make each web block self-contained or one continuous shell. Aggregate every raw marker with the relevant prefix before validation so unexpected UUIDs remain visible. Capture and compare the initial activated registration marker. Build deterministic expected aggregate files from the immutable tenant manifest plus committed literal tails and use `cmp` for every §11 gate.

## MAJOR

### M-C — The generated Roles DTO cannot have the contracted wire field

**Plan:** [rev 4:1209](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:1209>), [rev 4:1231](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:1231>), [rev 4:1241](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:1241>), and [rev 4:1972](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:1972>).

**Source:** The configured raw DTO transformer is listed at [typescript-transformer.php:34](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/typescript-transformer.php:34>). A PHP property named `emailVerifiedAt` at [UserData.php:25](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Application/DTOs/UserData.php:25>) is generated with the same camel-case name at [generated.d.ts:1017](</Users/houssamr/Projects/syneriva/apps/erp/packages/shared/types/generated.d.ts:1017>). The existing Roles API deliberately serializes snake-case keys at [RoleController.php:139](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:139>).

**Failure scenario:** The proposed PHP property is `isProvisionedReadOnly`, but the plan requires the generated member and wire key to be `is_provisioned_read_only`. Under the repository’s transformer, generation produces `isProvisionedReadOnly`. If RolesPage follows the plan and reads the snake-case member, typecheck fails. If the DTO object is JSON-encoded directly, existing `guard_name`, `users_count`, and timestamp keys also become camel case, breaking the current response contract. No named backend red-first test asserts the exact roles-index wire shape.

**Minimum correction:** Specify one executable serialization/type-generation strategy that produces the same snake-case keys on both the JSON wire and generated TypeScript declaration without hand-editing. Add a named backend response-contract test asserting `is_provisioned_read_only` is marker-derived and that all existing role keys remain unchanged, with first failing assertion, exact command, and lane. Keep the existing name/permission/delete rejection tests and grant snapshots.

## MINOR

### N1 — Two refreshed citations point to stale line numbers

**Plan:** [rev 4:256](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:256>) and [rev 4:259](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:259>).

**Source:** [RolesAndPermissionsSeeder.php:547](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:547>) is `pos.rotate_qr_signing_key`, not role synchronization; synchronization is at [RolesAndPermissionsSeeder.php:568](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:568>). [RolesAndPermissionsSeeder.php:632](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:632>) is `workshop.technicians.manage_certifications`, not manager recall; `batches.recall` is at [RolesAndPermissionsSeeder.php:653](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:653>).

**Failure scenario:** Reviewers following the convention-10 evidence land on unrelated permissions despite the plan claiming every citation was reverified at the refreshed baseline.

**Minimum correction:** Replace `:547` with `:568` and `:632` with `:653`. Re-run the absolute-citation semantic check, not only existence/range validation.

## Rev-3 closure table

| Finding | Result | Evidence line |
|---|---|---|
| B-B1 — migration success matching | **CLOSED** | Exact literal matches and heredoc `cmp` are executable at [rev 4:2699](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:2699>)–2728 and match the source output. |
| B-B2 — topology branch | **NOT CLOSED** | Separate-app activation remains prose at [rev 4:3479](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3479>), while SHA variables are undefined at [rev 4:3143](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3143>). |
| B-B3 — same-shell fail-closed gates | **NOT CLOSED** | Post-activation requires a pre-activation `/tmp` file in a replacement container at [rev 4:3416](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3416>). |
| M-C — protected-role API/web contract | **NOT CLOSED** | Rejection behavior is specified, but the camel-case DTO at [rev 4:1218](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:1218>) cannot generate the required snake-case member at [rev 4:1244](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:1244>). |
| M-D — `tenants:run` truthy normalization | **CLOSED** | Exact normalization is specified at [rev 4:1474](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:1474>) and both PostgreSQL wrapper tests are named at [rev 4:1806](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:1806>). |
| N-B — identical A-1b ownership | **CLOSED** | Both lists use the identical eligibility wording at [rev 4:141](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:141>) and [rev 4:3762](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-4.md:3762>). |

Round-1 B5 remains open through B-B2/B-B3. Round-1 N1 is regressed by the two stale semantic citations. Round-1 B1–B4, M1–M10, N2 and round-2 B-A, M-A, and M-B show no other regression.

## Rejected false positives

- A-1a correctly implements no hold schema, request route, hold behavior, eligibility change, release/reject transition, or hold UI.
- Dormant provisioning of `batches.recall.request` is a prerequisite, not a state-machine leak.
- A-1b’s four-state enum/CHECK is forward-compatible schema, not premature release/reject behavior.
- A-1b correctly owns only request/hold and `requested → recalled`; A-1c remains the sole release/reject owner.
- Delivery, write-off, stock-count, and return eligibility do not belong to A-1a or A-1b without separately named and sourced lanes.
- The protected-role migration’s PostgreSQL/SQLite constraints, non-null team requirement, partial index, guarded rollback, and role-ID preservation remain valid.
- The protected-role name/permission/delete rejection contracts and named PostgreSQL tests are present; the M-C finding is specifically the unresolved generated/wire DTO shape.
- Tasks 1, 2, and 6 remain the only implementation tasks.
- The manifest §4 checklist is byte-for-byte identical to the canonical checklist.
- No unauthorized design change outside a named round-3 correction was found.

## Preserve

- Tasks 1/2/6-only A-1a scope and explicit no-hold boundary.
- The corrected Q10 split: A-1b request/hold plus `requested → recalled`; A-1c release/reject.
- Four-state forward-compatible A-1b schema.
- Location-scoped current/historical visibility, `null` versus `[]`, filtered eager loads, and four-decimal totals.
- Complete trace DTOs, owning-module adapters, payload compatibility, and architecture boundary.
- Correct middleware route matrix preserving existing FormRequest checks.
- Non-null team marker constraint, partial unique index, SQLite triggers, guarded down migration, and role-ID preservation.
- Collision-safe delta, custom-grant preservation, transaction/team restoration, assignment locking, and manager recall removal.
- Stable protected-role mutation error, rejection ordering, and grant/identity snapshot tests.
- Flag-off legacy seeding, explicit Push-4 delta, permission-map provenance, web fingerprint, and worker → API → scheduler order.
- Convention-09 second-company, selected second-location, and rerun evidence.
- Both required reviewers per task and combined review.
- Verbatim shared-manifest checklist.
- Preserve the unrelated untracked files:
  - `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md`
  - `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md`

## Owner decisions required

None. The required corrections follow existing owner rulings, repository behavior, and the canonical staging manifest.

VERDICT: CHANGES-REQUIRED