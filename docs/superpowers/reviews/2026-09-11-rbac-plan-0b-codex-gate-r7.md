# Codex plan gate r7 — RBAC wave 0b plan rev 6.1 (gpt-5.6-sol, high, read-only, 2026-09-11)

`git rev-parse --short HEAD` → **`c8cb33806`**

Reviewed only `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` REV 6.1. I did not review the wave-1 plan. No files were changed and Artisan was not booted.

## Rev-6 closure table

| Round-6 finding | REV 6.1 disposition |
|---|---|
| M6-1 — third e2e actor held `services.update` while Edit/Delete had to be absent | **CLOSED.** The actor now holds exactly `services.view` and `services.create`; `services.create` is retained to reach `/services/new`, while `services.update` is explicitly prohibited. The ServiceDetail assertions now match the guards at the lane lines. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7026,7046`; `lane/w-lot-a-1a:apps/web/src/features/services/ServiceDetailPage.tsx:168-182` |
| M6-2 — service DELETE expected 200 instead of 204 | **CLOSED.** The manager e2e arm and Probe B both use 204, with the no-body warning. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7027,8683`; `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Controllers/ServiceController.php:168-193` |
| M6-3 — cross-company service update expected 403 instead of scoped 404 | **CLOSED for the executable PHPUnit recipe, but NOT CLOSED editorially.** The test now asserts unchanged data, 404 and `SERVICE_NOT_FOUND`; however, Task 13’s opening summary still says “not merely that the response was 403.” `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7624,7881-7890,7914-7937`; `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Controllers/ServiceController.php:128-149` |
| Minor 1 — six non-admin templates / nine guard cases | **CLOSED.** Six non-admin templates, seven affected templates including admin, seven data rows where manager is repeated, and nine named cases are consistently distinguished. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6916-6936,4484-4501,9106` |
| Minor 2 — parser 5c omitted refusal branches | **CLOSED.** Case 5c enumerates all seven parser branches, both malformed shapes, both empty-role shapes, exact messages and one error per refusal; the retired flag is separately identified as non-parser validation. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2897-2968,4491-4499,9107` |
| Minor 3 — stale e2e and signature summaries | **CLOSED.** The File Structure summary now reflects manager category access, and the introduction correctly distinguishes internal stopgap signature changes from contracts inherited by wave 1. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3,113,9108,9331-9342` |
| Minor 4 — stale displayed `dev` tip | **CLOSED editorially.** The citation pin remains `630afa86f`, while current `dev` `33796cc08` is recorded and its intervening documentation-only changes explained. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:37,43,9109-9110` |
| Round-6 citation audit | **CLOSED and still current.** The four branch tips and both principal diffstats remain unchanged since r6. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:37-43` |

The rev 6→6.1 change log accurately describes the three principal executable repairs: actor permissions, service DELETE 204, and scoped service 404. Its claim that the subsequent status-code pass checked every relevant assertion and both probes is not fully accurate; see M7-1 and minors 1–2. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9097-9146`

## Rev-1 closure table

| Round-1 finding | REV 6.1 disposition |
|---|---|
| B0b-1 — admin v0 omitted all nineteen additions | **CLOSED at rev-2 anchor and preserved.** Admin’s adoption oracle contains all nineteen additions and remains separate from the deploy grant map. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:60-61,577-673,9299-9302` |
| B0b-2 — knowingly red Task 1 commit | **CLOSED at rev-2 anchor.** Catalogue coverage ships with Task 6, and the plan prohibits knowingly red commits. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:62,6982-6985,9302` |
| B0b-3 — file-level writer test did not prove coverage | **CLOSED.** The method-level AST census retains the exact 20-method `dev` and 23-method W-LOT fixtures, three classifications, acquire-before-write checks, and tree-wide inherited-caller proof. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3290-3306,4106-4318,9303` |
| B0b-4 — frontend callers could knowingly issue 403s | **CLOSED.** The scripted census covers 87 rows in 17 files, including AddCompanyModal, ServiceListPage and the transitive category callers; all rows are dispositioned. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4924-4926,5274-5415,5647-5806,9304` |
| B0b-4b — wrong service-category API path | **CLOSED.** `/service-categories` is canonical; category and service updates use PATCH. SPA `/services/categories` links remain intentionally unchanged. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5604-5621,5785-5806,9305` |
| B0b-5 — missing admin could report success | **CLOSED.** Apply and dry-run produce `FAILED reason=admin_role_missing`, roll back and exit nonzero. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2192,2394-2405,2471-2508,9306` |
| B0b-6 — no atomic success condition for the two deploy invocations | **CLOSED.** The wrapper writes `pending`, atomically records either failed half and writes `ok` only after both invocations succeed. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:255,8969-9064,9307` |
| M0b-1 — fleet helpers/provider incomplete | **CLOSED.** Helpers, migrations-behind coverage and the extensible command provider are supplied. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1447-2179,9308` |
| M0b-2 — test-first sequencing | **REJECTED-correctly for wholesale task resequencing.** The residual execution-discipline note is adequate: author and observe each test before its implementation and record branch-removal reds honestly. No task resequencing is required. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:62,9246-9247,9309,9327` |
| M0b-3 — placeholders and prose-only implementations | **CLOSED.** The original controller, frontend and convention-09 stubs have concrete bodies or exact bounded instructions. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2992-3228,5857-6845,7630-8246,9310` |
| M0b-4 — inexact staging and commit subjects | **CLOSED.** Task-local staging is explicit and production commits use `Phase 0.2.<task>:` subjects. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:72-73,7069-7097,9311` |
| M0b-5 — wrong PG/static-analysis harness | **CLOSED.** PostgreSQL commands use `-c phpunit-pgsql.xml`, and final analysis scopes cover the touched modules and tests. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:66-71,4511-4530,8788-8815,9312` |
| Four round-1 minors | **CLOSED.** Start-universe wording, marker mode, create-only semantics and generic failure construction remain corrected. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2260-2283,2494-2508,9313-9316` |
| Round-1 0b citation rows | **CLOSED.** The plan distinguishes historical citation pins from current tips and requires re-derivation after both lane merges. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:33-45,296-311,9317-9321` |
| Round-1 cross-plan contracts | **CLOSED.** The detailed signature section correctly records that wave 1 inherits no changed production API, while retaining the three re-pin lessons. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9329-9375` |

The rev 1→rev 2 change log remains faithful to the inspected code. Its original seven-key `--grant-to` deployment shape is historical and is explicitly superseded by rev 6’s per-key `--grant` map; admin’s all-nineteen oracle and the other rev-2 closures remain intact. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:27,9295-9327`

## BLOCKER

None.

## MAJOR

### M7-1 — the stock-adjustment location-scope PHPUnit test accepts 422 even though this exact lane path contractually returns 403

`StockAdjustmentLocationScopePermissionTest` accepts either 403 or 422. Its fixture deliberately supplies a valid payload and changes only the membership’s allowed-location restriction; the positive half proves that the identical payload returns 201 once that restriction is removed. `StoreStockAdjustmentRequest::failedValidation()` specifically translates the sole `ValidLocationAccess` failure into HTTP 403 with `error.code=LOCATION_ACCESS_DENIED`.

As written, the test would stay green if that translation regressed and Laravel returned an ordinary 422 validation response. The row-absence assertion would also remain green, so the complete test would accept the broken API contract.

- Broad assertion and identical-payload positive half: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8121-8138`
- Fixture establishes a valid request whose only failing lever is the location restriction: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8195-8244`
- Lane’s exact translation to 403: `lane/w-lot-a-1a:apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockAdjustmentRequest.php:55-70,83-90`
- Successful create is 201: `lane/w-lot-a-1a:apps/api/app/Modules/Inventory/Presentation/Controllers/StockAdjustmentController.php:148-174`
- Rev 6.1 incorrectly calls `[403,422]` correct “depending on which lever is in play,” although this test fixes the lever: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9143-9144`

Required correction: replace the set assertion with `assertForbidden()` and assert `error.code = LOCATION_ACCESS_DENIED`. Retain both row assertions and the identical-payload 201 positive half.

## MINOR

1. Task 13’s summary retains the obsolete “response was 403” wording even though the supplied class correctly pins scoped 404 plus `SERVICE_NOT_FOUND`. Change the summary to “not merely that the response was 404” or, better, name the scoped-not-found contract explicitly. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7624,7881-7890,7936-7937`

2. Probe B still groups `GET/POST/PATCH /service-categories*` under 200. POST returns 201. The e2e contract is already correct, so this is an editorial propagation miss in the manual probe: spell the row as GET 200 / POST 201 / PATCH 200. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6991,6998,7027,8684`; `lane/w-lot-a-1a:apps/api/app/Modules/Service/Presentation/Controllers/ServiceCategoryController.php:111-126,132-166`

## Citation audit

Current refs, re-measured:

| Ref | Current tip | Ancestor of `dev`? | Current diff from `dev` |
|---|---:|:---:|---:|
| `dev` | `33796cc08` | — | — |
| `lane/w-lot-a-1a` | `a7010fe4d` | No | 83 files, +4,758/−663 |
| `lane/t2-receipt-spine` | `208449350` | No | 104 files, +13,412/−582 |
| `lane/rbac-w0a` | `ed88aa2ed` | No | 2 files, +117/−2 |

These match the plan’s current-tip paragraph and r6 register. W-LOT’s post-pin commit remains documentation-only. T2 still overlaps exactly seven wave paths: Inventory routes, `RequireAnyPermission.php`, CI, the seeder, feature-lane manifest, generated permission map and glossary. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:37-43,261-289`

Therefore the plan is not authorized to start execution: W-LOT, T2 and wave 0a remain unmerged. That is an execution prerequisite, not a plan defect.

### Status assertion spot-check

More than ten operative assertions were checked directly against the current W-LOT lane:

| Plan expectation | Result | Lane anchor |
|---|---|---|
| `GET /services` → 200 | Correct | `apps/api/app/Modules/Service/Presentation/Controllers/ServiceController.php:27-65` |
| `POST /services` → 201 | Correct | `…/ServiceController.php:107-122` |
| same-company `PATCH /services/{id}` → 200 | Correct | `…/ServiceController.php:128-162` |
| cross-company `PATCH /services/{id}` → 404 `SERVICE_NOT_FOUND` | Correct | `…/ServiceController.php:132-148` |
| `DELETE /services/{id}` → 204 | Correct | `…/ServiceController.php:168-193` |
| `GET /service-categories` → 200 | Correct | `…/ServiceCategoryController.php:27-51` |
| `POST /service-categories` → 201 | Correct; Probe B prose remains stale | `…/ServiceCategoryController.php:111-126` |
| `PATCH /service-categories/{id}` → 200 | Correct | `…/ServiceCategoryController.php:132-166` |
| `DELETE /service-categories/{id}` → 204 | Correct | `…/ServiceCategoryController.php:172-226` |
| `GET /categories/tree` → 200 | Correct | `apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php:59-75` |
| `POST /categories` → 201 | Correct | `…/CategoryController.php:115-185` |
| `PUT /categories/{id}` → 200 | Correct | `…/CategoryController.php:188-244` |
| `DELETE /categories/{id}` → 204 | Correct | `…/CategoryController.php:247-271` |
| `GET /channels` → 200 | Correct | `apps/api/app/Modules/Channel/Presentation/Controllers/ChannelController.php:24-33` |
| `POST /companies` → 201 | Correct | `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:208-214` |
| credit-note cancel happy path → 200 | Correct for the supplied draft fixture | `apps/api/app/Modules/Document/Presentation/Controllers/RefundController.php:231-248`; plan `:4698-4712` |
| batch delete → 200 | Correct | `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:277-289` |
| stock-adjustment create → 201 | Correct | `apps/api/app/Modules/Inventory/Presentation/Controllers/StockAdjustmentController.php:148-174` |
| location-restricted stock adjustment → `[403,422]` | **Too broad; exact contract is 403** | `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockAdjustmentRequest.php:55-70` |
| progression profile → issued and one of 200/404/503, never 403 | Correct for an authorized actor | `apps/api/app/Modules/Progression/Presentation/Controllers/CompanyProgressionController.php:25-47` |

The e2e service/category/company assertions are otherwise aligned with the lane controllers. The Vitest recipes assert request counts and verbs rather than HTTP status codes, so there is no additional Vitest status mismatch.

### Third actor

The third actor’s provisioning now matches every assertion referencing it:

- `services.view` permits `/services`, its list query and ServiceDetail loading.
- `services.create` permits `/services/new`, which is necessary to exercise ServiceForm’s category query.
- Absence of `services.update` makes ServiceDetail Edit/Delete correctly absent.
- Absence of all `service-categories.*` keys makes the nav, in-page link, category page and all three category queries silent.

`docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7026,7046,7052`; `lane/w-lot-a-1a:apps/web/src/routes/index.tsx:1573-1634`; `lane/w-lot-a-1a:apps/web/src/features/services/ServiceDetailPage.tsx:51-70,168-182`

### E2E capture and UX coverage

All five actors retain per-arm response and console listeners installed before navigation, with zero 5xx and zero console-error assertions. Positive and negative coverage exists for Channels, category read/mutations, service-category callers, ServiceDetail controls, company creation, progression controls, CommandPalette, InventoryHub and the product-form picker. AddCompanyModal and the two ServicePicker mount sites are explicitly assigned to component tests for stated reachability reasons. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6991-7052,8815`

### Signature and re-pin audit

The section is accurate in the operative sense:

- Wave 0b does change the internal stopgap runner parameter and both ensure CLIs.
- Wave 1 inherits none of those changed production contracts because it deletes those stopgaps.
- The three notes remain correct: copy marker-mode semantics, reuse the AST census with list-valued tree-wide inheritance proof, and delete the wrapper together with the ensure machinery.

`docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9331-9342,9344-9369,9371-9375`

## Rejected false positives

- The third actor does not need `services.update`; retaining `services.create` is necessary to reach the new-service form and its guarded category query. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7026`
- The service DELETE contract is consistently 204 in the executable e2e and operative Probe B service row. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:7027,8683`
- `[200,404,503]` for progression profile is not an authorization loophole: the assertion also requires the request to be issued and excludes 403, which is the property `progression.view` controls. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6994`
- `/services/categories` remains a valid SPA route while `/service-categories` is the canonical API route; leaving the SPA link unchanged is correct. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:5604-5621,5806`
- No new missing or extra PermissionWriterCensus fixture row is implied: the current `dev` and W-LOT tips are unchanged from r6’s exact 20/23 enumeration, and W-LOT’s intervening commit remains documentation-only. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:38,4106-4318`
- `LOCK_INHERITED_FROM` is a proof, not a waiver: the plan asserts complete tree-wide callers plus acquire-before-call for every declared entry point. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:4283-4318,9344-9369`
- M0b-2 does not require wholesale task resequencing; the execution-discipline note is sufficient. `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:9246-9247,9327`

## Preserve

Preserve:

- admin’s all-nineteen version-0 oracle;
- the 8 admin-only / 10 manager-only / 1 shared key split;
- six affected non-admin templates and nine guard cases;
- all-seven-branch grant parser coverage;
- the 20/23 method-level AST writer census;
- tree-wide, list-valued `LOCK_INHERITED_FROM`;
- `/service-categories` canonicalization and both PUT→PATCH corrections;
- the five-actor e2e structure and per-arm 5xx/console capture;
- the third actor’s exact `services.view` + `services.create` provisioning;
- service DELETE 204 everywhere;
- scoped service 404 with `SERVICE_NOT_FOUND`;
- progression’s issued-and-not-403 assertion over `[200,404,503]`;
- missing-admin failure on apply and dry-run;
- wrapper `pending` / `failed:<half>` / `ok` atomicity;
- all three merge prerequisites;
- the M0b-2 execution-discipline note without task resequencing;
- the three wave-1 re-pin notes.

## Owner decisions required

None. The required repairs are mechanical:

1. Tighten the stock-adjustment denial to 403 plus `LOCATION_ACCESS_DENIED`.
2. Replace Task 13’s stale 403 summary with scoped 404 wording.
3. Correct Probe B’s service-category row to GET 200 / POST 201 / PATCH 200.
4. Amend the rev-6.1 status-pass summary so it no longer claims `[403,422]` is correct for this fixed test lever.

## Dispatch assessment

REV 6.1 has **0 BLOCKER / 1 MAJOR / 2 MINOR**.

The three r6 executable assertion failures are repaired, the third actor is coherent, service DELETE is consistently 204, cross-company service update correctly pins scoped 404, the five e2e arms retain their capture obligations, and current lane measurements still match the plan.

Dispatch remains stopped by one substantive assertion gap: the location-scope PHPUnit test accepts a generic 422 even though its deliberately isolated lane contract is 403 `LOCATION_ACCESS_DENIED`. That permits a real API-contract regression to pass both its status and row assertions.

Independently, execution cannot start until `lane/w-lot-a-1a`, `lane/t2-receipt-spine`, and wave 0a are merged into `dev`.

VERDICT: CHANGES-REQUIRED