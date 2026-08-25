# Gate r1 — TENANCY/AUTHZ lens — Session B2 lane B2-6 / residual sweep

**VERDICT: spec ✅ + quality APPROVED-WITH-CONDITIONS — merge-blocking NO.**
Two MANDATORY pre-promotion conditions (both outside the branch: a `.github/**` edit the brief forbade in-lane, and a promotion-checklist row). Every item in my scope is code-correct and green on PostgreSQL by my own execution.

- Branch `fix/sb2-b26-residual-sweep`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-b26-residual-sweep`, 9 commits `533336b19..3f880783a` on base `d91886af9`.
- Scope reviewed: C-30(i), C-16(iii), C-17(iv), C-17(ii), C-17(vii)/(viii), the three honest pin rewrites, the ci.yml allowlist proposal, the deploy note. **Skipped per dispatch:** C-14(*) (inventory lens), C-28(i) (parent-verified).
- Evidence DB: my own throwaway `autoerp_tenancygate_test` on PG 5433 (`autoerp`/`autoerp_secret`), one phpunit file per run, by path.

---

## Execution evidence (mine, not the implementer's)

| what | result |
|---|---|
| `tests/Feature/POS/HeldOrderTest.php` (PG) | **OK 21 / 67** |
| `tests/Feature/POS/TerminalClaimHardeningTest.php` (PG) | **OK 26 / 111** |
| `tests/Feature/Treasury/TreasuryTenantIsolationTest.php` (PG) | **OK 73 / 227** |
| `tests/Feature/POS/HeldOrderTenantIsolationTest.php` (PG, sibling regression) | **OK 6 / 19** |
| `pint --test` on the 7 touched api files | pass |
| `phpstan analyse TerminalController.php PaymentMethodController.php` | `[OK] No errors` |

All four match the implementer's reported numbers exactly.

**Corroboration of REPORT residual #5 (accidental).** I briefly ran `HeldOrderTenantIsolationTest` in background and in foreground against the same DB at the same time: `1 failed, 5 passed` with a `QueryException` at `HeldOrderTenantIsolationTest.php:83` (the `RolesAndPermissionsSeeder` call in `setUp`). Re-run alone: `6 passed`. Two phpunit processes on one throwaway PG DB do corrupt each other. The report's harness warning is true; reviewers must serialise.

---

## Per-item verdicts

### C-30(i) `default_journal_id` prohibited on both verbs — **PASS**
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:101` (store) and `:219` (update) → `'default_journal_id' => ['prohibited']`; the write is gone from `create()` (`:150-153` now carries only the comment) and `unset($validated['default_journal_id'])` at `:266` precedes `$method->update($validated)`. Column kept — no migration in the lane, correct.
- Tenancy scoping of the surrounding path is sound: `update()` resolves the row tenant+company-scoped at `:174-177`; the `code` uniqueness rule is tenant-scoped at `:191-193`.
- **No client regression.** Grep across `apps/web/src`, `apps/pos/src`, `packages/`: **zero** references to `default_journal_id`. Nothing round-trips the field, so `prohibited` cannot 422 a live edit form. (`prohibited` also permits an explicit `null`/empty, so even a future full-object PUT is safe.)
- Verified there is genuinely no `journals` table: the only non-test producers are the model property/`$fillable` (`PaymentMethod.php:38`, `:82`), the census entry (`TreasuryOrphanCensusCommand.php:123`) and the column itself (`2025_11_30_120000_create_treasury_tables.php:75`).

### C-16(iii) held-order routes gated per verb — **PASS** (with two conditions below)
The brief's `permission:` mechanism is genuinely unavailable and the `can:` deviation is right:
- `apps/api/bootstrap/app.php:112-121` registers exactly `super_admin`, `central_admin`, `central_admin_role`, `validate.location.access`, `module`, `require.any.permission`, `scheduling.captcha`, `cross_tenant`. **No `permission` alias.** The diff does not add one (`bootstrap/app.php` is touched only at the C-14(iv) render block, `:934-946`).

**Route dump (executed, `$route->wheres` + `gatherMiddleware()`), all five held-order routes:**
```
api | auth:sanctum | SetPermissionsTeam | EnforceTokenTenantClaim | can:pos_held_orders.<verb>
```
So rule 12's `['api','auth:sanctum',SetPermissionsTeam::class,…]` chain is intact and the new `can:` is appended after it — the Spatie team context is set before `Authorize` runs (also proven empirically: the same file's happy-path tests are 200 and the two new deny tests are 403).

**`module:` gate — correctly absent, not a rule-12 miss.** `config/verticals.php` declares no `POS` module in any vertical's `default_modules`/`compatible_extras`, and no route in `apps/api/app/Modules/POS/routes.php` or `routes_held_orders.php` carries `module:`. There is no vertical-exclusive surface here, so the both-layer requirement is not triggered.

**The middleware is the ONLY gate.** `HeldOrderController` (`store`/`index`/`show`/`recall`/`destroy`) contains zero `Gate::`/`authorize`/`can(` calls — grep returns nothing. Before this commit every one of the five verbs, including the audited actor-attributed discard, was reachable by any authenticated company member. Confirmed.

**Verb map — sane.** store/recall → `.create`, index/show → `.view`, destroy → `.delete`. Recall flips `held → recalled`, so mapping it to the write permission rather than `.view` is defensible; the only role it could bite is a hypothetical custom view-only role.

**Seeded role matrix — corrected.** Permissions declared at `RolesAndPermissionsSeeder.php:396-398` (the LEDGER C-16 row's `:375-377` is line drift):

| role | seeder line | holds `pos_held_orders.*` |
|---|---|---|
| admin | `:559` (`Permission::all()` at `:545`) | yes |
| manager | `:562`, granted `:628` | yes |
| cashier | `:667`, granted `:689` | yes |
| **viewer** | `:703` | **none → now 403** |
| technician | `:743` | none → now 403 |
| **operator** | `:768` | **none → now 403** |
| accountant | `:802` | none → now 403 |

The implementer's matrix lists four roles and omits **viewer** and **operator**. Five seeded roles lose access, not two.

**Deny path is pinned and real.** `HeldOrderTest.php:456-513` — 403 on DELETE with the row still present (`deleted_at => null`), and 403 on GET+POST with `assertDatabaseCount('pos_held_orders', 0)`. Ran green.

### C-17(iv) `Route::whereUuid(['id','terminal'])` — **PASS**
- `apps/api/app/Modules/POS/routes.php:77` wraps all 11 `{id}`/`{terminal}` routes.
- **Executed route dump:** every one of the 11 (`GET/PATCH/DELETE /pos/terminals/{id}`, `/activate`, `/deactivate`, `/release`, `/archive`, `/toggle-training`, `/z-chain-state`, `/{terminal}/fiscal-schema-cutover`, `/{id}/acknowledge-v4-refund-authoring`) carries **both** `id` and `terminal` constrained to `[\da-fA-F]{8}-…-[\da-fA-F]{12}`. Nothing in the group is left open. The pre-group routes (`/available`, `/claim`, `/request`, `/web`, `/by-device/{hardwareIdentifier}`, `POST /pos/terminals`) are correctly untouched.
- **The RouteRegistrar replace-not-merge claim is TRUE — I reproduced it.** Registering two probe groups and dumping `$route->wheres`:
  - `Route::whereUuid('a')->whereUuid('b')->group(…)` → `["b"]` (the `a` constraint is silently dropped)
  - `Route::whereUuid(['a','b'])->group(…)` → `["a","b"]`
  The inline comment at `routes.php:72-76` and REPORT residual #4 are accurate. The lane-wide sweep for other chained `where*` route groups is worth a follow-up row.

### C-17(ii) `z_hash_sequence` from the latest Z row — **PASS**
- `TerminalController.php:904` → `$latestZReport->z_number`.
- **Scoping is clean.** `$terminal` is resolved `Terminal::forCompany($this->companyContext->requireCompanyId())->findOrFail($id)` (`TerminalController.php:869-870`); `ZReport::forTerminal()` is `where('terminal_id', $terminalId)` (`apps/api/app/Modules/POS/Domain/ZReport.php:279-282`). Terminal ids are UUID PKs of a company-scoped row, so there is no cross-terminal and no cross-company read; under db-per-tenant the tenant boundary is physical anyway. No new global/unscoped query.
- **The equality claim is verified against the device.** `apps/pos/src/lib/offline/zReportService.ts:440` `newZNumber = zChainState.z_number + 1` and `:442` `newHashSequence = zChainState.z_hash_sequence + 1` — both advance by one per Z from the same local row, both start at 0 (`apps/pos/src/lib/db/migrations.ts:210` default 0), so they are identical by construction and the endpoint must hand back a matching pair. `count()` under-reported whenever a Z row was missing server-side.
- Consumer confirmed: `apps/pos/src/lib/sync/syncService.ts:1552` `apiGet('/pos/terminals/${terminalId}/z-chain-state')` → `upsertZChainState`. Genesis arm (0/0/GENESIS) preserved and pinned.
- Authz on the endpoint unchanged: `Gate::any(['pos.manage_terminals','pos.operate_terminal'])` (`:865-867`).

### C-17(vii) terminal-code allocation — **PASS**
Answering the dispatch's questions directly:
- **Codes are unique at (tenant_id, company_id, location_id, code)** — `database/migrations/tenant/2026_01_08_190429_create_pos_terminals_table.php:65`, index `pos_terminals_unique_code`. The 2026-08-23 hardening migration adds `pos_terminals_unique_hardware_identifier` and does not touch the code index.
- **The lock is keyed per COMPANY** (`takeTerminalCodeLock()`, `TerminalController.php:1098-1109`, key `"pos_terminal_code:{$companyId}"`), and the allocator scans `Terminal::withTrashed()->forCompany($companyId)` (`:1071-1082`). Company is **coarser** than the index grain (company ⊃ location), so the lock cannot miss a serialisation the index would catch. Correct by construction, not by luck.
- **Tenant grain needs nothing extra.** I confirmed by execution that PostgreSQL advisory locks are database-scoped (`pg_locks` for an `advisory` lock reports `database = autoerp_tenancygate_test`), so under db-per-tenant the lock is already per-tenant-DB. In single-DB compat mode (`TENANCY_DB_PER_TENANT=false`) `company_id` is a UUID, so no cross-tenant aliasing either. `scopeForCompany` (`Terminal.php:230-233`) omits `tenant_id`, which here yields at worst a superset scan — safe.
- The key is **string-namespaced**, which is stricter than the bare-uuid siblings at `AccountingService.php:1306` and `GeneralLedgerService.php:5766`, and matches `ExpenseService.php:1119-1123` (`"expense_number:{$tenantId}"`). Good.
- **Both callers allocate inside a transaction**, so the `xact` lock is not a per-statement no-op: `store()` at `TerminalController.php:121` (new `DB::transaction`), `requestTerminal()` at `:663-668` (pre-existing, `generateTerminalCode()` evaluated inside the closure). The deviation-2 justification in the report ("there was no pre-existing lock to hang this off") checks out — `claim()`'s guarantee is a conditional UPDATE (`:431`) and locks nothing.
- max+1 parsing: `preg_match('/^POS(\d+)$/')` skips `CAISSE-A` rather than reading it as zero; PHP-side because `'POS99' > 'POS100'` lexicographically. Pinned by two behavioural tests plus a query-log ordering test that asserts `pg_advisory_xact_lock` precedes `insert into "pos_terminals"`.

### C-17(viii) `release()` under `FOR UPDATE` — **PASS, correctly scoped as half-closed**
- `TerminalController.php:553-599`: single `DB::transaction`; `Terminal::forCompany($companyId)->whereKey($id)->lockForUpdate()->firstOrFail()` at `:555-558`; the open-shift probe at `:576-579` and the clearing write at `:594-596` both under that lock. `TerminalReleased` is dispatched **after** commit (`:614-623`) — correct, no event on rollback. Company scoping preserved, so the cross-company 404 arm still holds (its test is green).
- The 409 arm returns a `JsonResponse` from inside the closure; nothing was written, so the commit is a no-op. Acceptable.
- The inline honesty block at `:541-551` is accurate: neither shift-open path takes the terminal row lock, so release-vs-shift-open is still unserialised. This is stated, not hidden, and matches REPORT residual #1. Correct disposition for this lane.

### The three honest pin rewrites — **all three genuinely pinned defects. PASS.**
1. `TreasuryTenantIsolationTest` — the old pair asserted `assertNotSame(500,…)` **plus `assertNoValidationErrorFor($response,'default_journal_id')`**, i.e. it required the API to ACCEPT a UUID for a column whose target table does not exist. That second half locked in the live write to a phantom target. Replaced with 422-on-both-verbs + a not-persisted assertion + a control proving a payload without the field still creates (201, column null). The update pin additionally proves the sibling `name` change does not land. Uses the real house helper `assertApiValidationErrors` (`tests/Traits/AssertsApiValidation.php:33-57` — asserts 422 and the `{error:{errors:{…}}}` envelope), not a bare status check.
2. `TerminalClaimHardeningTest::test_a_code_collision_is_not_mis_reported_as_a_device_binding_collision` — the old body asserted **`assertGreaterThanOrEqual(500, …)`**, i.e. it required the `count()`-derived collision to remain a reachable 500. Now asserts `< 500` and `data.code === 'POS03'`. Note the case no longer exercises `isUniqueViolation()` discrimination; the docblock says so and points at the sibling 409 test, which does. Acceptable.
3. `ReconciliationTest::test_cannot_finalize_with_unresolved_items` — the old body was `$response->assertStatus(500); // Service throws InvalidArgumentException`. A 500 asserted as expected behaviour is the textbook defect pin. (C-14 substance itself is the inventory lens's call; I rule only on the pin honesty.)

---

## Findings

**[Important] `ci.yml` — the new authz gate has NO CI guard at all. Append REQUIRED.**
`.github/workflows/ci.yml:1025` (`backend-test-pgsql` `--filter` alternation) contains `TerminalClaimHardeningTest` and `LocationReconciliationTest`; it contains **no** `HeldOrderTest` and **no** `CountingTerminalStateGuardTest`, and the lone `ReconciliationTest` substring hit is inside `LocationReconciliationTest` — the implementer's reading is exact. `tests/Feature/Treasury` runs wholesale in `treasury-spine-pgsql` (`ci.yml:1268-1276`), so C-30(i) is covered today. But `feature-lane-pos` (`ci.yml:1317`) and `feature-lane-inventory` (`:1553`) are parked behind `vars.SELF_HOSTED_RUNNER_READY` and sit in `ALLOW_SKIPPED_JOBS` (`:2644`), so **`HeldOrderTest` — the only regression guard for the five new `can:` gates — executes in no CI job whatsoever.** A route-middleware gate with zero CI coverage is one careless `routes_held_orders.php` edit away from silently reopening.
→ **RULING: APPROVE the proposed append** `|CountingTerminalStateGuardTest|ReconciliationTest|HeldOrderTest` at `ci.yml:1025`, on the B-3 precedent, applied by the parent/promotion lane (the brief correctly forbade `.github/**` edits in-lane).
Two things the append note must carry:
- **Anchoring is safe.** The filter is `'/\\(A|B|…)::/'`, so `ReconciliationTest` requires a literal `\` immediately before it and will **not** capture `LocationReconciliationTest` or `NormalizedReconciliationTest`; `LocationReconciliationTest` keeps its own entry. All three names are unique test classes (`ReconciliationTest` → `tests/Feature/Inventory/ReconciliationTest.php` only).
- **It does not arm on a direct dev promotion.** `backend-test-pgsql`'s `if:` (`ci.yml:588`) is `workflow_dispatch || base_ref==main || base_ref==dev || (push && ref==refs/heads/main)` — **not** `push → dev`. Same caveat the C-QR0a entry already records. If the batch lands as a push to `dev`, these pins still run nowhere until a PR or a main push.
- Green-alone evidence I reproduced: `HeldOrderTest` 21/67 and `TerminalClaimHardeningTest` 26/111 on PG, zero inherited reds. (`CountingTerminalStateGuardTest` / `ReconciliationTest` are the inventory lens's to confirm.)

**[Important] Deploy note is incomplete in two ways — both produce a fleet-wide silent 403.**
The report's note ("custom roles lacking `pos_held_orders.*` start getting 403 — role reseed + permission-cache reset") is right but under-scoped.
1. **Seeded roles: five, not two.** `viewer` (`RolesAndPermissionsSeeder.php:703`) and `operator` (`:768`) are missing from the report's matrix and are also newly 403 on all five routes.
2. **The catalogue-absence case is worse and unstated.** If a tenant DB's `permissions` table lacks the three rows at all — a tenant provisioned/seeded before `b8813020f` (2026-03-11, the commit that shipped both `2026_03_11_500000_create_pos_held_orders_table` and the three permission names), or any tenant never re-seeded — then Spatie swallows the miss rather than erroring: `checkPermissionTo()` catches `PermissionDoesNotExist` and returns `false` (`vendor/spatie/laravel-permission/src/Traits/HasPermissions.php:260-267`), the `Gate::before` hook returns `null` (`vendor/spatie/laravel-permission/src/PermissionRegistrar.php:125-131`), the gate falls through undefined and denies. **Every caller including `admin` gets 403**, because `admin` is `syncPermissions(Permission::all())` at seed time (`:545`) and cannot hold a row that does not exist.
→ **RULING: promotion-checklist row required before this batch reaches staging/prod:** per tenant DB, re-run `RolesAndPermissionsSeeder` + `php artisan permission:cache-reset`, then verify `SELECT count(*) FROM permissions WHERE name LIKE 'pos_held_orders.%';` returns **3** and that every role expected to operate a till carries all three. This is the classic silent-403 trap and it must be executed, not merely noted.

**[Minor] No frontend gating on the held-order UI.**
`apps/web/src/features/pos/components/HoldOrderButton.tsx`, `apps/web/src/features/pos/components/HeldOrdersBadge.tsx`, `apps/web/src/features/pos/molecules/HeldOrdersList/HeldOrdersList.tsx` and `.../HeldOrderCard/HeldOrderCard.tsx` contain no `hasPermission`/`usePermission`/`RequirePermission` check, so a member of an ungranted role now sees live Hold/Recall/Discard controls that 403. This is **not** a rule-12 both-layer violation (POS is not a module in `config/verticals.php`; no POS route carries `module:`), and the practical blast radius is small — the only live client is the web POS, driven by cashier/manager/admin, and `apps/pos/src/api/holdApi.ts` has **zero callers** in the Tauri POS (dead code). Follow-up row, not a blocker.

**[Minor] Deny-path fixture reads as a contradiction.**
`tests/Feature/POS/HeldOrderTest.php:481-495` builds the un-permissioned user with `UserCompanyMembership::create([... 'role' => 'admin'])` and then grants only `pos.operate_terminal`. It happens to make the test *stronger* (it proves the membership role is not an authz bypass) but nothing says so. Either use a non-admin membership role or add one line of docblock.

**[Minor / residual, out of brief scope] The C-17(iv) defect class is still open on the file this lane edited.**
The route dump shows `api/v1/pos/held-orders/{id}` (and `/{id}/recall`) with `wheres=[]`, while `HeldOrderController::show()` does `HeldOrder::where(tenant)->where(company)->findOrFail($id)` (`HeldOrderController.php:104-111`) against `pos_held_orders.id uuid` (`2026_03_11_500000_create_pos_held_orders_table.php:17`) — a non-UUID segment is `22P02` → 500 on PostgreSQL, invisible on SQLite. `PaymentMethodController::update()` (`:174-177`) has the identical shape on `/payment-methods/{id}`. One-line `->whereUuid('id')` each; correctly not taken in this lane (out of brief), but it should be a LEDGER row now that the pattern is established.

**[Observation] `pullZChainState` has no monotonic guard on the z-chain counters.**
`apps/pos/src/lib/sync/syncService.ts:1546-1575` → `decideZChainUpsert` guards only `grand_totals`; a server `z_number`/`z_hash_sequence` lower than local still overwrites, unlike `upsertTerminalState`'s `FiscalRegressionError` on the receipt chain (`apps/pos/src/lib/db/repositories/terminalStateRepository.ts:354-360`). C-17(ii) removes the systematic under-report that made this fire; the asymmetry itself is a POS-lane residual.

**[Observation] Admin-supplied duplicate terminal code is still an uncaught 23505.**
`CreateTerminalRequest::rules()` (`:32`) validates only shape (`nullable|string|max:20|regex:/^[A-Za-z0-9\-]+$/`) — no uniqueness rule — and `store()` catches nothing around the insert. REPORT residual #3 is accurate and unchanged by this lane (the pre-existing behaviour is preserved, not worsened). Not a regression.

**[Note] LEDGER line drift.** Row C-16(iii) cites `RolesAndPermissionsSeeder.php:375-377`; the permissions are at `:396-398` today. The implementer used the correct lines; worth correcting the ledger row.

---

## What to fix before merge
Nothing in the branch. Land the two conditions with the promotion: (1) parent applies `|CountingTerminalStateGuardTest|ReconciliationTest|HeldOrderTest` at `.github/workflows/ci.yml:1025` with the "does not arm on push→dev" caveat recorded; (2) add the per-tenant `RolesAndPermissionsSeeder` re-run + `permission:cache-reset` + `count(permissions LIKE 'pos_held_orders.%') = 3` verification to the promotion checklist, naming all five affected seeded roles (viewer, operator, technician, accountant — plus any custom role) and the admin-also-403 catalogue-absence case.
