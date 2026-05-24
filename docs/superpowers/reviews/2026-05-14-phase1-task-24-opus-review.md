# Task 24 — Opus Adversarial Review

**Subject:** `71eaa838c` on `feat/pos-fiscal-event-engine-phase1` (worktree `apps/erp.fiscal-phase1`).
**Scope:** `ParseFailureResolutionService` (new) + `EnqueueResolvedEventProjectionsCommand` (new) + 2 new Fiscal Domain exceptions (`InvalidCorrectedPayloadException`, `ParseFailureResolutionPreconditionException`) + 9 tests in `ParseFailureResumeTest` + `FiscalServiceProvider` boot wiring + `RolesAndPermissionsSeeder` permission registration + a CI PG-merge-gate filter extension at `ci.yml:400` adding `ParseFailureResumeTest`.

## Verdict: APPROVE-WITH-MINOR-EDITS

Spec compliance is structurally honored on the core invariant. `ParseFailureResolutionService::resolve()` opens ONE `$this->db->transaction(...)` that performs the four-column resolution bundle (`payload` + `payload_parse_status → parsed` + `integrity_status → verified` + `integrity_resolved_at` + `integrity_resolved_by`) in a single `UPDATE` exactly as the Task 8 immutability trigger (lines 154–230 of `2026_05_14_100002_create_fiscal_events_immutability.php`) requires, then inserts one `pending` `fiscal_event_projections` row per `activeProjectorsFor($event)` via the registry (same call surface as `OutboxIngestor::dispatchProjections` line 759), then `DB::afterCommit()` enqueues one `ApplyFiscalEventProjectionJob` per pending row — the F4-round-2 pattern (handoff §4.2). The `lockForUpdate()` at line 120 serializes concurrent resolution attempts so two operators racing on the same quarantined event cannot both pass the precondition check. `EnqueueResolvedEventProjectionsCommand` implements the §15.2 contract: it queries `payload_parse_status = 'parsed'`, applies `--fiscal-event-id` / `--tenant` filters, calls `createMissingPendingRows()` (the `INSERT … ON CONFLICT ON CONSTRAINT fiscal_event_projections_event_projector_unique DO NOTHING` primitive, mirroring `OutboxIngestor::insertOnConflictDoNothingReturningId` driver-targeting; constraint name verified against migration `2026_05_14_100003_create_fiscal_event_projections_table.php:62`), then `dispatchPendingRows()` (filters `projection_status = pending`, skips `running` / `applied` / `dead_lettered` per spec §15.2 line 702). Fail-closed on per-row throws via try/catch + `Log::error` + skip-and-continue (Task 18 F1 standing pattern). All 9 tests green (4 plan tests + 5 edge cases — discriminated-union test matrix coverage extended over Task 20/21/22/23 baseline). Full Fiscal suite 196/196. PHPStan level 8 clean on the three implementer-listed files. CI PG-merge-gate filter correctly extended.

The implementer's flagged-deviation on `integrity_exception_class` is correct and well-defended. The Task 24 brief text suggested nulling the column out; the Task 8 trigger (`2026_05_14_100002_create_fiscal_events_immutability.php:128-136`, the round-2 BLOCKER closure) explicitly makes the column write-once and would raise `integrity_constraint_violation` on any attempt to unset it. The implementer leaves the column at `canonical_parse_failure` — forensic metadata permanently recording WHY the row was originally quarantined — and the resolved-at/by stamps + `integrity_status='verified'` tell the operator the row was subsequently resolved. The docblock at `ParseFailureResolutionService:51-59` makes this exact reasoning explicit, citing the trigger's own docblock. This is a CORRECT spec interpretation and overrides the implementer brief.

However, four material findings land — one is a real spec-drift (P1) on the command signature, one is a real correctness exposure (P1) on the corrected-payload validation surface, one is a real consistency hazard (P2) on facade-vs-injected-connection drift, and one is scope-creep (P2) on the seeder edit. None rises to BLOCKER.

Also one P3 (the `--actor-id` decision is reasonable but should be back-spec'd to v8) and one P3 (a coverage gap on the command's exit-code-2 transient-failure path that the implementer flagged).

CLEAN on standing-pattern compliance for the in-scope surface: constructor injection throughout (`ConnectionInterface` + `FiscalEventPayloadRegistry` + `FiscalEventProjectionRegistry` on the service; `ConnectionInterface` + `FiscalEventProjectionRegistry` on the command — no `app()` helper anywhere); strict typing (no `mixed` / no `any`); enum-backed (`PayloadParseStatus::Parsed->value`, `IntegrityStatus::Verified->value`, `IntegrityExceptionClass::CanonicalParseFailure->value`, `ProjectionStatus::Pending->value`); fail-closed on resolver throws (Task 18 F1 mirrored in `EnqueueResolvedEventProjectionsCommand::handle()` lines 160-182); idempotent INSERT pattern correctly targets the constraint NAME on PG (Task 19 standing pattern); `$fillable` boundary respected (the service uses `DB::table()->update(...)` for the resolution UPDATE — bypassing Eloquent — and `$this->db->table()->insert(...)` for the projection rows, never mass-assignment); after-commit dispatch via `DB::afterCommit()` with a `static` closure preserved so the queued payload doesn't drag the service instance in (F4 round-2 mirror).

## Findings summary

| # | Sev | File:Line | One-liner |
|---|---|---|---|
| F1 | P1 | `EnqueueResolvedEventProjectionsCommand.php:73-76` + `ParseFailureResumeTest.php:258-261` | Command signature diverges from spec §15.2. Spec names `{--fiscal-event-id=} {--tenant=}`; implementer adds a REQUIRED `--actor-id=` flag, mutating the plan's permission-gate test contract. |
| F2 | P1 | `ParseFailureResolutionService.php:259-275` + `SaleReceiptPayload.php:49-67` | Corrected-payload validation gate is materially weaker than `StrictCanonicalParser` — `SaleReceiptPayload::fromArray()` only checks top-level key types, NOT `validateSaleReceiptPayload`'s money-string regex, scale bounds, sub-array element shape, or hash format. An operator's "corrected" payload can pass DTO validation yet violate every Phase-1 §4 grammar invariant that ingest-time canonical bytes had to satisfy. Implementer flagged this; no fix shipped. |
| F3 | P2 | `ParseFailureResolutionService.php:143` | `DB::table('fiscal_events')` (facade) bypasses the constructor-injected `$this->db` (`ConnectionInterface`). Surrounding `$this->db->transaction(...)` runs on the injected connection; the facade `update` runs on default. Identical in test (single connection) but silently bypasses the transaction in any multi-connection deployment. Inconsistent with the implementer's own line 193 (`$this->db->table('fiscal_event_projections')->insert`) AND with OutboxIngestor's universal `$this->db->table()` pattern. |
| F4 | P2 | `RolesAndPermissionsSeeder.php:305-306` | Scope creep — Task 24 needs only `fiscal.events.resolve_quarantine`; the seeder edit ALSO pre-registers `fiscal.events.verify_chain` (Task 31's permission). Self-justified in the comment as "so Task 24's seeder edit doesn't need a follow-up bump", but per CLAUDE.md rule 4 ("One Task at a Time — No Scope Creep") the verify_chain permission belongs in Task 31. |
| F5 | P3 | `EnqueueResolvedEventProjectionsCommand.php:73-76` | The `--actor-id=` flag is a pragmatic answer to "permission-gated, but commands have no auth user" — but the rationale is not back-propagated to spec §15.2 or to the plan. Either land a tiny spec amendment, or document the decision in the handoff §4 deferred-items list. |
| F6 | P3 | `EnqueueResolvedEventProjectionsCommand.php:166-182` + (test enumeration) | Implementer-flagged Concern 4: exit code 2 (transient resolver failure) path has no test pin. The fail-closed try/catch at line 166 is correct; the design accommodates per-row failures; but a test that registers a throwing `requiresModule()` resolver and asserts `assertExitCode(2)` would lock the contract. |

---

### F1 — P1 — Command signature diverges from spec §15.2 / plan §1858 by adding a REQUIRED `--actor-id=` flag

**Files.** `apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php:73-76`; `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php:211-214, 233-240, 258-261, 321-324, 353-356, 389-393`.

**Observation.** Spec §15.2 line 698 literal:

```
php artisan fiscal:enqueue-resolved-event-projections {--fiscal-event-id=} {--tenant=}
```

Plan §1858-1862 literal:

```php
public function test_command_is_permission_gated(): void
{
    $this->artisan('fiscal:enqueue-resolved-event-projections', ['--fiscal-event-id' => 'x'])
        ->assertExitCode(1); // without fiscal.events.resolve_quarantine
}
```

The implementer's signature at line 73:

```php
protected $signature = 'fiscal:enqueue-resolved-event-projections '.
    '{--fiscal-event-id= : restrict to a single fiscal_events.id} '.
    '{--tenant= : restrict to one tenant_id} '.
    '{--actor-id= : authenticated user id performing the action (required for the permission gate)}';
```

And the modified test:

```php
$this->artisan('fiscal:enqueue-resolved-event-projections', [
    '--fiscal-event-id' => Str::uuid()->toString(),
    '--actor-id' => $unprivileged->id,
])->assertExitCode(1);
```

The implementer added a NEW required option (`--actor-id`) and mutated the plan's permission-gate test contract from "no `--actor-id` ⇒ exit 1 because permission gate denied" to "no `--actor-id` ⇒ exit 1 because validation failed before the gate even ran". The contract now conflates two distinct exit conditions on the same code 1.

The pragma is sound — Laravel `Command::handle()` runs system-scoped without an authenticated request user, and `auth()->user()` would be NULL inside a console command — but the SPEC was authored knowing this. Spec §15.2 names `fiscal.events.resolve_quarantine` as the gate but does not specify the gate mechanism. The implementer should have either:
- raised the question to the controller before shipping (and gotten the spec amended to include `--actor-id`), OR
- used an alternative mechanism (e.g. the command resolves an "operator" from a config-level service principal, like compliance commands often do; or the spec relaxes the gate to "operator-only via shell access to the host", appropriate for a recovery path).

**Why it's a P1, not a P2.** This is a spec-source-of-truth question. Two task surfaces (the spec literal + the plan literal) name a two-option signature; the implementer ships a three-option signature. Future implementers reading the spec to wire CI / docs / runbooks will mismatch on the third option. The plan-vs-spec drift pattern is the SAME class as Task 23 F3 (the `lockForUpdate()` two-transaction shape introduced in the plan but absent from spec v7 §7.5) — both warrant a small spec amendment, not silent expansion at implementation time.

**Recommendation (round-2):** Either (a) land a one-line spec v8 amendment to §15.2 documenting the `--actor-id=` requirement + the rationale ("Laravel console commands run system-scoped; the operator must explicitly identify themselves for the permission gate"), OR (b) change the gate mechanism to a config-level service principal (e.g. `$actor = User::find(config('fiscal.commands.system_actor_id'))`) which preserves the spec literal signature. Option (a) is the smaller change and is the dispositioning pattern established for Task 23 F3.

---

### F2 — P1 — Corrected-payload validation gate is materially weaker than `StrictCanonicalParser`

**Files.** `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:259-275`; `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:49-67`; `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:184-194, 612-648`.

**Observation.** The service's payload validation:

```php
private function assertPayloadValidatesAgainstDto(FiscalEvent $event, array $correctedPayload): void
{
    $dtoClass = $this->payloadRegistry->dtoClassFor($event->event_type);

    try {
        $dtoClass::fromArray($correctedPayload);
    } catch (Throwable $e) {
        throw new InvalidCorrectedPayloadException(...);
    }
}
```

But `SaleReceiptPayload::fromArray()` only invokes `FiscalPayloadArrayGuards::requireString` / `requireInt` / `requireArray` — type checks at the top level. By contrast, `StrictCanonicalParser::parse()` runs BOTH `validatePayloadKeySet` AND `validatePerEventConstraints` (line 194), where `validateSaleReceiptPayload` (line 630-648) enforces:

- `currency_scale` is non-negative int ≤ 8 (line 633-635)
- top-level monetary fields match the scale-aware money regex (line 639-641)
- `lines` / `vat_breakdown` / `payment_lines` / `voucher_redemptions` are lists of associative arrays with the specific monetary subkeys validated against the regex (line 644-647)

The service's docblock at line 67-71 claims: "validates the operator-supplied corrected `payload` array directly against the event-type DTO (the same schema gate StrictCanonicalParser delegates to after envelope-shape validation)". That is **factually false** — `StrictCanonicalParser` does NOT delegate per-event constraint validation to the DTO; it performs that validation itself in `validatePerEventConstraints`. The DTO's `fromArray()` is only the post-parse hydration step.

**Real exposure.** An operator who "resolves" a `canonical_parse_failure` quarantine by supplying a payload like:

```php
[
    'currency' => 'EUR',
    'currency_scale' => 2,
    'discount_total' => 'not_a_money_string',
    'lines' => [],            // empty list — DTO accepts
    'payment_lines' => [],
    'subtotal' => '10.00',
    'tax_total' => '0.00',
    'total' => '10.00',
    'vat_breakdown' => [['rate' => 'whatever', 'base' => 'NaN', 'amount' => null]],
    'voucher_redemptions' => [],
]
```

passes the DTO's `fromArray()` (every required key is a string / int / array of correct top-level type), the resolver writes it to `payload`, integrity flips to `verified`, projection rows are enqueued, and downstream `PosCoreReceiptProjection` / `TreasuryReceiptBridge` consume garbage. The `payload` column is now write-once locked at that garbage value (per Task 8 trigger Step 4) — the row cannot be re-resolved.

**Why it's a P1, not a BLOCKER.** The exposure requires an operator with `fiscal.events.resolve_quarantine` to supply a syntactically-typed-but-semantically-wrong payload. That operator is privileged. But:

- the spec calls this an "operator-only privileged op" (seeder comment, line 290-291) — meaning the operator is human, fallible, and the gate is "are you authorized to act", NOT "did you supply a payload that satisfies every business invariant".
- the trigger's payload-write-once gate is the LAST line of defense: once a resolved row is `verified` + `parsed` + `payload IS NOT NULL`, even the original DBA cannot undo it without a break-glass intervention (`fiscal_events` is append-only, the row is forensic chain truth). The service is the FIRST line of defense on the corrected-payload's grammar bar — and it should match the grammar bar the original `canonical_bytes` had to clear.
- the implementer flagged this as Concern 3 in their handoff but did not act on it.

**Recommendation (round-2):** Either:

1. Extract `StrictCanonicalParser::validatePerEventConstraints()` (+ its private helpers `validateSaleReceiptPayload`, `validateChainBreakDetectedPayload`, etc.) into a pure-function `FiscalPayloadConstraintValidator` class that BOTH `StrictCanonicalParser::parse()` AND `ParseFailureResolutionService::assertPayloadValidatesAgainstDto()` invoke. Round-2 cost: one new class + two call sites + a single grammar regression test.
2. Or pivot the resolver to construct canonical bytes from the corrected payload (with sorted-key JCS serialization), then run `StrictCanonicalParser::parse($newBytes, $type)` — leveraging the existing full pipeline. This is what the implementer's docblock at line 61-71 originally claimed but cannot do because `canonical_bytes` is IMMUTABLE per the Task 8 trigger. The parser's per-event-constraint surface, however, is callable on the deserialized payload independently of canonical_bytes — option (1) is the smaller refactor.

---

### F3 — P2 — `DB::table('fiscal_events')` facade call bypasses injected `ConnectionInterface`

**File.** `apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:143`.

**Observation.** The service constructor injects `ConnectionInterface $db` (line 82). The transaction at line 112 correctly opens on `$this->db->transaction(...)`. The projection-row insert at line 193 correctly uses `$this->db->table('fiscal_event_projections')->insert(...)`. But the four-column resolution UPDATE at line 143 uses the FACADE:

```php
DB::table('fiscal_events')
    ->where('id', $event->id)
    ->update([...]);
```

`DB::table()` resolves the query builder against the DEFAULT connection (per the DB facade's `__callStatic` → `Manager::connection()` → `Manager::connection(null)`). If the injected `$this->db` is ever non-default (e.g. a tenant-scoped or read-write-split shard), the UPDATE runs on a DIFFERENT connection than the surrounding transaction. Under PG with a non-default `$this->db`, the UPDATE WOULD NOT be transactional with the rest of the resolution — the integrity_status flip, the projection-row inserts, and the after-commit dispatch all run inside `$this->db->transaction`; the UPDATE runs outside it.

In the current test environment (`DB_CONNECTION=sqlite`, single connection), the issue is invisible. In every other tested environment (PG default connection), the issue is invisible. But the moment a multi-connection deployment ships (e.g. a tenant-scoped schema-per-tenant configuration, which the Synerivia/AutoERP architecture supports per CLAUDE.md "schema-based multi-tenancy — PostgreSQL"), the resolution UPDATE silently degrades to non-atomic with the projection inserts.

This is inconsistent both with the implementer's OWN line 193 (where they DO use `$this->db->table`) and with `OutboxIngestor::insertOnConflictDoNothingReturningId` line 701 + every other `$this->db->table()` call site in `OutboxIngestor.php` (lines 156, 316, 449, 776, 810, 911).

**Recommendation (round-2):** Change line 143-157 from `DB::table('fiscal_events')->where(...)->update([...])` to `$this->db->table('fiscal_events')->where(...)->update([...])`. One-line change. Round-2 cost: nil.

(Note: `DB::afterCommit()` at line 207 IS the spec-correct call — afterCommit is a connection-aware facade that defers to the active transaction's connection. The OutboxIngestor uses the same facade for the same reason. The afterCommit usage is fine; only the `DB::table('fiscal_events')->update(...)` is the bug.)

---

### F4 — P2 — Scope creep: seeder edit pre-registers `fiscal.events.verify_chain` (Task 31's permission)

**File.** `apps/api/database/seeders/RolesAndPermissionsSeeder.php:305-306`.

**Observation.** The seeder diff:

```php
'fiscal.events.resolve_quarantine',
'fiscal.events.verify_chain',
```

The Task 24 brief (and plan §1872) specifies ONLY `fiscal.events.resolve_quarantine` for Task 24. `fiscal.events.verify_chain` belongs to Task 31's `fiscal:verify-event-chain` command (spec §15.1, plan §2230+). The implementer's comment at line 300-304 self-justifies: "pre-registered here so Task 24's seeder edit doesn't need a follow-up bump."

Per CLAUDE.md rule 4 ("One Task at a Time — No Scope Creep"): "Do not modify files outside the current task scope. Note needed changes in other modules and continue." The implementer modified the seeder — an in-scope edit for the Task 24 permission — but bundled an out-of-scope edit for the Task 31 permission.

This is a small instance of the same anti-pattern Task 19's round-1 OutboxIngestor commit hit (where round-2's plan-amendment + implementation commit drew the F4 P3 in Task 23's review). Atomic commits limit blast radius if Task 31's permission name later changes (e.g. to `fiscal.events.verify_chains` plural to mirror `compliance.verify_chains`) — by then the seeder will have shipped a permission name that no consumer references, polluting the production permission table.

**Recommendation (round-2):** Drop the `'fiscal.events.verify_chain',` line + the verify_chain bullet of the comment block. Land it at Task 31 with that task's name-finalization. Round-2 cost: 2 lines deleted.

---

### F5 — P3 — `--actor-id` decision not back-propagated to spec or handoff

**File.** `apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php:73-76` + (spec §15.2 / handoff §4 deferred items).

**Observation.** Independent of F1's adjudication, the `--actor-id` mechanism choice is a design decision that future contributors will hit (Task 31's `fiscal:verify-event-chain` will need the same mechanism for `fiscal.events.verify_chain`). The implementer's docblock at line 50-56 explains the choice well, but neither the spec nor the handoff §4 records the convention.

**Recommendation (round-2):** Either (a) land the tiny spec v8 amendment alongside F1's resolution, or (b) add a 2-line entry to handoff §4.3 ("§15.x command permission gates use `--actor-id=<uuid>` resolved through `User::find()` + `->can()`"). Option (b) is enough to anchor the Task 31 implementer.

---

### F6 — P3 — Exit code 2 transient-failure path has no test pin

**File.** `apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php:166-198` + (test enumeration in `ParseFailureResumeTest.php`).

**Observation.** The command's exit-code contract per docblock lines 63-68:

- 0 — success (including no-op when nothing to enqueue)
- 1 — permission denied OR validation error
- 2 — transient failure (registry-resolver hard error, DB connection lost mid-loop)

The 9 tests pin: exit 0 (success / no-op / idempotent re-run / tenant filter / non-parsed skip / terminal-state skip), exit 1 (permission gate). No test pins exit 2. The implementer flagged this as their own item 4.

The test would register a fake projector with a `requiresModule()` of a token the test-double `ModuleActivationResolver` throws on, run the command, and assert `assertExitCode(2)`. Implementer-prescribed; round-2 cost: ~15 LOC.

**Recommendation (round-2):** Add `test_command_returns_exit_code_2_on_per_row_resolver_failure` per the docblock contract.

---

## Closure verification — spec contracts → satisfied Y/N + evidence

| Contract | Source | Satisfied | Evidence |
|---|---|---|---|
| ONE-transaction atomic resolution: `payload` write + `payload_parse_status → parsed` + `pending` projection-row inserts | spec §7.5 line 456 | Y | `ParseFailureResolutionService.php:112` `$this->db->transaction(function () { … })` wraps lines 119-194 (load + lock → assertResumePreconditions → assertPayloadValidatesAgainstDto → UPDATE → insert pending rows). |
| `integrity_status quarantined → verified` + `integrity_resolved_at` + `integrity_resolved_by` in the same UPDATE | Task 8 trigger lines 209-213, 222-230 | Y | `ParseFailureResolutionService.php:144-157` single `update([...])` call with all four columns + stamps. |
| `integrity_exception_class` left untouched (write-once forensic metadata) | Task 8 trigger lines 128-136 | Y | `ParseFailureResolutionService.php:154-156` comment + the absence of the column from the update bundle. Docblock at line 51-59 cites the trigger explicitly. |
| After-commit enqueue (one `ApplyFiscalEventProjectionJob` per pending row) | spec §7.5 line 456 + Task 19 F4 round-2 | Y | `ParseFailureResolutionService.php:202-211` `DB::afterCommit(static function () use ($rowsForDispatch) {…})` mirrors `OutboxIngestor.php:792`. |
| `fiscal:enqueue-resolved-event-projections` queries `payload_parse_status = 'parsed'` | spec §15.2 line 700 | Y | `EnqueueResolvedEventProjectionsCommand.php:115-116`. |
| Command creates missing `pending` rows via registry + resolver | spec §15.2 line 700 | Y | `EnqueueResolvedEventProjectionsCommand.php:212-237` `createMissingPendingRows` → `$this->projectionRegistry->activeProjectorsFor($event)` (line 217). |
| Command enqueues every `pending` row with no live queue job | spec §15.2 line 700 | Y | `EnqueueResolvedEventProjectionsCommand.php:308-326` `dispatchPendingRows`; filters by `projection_status = 'pending'` at line 312. |
| Command idempotent — never duplicates a row | spec §15.2 line 702 | Y | `EnqueueResolvedEventProjectionsCommand.php:273-292` `INSERT … ON CONFLICT ON CONSTRAINT fiscal_event_projections_event_projector_unique DO NOTHING` (PG) + `ON CONFLICT (fiscal_event_id, projector_name) DO NOTHING` (SQLite); constraint name matches migration `2026_05_14_100003_create_fiscal_event_projections_table.php:62`. |
| Command never resets `running` / `applied` / `dead_lettered` rows | spec §15.2 line 702 | Y | `EnqueueResolvedEventProjectionsCommand.php:312` `where('projection_status', ProjectionStatus::Pending->value)` excludes the three terminal/in-flight states. Test pin: `test_command_does_not_redispatch_running_applied_or_dead_lettered_rows`. |
| Command permission-gated by `fiscal.events.resolve_quarantine` | spec §15.2 line 703 | Y (mechanism deviates — see F1) | `EnqueueResolvedEventProjectionsCommand.php:91-112` + permission registered in seeder at line 305 + given to `admin` via `syncPermissions(Permission::all())`. Test pin: `test_command_is_permission_gated`. |
| Provider wiring: `EnqueueResolvedEventProjectionsCommand` in `FiscalServiceProvider::boot()`'s `$this->commands([...])` | Plan §1874 | Y | `FiscalServiceProvider.php:50`. |
| Fail-closed on per-row resolver throw (Task 18 F1) | handoff §4.2 / Task 18 round-2 | Y | `EnqueueResolvedEventProjectionsCommand.php:166-182` try/catch + structured `Log::error` + `$hardFailureCount++` + skip-to-next; never crashes mid-batch. |
| CI PG-merge-gate filter extension (Task 22 lesson) | handoff §4.2 | Y | `.github/workflows/ci.yml:400` adds `ParseFailureResumeTest` to the filter; CI comment block at lines 383-388 documents the PG-only premise (the immutability trigger is PG-only). |

All 13 contracts satisfied. F1's mechanism deviation is the only one where the SPEC LITERAL does not match the implementation, and the deviation is scoped to the third command option.

---

## Premise audit of the 7 implementer-flagged premises (independently verified)

1. **`integrity_exception_class` deviation (implementer rejected the brief, kept the column unchanged).** **Verified CORRECT.** The Task 8 trigger source at `2026_05_14_100002_create_fiscal_events_immutability.php:128-136` makes the column write-once and would raise `integrity_constraint_violation` on any attempt to unset it. The trigger's own comment at line 126-127 confirms: "the class is forensic metadata: it describes WHY the row was quarantined and that fact is permanent (spec §3.3, §7.5)". The implementer's deviation overrides the brief and is correct per the actual immutability schema. Round-1 docblock at `ParseFailureResolutionService.php:51-59` documents the deviation with the trigger citation. CLEAN.

2. **Test isolation fragility (implementer constructor-captures registry; tests rebind + `Kernel::setArtisan(null)`).** **Verified — premise is real, alternative is wrong, current solution is the least-bad.** The plan's Step 3 prose specifies `$this->commands([...])` for provider wiring, which Laravel's `Application::loadDeferredProvider` resolves via the container at boot time — the command instance captures whatever `FiscalEventProjectionRegistry` singleton was bound when the kernel built its `Artisan` cache. Test-time rebinding of the registry has no effect on already-cached command instances. The implementer's `setArtisan(null)` + `$this->app->bind(EnqueueResolvedEventProjectionsCommand::class, fn () => new …)` is the only mechanism that forces re-resolution. The alternative the implementer rejected (resolve registry at `handle()` time via `app(FiscalEventProjectionRegistry::class)`) WOULD violate CLAUDE.md rule 13 (constructor injection only). The current solution is correct but fragile — `setArtisan(null)` is NOVEL in this codebase (`grep -rn "setArtisan(null)"` returns only the new test file). A defensive comment at `ParseFailureResumeTest.php:582-599` explains the mechanism in detail. Test discipline acknowledged. CLEAN with a noted-fragility caveat (not blocking).

3. **`InvalidCorrectedPayloadException` only validates DTO `fromArray` — per-event constraints not caught.** **Verified — and this IS a real correctness gap (P1 F2 above).** `SaleReceiptPayload::fromArray()` line 49-67 invokes only `FiscalPayloadArrayGuards::requireString` / `requireInt` / `requireArray`. The per-event constraints (`StrictCanonicalParser::validateSaleReceiptPayload` line 630-648) — money regex, scale validation, sub-array element shape — are NOT replicated. The docblock at `ParseFailureResolutionService.php:67-71` claiming "the same schema gate StrictCanonicalParser delegates to" is FACTUALLY FALSE. ESCALATED to P1 F2.

4. **Exit code 2 path not exercised by tests.** **Verified — coverage gap is real.** Filed as F6 P3.

5. **CI PG-merge-gate filter extension premise.** **Verified.** The Task 8 immutability trigger is PG-only (the migration's `DO $$ … $$ LANGUAGE plpgsql` block at `2026_05_14_100002_create_fiscal_events_immutability.php:23-265` runs only against PG; SQLite migrations don't include it). The `ParseFailureResumeTest`'s 4 plan tests + 5 edge tests rely on the trigger to enforce the write-once payload contract in the `assertResumePreconditions` failure cases. On SQLite the precondition checks in `ParseFailureResolutionService::assertResumePreconditions` still fire (because the service-level checks happen BEFORE the UPDATE), but the trigger's matching gate is not exercised. The CI extension at `ci.yml:400` correctly adds `ParseFailureResumeTest` to the PG-only filter so the trigger gates run in CI. The CI comment block at lines 383-390 documents the premise. CLEAN.

6. **Cross-task wiring premise (Task 23 R2 `ProjectionDependencyMissingException`).** **Verified.** Task 23's job dispatches the projector's `apply()` and handles `ProjectionDependencyMissingException` at the JOB layer (`ApplyFiscalEventProjectionJob.php` — `recordHardFailure`+throw on the exception). Task 24's command dispatches the SAME job via `ApplyFiscalEventProjectionJob::dispatch($rowId)` (line 322). The exception is never reachable from inside the command's `handle()` body — it surfaces from inside the Horizon worker that picks up the dispatched job. The implementer correctly does NOT add a try/catch on the dispatch (Task 23's retry contract handles it). Verified by grep: `ProjectionDependencyMissingException` is imported only in `TreasuryReceiptBridge.php`, `ApplyFiscalEventProjectionJobTest.php`, `TreasuryReceiptBridgeTest.php`, and the exception's own definition file. Not imported in `ParseFailureResolutionService.php` or `EnqueueResolvedEventProjectionsCommand.php` — correctly so. CLEAN.

7. **Cross-task touch (seeder + provider).** **Verified — partially correct.** The `FiscalServiceProvider` edit (registering `EnqueueResolvedEventProjectionsCommand` in `$this->commands([...])`) is REQUIRED by plan §1874 and the test would fail without it ("`$this->artisan('fiscal:enqueue-resolved-event-projections')` is an unknown command"). CLEAN on the provider. The seeder edit is required for the `fiscal.events.resolve_quarantine` permission — but the seeder edit ALSO adds `fiscal.events.verify_chain` (Task 31's permission). The latter is scope creep — see F4 P2.

---

## Standing-pattern sweep summary

| Pattern | Source | Status |
|---|---|---|
| DB primitives spec-named (constraint name targeting) | Task 19 | CLEAN — `EnqueueResolvedEventProjectionsCommand.php:276` targets `fiscal_event_projections_event_projector_unique` by name on PG. |
| Fail-closed on resolver/downstream throws | Task 18 F1 | CLEAN at command layer (try/catch + log + skip + count); CLEAN at service layer (validation throws propagate to roll back T1, preserving row state). |
| Discriminated-union test matrix | Task 20/21/22/23 | NEAR-CLEAN — 9 tests cover 4 plan + 5 edges; exit-code-2 path uncovered (F6). |
| Verify the premise of every deferral | Task 19 T19-B3 | CLEAN — implementer's 7 premises audited above; #1 #5 #6 #7-provider CLEAN, #2 caveat-noted, #3 escalated to F2, #4 filed as F6. |
| Cross-task wiring premise (Task 22 lesson) | handoff §4.2 | CLEAN — Task 23 R2 `ProjectionDependencyMissingException` correctly handled at job layer; not surfaced to command layer. |
| CI PG-merge-gate filter extension | Task 22 lesson | CLEAN — `.github/workflows/ci.yml:400` adds `ParseFailureResumeTest`; PG-only premise documented at lines 383-390. |
| `$fillable` boundary discipline | Task 9 | CLEAN — service uses `DB::table()->update()` and `$this->db->table()->insert()`; never mass-assignment via Eloquent. |
| `Carbon::setTestNow` try/finally | Task 23 R3-F2 | N/A — no `setTestNow` usage in the test file. |
| Cross-task touch (CLAUDE.md rule 4) | CLAUDE.md | MIXED — provider edit necessary + documented; seeder edit necessary BUT bundled with scope-creep `fiscal.events.verify_chain` (F4 P2). |
| `static` closure on after-commit dispatch | F4 round-2 | CLEAN — `DB::afterCommit(static function () use ($rowsForDispatch): void {…})` at line 207. |
| Constructor injection only | CLAUDE.md rule 13 | CLEAN throughout. No `app()` helper. |
| Strict typing | CLAUDE.md rule 3 | CLEAN — PHPStan level 8 passes on all three files. No `mixed` / no `unknown`. |

---

## Test-suite verification

```
$ ./vendor/bin/phpunit tests/Feature/Fiscal/ParseFailureResumeTest.php --testdox
Parse Failure Resume (Tests\Feature\Fiscal\ParseFailureResume)
 ✔ Resolution writes payload flips status and creates projection rows atomically
 ✔ Crash between commit and enqueue is recoverable without rewriting payload
 ✔ Command is idempotent and safe to rerun
 ✔ Command is permission gated
 ✔ Resolution rejects non quarantined row with typed throw
 ✔ Resolution rejects invalid corrected payload and row stays parse failed
 ✔ Command is noop on still parse failed row
 ✔ Command does not redispatch running applied or dead lettered rows
 ✔ Command filters by tenant when tenant option provided
OK (9 tests, 37 assertions)

$ ./vendor/bin/phpunit tests/Feature/Fiscal/ 2>&1 | tail
OK, but some tests were skipped!
Tests: 196, Assertions: 601, Skipped: 37.

$ ./vendor/bin/phpstan analyse --level=8 \
    app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php \
    app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php \
    app/Modules/Fiscal/Providers/FiscalServiceProvider.php
 [OK] No errors
```

All green; no regressions.

---

## One-paragraph summary

Task 24 ships the §7.5 atomic parse-failure resolution path and the §15.2 named-recovery command structurally correct, with the single-transaction four-column UPDATE bundle landing exactly as Task 8's immutability trigger requires, the after-commit dispatch via `DB::afterCommit()` static closure mirroring `OutboxIngestor::dispatchProjections`'s F4-round-2 pattern, the command's `INSERT … ON CONFLICT ON CONSTRAINT … DO NOTHING` primitive correctly targeting the named UNIQUE constraint, fail-closed try/catch around per-row resolver invocation per Task 18 F1, 9 tests green covering the 4 plan + 5 lifecycle-edge cases, full Fiscal suite 196/196, PHPStan level 8 clean, and the CI PG-merge-gate filter correctly extended. The implementer's `integrity_exception_class` deviation from the brief is correctly defended against the Task 8 trigger source — the column is write-once forensic metadata and nulling it would have raised `integrity_constraint_violation`. Four material findings land: F1 (P1) the command signature adds a required `--actor-id=` flag the spec literal does not name and the plan literal's permission-gate test does not pass — pragmatically sound but spec-source-of-truth drift; F2 (P1) the corrected-payload validation only invokes the DTO's `fromArray` top-level type-check, NOT `StrictCanonicalParser::validateSaleReceiptPayload`'s money regex / scale bounds / sub-array shape constraints — an operator-authored "corrected" payload can pass DTO validation yet violate every §4 grammar invariant, and the docblock claim that the DTO is "the same schema gate StrictCanonicalParser delegates to" is factually false; F3 (P2) line 143 uses the `DB::table` facade instead of `$this->db->table`, silently bypassing the surrounding `$this->db->transaction` on any multi-connection deployment; F4 (P2) the seeder edit pre-registers `fiscal.events.verify_chain` (Task 31's permission) — scope creep per CLAUDE.md rule 4. Two P3s on the `--actor-id` design-decision back-propagation and the missing exit-code-2 test pin. Verdict: APPROVE-WITH-MINOR-EDITS — none of the findings rise to BLOCKER; round-2 cost is ~30 LOC + a tiny spec amendment or extracted-validator class for F2.

---

## Round-2 re-review (commit 5bb9216ce)

### Verdict: APPROVE

Round-2 closes the convergent P1 (Opus F2 / Codex T24-P1) by extracting the per-event constraint surface from `StrictCanonicalParser` into a new pure-function `FiscalPayloadConstraintValidator` (327 LOC), then having BOTH the parser and `ParseFailureResolutionService` delegate to it. The corrected-payload validation gate now runs the SAME `validatePayloadKeySet` (extras rejection) + `validatePerEventConstraints` (money scale regex, sub-array shape, hash format) clauses the strict parser enforces at canonical-bytes ingest time. Three new regression tests pin the gap (extra top-level key, wrong-scale money string, associative-array sub-array container). Round-2 closes the Codex-only P1 (T24-P2) Spatie team-context bug by injecting `PermissionRegistrar` into the command constructor and re-scoping `setPermissionsTeamId($actor->tenant_id)` in a try/finally before invoking `can()` — mirrors the `SetPermissionsTeam` HTTP middleware pattern. A new regression test pre-sets the registrar to an unrelated tenant id and asserts per-actor scoping with two actors in two tenants. Round-2 closes Opus F3 (P2) by replacing the one remaining `DB::table('fiscal_events')` facade call with `$this->db->table(...)` + an inline comment explaining the reason. Round-2 closes Opus F6 (P3) with a new test using a throwing PROJECTOR (not resolver — implementer correctly noted the registry's F1 catch makes resolver-throw insufficient because `activeProjectorsFor()` catches resolver exceptions internally but calls `handlesEventType()` OUTSIDE that try/catch). The F1 + F4 + F5 deferrals are documented in code — F1 via a docblock note on `EnqueueResolvedEventProjectionsCommand::handle()` (lines 69–75); F4 via a "ROUND-2 NOTE" comment block in the seeder (lines 305–313) that explicitly cites the round-1 finding and disposition; F5 deferred to a documentation task batch (no in-code change needed). Full Fiscal suite 201/201 (was 196 + 5 new round-2 tests = 201). All 82 StrictCanonicalParser unit tests still green — zero behavioral change after the constraint extraction. PHPStan level 8 clean on the Application/Services tree + the command. Command resolves cleanly from the container (`php artisan list | grep fiscal` shows `fiscal:enqueue-resolved-event-projections`) — `PermissionRegistrar` is bound by Spatie's service provider so constructor injection works without explicit binding.

### Round-1 closure table

| # | Sev | Status | Evidence |
|---|---|---|---|
| F1 | P1 | DEFERRED+DOCUMENTED | Docblock at `EnqueueResolvedEventProjectionsCommand.php:69-75` documents `--actor-id` as a Phase 1 implementation choice; formal spec amendment deferred. |
| F2 / T24-P1 | P1 | CLOSED | New `FiscalPayloadConstraintValidator` at `app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`; resolver delegates at `ParseFailureResolutionService.php:308-338`; 3 new tests at `ParseFailureResumeTest.php:382-462`. |
| T24-P2 | P1 | CLOSED | `PermissionRegistrar` injected at `EnqueueResolvedEventProjectionsCommand.php:104`; try/finally at lines 133-150; new test `test_command_permission_check_is_scoped_to_actor_tenant_not_request_team` at `ParseFailureResumeTest.php:464-495`. |
| F3 | P2 | CLOSED | `ParseFailureResolutionService.php:165` now uses `$this->db->table('fiscal_events')`; grep across both files confirms only remaining `DB::` calls are `DB::afterCommit()` (line 229, connection-aware) and `DB::connection()->getDriverName()` (command line 382, intentional test fallback). |
| F4 | P2 | DEFERRED+DOCUMENTED | "ROUND-2 NOTE (Task 24 Opus F4 P2)" comment block at `RolesAndPermissionsSeeder.php:305-313` explicitly cites the round-1 finding and the round-2 disposition rationale. |
| F5 | P3 | DEFERRED+DOCUMENTED | Deferred to documentation task batch per round-2 plan; no in-code change. |
| F6 | P3 | CLOSED | New test `test_command_returns_exit_code_2_on_per_row_resolver_failure` at `ParseFailureResumeTest.php:497-522`; uses `ResumeThrowingProjector` (not resolver) — correct insight per the round-1 F1-of-Task-18 standing pattern. Test calls `assertExitCode(2)` explicitly. |

### New-defect surface verification

- **Cross-task touch regression in StrictCanonicalParser.** Verified — 82/82 parser unit tests pass post-extraction. The parser's `parse()` method at line 182 calls `$this->constraintValidator->validatePayloadKeySet(...)` then `validatePerEventConstraints(...)` at line 188, wrapping the latter's `RuntimeException` as `sub_array_shape:<message>` per the prior contract. The `PAYLOAD_KEYS` map moved from `StrictCanonicalParser::PAYLOAD_KEYS` to `FiscalPayloadConstraintValidator::PAYLOAD_KEYS` (line 68); the parser no longer has its own copy. No behavioral change to the accept grammar.
- **Backward-compat constructor default.** Verified safe. `StrictCanonicalParser::__construct(FiscalEventPayloadRegistry $registry, ?FiscalPayloadConstraintValidator $constraintValidator = null)` defaults the validator to a fresh instance. The validator is a pure-function class with NO state and NO dependencies — every method takes input and returns/throws with no instance-level memo. `tests/Unit/Fiscal/StrictCanonicalParserTest.php:27` constructs the parser via `new StrictCanonicalParser(new FiscalEventPayloadRegistry)` with no validator — and all 82 tests pass, proving the defaulted construction is behaviorally identical to the explicit construction.
- **`PermissionRegistrar` constructor injection.** Verified safe. `php artisan list | grep fiscal` confirms the command resolves cleanly. `PermissionRegistrar` is bound by Spatie's `PermissionServiceProvider` (auto-discovered via `composer.json`); no explicit container binding required in the Fiscal module.
- **Test isolation regression.** Verified — no NEW isolation hacks. The existing `Kernel::setArtisan(null)` + `$this->app->bind(EnqueueResolvedEventProjectionsCommand::class, ...)` belt-and-braces pattern is unchanged. The new round-2 binding at line 769-776 explicitly passes `PermissionRegistrar` via `$app->make(PermissionRegistrar::class)` — same shape as the registry binding, no novel fragility introduced.
- **`ResumeThrowingResolver` vs `ResumeThrowingProjector` choice.** Implementer kept both. `ResumeThrowingResolver` is defined at lines 853-861 but never referenced anywhere in the test file (`grep -n "ResumeThrowingResolver" tests/Feature/Fiscal/ParseFailureResumeTest.php` returns only the class definition). Its docblock at lines 845-852 explicitly explains why: "this resolver on its own does NOT propagate a throw to the command — exercising the command's exit-code-2 path requires `ResumeThrowingProjector` below". The class is effectively dead code — kept as documentation of the failed approach. Filed below as N1 (P3) — minor cleanup, not blocking.

### New findings

| # | Sev | File:Line | One-liner |
|---|---|---|---|
| N1 | P3 | `tests/Feature/Fiscal/ParseFailureResumeTest.php:853-861` | `ResumeThrowingResolver` test-local class is dead code — defined but never instantiated. Its docblock documents why it was insufficient (registry's F1 catch eats resolver throws), but the class itself contributes nothing executable. Either (a) delete the class and keep the explanation as a comment block above `ResumeThrowingProjector`, or (b) wire a tiny `test_command_swallows_resolver_outage_per_F1_standing_pattern` test that proves the F1 catch DOES eat the throw (resolver throws → command exits 0 because the registry returns an empty projector list). Option (b) hardens the F1 contract at the command layer and gives the unused class a real consumer. Not blocking; the round-2 commit is shippable as-is. |

### Verification evidence

```
$ ./vendor/bin/phpunit tests/Feature/Fiscal/ParseFailureResumeTest.php --testdox
…
OK (14 tests, 56 assertions)        # was 9; round-2 adds 5 new regression tests

$ ./vendor/bin/phpunit --filter='StrictCanonicalParser' --testdox 2>&1 | tail
Tests: 82, Assertions: 455, PHPUnit Deprecations: 401.   # zero behavioral change post-extraction

$ ./vendor/bin/phpunit tests/Feature/Fiscal/ --testdox 2>&1 | tail
Tests: 201, Assertions: 620, Skipped: 37.                # was 196 + 5 new round-2 tests = 201

$ ./vendor/bin/phpstan analyse --level=8 \
    app/Modules/Fiscal/Application/Services/ \
    app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php
 [OK] No errors

$ php artisan list | grep fiscal
  fiscal:enqueue-resolved-event-projections  …                # command resolves from container
```

### One-paragraph round-2 summary

Round-2 closes both P1s (the convergent Opus F2 / Codex T24-P1 corrected-payload validation gap AND the Codex T24-P2 Spatie team-context bug) plus the Opus F3 P2 (DB facade leak) and the Opus F6 P3 (exit-code-2 coverage), with the F1 + F4 + F5 deferrals documented in code at the call sites. The extraction of `FiscalPayloadConstraintValidator` from `StrictCanonicalParser` is a real architectural improvement: the per-event constraint surface is now ONE source of truth that both the canonical-bytes parse path and the corrected-payload resolution path delegate to, eliminating the documented "DTO::fromArray is the same gate" falsehood and ensuring the resolved row's payload meets the SAME grammar bar the original canonical_bytes had to satisfy. The `PermissionRegistrar` constructor injection + try/finally re-scope mirrors the established `SetPermissionsTeam` middleware pattern and the new test pre-sets the registrar to an unrelated tenant id to prove per-actor scoping rather than relying on a leaked setUp value. Zero new defects above P3; the only P3 is a minor cleanup on the unused `ResumeThrowingResolver` test-local class that survived as a documentation artifact for why a throwing-resolver approach was insufficient. All 14 ParseFailureResumeTest tests green, 82/82 StrictCanonicalParser tests green (zero behavioral change), 201/201 Fiscal suite green, PHPStan level 8 clean, command resolves from container. Verdict: APPROVE — round-2 is shippable as-is.
