# M5 implementation evidence — program-wide ratchets + WHOLE-LANE evidence + the A0 EXIT STATEMENT

**Milestone slice for the reviewer: `9fdd9bc08..HEAD`**

| Commit | What |
|---|---|
| `75f978674` | Phase 0.1.52 — open M5, close M4, sweep the five M4 round-1 findings (F-3 first, non-waivable) |
| `c533f005a` | Phase 0.1.53 — the two program-wide event ratchets, both proven to bite |
| *(this commit)* | Phase 0.1.54 — whole-lane evidence, the A0 exit statement, ledger to `review` |

**Harness:** PostgreSQL via `apps/api/phpunit-pgsql.xml`, `DB_DATABASE=autoerp_es_wave_a0_test`
(and `DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test` for the db-per-tenant leg) on
`127.0.0.1:5432`, every file **by path**, never the full suite. `apps/api/vendor` is a real
directory in this worktree, so every run executed THIS worktree's production code. Key files were
re-run on the default (SQLite) driver as well.

**M5 is the last milestone and it is a REVIEW milestone.** This document is evidence for the
whole-lane gate; it is not the gate. `status: review` on both M5 and the wave.

---

## 0. The five M4 round-1 sweeps, discharged at the opening commit

The M4 register (`M4-round1.md`) marked five findings as owed at M5's opening commit and named
**F-3 as NON-WAIVABLE before the A0 exit statement**. All five are in `75f978674`.

### F-3 — the M4 claim was FALSE, and this commit says so

The M4 evidence and the M4 ledger block both stated the CONFIRMED `pending_seal → fiscalized`
seal-branch gap was *"ticketed WITH executable evidence for an owning lane"*. It was not. There was
no file under `docs/superpowers/tickets/`, and the YAML `findings:` block held exactly four
entries, all inherited from M3b, none of them this one. The only record was a test docblock and a
comment inside a milestone block — which is not where this wave keeps residuals, and M5 is the exit
statement, after which nobody re-reads a milestone comment.

The record now exists, in both of the two places this wave uses:

- `docs/superpowers/tickets/2026-08-19-fiscal-seal-branch-unguarded-columns.md` — names
  `prevent_receipt_modification()` in
  `database/migrations/tenant/2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php:60-62`
  (reproduced in `down()` at `:180-181`), cites
  `ImmutabilityTriggerPresenceTest::test_pg_the_pending_seal_to_fiscalized_branch_has_no_column_guards_characterization`
  as the executable evidence, names the owning lane (receipt sealing / NF525 immutability), states
  the four decisions that lane has to make, and states the blast radius honestly (PostgreSQL only;
  no exploit claimed in the field; A0 ran no production probe).
- `docs/handoff/progress/es-wave-a0.progress.yaml` `findings:` → `M4-F-3`.

**A correction found while writing the ticket.** M4 recorded the three guarded branches as guarding
"7, 13 and 15" columns. Re-counted column by column: **7, 13 and 17**. The third branch (the
one-time `sealed_hash_algorithm` backfill, `:120-140`) guards the detach branch's thirteen PLUS
`partner_id`, `contact_id`, `fiscal_status` and `is_voided`. The test docblock is corrected. The
finding does not move: the first branch guards **zero**.

### F-1 — the verifier docblock claimed a capability the code does not have

`ServerAuthoredChainPlacementVerifier::deriveLinkageFailure()`'s docblock said the mirrored linkage
rule *"is the check that makes a mis-scoped head resolution visible"*. The M4 reviewer disproved it
at runtime: with the verifier fully wired and ONLY the head read de-scoped, the mis-linked row was
written, stamped `Verified`, and no exception was thrown.

The docblock now states what is true: both callers derive `$sequenceNumber` and `$previousHash`
**from the same `$prior` row** they hand to the verifier, so the linkage arm compares a value
against its own source; the hash arm re-verifies `$currentHash` against the bytes and provider that
produced it one line earlier; **only the clock arm can fire** on today's call graph. It also states
what the arms ARE for — structural guards for the day a caller stops deriving its placement from
the row it passes in — and forbids citing them as evidence that mis-scoping is detectable. A
pointer to the same correction is added to the class docblock, so the claim cannot be read out of
context.

### F-2 — the non-PG absence assertion was unfalsifiable

`ImmutabilityTriggerPresenceTest::triggerNamesOn()` returned `[]` **unconditionally, before running
any query**, on any driver that is not `pgsql`. The one test in the file that does not skip then
asserted `[]` against that hard-coded `[]`, for ONE of the two tables. Its docblock promised *"It
fails the day someone makes the triggers run on this driver"* — a promise it could not keep.

Three changes:

1. `triggerNamesOn()` reads `sqlite_master` on SQLite, `pg_trigger` on PostgreSQL, and **fails
   loudly** on a driver whose catalogue it does not know how to read (returning `[]` for an unknown
   driver is the same unfalsifiable shape).
2. The non-PG branch now asserts **both** tables (second-order finding).
3. The branch performs the **mutations** the `[PG]` tests prove are refused — an `UPDATE` of
   `current_hash` and a `DELETE` — and asserts they **SUCCEED**. The driver gap is now stated as a
   behaviour, and it goes RED the day enforcement reaches the driver.

**Two controls, each run and reverted (`git status --porcelain` empty after):**

```text
Control 1 — force the non-PG branch (if (false)) and run on PostgreSQL:
  1 failed (1 assertions)
  ES-41 (CONFIRMED half): immutability enforcement is PostgreSQL-only. …
  Failed asserting that two arrays are identical.
    -Array &0 []
    +Array &0 [ 0 => 'enforce_receipt_immutability' ]
  at tests/Feature/Fiscal/ImmutabilityTriggerPresenceTest.php:170

Control 2 — same, with the two catalogue assertions neutralised, so the
             BEHAVIOURAL half is what runs:
  1 failed (2 assertions)
  QueryException at tests/Feature/Fiscal/ImmutabilityTriggerPresenceTest.php:189
  (the UPDATE of current_hash is refused by the trigger on PG)
```

Both halves are load-bearing. SQLite assertion count on that test: **1 → 4**.

### F-4 — "each refusal two-sided" was true for 2 of 3

`test_pg_the_fiscal_events_trigger_refuses_a_delete` asserted the exception only (`expectException`
+ the DELETE, no re-read). It now uses the nested-transaction savepoint pattern its two siblings
already use, asserts the message comes from the append-only trigger itself, and asserts the row
**survives**. Three of three.

### F-7 — the standing ES-42 refusal test was status-only

Attribution lived in a fixture comment and in a red-first run nobody re-runs, and this file's own
history is that this exact test was once green for the wrong reason. The test now asserts, in the
test body:

- the refused principal **is** a full member of the terminal's company (so
  `CompanyContextMiddleware` cannot be what refuses it);
- it genuinely lacks `pos.operate_terminal`;
- `error.code === 'FORBIDDEN'` — the permission gate's code
  (`bootstrap/app.php`'s `AccessDeniedHttpException` render callback), never
  `NO_COMPANY_ACCESS` (`CompanyContextMiddleware.php:57-64`).

**Control, run and reverted:** deleting the non-operator's `UserCompanyMembership` turns the test
**RED on the fixture guard** (`1 failed (1 assertions)`, "the refused principal must be a FULL
member of the terminal's company") instead of green on the wrong 403.

### Recorded as notes, not work

`findings:` gained `M4-F-5` (the deposit-flow 500 and the disposition asymmetry with
`OutboxIngestor`), `M4-F-6` (two E2E callers of the gated route), `M4-F-8`
(`TerminalRegistrySnapshotService` has zero production callers, which narrows the ES-09 field
probe), and `M4-Minor` (the duplicated `CHAIN_CONTEXT` literal). Each cites `M4-round1.md`. The M4
entries are prefixed `M4-F-n` because the numbering collides with the M3b entries already in the
block.

---

## 1. The orphaned-event CI ratchet

`apps/api/tests/Architecture/OrphanedEventRatchetTest.php`

**Contract.** An event class dispatched somewhere in `app/` with **zero explicitly-registered
listeners** fires into nothing. The baseline enumerates the existing population **by name**; the
check fails on the next one. A baseline that is a count is not a ratchet (R-10).

### The number is 75, not 14 — and the handover's "14" was never a measurement

The brief says "baseline the existing 14 dead events BY NAME, fail on the 15th". Traced to source,
the 14 is a count of **ES register ROWS**, not of event classes. It comes from the handover's V2
requirement:

> *"An event with no consumer is not a fix, it is a new dead event (this register already contains
> 14 of those)."*

`ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md` today carries **16** rows whose class column
includes `DEAD-EVENT` — ES-12, ES-14, ES-20, ES-21, ES-25, ES-48, ES-75, ES-77, ES-78, ES-79,
ES-81, ES-82, ES-85, ES-86, ES-87, ES-88 — and several of those rows name many classes each (ES-75
names ten, ES-86 names six).

Measured in the code, under the handover's own definition, the population is **75**. About half map
onto a register row; the rest were never registered at all and are recorded for the first time in
the baseline's comments:

| Never-registered cluster | Count |
|---|---|
| Scheduling — the entire emission surface | 6 |
| Taxation — VAT period + withholding certificate lifecycle | 6 |
| Workshop/WorkOrder lifecycle (beside 7 siblings that DO have listeners) | 7 |
| Workshop/Technician — the module's own emissions | 3 |
| Voucher lifecycle (issued / partially / fully redeemed / voided) | 4 |
| Product created/updated/deleted | 3 |
| Catalog attribute + variant | 3 |
| Channel, Company, Loyalty (`LoyaltyAdjusted`, `ProgramDeactivated`) | 6 |

Baselining a number that was never measured would have produced a ratchet that is wrong on its
first run. The baseline is the measurement; the discrepancy is stated in the test's own docblock so
the next reader does not re-derive it.

### Mechanism — and why the obvious one does not work

`Event::hasListeners()` is **useless here**. `spatie/laravel-event-sourcing` registers
`Spatie\EventSourcing\StoredEvents\EventSubscriber@handle` against the wildcard pattern `*`, so
`hasListeners()` returns `true` for **every string**, including a class that does not exist —
verified directly: `Event::hasListeners('Totally\Fake\Class') === true`. A ratchet built on it
would report zero orphans forever and look green.

So the check reads the dispatcher's **explicit** listener map by reflection and excludes wildcards.
Two assumptions hold that up, and both are asserted rather than trusted:

- `test_the_only_wildcard_listener_is_the_spatie_stored_event_subscriber` — pins the wildcard set
  to exactly that one entry, so the exclusion cannot silently start hiding a real consumer.
- `test_the_projectionist_is_still_empty_so_the_listener_lookup_is_complete` — Spatie projectors
  and reactors consume by handler-method name rather than through the listener map.
  `Projectionist::getProjectors()` and `getReactors()` both return `[]` today; the day one is
  registered, this test goes red and says the lookup must widen.

"Dispatched" is resolved from source text — namespace + `use` map per file, then every `new X(`,
`X::dispatch(`, `X::dispatchIf(`, `X::dispatchUnless(` resolved through that map — the same
discipline `SweepScannerRegistrationTest` uses. The event's own defining file is excluded.

`test_every_baselined_event_class_still_exists` keeps the shrink-only property honest: a deleted
class cannot linger in the baseline.

### Blind spots, named in the test rather than hidden

- **The opposite orphan — a listener with no emitter.** ES-21 is exactly that: `ReceiptVoided` has
  live consumers wired and no production emitter, so a documented behaviour silently no longer
  exists. It has listeners, so it is NOT in this baseline, and this ratchet would not have caught
  it. Covering that direction is a second ratchet with a second definition of "emitter"; A0 did not
  take it.
- **The never-emitted class.** Not an orphan by this definition, so not baselined — and this
  produced a correction to a correction. `ES-REGISTER-CORRECTIONS-2026-08-11.md:61` states the
  three sampled ES-88 events "exist and are emitted", citing `PointsExpired.php:9-45` — which is
  the class's OWN definition file, not an emitter. Grepped across the whole backend including
  tests, `PointsExpired` has exactly two references and both are inside its own file. **It is never
  emitted.**
- **Indirect consumption** (a manual `Event::listen` inside a request lifecycle, a worker reading
  the stored-event table) reads as an orphan here.
- **Dispatch from outside `app/`** is not counted as an emission.

### TAMPER PROOF — it bites

Added a throwaway `RatchetTamperProbeEvent` under `app/Modules/POS/Domain/Events/` plus a
`RatchetTamperProbeEmitter` service that dispatches it.

```text
RED
   FAIL  Tests\Architecture\OrphanedEventRatchetTest
  ⨯ no new orphaned event has been introduced
  Failed asserting that two arrays are identical.
  +    34 => 'App\Modules\POS\Domain\Events\RatchetTamperProbeEvent',
  Tests:    1 failed (1 assertions)
```

Deleted both throwaway files:

```text
GREEN
   PASS  Tests\Architecture\OrphanedEventRatchetTest
  ✓ no new orphaned event has been introduced
  ✓ the projectionist is still empty so the listener lookup is complete
  ✓ the only wildcard listener is the spatie stored event subscriber
  ✓ every baselined event class still exists
  Tests:    4 passed (4 assertions)
```

`git status --porcelain` clean after the revert.

---

## 2. The projector-emission architecture test, as a ratchet

`apps/api/tests/Architecture/ProjectorEmissionRatchetTest.php`

**Contract.** Every registered `FiscalEventProjector` that writes a POS projection emits its
corresponding domain event. This is the guard that would have prevented **T1** — the v3 cutover
blackout, where six retired server write paths were replaced by six projectors and **not one emits
a domain event**.

**Measured 2026-08-19: zero of six emit.** A hard assertion would be a six-failure red wall on
`dev` from the moment it lands, which is how a check gets `@group`-excluded and forgotten (R-10).
So it ships as an **enumerated skip-list**, each entry annotated with the register row that will
delete it:

| Projector | Register rows that will delete the line |
|---|---|
| `PosCoreReceiptProjection` | **ES-01** — no receipt lifecycle event, so no NF525 `TICKET` `audit_events` row exists for any device-authored receipt |
| `ZReportProjection` | **ES-04** — no `ZReportGenerated`, so no `RAPPORT_Z` row and `pos_grandtotal_events` empty for the fiscal era |
| `ZSessionLifecycleProjection` | **ES-03** (no `ShiftOpened`/`ShiftClosed`) + **ES-02** (no `CashCountRecorded`, owner-RULED) + **ES-05** (no `CashDrawerOperationRecorded`) — three rows, one class |
| `AccountChargeReceiptProjection` | **none** |
| `AccountPaymentReceiptProjection` | **none** |
| `DepositReceiptProjection` | **none** |

The last three are marked as an **OPEN QUESTION for A1, not an established defect**: unlike
ES-01/03/04 there is no named consumer waiting for an event. The comment says so and explicitly
forbids deleting those lines by inventing an event.

**"Writes a POS projection"** is decided by module **ownership** (`App\Modules\POS\`), stated as a
criterion rather than assumed. A table-name grep cannot make the distinction — `TreasuryReceiptBridge`
READS `pos_receipts` (which is why it runs at `priority=150` behind the POS-core projectors at
`priority=50`) and writes Treasury `Payment` rows plus the GL post. The full registered set is
pinned on **both** sides of the partition (`test_the_registered_projector_set_is_unchanged`), so a
new projector in either module is classified deliberately rather than silently.

**Blind spot, named:** emission is detected on the projector's own source. A projector that emits
by delegating to a collaborator service reads as non-emitting. The direction is conservative (it
over-reports the gap), and the detection matches the fix shape the register prescribes for the whole
T1 cluster — *"emit inside the projector's `DB::transaction`, after the write, inside the existing
idempotency guard so Horizon redelivery cannot double-emit"*. If A1 chooses a delegating shape, the
detection must be widened deliberately.

### TAMPER PROOF — it bites in BOTH directions

**(a) Grow** — registered a throwaway silent POS projector by adding it to `POSServiceProvider`'s
`tag([...], FiscalEventProjector::class)` call:

```text
RED
  ⨯ no new silent pos projector has been registered
  ⨯ the registered projector set is unchanged
  ✓ every baselined projector is still registered
  +    4 => 'App\Modules\POS\Application\Projections\RatchetTamperProbeProjection',   (skip-list diff)
  +        4 => 'App\Modules\POS\Application\Projections\RatchetTamperProbeProjection',   (registered-set diff)
  Tests:    2 failed, 1 passed (3 assertions)
```

**(b) Shrink** — added one `event(new …)` call to the baselined `ZReportProjection`, simulating
ES-04 being fixed:

```text
RED
  ⨯ no new silent pos projector has been registered
  -    4 => 'App\Modules\POS\Application\Projections\ZReportProjection',
  Tests:    1 failed (1 assertions)
```

So a projector that STARTS emitting also turns the ratchet red, which forces the baseline line —
and the register row named in the comment above it — to be closed by hand rather than drifting.

Both tampers reverted:

```text
GREEN
   PASS  Tests\Architecture\ProjectorEmissionRatchetTest
  ✓ no new silent pos projector has been registered
  ✓ the registered projector set is unchanged
  ✓ every baselined projector is still registered
  Tests:    3 passed (3 assertions)
```

---

## 3. "in CI" — made true, narrowly

The handover asks for the orphaned-event ratchet **in CI**. It would not have been.
`tests/Architecture` runs in exactly one CI job step, and that step is
`if: github.event_name == 'workflow_dispatch'` — the manual security gate. An ordinary push or PR
never runs it.

`.github/workflows/ci.yml` therefore gains **one** step in `backend-test`, running exactly the two
ratchet files by path:

```yaml
- name: Event ratchets (orphaned events + projector emission)
  run: ./vendor/bin/phpunit tests/Architecture/OrphanedEventRatchetTest.php tests/Architecture/ProjectorEmissionRatchetTest.php
```

Named files, **not** the whole directory: `tests/Architecture` carries unrelated inherited baseline
failures, and adding the directory to an ordinary-push job would turn CI red on landing. Both files
are SQLite-safe — they read source text and the live container, no rows — verified green on the
default driver. The step comment states all of that so nobody "helpfully" widens it later.

---

## 4. WHOLE-LANE evidence over the integrated branch

Merge-base with `dev`: **`a5520f23ca39209f5b517723037e9516808f2bca`**. Whole-lane diff: **18
production files under `apps/api/app`, 19 files under `apps/api/tests`** (including 2 new
architecture tests and 1 trait), **1 CI workflow**, plus the review/ledger/ticket documents.
**Zero** migrations, **zero** config files, **zero** seeders, **zero** front-end files, **zero**
`onQueue` occurrences in the whole production delta.

### 4.0 Zero-line gates, measured over the FULL lane (`a5520f23c..HEAD`), not per milestone

`git diff --numstat a5520f23c..HEAD -- <path>` returns **no entry** for any of these:

```text
0  apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/ZReportHashService.php        (R-1 — the Z arm)
0  apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php              (:815 PARAM_STR untouched)
0  apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php (R-9 / D-8 — detection only)
0  apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php (F16-7)
0  apps/api/database/seeders/RolesAndPermissionsSeeder.php                          (ES-42 — no reseed)
```

Every constraint the brief and the STOP-C ruling placed on this lane holds across the whole branch,
not merely within the milestone that promised it.

### 4.1 Every test file this lane touched, re-run on PostgreSQL by path

| File | Result |
|---|---|
| `tests/Architecture/OrphanedEventRatchetTest` | 4 passed (4 assertions) |
| `tests/Architecture/ProjectorEmissionRatchetTest` | 3 passed (3 assertions) |
| `tests/Feature/Fiscal/FiscalEventIngestionEndpointTest` | 14 passed (69 assertions) |
| `tests/Feature/Fiscal/FiscalEventQuarantineResolutionTest` | 12 passed (74 assertions) |
| `tests/Feature/Fiscal/ImmutabilityTriggerPresenceTest` | 5 passed (10 assertions) |
| `tests/Feature/Fiscal/ParseFailureResumeTest` | 27 passed (209 assertions) |
| `tests/Feature/Fiscal/ReceiptChainRebuildTest` | 3 skipped, 22 passed (149 assertions) |
| `tests/Feature/Fiscal/ServerAuthoredChainContextScopingTest` | 6 passed (38 assertions) |
| `tests/Feature/Fiscal/VerifyEventChainCommandTest` | 34 passed (138 assertions) |
| `tests/Feature/Fiscal/VerifyEventChainFleetCommandTest` | 12 passed (58 assertions) |
| `tests/Feature/Fiscal/VerifyEventChainFleetCommandDbPerTenantTest` | 1 passed (8 assertions) |
| `tests/Feature/Fiscal/ZSessionLifecycleQuarantineVisibilityTest` | 7 passed (91 assertions) |
| `tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest` | 2 passed (48 assertions) |
| `tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest` | 8 passed (122 assertions) |
| `tests/Feature/POS/ReceiptChainVerificationTest` | 8 passed (34 assertions) |
| `tests/Feature/POS/VerifyPosChainCommandTest` | 15 passed (34 assertions) |
| `tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest` | **1 failed (14 assertions)** — inherited, §4.3 |
| `tests/Feature/POS/ReceiptReturnRefactorV3Test` | **2 failed, 7 passed (253 assertions)** — inherited, §4.3 |

Every count that a milestone register recorded reproduces exactly, with one deliberate exception:
`FiscalEventIngestionEndpointTest` is 69 assertions rather than M4's 66, because of the three F-7
attribution assertions added in `75f978674`.

**Note on `VerifyEventChainFleetCommandDbPerTenantTest`:** it first ran `1 failed (0 assertions)` on
a `FATAL: database "iziposcentral" does not exist`. That is the harness, not the code — `.env` pins
`DB_CENTRAL_DATABASE=iziposcentral`, which does not exist on the scratch server. Re-run with
`DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test`: **1 passed (8 assertions)**. Recorded rather than
quietly re-run, because a reviewer reproducing this lane will hit it.

### 4.2 Treasury-lens suites, re-run

| File | Result |
|---|---|
| `TreasuryReceiptBridgeTest` | 16 passed (54 assertions) |
| `DepositReceiptProjectionTest` | 5 passed (14 assertions) |
| `TerminalRegistrySnapshotTest` | 19 passed (71 assertions) |
| `DeadLetteredProjectionsControllerTest` | 12 passed (33 assertions) |
| `TreasuryDepositBridgeTest` | **9 failed (5 assertions)** — inherited, §4.3 |
| `AccountStatusChangedServerOnlyTest` | **1 failed (12 assertions)** — inherited, §4.3 |
| `DepositReceiptAppendTest` | **1 failed, 8 passed (26 assertions)** — inherited, §4.3 |

### 4.3 The inherited reds — PROVEN inherited, not asserted

Five suites are red on this branch. Rather than repeat the per-milestone claims, the whole-lane
control reverts **every** production and test file this lane touched to the merge-base
(`git checkout a5520f23c -- apps/api/app apps/api/tests`) and re-runs the five:

```text
BASE TreasuryDepositBridgeTest.php             Tests:    9 failed (5 assertions)
BASE AccountStatusChangedServerOnlyTest.php    Tests:    1 failed (12 assertions)
BASE DepositReceiptAppendTest.php              Tests:    1 failed, 8 passed (26 assertions)
BASE Task33FiscalFullFlowVerificationTest.php  Tests:    1 failed (14 assertions)
BASE ReceiptReturnRefactorV3Test.php           Tests:    2 failed, 7 passed (253 assertions)
```

**Identical, test for test and assertion for assertion, to the HEAD runs above.** 14 failing tests
across 5 files, unchanged by this lane. Restored with `git checkout HEAD -- apps/api/app
apps/api/tests`; `git status --porcelain` empty and `git diff --stat HEAD` empty afterwards.

Characterisations:

- `TreasuryDepositBridgeTest` (9) — `TreasuryDepositBridge::__construct` arity mismatch in the
  test's own wiring.
- `AccountStatusChangedServerOnlyTest` (1) — array key ORDER in an `assertSame`.
- `DepositReceiptAppendTest` (1) — a one-hour local-timezone artifact.
- `Task33FiscalFullFlowVerificationTest` (1) — a PG `bytea` stream-handle `assertSame` in the test
  itself; all 14 assertions before it pass, i.e. the POST through the ES-42-gated route succeeds and
  the ingested row is read back.
- `ReceiptReturnRefactorV3Test` (2) — `bccomp($independentNet, '10.000') === -1` at
  `ReceiptReturnRefactorV3Test.php:778` (recorded as `:771` at M2; the file has shifted). **This one
  is not cosmetic** and it is the wave's open question for the parent: the failure aborts before the
  post-Z-close `pos:verify-chains` assertion at the end of the test, which is how the
  context-flattening defect stayed invisible to the suite. Ownership is still unassigned — see
  `blockers:` in the ledger.

### 4.4 Static gates, whole lane

```text
pint --test  <37 changed PHP files>              {"result":"pass"}
phpstan level 8  <18 changed production files>   [OK] No errors
```

### 4.5 Default-driver (SQLite) cross-check

| File | Result |
|---|---|
| `OrphanedEventRatchetTest` | 4 passed (4 assertions) |
| `ProjectorEmissionRatchetTest` | 3 passed (3 assertions) |
| `ImmutabilityTriggerPresenceTest` | 4 skipped, 1 passed (4 assertions) — the 4 skips are loud `[PG]`; the assertion count is up from 1 by the F-2 fix |
| `FiscalEventIngestionEndpointTest` | 14 passed (69 assertions) |
| `ServerAuthoredChainContextScopingTest` | 6 passed (38 assertions) |
| `VerifyEventChainCommandTest` | 34 passed (138 assertions) |
| `ParseFailureResumeTest` | 27 passed (209 assertions) |

### 4.6 Standing rules, whole lane

- **Rule 19 / precision** — no money or quantity path is touched anywhere in the lane. The only
  `bc*` calls in added lines are `bcadd`/`bccomp` on strings narrowed by an `is_numeric()` guard in
  the ES-41 fixture. No `(float)`, `floatval`, `number_format`, or bare no-arg `getScale()` in any
  added production line.
- **Rule 20** — no `onQueue` anywhere in the lane, so no `config/horizon.php` entry is owed; no
  migration; no SQLite TEXT-timestamp boundary; no device shift re-hydration.
- **Rule 13** — every new class uses constructor injection with `private readonly`; no `app()` in
  added production lines.
- **Rule 12** — the one gated route stays inside the existing
  `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` group.
- **Rule 8** — no event class renamed, restructured or deleted. The two ratchets **read** the event
  surface; neither changes it.
- **Deploy** — nothing owed. No migration, no seeder change, no new permission, no
  `permission:cache-reset`, no config change. The only non-PHP change is the CI workflow step.

---

## 5. 🚨 THE A0 EXIT STATEMENT (R-11)

> **As of `c533f005ae2c9a80302089f9d92b1f6542fc2f21` on branch `codex/es-wave-a0` — the last commit
> in this lane that touches executable code; everything after it is documentation and the ledger —
> the AutoERP fiscal verifiers
> `fiscal:verify-event-chain` (with its manifest-driven fleet driver) and `pos:verify-chains` have
> been demonstrated to FAIL on the following tamper shapes, each with a green control on the clean
> equivalent, every run on PostgreSQL:**
>
> 1. **T-a — payload ⇄ `canonical_bytes` divergence on a SEALED row**, produced through the REAL
>    production path (`ParseFailureResolutionService::resolve()`), not by hand-editing a row: the
>    fixture self-asserts that the frozen bytes and the hash are unchanged while the payload is
>    replaced, and the verifier reports it. *(M1; ES-06 detection, M3.)*
> 2. **T-b — `previous_hash` taken from the WRONG `chain_context`** on a two-context terminal:
>    exactly one `CHAIN BREAK` line, with the other four checks asserted silent. *(M1.)*
> 3. **T-c — `pos_receipts.fiscal_hash` ⇄ `fiscal_events.current_hash` mirror mismatch**, plus a
>    tampered projected chain, with per-arm counts proving non-zero fiscal-era coverage and a
>    negative control on an all-legacy tenant. *(M2.)*
> 4. **`integrity_status` ≠ `verified`** — a quarantined-but-intact row no longer reports verified.
>    *(M1.)*
> 5. **A sealed envelope missing or altering any of its 14 sealed coordinates** — red on both a
>    parsed and a pending row, with green controls. *(M1.)*
> 6. **A `sequence_number` gap** on a fixture whose hash linkage is deliberately valid — so the
>    contiguity check is proven to be doing its own work. *(M1.)*
> 7. **Context-blind chain-head resolution on a two-context terminal** — the pre-fix services
>    resolve the deeper `z_session` head for an `operational` append: 5 of 6 tests red, every
>    failure "expected 3, stored 6", the M1 verifier reporting the break in its own words.
>    *(M4, ES-09.)*
>
> Each of the five M1 checks was additionally shown to be **individually load-bearing** by the
> brief's own antidote: each check disabled in turn with `false &&`, its fixture shown going green,
> then restored.
>
> **Downstream lanes may now cite these two commands as evidence of correctness — within the
> boundaries in §5.2.**

### 5.1 What A0 also delivered

- **ES-08** — the verifier checks payload⇄bytes, `integrity_status`, sealed coordinates and
  sequence contiguity, and gained a **manifest-driven fleet driver** (operator-supplied
  tenant→actor map, actor gate and tenant binding preserved exactly, missing/unauthorised tenants
  reported loudly with a non-zero aggregate exit, no new identity model).
- **ES-07 (narrowed)** — `pos:verify-chains` now counts fiscal-era receipts, reports per arm, and
  carries the `fiscal_hash` ⇄ `current_hash` mirror that did not exist anywhere before.
- **ES-06** — divergence on a sealed row is **detectable**. Detection only; the workflow half is
  owner gate D-8.
- **ES-09** — chain-head resolution scoped by `(tenant_id, company_id, terminal_id, chain_context)`
  at both server-authoring services, matching `OutboxIngestor`'s shape, with the integrity verdict
  **derived** rather than stamped.
- **ES-41** — trigger presence asserted on PG, the non-PG driver gap made falsifiable, and the
  SUSPECTED seal-branch half CONFIRMED with executable evidence and ticketed.
- **ES-42** — the ingestion route is gated by the existing seeded `pos.operate_terminal`; the
  refusal persists nothing and the device path still succeeds end to end.
- **ES-16 / ES-17** — the z-session-lifecycle quarantine blind spot is visible, and the
  `resolved_at`/`resolved_by` adjudication writer exists.
- **The two ratchets** above.

### 5.2 What A0 explicitly did NOT prove — the named blind spots

**On the verifiers themselves:**

1. **No production probe was run. Anywhere.** ES-09's field occurrence stays **SUSPECTED**. A0
   proved the defect exists in code and is reproducible in a fixture; it did not measure whether
   any live row is mis-linked. M4-F-8 narrows the question usefully — `TerminalRegistrySnapshotService`
   has **zero production callers**, so only `VirtualAdminFiscalEventService`'s two live callers can
   have written mis-linked rows, and only onto a `VirtualAdmin` terminal, which carries no
   `z_session` chain — so the realistic field blast radius may be nil. Read that before commissioning
   a probe.
2. **`ServerAuthoredChainPlacementVerifier` is doing less than it looks.** Two of its three arms are
   tautologies against the value that produced them; only the clock arm can fire on today's call
   graph. The defense against mis-scoped head resolution is the scoped READ, not the verifier. This
   was disproved at runtime by the M4 reviewer and is now stated in the code.
3. **The Z-report arm was deliberately not rewritten** (R-1). `ZReportHashService` is 0-line across
   the entire lane. A0 makes no new claim about Z-chain verification beyond what the mirror
   required.
4. **`pos:verify-chains` was verified on fixtures, not on a production tenant.** No fleet run, no
   staging run, no manifest was executed against real tenants.

**On the event surface:**

5. **The orphaned-event ratchet does not catch a listener with no emitter** (ES-21's shape), a
   never-emitted class (`PointsExpired`), indirect consumption, or dispatch from outside `app/`.
6. **The projector-emission ratchet does not catch emission via a collaborator service**, and its
   POS classification is by module ownership rather than by observed writes.
7. **75 orphaned events are baselined, not fixed.** A0 retired nothing and wired nothing. The
   ratchet stops the 76th; it does not reduce the 75.
8. **Zero of six POS projectors emit.** That is A1's work in its entirety; A0 only made the state
   visible and un-regressable.

**Deferred tickets and open gates A0 hands forward:**

9. **`docs/superpowers/tickets/2026-08-19-fiscal-seal-branch-unguarded-columns.md`** — the
   CONFIRMED `pending_seal → fiscalized` branch with zero column guards. A seal may rewrite
   `subtotal` and `total` in the same statement, on live PostgreSQL, demonstrated by a passing test.
   **This is the highest-consequence thing A0 found and did not fix.**
10. **The `OutboxIngestor.php:815` PARAM_STR ingestion defect** — `canonical_bytes` (PG `bytea`) is
    bound as `PDO::PARAM_STR`, so a canonical envelope carrying a backslash escape fails to INSERT
    with `22P02`. Discovered at M3, escalated, and **deliberately untouched** by every subsequent
    milestone (`OutboxIngestor.php` is 0-line across M3b, M4 and M5). It is an ingestion-lane
    ticket and it is still open.
11. **`ReceiptReturnRefactorV3Test` is 2/9 red and unowned** — inherited, red at the merge-base,
    excluded from A0's scope by the STOP-C ruling. Its failure aborts the test **before** the
    post-Z-close `pos:verify-chains` assertion, which is how the context-flattening defect stayed
    invisible to the suite. **Someone has to own this**; it is the one inherited red with a
    verification consequence.
12. **Owner gates still open:** `D-8` (ES-06 second approver / correcting event), `D-11` (ES-43
    unkeyed SHA-256 vs the NF525 RSA/ECDSA requirement — ES-43 is entirely outside A0's scope until
    ruled), `nf525-s8-resolved-by` (should the §8 export publish `resolved_by`?).
13. **Nine `findings:` residuals** in the ledger: M3b F-5/F-6/F-8/F-9 and M4 F-3/F-5/F-6/F-8 plus
    the duplicated `CHAIN_CONTEXT` literal. Each names its owing lane.
14. **`FEV-OPS-03..07`** — the M1 operability tickets
    (`docs/superpowers/tickets/2026-08-12-fiscal-event-chain-verifier-operability-followups.md`).

### 5.3 What A1 inherits

- **A trustworthy control surface.** Both verifiers are honest and demonstrated red on seven tamper
  shapes. A1's fixes may now cite them — that is the whole reason A0 ran first.
- **A red-first template.** Every milestone in this wave produced its red block by swapping the
  pre-fix production file back in and re-running the FINAL tests. A1 should do the same; the
  registers show what the reviewers will re-run.
- **Two ratchets that fail on drift**, wired into an ordinary-push CI step, with baselines A1 will
  shrink rather than edit. `ProjectorEmissionRatchetTest`'s skip-list is A1's ES-01/02/03/04/05
  worklist, already annotated with the register rows.
- **Three unclassified projectors** — `AccountCharge`, `AccountPayment`, `DepositReceipt` — with an
  explicit instruction NOT to invent an event for them, and a ruling owed on whether they owe one.
- **Forty never-registered orphaned events**, clustered by module in the baseline's comments. The
  ES register's dead-event rows cover roughly half the real population; A1 should not treat the
  register as the census.
- **A named, non-waivable ticket** on the seal branch, with a passing characterization test as its
  evidence and its retirement instruction written into the assertion message.
- **The knowledge that `Event::hasListeners()` lies in this codebase.** Any future event-wiring
  check must read the explicit listener map.

---

## 6. Ledger state

`M4: passed` (verdict `M4-round1.md`, ACCEPT). `M5: review`, wave `status: review` — the parent
orchestrator runs the whole-lane gate itself with both lenses, per the brief. Branch
`codex/es-wave-a0`, **not merged, not pushed**.
