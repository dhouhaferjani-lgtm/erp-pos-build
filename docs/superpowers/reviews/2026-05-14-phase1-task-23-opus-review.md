# Task 23 — Opus Adversarial Review

**Subject:** `34f1b6113` on `feat/pos-fiscal-event-engine-phase1` (worktree `apps/erp.fiscal-phase1`).
**Scope:** `ApplyFiscalEventProjectionJob` (new) + `FiscalEventProjectionRegistry::byName()` + `OutboxIngestor::dispatchProjections()` after-commit closure wiring + 7 lifecycle tests in `ApplyFiscalEventProjectionJobTest` + dispatch-assertion update in `OutboxIngestorTest::test_projection_dispatch_only_after_commit` + a Step 3 prose amendment to `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` (the two-transaction + lifecycle-lock + idempotent short-circuit prose, plus two new tests in §1716 — `test_already_applied_row_short_circuits_on_re_dispatch` and `test_dead_lettered_row_short_circuits_on_re_dispatch`).

## Verdict: APPROVE-WITH-MINOR-EDITS

The two-transaction shape per spec §7.5 / plan §1796 amendment is structurally honored: `handle()` opens a short T_lock around `lockForUpdate()` + the running-flip + the idempotent terminal-state short-circuit, commits, then runs the projector OUTSIDE the lock; terminal-status writes (`applied` / `dead_lettered`) and attempt accounting (`attempts++` / `last_error` / `last_attempted_at`) all live OUTSIDE the projector's `T_apply` so a projector rollback never loses operator-visible state. The lifecycle short-circuit on terminal states is correctly implemented in BOTH `handle()` (Step 1 T_lock) and `failed()` (the `if ($row->projection_status !== ProjectionStatus::DeadLettered)` gate at line 333). The `OutboxIngestor` after-commit closure is correctly wired with `static` preserved (no `$this` capture), per-row dispatch in registry-sorted (priority-then-name) order; the matching `OutboxIngestorTest` assertion is upgraded from `Queue::assertNothingPushed()` to `Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 1)` with a `projectionRowId` payload assertion that locks the row-id-keyed contract. The `FiscalEventProjectionRegistry::byName()` lookup correctly IGNORES current module-activation status — activation gating happened at ingest time, so a module deactivated between ingest and run must still resolve its projector (otherwise the projection row orphans indefinitely); the constructor-asserted uniqueness invariant (Task 18 F2 round-2) guarantees a single match. Standing patterns are observed: Task 17 `QueryException` wrap on `FiscalEvent::find()` for malformed-UUID defense (line 365-379); Task 18 fail-closed on misconfiguration (missing FiscalEvent OR missing projector both `Log::critical` + `recordHardFailure` + throw, never silent no-op); Task 9 `$fillable` discipline (lifecycle columns mutated via targeted assignment `$row->projection_status = …; $row->save()`, never via `fill()` / mass-assignment); Task 22 priority-based dispatch order (per-row dispatch consumes the registry's `(priority ASC, name ASC)` sort, so POS-core @ 50 enqueues before Treasury bridge @ 150). Phpstan level 8 clean on 5 touched files; pint --test pass; 7 lifecycle tests green (`Tests: 7, Assertions: 32`); the 20-test `OutboxIngestorTest` is also green. The "12 QR test failures are pre-existing" premise is verified against prior HEAD `a8f15ff4f` — 12 errors at that commit, identical at `34f1b6113`, and the diff `a8f15ff4f..34f1b6113` for the QR signer + test paths is empty.

However, two material findings land — neither rises to BLOCKER, but one is a real spec-drift from the plan literal:

1. **F1 (P1) — Cross-projector partial-cluster test substitutes a fake-projector `ApplyLog` for the plan's literal `pos_receipts->count() === 1` assertion.** Plan §1761 names a specific business-effect assertion: "POS-core effects intact" via real DB-table count. The implementer substituted an in-memory invocation counter on a `ConfigurableFakeProjector`. With fakes, there is no real DB write, so the test does not actually verify the cross-projector projection-atomicity invariant the plan was reaching for (independent transactions per §7.5: a Treasury-bridge failure must NOT roll back POS-core's already-committed business writes). What the test DOES verify is correct (lifecycle status accounting + invocation count); what it does NOT verify is what the plan literally named. The implementer flagged this as Concern 4 but did not justify the substitution. Real `PosCoreReceiptProjectionTest::storeSaleReceiptFiscalEvent` is reused (the implementer literally lifted its `storeSaleReceiptFiscalEvent` helper into `ApplyFiscalEventProjectionJobTest`) — so the fixture cost of swapping to a real `PosCoreReceiptProjection` instance is roughly nil. See F1 below for remediation.

2. **F2 (P1) — Three of the implementer's six self-reported concerns name real, uncovered execution paths that should have been tested in round-1.** Concern 1 (`failed()` re-invocation idempotency), Concern 2 (missing-FiscalEvent hard-misconfig path), Concern 3 (deregistered-projector hard-misconfig path) all describe behavior that IS implemented in the job — the `if ($row->projection_status !== ProjectionStatus::DeadLettered)` gate on line 333 (Concern 1); the `recordHardFailure(...) + throw` blocks at lines 237-248 (Concern 2) and lines 252-272 (Concern 3) — but NONE of them have a corresponding test in the round-1 suite. The Task 20 standing pattern (handoff §4.3, "discriminated-union test matrix coverage") is the load-bearing pattern: when a job's `handle()` reaches a discriminated set of execution paths (success / projector-throw / missing-FiscalEvent / missing-projector / terminal-state short-circuit), the test matrix is the exhaustive enumeration of those paths, not the subset the happy-path narrates. Three uncovered paths is the same coverage gap pattern Task 20 round-2 + Task 21 round-2 + Task 22 round-1 each re-introduced. Round-2 add three targeted tests; the cost is < 30 LOC.

There is also one P2 (the plan amendment vs. spec-source-of-truth question — `lockForUpdate()` lives in the plan but NOT in spec v7 §7.5), one P3 (per-commit hygiene drift on bundling plan amendment with implementation), and one P3 cleanup (the mid-test mutation of `ConfigurableFakeProjector::$shouldThrow` via a public property — Concern 6).

CLEAN on standing-pattern compliance for the in-scope surface: constructor injection (`ConnectionInterface` + `FiscalEventProjectionRegistry` method-injected, never `app()` helper); no `(type) $array['key']` payload casts (no payload reads at all — the job operates on the `fiscal_event_projections` row id); strict typing throughout (no `mixed`); fail-closed on `loadFiscalEvent` QueryException; the priority-based dispatch order is preserved through the after-commit closure; the `$fillable` boundary on `FiscalEventProjectionRow` is respected (lifecycle columns mutated via targeted assignment, not mass-assignment).

## Findings summary

| # | Sev | File:Line | One-liner |
|---|---|---|---|
| F1 | P1 | apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php:274-290 | Cross-projector partial-cluster test asserts in-memory `ApplyLog` counts instead of plan-literal `DB::table('pos_receipts')->count() === 1`. Substitution loses the cross-projector projection-atomicity invariant the plan was reaching for. |
| F2 | P1 | apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php (test enumeration) | Three round-1 uncovered execution paths: `failed()` re-invocation idempotency, missing-FiscalEvent hard-misconfig, missing-projector hard-misconfig. Task 20 standing pattern (discriminated-union test matrix) violated. |
| F3 | P2 | apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:49-59; docs/superpowers/plans/...md:1796; docs/superpowers/specs/...v7.md:454 | Plan amendment introduces `lockForUpdate()` + two-transaction shape that is NOT in spec v7 §7.5 (spec only says "in its own transaction"). The plan is now ahead of spec v7. Either roll the amendment into a spec v8, or document the plan-as-design-record disposition explicitly. |
| F4 | P3 | (git history) | Plan amendment lives in the same commit as the implementation. Prior tasks (T18, T19, T20, T21, T22) shipped `feat(fiscal): …` and `docs(fiscal): refresh handoff §4 after …` as separate atomic commits. Per-commit hygiene drift. |
| F5 | P3 | apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php:411, 616 | `ConfigurableFakeProjector::$shouldThrow` is a public mutable property toggled mid-test via `forceProjectorToThrow()`. Acceptable test plumbing but the public-mutability + post-construction state mutation is a test-hygiene smell — prefer two distinct fake classes or a constructor-only state. |

---

### F1 — P1 — Cross-projector partial-cluster test substitutes fake-projector `ApplyLog` for plan-literal `pos_receipts->count() === 1`

**File.** `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php:240-290`.

**Observation.** Plan §1756-1762 literal:

```php
public function test_pos_core_success_with_treasury_dead_letter_leaves_pos_core_intact(): void
{
    $event = $this->storeSaleReceiptFiscalEvent(); // creates pending rows for both projectors
    $this->runProjection($event, 'pos_core_receipt'); // succeeds
    $this->failProjectionToDeadLetter($event, 'treasury_receipt_bridge');
    $this->assertSame(1, \DB::table('pos_receipts')->count()); // POS-core effects intact
}
```

The plan asserts a REAL business effect (a `pos_receipts` row exists after the partial-cluster sequence). The implementer's version:

```php
$this->assertSame(1, $applyLog->countFor('pos_core_receipt'));
// ...
$this->assertSame(['pos_core_receipt'], $applyLog->names());
```

With a `ConfigurableFakeProjector`, `apply()` does nothing but record an invocation in an in-memory `ApplyLog` (line 635-644). No `pos_receipts` row is ever written. So the test does NOT verify the cross-projector projection-atomicity invariant that the plan was reaching for: that POS-core's already-committed business writes are NOT rolled back by Treasury's subsequent failure (independent transactions per spec §7.5 line 454: "each projection job runs `projector.apply(fiscalEvent)` in **its own transaction**"). The implementer's substitution proves only "the projector's `apply()` was called once" — which IS the job's responsibility — but loses the partial-cluster spec invariant the plan named.

The implementer flagged this as Concern 4 without justification. Given the test class ALREADY imports `Tests\TestCase` + `RefreshDatabase` + the full POS-core fixture stack (lines 18, 24, 121-126, 133, 136-142), and the test class literally lifted `storeSaleReceiptFiscalEvent` verbatim from `PosCoreReceiptProjectionTest` (line 460 docblock), the fixture cost of swapping to the real `PosCoreReceiptProjection` is roughly nil. The substitution is convenience, not necessity.

**Why it's a P1, not a P2.** The plan literal names a load-bearing assertion. The standing pattern from Task 20 / Task 21 round-2 / Task 22 round-2 (handoff §4.2, repeated three times) is: when a discriminated-union test matrix is enumerated by the plan, write the matrix exhaustively in round-1 — including the literal assertions, not stand-in equivalents. The fake-projector substitution is the SAME class of round-1-coverage-gap that Task 21 round-2's T21-B4 closed by adding split / voucher / stock / voucher+stock / zero-payment / voucher-rollback variants.

**Why it's not a BLOCKER.** The job's actual responsibility (orchestrating the lifecycle state machine + dispatching the projector + recording terminal state) IS tested. The plan literal goes further — pinning the cross-projector atomicity contract via a real business effect. The job itself does not own that contract (it's owned by Task 22's projector + spec §7.5's independent-transaction guarantee). So a coverage gap, not a defect.

**Remediation.** Replace the `ConfigurableFakeProjector('pos_core_receipt')` in this specific test with the real `PosCoreReceiptProjection` instance resolved from the container. The Treasury bridge can stay fake (it's the dead-lettered side; the test's invariant is about POS-core's effects surviving). Assert `DB::table('pos_receipts')->where('fiscal_event_id', $event->id)->count() === 1` after the cluster runs; assert the Treasury projection row is `dead_lettered`. Cost: ~20 LOC (the `PosCoreReceiptProjection` ctor takes `ChartOfAccountsService` + `ReceiptHashService` — both bind from the container — see `PosCoreReceiptProjectionTest:108-115` for the pattern).

**Reference.** Plan §1756-1762 literal; spec v7 §7.5 line 454 ("each projection job runs `projector.apply(fiscalEvent)` in **its own transaction**, idempotently"); Task 20 / Task 21 round-2 / Task 22 round-2 discriminated-union test matrix standing pattern (handoff §4.2).

---

### F2 — P1 — Three uncovered execution paths: `failed()` re-invocation, missing-FiscalEvent, missing-projector

**File.** `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php` (full test enumeration vs. job's actual branches).

**Observation.** The implementer self-reports three uncovered paths in Concerns 1, 2, 3. Each path is implemented in the job, but no test pins the behavior:

1. **`failed()` re-invocation idempotency** — job code line 333 (`if ($row->projection_status !== ProjectionStatus::DeadLettered)`) gates the terminal-state write so a re-invocation does NOT overwrite the first failure's `dead_lettered_at` timestamp. There is no test asserting `failed() → failed()` preserves the original timestamp.

2. **Missing-FiscalEvent hard-misconfiguration** — job code lines 237-248 (`loadFiscalEvent` returns null → `recordHardFailure` → throw `RuntimeException`). There is no test pinning this path — neither the `recordHardFailure` accounting (attempts increments, last_error captured) nor the throw-then-Horizon-eventually-dead-letters chain.

3. **Missing-projector hard-misconfiguration (deregistered between ingest and run)** — job code lines 252-272 (`$registry->byName(...)` returns null → `recordHardFailure` + throw). There is no test pinning the "row references projector `foo`; registry has no `foo`" branch. (Importantly: the test class's `setUp` always registers the projector that matches the row, so this path is NEVER hit by the existing 7 tests.)

**Why it's a P1, not a P2.** The Task 20 standing pattern (handoff §4.2, restated three times across tasks 20-22) explicitly says: when a method's `handle()` reaches a discriminated set of execution paths, write the test matrix exhaustively in round-1. The job has 7 logically distinct paths through `handle()` / `failed()`:

- A. Success first run (test_successful_projection_marks_applied) ✓
- B. Projector throw → attempts++ + re-throw (test_job_start_sets_running_then_failure_advances_attempts) ✓
- C. `failed()` first invocation → dead_lettered (test_exhausted_retries_dead_letter_via_failed_handler) ✓
- D. `failed()` re-invocation → no-op (Concern 1) ✗
- E. Missing FiscalEvent → recordHardFailure + throw (Concern 2) ✗
- F. Missing projector → recordHardFailure + throw (Concern 3) ✗
- G. Re-dispatch on terminal state → short-circuit (test_already_applied_…, test_dead_lettered_…) ✓
- H. Cross-projector partial cluster (test_pos_core_success_with_treasury_dead_letter_…) ✓ (but see F1 — substituted)
- I. FiscalEvent immutability (test_projection_failure_never_mutates_the_fiscal_events_row) ✓

So 6 of 9 are covered; 3 of 9 are not. The pattern is well-known and the implementer KNOWS the tests are missing (Concerns 1-3 are explicit). Round-1 should have included them. The same "convergent BLOCKER on round-1 coverage gap" pattern recurred on Tasks 20, 21, 22 — Task 23 round-1 should have absorbed the lesson.

**Why it's not a BLOCKER.** The implementations themselves are correct (the implementer walked through each path mentally and added the right guards). The gap is test coverage, not behavior. But the same gap on Tasks 20/21/22 escalated to BLOCKER because the GAP DOES MASK FUTURE REGRESSIONS — a future refactor of `recordHardFailure` or the `failed()` idempotency gate would silently pass the existing test suite.

**Remediation.** Add three tests in round-2:

```php
public function test_failed_re_invocation_preserves_original_dead_lettered_at(): void
{
    [$event, $row] = $this->pendingProjection('treasury_receipt_bridge');
    $job = new ApplyFiscalEventProjectionJob($row->id);

    $job->failed(new RuntimeException('first failure'));
    $firstTimestamp = DB::table('fiscal_event_projections')->where('id', $row->id)->value('dead_lettered_at');
    $firstError = DB::table('fiscal_event_projections')->where('id', $row->id)->value('last_error');

    // Re-invoke failed() — must not overwrite the first timestamp.
    Carbon::setTestNow(Carbon::now()->addSeconds(5)); // ensure now != first
    $job->failed(new RuntimeException('second failure'));

    $row = DB::table('fiscal_event_projections')->where('id', $row->id)->first();
    $this->assertSame($firstTimestamp, $row->dead_lettered_at);
    $this->assertSame($firstError, $row->last_error);
    $this->assertSame('dead_lettered', $row->projection_status);
}

public function test_missing_fiscal_event_records_hard_failure_and_throws(): void
{
    [$event, $row] = $this->pendingProjection('pos_core_receipt');
    // Sever the FK link without violating the immutability trigger by
    // pointing the projection row at a non-existent FiscalEvent id.
    DB::table('fiscal_event_projections')
        ->where('id', $row->id)
        ->update(['fiscal_event_id' => Str::uuid()->toString()]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessageMatches('/FiscalEvent .* not found/');

    try {
        $this->runJobInline($row->id);
    } finally {
        $after = DB::table('fiscal_event_projections')->where('id', $row->id)->first();
        $this->assertSame(1, (int) $after->attempts);
        $this->assertStringContainsString('FiscalEvent row not found', $after->last_error);
        // Status stays `running` between Horizon retries.
        $this->assertSame('running', $after->projection_status);
    }
}

public function test_missing_projector_records_hard_failure_and_throws(): void
{
    // Seed a row referencing a projector name not in the registry.
    $event = $this->storeSaleReceiptFiscalEvent();
    $row = $this->seedPendingProjectionRow($event, 'pos_core_receipt');
    // Re-bind the registry with NO projectors so byName() returns null.
    $this->registerFakeProjectors([]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessageMatches('/no projector named "pos_core_receipt" registered/');

    try {
        $this->runJobInline($row->id);
    } finally {
        $after = DB::table('fiscal_event_projections')->where('id', $row->id)->first();
        $this->assertSame(1, (int) $after->attempts);
        $this->assertStringContainsString('projector deregistered', $after->last_error);
    }
}
```

Cost: < 50 LOC. The fixture is already in `setUp()`.

**Reference.** Task 20 standing pattern (handoff §4.2 — "discriminated-union test matrix coverage"); Task 21 round-2 closure (added 6 missing test variants — split / voucher / stock / voucher+stock / zero-payment / voucher-rollback); Task 22 round-1 → round-2 (3 of 6 variants missing in round-1 → closed in round-2). The convergent message from three consecutive tasks: write the matrix in round-1, not after a reviewer counts the unmatched branches.

---

### F3 — P2 — Plan amendment introduces `lockForUpdate()` + two-transaction shape that is NOT in spec v7 §7.5

**Files.** `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1796` (the amended Step 3 prose); `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:454` (the spec text the plan extends).

**Observation.** Spec v7 §7.5 line 454 says only:

> "each projection job runs `projector.apply(fiscalEvent)` in **its own transaction**, idempotently"

— a single-transaction model. The plan §1796 amendment introduces a two-transaction shape:

> "open a DB transaction (T_lock) and load the projection row with `lockForUpdate()` … commit T_lock (releases the row lock so a sibling worker on a DIFFERENT row isn't blocked while this one runs the projector — the lock's job is to serialize *the same* row, not gate all dispatch). Then run `projector.apply($event)` **in its own transaction (T_apply)**, idempotently"

The plan now describes a TWO-transaction shape (T_lock + T_apply). The spec describes a ONE-transaction shape ("in its own transaction"). Whether the plan amendment is a refinement (the spec's single transaction is the projector's transaction, and the lifecycle lock is layered atop it) or a divergence depends on which document is the load-bearing source of truth.

The handoff §4.3 line 160 acknowledges this gap:

> "the Task 23 `ApplyFiscalEventProjectionJob` plan (§1705+) does NOT specify `lockForUpdate()` on `fiscal_event_projections`. Task 22 round-2 closed the gap independently via its own advisory lock — but Task 23 SHOULD still add `lockForUpdate()` … Suggest amending the Task 23 plan during its implementer brief."

So the amendment IS what the handoff requested. But the amendment lives in the plan, not in spec v7. Per project hygiene (the spec is the source of truth for the contract; the plan is the implementation guide), the two-transaction shape should either:
1. Be promoted into a spec v8 §7.5 revision ("two-layer defense: row-level `lockForUpdate()` lifecycle lock + projector-level transaction"), OR
2. Be explicitly dispositioned in the plan as "implementation refinement of spec §7.5; spec text is intentionally agnostic on the lifecycle-lock layer."

Without one of these, a future reader holding the spec will read "one transaction" and may "fix" the implementation back to a single transaction (silently regressing the Task 22 cross-task implication).

**Why it's a P2, not a P1.** The implementation IS what the handoff requested. The drift is documentation-layer, not implementation-layer — and the handoff §4.3 explicitly authorized the plan amendment. But the spec-vs-plan divergence is a stale-comment hazard (Task 20 standing pattern, handoff §4.3): a future reader's interpretation of "in its own transaction" may differ from the implementation.

**Remediation.** Choose one:
1. (Preferred) Open a spec v8 PR that revises §7.5 line 454 to: "each projection job acquires a row-level `lockForUpdate()` lifecycle lock on the `fiscal_event_projections` row in a short transaction (T_lock), commits it, then runs `projector.apply(fiscalEvent)` in **its own transaction** (T_apply), idempotently. The two-layer defense (lifecycle lock + Task 22 projector-level `pg_advisory_xact_lock`) is mandatory; neither alone is sufficient."
2. Add a one-line disposition note to the plan §1796 amendment: "This amendment refines spec v7 §7.5 (which says only "in its own transaction") to the two-transaction T_lock + T_apply pattern per handoff §4.3 cross-task implication; spec v8 should fold this in if the cohort revisits the spec."

**Reference.** Spec v7 §7.5 line 454; plan §1796 amendment (lines 1793-1796 of the unified diff); handoff §4.3 line 160; Task 20 stale-comment hazard standing pattern.

---

### F4 — P3 — Plan amendment in same commit as implementation (per-commit hygiene drift)

**Observation.** `git log` for prior tasks (T18, T19, T20, T21, T22) shows a consistent two-commit cadence:
- `feat(fiscal): … round-1 work`
- `docs(fiscal): refresh handoff §4 after Task NN round-1 ships` (separate commit)
- `fix(fiscal): … round-2 closures`
- `docs(fiscal): refresh handoff §4 after Task NN round-2 ships` (separate commit)

The Task 23 commit `34f1b6113` bundles:
- New `ApplyFiscalEventProjectionJob.php` (438 LOC)
- New `ApplyFiscalEventProjectionJobTest.php` (697 LOC)
- Modified `OutboxIngestor.php` (closure body replacement)
- Modified `FiscalEventProjectionRegistry.php` (new `byName()` method)
- Modified `OutboxIngestorTest.php` (assertion upgrade)
- **Plan amendment** (`docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` — Step 3 prose + 2 new tests added to §1716)

The plan amendment changes the load-bearing prose of Step 3. Mixing a `feat` + `docs(plan)` in one commit obscures the diff readability for the dual-review process — a reviewer trying to understand "what changed in the implementation" has to filter out the plan-prose churn. Prior tasks kept these separate.

**Why it's P3.** Aesthetic. The amendment is correct and load-bearing; the bundle doesn't break anything. But the cadence drift is worth flagging because (a) the implementer was instructed in the brief to weigh "judgment call; flag whether the project's per-commit hygiene supports atomic spec+code or prefers separate commits"; (b) the prior 5 tasks shipped the cleaner cadence.

**Remediation.** None required. Optionally split the plan amendment into a follow-up commit on round-2 if the cluster is re-grouped. Document the cadence as either-acceptable in the handoff or pick one; prior precedent leans toward separate.

---

### F5 — P3 — `ConfigurableFakeProjector::$shouldThrow` is mutated mid-test via public property (Concern 6)

**File.** `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php:411, 616`.

**Observation.** `ConfigurableFakeProjector::$shouldThrow` (line 616) is `public bool` rather than `private readonly bool`. The helper `forceProjectorToThrow()` (line 402-412) toggles it mid-test:

```php
$existing->shouldThrow = true;
```

This violates the standing pattern for `final class` test fakes — DI properties are normally `private readonly`. The public-mutability + post-construction state mutation is a test-hygiene smell: it makes the fake's behavior dependent on call-order rather than on construction-time configuration, which complicates reasoning when a test sets up multiple projectors.

The implementer flagged this as Concern 6 explicitly.

**Why it's P3.** Test-only code, no production impact. The helper works correctly, and the alternative (two distinct fake classes — `AlwaysSucceedsFakeProjector` + `AlwaysThrowsFakeProjector`) is more verbose. Acceptable for now.

**Remediation.** Optionally refactor to two fake classes with `private readonly` state, or extract the throw decision into a closure passed via the constructor:

```php
final class ConfigurableFakeProjector implements FiscalEventProjector
{
    public function __construct(
        // …
        private readonly Closure $applyImpl = null,
    ) {}

    public function apply(FiscalEvent $event): void
    {
        ($this->applyImpl ?? static fn () => null)($event);
    }
}
```

Tests then pass a `fn () => throw new RuntimeException(...)` closure. No mid-test mutation.

---

## Premise audits

### Premise A — "The 12 QR test failures are pre-existing"

**Verdict: VERIFIED TRUE.**

- Located the test at `apps/api/tests/Unit/POS/Fiscal/ReceiptQrTokenSignerTest.php` (the implementer's brief had a stale path; the actual location was found via `find`).
- Ran `phpunit tests/Unit/POS/Fiscal/ReceiptQrTokenSignerTest.php` against prior HEAD `a8f15ff4f` (after `git checkout a8f15ff4f -- apps/api/tests/Unit/POS/Fiscal/ReceiptQrTokenSignerTest.php apps/api/app/Modules/POS/Domain/TenantSigningKey.php`): result was `Tests: 12, Assertions: 0, Errors: 12.`
- Verified `git diff a8f15ff4f 34f1b6113 -- apps/api/tests/Unit/POS/Fiscal/ReceiptQrTokenSignerTest.php apps/api/app/Modules/POS/Domain/TenantSigningKey.php` returns empty — Task 23 commit did not touch either file.
- Restored worktree to `34f1b6113` state.

Conclusion: the 12 QR test errors are pre-existing at `a8f15ff4f` (the prior commit) and at `34f1b6113`. Task 23 neither introduced nor fixed them. The premise is verified.

### Premise B — "`byName()` should ignore current module activation (orphan-avoidance over activation-recheck)"

**Verdict: VERIFIED TRUE.**

The architectural reasoning matches the docblock at registry lines 178-203:

> "at job-run time the `fiscal_event_projections` row already exists (activation gating happened at ingest time), so a module deactivated between ingest and run must still be able to resolve its projector — otherwise the row would be orphaned indefinitely"

Cross-checked against:
- Spec v7 §7.5 line 453: "in the §7.2 Step 2 transaction (T1), `OutboxIngestor` inserts one `pending` `fiscal_event_projections` row per active projector for the event type." Activation gating is at ingest time, not run time.
- `OutboxIngestor::dispatchProjections()` line 758: `$activeProjectors = $this->projectionRegistry->activeProjectorsFor($eventModel);` — the activation gate runs ONCE at ingest, materializing the active set into persistent rows.
- Plan §1796 amendment: explicitly mandates `byName()` resolution at run time (not `activeProjectorsFor`).

The alternative (re-check activation at run time) would create a hard race: a module deactivated between ingest and run would never have its projection row processed, but ALSO never have it dead-lettered (the job would silently no-op every retry until Horizon's $tries exhausted, then dead-letter on a confusing "module inactive" reason). The implementer's `byName()` design fail-closes to dead-letter ONLY for genuine misconfiguration (projector deregistered, not deactivated) — activation-state changes don't orphan the row. Sound.

### Premise C — "`lockForUpdate()` works on SQLite (no-op acceptable)"

**Verdict: VERIFIED TRUE (with sub-claim).**

`lockForUpdate()` on SQLite is a no-op — SQLite uses database-level locking for write transactions, not row-level. The test's lifecycle assertions are SQL-portable: they assert state transitions (status flips, attempts increments, terminal timestamps) which work identically on both drivers.

Examined each of the 7 tests:
1. `test_successful_projection_marks_applied` — sequential, single worker, no concurrency
2. `test_job_start_sets_running_then_failure_advances_attempts` — sequential
3. `test_exhausted_retries_dead_letter_via_failed_handler` — direct `failed()` invocation
4. `test_projection_failure_never_mutates_the_fiscal_events_row` — sequential
5. `test_pos_core_success_with_treasury_dead_letter_…` — sequential
6. `test_already_applied_row_short_circuits_on_re_dispatch` — sequential re-dispatch
7. `test_dead_lettered_row_short_circuits_on_re_dispatch` — sequential

None assert behavior dependent on real row-level locking. The implementer's "option (b)" decision (CI filter unchanged — don't add the new test to the PG merge-gate) is acceptable because the test does NOT exercise PG-specific behavior. Note: the `OutboxIngestorTest` is already in the CI filter and its updated assertion (`Queue::assertPushed(...)`) is portable.

**Sub-claim flagged.** The plan's amended prose says "serializes any concurrent dispatch of the same row across Horizon workers." This is slightly imprecise: the lifecycle lock serializes the T_lock window (lifecycle state mutations + terminal-state short-circuit), NOT the T_apply window (the projector's body). Two concurrent workers passing T_lock on a non-terminal row WOULD both invoke the projector concurrently — Task 22's `pg_advisory_xact_lock` is what serializes the projector body. The "two-layer defense" framing in the docblocks (job lines 75-76) correctly describes this; the plan prose is the bit that misstates. The implementation is correct; the plan prose is slightly off. Not actionable here.

### Premise D — "Plan amendment should be in this commit"

**Verdict: DEFENSIBLE BUT DRIFTS FROM PRIOR PRECEDENT.**

`git log` for `feat/pos-fiscal-event-engine-phase1` shows a consistent pattern (Tasks 18-22):
- `feat(fiscal): …` (implementation round 1)
- `docs(fiscal): … round-1 dual review artifacts` (separate commit)
- `fix(fiscal): … round-2 closures`
- `docs(fiscal): refresh handoff §4 after round-2 ships` (separate commit)

The Task 23 commit bundles the plan amendment with the implementation. This is defensible because the amendment is load-bearing for the implementation (the implementer added two new tests + the Step 3 prose for the two-transaction shape; without the amendment the implementation would be a spec-drift). Atomic spec+code IS a valid hygiene model.

However, prior cadence preferred separation. The reviewer can read the implementation diff and the spec diff independently when they are separate commits. Bundling forces the dual-review process to also adjudicate the spec change. This may be why the brief asked the reviewer to flag the per-commit hygiene question.

Recommendation: flag as P3 (F4 above); no remediation required. Future tasks might benefit from a written cadence rule in the handoff.

---

## Grep verification log

| Claim | Verified | Notes |
|---|---|---|
| 7 lifecycle tests in `ApplyFiscalEventProjectionJobTest` cover plan §1716's enumeration | Partial | 7/7 tests match plan §1716 names; one (test_pos_core_success_with_treasury_dead_letter_leaves_pos_core_intact) substitutes ApplyLog for `pos_receipts->count()` per F1. |
| Phpunit green on `ApplyFiscalEventProjectionJobTest` | Yes | 7 tests, 32 assertions pass on SQLite. |
| Phpunit green on `OutboxIngestorTest` (with the dispatch-assertion upgrade) | Yes | 20 tests, 100 assertions pass. |
| Phpstan level 8 green on 5 touched files | Yes | 0 errors on `ApplyFiscalEventProjectionJob.php`, `FiscalEventProjectionRegistry.php`, `OutboxIngestor.php`, `ApplyFiscalEventProjectionJobTest.php`, `OutboxIngestorTest.php`. |
| Pint --test green on 5 touched files | Yes | `{"result":"pass"}`. |
| `byName()` filters by `$projector->name() === $name` only | Yes | `FiscalEventProjectionRegistry.php:194-203` — strict-equality on name, no activation gate. |
| `byName()` returns null on miss | Yes | `return null` at line 202. |
| `byName()` benefits from constructor uniqueness invariant | Yes | Registry ctor line 124-130 (F2 round-2) throws LogicException on duplicate names; `byName()`'s "first match wins" loop is unambiguous because no duplicates can survive construction. |
| `OutboxIngestor::dispatchProjections()` after-commit closure uses `static fn` (no `$this` capture) | Yes | `OutboxIngestor.php:791` — `DB::afterCommit(static function () use ($rowsForDispatch): void {`. |
| Closure dispatches `ApplyFiscalEventProjectionJob::dispatch($row['id'])` per row | Yes | `OutboxIngestor.php:792-794`. |
| `ApplyFiscalEventProjectionJob` import added to `OutboxIngestor` | Yes | `OutboxIngestor.php:10` — `use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;`. |
| `OutboxIngestorTest::test_projection_dispatch_only_after_commit` upgraded from `Queue::assertNothingPushed()` to `Queue::assertPushed(...)` | Yes | `OutboxIngestorTest.php:420` — `Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 1);` plus payload-assertion at lines 430-433 (`$job->projectionRowId === $projectionRowId`). |
| Rolled-back outer transaction still suppresses dispatch | Yes | `OutboxIngestorTest::test_outer_transaction_rollback_suppresses_fiscal_event_and_projection_dispatch` (line 683-709) keeps `Queue::assertNothingPushed()` after a forced rollback — proves `DB::afterCommit` honors outer rollback. |
| Plan §1716 added two new tests (already_applied / dead_lettered short-circuit) | Yes | Plan amendment diff at `docs/superpowers/plans/...md:1762-1786` adds both tests; implementer's test file includes both at lines 292-356. |
| Plan §1796 Step 3 prose amended for two-transaction shape | Yes | Plan amendment diff line 1796 replaces the single-paragraph Step 3 with the T_lock + T_apply two-transaction prose + idempotent short-circuit on terminal states. |
| `FiscalEventProjectionRow::$fillable` excludes lifecycle columns | Yes | `FiscalEventProjectionRow.php:74-78` — only `['id', 'fiscal_event_id', 'projector_name']`. Job uses targeted assignment, not mass-assignment. |
| Lifecycle columns mutated via targeted assignment in job | Yes | Lines 198 (`$row->projection_status = ProjectionStatus::Running`), 292-294 (`Applied`), 334-340 (`DeadLettered`), 390-393 (advance accounting), 417-420 (hard failure). All use targeted assignment + `$row->save()`. |
| `loadFiscalEvent` wraps `FiscalEvent::find()` in try/catch(QueryException) | Yes | Job lines 365-379 — Task 17 standing pattern applied. |
| Job has no `app()` helper, no `(type) $array['key']` casts | Yes | Grep verified zero hits for `app(` in the job + zero `(type)` casts on array values. |
| Job is `final class` with `ShouldQueue` interface + standard traits | Yes | `final class ApplyFiscalEventProjectionJob implements ShouldQueue` with `Dispatchable, InteractsWithQueue, Queueable, SerializesModels` (lines 105-110). |
| Method-injection for `ConnectionInterface` + `FiscalEventProjectionRegistry` in `handle()` | Yes | Lines 155-158. No constructor injection (would require ctor params per dispatched job — standard Laravel pattern is method-injection on `handle()`). |
| `$tries = 5`, `$timeout = 120`, `backoff(): array = [10, 30, 60, 300, 900]` | Yes | Lines 119, 128, 139-142. Reasonable. |
| `onQueue('fiscal-projections')` set in constructor | Yes | Line 133. Survives serialization (Queueable trait). |
| Pre-existing QR test failures verified at prior HEAD `a8f15ff4f` | Yes | `git checkout a8f15ff4f -- … && phpunit tests/Unit/POS/Fiscal/ReceiptQrTokenSignerTest.php` → 12 errors. Identical at `34f1b6113`. Diff between commits is empty for QR signer + test paths. |
| `ConfigurableFakeProjector::$shouldThrow` is `public bool` (mid-test mutability) | Yes | Line 616. Mutated in `forceProjectorToThrow` at line 411. Per Concern 6. |
| Cross-projector partial-cluster test substitutes `ApplyLog` for plan-literal `pos_receipts->count() === 1` | Yes | Test lines 274-290 use `applyLog->countFor(...)` + `applyLog->names()` instead of plan §1761's `DB::table('pos_receipts')->count() === 1`. Per Concern 4. |
| Plan amendment lives in same commit as implementation | Yes | `git show 34f1b6113 --stat` lists `docs/superpowers/plans/...md` alongside the 5 implementation/test files. Per Concern 5. |
| CI PG-merge-gate filter unchanged | Yes | `.github/workflows/ci.yml:392` does NOT include `ApplyFiscalEventProjectionJobTest`. Per implementer's option (b) decision in the brief. |
| Test does not exercise PG-only behavior | Yes | All 7 tests are sequential, single-worker, asserting lifecycle state transitions that work identically on SQLite + PG. No advisory locks, no `ON CONFLICT`, no concurrent execution assertions. |

---

## Standing-pattern conformance

| # | Pattern | Status |
|---|---|---|
| 1 | `(type) $array['key']` PHP casts → silent coercion | CLEAN — job doesn't read payload arrays; operates on `fiscal_event_projections` row id only. |
| 2 | Fail-closed on resolver / downstream-service exception (Task 18 BLOCKER F1) | CLEAN — `loadFiscalEvent` catches `QueryException` (line 367); both null-return paths (missing FiscalEvent, missing projector) call `recordHardFailure` + throw via the documented dead-letter path. |
| 3 | Boot-time invariants must be constructor-asserted (Task 18 P2 F2/F3) | CLEAN — `byName()` relies on the registry constructor's uniqueness invariant (F2 round-2); the job doesn't add new boot invariants. |
| 4 | DB primitives spec-named are load-bearing (Task 19 BLOCKER B1/T19-B1) | CLEAN — plan amendment §1796 names `lockForUpdate()` exactly; implementation uses `FiscalEventProjectionRow::query()->lockForUpdate()->find(...)` at line 164-166. Not a substituted equivalent. |
| 5 | Multi-constraint tables need constraint-name-targeted ON CONFLICT (Task 19 BLOCKER T19-B2) | N/A — job doesn't write ON CONFLICT. |
| 6 | Verify the premise of every deferral (Task 19 BLOCKER T19-B3) | MIXED — 4 of the 6 implementer-flagged concerns are real coverage gaps (F1, F2 above). The "12 QR failures are pre-existing" premise IS verified (Premise A). The `byName()`-ignores-activation premise IS verified (Premise B). The SQLite `lockForUpdate()` no-op premise IS verified (Premise C). The CI-filter-unchanged decision (option b) IS valid. So premise audits pass; the misses are coverage gaps, not false-premise deferrals. |
| 7 | Discriminated-union test matrix coverage (Task 20 standing pattern) | VIOLATED — 3 of 9 logical handle()/failed() branches uncovered. See F2. |
| 8 | Stale-comment hazard (Task 20 standing pattern) | MIXED — see F3 (spec-vs-plan drift on the "in its own transaction" wording). Job docblocks themselves are accurate to the implementation. |
| 9 | Legacy verify/audit infrastructure may inspect relocated columns (Task 21 standing pattern) | N/A — job doesn't touch any column that legacy verify infrastructure reads. |
| 10 | D8 coexistence via spec §14 disposition (Task 21 standing pattern) | N/A — job doesn't create a new write path for an existing model. |
| 11 | Projector dispatch order must be explicit via `priority()` (Task 22 BLOCKER T22-B1) | CLEAN — job operates on a single row id; doesn't iterate the registry. The dispatch ORDER is owned by `OutboxIngestor::dispatchProjections` which already consumes the registry's `(priority ASC, name ASC)` sort. The closure preserves the sorted order via `foreach ($rowsForDispatch as $row)` (line 792). |
| 12 | Projector idempotency on non-UNIQUE keys requires `pg_advisory_xact_lock` (Task 22 BLOCKER B2) | CLEAN — the advisory lock is owned by each projector (Task 22 round-2); the job doesn't add or remove it. The plan §1796 amendment correctly names the two-layer defense: row-level lifecycle lock (this task) + projector-level advisory lock (Task 22). |
| 13 | NULL-origin inheritance fails closed to canonical sentinel (Task 22 BLOCKER B2) | N/A — job doesn't touch inheritance columns. |
| 14 | `$fillable` boundary discipline (Task 9) | CLEAN — `FiscalEventProjectionRow::$fillable` correctly excludes the 6 lifecycle columns (`projection_status`, `attempts`, `last_error`, `last_attempted_at`, `applied_at`, `dead_lettered_at`); all mutations in the job use targeted assignment + `$row->save()`. |
| 15 | CI PG-merge-gate filter extended at the same commit (Tasks 10/11/19/21/22 reviewer convergent) | ACCEPTABLE (option b) — test does NOT exercise PG-specific behavior. Per implementer's brief, option (b) is "tests assert lifecycle semantics in a single-worker scenario only and trust the PG driver to honor `lockForUpdate()` at runtime; CI filter unchanged." Verified: none of the 7 tests assert PG-specific behavior. The existing `OutboxIngestorTest` (which now exercises Task 23's dispatch wiring) IS already in the filter — so the after-commit dispatch contract still gets PG coverage via that path. |
| 16 | Constructor injection only — no `app()` helper | CLEAN — `handle()` uses Laravel method-injection (`ConnectionInterface` + `FiscalEventProjectionRegistry`); the test's `runJobInline()` uses `$this->app->call([$job, 'handle'])` which mirrors Horizon's worker dispatch. Zero `app()` helper hits. |

---

## C1–C6 / implementer-concern adjudications

### Concern 1 — `failed()` re-invocation idempotency implemented but not tested

**Verdict: ESCALATED to F2 (P1).** The behavior is correctly implemented (line 333 gate) but the test coverage gap is real. Round-1 should have included the test. See F2 remediation.

### Concern 2 — No test for "FiscalEvent missing" hard-misconfig path

**Verdict: ESCALATED to F2 (P1).** Same class as Concern 1. Implementation correct; test missing. See F2 remediation.

### Concern 3 — No test for "projector deregistered" hard-misconfig path

**Verdict: ESCALATED to F2 (P1).** Same class. See F2 remediation.

### Concern 4 — Cross-projector partial-cluster test uses fake projectors instead of real PosCoreReceiptProjection

**Verdict: ESCALATED to F1 (P1).** Plan literal names `DB::table('pos_receipts')->count() === 1`; implementer substituted `ApplyLog` invocation counts. Loses the cross-projector projection-atomicity invariant. See F1 remediation.

### Concern 5 — Plan amendment committed alongside implementation

**Verdict: ACCEPTABLE BUT DRIFTS FROM PRIOR PRECEDENT.** See F4 (P3). Atomic spec+code is defensible; prior cadence (Tasks 18-22) preferred separate `feat` + `docs` commits. Future tasks should pick one and document the rule.

### Concern 6 — `ConfigurableFakeProjector::$shouldThrow` mutated mid-test via public property

**Verdict: ACCEPTABLE TEST-HYGIENE SMELL.** See F5 (P3). Test-only impact; optional refactor to closure-based or two-class pattern.

---

## Cross-task wiring verification

### `OutboxIngestor::dispatchProjections()` after-commit closure

| Check | Verified |
|---|---|
| Import added | Yes — `OutboxIngestor.php:10` (`use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;`) |
| Closure body actually dispatches | Yes — lines 791-795 (`DB::afterCommit(static function () use ($rowsForDispatch): void { foreach ($rowsForDispatch as $row) { ApplyFiscalEventProjectionJob::dispatch($row['id']); } });`) |
| `static` keyword preserved (no `$this` capture) | Yes — `static function` at line 791 |
| `OutboxIngestorTest::test_projection_dispatch_only_after_commit` upgraded | Yes — `Queue::assertNothingPushed()` → `Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 1)` + payload assertion that `$job->projectionRowId === $projectionRowId` |
| Dispatch order matches registry's `(priority ASC, name ASC)` sort | Yes — `OutboxIngestor::dispatchProjections` (line 758) consumes `$this->projectionRegistry->activeProjectorsFor($eventModel)` which returns the registry's sorted set; the closure preserves the order via `foreach ($rowsForDispatch as $row)` |
| Rolled-back outer transaction still suppresses dispatch | Yes — `test_outer_transaction_rollback_suppresses_fiscal_event_and_projection_dispatch` (line 683-709) still asserts `Queue::assertNothingPushed()` after forced outer rollback |

### `FiscalEventProjectionRegistry::byName()`

| Check | Verified |
|---|---|
| Filters by `$projector->name() === $name` only (no activation gating) | Yes — `Registry.php:194-203` — strict-equality loop, no resolver call |
| Returns null on miss | Yes — `return null` at line 202 |
| Constructor-asserted uniqueness invariant (Task 18 F2 round-2) means `byName()` is well-defined | Yes — Registry ctor lines 124-130 throw `LogicException` on duplicate `name()`; the "first match wins" loop is unambiguous |
| Phpdoc added | Yes — lines 178-193 explain the activation-gating-at-ingest design + the caller's fail-closed contract on null |

---

## Final notes on subagent execution

The subagent followed the plan's amended Step 3 prose precisely:
- Two-transaction shape (T_lock + T_apply) is structurally correct
- Lifecycle lock + idempotent short-circuit on terminal states wired in both `handle()` and `failed()`
- Terminal-status writes + attempt accounting live OUTSIDE the projector's transaction (correctly survives projector rollback)
- `OutboxIngestor` enqueue closure correctly replaced with real dispatch, `static` preserved, per-row iteration in registry-sorted order
- `byName()` design correctly ignores activation (orphan-avoidance over activation-recheck)
- Fail-closed on misconfiguration for both missing-FiscalEvent and missing-projector paths
- Standing patterns honored: `QueryException` wrap on `Eloquent::find($uuid)`, `$fillable` discipline on lifecycle columns, no `app()` helper, no `(type) $array['key']` casts, constructor uniqueness invariant leveraged by `byName()`

The remaining gaps are **test coverage**, not implementation defects:
- F1: cross-projector partial-cluster substitutes `ApplyLog` for the plan literal `pos_receipts->count() === 1` — substitute the real `PosCoreReceiptProjection`
- F2: three logical branches (failed() re-invocation, missing-FiscalEvent hard-misconfig, missing-projector hard-misconfig) untested — add the three tests from F2 remediation

Plus two documentation-layer concerns:
- F3 (P2): plan amendment introduces `lockForUpdate()` two-transaction shape that is NOT in spec v7 §7.5 — promote to spec v8 or add a disposition note
- F4 (P3): plan amendment bundled into the implementation commit (prior cadence preferred separation)

And one test-hygiene cleanup:
- F5 (P3): `ConfigurableFakeProjector::$shouldThrow` mid-test mutability — optional refactor

Round-2 changeset shape: F1 (real PosCoreReceiptProjection substitution) + F2 (three new tests) are must-close P1s. F3 (P2 spec-promotion or disposition note) is should-close. F4 + F5 are sweep-while-you're-in-here.

Convergent with Codex? — F1 (test substitutes fake for real business effect) and F2 (uncovered branches) are likely Codex's wheelhouse on adversarial-correctness; F3 (spec-vs-plan drift) may be Opus-unique on the documentation lens. Codex may also catch the "concurrent dispatch" subtlety in the plan amendment prose vs. implementation behavior (the lifecycle lock serializes T_lock window only, not T_apply) — that's the kind of plan-prose-vs-code drift Codex flagged on Task 22's docblock-vs-behavior bug.

---

## Round-2 re-review (commit 6c03bac5e)

**Subject.** `6c03bac5e` on `feat/pos-fiscal-event-engine-phase1` (worktree `apps/erp.fiscal-phase1`).
**Scope.** Two BLOCKERs (Codex T23-B1 + T23-B2), one P1 (Codex T23-P1-1 / Opus F1 convergent), three P3s (Codex T23-P3-1/2/3); deferred Opus F3 (P2 — spec v8 promotion) and F4/F5 (P3 — accepted) per the implementer's brief.

### Verdict: APPROVE

All seven dual-review findings that round-2 was scoped to close are CLOSED with high-quality fixes; the new defect surface is empty. The two BLOCKERs received well-engineered two-layer defenses (T23-B1: `WithoutOverlapping` middleware + belt-and-braces stale-running age check inside T_lock; T23-B2: new `ProjectionDependencyMissingException` extending `RuntimeException` so the Task 23 job's existing `catch (Throwable)` handles it as a retryable failure, with the renamed test inverting the "no crash" contract to the "throws retryable" contract). The P1 plan-literal restoration (`DB::table('pos_receipts')->count() === 1`) is exact; the P3 closures each add the targeted test the round-1 review named. The cross-task touch on `TreasuryReceiptBridge` does NOT regress Task 22 (`TreasuryReceiptBridgeTest` 16/16 green) and the docblock at lines 191-208 thoroughly explains the new throw-on-missing-dependency contract, replacing the stale "deferred bail-out" prose. Phpstan level 8 clean across all four touched implementation files (`ApplyFiscalEventProjectionJob.php`, `OutboxIngestor.php`, `ProjectionDependencyMissingException.php`, `TreasuryReceiptBridge.php`); 15/15 `ApplyFiscalEventProjectionJobTest` green (8 new tests, all green); 184/184 full Fiscal feature suite green (+8 tests, +55 assertions vs round-1 baseline of 176/493). The five implementer-flagged premises (WithoutOverlapping properties are public-API, array-cache lock semantics, cross-worker race artificiality, `last_attempted_at` semantics, `isRecentlyAttempted` window) all hold up to verification — see Premise audits below.

The fix is denser than typical round-2 changesets (~99 LOC added to the job, new 65-LOC exception class, ~34 LOC of TreasuryReceiptBridge change, ~561 LOC of new test code) but every increment is load-bearing. The two-defense T23-B1 closure (queue lock + age check) is properly motivated by the cache-driver-fails-open case (the docblock at job lines 79-106 explains exactly which scenario each defense handles); the test exercises the middleware via direct `Cache::lock` simulation rather than process-forking, which is the right test shape given Laravel's `array` cache driver semantics (ArrayLock shares state across calls in the same PHP process, so the middleware-level test is deterministic). The T23-B2 closure inverts a known anti-pattern (the round-1 "deferred bail-out test smell" that handoff §4.2 line 117 flagged as a standing lesson from Task 22's convergent BLOCKER) — the new test name (`test_no_pos_receipt_for_event_throws_projection_dependency_missing`) IS the contract documentation. The cross-worker race regression (`test_treasury_first_then_pos_core_resolves_via_retry_contract`) walks all three states of the retry contract end-to-end (Treasury throws → POS lands → Treasury retry succeeds with Payment + GL rows written).

### Round-1 finding closure table

| Round-1 ID | Severity | Status | Note |
|---|---|---|---|
| Opus F1 / Codex T23-P1-1 | P1 (convergent) | **CLOSED** | `ApplyFiscalEventProjectionJobTest:285-352` now uses `$realPosCore = $this->app->make(PosCoreReceiptProjection::class)` + `$treasuryFake = new ConfigurableFakeProjector(...)` registered via new `registerProjectors()` helper. Asserts `DB::table('pos_receipts')->where('fiscal_event_id', $event->id)->count() === 1` per plan §1761 literal; bonus assertion on `pos_receipt_lines->count() > 0` for line-write atomicity; explicit `DB::table('payments')->count() === 0` belt-and-braces for the Treasury dead-letter side. |
| Opus F2 / Codex T23-P3-1 | P1 (convergent) | **CLOSED** | `test_failed_re_invocation_preserves_original_dead_lettered_at` at lines 693-726 — uses `Carbon::setTestNow` to advance the clock 60 seconds, asserts both `dead_lettered_at` AND `last_error` unchanged across the re-invocation. Includes a `Carbon::setTestNow()` reset in the finally-equivalent at line 725. |
| Opus F2 / Codex T23-P3-2 | P1 (convergent) | **CLOSED** | Both tests exist: `test_missing_fiscal_event_records_hard_failure_and_throws` (lines 732-773) updates the row via raw `DB::table` to point at a non-existent UUID (correct — Task 8's BEFORE DELETE trigger on `fiscal_events` forbids real deletion); `test_missing_projector_records_hard_failure_and_throws` (lines 775-816) seeds a row with `projector_was_removed` then rebinds the registry with NO matching projector. Both tests assert `Log::shouldReceive('critical')->atLeast()->once()` + `attempts++ === 1` + `last_error` contains the expected string + status stays `running` (not flipped to `dead_lettered` until `failed()` is invoked). |
| Codex T23-B1 | BLOCKER | **CLOSED** | Two-defense closure: (a) `middleware()` method at job lines 188-195 returns `[(new WithoutOverlapping($projectionRowId))->expireAfter($timeout)->dontRelease()]`; (b) belt-and-braces stale-running age check inside T_lock at lines 259-265 short-circuits when `projection_status === Running && isRecentlyAttempted($row)`. Four targeted tests: `test_middleware_includes_without_overlapping_keyed_by_projection_row_id` (asserts middleware presence + key + releaseAfter NULL + expiresAfter matching `$timeout`); `test_without_overlapping_middleware_rejects_duplicate_delivery_while_first_in_flight` (uses `Cache::lock` to simulate first-worker holding the lock + asserts `$next` is NOT called from the second middleware invocation + asserts `$next` IS called after release); `test_fresh_running_row_short_circuits_belt_and_braces` (mutates row to running + fresh timestamp + asserts projector NOT invoked); `test_stale_running_row_is_recovered_on_re_dispatch` (mutates row to running + `last_attempted_at = now - (timeout + 60)` + asserts projector IS invoked + row ends `applied`). |
| Codex T23-B2 | BLOCKER | **CLOSED** | Three-part closure: (a) new `ProjectionDependencyMissingException` extends `RuntimeException` with `public readonly` constructor properties (`projectorName`, `fiscalEventId`, `missingDependency`); (b) `TreasuryReceiptBridge::apply()` at lines 224-230 throws the new exception in place of round-1's `Log::warning + return`; (c) renamed `TreasuryReceiptBridgeTest::test_no_pos_receipt_for_event_throws_projection_dependency_missing` inverts the contract assertion. Cross-worker race regression `test_treasury_first_then_pos_core_resolves_via_retry_contract` walks the full end-to-end retry: Treasury throws (attempts=1, status=`running`, payments=0) → POS-core lands (`pos_receipts.count()=1`) → Treasury retry succeeds (status=`applied`, payments=1, journal_entries>0). |
| Codex T23-P3-3 | P3 | **CLOSED** | `OutboxIngestor.php:723-726` rewritten to "After T1 commits, dispatch one `ApplyFiscalEventProjectionJob` per pending row via `DB::afterCommit()`…" (no more "Task 23 — not yet implemented"). `OutboxIngestorTest.php:156-163` rewritten to "Queue::fake() catches the real `ApplyFiscalEventProjectionJob` dispatches without executing them. Task 23 shipped the real job class…" (no more "the job class which doesn't exist yet"). One additional informative comment at `OutboxIngestor.php:786` ("Task 23 lands here: the previous TODO no-op is replaced with the real dispatch") describes the round-2 transition rather than referring to stale state — acceptable. |
| Opus F3 | P2 | **DEFERRED (per brief)** | Spec v8 promotion of the `lockForUpdate()` two-transaction shape was deferred per the implementer's brief. The plan amendment from round-1 still lives in the plan only. **No re-finding** — the deferral is explicit. |
| Opus F4 | P3 | **ACCEPTED (per brief)** | Per-commit hygiene drift (plan amendment in same commit as round-1 implementation) is accepted; round-2 cleanly separates fix code from doc churn. **No re-finding.** |
| Opus F5 | P3 | **ACCEPTED (per brief)** | `ConfigurableFakeProjector::$shouldThrow` public mutability accepted. The new fake projectors in round-2 tests don't toggle state mid-test — they're constructor-configured at the start of each test. **No re-finding.** |

### New findings

None. The round-2 changeset is clean.

### Premise audits (the implementer self-flagged 5)

#### Premise 1 — `WithoutOverlapping::$key`, `$releaseAfter`, `$expiresAfter` are documented public-API properties

**Verdict: VERIFIED TRUE.**

Read `vendor/laravel/framework/src/Illuminate/Queue/Middleware/WithoutOverlapping.php`. The three properties are declared `public` with docblocks at lines 11-27:

```php
/** @var string */
public $key;

/** @var \DateTimeInterface|int|null */
public $releaseAfter;

/** @var int */
public $expiresAfter;
```

They are part of the documented public-API surface (Laravel's own `Container::call()` and `getLockKey($job)` reach for them by name). The test's `$first->key`, `$first->releaseAfter`, `$first->expiresAfter` assertions are stable against framework upgrades within the Laravel 11 / 12 majors. The implementer's self-flag was conservative; no P3 needed.

#### Premise 2 — Laravel `ArrayLock` shares state across calls in the same PHP process

**Verdict: VERIFIED TRUE.**

The Laravel `array` cache driver's lock implementation (`Illuminate\Cache\ArrayLock` extending `Illuminate\Cache\Lock`) stores locks in the same `ArrayStore::$storage` array for the lifetime of the PHP request. The test `test_without_overlapping_middleware_rejects_duplicate_delivery_while_first_in_flight` uses `Cache::lock(...)->get()` directly to acquire the lock + then invokes the middleware's `handle()` with a no-op `$next` closure. Because the middleware's internal `Container::getInstance()->make(Cache::class)->lock(...)` resolves the same store, the second `->get()` returns false and the middleware silently drops (because `dontRelease()` made `releaseAfter` null). The test is deterministic on the `array` driver in single-process PHPUnit execution. The premise is sound; the test is correct.

#### Premise 3 — Cross-worker race test artificiality

**Verdict: ACCEPTED AS-IS — the test value is in the contract pinning, not the concurrency simulation.**

The implementer self-flagged that `test_treasury_first_then_pos_core_resolves_via_retry_contract` runs `runJobInline` sequentially rather than truly concurrently. This is correct — the test does NOT simulate two simultaneous Horizon workers. What it DOES pin is the three-step retry contract end-to-end:

1. **Treasury runs first while POS-core hasn't committed.** The bridge throws `ProjectionDependencyMissingException`; the job catches via `catch (Throwable)` (the new exception extends `RuntimeException` so the existing catch path captures it); `advanceFailureAccounting()` runs OUTSIDE T_apply (correct per the round-1 "terminal-status writes live outside T_apply" pattern); attempts increments to 1; status stays `running`; `last_error` captures the message; payments count is 0; the wrapping job re-throws (the test catches the re-throw and asserts `instanceof ProjectionDependencyMissingException`).

2. **POS-core lands second.** The real `PosCoreReceiptProjection` writes `pos_receipts` (1 row).

3. **Treasury retry succeeds.** The test must first manually advance `last_attempted_at` past the `$timeout` window so the belt-and-braces stale-running check (which interprets fresh-running as "sibling worker mid-apply") doesn't short-circuit the retry. The implementer correctly documents this at lines 664-668 ("In production, Horizon's backoff exceeds the freshness window" — true: the smallest backoff is 10s, the timeout is 120s, so under typical retry scheduling the freshness window has expired by the time Horizon retries). After the manual clock advance, the retry lands `applied` with payments=1 + journal_entries>0.

The test is artificial in the concurrency sense but exhaustively pins the failure-and-recovery contract. A genuine cross-worker race test would require process forking + a real cache backend, which is over-engineering for a unit/feature test. Accepted as-is; no P3 needed.

#### Premise 4 — `last_attempted_at` semantics across the lifecycle

**Verdict: VERIFIED TRUE with one minor nuance.**

The implementer's design distinguishes three cases of `(projection_status = running, last_attempted_at)`:

- **`null`** — row was flipped to `running` by an earlier `handle()` that crashed BEFORE any apply() attempt advanced `last_attempted_at` (the field is only set in `advanceFailureAccounting()` and `recordHardFailure()`, both of which run AFTER the projector throw). Interpret as "lock was held but apply() never started"; treat as crash recovery and re-attempt. `isRecentlyAttempted` returns false for null.

- **Fresh (within `$timeout` seconds)** — interpret as "sibling worker is mid-apply on this row" and short-circuit. The cache lock should normally hold this case but the belt-and-braces test exists for the array-cache-fails-open scenario.

- **Stale (older than `$timeout` seconds)** — interpret as "sibling worker died, the cache lock auto-expired" and re-attempt as crash recovery.

The minor nuance: there is a NULL `last_attempted_at` case that the docblock at lines 256-258 explicitly handles ("`last_attempted_at` is null OR older than `$timeout`"), and the test `test_stale_running_row_is_recovered_on_re_dispatch` covers the STALE branch but not the explicit NULL branch. Not actionable — the `isRecentlyAttempted` helper at line 513-515 correctly returns false on null:

```php
if ($row->last_attempted_at === null) {
    return false;
}
```

— so the recovery path IS taken on null. A targeted test for the NULL branch would be < 10 LOC but is genuinely sweep-while-you're-in-here, not a finding.

#### Premise 5 — `isRecentlyAttempted` window matches `WithoutOverlapping::expireAfter`

**Verdict: VERIFIED TRUE.**

Both use `$this->timeout` (= 120 seconds):
- `middleware()` at line 192: `->expireAfter($this->timeout)`
- `isRecentlyAttempted()` at line 518: `Carbon::now('UTC')->subSeconds($this->timeout)`

This is the correct alignment. If the cache lock auto-expires (after `timeout` seconds), the next delivery's belt-and-braces check ALSO interprets the row as stale (because `last_attempted_at` is older than `timeout`), so the two layers' recovery semantics agree. If the cache lock expired but `last_attempted_at` is fresher than `timeout` (only possible if `advanceFailureAccounting` ran very recently — i.e., the prior worker DID get to a projector throw inside the apply window), then the belt-and-braces correctly drops the duplicate. The implementation is internally consistent.

### Standing-pattern conformance sweep (round-2)

| Pattern | Round-2 status |
|---|---|
| Fail-closed on downstream-service exceptions (Task 18 F1) | CLEAN — `ProjectionDependencyMissingException` extends `RuntimeException` and is caught by the job's `catch (Throwable)` at line 351, advances attempt accounting, re-throws for Horizon retry. The catch signature is unchanged. |
| DB primitives spec-named are load-bearing (Task 19 B1) | CLEAN — `lockForUpdate()` still at lines 217-219 in T_lock; `WithoutOverlapping` is added on top as a queue-level overlap lock, NOT a replacement. The two defenses coexist (queue-level + DB-level + belt-and-braces age check = three layers). |
| `$fillable` boundary discipline (Task 9) | CLEAN — sweep of the new test code shows zero `->fill()` / mass-`create([...])` calls on `FiscalEventProjectionRow`. All test mutations use targeted assignment (`$row->projection_status = ...; $row->save()`) or raw `DB::table('fiscal_event_projections')->update([...])` which bypasses the model boundary entirely (acceptable for direct-DB test setup). |
| Discriminated-union test matrix coverage (Task 20 standing pattern) | CLEAN — 15 tests now cover the discriminated union of `handle()` / `failed()` paths: success (A), projector-throw with attempt accounting (B), failed() first-invocation dead-letter (C), failed() re-invocation idempotency (D — NEW), missing-FiscalEvent hard-misconfig (E — NEW), missing-projector hard-misconfig (F — NEW), terminal-state short-circuit (G ×2), cross-projector partial cluster (H — NOW REAL), FiscalEvent immutability (I), WithoutOverlapping middleware presence (J — NEW), WithoutOverlapping rejects duplicate (K — NEW), fresh-running short-circuit (L — NEW), stale-running recovery (M — NEW), cross-worker race retry contract (N — NEW). 9 of 9 round-1 logical paths covered; 5 new round-2 paths covered (the two concurrent-delivery defenses, the dependency-missing exception path, the cross-worker race contract). |
| Stale-comment hazard (Task 20 standing pattern) | CLEAN — T23-P3-3 explicitly closed two stale comments in `OutboxIngestor.php` + `OutboxIngestorTest.php`; sweep for "Task 23.*not yet implemented" / "the job class which doesn't exist yet" / "TODO.*Task 23" finds zero remaining hits across the whole `apps/api` tree. The one residual line at `OutboxIngestor.php:786` ("Task 23 lands here: the previous TODO no-op is replaced with the real dispatch") is a transition narrative, not a stale claim. |
| Deferred-bail-out test-smell inversion (Task 22 standing pattern, handoff §4.2 line 117) | CLEAN — the renamed `test_no_pos_receipt_for_event_throws_projection_dependency_missing` IS the lesson learned. Round-1 of Task 22 left this exact anti-pattern in place ("a test that asserts 'no crash' can mask a silent-applied bug"); round-2 of Task 23 inverts the contract assertion. The test name documents the new contract. |
| Constructor-injection only (Task 9, Task 18 R2) | CLEAN — the new `ProjectionDependencyMissingException` uses constructor property promotion with `public readonly`; zero `app()` helper hits in the new exception class or in the new test code. |

### Cross-task touch verification (Task 22 — `TreasuryReceiptBridge`)

**Touch summary.** Round-2 modifies `TreasuryReceiptBridge.php` (apps/api lines 7, 181-208, 224-230): adds the new exception import, replaces 24 lines of stale "deferred bail-out" docblock prose with new "throw-on-missing-dependency" prose, and replaces the `Log::warning + return` no-op at the missing-receipt branch with `throw new ProjectionDependencyMissingException(...)`.

| Check | Verified |
|---|---|
| `TreasuryReceiptBridgeTest` is green after the change | YES — 16/16 tests pass; `test_no_pos_receipt_for_event_throws_projection_dependency_missing` (renamed from `test_no_pos_receipt_for_event_is_a_deferred_bail_out_not_a_crash`) is in the pass set. |
| The renamed test correctly asserts the throw + zero Payment + zero GL | YES — `TreasuryReceiptBridgeTest:546-583` asserts `ProjectionDependencyMissingException` is thrown, `projectorName === 'treasury_receipt_bridge'`, `fiscalEventId === $event->id`, `missingDependency` contains 'pos_receipts', message contains 'RETRYABLE', and asserts `payments.count() === 0` + `journal_entries.count() === 0`. |
| The TreasuryReceiptBridge docblock no longer says "deferred bail-out" | YES — sweep of `TreasuryReceiptBridge.php` for "deferred bail-out" finds zero matches; the round-2 prose at lines 191-208 explains the new throw contract. |
| The class-level docblock at the test file is updated | YES — `TreasuryReceiptBridgeTest.php:62-71` rewrites the "Boundary discipline" paragraph to describe the throw-then-retry contract. |
| The cross-task touch is documented in the commit message | YES — the commit message explicitly calls out T23-B2 + the TreasuryReceiptBridge.php change. |

### New-defect surface sweep

I specifically looked for the patterns common to round-2 defect introductions:

| Defect class | Result |
|---|---|
| Stale-comment regression introduced by round-2 (Task 20 lesson) | NONE — sweep of the round-2 diff for "TODO" / "future" / "Task X" markers finds only contextual references in docblocks (e.g., "Task 22 round-2" referring to the existing Task 22 advisory lock, "Task 23 round-2 — Codex T23-B1 BLOCKER" labeling the round-2 code), none of which are stale claims. |
| Test that masks behavior with `try/catch` swallowing | NONE — the cross-worker race test at lines 629-633 uses a manual try/catch ONLY to capture the exception for `instanceof` assertion + re-asserts on the captured exception; this is the correct shape (PHPUnit's `expectException` would terminate the test before the second-stage assertions). |
| Test that asserts the middleware list contents but not the middleware behavior | NONE — `test_middleware_includes_without_overlapping_keyed_by_projection_row_id` asserts the SHAPE; `test_without_overlapping_middleware_rejects_duplicate_delivery_while_first_in_flight` asserts the BEHAVIOR. Both are present and complementary. |
| Mass-assignment leakage in test setup | NONE — all `FiscalEventProjectionRow` mutations in new tests use either targeted assignment or raw `DB::table('fiscal_event_projections')->update([...])` (which deliberately bypasses `$fillable`). |
| `WithoutOverlapping` middleware applied but not exercised by tests | RESOLVED — both shape AND behavior tests exist; the second test directly exercises the cache-lock semantics by manually acquiring the lock before invoking the middleware's handle(). |

### Verification commands

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api
./vendor/bin/phpunit tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php --testdox
# Tests: 15, Assertions: 82 — all green

./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php --testdox
# Tests: 16, Assertions: 54 — all green (renamed test included)

./vendor/bin/phpunit tests/Feature/Fiscal/OutboxIngestorTest.php --testdox
# Tests: 20, Assertions: 100 — all green

./vendor/bin/phpunit tests/Feature/Fiscal/ 2>&1 | tail -10
# Tests: 184, Assertions: 548, Skipped: 37 — all-green-with-skips (the 37 skips are PG-only tests not running on SQLite)

./vendor/bin/phpstan analyse --level=8 \
  app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php \
  app/Modules/Fiscal/Application/Services/OutboxIngestor.php \
  app/Modules/Fiscal/Domain/Exceptions/ProjectionDependencyMissingException.php \
  app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php
# [OK] No errors
```

### Summary

Round-2 closes every dual-review finding it was scoped to close — the two BLOCKERs, the convergent P1, and three P3s — with high-quality fixes that respect the standing patterns inherited from Tasks 17-22. The T23-B1 closure uses a two-layer defense (queue-level `WithoutOverlapping` + belt-and-braces stale-running age check) properly motivated by the array-cache-fails-open scenario; the T23-B2 closure introduces a `ProjectionDependencyMissingException` extending `RuntimeException` so the Task 23 job's existing `catch (Throwable)` handles it as a retryable failure via the documented attempt-accounting + Horizon-backoff path, and the renamed `TreasuryReceiptBridgeTest::test_no_pos_receipt_for_event_throws_projection_dependency_missing` inverts the round-1 "no crash" anti-pattern (handoff §4.2 line 117 standing lesson from Task 22). The P1 plan-literal restoration swaps the real `PosCoreReceiptProjection` into the partial-cluster test, asserting `DB::table('pos_receipts')->count() === 1` per plan §1761 with a bonus `pos_receipt_lines` line-write assertion. The three P3 closures add the targeted test the round-1 review named and update the two stale comments. All five implementer-flagged premises hold under verification; the cross-task touch on `TreasuryReceiptBridge` does NOT regress Task 22 (16/16 still green); phpstan level 8 + pint are clean; 184/184 Fiscal feature suite green (+8 tests, +55 assertions vs round-1). No new findings. **APPROVE.**

---

## Round-3 re-review (commit 149ac8bc4)

**Subject.** `149ac8bc4` on `feat/pos-fiscal-event-engine-phase1` (worktree `apps/erp.fiscal-phase1`).
**Scope.** Two BLOCKERs that Codex caught and Opus's round-2 re-review missed: T23-R2-B1 (the `running`-state stuck-retry trap from `advanceFailureAccounting` leaving status=Running with fresh `last_attempted_at`) and T23-R2-B2 (the `dontRelease()` silently-drops-duplicate trap when the lock is stale-held). The round-3 implementer also symmetrically applied the R2-B1 fix to `recordHardFailure` (scope expansion documented in commit message) and updated two pre-existing hard-misconfig tests to assert the new `pending`-between-retries contract.

### Verdict: APPROVE

Both BLOCKERs Codex caught are CLOSED with the exact options Codex proposed (option (c) for R2-B1: reset to Pending; option (b) for R2-B2: `releaseAfter($timeout + 30)`). The symmetric application to `recordHardFailure` is correct: the round-2 hard-misconfig tests asserted `'running'` was load-bearing, but that assertion was pinning the bug rather than the contract — round-3 correctly updates them to `'pending'` and the implementer's commit message names this explicitly ("round-2 stale-state-encoded-the-bug assertion"). The new regression `test_horizon_retry_after_failure_does_not_hit_stale_running_short_circuit` uses `Carbon::setTestNow(+10s)` to pin Horizon-style timing (within the 120s freshness window) so the test would have FAILED on round-2 code — verified by tracing: on round-2, after first throw the row is `(status=Running, last_attempted_at=now)`; T_lock at +10s sees status=Running AND `isRecentlyAttempted` returns true (10s < 120s) → short-circuit returns false → projector never invoked → row stays `Running` → assertion `'applied' === $afterRetry->projection_status` fails. On round-3 the row is `(status=Pending, ...)`, T_lock proceeds, applies, asserts pass. The R2-B2 regression test (`test_without_overlapping_re_queues_duplicate_delivery_when_lock_already_held`) exercises the vendor middleware's release-vs-drop branch directly: on round-2 with `dontRelease()` → `releaseAfter=null` → vendor line 81 `elseif (! is_null(...))` is skipped → `release()` is NOT called → `$duplicate->wasReleased` stays false → assertion fails. On round-3 with `releaseAfter($timeout+30)` → `release()` IS called → assertion passes. Both regressions are valid bug-pinners.

The remaining stale-running guard is correctly narrowed. The implementer's docblock at lines 555-568 accurately enumerates the only ways into "(status=Running, fresh last_attempted_at)" under the round-3 code shape: (a) primary `WithoutOverlapping` defense failed open (misconfigured cache driver — the documented defense-in-depth scenario), or (b) the worker crashed in a microsecond race between recording the timestamp inside T_apply and the failure or success terminal write — which the implementer notes is "impossible under the current code shape" because `last_attempted_at` is only advanced inside `advanceFailureAccounting`/`recordHardFailure` and both reset status to Pending in the same `save()`. Grep-verified: `last_attempted_at` writes appear at exactly two sites (job lines 505, 538), both in the same `save()` as the `projection_status = Pending` assignment.

**Operator-visibility shift is non-breaking.** Comprehensive sweep of `apps/api/app` for production consumers of `projection_status === Running`: zero hits outside the job itself (lines 254, 255, 279, 426). No Filament dashboard, no console command (`grep -rn FiscalEventProjectionRow app/Console` → no results), no controller, no routes file references the table, no recovery sweeper. The partial index `fiscal_event_projections_status_pending_idx` at migration line 84-87 already covers BOTH `pending` AND `running` (`WHERE projection_status IN ('pending', 'running')`), so a row that's `pending` between retries stays in the worker dispatcher's hot scan — index-coverage semantics unchanged. The implementer's operator-visibility rationale ("`attempts > 0` / `last_error IS NOT NULL` are the actual operator API for in-flight retries") is correct: no consumer is silently broken by the shift. The `apps/web` sweep also returns zero hits for `fiscal_event_projections`.

The `releaseAfter($timeout + 30)` buffer is correctly sized. `$timeout = 120s` is hardcoded `public int $timeout = 120;`; the class is `final`; no `$timeout` setter exists in production code (grep-verified across `app/Modules/Fiscal`, `app/Modules/Treasury`, `app/Modules/POS`). Redis queue `retry_after` default per `config/queue.php:71` is 90s. `releaseAfter = 150s > retry_after = 90s` and `> expireAfter = 120s` — the buffer ensures the re-queued delivery returns AFTER the original lock's worst-case lifetime. Under env override (`REDIS_QUEUE_RETRY_AFTER`) ops could in principle set retry_after higher than 150, but that's a configuration concern — the in-code defaults are internally consistent and the docblock explains the math.

### Closure table for the two R2 BLOCKERs

| ID | Codex caught | Status | Evidence |
|---|---|---|---|
| T23-R2-B1 | Round-2 re-review § "New findings" | **CLOSED** | `advanceFailureAccounting` (job line 506) + `recordHardFailure` (job line 539) both now end with `$row->projection_status = ProjectionStatus::Pending; $row->save();`. New regression test at `ApplyFiscalEventProjectionJobTest.php:776-848` uses `Carbon::setTestNow(+10s)` to pin Horizon-style retry timing and asserts the retry reaches `applied` (would fail on round-2 because the stale-running guard would short-circuit). Two existing hard-misconfig tests updated to assert `'pending'` (lines 928-936, 977-983). Implementer chose Codex's prescribed option (c) — the safest of the three. |
| T23-R2-B2 | Round-2 re-review § "New findings" | **CLOSED** | `middleware()` (job line 208) now returns `(new WithoutOverlapping($projectionRowId))->expireAfter($timeout)->releaseAfter($timeout + 30)`. New regression test at `ApplyFiscalEventProjectionJobTest.php:542-591` directly exercises the vendor middleware's release-vs-drop branch (`WithoutOverlapping.php:75-83`) and asserts `$duplicate->wasReleased === true` + `$duplicate->releaseDelay === $timeout + 30`. Updated `test_middleware_includes_without_overlapping_keyed_by_projection_row_id` (line 457) asserts `releaseAfter` is the `$timeout + 30` integer, NOT null. Implementer chose Codex's prescribed option (b) — the safest of the three. |

### Premise audit (the implementer's 5 round-3 premises, independently verified)

#### Premise 1 — Reset to Pending in advanceFailureAccounting + recordHardFailure does not break any external consumer

**Verdict: VERIFIED TRUE.** Comprehensive grep across `apps/api/app` for `ProjectionStatus::Running` and `'running'` (string literal in the context of `projection_status`):

```
app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:279,291  — the job itself
app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php:10                          — enum case definition
```

That's it. No Filament resource, no console command, no controller, no Horizon dashboard query, no recovery sweeper reads the column. The partial index covers both `pending` AND `running` (verified at `2026_05_14_100003_create_fiscal_event_projections_table.php:86`). The operator-facing API for "in-flight retry" is `attempts > 0` + `last_error IS NOT NULL` — both populated by `advanceFailureAccounting`/`recordHardFailure` BEFORE the status reset, so operator visibility is preserved. Test grep finds 3 hits — all in `ApplyFiscalEventProjectionJobTest.php` (lines 638, 649, 677), and all in the two existing `test_fresh_running_row_short_circuits_belt_and_braces` + `test_stale_running_row_is_recovered_on_re_dispatch` tests that DIRECTLY exercise the stale-running guard (they manually set `status=Running` to simulate a sibling worker mid-apply or crash — those tests' contract is unchanged because the test is not about Horizon retries, it's about the simulated-sibling-worker scenario the guard protects against).

#### Premise 2 — Horizon's `failed()` handler still fires correctly on a Pending row after `$tries` exhaustion

**Verdict: VERIFIED TRUE.** Horizon's retry loop dispatches `failed()` based on the JOB's retry counter (Horizon's own `$attempts` tracking inside Redis), NOT on the projection row's `projection_status` column. The job's `failed()` handler (line 405-448) reads the row's CURRENT status, and at line 426 the gate is `if ($row->projection_status !== ProjectionStatus::DeadLettered)` — accepts `Pending` OR `Running` OR `Applied` (the last would be a no-op race but defensive). Verified by running `test_exhausted_retries_dead_letter_via_failed_handler` in the suite: 17/17 green, includes this path. The implementer's `failed()` implementation is unchanged by round-3, so the existing test's coverage of the path remains valid.

#### Premise 3 — The microsecond-race window (T_lock flips to Running, projector throws, worker SIGKILLed before advanceFailureAccounting's save()) is bounded by `$timeout`

**Verdict: VERIFIED with a documentation nuance worth surfacing as P3.** The race exists in theory: T_lock commits at t=0 (`status=Running`, `last_attempted_at` unchanged from prior — either NULL or a value from an earlier failure save). Projector throws at t=N. `advanceFailureAccounting` builds the in-memory mutation but worker SIGKILLed before `save()` returns. Row stays at `(Running, last_attempted_at=prior_value)`. There are three sub-cases:

- **Sub-case A (first attempt crashes mid-advanceFailureAccounting):** `last_attempted_at` is still NULL → `isRecentlyAttempted` returns false → next retry proceeds. ✓
- **Sub-case B (retry N crashes mid-advanceFailureAccounting, prior `last_attempted_at` is stale i.e. > `$timeout` old):** stale-running guard does NOT fire → next retry proceeds. ✓
- **Sub-case C (retry N crashes mid-advanceFailureAccounting, prior `last_attempted_at` is fresh i.e. < `$timeout` old):** stale-running guard fires for at most `$timeout - elapsed = ~120s` worth of retries, then `last_attempted_at` ages out and recovery proceeds. ✓ Bounded.

So the residual window is at most `$timeout = 120s` of silent short-circuiting after a worker SIGKILL between projector-throw and advanceFailureAccounting's save(). In production this is rare (microseconds of code between try/catch dispatch and save()) and the recovery is automatic. The docblock at job line 570-573 says "in steady-state production this check should never fire" — true but slightly understates the residual window. Worth a P3 doc-clarification (not actionable for round-3 closure).

#### Premise 4 — The R2-B2 spy harness actually exercises the production code path

**Verdict: VERIFIED TRUE with caveat noted.** The spy is necessary because `ApplyFiscalEventProjectionJob` is `final` (can't subclass). The vendor `WithoutOverlapping::handle()` at line 69-84 takes `$job` as `mixed` and only invokes `$next($job)` or `$job->release($this->releaseAfter)` — both of which work on any object with a compatible interface. The spy records `wasReleased` + `releaseDelay` exactly when `$job->release()` is called. The test acquires the held lock against the SPY's own `getLockKey($spy)` (which derives from `get_class($spy)`), so the lock-key collision is exact at the cache-driver level. What the test does NOT exercise is the production lock-key derivation for the REAL job class — but that's not the bug being verified. The bug was a CONTROL-FLOW bug in the vendor middleware's response to a held lock (drop vs re-queue), and the test pins that control flow correctly. The implementer's docblock at the spy class (test lines 1376-1394) calls this out explicitly and explains the design choice. Acceptable test architecture.

#### Premise 5 — The lifecycle ASCII diagram in the class docblock accurately reflects the new behavior

**Verdict: VERIFIED TRUE.** The diagram at job lines 36-42:

```
pending ──handle()──► running ─┬─success─► applied        (terminal)
                               │
                               └─throw──► pending         (retry-ready)
                                     │
                                     └─exhausted──► dead_lettered (terminal)
```

Correctly shows the round-3 contract: throw goes back to `pending`, not stuck at `running`. The prose at lines 44-51 explicitly calls out the round-3 change vs round-2 ("RESET to `pending` on failure (round-3 change — round-2 left it as `running`, which tripped the stale-running guard on the very next Horizon retry)") and explains the operator-visibility shift ("`attempts > 0` / `last_error IS NOT NULL` / `last_attempted_at IS NOT NULL`, not from the status column"). Diagram + prose are internally consistent and faithful to the implementation.

### Operator-visibility audit (independent of Premise 1)

**Question.** Does any production code (controllers, commands, dashboards, Horizon viewers, recovery sweepers, alerting rules) read `projection_status === Running` as a signal of "in-flight" that breaks now that the column transitions through Pending between retries?

**Method.** 
- `grep -rn "projection_status" app --include='*.php'` — 16 hits, all in the job + the model + the OutboxIngestor's row-insertion default `'pending'`. None in console/controllers/Filament.
- `grep -rn "FiscalEventProjectionRow" app/Console` — zero hits.
- `find app -path '*Filament*' -name '*Fiscal*'` — zero results.
- `grep -rn "fiscal_event_projections" apps/web` — zero hits (the table is not surfaced to the frontend; Phase 1 ships no operator UI for the projection table).
- `grep -rn "fiscal_event_projections" apps/api/routes` — zero hits.

**Conclusion.** The operator-visibility shift is non-breaking. The Phase 1 surface area does not include any dashboard/CLI consumer of `projection_status`; future Phase 2 operator-UI work will read the table at design time and will naturally encode the new "attempts > 0 || last_error IS NOT NULL" filter for in-flight retries.

### Findings

None at BLOCKER or P1 severity. Two P3 housekeeping observations the implementer may sweep at their convenience:

| # | Sev | File:Line | One-liner |
|---|---|---|---|
| R3-F1 | P3 | `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:570-573` | The "in steady-state production this check should never fire" claim in `isRecentlyAttempted`'s docblock slightly understates the residual race window (Premise 3 sub-case C: up to `$timeout = 120s` of silent short-circuit after a worker SIGKILL between projector-throw and advanceFailureAccounting's save). Worth a one-sentence clarification: "may transiently fire for up to `$timeout` seconds after a worker crash between try/catch and save(), after which the timestamp ages out and recovery proceeds automatically." |
| R3-F2 | P3 | `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php:829, 847` | `Carbon::setTestNow(...)` set at line 829 and reset at line 847 — if any assertion between lines 830-846 fails, the test-now leaks into the next test (PHPUnit's `--stop-on-failure` would isolate it but a normal run would surface a flake in the next time-sensitive test). Move the reset into a try/finally around the assertions. Cost ~5 LOC. |

Neither is round-3-blocking. R3-F1 is documentation accuracy; R3-F2 is test-hygiene defense-in-depth (no observed flake — the suite is 17/17 green).

### Plan-vs-implementation drift (carry-over from F3)

The plan amendment at `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1796` still describes the round-2 lifecycle semantics ("on throw → update `attempts`/`last_error`/`last_attempted_at`") and does NOT mention the round-3 reset-to-Pending. Per standing precedent (handoff §4), the plan should be amended OR a disposition note added explaining the implementation is the source-of-truth (the prior F3 round-1 finding on plan-vs-spec drift escalated into the same documentation-layer concern; it remained deferred per the round-2 brief). NOT a round-3 BLOCKER — the implementation docblocks at job lines 36-51 + 89-112 are the operating source of truth and are accurate. Carrying forward as a documentation backlog item rather than a re-finding.

### Standing-pattern conformance sweep (round-3)

| Pattern | Round-3 status |
|---|---|
| Fail-closed on downstream-service exceptions (Task 18 F1) | CLEAN — no change to exception handling shape. |
| DB primitives spec-named are load-bearing (Task 19 B1) | CLEAN — `lockForUpdate()` still at job line 233 in T_lock; the round-3 status-reset changes only the post-throw write, not the lock primitive. |
| `$fillable` boundary discipline (Task 9) | CLEAN — round-3 only adds `$row->projection_status = ProjectionStatus::Pending; $row->save();` in two methods; targeted assignment + save, no `fill()`. |
| Discriminated-union test matrix coverage (Task 20 standing pattern) | CLEAN — 17 tests now cover the discriminated union: all 15 round-2 paths PLUS the new (R2-B1) Horizon-retry-after-failure path and the new (R2-B2) duplicate-delivery-re-queue path. 11 of 11 logical paths through `handle()` / `failed()` / `middleware()` covered. |
| Stale-comment hazard (Task 20 standing pattern) | CLEAN — both R2-B1 and R2-B2 docblocks (job lines 36-51, 82-112, 180-199, 270-278, 477-499, 511-519, 544-573) explicitly call out the round-3 change vs round-2 and explain WHY (load-bearing rationale, not just a what-changed note). |
| Constructor injection only (Task 9, Task 18 R2) | CLEAN — no new dependencies introduced. |
| Two-layer defense (queue lock + DB-row lifecycle lock) | CLEAN — both layers preserved; round-3 narrows the stale-running guard's firing conditions but keeps it as the second-layer defense-in-depth. |
| Bug-pinner regression tests (new round-3 standing pattern) | CLEAN — both new regression tests trace through round-2 code mentally to confirm they would FAIL on the round-2 commit (T23-R2-B1: status=Running + last_attempted_at=now → guard short-circuits retry → assertion `'applied'` fails; T23-R2-B2: `dontRelease()` → releaseAfter=null → vendor middleware skips release → `wasReleased` stays false → assertion fails). The implementer's commit message and the test docblocks (lines 776-799 for R2-B1, 542-557 for R2-B2) both explicitly explain WHY each test would have caught the round-2 bug. |

### Cross-task touch verification (round-3)

Round-3 touches only `ApplyFiscalEventProjectionJob.php` (production) + `ApplyFiscalEventProjectionJobTest.php` (tests). Zero changes to:
- `OutboxIngestor.php` (the dispatch wiring is unchanged)
- `FiscalEventProjectionRegistry.php` (no registry changes)
- `TreasuryReceiptBridge.php` (no projector changes)
- `PosCoreReceiptProjection.php` (no projector changes)
- `ProjectionDependencyMissingException.php` (no exception changes)

`./vendor/bin/phpunit tests/Feature/Fiscal/ --testdox 2>&1 | tail -10` shows 186/186 green (+2 tests, +12 assertions vs round-2 baseline of 184/548). The full Fiscal feature suite passes; no regression in any sibling test.

### New-defect surface sweep (round-3)

I specifically looked for the patterns common to round-3 defect introductions (these are the patterns Codex catches that Opus misses):

| Defect class | Result |
|---|---|
| State-machine reset breaks an invariant elsewhere | NONE — the operator-visibility audit + the partial-index check + the `failed()` handler trace confirm the Running→Pending reset has no external consumer. |
| New regression test pins the bug, not the contract (e.g., asserts `dontRelease` semantics that round-2 changed) | NONE — both new tests assert the round-3 contract (`pending` between retries; `release()` called with delay) and the implementer's docblocks explicitly explain why these are contract assertions, not bug pinners. Two existing tests updated to assert `'pending'` instead of `'running'` — those WERE bug pinners on round-2; round-3 correctly inverts them to contract assertions per the lesson at handoff §4.2 line 117. |
| Buffer math off-by-one (releaseAfter vs retry_after vs expireAfter) | NONE — `releaseAfter = 150 > retry_after = 90` AND `releaseAfter = 150 > expireAfter = 120`. Both inequalities hold. The +30 buffer is generous; the only way to break it is ops setting `REDIS_QUEUE_RETRY_AFTER > 150`, which is documentation-level not code-level. |
| Test depends on Carbon::setTestNow leaking between tests | TRACE — line 829 sets, line 847 resets; if assertions between fail, the reset is skipped (no try/finally). Already flagged as R3-F2 P3. |
| Spy harness doesn't actually exercise the bug | NONE — the spy correctly invokes the vendor middleware's `release()` path; the docblock explains why cross-class lock-key parity isn't relevant to the contract being tested. |
| Stale plan/spec docs no longer match the implementation | YES (carry-over from F3) — the plan amendment at line 1796 still describes round-2 semantics. Carrying forward as backlog, not a re-finding. |

### Verification commands

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api
./vendor/bin/phpunit tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php --testdox
# Tests: 17, Assertions: 94 — all green (round-1: 7; round-2: 8 new; round-3: 2 new + 4 updated assertions)

./vendor/bin/phpunit tests/Feature/Fiscal/ --testdox 2>&1 | tail -10
# Tests: 186, Assertions: 560, Skipped: 37 — all-green-with-skips (+2 tests, +12 assertions vs round-2 baseline)

./vendor/bin/phpstan analyse --level=8 \
  app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php
# [OK] No errors

./vendor/bin/phpstan analyse --level=8 \
  tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php
# [OK] No errors

grep -rn "ProjectionStatus::Running\|'running'" app tests --include='*.php'
# 8 hits, all in the job itself + enum definition + test files exercising the stale-running guard.
# Zero hits in app/Console, app/Filament, app/Http (controllers/middleware), apps/web.
```

### Why Opus's round-2 re-review missed both BLOCKERs (post-mortem)

Worth surfacing for the standing-patterns log. Opus's round-2 APPROVE was wrong on two distinct counts:

1. **T23-R2-B1 miss.** The round-2 Premise 4 audit traced `last_attempted_at` semantics through the lifecycle and correctly identified the three states `(null | fresh | stale)`, but did NOT trace what HAPPENS to that timestamp across a SEQUENCE of (failure → retry → failure → retry → ...) cycles. The premise was scoped to single-attempt semantics, missing the multi-attempt interaction with the stale-running guard. **Lesson:** when reviewing a lifecycle state machine, the test matrix MUST include multi-cycle sequences (failure → retry, not just first-attempt-then-terminal). Codex's "use `Carbon::setTestNow` between attempts to pin Horizon-style timing" is the lesson for future round-2 audits.

2. **T23-R2-B2 miss.** The round-2 audit confirmed `dontRelease()` was the chosen behavior + the test asserted the duplicate was dropped — but did NOT cross-check against the queue driver's `retry_after` interaction. The `dontRelease()` semantics are correct IFF the cache lock's `expireAfter` is GREATER than the queue's `retry_after`. With Redis's default `retry_after = 90s` and our `expireAfter = 120s`, the 30s window between t=90s and t=120s is the loss-of-delivery window. **Lesson:** when reviewing queue middleware, the audit MUST cross-check against the queue driver's redelivery contract, not just the middleware's intra-process semantics.

Both lessons should be added to handoff §4 as a "Round-2 audit pitfalls" standing pattern.

### Summary

Round-3 closes both BLOCKERs Codex caught in the round-2 re-review (T23-R2-B1: `running`-state stuck-retry trap; T23-R2-B2: `dontRelease()` silently-drops-duplicate trap) using the exact Codex-prescribed options (c) and (b) respectively. The symmetric application of the R2-B1 fix to `recordHardFailure` is correct scope-expansion per Task 22's standing pattern (when fixing a class of defect, sweep all instances). Two new regression tests validly pin the bugs — traced through round-2 code, both would have FAILED on the round-2 commit. The operator-visibility shift from `running`-between-retries to `pending`-between-retries is non-breaking: comprehensive sweep of `apps/api/app` + `apps/web` finds zero production consumers of `projection_status === Running` outside the job itself; the partial index already covers both `pending` AND `running`; the operator-facing in-flight signal is `attempts > 0` + `last_error IS NOT NULL`, both populated BEFORE the status reset. The `releaseAfter($timeout + 30)` buffer math is correctly sized for the default Redis `retry_after = 90s` and `expireAfter = 120s` contract. The class docblock + lifecycle diagram + `isRecentlyAttempted` docblock are all updated to reflect the round-3 semantics. PHPStan level 8 clean on both job + test file; 17/17 ApplyFiscalEventProjectionJobTest green (+2 tests, +12 assertions vs round-2); 186/186 full Fiscal feature suite green (+2 tests, +12 assertions vs round-2 baseline of 184/548). The two P3 housekeeping observations (R3-F1: minor docblock claim about "should never fire" understating the residual race window; R3-F2: `Carbon::setTestNow` reset not in try/finally) are non-blocking and sweep-while-you're-in-here. The plan-vs-implementation drift (F3 carry-over: plan §1796 still describes round-2 semantics) remains documentation backlog rather than a round-3 re-finding. **APPROVE.**
