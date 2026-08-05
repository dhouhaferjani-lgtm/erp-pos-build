# cat-(b) Wave 2 — adversarial tenancy / iteration-semantics review

Reviewer: tenancy-authz-reviewer (read-only). Date: 2026-08-05.
Commits: dbc5476aa, 97f123c1c, 6c07d2730, 43e14a041, 6037c8493, 7686be04e, d21811a7a, 61189c8b8 (local `dev`).
Scope: tenancy + iteration semantics only. Verification-logic invariance of the fiscal verifiers is the parallel fiscal-pos review's scope and is NOT covered here.
Paths below are relative to `apps/api/` unless stated.

**VERDICT: CHANGES-REQUESTED** (1 blocker, 5 required, 7 minor).

---

## BLOCKER

### B1 [Critical] `app/Modules/POS/Domain/Services/ReceiptHashService.php:57` — the third leaked central connection; `pos:verify-chains` still cannot verify a chain under db-per-tenant

`ReceiptHashService` captures `private readonly ConnectionInterface $db` in its **constructor** (`:57`). `VerifyPosChainCommand` constructor-injects it (`app/Modules/POS/Commands/VerifyPosChainCommand.php:66`), so the connection is resolved when the console kernel instantiates the command — before `forEachTenant()` calls `tenancy()->initialize()`.

Empirically confirmed on this checkout:

```
captured conn class=Illuminate\Database\PostgresConnection name=central db=iziposcentral
after default swap, captured still name=central db=iziposcentral
fresh resolve name=sqlite
```

Reach path: `VerifyPosChainCommand.php:257` → `ReceiptHashService::verifyTerminalChain()` `:185` → `verifyTerminalChainFiscalArm()` `:213` `$this->db->table('fiscal_events')`. `fiscal_events` is a tenant table (`database/migrations/tenant/2026_05_14_100001_create_fiscal_events_table.php`) and is verified absent from central (`Schema::connection('central')->hasTable('fiscal_events')` → `0`).

Consequence: for any tenant with ≥1 legacy fiscalized receipt (`VerifyPosChainCommand.php:242-247` gate), the fiscal arm raises 42P01 on the CENTRAL database, the exception escapes the closure, `forEachTenant` `:267-274` logs it and scores that tenant FAILURE. The launch verifier named in `docs/handoff/DISPATCH-PLAN-v4-first-tenant-2026-07-31.md:131` and feeding E-7 evidence therefore still does not work post-conversion, contrary to the commit docblock (`VerifyPosChainCommand.php:24-40`).

This is exactly the class the wave already fixed twice in `6c07d2730` (see its own rationale at `EnqueueResolvedEventProjectionsCommand.php:131-142` / `VerifyEventChainCommand.php:111-122`). `ZReportHashService` is unaffected only because it resolves at call time (`:257-258`, via `app()` — a rule-13 violation, but tenancy-correct).

**Fix:** apply the `6c07d2730` template to `ReceiptHashService` — inject `Illuminate\Database\DatabaseManager` and add a `private function db(): ConnectionInterface { return $this->databaseManager->connection(); }`, replacing `$this->db` at `:213` and `:450`. Add a regression test that runs `pos:verify-chains` with `config(['tenancy_resolver.db_per_tenant' => true])` and a provisioned tenant DB (see R5).

---

## REQUIRED

### R1 [Important] `app/Console/TenantScopedCommand.php:308-321` — `forEachTenantFiltered` opens every tenant DB and lets unrelated tenants contaminate a scoped run

The filter is applied *inside* the iteration closure (`:310-315`), so `forEachTenant` still runs the DB-existence probe **and** `tenancy()->initialize($tenant)` for every directory row (`:216-266`) before the wrapper short-circuits. Two consequences:

- A probe-throw (`:219-240`) or an `initialize()` throw (`:267-274`) on tenant **Y** sets `aggregate = FAILURE` even when the operator asked only for tenant **X** and X completed cleanly. `EnqueueResolvedEventProjectionsCommand.php:103-110` and `VerifyEventChainCommand.php:47-52` document exit 1 = "validation error" and exit 2 = "transient"; an unrelated tenant's transient infra fault is therefore reported to the operator as a validation error.
- O(fleet) database opens for a single-tenant command.

The docblock's justification (`:296-299` — "so the visited/skipped bookkeeping stays authoritative") does not require this shape: the directory query can be narrowed while still recording the target's visited/skipped outcome.

**Fix:** narrow the directory iteration when `$tenantFilter !== null`, or scope the aggregate to the filtered tenant's slot.

### R2 [Important] `app/Modules/Fiscal/Infrastructure/Commands/PreflightFiscalGateCommand.php:125,143` — a `--tenant`-scoped gate that can never pass on a fleet with any unprovisioned tenant row

`$skipped = count($this->skippedTenantIds())` (`:125`) is fleet-wide, and `:143` turns any `$skipped > 0` into "SERVER SURFACE: unable to verify" plus a non-zero exit. Under `--tenant=X`, every *other* tenant row whose database is not provisioned (archived / failed-provision — precisely the rows the probe exists to skip, `TenantScopedCommand.php:242-252`) forces the gate to fail. A gate that cannot pass is a gate that gets bypassed before a schema-destructive fiscal rebuild.

**Fix:** when `--tenant` is supplied, count only that tenant in `$skipped`.

### R3 [Important] `EnqueueResolvedEventProjectionsCommand.php:182` and `VerifyEventChainCommand.php:199` — the permission-gate actor lookup has no compat-mode tenant predicate

`User::query()->find($actorId)` carries no `tenant_id` predicate, and `App\Modules\Identity\Domain\User` declares no global tenant scope. Every other query in both closures deliberately kept an explicit `tenant_id` predicate described as "load-bearing in single-schema compatibility mode" (`EnqueueResolvedEventProjectionsCommand.php:224-226`, `VerifyEventChainCommand.php:306-317,331`) — this one did not.

Under `tenancy_resolver.db_per_tenant = false` an actor belonging to tenant **B** resolves for a `--tenant=A` run; `setPermissionsTeamId($actor->tenant_id)` (`:198` / `:212`) then evaluates `can()` against **B's** team, so B's roles authorise fiscal recovery/verification work on A's rows. Production runs `db_per_tenant=true`, so the live blast radius is compat/test only — but this is the same gap the Wave-1 review caught in enrichment.

**Fix:** `User::query()->where('tenant_id', $tenantId)->find($actorId)` in both.

### R4 [Important] `d21811a7a` — 4 of the 9 refreshed justifications are FALSE against current code

Each asserts "a bare run … raises 42P01 on the CENTRAL connection", but each command opens `handle()` with a fail-closed `Schema::hasTable()` guard that returns FAILURE with an operator message and never issues a query:

| Annotation | Guard that makes the claim false |
|---|---|
| `app/Console/Commands/BackfillBanksCommand.php:60-63` | `:81-87` |
| `app/Console/Commands/BackfillTolerancePurposesCommand.php:72-74` | `:99-105` |
| `app/Console/Commands/ConfigureCashRoundingCommand.php:65-67` | `:102-113` |
| `app/Console/Commands/SeedChartsCommand.php:53-56` | `:74-80` |

The remaining 5 verify TRUE, including the two the commit flagged as previously-false:
- `BackfillPricingModeCommand.php:21-24` — `products` is a tenant table; no scheduler entry or console loop references it anywhere (`routes/console.php`, `app/`); no `Schema` guard, so 42P01 is accurate.
- `TestE2EGLPosting.php:22-24` — `Tenant` pins the central connection via Stancl's `CentralConnection` trait (`vendor/stancl/tenancy/src/Database/Concerns/CentralConnection.php:9-12`), so "`Tenant::first()` resolves from CENTRAL but `Company::first()` does not" is exactly right.
- `GrirDriftReportCommand.php:15-17`, `RematchDraftSupplierInvoicesCommand.php:19-21`, `TestTaxRecoverability.php:19-21` — no guards, tenant tables, claims hold.

Given the whole point of the corpus is that the annotation is evidence, these four must be corrected (4 docblock lines).

### R5 [Important] The wave has no test in the production tenancy mode

`phpunit.xml:46` sets `<env name="TENANCY_DB_PER_TENANT" value="false" force="true"/>`, so `forEachTenant` never reaches `tenancy()->initialize()` (`TenantScopedCommand.php:209,261-264`) in any of the wave's tests. All 62 assertions across `tests/Feature/Console/OneShotBackfillTenantScopeTest.php`, `tests/Feature/Compliance/FiscalBackfillTenantScopeTest.php`, `tests/Feature/Treasury/AuditDiscountsCommandTest.php`, `tests/Feature/POS/VerifyPosChainCommandTest.php`, `tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php` therefore prove option semantics and compat-mode scoping only — and are structurally blind to B1.

Neither new base helper has a single test under `db_per_tenant=true`: `tests/Unit/Console/TenantScopedCommandForEachTenantTest.php` (which does flip it at `:189,:252,:290,:339,:364`) was last touched at `318406dfb`, before this wave, and contains no reference to `forEachTenantFiltered` / `forEachExplicitlySelectedTenant`.

**Fix:** add helper tests to that file under `db_per_tenant=true`, minimally: (a) `--tenant=X` while tenant Y's probe throws — asserting the intended aggregate; (b) `--all-tenants` N1 propagation; (c) a `pos:verify-chains` regression covering B1 using `tests/Traits/ProvisionsTenantDatabases.php`.

---

## MINOR

- **M1** `app/Console/Commands/MigrateParapharmacyDataCommand.php:91-95,105` — `--limit` is inert. `limit()` does not cap `count()` (SQL `LIMIT` on an aggregate returns the full count), and `chunk()` overwrites limit/offset via `forPage()`. Pre-existing, but the wave's docblock `:36-37` and the `7686be04e` commit message now positively assert "`--limit` becomes a PER-TENANT cap", which the code does not support.
- **M2** `phpstan.neon:19` excludes `app/Console/Commands/MigrateParapharmacyDataCommand.php` from analysis, so the item-7 `console.undefinedOption` clearance does not cover it. Its options are self-declared, so no live defect.
- **M3** `MigrateParapharmacyDataCommand.php:170-171` — `--dry-run` rolls back by throwing inside the transaction; the throw is caught at `:110-114` and now also sets `$tenantExit = FAILURE`, so a dry run prints one "Error migrating product …" per row. Exit code is unchanged vs pre-conversion; cosmetic, but a `--dry-run` carve-out would be cleaner.
- **M4** `app/Modules/Treasury/Presentation/Console/AuditDiscountsCommand.php:91-92` returns the tenancy exit *before* the `--dry-run` always-zero branch at `:101-103`, so the docblock claim at `:46-47` ("the exit-code contract … always zero with `--dry-run` … untouched") is now slightly false. The behaviour is preferable; fix the docblock.
- **M5** `app/Console/Commands/GenerateProductImageVariants.php:36` — `--product=` is declared and never read (also true pre-conversion). `--product=X --all-tenants` silently regenerates the whole fleet. Wire it or drop it.
- **M6** `tests/Feature/Treasury/AuditDiscountsCommandTest.php` covers refusal-without-scope (`:185`) and unknown-tenant (`:192`) but not `--tenant` + `--all-tenants` mutual exclusion; the other six explicit-scope backfills are covered by data providers at `FiscalBackfillTenantScopeTest.php:84` and `OneShotBackfillTenantScopeTest.php:79`. Base-class behaviour, so no live defect.
- **M7** `MigrateParapharmacyDataCommand.php:330,383,432,487` — the dictionary tables (`ingredients`, `key_components`, `health_claims`, `certifications` and their `*_translations`) carry no `tenant_id` column at all (`database/migrations/tenant/2026_01_08_*`), so in compat mode the `findOrCreate*` helpers read/write a fleet-shared dictionary. Unscopable by schema and pre-existing; noted so the `:34-37` "compatibility-mode predicate" claim is read as covering only the metadata selection.

---

## Verified clean

**Item 1 — option plumbing across all 12.** Every command reads its own options and passes the right values:

| Command | Helper | tenant arg | all-tenants arg |
|---|---|---|---|
| `fiscal:preflight-gate` | `forEachTenantFiltered` | `PreflightFiscalGateCommand.php:85` | fleet-default (no flag) |
| `fiscal:verify-chains` | `forEachTenantFiltered` | `VerifyFiscalChainsCommand.php:79` | fleet-default |
| `pos:verify-chains` | `forEachTenantFiltered` | `VerifyPosChainCommand.php:88` | fleet-default |
| `fiscal:enqueue-resolved-event-projections` | `forEachTenant` + manual filter | required at `:154-159`, used `:162` | none by design (`:96-99`) |
| `fiscal:verify-event-chain` | `forEachTenant` + manual filter | required at `:135-140`, used `:167` | none by design (`:83-85`) |
| `fiscal-years:backfill` | `forEachExplicitlySelectedTenant` | `BackfillFiscalYears.php:68` | `:69` (sig `:42`) |
| `fiscal:backfill` | ” | `BackfillFiscalHashesCommand.php:97` | `:98` (sig `:53`) |
| `tolerance:audit-discounts` | ” | `AuditDiscountsCommand.php:72` | `:73` (sig `:54`) |
| `procurement:backfill-goods-receipts` | ” | `BackfillGoodsReceiptsCommand.php:74` | `:75` (sig `:45`) |
| `import:fix-orphaned-products` | ” | `FixOrphanedProducts.php:68` | `:69` (sig `:38`) |
| `products:generate-image-variants` | ” | `GenerateProductImageVariants.php:51` | `:52` (sig `:34`) |
| `parapharmacy:migrate-data` | ” | `MigrateParapharmacyDataCommand.php:74` | `:75` (sig `:45`) |

Mutual exclusion is fail-closed at `TenantScopedCommand.php:363-367` — `self::INVALID` (exit 2), nothing processed; neither-supplied is the same at `:354-361`. Both are exercised by data providers (`FiscalBackfillTenantScopeTest.php:73,84`, `OneShotBackfillTenantScopeTest.php:69,79`). No command can silently run fleet-wide when one tenant was intended: the 7 mutating backfills all require an explicit scope, and the 5 fleet-default verifiers keep the fleet default deliberately and document it.

**Item 2 — N1 fail-closed propagation.** `forEachExplicitlySelectedTenant` (`:352-370`) → `forEachTenantFiltered` (`:308-321`) → `forEachTenant` (`:206-287`). Nothing is swallowed at either layer: a probe-throw on the filtered tenant records it in `skippedTenantIds` and sets `aggregate = FAILURE` (`:225-239`), and `failIfTenantFilterUnvisited` then returns FAILURE with the "does not exist or could not be opened" message (`:423-431`), which `forEachTenantFiltered:320` preserves via `$miss ?? $exit`. Under `--all-tenants` the filter is `null`, the wrapper always calls `$fn`, and `failIfTenantFilterUnvisited(null)` returns `null` (`:419`) — identical semantics to a bare `forEachTenant`. The only divergence from `forEachTenant` is *over*-propagation, recorded as R1.

**Item 3 — leaked central context, remaining 11 commands.** Full sweep of `readonly ConnectionInterface|readonly Connection|readonly DatabaseManager` across `app/`, cross-checked against each command's dependency graph. Only `ReceiptHashService` (B1) is reachable and pinned. `FiscalHashService`, `DocumentNumberingService` have no constructor; `FiscalYearCreationService` injects only `CountryFiscalRulesProvider`; `DiscountToleranceBoundary` / `PaymentToleranceService` hold no connection and no static/instance cache; `FiscalEventProjectionRegistry` materialises projector *instances* at construction (`:112-175`) but the command only calls `name()` on them; `ZReportHashService` resolves the connection at call time (`:257-258`). The two commands 6c07d2730 fixed now resolve via `DatabaseManager::connection()` at call time (`EnqueueResolvedEventProjectionsCommand.php:139-142`, `VerifyEventChainCommand.php:119-122`) — correct.

**Item 4 — compat-mode scoping, all 12 closures.** Correct except R3:
`PreflightFiscalGateCommand.php:191-208` (`pos_receipts.tenant_id`; `pos_z_reports`/`pos_receipt_prints` via the `terminalIdsQuery` subquery `:220-223`; `pos_terminals.tenant_id` with the OR-group correctly parenthesised at `:205-207`) · `VerifyFiscalChainsCommand.php:91-93` + `:183` (company_id, itself tenant-scoped) · `VerifyPosChainCommand.php:212` + `:242,:279,:313` (terminal_id) · `EnqueueResolvedEventProjectionsCommand.php:226` + `:410-412` (fiscal_event_id) · `VerifyEventChainCommand.php:311-317,331,459-462` — its `resolveExpectedPreviousHash` `pos_terminals` read (`:435-437`) is keyed on the UUID PK only, provably unique, and `terminalExistsInBoundTenant` already asserted ownership · `BackfillFiscalYears.php:74-81` · `BackfillFiscalHashesCommand.php:111-113,129,223,243` · `AuditDiscountsCommand.php:124,176` · `BackfillGoodsReceiptsCommand.php:88-92,145,150,158,164` (`po_line_id` reads at `:446,:494` are UUID-FK-unique) · `FixOrphanedProducts.php:74-79,98,105` · `GenerateProductImageVariants.php:57-60` · `MigrateParapharmacyDataCommand.php:80-89` (subquery against the tenant's products; pivots anchored on `product_id`; dictionaries per M7).

**Item 5 — the other 5 re-justifications:** verified TRUE (see R4 table).

**Item 6 — `fiscal:backfill` is NOT production-registered.** `ComplianceServiceProvider.php:67-71` registers only `VerifyFiscalChainsCommand` and `ExportNf525JetCommand`. Repo-wide grep finds no other registration; the only invocation path is the test kernel's `->registerCommand($this->app->make(BackfillFiscalHashesCommand::class))` at `tests/Feature/Compliance/FiscalBackfillTenantScopeTest.php:48-49`. No commit in this wave adds a production registration. Disposition is ticketed, not taken: `docs/superpowers/tickets/2026-08-05-cross-tenant-annotation-ast-check.md:108,133`.

**Item 7 — PHPStan.** `./vendor/bin/phpstan analyse` over `app/Console/TenantScopedCommand.php` + the 12 commands: **zero errors**, no `larastan.console.undefinedOption` (rule present at `vendor/larastan/larastan/src/Rules/ConsoleCommand/UndefinedArgumentOrOptionRule.php:84`; no `undefinedOption` entries in `phpstan-baseline.neon`). The mid-wave 23 errors are resolved by the base taking `bool $allTenants` as a parameter (`TenantScopedCommand.php:352`) and never reading `$this->option('all-tenants')` — the design documented at `:343-348`. Caveat M2 (1 of 12 excluded from analysis).

**Housekeeping.** No `@cross-tenant-by-design` remains on any of the 12; none appears in `tests/Architecture/fixtures/console-command-deferrals.json`. `61189c8b8`'s edit to `tests/Feature/Inventory/GoodsReceiptBackfillTest.php` only adds the now-required `--tenant`; no assertion was weakened. The 5 test files above run green: `OK (62 tests, 151 assertions)`.

---

**Fix before merge:** B1 (`ReceiptHashService` constructor-pinned connection makes `pos:verify-chains` still 42P01 on central), then R1–R5.
