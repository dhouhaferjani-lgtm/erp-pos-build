I couldn't write the verdict file (permission not granted), so here it is inline.

GATE VERDICT: APPROVE

## R1 findings — both closed

**1. (Important, R1) No cross-company denial test — CLOSED**

`tests/Feature/Treasury/OutboundInstrumentEndpointsTest.php:135-185` adds `test_all_outbound_actions_hide_another_company_instrument`. The fixture is a real second company under the **same tenant** (`:137`) — correctly a company-boundary test, not a tenant one — fully hydrated: chart seeded `:138`, partner `:139-142`, bank repository whose `gl_account_id` is resolved via `Account::findByPurposeOrFail($otherCompany->id, …Bank)` `:143-149` (not borrowed from company 1), method `:150-155`, instrument `company_id => $otherCompany->id`, `Outbound`, `Received` `:156-171`.

The negative test is **not vacuous**: `:82-97` proves `Received` + `Outbound` is a state where `clear-outbound` succeeds, so absent scoping the loop at `:173-182` would mutate. The four `assertNotFound()` calls therefore isolate exactly the scope check.

404 rather than 403 is meaningful because admin holds every permission — `RolesAndPermissionsSeeder.php:452-453` `$admin->syncPermissions(Permission::all())` — so `can:` passes and the 404 can only come from the company predicate at `PaymentInstrumentController.php:446-457` (`where tenant_id`/`where company_id` + `findOrFail`). Request-time company resolves through the real path: `CompanyContextMiddleware.php:110-111` default-company lookup off the sole `UserCompanyMembership` (`:211-215`), not the `setUp()` container write at `:62`.

**2. (Important, R1) Mutation-survival proof — CLOSED**

`:184` `assertSame(InstrumentStatus::Received, $instrument->fresh()?->status)`. The nullsafe cannot mask anything — a null `fresh()` makes it `assertSame(enum, null)` and fails. Structurally reinforced: `findInstrument()` is the first statement in all four actions (`:358`, `:379`, `:399`, `:417`), so the abort precedes any service call, event, or JE.

**3. (Minor, R1) Manager 403 — CLOSED**

`:117-133` now covers clear-outbound `:121`, bounce-outbound `:124`, represent `:127`, cancel-outbound `:130`. The 403s are real: manager's grant list (`RolesAndPermissionsSeeder.php:458-549`) has `instruments.clear/bounce/remit/cancel` but neither `-outbound` permission; accountant has both (`:694`, asserted `:108-109`).

## No weakening

`t5a-gate-2..HEAD` over the four boundary files is 350 insertions / **1 deletion** — the accountant permission line, re-added verbatim plus the two new permissions (`RolesAndPermissionsSeeder.php:706`). No permission broadened, no middleware relaxed, no assertion removed.

## Original boundary re-confirmed

Routes `routes.php:145-159` each carry an explicit `can:` (clear-outbound gates clear/bounce/represent; cancel-outbound gates cancel — deliberate lifecycle grouping, unchanged since gate-2). UUID guard `PaymentInstrumentController.php:448` (404 not 500, pinned `:200-205`). `X-Company-Id` is membership-validated at `CompanyContextMiddleware.php:67-74` → 403, so the header is not an escape hatch.

## Non-blocking

- **N1** Cross-company test asserts only `status`; side-effect absence (events, `repository.balance`) is inferred from the early abort rather than asserted. Optional.
- **N2** `app(ChartOfAccountsService::class)` `:138` mirrors the `setUp()` idiom `:56`; Rule 13 targets production code. No action.
- **N3** No both-company-membership case; default-company path is the realistic vector and is covered. Optional.

## Verification caveat

My own `./vendor/bin/phpunit …/OutboundInstrumentEndpointsTest.php` run was **denied by the permission layer** — I did not independently reproduce it. This approval rests on static verification plus your supplied evidence (SQLite 6/23, PG isolated 6/23, PHPStan L8 clean, Pint pass), which is consistent with a 6-test file. Full suite not run.

VERDICT: spec ✅ + quality APPROVED

Remaining before Wave 4: nothing on the tenancy/authz axis — proceed once the treasury-axis verdict is likewise clear.
