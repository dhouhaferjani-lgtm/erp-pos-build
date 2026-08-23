# Adversarial merge gate — round 3 (final) — `fix/r8-shift-variance-queue`

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/r8-sv-queue`, branch `fix/r8-shift-variance-queue`, tip `dc7df863a`.
- **Under review this round:** `7db5fe467..dc7df863a` — the single commit *"r8 gate round 2: report() the swallowed fault, make the scanner guard real, tell the truth about all-or-nothing"*. Rounds 1 and 2 verified the mechanism end-to-end; nothing from `2026-08-23-r8-sv-queue-gate-r1.md` / `-r2.md` is re-litigated here. This round answers exactly one question: **do the round-3 changes close F-1..F-6?**
- **Reviewer posture:** read-only on the lane. `git status --porcelain` in the worktree was empty before this review, after each of the five scratch tampers below, and at the end. My only durable write is this file, in the main checkout.
- **Class-resolution check (before trusting any run):** `ReflectionClass::getFileName()` resolves
  `App\Modules\POS\Application\Services\CashCountDispatcher` → `…/.worktrees/r8-sv-queue/apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php`,
  `App\Modules\Treasury\Application\Listeners\PostShiftCashVarianceAdjustment` → `…/.worktrees/r8-sv-queue/apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php`,
  `Illuminate\Support\Facades\Exceptions` → `…/.worktrees/r8-sv-queue/apps/api/vendor/…`. `realpath(vendor)` = the worktree's own `vendor`, `is_link(vendor)` = false. **Worktree code, worktree vendor.** Every run below is trustworthy.

---

## 0. Scope audit (item 7) — CLEAN

`git diff --stat 7db5fe467..dc7df863a` — **6 files, +379 / −62**, exactly as declared:

| File | Justified by |
|---|---|
| `apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php` (+121/−…) | F-1, F-3(a) docblock, F-4 |
| `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php` (+13/−…) | F-6 (import removed), F-3 docblock cross-reference |
| `apps/api/tests/Architecture/QueuedListenerTenantContextTest.php` (+79/−…) | F-5 |
| `apps/api/tests/Feature/POS/CashCountDispatchGuardTest.php` (+105) | F-1 pin, F-3(b) pins |
| `apps/api/tests/Unit/Config/HorizonQueueCoverageTest.php` (+87/−…) | F-2 |
| `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md` (+36/−…) | F-3(c) operator truth |

**Nothing** under `config/`, `database/migrations/`, `.github/`, no deptrac baseline, no ratchet manifest, no fixture-data change (the `queued-listener-deferrals.json` fixture is *read* differently but is byte-unchanged). No new G-5 surface. Scope claim holds.

---

## 1. F-1 — `report($e)` is the first of three independently-guarded legs — CLOSED

**Code.** `CashCountDispatcher.php:135-193`, `recordUndeliverable()`:

- `:137-143` — `try { report($e); } catch (Throwable) { … }`. **First**, in its own guard.
- `:145-156` — the `Log::error(…)` leg, now inside its own `try/catch`.
- `:158-193` — the `auditService->record(…)` leg in its own `try`, and its `catch (Throwable $auditFailure)` block now wraps the `Log::critical(…)` in a further `try/catch` (`:186-192`).

Three separate guards, not one outer one. That is the shape the finding asked for: **a failing audit store cannot suppress the Sentry event, and a broken logger cannot cost the audit row.** (Strictly, the independence is bought by the per-leg guards rather than by the ordering — with those guards in place `report()` would survive even if it ran last. The docblock at `:122-123` attributes it to the order; harmless, and the order is legitimate defence-in-depth. Recorded as **M-3**, not a defect.)

**Run.** `tests/Feature/POS/CashCountDispatchGuardTest.php::test_a_swallowed_consumer_fault_still_reaches_the_error_reporter` (`:145-162`) — `Exceptions::fake()`, a real `Event::listen` that throws, a real `app(CashCountDispatcher::class)->dispatch(...)`, then `Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'fraud alert store is down')`. **Green** as part of the 13-test run below.

**Tamper (scratch, restored).** Commenting out `report($e);` at `:138`:

```
1) …CashCountDispatchGuardTest::test_a_swallowed_consumer_fault_still_reaches_the_error_reporter
The expected [RuntimeException] exception was not reported.
```

Red for the right reason. `git checkout --` restored; `git status --porcelain` empty. **The pin is real, not decorative.**

---

## 2. The disclosed deviation — no `report()` in `PostShiftCashVarianceAdjustment::refuse()` — ACCEPT, with the rationale corrected

I verified the premise rather than the conclusion, and the premise as *stated* is imprecise — but the conclusion survives on a stronger argument.

**Census of `refuse()` callers** (`PostShiftCashVarianceAdjustment.php`):

| Line | Reason code | Shape |
|---|---|---|
| `:316` | `feature_disabled_after_enqueue` (level `error`) | ops/env skew — **no exception object exists** |
| `:338` | `insufficient_repository_balance` | policy refusal (`allowNegative: false`) |
| `:346` | `repository_frozen` | policy refusal (`allowWhileFrozen: false`) |
| `:579` | `aggregate_not_attributable` | policy |
| `:599` | `aggregate_breakdown_mismatch` | policy |
| `:609` | `unattributable_tolerance_writeoff` | policy |
| `:630` | `currency_mismatch` | policy |
| `:780` | `tender_not_physical_or_unknown` | policy |
| `:795` | `no_repository_resolved` | policy |
| `:806` | `ambiguous_repositories` | policy |
| `:827` | `resolved_repository_is_not_a_cash_till` | policy |
| **`:459`** | **`reasonFor($e)` → `unbalanced_journal_entry` \| `exception`, via `deadLetter()`** | **EXCEPTION-SHAPED** |

So: **eleven** non-dead-letter reason codes (not six — see **M-2**), and `deadLetter()` at `:457-463` *is* a `refuse()` caller and *is* exception-shaped. "Nothing exception-shaped lands in `refuse()`" is literally false.

**Why the deviation is nonetheless correct.** The exception-shaped path reaches the error reporter through the **worker**, not through `refuse()`:

- `retryOrDeadLetter():390-410` — while `connectionCanRetry()` is true it logs and **re-throws** (`:409`).
- `Worker::handleJobException()` (`vendor/laravel/framework/src/Illuminate/Queue/Worker.php:510-544`) marks the job failed / releases it, then **`throw $e;`** at `:543`.
- `Worker::runJob():434-441` catches that and calls **`$this->exceptions->report($e)`** at `:437`.

So on every attempt, including the last, a genuine crash and an `UnbalancedJournalEntryPostException` both hit Sentry via the framework — and `failed()` → `deadLetter()` then adds the durable recovery row. Adding `report()` inside `refuse()` would double-report those *and* spam Sentry with eleven legitimate policy refusals (a frozen till, an unresolvable tender). **The lane's conclusion is right; only its stated reason is thin.**

The connection is the production one: `QUEUE_CONNECTION=redis` at `.env:61` and `.env.example:100`.

**Residual, non-blocking (M-5).** `connectionCanRetry():423-426` is false under `sync` or a null `$this->job`; that path (`:392-396`) dead-letters **without** re-throwing, so no worker reports it and `refuse()` does not either. It is byte-for-byte the pre-R-8 disposition (the base already caught `Throwable` into an `exception` refusal), so this lane introduces no new quiet, and it is not the configured connection. Worth one line on the G-5 checklist, which already mandates `QUEUE_CONNECTION=redis`.

`feature_disabled_after_enqueue` (`:316`, level `error`) is the one arm that *should* page and has no exception to report; it is covered by the checklist's existing instruction to alert on `treasury.shift_variance_gl_skipped`. Acceptable.

---

## 3. F-2 — `scanQueueNames()` extraction — CLOSED

- **The production assertion uses the same method.** `HorizonQueueCoverageTest.php:54-56` — `foreach ($this->scanQueueNames($contents) as $queue) { $dispatchedQueues[$queue] = true; }`. The two inline `preg_match_all` blocks are gone; both forms (`onQueue('…')` and `public [readonly] [?string] $queue = '…'`) now live only in `scanQueueNames()` (`:105-127`).
- **The integrity test calls the real scanner.** `test_the_scanner_sees_queue_declared_as_a_property` (`:140-153`) first asserts the listener contains no `onQueue(` (so the fixture stays meaningful), then `assertContains('default', $this->scanQueueNames($contents))`.

**Tamper (scratch, restored).** Deleting the `$queue`-property branch from `scanQueueNames()`:

```
1) …HorizonQueueCoverageTest::test_the_scanner_sees_queue_declared_as_a_property
The real scanner no longer sees a $queue property declaration — every queued listener
in the codebase just became invisible to the Horizon coverage guard.
Failed asserting that an array contains 'default'.
```

Red. The exact regression the docblock promised, which the r2 version could not detect, is now detected. Restored clean.

---

## 4. F-3 — asymmetric all-or-nothing: truthfully documented, and pinned three ways — CLOSED

**Documentation now matches behaviour.** `CashCountDispatcher.php:37-68` spells out the fixed order (queue push → fraud alert → Spatie wildcard stored-event write) and states both asymmetric cases explicitly. The deploy-notes ticket (`docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md:122-142` and the SQL comment at `:161-171`) now tells the operator to read the `exception` field before concluding anything, and replaces the r2-flagged false line ("no consumer ran") with the two-case split — plus a new pre-enable checkbox to decide on per-listener isolation before the flag flips. The listener's own docblock (`:157-166`) points at the same asymmetry.

**The lane's disclosure that these are CHARACTERIZATION pins, not red-first, is correct and acceptable** — the behaviour is pre-existing (Laravel's `Dispatcher::invokeListeners()` has no per-listener try/catch) and the pins exist to prevent a *silent* change.

**Does the healthy-queue control genuinely discriminate?** This was the sharp question, and the answer is yes — proven by tamper, not by reading.

*Tamper A — break the fraud listener only* (early `return;` at the top of `OpenFraudAlertForShiftVariance::handle()`, `:22-24`):

```
....F..                                                             7 / 7
1) …CashCountDispatchGuardTest::test_a_healthy_dispatch_lets_every_consumer_run
Failed asserting that 0 is identical to 1.
```

Exactly the designed outcome: `test_a_push_failure_suppresses_the_later_consumers_and_says_so` **still passes vacuously**, and the control is the only thing that catches it. Without the control, "0 fraud alerts after a push failure" would be indistinguishable from "the fraud listener is dead". **The control is load-bearing and it works.**

*Tamper B — swap `TreasuryServiceProvider` / `ComplianceServiceProvider` in `bootstrap/providers.php:76-77`*:

```
...F.F.                                                             7 / 7
1) …::test_a_push_failure_suppresses_the_later_consumers_and_says_so
   A push failure is expected to abort the fraud alert — … Failed asserting that 1 is identical to 0.
2) …::test_treasury_is_registered_ahead_of_compliance
   … Failed asserting that 16 is less than 15.
```

Two reds: one **behavioural** (the fraud alert now survives a push failure, inverting the documented semantics) and one **structural** naming the cause. A provider reshuffle can no longer silently invert which consumers survive which fault. Both tampers restored; worktree clean.

---

## 5. F-4 / F-5 / F-6 — CLOSED

- **F-4 (never-throws now covers the logger legs).** Verified in code at `CashCountDispatcher.php:145-156` (the `Log::error` leg) and `:186-192` (the nested `Log::critical`). Both were outside any `try` at `7db5fe467`; both are guarded now. The method as a whole has no unguarded statement, so the "Never throws" docblock at `:110` is finally accurate.
- **F-5 (deferrals escape hatch validated).** `loadDeferralsFresh():313-334` now requires `class`, `deferred_to_cluster` **and** `tracked_in` to all be non-empty strings or the entry is skipped (`continue`) and exempts nothing; the new `test_every_deferral_carries_a_cluster_and_a_justification()` (`:246-286`) names the malformed entry.
  **Tamper (scratch, restored):** deleting `tracked_in` from the Loyalty entry produced **two** failures — `test_every_concrete_queued_listener_is_tenant_classified` (`EarnPointsOnReceiptCompleted` fell into `$unclassified`) **and** the new fixture-validation test. A malformed entry fails **both** the validation and the exemption, exactly as claimed. Fixture restored.
  *Residual (M-4):* still no cap / shrink-only ratchet on the entry count, and the four `tracked_in` values are prose rather than a ticket id. r2 flagged both as "ideally"; the non-empty assertion is the part that was asked for.
- **F-6 (cross-module import).** `grep -n '^use'` on the listener shows **no** `use App\Modules\POS\Application\Services\CashCountDispatcher;` — the docblock at `:160` uses the fully-qualified name in prose instead. The only remaining POS imports are `CashCountBreakdownDTO` and `CashCountRecorded` (a DTO and the event — rule 6's sanctioned channel), both pre-existing.

---

## 6. Verification runs (item 6) — as claimed

Everything below run by path, sqlite (`phpunit.xml`), in the worktree:

| Run | Result |
|---|---|
| `tests/Feature/POS/CashCountDispatchGuardTest.php` + `tests/Unit/Config/HorizonQueueCoverageTest.php` + `tests/Architecture/QueuedListenerTenantContextTest.php` | **OK (13 tests, 36 assertions)** — the declared 13/36, matched exactly |
| `tests/Feature/Treasury/ShiftCashVarianceQueueRetryTest.php` + `tests/Feature/Treasury/ShiftCashVarianceTriggerPathsTest.php` | **OK (18 tests, 107 assertions)** |
| `tests/Architecture/QueueJobTenantContextTest.php` | **RED**, on exactly `Product\Application\Jobs\SendEnrichmentFeedbackJob` and `SendBrandMappingJob` — the single disclosed pre-existing red, unchanged by this diff (which touches no `Jobs/` directory) |
| Pint `--test` on all 5 changed PHP files | `{"result":"pass"}` |
| PHPStan level 8 on all 5 changed PHP files | **`[OK] No errors`** — note this is *better* than round 2, whose 2 inherited errors lived in `ShiftCashVarianceTriggerPathsTest.php`, a file this round does not touch |

**"PG not required" — verified by reading the diff, not taken on trust.** The round-3 diff contains no migration, no schema touch, and no balance mutation: the new tests assert only `fraud_alerts` counts, `audit_events` rows and the `bootstrap/providers.php` array; `grep` for `forceFill` / `payment_repositories` in `CashCountDispatchGuardTest.php` returns nothing, so the `forbid_direct_balance_write()` trigger has no surface here. Under `QUEUE_CONNECTION=sync` (`phpunit.xml:51`) the Treasury listener does run inline in the new healthy-dispatch test, but `connectionCanRetry()` is false for a `SyncJob` so any fault dead-letters rather than escaping, and no balance is written. The claim holds. I did **not** execute the suite on PostgreSQL.

---

## Findings (all Minor, none blocking)

**[Minor] M-1** — `PostShiftCashVarianceAdjustment.php:316-459` — the deviation's stated rationale ("six policy outcomes; the exception-shaped arms already dead-letter") is imprecise on both halves: there are **eleven** non-dead-letter reason codes, and `deadLetter():459` *is* a `refuse()` caller carrying `unbalanced_journal_entry` / `exception`. The correct argument — which I verified and which is stronger — is that `retryOrDeadLetter():409` re-throws and `Worker::runJob()` (`vendor/…/Queue/Worker.php:437`) reports every exception-shaped fault to Sentry, so `report()` inside `refuse()` would double-report crashes and spam eleven legitimate refusals. *Fix:* one sentence in the listener docblock recording the worker-reports-it argument, so the next reader does not re-open this.

**[Minor] M-2** — `PostShiftCashVarianceAdjustment.php:841` — *"Six distinct paths can legitimately produce no GL leg"* is stale; there are eleven, plus the dead-letter path. **Pre-existing** (present verbatim at base `fa807a699`, line 511), not introduced here, but it is the source of the "six policy outcomes" framing in the disclosure. *Fix:* drop the count or say "every path that legitimately produces no GL leg".

**[Minor] M-3** — `CashCountDispatcher.php:122-123` — the docblock attributes "a failing audit write cannot suppress `report()`" to the **ordering**; it is actually bought by the **per-leg guards** (`report()` would survive even if it ran last). The order is legitimate defence-in-depth. Cosmetic.

**[Minor] M-4** — `tests/Architecture/QueuedListenerTenantContextTest.php:246-286` + `fixtures/queued-listener-deferrals.json` — non-emptiness is now enforced, but there is still no cap / shrink-only ratchet on the entry count, and the four `tracked_in` values carry prose rather than a ticket id (the sibling Jobs fixture carries a real locator). r2 flagged both as optional; recorded so it is not rediscovered.

**[Minor] M-5** — `PostShiftCashVarianceAdjustment.php:392-396` + `:423-426` — under `sync` or a null `$this->job`, an exception-shaped fault dead-letters **without** re-throwing, so neither the worker nor `refuse()` reports it. Pre-existing (identical to the base's `catch (Throwable)` → refusal) and not the configured connection (`.env:61` `QUEUE_CONNECTION=redis`), so no regression. *Fix:* one line on the G-5 checklist noting that the `report()` reach depends on the redis connection it already mandates.

---

## What round 3 actually bought (recorded so this is not re-litigated)

- **F-1** closed at the right frame and in the right order, with three independent guards and a real `Exceptions::fake()` pin that goes red when the call is removed.
- **F-2** closed properly — one shared scanner, and the integrity test now fails when the property branch is deleted. The two guards added in this lane are finally built to the same standard.
- **F-3** closed as well as a characterization pin can be: the docs stopped contradicting each other, and the ordering is pinned **behaviourally** (a provider swap reds the suppression test) with a control that provably discriminates a broken fraud listener from a suppressed one. Per-listener isolation is correctly deferred behind an explicit pre-enable checkbox rather than silently.
- **F-4/F-5/F-6** all closed, with F-5 verified by a tamper that reds *both* the validation and the classification.
- Scope is 6 files, no config/migration/CI/baseline surface, Pint clean, **PHPStan clean on every changed file**, and the only red in the focused set is the disclosed, untouched, pre-existing pair of Product jobs.

**What to fix before merge:** nothing blocking — fold M-1/M-2 (the refuse()-census wording) into the listener docblock on the way past, and add M-5's one-line redis caveat to the G-5 checklist.

Spec ✅ + quality APPROVED.

VERDICT: ACCEPT
