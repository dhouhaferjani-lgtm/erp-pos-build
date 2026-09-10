# Codex plan gate r3 — RBAC wave 0a plan rev 3 (gpt-5.6-sol, high, read-only, 2026-09-10)

`git rev-parse --short HEAD`: `604ae9ec6`

Current refs:

| Ref | Tip | `dev...ref` |
|---|---:|---:|
| `dev` | `630afa86f` | — |
| `lane/w-lot-a-1a` | `52f5ad796` | 83 files, +4,714/−663 |
| `lane/t2-receipt-spine` | `951a7637e` | 100 files, +13,144/−546 |
| `lane/t1-transfers-edge` | `86273346a` | already merged |
| `origin/dev` | `ad1d6ceb1` | does not contain `6415062b9` |

S-1 commit `6415062b9` is an ancestor of local `dev`, not `origin/dev`.

## Rev-2 closure table

| Rev-2 finding | Round-3 disposition |
|---|---|
| B-1 — scanner included the whole Laravel router | **CLOSED.** `RoutePermissionCoverageScanner::isInApiUniverse()` now filters before classification; the non-API/twin liveness case and post-T4 regeneration are present at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:1655-1719,2370-2414,2908-2921`. |
| B-2 — PSR-4 mismatch, missing support implementations and missing classification counts | **CLOSED.** The filename is `RoutePermissionCoverageScanner.php`; all support classes are supplied; `countsByClassification()` is implemented; all appear in file, PHPStan, Pint and commit lists at `:882-896,1575-1963,2551-2560`. The complete PHP snippets pass `php -l`. |
| B-3 — tombstone exemption lacked a source-shape proof | **NOT CLOSED.** The proof mechanism is now sound in shape, but all four fixture pins remain literal placeholders at `:985,992,999,1006`; see B-2 below. |
| M-1 — Phase 0 rejected its own accepted `ci.yml` overlap | **CLOSED functionally.** Checks A/B are separated at `:109-126`; current lane hunks remain `:1130` and `:1138`. A minor arithmetic wording defect remains. |
| M-2 — final PHPStan/Pint commands covered invalid inherited fixtures or no files | **CLOSED.** The exact 25 PHP paths are enumerated at `:3328-3385`; the repository-root completeness check is at `:3387-3390`. |
| M-3 — T5 used invented widget permissions | **CLOSED for the keys.** `promotions.view` and `.manage` are real. The replacement example nevertheless does not match the implementation it claims to reproduce; see M-1. |
| M-4 — liveness collapsed exact classifications to covered/uncovered | **CLOSED.** Exact enum equality and zero-filled five-way distribution are asserted at `:2308-2367`. |
| M-5 — programme gave the UsersPage guard the wrong permission | **CLOSED.** The programme now uses `users.assign-roles` for UsersPage and `roles.view` for RolesPage/route/card at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:138`. |
| M-6 — baseline generation preceded the intended red | **CLOSED.** The missing-baseline red is now Step 5, before generation, at `wave-0a.md:2507-2530`. |
| m-1 — stale ref pins | **CLOSED at current tips.** All recorded tips still match. The multi-ref command used to reproduce them is invalid; see m-1. |
| m-2 — nonnumeric commit suffixes | **CLOSED.** Subjects are numeric `Phase 0.1.0`, `.1`, `.2`, `.4`, `.5`, `.6`, `.7`. |
| m-3 — comments claimed 0a could not edit CI | **CLOSED.** The actual residual is the owner-held protected blob, not file access. |
| m-4 — `actionlint` failure could fall through to YAML parsing | **CLOSED.** The explicit availability branch at `:3263-3273` preserves semantic failures. |
| m-5 — undefined/stale `can:` count | **CLOSED.** Re-derived counts are 597 route-middleware occurrences in `app`+`routes`, 598 throughout `apps/api`; loose literal counts are separately identified as 608/621 at `:2988`. |
| Citation audit | **PARTIAL.** The prior eight citations were corrected, but round 3 found new stale/false coordinates and claims listed below. |

## BLOCKER

### B-1 — Rev 3 removes a binding accepted-spec 0a item and cannot reach the required post-0a ratchet state

The r9 Preserve list explicitly requires “all six start-now 0a tasks” at `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:190-210`. The accepted spec includes 0a-4 at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2191-2194` and fixes post-0a at **152 writes / 142 reads / 294 keys** at `:2205`.

Rev 3 instead tombstones T3/T3b and moves them to 0b-15 at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:2618-2628`. Its handback requires **152/146/298** at `:3406-3411`; the programme repeats the move at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:103,138`.

The code-derived arithmetic is:

- After T1 classification: **315** uncovered = 167 writes + 148 reads.
- After T4’s 15 writes and 2 coupon reads: **298** = 152 writes + 146 reads.
- After the four Identity reads: **294** = 152 writes + 142 reads.

Therefore the requested post-0a 294/152/142 cannot be confirmed for rev 3.

The relocation is operationally understandable: merely restoring the API gates would break manager-visible pages. `RolesPage` calls `/roles` and `/permissions` unconditionally at `apps/web/src/features/settings/RolesPage.tsx:73-89`; its route is protected only by the broader settings module at `apps/web/src/routes/index.tsx:2344-2351`; its card has no permission field at `apps/web/src/features/settings/SettingsPage.tsx:23-42`. Those three required frontend files overlap current W-LOT, so adding the complete fix to start-now 0a is not disjoint.

Required resolution: either obtain and record an explicit accepted amendment superseding r9’s binding Preserve row, or restore 0a-4 and sequence the complete API-plus-frontend package after W-LOT. A plan-gate change log alone cannot override the premise imposed for this gate.

### B-2 — The four binding tombstone source pins are still placeholders

The fixture supplied for `route-tombstones.json` contains unresolved values at:

- `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:985`
- `:992`
- `:999`
- `:1006`

They do not equal the actual token-normalised closure sources at:

- `apps/api/app/Modules/POS/routes.php:187-194`
- `apps/api/app/Modules/POS/routes.php:222-229`
- `apps/api/app/Modules/POS/routes.php:231-243`
- `apps/api/app/Modules/POS/routes_orders.php:60-67`

Consequently `TombstoneRouteBehaviourTest::every_tombstone_closure_has_the_pinned_inert_source_shape()` first fails at the equality assertion at plan lines `1180-1188`, not green as a completed security fixture.

Step 5b at `:2518-2522` can generate the strings, but that is still an unresolved manual paste in the implementation plan. It violates the requested “no placeholders” condition and prevents this gate from reviewing the actual source pins.

The prose also says the regeneration mode “writes them” at `:978` and in the rev-3 change log, while the supplied implementation only emits JSON through `self::fail()` at `:1240-1245`. Supply the four exact strings in the fixture and change “writes” to “prints/emits”.

## MAJOR

### M-1 — T5’s canonical Promotion example is not the file Task 4 leaves behind

T5 calls its snippet “exactly as this wave leaves it” at `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md:3038-3044`, then gives `can:promotions.view` to both GET routes at `:3053-3056`.

Task 4’s actual implementation deliberately leaves those reads without route middleware at `:2795-2816`; it adds only `promotions.manage` to delete and the three transitions. The current route locations are `apps/api/app/Modules/Promotion/Presentation/routes.php:12-21`.

The snippet also omits store, update, activate and pause, despite claiming to reproduce the real file. Applying T5 as written therefore leaves the convention document making a false, diffable claim about a canonical production file.

Required correction: either show the exact post-T4 route group, or label it as a reduced illustrative excerpt and stop claiming it is the resulting file. Do not add the two read gates silently: doing so would change the ratchet arithmetic.

### M-2 — Programme O-2 tells the owner to lower a real class count

Programme O-2 says to lower both `Identity.classes` and `gated_ceiling` when the tenancy lane becomes live at `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:279`.

That is wrong. `Identity.classes` is the actual on-disk class count, 33, at `dev:apps/api/tests/feature-lane-manifest.json:817-821`; the checker says it becomes informational when the lane is live, not numerically smaller, at `apps/api/tools/feature-lane-manifest-check.php:774-781`.

Moreover, flipping the tenancy lane affects eleven groups, not only Identity. The correct operation is:

- remove the one temporary explicit CI allow-list entry;
- retain the actual per-group class counts;
- recompute `gated_ceiling` from all groups still behind inactive lanes.

Blindly decrementing the two numbers by one would falsify the manifest rather than describe the runner transition.

## MINOR

### m-1 — Both multi-ref `git rev-parse --short` commands are invalid

The commands at `wave-0a.md:17` and `programme-execution-plan.md:346` exit 128 with:

```text
fatal: Needed a single revision
```

Run `git rev-parse --short <ref>` separately for each ref, or use a loop.

### m-2 — Phase-0 hunk intersection arithmetic is misstated

The parenthetical at `wave-0a.md:126` uses `start + count ≥ 143`. The last old-file line is `start + count - 1`, and intersection with `143..236` is:

```text
start <= 236 && start + count - 1 >= 143
```

The present `<237` stop condition is conservative and catches the current risk, so this creates no current false negative, but the arithmetic should be made exact at both `:126` and `:3279`.

### m-3 — Two, not three, tombstone closures have typed `$id`

The comment at `wave-0a.md:1214-1216` says three closures declare `function (string $id)`. Only payments and close do so: `apps/api/app/Modules/POS/routes.php:231-243` and `routes_orders.php:60-67`. Receipts and void use `function ()` at `routes.php:187,222`. The body-only token logic remains correct.

### m-4 — The authorization checklist citation starts two lines early

`wave-0a.md:3009,3113` cites `docs/conventions/03-AUTHORIZATION.md:176-184`. Line 176 closes the preceding code fence; the checklist is actually `docs/conventions/03-AUTHORIZATION.md:178-184`.

## Citation audit

Wrong or stale citations/claims are exactly:

1. Tombstone fixture source fields at `wave-0a.md:985,992,999,1006`; real closures are `POS/routes.php:187-194,222-229,231-243` and `POS/routes_orders.php:60-67`.
2. Typed-closure claim at `wave-0a.md:1214-1216`; the real count is two.
3. T5 “exact post-wave file” claim at `wave-0a.md:3038-3064`; the actual proposed route implementation is `:2795-2816`.
4. Checklist locations at `wave-0a.md:3009,3113`; real section is `docs/conventions/03-AUTHORIZATION.md:178-184`.
5. Invalid reproducibility commands at `wave-0a.md:17` and `programme-execution-plan.md:346`.
6. Programme O-2’s semantic citation at `programme-execution-plan.md:279`; the checker’s real live-lane behavior is `apps/api/tools/feature-lane-manifest-check.php:774-817`.

The remaining material citations checked out:

- `AllowSelfService` alias insertion shape matches `apps/api/bootstrap/app.php:113-123`.
- Self-service route sites match Identity `:39-47`, Notification `:19-30`, SupportAccess `:49-58`.
- Notification `{id}` is caller-scoped at `NotificationController.php:55-58`.
- Identity reads are exactly `apps/api/app/Modules/Identity/routes.php:59,61,64,78`; the current full-permission payload is `RoleController.php:429-437`.
- UsersPage import/destructure/query are `UsersPage.tsx:38,102,130-137`; generated key is `permissionsMap.generated.ts:272`; the affected existing assertion is `UsersPage.tenantScope.test.tsx:169-176`.
- T4 route coordinates all match Promotion `:16,19-21`, Uom `:17-19,23,26`, Menu `:18,23,28`, Coupon `:12,14,16,22-23`.
- T4 keys exist at seeder lines `101,104-105,108-109,190-194`; no key is introduced.
- CI references are accurate: named Architecture steps at `dev:.github/workflows/ci.yml:191,202,215,228`; heavy backend job skipped for PR→`dev` at `:325-330`; manual whole-Architecture execution at `:473-501`.
- `dev:` citations for entrypoint `:190-191`, manifest `:817-821`, and CI `:1271` are correct.

## Rejected false positives

- **“All new Architecture tests run on no CI event” is false.** They run through the manual `workflow_dispatch` suite at `dev:.github/workflows/ci.yml:473-501`. What is true is that none of the six new classes presently gates the normal PR→`dev` event. Registration in 0a is required and the proposed step at `wave-0a.md:3238-3259` is correctly placed.
- **The current ratchet arithmetic is not 294/142 without T3.** Live-router re-derivation from `dev` produced 1,092 live routes, 1,054 API-universe routes, 710 gated, 18 public, 7 self-service, 4 tombstones and 315 uncovered = 167 writes + 148 reads. T4 alone reaches 298/152/146; T3 reaches 294/152/142.
- **The self-service marker cannot certify an unrelated route.** Classification uses the exact allow-list, not `authz.self`; the two-way equality and shape checks include a planted unrelated marked route. The exact seven are correct, including the named Notification exemption.
- **The manifest overlap is gone.** S-1 already consumed it on local `dev`; the remaining 0a file list does not include the manifest. At current tips, the only overlap is `.github/workflows/ci.yml`.
- **The current CI overlap is not a textual collision.** W-LOT’s only hunk is `@@ -1130,7 +1130,7 @@`; T2’s is `@@ -1138,8 +1138,11 @@`; 0a appends inside `backend-architecture` after line 228.
- **No other production frontend callers were found.** `apps/web/src` has `/roles` callers only in `RolesPage.tsx:76` and `UsersPage.tsx:133`, and `/permissions` only in `RolesPage.tsx:85`. No production caller of GET `/roles/{id}` or `/users/{id}/roles` exists; `apps/pos/src` has none.
- **Names-only shaping is not implemented by the proposed gate-only change.** The programme explicitly defers D4 payload shaping to wave 2a at `programme-execution-plan.md:162`; the accepted final shape remains defined at spec `:1616-1626`.

Frontend overlap if the complete T3/T3b package were added to 0a:

- W-LOT overlaps `RolesPage.tsx`, `routes/index.tsx`, `usePermissions.ts`, and `permissionsMap.generated.ts`.
- T2 overlaps `permissionsMap.generated.ts`.
- `UsersPage.tsx`, its tests, `SettingsPage.tsx`, and the Identity route file are disjoint from both.
- A UsersPage-only addition is therefore file-safe but functionally incomplete because RolesPage remains reachable.

## Preserve

Preserved and verified:

- `can:` is the route action gate; `require.any.permission:` is retained only for the two D4 any-of reads.
- Exact seven-entry self-service allow-list and B-7 non-self-certifying lineage.
- Separate public, self-service, tombstone, gated and uncovered classifications.
- Separate shrink-only read/write ceilings and live growth/stale checks.
- Four behavior-tested POS tombstones and exact 18-route public allow-list.
- All T4 mappings, with `POST /coupons/validate` deliberately left uncovered.
- No new permission key, seeder edit, migration or `apps/web` edit in the stated 0a.
- D4 final payload shaping remains assigned to wave 2a.
- All implementation commit subjects are numeric and path-scoped.
- CI registration is in the detector’s own lane.
- No legacy role re-homing, permission-registry, template-delta, token-scope, audit-event or migration ruling is disturbed.

Not preserved under this gate’s binding premise:

- Accepted-spec item 0a-4 and its post-0a 294/152/142 state.
- The r9 wording that all six original start-now items remain in 0a.

## Owner decisions required

One immediate dispatch decision is required:

1. Either formally accept the post-spec relocation of 0a-4 to 0b-15, explicitly superseding r9’s final Preserve bullet and changing the accepted post-0a ratchet state to 298/152/146; or require the complete API-plus-frontend item and sequence it after W-LOT.

The existing later decisions remain OQ-1 through OQ-4. None otherwise blocks T0, T1, T2, T4, T5 or CI work. Programme O-2 is not a product decision; it is an erroneous operational instruction that should be corrected.

## Dispatch assessment

| Task | Status | Exact files |
|---|---|---|
| T0 | Dispatchable as written from current `dev` | `apps/api/tests/Architecture/EntrypointPermissionCacheResetShapeTest.php`; `apps/api/docker/entrypoint.sh` |
| T1 | Dispatchable as written | `apps/api/app/Http/Middleware/AllowSelfService.php`; `apps/api/tests/Architecture/Support/SelfServiceRouteRegistry.php`; `SelfServiceRouteAllowListTest.php`; `SelfServiceRouteShapeTest.php`; `apps/api/bootstrap/app.php`; Identity, Notification and SupportAccess route files |
| T2 | **Not dispatchable as-is** | Nine support classes; three Architecture tests; route baseline; liveness fixture; tombstone fixture, as enumerated at `wave-0a.md:882-896` |
| T3/T3b | **Missing from 0a; authority decision required** | If restored completely: Identity routes; `UsersPage.tsx`; `RolesPage.tsx`; `SettingsPage.tsx`; `routes/index.tsx`; `usePermissions.ts`; affected tests and Playwright spec |
| T4 | Technically complete, but depends on corrected T2 and the ratchet-scope decision | Promotion, Uom, Menu and Coupon route files; `RoutePermissionCoverageRatchetTest.php`; route baseline |
| T5 | **Not dispatchable as-is** | `docs/conventions/03-AUTHORIZATION.md`; `.claude/commands/add-permissions.md` |
| T6 CI commit | Dispatchable after T2 is corrected | `.github/workflows/ci.yml` |
| T6 handback | Depends on all prior tasks | `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md` |

All complete PHP snippets were syntax-checked; namespaces, `Tests\TestCase`, Laravel traits/helpers, composer PSR-4 paths, default PHPUnit configuration, PHPStan paths and final Pint paths exist. The stated T0, T1, T2 and T4 initial failures are otherwise reached for the stated reasons. No repository files were modified during this read-only review.

VERDICT: CHANGES-REQUIRED