# Gate record — R2-H route gating + FE list contract

**Date:** 2026-08-07
**Reviewer:** tenancy-authz-reviewer (adversarial, code-grounded)
**Branch:** `fix/r2h-withholding` — worktree `/Users/houssamr/Projects/syneriva/apps/erp.fix-r2h-withholding`
**Commits under gate:** `550f626f8` (FE list-contract fix), `b41ab525c` (route gating + seeder)
**Diff base:** `a1952aa23`. Sibling `8b4556ef9` (zero-rate refusal) gated separately — its files
(`WithholdingCertificateService.php`, `WithholdingZeroRateGuardTest.php`, `WithholdingCertificateTest.php`,
`PaymentTest.php`) were only used here as regression controls.
**Spec:** `docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md` §106-174 (#3) + §178-220 (#4)

## VERDICT

**spec ✅ / quality ❌ CHANGES-REQUESTED**

Both ticket defects are genuinely fixed and the route→permission matrix is correct and complete
(independently re-derived at runtime, below). Three things block merge: a CI-red stale generated
permission map, an authorization test suite that cannot run on PostgreSQL, and a deploy note that
prescribes a remediation which cannot work.

---

## What I verified myself (not taken on trust)

### Route matrix — runtime middleware dump, all 16 routes

Derived by walking `app('router')->getRoutes()` and filtering `can:` out of `gatherMiddleware()`
(booted against live PG, worktree code). Matches the commit body's table byte-for-byte:

| method | uri | gate |
|---|---|---|
| GET | `api/v1/withholding/certificates` | `can:withholding.view` |
| GET | `api/v1/withholding/certificates/export-tej-batch` | `can:withholding.view` |
| GET | `api/v1/withholding/certificates/{id}` | `can:withholding.view` |
| POST | `api/v1/withholding/certificates` | `can:withholding.create` |
| POST | `api/v1/withholding/certificates/{id}/issue` | `can:withholding.update` |
| POST | `api/v1/withholding/certificates/{id}/void` | `can:withholding.update` |
| POST | `api/v1/withholding/certificates/{id}/submit-tej` | `can:withholding.update` |
| GET | `api/v1/withholding/certificates/{id}/download-pdf` | `can:withholding.view` |
| GET | `api/v1/withholding/certificates/{id}/download-tej-xml` | `can:withholding.view` |
| DELETE | `api/v1/withholding/certificates/{id}` | `can:withholding.delete` |
| GET | `api/v1/withholding/rules` | `can:taxation.withholding_rules.manage` |
| GET | `api/v1/withholding/rules/{id}` | `can:taxation.withholding_rules.manage` |
| POST | `api/v1/withholding/rules` | `can:taxation.withholding_rules.manage` |
| PATCH | `api/v1/withholding/rules/{id}` | `can:taxation.withholding_rules.manage` |
| POST | `api/v1/withholding/rules/{id}/deactivate` | `can:taxation.withholding_rules.manage` |
| DELETE | `api/v1/withholding/rules/{id}` | `can:taxation.withholding_rules.manage` |

- **No route left ungated in either group.** `deactivate` + `destroy` (the two that had ZERO
  authorization per ticket §153-174) are covered by the group-level middleware at
  `apps/api/app/Modules/Taxation/routes.php:43`.
- **Rule-12 stack intact:** `apps/api/app/Modules/Taxation/routes.php:16` —
  `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`.
- **Permission strings byte-match the seeded catalog:** `withholding.view/create/update/delete` at
  `apps/api/database/seeders/RolesAndPermissionsSeeder.php:372-375`;
  `taxation.withholding_rules.manage` at `:389`. No silent-403-by-typo.
- **Route ordering preserved:** `/export-tej-batch` (`routes.php:64`) still registers before
  `/{id}` (`routes.php:66`), so it is not swallowed by the wildcard.
- **403 precedes validation** — confirmed dynamically: `test_cashier_is_refused_creating_a_certificate_before_validation_runs`
  (empty payload) and `test_cashier_is_refused_voiding_a_certificate_before_validation_runs`
  (`reason: 'short'`, below `min:10`) both return 403 not 422 under the default (sqlite) config.
  Mechanism is engine-independent — route middleware resolves before the FormRequest.

### Seeder / grant blast radius — no unintended widening

Exact grant diff is **two lines**: the catalog entry (`RolesAndPermissionsSeeder.php:389`) and the
accountant grant (`:781`). Nothing else in `rolePermissionGrants()` moved.

Certificate permission holders are UNCHANGED by this diff (verified against the committed
`apps/web/src/hooks/permissionsMap.generated.ts:269-272`, which reflects pre-diff state):
`withholding.view` → accountant/admin/manager; `.create` → accountant/admin; `.update` →
accountant/admin; `.delete` → **admin only**. Manager's view-only grant is pre-existing
(`RolesAndPermissionsSeeder.php:589`).

`taxation.withholding_rules.manage` → admin (via `Permission::all()` at `:504`) + accountant only.
Confirmed green on live PG: `RolesAndPermissionsWithholdingRulesManageGrantTest` (3 tests) asserts
seeded + admin/accountant true + viewer/manager/cashier false.

### `error.ability` claim — correct

`apps/api/bootstrap/app.php:204-215`: the handler recovers `ability` only from
`PermissionDeniedException` via `getPrevious()`; route-level `can:` throws a plain
`AuthorizationException` → `AccessDeniedHttpException` with no such previous, so `ability` is
`null` by design and `error.code` is `'FORBIDDEN'`. The tests correctly pin `error.code`.

### FE contract fix — correct against the real response shape

`apps/api/app/Modules/Taxation/Presentation/Controllers/WithholdingCertificateController.php:62-71`
emits a **top-level** `{data, meta: {per_page, has_more}, links: {next, prev}}`. `apiGet` unwraps
`response.data.data` (`apps/web/src/lib/api.ts:269-272`), so the pre-fix call resolved to the bare
array. `api.get` + `return response.data` (`apps/web/src/features/withholding/api/withholdingApi.ts:58-63`)
is the documented paginated pattern and matches the envelope exactly. The component was already
correct: `apps/web/src/features/withholding/WithholdingCertificatesList.tsx:80` reads
`data?.data ?? []`.

**Sibling functions correctly left on `apiGet`** (I checked for a half-fix): `fetchWithholdingRules`
(`withholdingApi.ts:166`) and `fetchSalesWithholdingTracking` (`:221`) hit endpoints that return
`{data}` only — `WithholdingTaxRuleController.php:53-55`,
`SalesWithholdingTrackingController.php:96-98`. No residual double-unwrap in this file.

**tenantScopedKey discipline intact:** all four withholding `useQuery` keys use it —
`apps/web/src/features/withholding/hooks/useWithholding.ts:52, 66, 209, 223`. The raw
`['withholding-certificate', id]` invalidations at `:110/:137/:164` are prefix matches against the
tenant-suffixed keys, so they still hit.

### Regression sweep — the highest-risk vector is clean

The cashier payment path is **not** broken by the new gates: `apps/web/src/features/treasury/PaymentForm.tsx:559`
uses `useWithholdingPreview` → `POST /withholding/preview`, which is outside both gated groups
(`routes.php:35`). No FE code calls `createWithholdingCertificate` outside the withholding feature;
certificates are minted server-side by the payment flow.

### Commands run

| command | result |
|---|---|
| `vitest run withholdingApi.test.ts WithholdingCertificatesList.test.tsx` | **5 passed** |
| `tsc --noEmit -p tsconfig.json` (apps/web) | **clean** |
| `eslint` on the two changed FE files | **clean** |
| `pint --test` on the 4 changed/added PHP files | **pass** |
| `phpstan analyse app/Modules/Taxation database/seeders/RolesAndPermissionsSeeder.php` | **[OK] No errors** |
| `phpunit RolesAndPermissionsWithholdingRulesManageGrantTest` (live PG) | **OK (3 tests, 6 assertions)** |
| `phpunit WithholdingRouteAuthorizationTest` (default sqlite) | **OK (20 tests, 55 assertions)** |
| `phpunit WithholdingRouteAuthorizationTest` (**live PG**) | **20 ERRORS, 0 assertions** — F2 |
| `phpunit TaxationTenantIsolationTest WithholdingZeroRateGuardTest` (live PG) | 26 tests, 2 errors (both **pre-existing**, same PG cause as F2) — no regression from the new gates |
| `phpunit ExportFrontendPermissionsMapCommandTest` | **1 FAILURE** — F1 |

Live PG: `127.0.0.1:5433`, isolated database `autoerp_r2h_gate_test` (created + dropped for this gate).

---

## Findings

### [IMPORTANT] F1 — `permissionsMap.generated.ts` was not regenerated; CI and preflight are red

`apps/web/src/hooks/permissionsMap.generated.ts:3` still carries
`// Source hash: sha256:2578583cb8d8ca55e7c3f07e2e84eca751caee49d76060f67a81dc19a42f9649`, the
pre-change hash, and the map has no `taxation.withholding_rules.manage` entry. I re-ran the
generator's exact `render()` algorithm (`apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:49-91`)
over the new seeder and diffed:

```
3c3
< // Source hash: sha256:08ebc97307842c08d45e9858da382150261a9f6d7f9d930426798343f5b64788
---
> // Source hash: sha256:2578583cb8d8ca55e7c3f07e2e84eca751caee49d76060f67a81dc19a42f9649
249d248
<   'taxation.withholding_rules.manage': ['accountant', 'admin'],
```

**Why it matters.** Three guards fail on this exact condition:
`apps/api/tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php:15-22`
(`test_the_committed_frontend_map_is_fresh_against_the_seeder` — I ran it, it FAILS),
`.github/workflows/ci.yml:1019-1030`, and `scripts/preflight.sh:147-152`. The commit body's
"Quality: … PHPStan level 8 clean; Pint clean" does not cover this and the map regen is not
mentioned anywhere in either commit. Secondary consequence: `apps/web/src/hooks/usePermissions.ts:1`
types `Permission = keyof typeof PERMISSIONS`, so a future
`RequirePermission permission="taxation.withholding_rules.manage"` will not typecheck until the map
is regenerated — i.e. F1 currently blocks the FE half of rule-12 for the rules group (see F5).

**Fix.** `cd apps/api && php artisan permissions:export-frontend-map`, commit
`apps/web/src/hooks/permissionsMap.generated.ts`.

### [IMPORTANT] F2 — the new authorization test errors 20/20 on PostgreSQL; the security evidence is SQLite-only

`apps/api/tests/Feature/Taxation/WithholdingRouteAuthorizationTest.php:123` seeds the rule fixture
with `'rate' => '10.00'`. That column is `decimal(5, 4)`
(`apps/api/database/migrations/tenant/2026_01_08_172123_create_withholding_tax_rules_table.php:32`),
and the rate is a **fraction**, not a percentage — `CreateWithholdingRuleRequest.php:32` validates
`'min:0', 'max:1'`, and every production seeder uses fractions
(`database/seeders/TunisiaWithholdingRulesSeeder.php:44` → `0.1000`;
`database/seeders/TunisianParapharmacySeeder.php:233` → `'0.100'`).

On live PostgreSQL every one of the 20 tests errors in `setUp()`:

```
PDOException: SQLSTATE[22003]: Numeric value out of range: 7 ERROR:  numeric field overflow
DETAIL:  A field with precision 5, scale 4 must round to an absolute value less than 10^1.
  ...WithholdingRouteAuthorizationTest.php:117
Tests: 20, Assertions: 0, Errors: 20.
```

Under `phpunit.xml` (which pins `DB_CONNECTION=sqlite`, `:memory:` at `apps/api/phpunit.xml:41-42`)
the same file is `OK (20 tests, 55 assertions)` — SQLite does not enforce decimal precision.

**Why it matters.** This is a privilege-escalation fix whose *entire* automated proof is void on the
production database engine. The repo's main `backend-test` CI job runs sqlite
(`.github/workflows/ci.yml:268-270`), so this goes green in CI while being unrunnable in prod-shaped
conditions — precisely the class of defect the immediately preceding commit `844933ed6`
("fix two live-PostgreSQL-only bugs in PaymentAllocationDocumentStateTest") was closing. The
follow-on assertion `:346` `assertSame('10.0000', $this->rule->fresh()?->rate, …)` also encodes the
wrong semantic and must change with the fixture.

Mitigation for this gate: I re-derived the route matrix at runtime (table above), so the *fix* is
verified independent of the test. It is the *test* that is broken.

Note a pre-existing sibling of the same bug, NOT introduced here:
`apps/api/tests/Feature/Taxation/TaxationTenantIsolationTest.php:596` and the adjacent
`test_update_company_specific_withholding_rule_rejects_cross_tenant` error identically on PG
(2 errors of 26 tests). This diff adds 20 more instances of the pattern.

**Fix.** Use a legal fraction (e.g. `'0.1000'`) at `:123` and update the `:346` expectation to
`'0.1000'`; then re-run the file against PostgreSQL, not just sqlite.

### [IMPORTANT] F3 — the deploy note prescribes a step that cannot fix the problem it describes

Commit `b41ab525c` body: *"Every existing tenant needs `php artisan tenants:run permission:cache-reset`
after the next `tenants:migrate`/reseed cycle."*

`permission:cache-reset` flushes a cache; it cannot **create** a permission row that does not exist
in the tenant database. And `tenants:migrate` does not seed permissions — the deploy path is
`apps/api/docker/entrypoint.sh:141` (`tenants:migrate-rolling`, DDL only). The only step that
creates the new permission on already-provisioned tenants is the **opt-in** branch at
`apps/api/docker/entrypoint.sh:152-161`:

```
if [ "$SYNC_PERMISSIONS_ON_BOOT" = "true" ]; then
    ... php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'
```

followed by the unconditional `php artisan permission:cache-reset` at `:168`.

**Why it matters.** Pre-fix the rules group was ungated, so it worked for everyone. Post-fix, on any
tenant that is not reseeded, **every** user — admin included — gets 403 on all six rules routes.
That is the silent-403 trap this reviewer exists to catch, and the note as written would leave an
operator flushing a cache and wondering why admin still 403s.

**Mitigating (verified):** there is no UI consumer today —
`apps/web/src/features/withholding/pages/WithholdingRulesPage.tsx:18` is neither exported from the
feature barrel nor routed anywhere in `apps/web/src/routes/index.tsx` (the only withholding routes
there are `withholding-certificates`, `withholding-certificates/:id`, `sales-withholding-tracking`
at `:1862-1890`). So the regression is API-only today. **Cannot verify** whether
`SYNC_PERMISSIONS_ON_BOOT=true` is set on staging/prod — that env is not in the repo.

**Fix.** Replace the note with the ordered, sufficient step: confirm `SYNC_PERMISSIONS_ON_BOOT=true`
on the target environment (or run
`php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'` manually)
**before** `permission:cache-reset`, and add it to the launch-program deploy checklist alongside the
already-stacked treasury permission reseeds.

### [MINOR] F4 — "dead PermissionSeeder never called" is imprecise

Both the commit body and `RolesAndPermissionsWithholdingRulesManageGrantTest.php:21-23` say
`PermissionSeeder` is "never called by `DatabaseSeeder`". It is called — by
`apps/api/database/seeders/ProductionSeeder.php:68`. The substantive claim is nonetheless correct:
tenant databases are provisioned via `RolesAndPermissionsSeeder` only
(`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:178`), so the
permission never existed on a tenant DB. Reword to "never run against tenant databases" so the next
reader does not chase a false lead.

### [MINOR] F5 — the rules group satisfies rule-12 vacuously, not actually

Rule 12 requires gating on both layers. The backend layer is now correct; there is no frontend
layer at all, because `WithholdingRulesPage` is unrouted (see F3). Record the follow-up explicitly:
when that page is routed it must carry
`RequirePermission permission="taxation.withholding_rules.manage"` — which requires F1's regen
first, or the prop will not typecheck.

### [MINOR] F6 — no positive control on the `destroy` / `submit-tej` / `export-tej-batch` gates

`WithholdingRouteAuthorizationTest` proves the allow path only for `index` (manager/accountant) and
`issue` (accountant). Every assertion about `DELETE /{id}` is a deny assertion. If the `destroy`
guard string were typo'd (`can:withholding.destroy`), every role including admin would 403 and all
20 tests would still pass. I closed this gap by hand (runtime middleware dump + byte-match against
the seeder catalog), but the suite does not. Add one admin/accountant allow-path probe per distinct
permission — cheapest is an admin `DELETE` on a draft returning 2xx.

### [MINOR / pre-existing, out of diff scope] F7 — no `Str::isUuid()` guard before uuid-column lookups

`apps/api/app/Modules/Taxation/Presentation/Controllers/WithholdingCertificateController.php:355-362`
(`findOrFail($id)`) and the rules controller's `requireCompanyScopedRule` / `loadReadableRule` bind
the raw `{id}` path segment into a `uuid` primary key (migration `:15`; verified in PG as
`id | uuid | not null`). A non-UUID segment 500s on PostgreSQL rather than 404ing. Not introduced by
this diff, and the new gates narrow reachability to permission-holders. Ticket it.

### Observation (not a finding) — `POST /withholding/preview` remains ungated

`apps/api/app/Modules/Taxation/routes.php:35` carries no `can:`, so any authenticated tenant user
can probe a partner's suggested withholding rate
(`WithholdingPreviewController::preview` — tenant/company-scoped, so no cross-tenant leak). Outside
the ticket's stated scope (§106-174 names the certificates and rules groups only), and it is the
endpoint the cashier-facing `PaymentForm.tsx:559` depends on, so gating it is a payments-side
permission decision rather than a fix to fold into this lane.

---

## What to fix before merge

Regenerate and commit `permissionsMap.generated.ts` (F1 — CI is red), change the rule fixture at
`WithholdingRouteAuthorizationTest.php:123` to a legal `decimal(5,4)` fraction and re-run the file
against PostgreSQL (F2), and rewrite the deploy note to name
`tenants:seed --class=RolesAndPermissionsSeeder` (or `SYNC_PERMISSIONS_ON_BOOT=true`) as the
required step before `permission:cache-reset` (F3).

---

# Fix-round re-verify — commit `ae7d147e1`

**Date:** 2026-08-07 (same day)
**Scope:** narrow — ONLY the findings raised above, plus a spot-check of the two zero-rate gate
minors closed in the same commit. No re-review of anything already verified in round 1.
**Disposable PG:** `autoerp_r2h_gate2` on `127.0.0.1:5433`, created and dropped for this round.

## REVISED VERDICT: spec ✅ + quality ✅ — **CLEAR TO MERGE**

All three IMPORTANT blockers are closed, verified by re-derivation and by reruns I executed myself
rather than by reading the commit's claims.

### F1 — CLOSED. Permission map regenerated; independently re-derived

I re-ran the generator's `render()` algorithm (`ExportFrontendPermissionsMap.php:49-91`) over the
current seeder into a scratch file and diffed it against the committed map: **byte-identical, zero
differences.** `apps/web/src/hooks/permissionsMap.generated.ts:3` now carries
`sha256:08ebc97307842c08d45e9858da382150261a9f6d7f9d930426798343f5b64788` — exactly the hash this
gate record predicted in round 1 — and `:249` carries
`'taxation.withholding_rules.manage': ['accountant', 'admin']`. The committed diff is those two
lines and nothing else, so no unrelated grant drifted in under cover of the regen.

`ExportFrontendPermissionsMapCommandTest` **3/3 green** on live PG (previously 1 failure). The
idempotency claim is implied by my re-derivation producing a zero diff — a second regen cannot
change a file the generator already reproduces exactly.

### F2 — CLOSED. Fixture is a legal fraction; suite is green on live PostgreSQL

`WithholdingRouteAuthorizationTest.php:133` is now `'rate' => '0.1000'` and the post-condition at
`:414` is `assertSame('0.1000', $this->rule->fresh()?->rate, …)` — both semantically correct for a
`decimal(5,4)` fraction column. The fixture carries an inline comment naming the PG overflow, the
SQLite false-green, and the `min:0, max:1` domain, so the trap is documented at the point of use.

**My own rerun on live PostgreSQL:**

```
OK (23 tests, 63 assertions)
```

Matches the paste exactly (23 tests = the original 20 + 3 F6 controls; 63 assertions). Round 1's
`20 ERRORS, 0 assertions` on the same engine is fully reversed — the authorization proof now
actually executes in prod-shaped conditions.

**Sibling confirmed untouched as instructed:** `git diff a1952aa23..ae7d147e1 --stat` reports no
change to `tests/Feature/Taxation/TaxationTenantIsolationTest.php`, and it still carries
`'rate' => '15.00'` at `:584` and `:602`. Its 2-of-18 PG errors remain pre-existing and outside
this lane's scope, ticketed separately by the coordinator.

### F3 — CLOSED. Deploy note is now accurate against the actual deploy path

I re-read `apps/api/docker/entrypoint.sh` against every claim in the new note:

- `:141` — `tenants:migrate-rolling --force`. DDL only, seeds nothing. ✅ as described.
- `:152-161` — the opt-in `SYNC_PERMISSIONS_ON_BOOT = "true"` branch running
  `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'`. ✅ exact
  command match.
- unconditional `php artisan permission:cache-reset` — present, but at **`:167`**, not the `:168`
  the note cites (`:168` is a blank line). Off-by-one in a documentation citation only; not a
  defect, recorded for accuracy.

The note states the correct **ordering** (seed → then cache-reset), the correct **failure mode**
(on any un-reseeded tenant every user *including admin* 403s on all six rules routes, because the
group gates on a single permission with no other holder), the correct **blast-radius limit**
(API-only today — `WithholdingRulesPage.tsx` is neither barrel-exported nor routed), and explicitly
records that `SYNC_PERMISSIONS_ON_BOOT`'s value on staging/prod **cannot be verified from this
repo**. It also explicitly supersedes the wrong note in `b41ab525c`, which matters because commit
bodies are immutable and a future reader will hit the old one first.

### F6 — CLOSED, and I verified the controls are actually load-bearing

Four admin allow-path (2xx) controls added: `store` → 201; `issue` → 200 → `export-tej-batch` → 200
→ `submit-tej` → 200 (ordered so the batch export's `status=issued` filter still matches before
`submit-tej` flips the certificate to SUBMITTED, with status assertions at both ends); and
`destroy` on a draft. That covers every distinct permission that previously had only deny-path
assertions.

**The check that makes this finding genuinely closed rather than cosmetically closed:** an admin
allow-path control only proves anything if admin's authorization actually runs through the Spatie
permission check. I grepped `apps/api/app/` for a super-admin bypass — there is **no `Gate::before`
anywhere**; the only Gate registrations are `Gate::define('viewHorizon', …)`
(`app/Providers/HorizonServiceProvider.php:30`) and two `Gate::policy` bindings
(`app/Providers/AppServiceProvider.php:179-180`). So a typo'd guard string
(e.g. `can:withholding.destroy`, absent from the seeded catalog) would deny admin exactly as it
denies everyone else, and these four tests would go red. Confirmed load-bearing.

### Zero-rate gate minors — spot-check, both closed

- **Docblock** (`WithholdingCertificateService.php`): the "rounds to `0.000` at currency scale 3"
  wording is replaced with the resolved-scale behaviour
  (`CurrencyScaleResolverInterface::getScale()`, with concrete per-currency examples: 3 TND, 2
  EUR/USD, 0 XOF/JPY/KRW) plus an explicit statement of the safe direction — over-refusal for
  coarser-scale currencies, never under-refusal. The docblock now matches the code.
- **Message pinning** (`WithholdingZeroRateGuardTest.php`): both guard tests now add
  `assertJsonPath('error.message', 'Withholding amount is zero; no certificate is created.')`
  alongside the shared `CREATION_FAILED` code, so a regression that misattributes the 422 to the
  "No applicable withholding rule found" `\DomainException` can no longer pass silently. Green on
  live PG (part of the 9/9 run below), which also proves the pinned literal matches the string the
  service actually throws.

### Reruns I executed this round

| command | result |
|---|---|
| independent re-derivation of `permissionsMap.generated.ts` | **zero diff vs committed** |
| `phpunit WithholdingRouteAuthorizationTest` (**live PG**) | **OK (23 tests, 63 assertions)** |
| `phpunit ExportFrontendPermissionsMapCommandTest WithholdingZeroRateGuardTest RolesAndPermissionsWithholdingRulesManageGrantTest` (live PG) | **OK (9 tests, 37 assertions)** |
| `vitest run src/features/withholding` | **8 files, 23 tests passed** |
| `pint --test` on the 3 touched PHP files | **pass** |

## Still open (all non-blocking; none gate this merge)

- **F4** (Minor, doc nit) — `RolesAndPermissionsWithholdingRulesManageGrantTest.php:21-23` still
  says `PermissionSeeder` is "never called by `DatabaseSeeder`"; it *is* called by
  `ProductionSeeder.php:68`. The substantive claim (never run against tenant DBs) is correct.
  Reword opportunistically.
- **F5** (Minor, follow-up) — when `WithholdingRulesPage` is eventually routed it must carry
  `RequirePermission permission="taxation.withholding_rules.manage"`. Now unblocked: the regenerated
  map makes that string a valid `Permission` key.
- **F7** (Minor, pre-existing) — no `Str::isUuid()` guard before the uuid-PK `findOrFail` in either
  taxation controller; non-UUID path segment 500s on PG. Ticket.
- **Pre-existing PG fixture bug** in `TaxationTenantIsolationTest.php:584, :602` (`'rate' => '15.00'`)
  — separately ticketed, deliberately untouched here.
- **Observation** — `POST /withholding/preview` (`routes.php:35`) remains ungated; a payments-side
  permission decision, not this lane's.
- **Operational, before deploy** — confirm `SYNC_PERMISSIONS_ON_BOOT=true` on the target
  environment (or run the per-tenant seed manually) BEFORE this reaches a tenant, per F3. This is
  the one item that can still bite in production and it lives outside the repo.

## What to do before merge

Nothing blocking. Carry the F3 operational step into the launch-program deploy checklist alongside
the already-stacked treasury permission reseeds.
