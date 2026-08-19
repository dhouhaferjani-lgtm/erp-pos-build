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
| Workshop/WorkOrder lifecycle (beside 7 siblings that DO have listeners) | 7 |
| Scheduling — the entire emission surface | 6 |
| Taxation — VAT period + withholding certificate lifecycle | 6 |
| Voucher lifecycle (issued / partially / fully redeemed / voided) | 4 |
| Workshop/Technician — the module's own emissions | 3 |
| Product created / updated / deleted | 3 |
| Catalog attribute + variant | 3 |
| **Vehicle** (`MileageAnomalyDetected`, `MileageReadingLogged`) | **2** |
| Channel (`ChannelOrderReceived`, `ChannelSyncDriftDetected`) | 2 |
| Company (`FirstTransactionPosted`, `FiscalYearValidated`) | 2 |
| Loyalty (`LoyaltyAdjusted`, `ProgramDeactivated`) | 2 |
| **Total** | **40** |

> **Corrected at M5 round 1, F-5.** The first version of this table summed to **38**: it omitted the
> Vehicle pair entirely and collapsed Channel/Company/Loyalty into one row, while §5.3 said "forty".
> §5.3 was right. The count is re-derived mechanically from the baseline's own annotations — 75
> entries, of which exactly 40 carry a `— no register row` marker — and this table now enumerates
> all eleven clusters and sums. A1 is pointed at this table as its never-registered inventory, so an
> omission here is directly consumed.

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

**Detection, and its blind spots — BOTH directions, corrected at M5 round 1 (F-4).** Emission is
detected on the projector's own source, which matches the fix shape the register prescribes for the
whole T1 cluster — *"emit inside the projector's `DB::transaction`, after the write, inside the
existing idempotency guard so Horizon redelivery cannot double-emit"*. The first version of this
paragraph asserted the error direction was one-way and "conservative". It is not:

- **Over-report** — a projector that emits by delegating to a collaborator service reads as silent.
  A fix in that shape makes the ratchet red for the right list and the wrong reason.
- **Under-report** — pattern 2 matches any `Symbol::dispatch(`, so a projector that dispatches a
  **queued job** and emits no domain event reads as emitting and never enters the baseline. Not live
  today (all six POS projectors contain no `::dispatch`, no `->dispatch` and no `event(` at all),
  but this is the direction that loses coverage silently. Fix shape if it ever becomes live: resolve
  the dispatched symbol through the file's `use` map and require it to land under
  `Domain\Events\` / `Shared\Events\`.
- **Under-report, second vector — STRING LITERALS are not stripped (recorded at M5 round 2, N-8).**
  `sourceWithoutComments()` drops `T_COMMENT` / `T_DOC_COMMENT` but leaves
  `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE` in the haystack, so a string containing
  `event(new …)` — a log line, an exception message, a code sample in a heredoc — would read as an
  emission and silently retire a baseline entry. **There is already a near-miss in the very file the
  F-4 probe used:** `ZReportProjection.php:326` contains the text `event(s)` inside a
  `missingDependency` message (`'pos_receipts rows for %d verified v%d+ SALE_RECEIPT event(s) on
  terminal %s …'`). It is harmless today only because pattern 1 is `/\bevent\s*\(\s*new\s+/` and
  requires the `new`. **Recorded as a known detector limitation rather than fixed**, per the
  round-2 disposition ("optional") and the parent's instruction: a second token-class change to the
  detector in a terminal documentation round would re-open the exit statement's code anchor for a
  vector that is not live. Fix shape when someone next opens this file: add
  `|| $token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE` to the
  strip condition, and extend the negative fixture with a string-literal line.

**Comments are now stripped before matching, and that was a real hole, not a nicety.** The round-1
reviewer appended one line to `ZReportProjection.php`:

```php
// PROBE: event(new Something());
```

and the projector dropped out of the discovered list, firing the message that instructs a maintainer
to *"delete its line AND close the register row"* — i.e. a doc-block edit on a 2 000-line projector
could talk someone into closing **ES-04**. `emitsADomainEvent()` now runs the source through
`token_get_all()` and drops `T_COMMENT` / `T_DOC_COMMENT` before matching. Tokenising rather than
regex-stripping is deliberate: a regex that skips comments has to understand strings, heredocs and
escaping, and getting that subtly wrong is the same silent-miss class the ratchet exists to prevent.

Pinned by a new test, `test_a_commented_out_emission_does_not_count_as_emitting`, with two fixtures
under `tests/Architecture/ProjectorEmissionFixtures/` — one whose only emissions are inside two `//`
lines, a `/* */` block and a doc-block (must read as NOT emitting) and one positive control with a
real `event(new …)` (must read as emitting, so the stripping cannot blind the detector wholesale).

> **Corrected at M5 round 2, N-5.** The first version of this sentence also claimed a `#` comment in
> the negative fixture. There is none — `grep -c '#'` on the fixture returns 0. A `#` line WAS
> written and `pint`'s `single_line_comment_style` fixer rewrote it to `//` when the fix round ran
> `pint` (that rewrite is visible in the round-1 verification output). So a `#` control cannot be
> kept in this codebase at all, and the sentence is corrected rather than the fixture. Behaviour is
> unaffected either way: `#` lexes as `T_COMMENT`, which is exactly what the detector strips —
> round 2 verified that independently. Describing a control that does not exist is the small end of
> the F-3 class, which is why it is corrected rather than waved off.

**Controls, run and reverted:**

```text
CONTROL A  comment probe re-applied to ZReportProjection  ->  4 passed (5 assertions)   [no longer bites]
CONTROL B  a REAL event(new \stdClass()) on the same file ->  1 failed, 3 passed        [still bites]
RESTORED                                                  ->  4 passed (5 assertions)
```

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

## 3. "in CI" — partially, and the reach is narrower than M5 first claimed

**Corrected at M5 round 1, F-1.** The first version of this section said *"An ordinary push or PR
never runs it"* about the pre-existing state and implied the new step fixed that. The first half is
true; the implication is not, and the difference matters because A1 will act on it.

**Before this lane:** `tests/Architecture` appeared in exactly one CI step — "Full backend suite
(manual security gate)" — guarded by `if: ${{ always() && github.event_name == 'workflow_dispatch' }}`.
A ratchet placed in that directory alone would only ever run on a manual dispatch. That part stands.

**What the new step actually gates.** `.github/workflows/ci.yml` gains one step in `backend-test`
running exactly the two ratchet files by path:

```yaml
- name: Event ratchets (orphaned events + projector emission)
  run: ./vendor/bin/phpunit tests/Architecture/OrphanedEventRatchetTest.php tests/Architecture/ProjectorEmissionRatchetTest.php
```

The step carries no `if:` of its own — but the **job** does, one line above the steps
(`ci.yml:185`):

```yaml
if: github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || (github.event_name == 'push' && github.ref == 'refs/heads/main')
```

and the workflow's own `on:` block triggers `push` for `main` only. The real matrix is therefore:

| Event | Do the ratchets run? |
|---|---|
| `workflow_dispatch` | **yes** |
| PR → `main` | **yes** |
| push → `main` | **yes** |
| PR → `dev` | **no** — the workflow starts, `backend-test` is skipped on the job-level `if:` |
| push → `origin/dev` (the rule-21 promotion path this whole program uses) | **no workflow runs at all** |

So the improvement is real (workflow_dispatch-only → also the `main` boundary), but **the ratchets
do not gate day-to-day `dev` work today.** Drift can land on `dev` and will only be caught when it
reaches `main`. The M5 round-1 reviewer found this by reading the job-level `if:` that the first
pass missed, and it is the finding that mattered most, because the milestone's deliverable is the
exit statement.

**Deferred, not silently left:** wiring a ratchet lane that runs on PR→`dev` means editing the CI
job matrix, which is out of scope for a fiscal-verifier wave (rule 4). It is deferred to the
**enforcement-P2 `ci.yml` reconciliation**, where the parent has ticketed it. The step's own comment
in `ci.yml` states the true matrix and the deferral, so nobody reads the step and assumes more than
it does.

Named files, **not** the whole directory: `tests/Architecture` carries unrelated inherited baseline
failures — measured at round 1 as `Tests: 53, Assertions: 238, Failures: 4` on the default driver,
the four being `QueueJobTenantContextTest` and siblings — so adding the directory would turn the job
red on landing. Both ratchet files are SQLite-safe (they read source text and the live container, no
rows), verified green on the default driver.

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
0  apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php                  (R-1 — the Z arm)
0  apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php              (:815 PARAM_STR untouched)
0  apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php (R-9 / D-8 — detection only)
0  apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php (F16-7)
0  apps/api/database/seeders/RolesAndPermissionsSeeder.php                          (ES-42 — no reseed)
```

Every constraint the brief and the STOP-C ruling placed on this lane holds across the whole branch,
not merely within the milestone that promised it.

> **Path corrected at M5 round 1, F-3.** The first version of this block published the Z-arm gate
> against `apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/ZReportHashService.php` — **a path
> that does not exist**. An empty numstat against a non-existent path proves nothing about the file
> it claims to measure, which is exactly the unfalsifiable-assertion class M4-F-2 faulted, published
> here as the proof of R-1 — the most repeated constraint in this wave. The real path has no
> `Fiscal/V3/` segment. (The directory `app/Modules/POS/Domain/Services/Fiscal/V3/` **does** exist —
> it holds `CanonicalJsonEncoder.php` and `CanonicalPayloadBuilder.php` — which is why the wrong path
> looked plausible for three milestones.) **The gate genuinely holds:** re-measured against the real
> path (by the round-1 reviewer independently, by the round-2 reviewer, and again here),
> `git diff --numstat a5520f23c..HEAD --
> apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php` returns no entry and the file is
> present.
>
> **INHERITED — the bogus path is older than M5, and three FROZEN registers still publish it
> (recorded at M5 round 2, N-2).** It did not originate here. The same non-existent path is
> published as proof of the R-1 zero-line gate at:
>
> - `docs/handoff/reviews/es-wave-a0/M3b-implementation-evidence.md:43`
> - `docs/handoff/reviews/es-wave-a0/M3b-round1.md:45`
> - `docs/handoff/reviews/es-wave-a0/M4-round1.md:31`
>
> Two of those three are **review** registers — i.e. the record of a reviewer confirming the gate —
> so the unfalsifiable green was independently re-published rather than merely copied. **Those files
> are FROZEN and are deliberately left unedited**: a review register is the record of what a
> reviewer actually wrote, and rewriting one to be correct destroys exactly the evidence it exists to
> preserve. The correction lives here and in the ledger's F-3 entry instead. Anyone reading M3b or M4
> and relying on that line for R-1 should re-measure against
> `apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php`; the gate holds, the published
> proof of it did not.

### 4.1 Every test file this lane touched, re-run on PostgreSQL by path

| File | Result |
|---|---|
| `tests/Architecture/OrphanedEventRatchetTest` | 4 passed (4 assertions) |
| `tests/Architecture/ProjectorEmissionRatchetTest` | 4 passed (5 assertions) — corrected at round 2, N-1 |
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
| `tests/Feature/POS/ReceiptReturnRefactorV3Test` | **9 passed (345 assertions)** — GREEN; the previously recorded red was a PG-session-timezone artifact, §4.3 |

Every count that a milestone register recorded reproduces exactly, with two deliberate exceptions:
`FiscalEventIngestionEndpointTest` is 69 assertions rather than M4's 66, because of the three F-7
attribution assertions added in `75f978674`; and `ProjectorEmissionRatchetTest` is 4/5 rather than
the 3/3 it shipped at, because fix round 1 added
`test_a_commented_out_emission_does_not_count_as_emitting` (F-4).

> **Corrected at M5 round 2, N-1.** This table published the projector ratchet at its **pre-fix**
> 3/3 after the fix round had already taken it to 4/5 — contradicting §2 of this same document,
> which prints 4/5 twice, and the fix commit's own message, which says "up from 7/7". The stale
> number sat in the published per-file record a reviewer diffs against, on the **one file that round
> changed**, and it was a count mismatch on a *ratchet* — which manufactures precisely the tamper
> alarm the ratchet exists to make meaningful. Same class as F-1 and F-3: the artifact wrong about
> the thing the round was for.

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
| `TreasuryDepositBridgeTest` | **9 red (5 errors + 4 failures, 5 assertions)** — inherited, §4.3 |
| `AccountStatusChangedServerOnlyTest` | **1 failed (12 assertions)** — inherited, §4.3 |
| `DepositReceiptAppendTest` | **1 failed, 8 passed (26 assertions)** — inherited, §4.3 |

*(`TreasuryDepositBridgeTest` was written as "9 failed" in the first version; PHPUnit reports it as
5 errors + 4 failures. The count of 9 is right, the wording was loose — tightened at M5 round 1.)*

### 4.3 The inherited reds — **12 across 4**, proven inherited

**Corrected at M5 round 1, F-2.** The first version of this section published *"14 failing tests
across 5 files"* and singled out `ReceiptReturnRefactorV3Test` as *"the one inherited red with a
verification consequence"*. The reviewer could not reproduce that suite's red — twice green at HEAD
and twice green at the base. Both measurements were correct, and the discriminating variable has now
been found.

#### `ReceiptReturnRefactorV3Test` is GREEN — the red was a **PostgreSQL session-timezone** artifact

Re-run at HEAD on the same scratch database, varying one thing:

```text
default session on 127.0.0.1:5432  ->  Tests: 2 failed, 7 passed (253 assertions)   [x3, deterministic]
PGTZ=UTC          on the same host ->  Tests: 9 passed (345 assertions)             [matches the reviewer]
```

*("Default session" is qualified by the instance — see the N-6 note below. The round-2 reviewer
additionally reproduced the historical red on demand with `PGTZ=Africa/Tunis`: `Tests: 9,
Assertions: 253, Failures: 2`, both at `:778`, i.e. the mechanism reproduces in both directions.)*

Diagnosed rather than guessed. Instrumenting the failing assertion (added, dumped, reverted) shows
the aggregate window and the rows are **exactly one hour apart**:

```text
DIAG net=0.000 vat=0.000 gross=0.000
     window=2026-08-19 22:38:54..22:44:54
     rows=[{"sale","2026-08-19 23:39:54"},{"return","23:41:54"},{"sale","23:43:54"}]

with PGTZ=UTC:
DIAG net=10.000 vat=2.000 gross=12.000
     window=2026-08-19 22:39:26..22:45:26
     rows=[{"sale","22:40:26"},{"return","22:42:26"},{"sale","22:44:26"}]
```

The test builds its Z window from `Carbon::now('UTC')` (`ReceiptReturnRefactorV3Test.php:580-581`)
and compares it as `'Y-m-d H:i:s'` text against `pos_receipts.posted_at` (`:755-757`). The scratch
PostgreSQL server reports `show timezone` → **`Africa/Tunis`** (UTC+1), so every row renders one
hour outside the window, all three drop out, `$independentNet` becomes `0.000`, and
`bccomp('0.000','10.000')` is `-1` at `:779`. Nothing about the code under test is involved.

##### Which "scratch server" — the discriminating variable is the INSTANCE, not the client (M5 round 2, N-6)

Round 2 could not reproduce the "default session is red" half and proposed that the non-UTC session
came from the **client's `PGTZ`** while the server GUC was UTC. Re-measured here with `PGTZ`
explicitly unset (`env -u PGTZ`), that is not what this machine reports. **There are TWO local
PostgreSQL instances, and both host a database named `autoerp_es_wave_a0_test`:**

```text
env -u PGTZ, same database name, both instances:

port 5432  (Homebrew postgresql@15, data_directory /opt/homebrew/var/postgresql@15)
    select setting, reset_val, source from pg_settings where name='TimeZone'
    ->  Africa/Tunis | reset_val=Africa/Tunis | source=configuration file
    psql  show timezone            -> Africa/Tunis
    PDO   show timezone (no PGTZ)  -> Africa/Tunis
    select count(*) from pg_db_role_setting -> 0        (no per-db / per-role override)

port 5433  (the containerised instance)
    ->  UTC | reset_val=UTC | source=configuration file
```

`source = configuration file` and `reset_val = Africa/Tunis` are the marks of a **server GUC set in
`postgresql.conf`**, not of a client-supplied value — a client `PGTZ` would show `source = client`
and leave `reset_val` at the server default. So on port **5432** the non-UTC session is the server's,
and `PGTZ=UTC` is the client *overriding* it; on port **5433** the server is already UTC and no
override is needed.

That reconciles both measurements without either being wrong: this lane's runs used **5432** (the
`.env`-adjacent host all M0–M5 evidence was produced against), the round-2 reviewer's used an
instance reporting UTC. Round 2's proposed clause — "the server's `timezone` GUC is UTC, so the
default session is environment-dependent" — is **not adopted**, because writing it would put a
statement into the terminal exit evidence that this machine contradicts. What IS adopted is round
2's real point, and it is the stronger one: **"the scratch server" was never a sufficient
identifier.**

**Carry-forward rule, strengthened accordingly — pin the instance AND assert the session:**
run this lane's PG suites against a **UTC session**, and do not assume you have one. Either pin
`DB_PORT=5433`, or set `PGTZ=UTC`, and in either case verify with
`select setting, reset_val, source from pg_settings where name='TimeZone'` before trusting a red.
A non-UTC session manufactures red in every window-based aggregate test in this suite.

**Consequences, stated plainly:**

- The suite is **GREEN at HEAD** on a UTC session. There is no inherited code red here and nothing
  for the parent to assign.
- The **historical record was wrong in attribution.** M2 round-1 finding 6 recorded it as "2/9 red
  on this branch and on its base"; it was red on both because the same non-UTC scratch server was
  used both times, not because of anything in the code.
- The causal claim attached to it — that the failure aborts before the post-Z-close
  `pos:verify-chains` assertion and *"is how the context-flattening defect stayed invisible to the
  suite"* — is **withdrawn**. On a UTC session the test runs to completion and reaches that
  assertion.
- The `blockers:` entry asking the parent to assign ownership is **removed** from the ledger.
- **Carry-forward for anyone reproducing this lane:** run the PG suites with a **UTC session** —
  pin the instance (`DB_PORT=5433`) *or* set `PGTZ=UTC`, and verify with
  `select setting, reset_val, source from pg_settings where name='TimeZone'` before trusting a red.
  A non-UTC session manufactures red in window-based aggregate tests. **Note (round 2, N-3):** the
  M4 register's "one-hour local-TZ artifact" note on `DepositReceiptAppendTest` is a *different*
  defect and does not belong to this class — measured, that failure is a payload key-order
  `assertSame` with no timezone component (see the characterisation below). Round 1 grouped the two
  together; they are unrelated.

#### The four that are real

Re-run at HEAD with `PGTZ=UTC` — all four are unchanged by the timezone, so they are genuine:

```text
PGTZ=UTC TreasuryDepositBridgeTest             9 red (5 errors + 4 failures, 5 assertions)
PGTZ=UTC AccountStatusChangedServerOnlyTest    1 failed (12 assertions)
PGTZ=UTC DepositReceiptAppendTest              1 failed, 8 passed (26 assertions)
PGTZ=UTC Task33FiscalFullFlowVerificationTest  1 failed (14 assertions)
PGTZ=UTC ReceiptReturnRefactorV3Test           9 passed (345 assertions)
```

**The inherited-red inventory is 12 failing tests across 4 files**, not 14 across 5.

Proven inherited by reverting **every** production and test file this lane touched to the merge-base
(`git checkout a5520f23c -- apps/api/app apps/api/tests`) and re-running:

```text
BASE TreasuryDepositBridgeTest.php             Tests:    9 failed (5 assertions)
BASE AccountStatusChangedServerOnlyTest.php    Tests:    1 failed (12 assertions)
BASE DepositReceiptAppendTest.php              Tests:    1 failed, 8 passed (26 assertions)
BASE Task33FiscalFullFlowVerificationTest.php  Tests:    1 failed (14 assertions)
```

Identical to the HEAD runs, test for test and assertion for assertion. Restored with `git checkout
HEAD -- apps/api/app apps/api/tests`; `git status --porcelain` empty and `git diff --stat HEAD`
empty afterwards.

> **Caveat on the control's method — M5 round 1, F-6; residue enumerated at round 2, N-4.**
> `git checkout <base> -- apps/api/app apps/api/tests` restores files that exist at the base; it does
> **not delete** files this lane CREATED. The exact residue is
> `git diff --diff-filter=A --name-only a5520f23c..HEAD -- apps/api` → **20 files (9 production, 11
> test)**, *including* `ServerAuthoredChainPlacementVerifier.php`,
> `QuarantineIncidentResolutionService` / `Controller`, **three** new exceptions
> (`ServerAuthoredChainPlacementException.php` as well as the two quarantine ones), two new console
> commands (`AuthorizedFiscalChainCommand.php`, `VerifyEventChainFleetCommand.php`),
> `ReceiptChainArmVerificationResult.php`, six added feature tests, the two ratchets, the two new
> fixtures and `tests/Traits/ReadsCanonicalBytes.php`. Round 1 published a seven-item parenthetical
> that read as exhaustive and understated the residue by roughly two-thirds (it said "two new
> exceptions"; there are three) — which blunts the very finding the caveat exists to close. Run the
> command rather than trusting any list. So the control is "base content plus this lane's 20 new,
> unreferenced files", not a clean base checkout. It is adequate — with `FiscalServiceProvider.php` and `routes.php` reverted those
> classes are not wired into anything — but the description "re-running the merge-base" was looser
> than the method. The round-1 reviewer also hit a practical edge reproducing it: the first
> post-checkout run can error inside `RefreshDatabase` bootstrap before settling on the second run,
> which is exactly the kind of artifact that can manufacture a "red at base" line. **A clean
> worktree checked out at the merge-base is the stronger control** if this ever needs re-running.

Characterisations of the four:

- `TreasuryDepositBridgeTest` (9) — `TreasuryDepositBridge::__construct` arity mismatch in the
  test's own wiring.
- `AccountStatusChangedServerOnlyTest` (1) — array key ORDER in an `assertSame`.
- `DepositReceiptAppendTest` (1) — **an `assertSame` on the payload KEY LIST**: the test's "16-key
  lean server contract" expects the keys **lexicographically sorted** (`:73-74`) while the payload
  arrives in **insertion order**, so the diff is `'actor_name', 'actor_user_id', 'business_date', …`
  against `'notes', 'payment', 'customer', …`. Failure reported at
  `DepositReceiptAppendTest.php:74`. **No timezone component at all** — same shape as
  `AccountStatusChangedServerOnlyTest` above.

  > **Corrected at M5 round 2, N-3.** Fix round 1 wrote this as *"a one-hour local-timezone artifact
  > … though this one does not clear under `PGTZ=UTC` and so has a second cause"* — a cause carried
  > over from an inherited M4 note and reconciled by inventing a "second cause", rather than read off
  > the failure output. That is F-2's failure mode in miniature, **added by the very commit that
  > rewrote this section because an inherited causal note had turned out to be false.** The count (1)
  > and the inventory (12-across-4) were never affected; only the stated cause was wrong. The cause
  > above is copied from the run.
- `Task33FiscalFullFlowVerificationTest` (1) — a PG `bytea` stream-handle `assertSame` in the test
  itself; all 14 assertions before it pass, i.e. the POST through the ES-42-gated route succeeds and
  the ingested row is read back.

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
> in this lane that touches VERIFIER code; no production file has changed since — the AutoERP fiscal
> verifiers
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

> **⚠️ The SHA above anchors the VERIFIERS, not the ratchets (recorded at M5 round 2, N-7).** The
> two are on different clocks and conflating them sends a reader to the wrong commit:
>
> - **Verifiers** — `fiscal:verify-event-chain`, its fleet driver and `pos:verify-chains`. No
>   production file has changed since `c533f005a` (the M5 commits after it change comments, tests,
>   docs and the ledger only, measured by `numstat`), so the seven tamper shapes reproduce at that
>   SHA and at every later one.
> - **Ratchets** — `OrphanedEventRatchetTest` and `ProjectorEmissionRatchetTest`. The projector
>   ratchet's DETECTOR changed at `413166120` (fix round 1, F-4: comments are now stripped before
>   matching). At `c533f005a` the detector still has the hole — the round-2 reviewer ran the pre-fix
>   detector against the comment probe and it went red, dropping `ZReportProjection`. **Re-run either
>   ratchet at the BRANCH TIP, never at a milestone SHA.**

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
6. **The projector-emission ratchet errs in BOTH directions** — it does not catch emission via a
   collaborator service (over-report), and it reads a `SomeJob::dispatch(` as an emission
   (under-report, the direction that loses coverage silently; not live today). Its
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
11. **RETRACTED at M5 round 1 (F-2) — `ReceiptReturnRefactorV3Test` is NOT an inherited red.** The
    first version of this item called it *"the one inherited red with a verification consequence"*
    and asked the parent to assign ownership. It is **GREEN at HEAD: 9 passed (345 assertions)**.
    The red was a PostgreSQL **session-timezone** artifact — the scratch server runs
    `timezone = Africa/Tunis`, the test's Z window is built from `Carbon::now('UTC')`, and every
    `pos_receipts.posted_at` renders one hour outside it; with `PGTZ=UTC` the suite is green
    (diagnosis and dumps in §4.3). The historical record (M2 round-1 finding 6, "2/9 red on this
    branch and on its base") was wrong in attribution — both runs used the same non-UTC server. The
    causal claim that its failure hid the post-Z-close `pos:verify-chains` assertion is **withdrawn**;
    the test reaches that assertion. The `blockers:` entry is removed. **The inherited-red inventory
    is 12 across 4, not 14 across 5.**
    *What A1 does inherit here is a harness rule, not a defect:* **run this lane's PG suites with a
    UTC session.** A non-UTC session manufactures red in every window-based aggregate test.
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
- **Two ratchets that fail on drift**, with baselines A1 will shrink rather than edit.
  `ProjectorEmissionRatchetTest`'s skip-list is A1's ES-01/02/03/04/05 worklist, already annotated
  with the register rows.
  **⚠️ Corrected at M5 round 1 (F-1): they do NOT gate `dev` today.** The first version of this line
  said "wired into an ordinary-push CI step", which A1 would have read as "my `dev` work is gated".
  It is not: `backend-test` carries a **job-level** `if:` limiting it to PR→`main`, push→`main` and
  `workflow_dispatch`, and a push to `origin/dev` fires no workflow at all. Until the enforcement-P2
  `ci.yml` reconciliation wires a lane that runs on PR→`dev`, **run the two ratchet files locally
  before promoting** — see §3 for the full trigger matrix.
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

`M4: passed` (verdict `M4-round1.md`, ACCEPT). `M5: review`, `fix_rounds: 1`, `last_verdict:
CHANGES-REQUIRED` (`M5-round1.md`), wave `status: review` — the parent orchestrator runs the
verification round. `blockers:` is now **empty** (the `ReceiptReturnRefactorV3Test` ownership
question was withdrawn at round 1, F-2). Branch `codex/es-wave-a0`, **not merged, not pushed**.

### Fix round 1 — what changed, and what did not

Round 1 was CHANGES-REQUIRED with **no code defect and no fiscal fact moved**. The reviewer
independently re-derived the 75-name census, reproduced both ratchets' tamper proofs, verified F-3's
discharge at the M4 tip, and re-derived the 7/13/17 trigger recount branch by branch. All three
Important findings landed on the *artifact* — the exit statement and the whole-lane evidence — which
is precisely what A1 trusts and nobody re-reads after an ACCEPT, the same failure mode that produced
M4-F-3 one milestone earlier.

| Finding | Disposition |
|---|---|
| **F-1** CI reach over-claimed | §3 rewritten with the real trigger matrix; §5.3 and the `ci.yml` step comment corrected; PR→`dev` wiring deferred to the enforcement-P2 `ci.yml` reconciliation |
| **F-2** `ReceiptReturnRefactorV3Test` | Root-caused to the PG session timezone, not re-run and hand-waved. GREEN at HEAD under `PGTZ=UTC`. §4.1/§4.2/§4.3 and exit item 11 corrected, blocker removed, inventory now 12-across-4 |
| **F-3** `ZReportHashService` path | Corrected; gate re-measured against the real file and it holds |
| **F-4** raw-source matching | **The only code change:** comments/doc-blocks stripped via `token_get_all()`, new test + two fixtures, both error directions documented |
| **F-5** cluster table 38 → 40 | All eleven clusters enumerated, Vehicle pair restored, table sums |
| **F-6** control caveat | Stated: `git checkout <base> -- <paths>` leaves lane-added files in place |
| **F-7** ES-88 generalisation | Narrowed name by name (`PointsRedeemedV2` does not exist; `PointsEarnedV2` is census-only; the V1s are never-emitted) |
