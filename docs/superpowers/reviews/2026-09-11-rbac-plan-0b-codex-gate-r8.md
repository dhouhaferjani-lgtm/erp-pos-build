# Codex plan gate r8 — RBAC wave 0b plan rev 6.2 (gpt-5.6-sol, high, read-only, 2026-09-11)

`git rev-parse --short HEAD` → **`5d99307e7`**

Reviewed only `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` REV 6.2. I did not review the wave-1 plan. No files were changed and Artisan was not booted.

## Rev-1 closure table

| Round-1 finding | REV 6.2 disposition |
|---|---|
| B0b-1 — admin v0 omitted all nineteen additions | **CLOSED at rev-2 anchor and preserved.** Admin’s adoption oracle contains all nineteen additions. The historical seven-key deployment ruling was correctly separate from the oracle and was later superseded by rev 6’s settled per-key grant map. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:60-61,9338,9373,9377-9378` |
| B0b-2 — knowingly red Task 1 commit | **CLOSED at rev-2 anchor and preserved.** Catalogue coverage lands with Task 6, and every intermediate commit must pass its stated run list. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:62,8812-8814,9339` |
| B0b-3 — file-level writer coverage was not method-sensitive | **CLOSED at rev-2 anchor and preserved.** The AST census remains method-level, partitioned into locked, inherited and initialization-only writers; inherited callers are proven tree-wide, with per-method acquire/write and row-order assertions. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8815-8818,9340,9385-9406` |
| B0b-4 — frontend callers knowingly issued gated requests | **CLOSED at rev-2 anchor and strengthened later.** The original sixteen-row closure evolved into the scripted 87-row/17-file census, guarded transitive callers, five e2e actors and a mandatory frontend-conventions precondition. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4924-4926,5291-5415,6989-7067,9341` |
| B0b-4b — wrong service-category API path | **CLOSED at rev-2 anchor and preserved.** `/service-categories` remains canonical; service and service-category updates use PATCH while `/services/categories` remains a valid SPA route. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5604-5621,5806-5812,7027,9342` |
| B0b-5 — missing admin could report success | **CLOSED at rev-2 anchor and preserved.** Apply and dry-run retain `FAILED reason=admin_role_missing`, roll back and exit nonzero. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8821,9343` |
| B0b-6 — no atomic success condition across two deploy invocations | **CLOSED at rev-2 anchor and preserved.** The wrapper writes `pending`, records the failing half, exits nonzero on either failure and writes `ok` only after both successful invocations. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8986-9073,9344` |
| M0b-1 — fleet helpers/provider incomplete | **CLOSED at rev-2 anchor and preserved.** Required helpers, migrations-behind coverage and the extensible command provider remain supplied. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8820,9345` |
| M0b-2 — wholesale task resequencing | **REJECTED-correctly.** The residual execution-discipline rule—author and observe each test before its implementation and record branch-removal reds honestly—is sufficient; task-level resequencing is not required. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9346,9360-9364` |
| M0b-3 — placeholders and prose-only implementations | **CLOSED at rev-2 anchor and preserved.** Concrete bodies, bounded instructions and explicit staging paths remain present. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9347` |
| M0b-4 — inexact staging and commit subjects | **CLOSED at rev-2 anchor and preserved.** Task-local staging is explicit and production subjects use `Phase 0.2.<task>:`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:72-73,8853,9348` |
| M0b-5 — wrong PostgreSQL/static-analysis harness | **CLOSED at rev-2 anchor and preserved.** Every PG leg uses `-c phpunit-pgsql.xml`; final analysis paths are enumerated. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:66-71,8288-8290,8843-8851,9349` |
| Four round-1 minors | **CLOSED at rev-2 anchor and preserved.** Start-universe wording, marker-mode rendering, create-only semantics and generic failure construction remain corrected. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9350-9353` |
| Round-1 citation rows | **CLOSED at rev-2 anchor and preserved.** Historical pins are distinguished from current tips and the plan requires re-derivation after both lane merges. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:33-45,296-311,9354-9358` |
| Round-1 cross-plan contracts | **CLOSED at rev-2 anchor and preserved.** The wave-0b section accurately says wave 1 inherits none of the changed stopgap production contracts and retains all three re-pin lessons. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9366-9412` |

The rev 1→rev 2 change log remains faithful to the inspected code and historical rulings. Its seven-key `--grant-to` shape was correct at the rev-2 anchor and is explicitly superseded by rev 6’s settled per-key `--grant` map; this does not reopen any round-1 finding. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:27,9332-9364,9377-9378`

## Rev-7 closure table

| Round-7 finding | REV 6.2 disposition |
|---|---|
| M7-1 — location-scope test accepted 422 as well as 403 | **CLOSED at rev-6.2 anchor.** The row-absence assertion remains first; the response now uses `assertForbidden()` and pins `error.code=LOCATION_ACCESS_DENIED`. The restriction is then removed and the identical payload must return 201 and create exactly one row. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8115-8156,9120` |
| Minor 1 — Task 13 summary retained obsolete 403 wording | **CLOSED at rev-6.2 anchor.** It now states scoped 404 plus `SERVICE_NOT_FOUND`, consistent with the supplied class and lane controller. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7622-7625,9121`; `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Controllers/ServiceController.php:128-149` |
| Minor 2 — Probe B collapsed service-category GET/POST/PATCH under 200 | **CLOSED at rev-6.2 anchor.** Probe B now says GET 200, POST 201 and PATCH 200. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8700-8702,9122`; `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Controllers/ServiceCategoryController.php:111-126,132-166` |
| Rev-6.1 status-pass overclaim | **CLOSED operationally at rev-6.2 anchor.** The historical `[403,422]` verdict is struck and immediately corrected, while the old asserted value remains visible as history. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9123,9179-9181` |
| Round-7 citation audit | **CLOSED and still current.** All four refs, principal diffstats, ancestry results and the seven-path T2 overlap remain unchanged. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:37-43,9124` |

The rev 6.1→6.2 diff has seven relevant hunks: revision header, Task 13 wording, row-first comment, exact PHPUnit assertions, Probe B’s per-verb statuses, the new change log and the annotated historical status row. Its substantive M7-1 claim matches the lane exactly: `ValidLocationAccess` emits the sole matching location error, and `StoreStockAdjustmentRequest::failedValidation()` converts that precise error shape to HTTP 403 with `error.code=LOCATION_ACCESS_DENIED`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3,8121-8156,9114-9126,9179-9181`; `lane/w-lot-a-1a:apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockAdjustmentRequest.php:55-70,83-90`; `lane/w-lot-a-1a:apps/api/app/Rules/ValidLocationAccess.php:84-87`

## BLOCKER

None.

## MAJOR

None.

## MINOR

1. **M8-1 — the newly added status-pass retrospective incorrectly says all three r7 defects were prose-only and that the PHPUnit contract was already right.** M7-1 was specifically an executable PHPUnit assertion defect: rev 6.1’s test accepted `[403,422]`, and rev 6.2 changes that assertion to exact 403 plus `LOCATION_ACCESS_DENIED`. Replace “All three are propagation misses into prose, not into executable assertions—the e2e and PHPUnit contracts were already right” with wording that distinguishes **one executable PHPUnit defect and two prose propagation misses**. The operative test and change-log row are already correct, so this is editorial only. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8140-8141,9120,9181`

## Citation audit

Current refs, re-measured from the shared checkout:

| Ref | Current tip | Ancestor of `dev`? | Current diff from `dev` |
|---|---:|:---:|---:|
| `dev` | `33796cc08` | — | — |
| `lane/w-lot-a-1a` | `a7010fe4d` | No | 83 files, +4,758/−663 |
| `lane/t2-receipt-spine` | `208449350` | No | 104 files, +13,412/−582 |
| `lane/rbac-w0a` | `ed88aa2ed` | No | 2 files, +117/−2 |

These match the plan. W-LOT’s post-pin commit remains documentation-only. T2 still overlaps exactly seven wave paths. All three ancestry checks still fail, and the wave-0a ratchet is absent from `dev`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:37-45,261-311`

The settled amendment arithmetic is consistently represented: post-0a `152/146/298`, post-0b-15 `152/142/294`, and final wave-0b ceilings `127/126`. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:37,8611-8629,8847,9116`

## Rejected false positives

- The historical `[403,422]` text in the rev-6.1 status table is not an active assertion. It is struck and immediately corrected; retaining it as annotated history is the settled treatment. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9179-9181`
- Task 13’s stock-adjustment summary need not repeat every response assertion: the supplied executable class pins 403 and `LOCATION_ACCESS_DENIED`, while the summary correctly emphasizes the convention-09 row semantics. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7626,8121-8156`
- The unmet W-LOT, T2 and wave-0a prerequisites are execution blocks, not plan defects. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:261-289,9128`
- M0b-2 still does not require wholesale task resequencing; the execution-discipline note remains acceptable. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9346,9364`
- The internal ensure runner and CLI do change in wave 0b, but wave 1 inherits none of those contracts because it deletes the stopgap. Recording that distinction is accurate. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9368-9379`

## Preserve

Preserve:

- the row-first `assertDatabaseMissing`, exact `assertForbidden()` and `LOCATION_ACCESS_DENIED` assertion;
- the identical-payload 201 positive half and exactly-one-row assertion;
- Task 13’s scoped service 404 with `SERVICE_NOT_FOUND`;
- Probe B’s per-verb service-category statuses;
- the annotated historical rev-6.1 status row;
- admin’s all-nineteen version-0 oracle and the settled 8 admin-only / 10 manager-only / 1 shared split;
- the 20/23 method-level AST writer census and tree-wide, list-valued `LOCK_INHERITED_FROM`;
- all frontend census guards and five-arm 5xx/console capture;
- the wrapper’s `pending` / `failed:<half>` / `ok` semantics;
- all three merge prerequisites;
- the M0b-2 execution-discipline note without task resequencing;
- the wave-1 record of the ensure CLI change and all three re-pin notes.

`docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8121-8156,9126,9366-9412`

## Owner decisions required

None. M8-1 is a mechanical editorial correction.

## Dispatch assessment

REV 6.2 has **0 BLOCKER / 0 MAJOR / 1 MINOR editorial finding**.

M7-1 is closed correctly and completely. Both round-7 prose defects are repaired, the rev 6.1 status row is properly annotated, current lane measurements still match, and no prior closure has been reopened. The plan is dispatch-ready with M8-1 listed in the dispatch brief.

Execution still cannot start until `lane/w-lot-a-1a`, `lane/t2-receipt-spine`, and wave 0a are merged into `dev`. The ensure CLI signature change is recorded for wave 1. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:261-289,9128,9366-9378`

VERDICT: DISPATCH-READY