# Gate record — Session B lane Q-7 (terminal-claim hardening), tenancy-authz lens r1

Commit `d760748b0` on base `83448e0bd`, worktree `.worktrees/sb-q7-terminal-claim`,
branch `fix/sb-q7-terminal-claim-hardening`. Dual gate; this is the TENANCY/AUTHZ half
(fiscal-pos reviewer runs the other).

**Verdict: ACCEPT-with-conditions.**

No cross-tenant leak, no auth bypass, no privilege escalation, and no silent-403 seeder
trap found. Every new query is company-scoped through the same mechanism the controller
already used; the new `release()` route sits inside the module's rule-12 middleware group;
its permission is already seeded and already role-granted, so no tenant needs a seeder
re-sync to reach it. The three conditions below are merge-mechanics and operational, not
code defects — but condition 1 turns dev's manifest checker RED if resolved naively.

## Conditions (all must be discharged before/at merge)

- **C-1 (blocking at merge).** Manifest union arithmetic. The branch raises
  `gated_ceiling` 1145 -> 1147 (`apps/api/tests/feature-lane-manifest.json:9`) and POS
  `classes` 149 -> 151 (`:884`) off a base that read 1145/149. **Dev has since moved to
  1147 on its own** (`git diff 83448e0bd dev -- apps/api/tests/feature-lane-manifest.json`
  shows two OTHER groups at +1 each: `2 -> 3` and `17 -> 18`). The correct post-merge value
  is **`gated_ceiling` 1149 with POS `classes` 151**, notes unioned. Taking either side's
  1147 leaves 1149 gated classes under a 1147 ceiling, which trips
  `tools/feature-lane-manifest-check.php:810` (`$gatedClasses > $gatedCeiling`). Re-run
  `php tools/feature-lane-manifest-check.php` after the resolve.
- **C-2 (deploy checklist).** The migration's per-leg outcome is `status=ok` /
  `status=BLOCKED` / `status=FAILED`, and `run()` swallows a genuine DDL failure
  (`…140000_harden_pos_terminals_identity_and_lifecycle.php:339-347`) while the migrator
  still records the migration as applied — so a FAILED leg is never retried. The checklist
  must grep the tenant log for **both** `status=BLOCKED` **and** `status=FAILED` on the
  token `POS TERMINAL IDENTITY/LIFECYCLE HARDENING:` and carry a manual re-apply step. The
  three census queries below are exact against the live schema (executed on PG 5433).
- **C-3 (ledger rows).** Record finding 2 (no in-product release surface), finding 4
  (non-UUID `{id}` 500), finding 5 (missing cross-company 404 test), finding 7 (the
  per-company hardware-uniqueness ruling) as residuals; none is a merge blocker.

### Per-tenant census (run per tenant DB BEFORE the deploy; all three verified executable)

```sql
-- leg 1: duplicate live hardware bindings (blocks the partial unique)
SELECT tenant_id, company_id, hardware_identifier, COUNT(*)
FROM pos_terminals WHERE hardware_identifier IS NOT NULL AND deleted_at IS NULL
GROUP BY 1,2,3 HAVING COUNT(*) > 1;
-- leg 2: out-of-enum type (blocks pos_terminals_type)
SELECT type, COUNT(*) FROM pos_terminals
WHERE type IS NULL OR type NOT IN ('web','physical','virtual_admin') GROUP BY 1;
-- leg 3: incoherent lifecycle rows (blocks pos_terminals_active_logic)
SELECT COUNT(*) FROM pos_terminals WHERE NOT (
  (is_active = false OR (deactivated_at IS NULL AND deactivation_reason IS NULL))
  AND (deactivation_reason IS NULL OR deactivated_at IS NOT NULL));
```

## Findings

1. **[Important] `apps/api/tests/feature-lane-manifest.json:9,884` — stale-base manifest
   raise collides with a raise dev already took.** Branch says 1145 -> 1147 for +2 POS
   classes; dev independently reached 1147 for +2 elsewhere. A textual conflict whose two
   sides carry the *same number for different reasons* is the exact shape that gets resolved
   wrongly. Fix: resolve to `gated_ceiling: 1149`, POS `classes: 151`, union both notes,
   re-run the checker (it currently exits 0 in-branch: "1381 Feature classes in 74 groups …
   every --filter entry is anchored and uniquely matched").

2. **[Important] `apps/web/src/features/pos/api/terminalApi.ts:91-116` — the release path
   has no client.** The file exposes `archiveTerminal`, `activateTerminal`,
   `deactivateTerminal`; there is no `releaseTerminal`, and `apps/web/src/pages/POS/
   Terminals.tsx` therefore cannot reach the new endpoint. The lane's own justification
   (`TerminalController.php:469-472`: "a till whose hardware died could not be re-homed")
   is only half-satisfied — the remedy exists in the API and nowhere an operator can click.
   The brief scoped item (e) to the endpoint, so this is NOT a spec miss; it is a
   completeness residual that needs its own lane or an explicit owner "curl-only is fine"
   row. (No both-layer *gating* obligation is breached — see gate-verified item 3.)

3. **[Important] migration `…140000_harden_pos_terminals_identity_and_lifecycle.php:324-347`
   — a FAILED leg is indistinguishable from an absent one, and is never retried.** `run()`
   catches `Throwable`, logs `status=FAILED`, and returns; `up()` then completes normally so
   the `migrations` bookkeeping row is written and the leg will not re-run on any later
   deploy. The BLOCKED path is a deliberate, well-argued divergence from the
   `uniq_je_source_inventory_movement` throw-precedent (docblock :101-119) and I accept it —
   a bricked tenant migration chain is worse than a missing backstop. But the FAILED path
   inherits the same silence without the same reasoning, and the docblock only promises a
   manual re-apply for BLOCKED (`:350-361`). Fix: make the deploy gate grep both statuses
   (C-2), and state in the docblock that FAILED is also permanent-until-manual.

4. **[Minor] `TerminalController.php:490-491` — `release()` returns HTTP 500 for a non-UUID
   `{id}` on PostgreSQL.** Verified by probe on PG 5433: `POST /api/v1/pos/terminals/
   not-a-uuid/release` -> 500 `INTERNAL_ERROR`. This is the documented PG uuid-column
   pitfall, but it is **not a regression**: the same probe gives 500 on the pre-existing
   `PATCH …/deactivate` and `GET …/{id}`, so `release()` merely adds one more instance of a
   controller-wide idiom. Fix (separate ticket, whole route group): `->whereUuid('id')` on
   the terminal `{id}` routes in `apps/api/app/Modules/POS/routes.php:63-77`, or a
   `Str::isUuid()` 404 guard in the shared lookup. Do not fix it on `release()` alone —
   divergent behaviour across sibling routes is worse than uniform 500s.

5. **[Minor] `tests/Feature/POS/TerminalClaimHardeningTest.php:383-404` — the deny path is
   pinned, the cross-company path is not.** The permission deny (403 for an
   operate-only user, binding untouched) is properly tested. The information-leak contract
   is not: nothing asserts that a terminal belonging to another company of the same tenant
   answers **404, not 403, and is not cleared**. I verified the behaviour is correct by
   probe (404; `hardware_identifier` still `HW-FOREIGN` afterwards), but the endpoint that
   introduced the surface should pin it. Fix: add one case mirroring
   `TerminalLocationPosEnabledTest::test_claim_fails_closed_when_the_terminal_points_at_
   another_companys_location`.

6. **[Minor] `ClaimTerminalRequest.php:38` / `RequestTerminalRequest.php:38` validate
   `max:255` against a `varchar(100)` column** (`2026_01_08_190429_create_pos_terminals_
   table.php:50`). A 101–255-character `hardware_identifier` still produces a 500: the
   resulting `QueryException` is not a unique violation, so `isUniqueViolation()`
   (`TerminalController.php:880-888`) correctly declines it and it rethrows. Pre-existing,
   unchanged by this lane, but directly adjacent to the guard work it ships. Fix: tighten
   both FormRequests to `max:100`.

7. **[Minor / ruling to record] Uniqueness grain is effectively PER-COMPANY, and that is
   deliberate.** The index is
   `(tenant_id, company_id, hardware_identifier) WHERE hardware_identifier IS NOT NULL AND
   deleted_at IS NULL` (migration `:241-243`) — matching the brief verbatim. Under
   db-per-tenant `tenant_id` is constant within a tenant DB and is functionally determined
   by `company_id`, so the operative grain is company. **The ruling the code takes: one
   physical device MAY hold a terminal in two companies of the same tenant**, pinned by
   `PosTerminalsIdentityLifecycleConstraintsTest.php:120-137` and consistent with the
   pre-existing `TerminalDeviceLookupTest.php:79-95` (cross-company same-hardware row is
   invisible to this company's lookup). The controller pre-check matches that grain exactly
   (`hardwareBoundElsewhere()` is `forCompany`-scoped, `TerminalController.php:825-834`).
   Consequence for the owner ledger, not a defect: a single till can then author into two
   distinct NF525 chains under two legal entities. That is legitimate for a multi-entity
   site and is the only grain compatible with the already-pinned lookup behaviour; a
   tenant-wide grain would have broken that test. No change requested.

8. **[Minor] `tests/Feature/POS/TerminalClaimHardeningTest.php:318-332` pins a 500 as
   contract.** The case's value is `assertNotSame(409, …)` — that a `pos_terminals_
   unique_code` collision is not mis-attributed as `DEVICE_ALREADY_BOUND`. The following
   `assertStatus(500)` additionally freezes the unfixed `generateTerminalCode()` TOCTOU's
   unhandled-exception status, so whoever finally fixes that TOCTOU gets a red test in a
   file about something else. Fix: drop the `assertStatus(500)` line, or replace with
   `assertTrue($response->getStatusCode() >= 500)` plus a comment naming the follow-up.

## Gate verified

1. **Rule 12 middleware.** New route `POST /api/v1/pos/terminals/{id}/release`
   (`apps/api/app/Modules/POS/routes.php:74`) sits inside the module group at `:42`:
   `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`.
   Both required elements present, plus the tenant-claim defence-in-depth its siblings carry.
2. **Permission + seeder sync (the silent-403 trap): CLEAN.** `release()` asserts
   `Gate::authorize('pos.manage_terminals')` (`TerminalController.php:484`) — no NEW
   permission is introduced. `pos.manage_terminals` is already in the catalog
   (`RolesAndPermissionsSeeder.php:342`) and already granted to a role (`:597`, the manager
   block), while the cashier block (`:664`) holds only `pos.operate_terminal`. Existing
   tenants therefore need **no** seeder re-sync to reach the endpoint, and the deny path is
   a real user shape, not a synthetic one.
3. **Module gating: not applicable, correctly.** `config/verticals.php` has no `POS` module
   key (grep: only vertical labels "(IziPOS)"), so `module:POS` is not an available guard;
   every sibling terminal route is likewise ungated, and `routes.php:150` records the NG-5
   intent that these routes inherit `module:POS` when POS becomes a real tenant module.
   Terminal management is not vertical-exclusive, so no both-layer obligation is triggered.
4. **Tenant/company scoping of every new query.** `claim()` `:357`, `:425`; `release()`
   `:490`; `requestTerminal()` `:551` (via `$company->id` from `requireCompany()`);
   `findByDevice()` `:681`; helper `hardwareBoundElsewhere()` `:825-834` — all go through
   `Terminal::forCompany($this->companyContext->requireCompanyId())`
   (`Terminal.php:230-233` = `where('company_id', …)`). No raw cross-company/cross-tenant
   query, no row-level-tenant-scoping assumption, no `central`-connection misuse (this lane
   touches no central-directory table). Company context cannot be steered to a non-member
   company: `CompanyContext::userHasAccessToCompany()` requires an **ACTIVE** membership
   (`CompanyContext.php:139-146`).
5. **Cross-company probes (executed, PG 5433).** `release()` on another company's terminal
   -> **404**, terminal untouched. `claim()` on another company's terminal -> **422**
   (`ScopedExists` on `terminal_id`). Permission check precedes the lookup, so a caller
   without `pos.manage_terminals` gets 403 uniformly and the endpoint is not an existence
   oracle.
6. **`findByDevice()` ordering cannot be steered.** `orderBy('created_at')->orderBy('id')`
   (`TerminalController.php:684-693`) applies *inside* the `forCompany` scope, so rows an
   attacker could author live in a different company (or, in prod, a different database) and
   can never enter the ordering. The route parameter binds to a `varchar(100)` column, so
   there is no uuid-cast 500 on this path.
7. **Device vs user auth.** There is no separate device principal: `auth:sanctum` +
   `EnforceTokenTenantClaim` resolve an `App\Modules\Identity\Domain\User`
   (`EnforceTokenTenantClaim.php:55-70`). The device runs as a cashier user holding
   `pos.operate_terminal` (`claim()` `:353`), and `release()` requires
   `pos.manage_terminals` (`:484`) which the cashier role does not hold
   (`RolesAndPermissionsSeeder.php:664`). The operate/manage split IS the device-vs-admin
   boundary, and the lane got it the right way round.
8. **Impersonation coverage is automatic.** `ImpersonationActionClassifier::
   usesProtectedFinancialController()` hard-blocks any non-safe request whose controller
   action contains `\Modules\POS\`, `\Modules\Fiscal\` or `\Modules\Accounting\`
   (`app/Modules/SupportAccess/Domain/Services/ImpersonationActionClassifier.php:63-75`).
   `TerminalController@release` matches, so a support operator — even write-elevated —
   receives `IMPERSONATION_ACTION_BLOCKED` with a denied-request audit row. No new entry in
   `config/support_access.php` is required.
9. **Audit rows carry actor + company + tenant.** `TerminalClaimed`/`TerminalReleased` ->
   `DomainEventSubscriber.php:846-892` -> `persistEvent()` `:1077-1091` (userId from
   `Auth::id()`) -> `AuditService::record()` `:51-83`, which stamps `tenant_id` and the
   impersonation attribution columns. Both events also carry `claimedBy`/`releasedBy`
   explicitly, so the actor survives even if the listener context is lost.
10. **Migration is per-tenant and cannot touch central.** File lives in
    `apps/api/database/migrations/tenant/`, which is exactly the path
    `config/tenancy.php:197` feeds to `tenants:migrate`. Pre-flight scans are per-leg and
    per-tenant, abort only their own leg, and the other legs still apply — pinned by
    `PosTerminalsIdentityLifecycleConstraintsTest.php:291-374` (three cases, one per leg).
    All three census queries executed successfully against the live schema.
11. **Fresh-tenant provisioning cannot violate the new CHECKs.** `TerminalFactory` sets no
    `type` (column DEFAULT `'physical'`, `2026_02_19_000002:16`) and `is_active = true` with
    no deactivation columns; `CoffeeShopSeeder.php:1219-1237`, `DemoPharmacySeeder.php:
    755-779` and `VirtualAdminTerminalResolver.php:38-54` all write active/enum-valid/
    no-deactivation rows with `hardware_identifier` NULL (exempt from the partial unique).
    `activate()` clears both deactivation columns (`TerminalController.php:247-252`), so the
    deactivate->activate round trip stays legal — pinned at test `:238-260`.
    `Tests\Feature\Identity\TenantLaunchContractTest` (fresh tenant, v3 terminal) green on PG.
12. **Executed on PostgreSQL 5433, throwaway DB `autoerp_gate_q7t`, by path only.** New:
    `PosTerminalsIdentityLifecycleConstraintsTest` 15/15, `TerminalClaimHardeningTest` 14/14.
    Regression: `TerminalLocationPosEnabledTest` 12/12 (B-3 refusals intact),
    `TerminalDeviceLookupTest` 4/4, `TerminalActivationTest` 3/3,
    `VirtualAdminTerminalResolverTest` 3/3, `TerminalLifecycleEventsTest` 7/7,
    `TerminalResourcePolicyTest` 5/5, `TerminalCreationFiscalSchemaVersionTest` 6/6,
    `TenantLaunchContractTest` 2/2. PHPStan on the five changed backend files: no errors.
    Pint: pass. Manifest checker in-branch: **EXIT=0**.
13. **The `.github/workflows/ci.yml` edit is justified — do NOT revert it.** The single
    changed line (`:958`) appends only `TerminalClaimHardeningTest|
    PosTerminalsIdentityLifecycleConstraintsTest` to the existing `--filter` allowlist of
    the `backend-test-pgsql` job (job at `:578`). No job, trigger, matrix, secret or command
    is altered. It is the precedent this repo already set for a migration-bearing lane —
    B-3 added `TerminalLocationPosEnabledTest|BackfillLocationPosEnabledB3MigrationTest` to
    the same list — and it is the only way these PG-only CHECK/index cases execute at all,
    since their POS lane is parked behind `vars.SELF_HOSTED_RUNNER_READY`. The manifest
    checker independently validates that every `--filter` entry is anchored and uniquely
    matched. The manifest note's claim that both classes "DO execute on PR->dev on real
    PostgreSQL" is accurate.
14. **Scope discipline: clean.** Files touched are `TerminalController`, `POS/routes.php`,
    two new POS domain events, `Compliance/Listeners/DomainEventSubscriber`, one tenant
    migration, two test files, the manifest and the one CI line. Nothing on Session A's
    collision matrix (`HANDOVER-…-session-B-2026-08-23.md` §3) or the 2026-08-24 extension
    (§4) is touched: no PIN/`has_pins` surface, no `PosAuthController`/`PinVerifier`, no VAT
    resolution, no StockLevel read path, no web document page. `DomainEventSubscriber` is a
    shared high-traffic file — merge-conflict watch only, not a boundary violation. B-3's
    `pos_enabled` logic is untouched and its ordering is regression-pinned at
    `TerminalClaimHardeningTest.php:258-274`.

Not this lane's call, left to the fiscal-pos half: whether `release()` should be blocked (or
merely warned) while a shift is OPEN — the lane rules deliberately NOT
(`TerminalController.php:474-480`, test `:474-495`) — and the residual `zChainState`
`count()` defect the brief parked.
