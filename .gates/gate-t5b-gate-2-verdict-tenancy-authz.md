`APPROVE`

I ran the review myself against the code. Findings below.

---

## Findings

### MAJOR

**M1 — `StatementProfileController.php:70-75` — hard `destroy()` silently strips provenance from historical statements.**
`destroy()` calls `->delete()` unconditionally. The FK is `nullOnDelete` (`database/migrations/tenant/2026_07_19_110001_create_bank_statements.php:33-36`), so deleting a profile nulls `parser_profile_id` on every already-imported `bank_statements` row that used it. Failure scenario: accountant imports March/April statements under profile P, renames the bank's format, deletes P — both historical statements now report `parser_profile_id: null` from `format()` (`BankStatementController.php:157`), and the parse rules that produced their lines are unrecoverable. The model already carries `is_active`; deactivation is the correct verb here. Not a tenancy or authz defect — the scoping around it is correct — but it is a real audit-traceability regression the new endpoint introduces.
*Fix:* refuse `destroy()` with a `DomainException` when `BankStatement::where('parser_profile_id', $profile->id)->exists()`, and steer callers to `PATCH {is_active:false}`.

**M2 — Deploy obligation not yet recorded (check 8).**
`docs/handoff/` has `treasury-phase5a-deploy-checklist.md` but no ⑤b equivalent. This diff adds four permissions (`RolesAndPermissionsSeeder.php:235-238`) and grants three to accountant (`:714`). Per the known tenant-blind permission-cache bug, a deploy without reseed + reset yields a silent 403 for every accountant on every statement route.
*Additional obligation to record now, beyond reseed/cache-reset:* the two gate-1 tenant migrations (`statement_import_profiles`, `bank_statements`) must run via `tenants:migrate`, **and** the `local` disk (`config/filesystems.php:33-35` → `storage_path('app/private')`) must be node-stable or shared. `preview()` writes the staged file (`StatementImportService.php:57`) and `confirm()` reads it back on a *separate request* (`:123`); on a multi-replica deploy the second request can land on a different container and hard-fail with "The staged statement file no longer exists." This follows the existing `Import` module precedent (`ImportController.php:142`), so it is not new drift — but it is a deploy constraint that must be stated.

### MINOR

**m1 — `UploadBankStatementRequest.php:25,28` — cross-company enumeration oracle.**
`ScopedExists::tenant` (tenant-only) is what the plan specified, and the service guard closes the security hole. But the two failure modes are distinguishable: a sister company's repository id returns `422 BUSINESS_ERROR` "does not belong to the active company" (`StatementImportService.php:242`), while a nonexistent id returns a `422` *validation* error. A user with `bank-statements.import` can therefore enumerate which UUIDs are live repositories in sibling companies. Both entities are company-owned, so `ScopedExists::tenantAndCompany('payment_repositories', $company->tenant_id, $company->id)` is strictly available and collapses both cases into one indistinguishable response. Same applies to `StatementProfileRequest.php:27`.

**m2 — Evidence provenance: I could not reproduce the PostgreSQL run.**
I ran the file and got **12 passed / 68 assertions** — but on **SQLite**, since `phpunit.xml:41` defaults to `sqlite/:memory:`. `phpunit-pgsql.xml` fails in this worktree (`FATAL: role "root" does not exist`) because there is no `.env` here. The brief's "fresh PostgreSQL" claim is therefore unverified by me. This does *not* change my verdict: the PG-specific risk (invalid UUID into a `uuid` column) is closed by explicit code I read, not by the run — `Str::isUuid` guards at `BankStatementController.php:130` and `StatementProfileController.php:105`. No `latestOfMany`/`ofMany` anywhere in the diff.

**m3 — `bank-statements.reopen` is seeded with no consumer.** `grep` finds zero routes using it. Forward-declaration for a later wave; harmless, and it makes the admin-only assertion at `StatementImportFlowTest.php:272-273` a catalog test rather than a route test. Fine as-is — note it so a later wave doesn't re-add the permission.

---

## Eight-check table

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | `api/v1` group inherits full middleware chain | **PASS** | `routes.php:35` — `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`; all 10 new routes inside the group (`:266-297`) |
| 2 | Permission grant matrix | **PASS** | Seeder `:235-238` catalog; admin = `Permission::all()` `:458`; accountant `:714`; manager block `:463-553` contains no `bank-statements.*`; manager 403 on read **and** upload proven `StatementImportFlowTest.php:275-280` |
| 3 | `ScopedExists::tenant` + service ownership guards; cross-company 422 pre-storage | **PASS** (see m1) | `UploadBankStatementRequest.php:25,28`; guard at `StatementImportService.php:47` fires **before** `putFileAs` at `:57`; proven by `assertDirectoryEmpty` `StatementImportFlowTest.php:264` |
| 4 | Profile CRUD company-scoped; binds only to active-company bank repo | **PASS** | `StatementProfileController.php:103-114` (list `:25-30`); `guardProfileInput:78-87` enforces tenant+company+`RepositoryType::BankAccount`; rebind on update re-guarded `:59-64`; test `:225-252` |
| 5 | Statement list/show/void tenant+company scoped; 404s | **PASS** | `findStatement:127-138`; index `:36-38`; route param `{bankStatement}` `routes.php:268,281`; test `:290-309` |
| 6 | Preview token cannot cross tenant/company; confirm re-resolves | **PASS** | `confirm:107-109` tenant+company match; `:114-119` re-fetches both entities from DB and re-guards; `:146-151` re-fetches **and re-guards under `lockForUpdate`**; `:127` `hash_equals` on file digest; expiry `:110` |
| 7 | No foreign id/data leakage | **PASS** (see m1) | `DuplicateStatementFileException` id is repository-scoped (`:262-268`, repository already company-owned); `continuityWarning:314-320` likewise; `index` `payment_repository_id` filter applied *inside* the company-scoped base query |
| 8 | Deploy checklist obligations | **OUTSTANDING** | No `treasury-phase5b-deploy-checklist.md` exists — see M2 |

---

## Middleware / permission / scoping assessment

The middleware chain is correct and complete — this is the one place these reviews most often find a hole, and there isn't one: `'api'` present (no 401 trap), `SetPermissionsTeam` present (no silent permission-check failure), `EnforceTokenTenantClaim` present. Every one of the ten new routes carries an explicit `can:` guard; none is left bare.

The permission catalog is synced — all four new permissions are seeded **and** granted, so there is no "seeded but ungranted ⇒ 403 for everyone" trap. Admin inherits `reopen` through `Permission::all()`, which satisfies admin-only without a second grant list.

Scoping is defense-in-depth and holds at every layer: validation (tenant), controller (tenant+company query), service (tenant+company+type+active), and again under row lock inside the transaction. The re-guard at `StatementImportService.php:151` — *after* `lockForUpdate`, not just before the transaction — is the detail that closes the TOCTOU window where a repository is reassigned between preview and confirm. That is a deliberate, correct choice.

No module gate is required: `Treasury` is not vertical-exclusive, so the both-layers rule (CLAUDE.md rule 12) does not bind here.

## Decision on the guarded `{bankStatement}` implementation

**Accepted.** `BankStatementController.php:127-138` implements exactly the alternative the plan review endorsed: `Str::isUuid` → `abort(404)`, then `requireCompany()`, then a `where(tenant_id)->where(company_id)->findOrFail()`. This is strictly better than implicit route-model binding, which would materialize the model *unscoped* before any authorization ran, and would 500 on PostgreSQL when a malformed id reached the `uuid` column. Malformed and foreign-company ids are indistinguishable to the caller — both 404 — so there is no existence oracle on statements. `void()` routes through the same helper (`:120`), so the mutating path is scoped identically to the read path. `StatementProfileController.php:103-114` mirrors it. This also directly answers the gate-t5a finding that nothing pinned `findInstrument`'s scoping: here `StatementImportFlowTest.php:290-309` pins it with a real second company.

---

**VERDICT: tenancy ✅ + authz ✅ + quality APPROVED**

Two merge obligations to carry forward (neither blocks this gate): guard `StatementProfileController::destroy()` against profiles referenced by existing statements (M1), and author `docs/handoff/treasury-phase5b-deploy-checklist.md` covering the two tenant migrations, `tenants:run db:seed --class=RolesAndPermissionsSeeder`, `tenants:run permission:cache-reset`, and the shared-storage constraint on the `local` disk (M2).

I did not edit, commit, or push anything. `.gates/gate-t5b-gate-2-verdict-tenancy-authz.md` is currently an empty placeholder — say the word and I'll write this verdict into it.
