# Ticket — make `@cross-tenant-by-design` a checked claim, not a free-text string

- **Opened:** 2026-08-05
- **Origin:** cat-(b) cross-tenant conversion program, wave 2 (12 operator/one-shot/gate commands)
- **Status:** OPEN — not started
- **Owner:** unassigned

---

## 1. The problem

`tests/Architecture/ConsoleCommandTenantContextTest.php:67-78` and its queue-job
sibling `tests/Architecture/QueueJobTenantContextTest.php` require exactly one
thing of an annotated class: that a class-level `@cross-tenant-by-design` tag
exists and is followed by a non-empty string. Nothing checks whether the string
is *true*.

That is the structural reason the 2026-05-28 database-per-tenant flip broke 18
classes without turning a single test red. Concretely, on 2026-08-05 the
following justifications were **actively false** while the architecture suite
was green:

| Class | The false claim | The reality |
|---|---|---|
| `PreflightFiscalGateCommand` | "Audits all POS fiscal surfaces before schema-destructive fiscal rebuild tasks." | All four probed tables are TENANT tables absent from the CENTRAL connection; `tableCount()` returned 0 for a missing table, so the gate printed **"SERVER SURFACE: clear"** and exited 0 having audited nothing. |
| `VerifyPosChainCommand` | "…the canonical run path is fleet-wide." | `Terminal::active()->get()` on CENTRAL raised 42P01; fleet-wide had been impossible for ten weeks. |
| `AuditDiscountsCommand` | "Pre-deploy CI gate that sweeps the entire documents / document_lines surface … the gate must check the whole dataset before a deploy." | No workflow in `.github/workflows/` invokes `tolerance:audit-discounts` (checked against ci.yml, smoke-test.yml, react-doctor.yml, sonarcloud.yml), and both tables are TENANT tables absent from CENTRAL. Neither half was true. |
| `BackfillPricingModeCommand` | "per-tenant invocation is handled by the caller (scheduled task, console loop over tenants, etc.)" | No scheduled caller and no loop exists. Nothing invokes it. |

Wave 2 fixed the code and rewrote the strings, but the corpus can drift again
the moment the next infrastructure change lands. The annotation needs a
mechanical check behind it.

## 2. Proposed work

### 2a. Companion architecture test (the main deliverable)

Add a test that fails any class carrying `@cross-tenant-by-design` whose body
reaches a tenant-table model or `DB::table()` call **without** one of:

- `extends App\Console\TenantScopedCommand`
- `use App\Jobs\Concerns\BindsTenantContext`
- a literal `tenancy()->initialize(` call in the class body

The AST machinery already exists and does not need to be written from scratch:

- `app/Application/Sweep/Visitors/FindCallVisitor.php` — the sweep scanners'
  call-site visitor; it already parses console/job sources and already knows
  how to recognise the `@cross-tenant-by-design` marker
  (`FindCallVisitor.php:35,549`, `ExistsRuleVisitor.php:27,352` — note those
  string occurrences are the *detector implementation*, not classifications of
  those two classes).
- The tenant-vs-central table split is derivable from the migration tree:
  `database/migrations/tenant/**` = TENANT, `database/migrations/*.php` =
  CENTRAL. Generate the table list rather than hand-maintaining it.

Deferral fixtures (`tests/Architecture/fixtures/console-command-deferrals.json`,
`queue-job-deferrals.json`) should keep working the same way, but a deferral
entry for this new check should require a `reason` field, matching the
controller-deferrals convention.

### 2b. `Schema::hasTable()` grep sweep over console/scheduler paths

`Schema::hasTable()` in a central-context console path is a silent-pass
generator: it converts "the entire database is missing" into "nothing to do".
Two surfaces in the cat-(b) sweep did exactly this —
`ChannelServiceProvider.php:32` (fixed in wave 1, `500f0bcff`) and
`PreflightFiscalGateCommand.php:77` (fixed in wave 2). Sweep the rest:

```
grep -rn "Schema::hasTable" --include='*.php' apps/api/app/Console apps/api/app/Modules/*/Providers \
  apps/api/app/Modules/*/*/Commands apps/api/app/Modules/*/Presentation/Console apps/api/routes/console.php
```

For each hit decide: is a missing table a legitimate boot-order guard, or is it
laundering a missing database into a clean verdict? The latter must fail closed.

## 3. Pre-existing architecture-test failures (context, NOT this ticket's scope)

`tests/Architecture` had **5 failures** before wave 2 and **5 after** — the wave
introduced none and closed none, because none of the unclassified classes fall
in its touched set. For the record:

1. `AuthLifecycleTest::test_every_auth_sanctum_route_group_includes_set_permissions_team` — non-literal middleware array at `app/Modules/POS/routes.php:93`.
2. `ConsoleCommandTenantContextTest::test_every_concrete_artisan_command_is_tenant_classified` — 8 commands carry no classification at all:
   - `App\Console\Commands\ScanPercentScaleDrift`
   - `App\Console\Commands\ExportFrontendPermissionsMap`
   - `App\Console\Commands\ConfigureMethodRepositoryRoutingCommand`
   - `App\Console\Commands\BackfillPayableInstrumentAccountsCommand`
   - `App\Console\Commands\BackfillTaxDetailsCommand`
   - `App\Modules\Product\Presentation\Console\RunEnrichmentCommand`
   - `App\Modules\Treasury\Presentation\Console\BackfillLocationAttributionCommand`
   - `App\Modules\Company\Presentation\Console\BackfillMembershipsCommand`
3. `ControllerTenantContextTest::test_every_controller_method_is_classified` — 5 unclassified controller methods (TenantHealthController::index, NotificationController::{index,unreadCount,markRead,readAll}).
4. `InventoryCostLockCoverageTest` — `GoodsReceiptService::receiveGoods` acquires product advisory locks per-iteration instead of up-front.
5. `QueueJobTenantContextTest` — `SendEnrichmentFeedbackJob` and `SendBrandMappingJob` unclassified.

Item 2 is the natural companion to 2a: several of those 8 are backfills that
almost certainly need the same explicit-scope treatment wave 2 applied
(`BackfillTaxDetailsCommand`, `BackfillPayableInstrumentAccountsCommand`,
`BackfillLocationAttributionCommand`, `BackfillMembershipsCommand`), and
`RunEnrichmentCommand` dispatches `ApplyCatalogEnrichmentJob` and so needs a
bound tenant to dispatch under. They were deliberately left alone: classifying
a command requires auditing what it actually does, which is a wave of its own.

## 4. Also found in wave 2 — needs a disposition

**`fiscal:backfill` (`App\Modules\Compliance\Commands\BackfillFiscalHashesCommand`)
is registered by no service provider.** `ComplianceServiceProvider` wires only
`VerifyFiscalChainsCommand` (`ComplianceServiceProvider.php:69`), and module
commands are not auto-discovered — only `app/Console/Commands/**` is. So the
command cannot be invoked from the CLI at all. `php artisan list` confirms its
absence.

This contradicts the cat-(b) audit's "No class was found to be DEAD" (§2 of
`partA1-cat-b-resweep.md`). Wave 2 converted it anyway (the architecture test
discovers it by file path, so its annotation is part of the corpus) and
exercises the conversion by registering it into the test kernel
(`tests/Feature/Compliance/FiscalBackfillTenantScopeTest.php`). Wiring a
schema-touching backfill into the production CLI surface was judged out of
scope for a conversion wave.

**Decide:** register it in `ComplianceServiceProvider`, or delete it. Leaving an
unreachable retroactive fiscal-hash backfill in the tree is the worst of the
three options — the OWNER checklist's `fiscal:backfill-sealed-hash-algorithm`
step is one typo away from an operator believing they ran it.

## 5. Acceptance

- [ ] Companion AST check exists and fails a deliberately-mislabelled fixture class.
- [ ] The four historically-false justifications in §1 would each have been caught by it (regression fixtures).
- [ ] `Schema::hasTable()` console/scheduler sweep completed; each hit either justified in a comment or converted to fail-closed.
- [ ] `fiscal:backfill` disposition recorded (register or delete).
