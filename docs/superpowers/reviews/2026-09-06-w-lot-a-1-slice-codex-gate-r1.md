# Codex slice-plan gate r1 — W-LOT-A-1 rev 1 (gpt-5.6-sol, high, read-only, 2026-09-06; attempt 1 lost to model capacity)

Input: docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md at 31ff4bc99. Verbatim.

---
Review baseline: HEAD is `64bc7f84aa69d43d4d5131691e2c30ea78fb116b`. No files were changed and no tests or write operations were run.

All 39 linked repository `path:line` citations in the plan exist and are in bounds at HEAD. The cited production code is unchanged from the plan’s older baseline; only the owner-rulings document and newly added staging manifest changed.

## BLOCKER — blocks dispatch

### B1. The plan still treats Q10 as open and explicitly blocks its own dispatch

- Plan: [lines 3–7](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:3>), [line 45](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:45>), [line 724](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:724>), [line 759](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:759>)
- Source: [owner ruling Q10](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:147>), specifically [line 151](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151>) and the policy-neutral slice instruction at [line 158](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:158>).
- Failure scenario: the dispatcher follows the plan’s own checklist and stops for a nonexistent owner decision, or records Q10 as open in implementation handbacks.
- Minimum correction: repin to HEAD, replace “Q10 OPEN” with the ruled text, and state that this slice deliberately implements only the common `requested → recalled` base while the already-ruled release/reject behavior belongs to the later slice. Remove every “reconcile before dispatch” gate. Do not add release/reject implementation here.

### B2. Deployment does not follow the now-present canonical manifest

- Plan: [lines 726–742](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:726>)
- Source: required reference sentence at [manifest line 265](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:265>), required variables at [line 273](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:273>), dormant-code requirement at [line 114](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:114>), permission rollout at [line 136](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:136>), web block at [line 201](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:201>).
- Failure scenario: Task 3’s POST route becomes writable before hold enforcement or tenant permission rollout. The plan says not to activate the writer before Task 4, but defines no feature flag or equivalent activation mechanism. The deployment also lacks the manifest’s exact migration annotation, tenant command mode/marker, web fingerprint, queues, device, collapsed-push, census, and environment-path variables.
- Minimum correction:
  - Insert the manifest’s required reference sentence verbatim.
  - Supply all ten variables: slice slug, ordered migration list, flag, commands with `tenants:run` status and stdout marker, censuses, web fingerprint, device build, queues, collapsed pushes, and environment path.
  - Define a default-false hold feature flag read by both request creation and every eligibility consumer; activate only after schema, Task 4/5 code, per-tenant delta/reseed, and per-tenant cache reset.
  - Mark the migration additive and self-guarding.
  - Give exact per-tenant invocations and grep markers for the delta and cache reset.
  - Reference rather than re-derive the manifest mechanics, and include its required gate checklist.

## MAJOR — fix before the named task

### M1. The supposedly policy-neutral schema permits mutually exclusive terminal outcomes to coexist

- Plan: status-specific unique at [line 329](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:329>); recall transitions “every requested root” at [line 596](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:596>).
- Source: Q10 requires `requested → recalled` **or** `requested → released` at [owner ruling line 151](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151>).
- Failure scenario: after the later release slice appends `released`, Task 5 can still append `recalled` for the same root. `UNIQUE(company_id, request_id, status)` allows both.
- Minimum correction: use a partial unique such as `(company_id, request_id) WHERE request_id IS NOT NULL`, independent of status; make the consistency trigger enforce one terminal child; and have Task 5 transition only roots with no terminal child. This remains release/reject-neutral.

### M2. The transport contract drops the recall-transition reason

- Plan: DTO fields at [line 361](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:361>), mandatory transition reason at [line 597](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:597>).
- Source: mandatory reason and append-only evidence are owner-ruled at [line 151](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151>).
- Failure scenario: the table stores the general manager’s recall reason, but the API/history DTO exposes only one ambiguous `reason`, alongside requester fields. Reviewers and operators cannot distinguish request evidence from disposition evidence.
- Minimum correction: expose separate request evidence and a nullable typed transition object containing status, reason, actor, and timestamp. Add response/history assertions proving both reasons survive unchanged.

### M3. General-manager assignment sequencing is not dispatchable against the actual create path

- Plan: guard contract and active-membership requirement at [lines 228–257](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:228>).
- Source: user creation currently starts its transaction at [UserController.php:217](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:217>), assigns the role at [line 234](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:234>), and creates membership only at [line 241](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:241>).
- Failure scenario: a guard requiring an active unrestricted membership either rejects every new general manager or runs after role assignment, briefly granting tenant-wide authority before validation.
- Minimum correction: prescribe exact atomic ordering: create user, create/write the effective membership, lock and validate all applicable active memberships, then assign the role—all inside the same transaction. Give equivalent merged-state ordering for update and [RoleController.php:350](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:350>).

### M4. Existing versus slice-created `general_manager` cannot be distinguished on rerun

- Plan: collision and rerun requirements at [lines 252–268](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:252>).
- Source: the current seeder simply creates/fetches named roles and synchronizes them at [RolesAndPermissionsSeeder.php:543](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:543>) and [line 547](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:547>).
- Failure scenario: without durable provenance, a second run cannot tell a role created by the first delta from a pre-existing custom role of the same name. It must either report a collision forever or silently adopt/broaden a custom role.
- Minimum correction: define a durable ownership discriminator and exact collision algorithm. The command must create the role plus provenance atomically; reruns accept only that provenance, while unknown pre-existing names fail without mutation. Include the exact CLI options, result codes, and stable stdout marker.

### M5. Held-stock refusal has no specified exception or endpoint mapping

- Plan: `assertCanIssue()` at [line 465](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:465>) and consumer requirements at [line 509](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:509>).
- Source: direct batch transfer catches only `DomainException` at [BatchController.php:385](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:385>); stock transfer catches only `InsufficientStockException` and `InvalidArgumentException` at [StockTransferController.php:187](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:187>).
- Failure scenario: one path returns a controlled 422 while another leaks a 500, or a projection containment layer mistakes a safety hold for an incidental failure.
- Minimum correction: name the exception class, inheritance, stable error code/status, and mapping at every HTTP/projection consumer. Add the missing exception/controller files to Task 4’s exact file list and assert the error envelope plus unchanged stock/allocations.

### M6. Red-first contracts are not exact, and convention-09 coverage is not mapped per task

- Plan: test sections beginning at [Task 1 line 179](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:179>), [Task 2 line 261](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:261>), [Task 4 line 531](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:531>), and [Task 6 line 692](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:692>).
- Source: convention-09 requires second company, second location, and rerun assertions on data meaning at [lines 37–50](</Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37>).
- Failure scenario:
  - Several entries give a behavioral description rather than a literal first failing assertion.
  - Task 2’s “cannot be narrowed” case calls rejection the first failure even though rejection is the expected result.
  - Task 4’s concurrency cases share one generic failure description rather than one assertion each.
  - The real-registration fixture is declared globally but no per-task table states which tests use it or explicitly marks convention-09 not applicable.
- Minimum correction: for every task, provide a table with exact file, class/suite and method, literal first assertion, exact command, lane, and convention-09 disposition. Define the fixture’s properties, setup/cleanup contract, and list every test class that uses it. Add an SQLite schema-trigger lane or remove the promise of equivalent SQLite triggers.

### M7. Generated-type production contracts omit the mechanism that makes them generated

- Plan: new DTOs at [lines 300–301](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:300>) and claimed generated frontend use at [line 648](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:648>).
- Source: transformer collection is configured at [typescript-transformer.php:17](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/typescript-transformer.php:17>); the established declaration uses `#[TypeScript]` and `extends Data` at [UnitTextMappingResultData.php:10](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Uom/Application/DTOs/UnitTextMappingResultData.php:10>).
- Failure scenario: an implementer creates plain PHP classes matching the shown constructors; `typescript:transform` emits no usable frontend contract, so Task 6 either fails typecheck or introduces a shadow interface.
- Minimum correction: show exact class/enum declarations with `#[TypeScript]`, `extends Data`, imports, enum export, and the fully qualified web aliases such as `App.Modules.BatchExpiry.Application.DTOs.BatchRecallRequestData`. Add a generated-output assertion.

### M8. The promised allowed-location selector has no test or exact data-source contract

- Plan promise: [line 677](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:677>); Task 6 tests at [line 692](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:692>).
- Source: the existing batch-stock endpoint returns every company location’s stock at [BatchController.php:264](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:264>).
- Failure scenario: the form offers A2 to an A1-only manager; submission then returns the intended backend 404. Server security holds, but the promised usable scoped UI does not.
- Minimum correction: prescribe intersection with the existing scoped-location source and positive lot stock, and add a Vitest case asserting A1 is offered, A2 is absent, and the submitted location remains stable on retry.

## MINOR

### N1. Several convention-10 decisions claim “DIVERGE” where every comparator is NV

- Plan: duplicate/edit/rerun/audit rows at [lines 20–27](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:20>).
- Source: decision meanings at [convention-10 line 51](</Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:51>).
- Failure scenario: reviewers cannot identify what benchmark AutoERP is deliberately diverging from when all cited products are explicitly unverified.
- Minimum correction: use `MATCH` for the project guarantee or `DEFER` to a named lane; reserve `DIVERGE` for a verified comparator behavior. The matrix’s columns and required nine rows are otherwise correct.

## Rejected false positives

- Release/reject absence is correct for this slice. [Plan line 713](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-1.md:713>) contains no release/reject state, route, permission, UI, or test, matching the owner’s later-slice instruction.
- The plan does not otherwise implement Q10’s release/reject branch. The finding is future-incompatible terminal uniqueness and stale governance wording, not forbidden release functionality.
- Batch and stock FK types are correct: `product_batches.id` is bigint at [migration line 14](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:14>), while company/location/user identities are UUID-based.
- No JSONB column is proposed, so a JSONB DTO is not required.
- Six tasks satisfy the prompt’s task-count ceiling.
- Existing create/update/transfer/write-off authorization is real and correctly preserved through FormRequests and existing route middleware.
- The intended single operator surface—existing batch detail—is consistent with convention 11; the three proposed glossary rows are substantively reconciled with the existing Lot row.
- The hold remains an eligibility fact rather than a quantity, reservation, movement, valuation, or GL mutation.
- All linked code citations remain accurate at HEAD despite the obsolete declared SHA.

## Preserve list

- Existing `api`, Sanctum, permission-team, tenant-claim, and `module:BatchExpiry` middleware; literal-route ordering and company-scoped 404 behavior.
- Existing FormRequest guards for create, update, transfer, write-off, and reversal.
- `product_batches.is_recalled` as the company-wide recall projection.
- Permission-based authorization for custom roles; no hardcoded general-manager-only action check.
- `NULL = unrestricted`, `[] = no access`, and no Treasury permission bypass for branch recall requests.
- Append-only request evidence and stable request operation UUID replay.
- Zero hold-side quantity, reservation, stock-movement, transfer, valuation, or GL changes.
- Decimal-string quantity handling, variant separation, FEFO ordering/fallback, existing `SKIP LOCKED` behavior, and aggregate-before-batch transfer lock ordering.
- Company B and location A2 independence, real-registration fixtures, and PostgreSQL concurrency coverage.
- Existing batch-detail surface, generated backend-owned frontend types, translations, module gates, and tenant-scoped query keys.
- No release/reject implementation in W-LOT-A-1.
- Forward-only evidence retention and canonical-manifest rollback discipline.

## Owner decisions required

None. Q10 is ruled, and its release/reject implementation is explicitly assigned to a later slice.

VERDICT: CHANGES-REQUIRED