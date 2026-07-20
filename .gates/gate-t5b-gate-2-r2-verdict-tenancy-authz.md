`APPROVE`

Tenancy and authz are clean. The two remediations do what the request claims, and neither weakens scoping, token isolation, permission enforcement, or error disclosure. Three MAJOR carry-forwards below — none breaches a tenancy or authz boundary, so none blocks this gate.

I could not reproduce the test evidence: the phpunit run was denied approval in this session. All findings below are grounded in code I read, not in a run.

---

## Findings

### MAJOR

**M1 — `StatementImportService.php:251` — `void()` hard-deletes statement lines; undocumented, and it opens the same audit gap the first review raised as M1.**

`$locked->lines()->delete()` destroys every parsed line on void. The spec authorizes void as a *status transition* (`canTransitionTo(Voided)`, `:236`) and frames line cascade as delete-only (`docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:74` — `cascadeOnDelete` from statements → lines). Nothing in the spec authorizes destroying lines on void.

Root cause is that the deletion is *load-bearing* for re-import, not deliberate: `withoutExistingFingerprints` (`:340-342`) filters on `payment_repository_id` + `fingerprint` with **no statement-status filter**, so without the delete a re-import of the same file after void would match every fingerprint, return `accepted === []`, and demand `acknowledge_empty` for a zero-line import. The delete is a workaround for a query that should have been made status-aware.

Failure scenario: accountant voids a mis-scoped statement. `show()` (`BankStatementController.php:63,166`) now returns `lines: []` and `lines_count: 0` for it. An auditor asking what the voided statement contained gets nothing from the API. Mitigated — the source file is retained at `source_file_path` and void is blocked when allocations or executions exist (`:239-249`), so no reconciliation work is destroyed and the lines are reproducible — which is why this is MAJOR and not a blocker.

*Fix:* drop `:251` and make the fingerprint query status-aware instead — join `bank_statements` and exclude `status = 'voided'` in `withoutExistingFingerprints`. That preserves provenance **and** unblocks re-import.

**M2 — `2026_07_19_110001_create_bank_statements.php:50-64` — a shipped migration was edited in place; the change cannot self-apply to any DB that already ran it.**

The file was created in `ebe85487d` (Phase 5.2.3) and the unique index was swapped for a partial one in `abccafa6f`. Two independent mechanisms prevent the new index from landing on an existing DB: Laravel records the migration by filename so `tenants:migrate` skips it entirely, and the `Schema::hasTable('bank_statements')` early return at `:14-16` is a second stop. A tenant DB provisioned during 5.2.3–5.2.7 therefore keeps the **non-partial** `bank_statements_repository_file_unique` — and void → re-import fails there with a `23505` unique violation that no migration will ever repair.

Blast radius is bounded and I verified it: `git branch -a --contains e06d2a831` returns only `feat/treasury-phase5` and its remote. This has never been on `dev`, so staging and production have never run the 5.2.3 version and will get the correct partial index on first deploy. The exposure is developer/local tenant DBs. The PG evidence in the brief ("fresh PostgreSQL") confirms the create path and says nothing about the upgrade path.

*Fix:* either add a separate corrective migration that drops and recreates the index, or record in the checklist that any DB provisioned on this branch before `abccafa6f` must be rebuilt.

**M3 — deploy checklist still absent (check 8, carry-forward as the request anticipated).**

`docs/handoff/` contains `treasury-phase5a-deploy-checklist.md` but no ⑤b equivalent. Confirming all four obligations in request item 4 are still owed, verified against code:

- **Tenant migrations** — `statement_import_profiles` and `bank_statements` both under `database/migrations/tenant/`, require `tenants:migrate`. Plus M2's index caveat.
- **Permission reseed + `permission:cache-reset`** — four permissions at `RolesAndPermissionsSeeder.php:235-238`, three granted to accountant at `:714`. Per the known tenant-blind permission-cache bug, a deploy without both steps is a silent 403 for every accountant.
- **Node-stable / shared private staged-file storage** — still required. `preview()` writes at `StatementImportService.php:57`; `confirm()` reads back on a separate request at `:127-130`. Multi-replica without shared storage hard-fails on "The staged statement file no longer exists."
- **Staged-file retention/cleanup** — still owed and still unimplemented. `grep` for `bank-statements/` finds only the write site (`:56`); no console command, no prune job. Abandoned previews accumulate under `storage/app/private/bank-statements/` forever.

### MINOR

**m1 — carried forward unremediated: cross-company enumeration oracle.** `UploadBankStatementRequest.php:25,28` still use `ScopedExists::tenant`, and `BankStatementController.php:73-74` still resolves both ids with an **unscoped** `findOrFail` before `guardOwnership` runs. A sister company's repository id yields `422 BUSINESS_ERROR` "does not belong to the active company" (`StatementImportService.php:266`); a nonexistent id yields a `422` *validation* error. Distinguishable ⇒ a holder of `bank-statements.import` can enumerate live repository UUIDs in sibling companies. No data leaks and the service guard closes the security hole; only the oracle remains. `tenantAndCompany(...)` collapses both cases.

**m2 — the under-lock re-checks are untested.** `test_confirm_rejects_parser_profile_changes_after_preview` (`StatementImportFlowTest.php:391-400`) exercises only the pre-transaction digest check at `:121`. The under-lock digest check (`:173`) and the new repository-currency check (`:170`) are unreachable single-threaded. Acceptable — but they are the TOCTOU-critical branches and currently carry zero coverage.

**m3 — `bank-statements.reopen` still has no consumer.** `grep` over `app/` returns nothing. Seeded at `:238`, admin-only via `Permission::all()`. Harmless forward-declaration; noting so a later wave doesn't re-add it.

**m4 — `ConfirmBankStatementRequest.php:24-25` deviates from rule 19's letter.** `regex:/^-?\d+(?:\.\d+)?$/` has no scale ceiling. Functionally covered, and arguably better: `canonicalMoney` (`StatementImportService.php:414-422`) builds the ceiling from the resolved currency scale and throws otherwise — currency-aware where a hardcoded `{1,3}` would not be. Pre-existing at gate base. No float touches money anywhere in the diff.

---

## Eight-check table

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | `api/v1` group inherits full middleware chain | **PASS** | `routes.php:35` — `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`; all 10 routes inside the group (`:266-297`), each with an explicit `can:` |
| 2 | Permission grant matrix | **PASS** | Catalog `:235-238`; accountant `:714`; admin via `Permission::all()`; manager deny-path proven on read **and** upload, `StatementImportFlowTest.php:328-333` |
| 3 | `ScopedExists` + service ownership guards; cross-company 422 pre-storage | **PASS** (see m1) | `UploadBankStatementRequest.php:25,28`; `guardOwnership` at `:47` fires **before** `putFileAs` at `:57` |
| 4 | Profile CRUD company-scoped; binds only to active-company bank repo | **PASS** | `StatementProfileController.php:107-118`, `guardProfileInput:82-105`; rebind re-guarded `:59-64` |
| 5 | Statement list/show/void tenant+company scoped; 404s | **PASS** | `findStatement:128-139`; index `:36-38`; cross-company 404s `StatementImportFlowTest.php:358-362` |
| 6 | Preview token cannot cross tenant/company; confirm re-resolves | **PASS** | `confirm:108-110`; re-fetch + re-guard `:115-123`; re-guard under `lockForUpdate` `:164-175`; digest `:121,173`; expiry `:111` |
| 7 | No foreign id/data leakage | **PASS** (see m1) | `DuplicateStatementFileException` id is repository-scoped (`:284-293`); `continuityWarning:359-367` likewise; `index` filter applied *inside* the company-scoped base query `:40-44` |
| 8 | Deploy checklist obligations | **OUTSTANDING** | No `treasury-phase5b-deploy-checklist.md` — M3 |

---

## Middleware / permission / scoping assessment

The remediation touched the service and one controller method; it did not touch routing or the permission catalog, and I re-verified both rather than assuming. The chain is intact: `'api'` present (no 401 trap), `SetPermissionsTeam` present (no silent permission-check failure), `EnforceTokenTenantClaim` present. All four permissions are seeded **and** granted — no "seeded but ungranted ⇒ 403 for everyone" trap. `Treasury` is not vertical-exclusive, so rule 12's both-layers module gate does not bind.

Scoping remains defense-in-depth at four layers — validation, controller query, service guard, and re-guard under row lock — and the remediation **strengthened** the innermost layer rather than weakening it. The parse was moved out of the transaction (`:137`, pre-`DB::transaction`) to shorten the lock, which is the correct call, but it creates a new window: `$parsed` is computed against the *unlocked* repository, and `StatementRowMapper.php:38` scales amounts by `$repository->currency`. The author closed that window explicitly at `:170-172` — re-asserting the locked repository's currency matches the one parsing used. That is the non-obvious consequence of the refactor, and it was caught. The file-integrity hash is likewise re-asserted *after* parsing (`:138-142`), closing swap-during-parse.

The profile digest (`:309-320`) covers every profile field the parsers actually read — I enumerated the table columns against parser usage: `header_rows` (`CsvStatementParser.php:29,55`, `XlsxStatementParser.php:38`), `column_map`, `direction_convention`, `date_format`, `decimal_format` (`StatementRowMapper.php:39,48,60,68,93`), plus `parser_key` and `payment_repository_id`. Excluded fields are correctly excluded: `name` is not parser-affecting; `is_active` and `tenant_id`/`company_id` are enforced by `guardOwnership` rather than the digest. Digest mismatch is fail-safe (a reordered but logically identical `column_map` rejects rather than silently accepts). No gap found.

Profile delete guard (`StatementProfileController.php:73-75`) is correct and returns 422: `DomainException` → `BUSINESS_ERROR` 422 via `bootstrap/app.php:356-365`. `statements()` (`StatementImportProfile.php:64-67`) is not itself company-scoped, but the profile it hangs off already is, and a statement referencing it is necessarily same-company — no leak. Deactivation preserving `parser_profile_id` is pinned by `assertDatabaseHas` at `StatementImportFlowTest.php:387`. This closes the first review's M1.

No `latestOfMany`/`ofMany` anywhere in the diff. `Str::isUuid` guards precede every uuid-column comparison.

---

## Decision on the guarded `{bankStatement}`

**Accepted, unchanged.** `BankStatementController.php:128-139` is unmodified by the remediation and still implements the endorsed alternative: `Str::isUuid` → `abort(404)`, then `requireCompany()`, then `where(tenant_id)->where(company_id)->findOrFail()`. Strictly better than implicit route-model binding, which would materialize the model *unscoped* before authorization and 500 on PostgreSQL when a malformed id hit the `uuid` column. Malformed and foreign-company ids are indistinguishable — both 404 — so no existence oracle on statements.

Critically for this round: `void()` at `:123` routes through the same helper, so the newly-destructive void path is scoped identically to the read path. A cross-company void attempt 404s before `StatementImportService::void()` is ever reached. The re-import remediation did not introduce a second, less-guarded entry point.

---

**VERDICT: tenancy ✅ + authz ✅ + quality APPROVED**

Three merge obligations carried forward, none gate-blocking: make the fingerprint query status-aware and drop the line deletion in `void()` (M1); resolve the in-place migration edit for DBs already on this branch (M2); author `docs/handoff/treasury-phase5b-deploy-checklist.md` covering tenant migrations, `tenants:run db:seed --class=RolesAndPermissionsSeeder`, `tenants:run permission:cache-reset`, shared/node-stable private storage, and staged-file retention (M3).

I did not edit, commit, merge, tag, or push. `.gates/gate-t5b-gate-2-r2-verdict-tenancy-authz.md` is an empty placeholder — say the word and I'll write this verdict into it.
