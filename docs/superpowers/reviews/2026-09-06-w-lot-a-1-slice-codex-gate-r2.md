# Codex slice-plan gate r2 — W-LOT-A-1 rev 2 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 2 at 1ffaaad95. Verbatim.

---
# Adversarial gate review — W-LOT-A-1 rev 2

Review baseline: repository HEAD is `5fe262c84527d90a6124e467978b282806630d50`. The plan incorrectly declares `f13b923a5c150ceee8acb8165e98197207c88eff` as current HEAD. All linked local `path:line` citations are present and in bounds at actual HEAD, and the relevant production sources are unchanged between those commits. No files were edited, no tests were run, and no Git writes occurred.

Q10 is correctly reflected: the plan implements only `requested → recalled`, names W-LOT-A-1b for release/reject, transports a separate transition reason, and reserves a status-independent single terminal-child slot. That part is dispatchable in isolation.

## BLOCKER — blocks dispatch

### B-R2-1. The requested immediate POS block does not reach the live sale path

- Plan: [lines 655–688](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:655>), especially the admitted limitation at [line 688](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:688>), tests at [line 709](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:709>), and `Device build: no` at [line 917](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:917>).
- Source: Q5 requires an immediate local sale-and-transfer block at [owner ruling line 114](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:114>), and the scope contract repeats it at [authoring prompt line 13](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-slice-plan-WLOTA-1-permissions-hold-2026-09-06.md:13>). The online receipt route is permanently short-circuited to 410 at [POS routes.php:168](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/routes.php:168>) and [line 187](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/routes.php:187>). `ReceiptController::store()` is explicitly retired at [ReceiptController.php:572](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:572>), and `ReceiptCreationService` says the path has not run in production since retirement at [ReceiptCreationService.php:1550](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:1550>). Live device sales reach non-strict lot consumption only after the fiscal event is sealed at [PosCoreReceiptProjection.php:1996](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1996>) and [line 2046](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2046>).
- Failure scenario: a branch creates a recall request, but an offline or already-open terminal continues selling the held lot. The server discovers the problem only after payment and fiscal sealing, logs a projection conflict, and cannot reject the sale. The promised “immediate block” therefore does not exist on the production critical path. The proposed online-receipt 422 test can never turn green without violating the intentional 410 retirement.
- Minimum correction: remove `ReceiptController::store()` and the retired new-sale `ReceiptCreationService` path from this slice. Add the live device hold-distribution/cache contract and pre-seal checkout enforcement, refresh/invalidation semantics, two-terminal tests required by [spec line 390](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:390>), exact `apps/pos` files, offline/stale-cache behavior, and device build/fingerprint deployment variables. Preserve the projector’s rule that sealed evidence cannot be rejected.

### B-R2-2. “Sole writer BatchRecallService” is false against existing code

- Plan: glossary ownership claim at [line 53](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:53>) and Task 5 files/implementation at [lines 733–772](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:733>).
- Source: the public repository contract still exposes `recall()` at [BatchRepositoryInterface.php:52](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php:52>); its implementation directly overwrites recall reason/time at [BatchRepository.php:149](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:149>). The entity also directly overwrites those fields at [Batch.php:134](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:134>). Convention 11 requires one writer at [lines 37–39](</Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:37>) and classifies a second writer that drops primary-path evidence as a blocker at [line 60](</Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:60>).
- Failure scenario: an existing or future caller uses `BatchRepository::recall()` and overwrites the first reason/timestamp without locking request roots or appending recalled transitions. The company-wide projection says recalled while immutable recall-request history remains unresolved.
- Minimum correction: add `BatchRepositoryInterface.php` and `BatchRepository.php` to Task 5 and retire or redirect their recall writer. Specify how the entity mutator becomes idempotent and reachable only through the canonical service. Add a writer-census/architecture test and a regression proving no public alternate path can overwrite recall projection fields without terminal evidence.

## MAJOR — fix before the named task

### M-R2-1. Task 1 leaves location-restricted read endpoints leaking other branches

- Plan: route matrix at [line 165](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:165>), but implementation scopes only `expiring()` at [lines 195–203](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:195>). Task 6 explicitly acknowledges that batch stock is not staff-scoped at [line 860](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:860>).
- Source: the governing spec requires batch read/stock/suggestion endpoints to respect location authority at [spec line 308](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:308>). `show()` loads every location at [BatchController.php:105](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:105>); `stock()` returns every location at [line 264](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:264>); product batch stock emits unfiltered eager-loaded stock at [line 329](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:329>) and [BatchRepository.php:65](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:65>). POS suggestions validate company ownership but not membership location access at [BatchController.php:288](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:288>).
- Failure scenario: an A1-only employee with `batches.view` supplies A2’s UUID or opens batch detail and sees A2 quantities, reservations, and availability.
- Minimum correction: expand Task 1’s exact files/signatures to scope list, detail, batch-stock, product-stock and POS-suggestion relations/queries through `LocationScopeResolver`. Add A1/A2 positive and denial tests on each endpoint, including an empty-membership case.

### M-R2-2. The approved viewer/operator seeded-role delta is missing

- Plan: Task 2 changes only manager/general-manager grants at [lines 281–284](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:281>).
- Source: the accepted spec requires `batches.view` for viewer and operator at [spec lines 314–322](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:314>). Neither currently has it: viewer grants begin at [RolesAndPermissionsSeeder.php:704](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:704>) and operator grants at [line 769](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:769>).
- Failure scenario: when Task 1 activates `batches.view`, seeded viewer/operator users lose previously available read-only batch visibility and receive 403, while the plan’s tests use manually constructed view-bearing payloads and miss the seeded-role regression.
- Minimum correction: add targeted `batches.view` grants for viewer and operator, preserve the prescribed absence of mutation/traceability permissions, and add fresh/existing-tenant seeder, API, web-map, and rerun assertions for every seeded role.

### M-R2-3. The capability endpoint is promised but omitted from both tasks’ complete contracts

- Plan: the shared section promises `BatchRecallRequestController::capabilities()` and `GET /batches/recall-capabilities` at [line 108](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:108>). Task 3’s “Full public contracts” omits the method at [lines 478–531](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:478>) and lists only two request routes at [lines 533–538](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:533>). Task 6’s frontend signatures likewise omit a capability fetch/hook at [lines 823–834](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:823>).
- Failure scenario: the implementer follows the per-task “complete” contracts and ships no capability route or query. The web form then cannot distinguish dormant from enabled state, or invents a handwritten/duplicated mechanism.
- Minimum correction: put the literal route, controller signature, response DTO/envelope, API-client signature, hook signature, tenant-scoped key, loading/error behavior and direct API/Vitest assertions into Tasks 3 and 6.

### M-R2-4. Permission-gate activation contradicts the manifest’s dormant-code push

- Plan: [line 106](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:106>) says changed batch actions use the flag, while Task 1 says simply add `can:` middleware at [line 197](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:197>). Deployment calls Push 3 dormant at [line 919](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:919>).
- Source: the manifest requires all Push-3 behavior to remain inert at [manifest lines 114–132](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:114>).
- Failure scenario: one implementer adds unconditional middleware, changing access before the role delta; another conditionally omits it while the flag is false, leaving globally callable recall routes through Push 4. The plan has no exact middleware/signature or transition-state test resolving those alternatives.
- Minimum correction: specify the exact flag-aware authorization mechanism and the safe access matrix during Pushes 3, 4 and 5. Include OFF-before-delta, OFF-after-delta, and ON-after-cache-reset API tests. At no point may recall become unguarded or intended viewers be locked out.

### M-R2-5. Prior M6 remains open: several alleged red-first cases are already green or fail before the named assertion

- Plan: [line 148](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:148>) calls every listed case a required first behavioral failure.
- Source: CLAUDE requires a genuinely failing test first at [CLAUDE.md:18](</Users/houssamr/Projects/syneriva/apps/erp/CLAUDE.md:18>).
- Failure scenarios:
  - Task 1’s foreign-company assertion at [plan line 213](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:213>) already passes because `findBatchOrFail()` returns company-scoped 404 at [BatchController.php:57](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:57>).
  - Task 3’s flag-off request assertion at [plan line 567](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:567>) already sees 404 because the route does not exist at HEAD.
  - Task 3 schema tests at [lines 559–561](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:559>) encounter a missing-table/setup error before their claimed boolean assertion.
  - Task 4’s unchanged stock/value assertion at [line 712](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:712>) is already true after Task 3 because request creation is expressly non-mutating at [plan line 96](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:96>).
  - Task 5’s company-B unchanged assertion at [line 787](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:787>) is already protected by the existing company lookup.
- Minimum correction: distinguish supplemental green regression cases from actual red cases. For every task, identify at least one command whose valid setup reaches and fails the literal target assertion before production changes. Schema-first dependencies must be split so missing classes/tables are not accepted as red evidence.

## MINOR

### N-R2-1. The declared planning baseline is not current HEAD

- Plan: [line 3](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:3>).
- Source: repository metadata resolves HEAD to `5fe262c84527d90a6124e467978b282806630d50`.
- Failure scenario: handbacks and later gates attribute the implementation contract to the wrong snapshot, despite the authoring prompt requiring current HEAD.
- Minimum correction: repin the plan to actual HEAD. Relevant code did not drift, so citations need re-attribution rather than substantive relocation.

### N-R2-2. Push 1 cannot be “not collapsed” when it ships no tooling commit

- Plan: `Collapsed pushes: none` and “no new tooling commit required” coexist at [line 919](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:919>).
- Source: manifest Push 1 is defined as shipping read-only tooling at [manifest lines 74–87](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:74>).
- Failure scenario: the operator is instructed to perform an empty “push” merely to run existing censuses.
- Minimum correction: mark Push 1 collapsed into the pre-Push-2 baseline evidence step, or identify an actual Push-1 commit.

### N-R2-3. The cache-reset marker is already knowable and is not quoted exactly

- Plan: [line 914](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:914>) defers verification and omits the terminal period.
- Source: installed Spatie code emits exactly `Permission cache flushed.` at [CacheReset.php:20](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/spatie/laravel-permission/src/Commands/CacheReset.php:20>).
- Failure scenario: deployment evidence uses an approximate marker despite the plan’s requirement to verify complete CLI contracts at HEAD.
- Minimum correction: pin the exact literal now.

### N-R2-4. The deterministic transition UUID algorithm is underspecified

- Plan: [line 94](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:94>) and [line 769](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:769>).
- Failure scenario: implementations choose different UUID versions, namespaces or input framing, so “deterministic” IDs differ between workers or future maintenance.
- Minimum correction: name the UUID algorithm/version, fixed namespace and exact canonical input, and assert the resulting UUID for one fixture.

### N-R2-5. Convention-10 prose appears to reopen an already-ruled role choice

- Plan: [line 43](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:43>) says application-role mapping remains an owner policy decision.
- Source: the owner already confirmed the new general-manager role and immediate hold at [owner rulings lines 95 and 114](</Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:95>).
- Failure scenario: a reviewer treats the role choice as unresolved despite the later “no owner decision” statement.
- Minimum correction: say the operational mapping is ruled; only legal qualification as a pharmaceutical quality authority is not established by the benchmark.

## Prior gate r1 closure table

| Prior item | Status | Rev-2 evidence and result |
|---|---|---|
| B1 | **NOT CLOSED** | Q10 wording, later-slice ownership and dispatch stop are corrected at [lines 61–69](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:61>) and [899–901](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:899>), but the required HEAD repin is still wrong at line 3. |
| B2 | **NOT CLOSED** | Exact manifest reference, ten variables and checklist are present at [lines 905–961](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:905>), but Task 1’s unconditional `can:` instruction conflicts with dormant activation, as M-R2-4 explains. |
| M1 | **CLOSED** | Status-independent terminal unique and trigger at [lines 373–386](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:373>); recall selects only unresolved roots at [line 767](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:767>). |
| M2 | **CLOSED** | Separate request and transition reason/actor/time DTO contract at [lines 425–465](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:425>) and history assertion at [line 789](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:789>). |
| M3 | **CLOSED** | Exact create/update/role-endpoint transaction ordering at [lines 294–296](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:294>). |
| M4 | **CLOSED** | Durable marker, collision policy, exact outcomes and rerun contract at [lines 298–302](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:298>). |
| M5 | **NOT CLOSED** | Exception/envelopes are named at [lines 655–686](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:655>), but the online POS mapping is unreachable and its 422 test contradicts the pinned 410 route. |
| M6 | **NOT CLOSED** | Tables, lanes and convention-09 mappings were added, but several rows are not red-first, as M-R2-5 demonstrates. |
| M7 | **CLOSED** | Annotated Data classes/enum, generated output and aliases are specified at [lines 398–476](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:398>). |
| M8 | **CLOSED** | Exact scoped-location source, positive physical-stock intersection and retry behavior at [lines 860–864](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:860>), with tests at lines 882–884. |
| N1 | **CLOSED** | NV-only comparator rows now use DEFER at [lines 34–41](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WLOTA-1-permissions-hold-rev-2.md:34>). |

## Rejected false positives

- Q10 is not stale or open. Its ruled text is copied correctly, only `requested` and `recalled` are implemented, and W-LOT-A-1b is explicitly named for release/reject.
- The state machine is forward-compatible: terminal uniqueness is independent of status, eligibility tests absence of any terminal child, and transition reason/actor/time are already typed.
- No forbidden release/reject permission, route, writer, UI or behavioral test is introduced.
- The schema’s UUID/bigint FK types match current parent tables; no JSONB exists, so no JSONB DTO is owed.
- Six tasks satisfy the task-count ceiling.
- The convention-10 table has all nine requested rows and follows the authoring prompt’s explicit `ID | Guarantee | ...` format. NV-only rows no longer misuse DIVERGE.
- Convention-09 second-company, second-location and rerun mappings are present per task. The remaining problem is the inaccurate red-first classification, not their absence.
- The three convention-11 terms and single batch-detail operator surface are appropriate; the rejected part is only the false sole-writer claim.
- The shared-manifest reference sentence, all ten variable rows, web fingerprint block and copied checklist are present.
- Existing BatchDetail quantity/`parseFloat` debt belongs to the separately specified L3 precision lane; this slice should not absorb that refactor merely because it touches the page.
- Existing create/update/transfer/write-off authorization should remain preserved; this review does not require reopening those guards.

## Preserve list

- Preserve the exact Q10 boundary: this slice implements only `requested → recalled`; W-LOT-A-1b owns release/reject.
- Preserve the status-independent single terminal-child constraint and append-only request/transition evidence.
- Preserve `product_batches.is_recalled` as the company-wide projection, without allowing alternate overwrite writers.
- Preserve module, Sanctum, tenant-claim, permission-team and company-scoped 404 behavior.
- Preserve permission-based custom-role authorization; do not hardcode the role name as the only authority check.
- Preserve `NULL = unrestricted`, `[] = no access`, and no Treasury bypass for branch requests.
- Preserve zero hold-side changes to quantities, reservations, movements, valuation or GL.
- Preserve decimal-string quantities, variant separation, FEFO fallback, `SKIP LOCKED`, and established aggregate-before-batch lock ordering.
- Preserve the device-authored fiscal sale as the canonical live path; never resurrect the retired online new-sale endpoint.
- Preserve sealed fiscal evidence and financial projection even when post-seal lot projection conflicts.
- Preserve generated backend-owned frontend DTOs, translations, the existing batch-detail surface and tenant-scoped query keys.
- Preserve forward-only evidence and canonical-manifest rollback discipline.

## Owner decisions required

None. Q5 and Q10 are ruled. The required corrections are implementation and plan-contract corrections, not policy choices.

VERDICT: CHANGES-REQUIRED