# M5 round 1 — TERMINAL WHOLE-LANE gate (lenses: fiscal-pos, + treasury applicability)

**Slice reviewed:** `9fdd9bc08..b705b2bfd` (`75f978674` sweeps, `c533f005a` ratchets, `b705b2bfd`
whole-lane evidence + exit statement).
**Whole-lane range spot-verified:** `a5520f23ca39209f5b517723037e9516808f2bca..b705b2bfd`
(merge-base re-derived by `git merge-base HEAD dev` — matches the evidence's claim).
**Branch tip at review:** `b705b2bfda8aba16e27c973856b89bfdf9c46558` on `codex/es-wave-a0`. Expected
tip matched. Tree clean at start and at end of review.

Everything below that says "verified" was executed in this worktree, on PostgreSQL by path
(`apps/api/phpunit-pgsql.xml`, `DB_DATABASE=autoerp_es_wave_a0_test`) or on the default driver, or
re-derived from source. Every tamper and every control was reverted and the tree re-checked clean.

---

## 1. F-3 — discharged for real, and the recount is CORRECT

**The ticket exists and says what it must.**
`docs/superpowers/tickets/2026-08-19-fiscal-seal-branch-unguarded-columns.md` names
`prevent_receipt_modification()` in
`apps/api/database/migrations/tenant/2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php:60-62`
(and the `down()` reproduction at `:180-181`), cites
`ImmutabilityTriggerPresenceTest::test_pg_the_pending_seal_to_fiscalized_branch_has_no_column_guards_characterization`
as executable evidence, names the owning lane ("receipt sealing / NF525 immutability"), states four
decisions that lane must make, and states blast radius honestly (PostgreSQL only, no field exploit
claimed, no production probe run). The YAML `findings:` entry exists at
`docs/handoff/progress/es-wave-a0.progress.yaml:573`.

**The M4 claim really was false — verified at the M4 tip, not taken on trust.**
`git ls-tree 59bb6913f docs/superpowers/tickets/` contains no `2026-08-19-*` file, and
`git show 59bb6913f:docs/handoff/progress/es-wave-a0.progress.yaml` holds exactly four `findings:`
entries (F-5, F-6, F-8, F-9 — all M3b). The self-correction is accurate.

**Column counts re-derived by me, branch by branch, from the trigger source:**

| Branch | Lines | Guarded columns (my count) | Evidence |
|---|---|---|---|
| `pending_seal → fiscalized` | `:60-62` | **0** — unconditional `RETURN NEW` | 0 ✔ |
| `fiscalized → voided` | `:64-75` | **7** (`fiscal_hash, receipt_number, total, subtotal, tax_amount, chain_sequence, posted_at`) | 7 ✔ |
| FK detach | `:78-96` | **13** identity guards (`:82-94`), plus `partner_id`/`contact_id` forced NULL as the branch's own entry condition | 13 ✔ |
| `sealed_hash_algorithm` backfill | `:120-141` | **17** (`:123-139` — the detach thirteen **plus** `partner_id`, `contact_id`, `fiscal_status`, `is_voided`) | 17 ✔ |

**7 / 13 / 17 is correct. M4's "15" was wrong, and M5's correction is right for the right reason.**
The test docblock (`ImmutabilityTriggerPresenceTest.php:51-64`) carries the corrected numbers and
the erratum. The finding is unchanged: the seal branch guards zero.

---

## 2. The orphaned-event ratchet — census independently re-derived: **75, name-for-name**

I did not trust the test's own run. I wrote a separate script that boots the same container,
reads the dispatcher's `listeners` property by reflection, re-implements the source-text dispatch
scan (namespace + `use` map → `new X(` / `X::dispatch(If|Unless)?(`, own defining file excluded),
and diffed the result against `BASELINE`.

```
EVENT_CLASSES=175  DISPATCHED=153  ORPHANS=75
WILDCARDS: {"*":["Spatie\\EventSourcing\\StoredEvents\\EventSubscriber@handle"]}
PROJECTORS: 0  REACTORS: 0
```

The 75 names are **identical** to `OrphanedEventRatchetTest::BASELINE`. Both asserted assumptions
hold outside the test as well: the wildcard set is exactly Spatie's one entry, and the Projectionist
is empty on both sides. I additionally checked that **no provider in `app/` is a
`DeferrableProvider`** (`grep -rln DeferrableProvider app` → empty), so the boot-time listener map
really is complete — the mechanism is sound, not merely self-consistent.

**Ten named classes spot-checked for genuine orphanhood** — `AccountCreated`,
`StockTransferCompleted`, `PurchaseOrderConfirmed`, `ReturnNoteConfirmed`, `OrderClosed`,
`ReplenishmentRequested`, `AppointmentScheduled`, `WithholdingCertificateIssued`, `WorkOrderCreated`,
`VoucherIssued`. For each: zero `X::class =>` listener-map keys anywhere in `app/`, and none appears
in any `Event::listen(...)` / `Event::subscribe(...)` site (I enumerated all of them). The one
apparent hit on `OrderClosed` is a substring match on `WorkOrderClosed`, which is a *different* class
and *does* have a listener. All ten are genuine orphans.

**`PointsExpired` is truly never emitted.** Grepped the whole backend including `tests/`: exactly two
references, both inside `app/Modules/Loyalty/Domain/Events/PointsExpired.php` (`:18`, `:20`). The
cited correction-of-a-correction is real — `docs/handoff/ES-REGISTER-CORRECTIONS-2026-08-11.md`
does say the three sampled ES-88 events "exist and are emitted (`PointsExpired.php:9-45`; …)",
citing the class's own definition file. M5's correction stands.

**The ES-21-shape disclaimer is accurate.** `ReceiptVoided` has two live consumers
(`EventServiceProvider.php:105-106` → `VoucherCascadeOnReceiptVoidedListener`, and
`DomainEventSubscriber.php:1133` → `handleReceiptVoided`) and **zero** production emitters
(`new ReceiptVoided(` / `ReceiptVoided::dispatch` in `app/` → empty; the only emitter is a test at
`tests/Feature/Voucher/VoucherCascadeServiceTest.php:369`). It therefore correctly does not appear in
the baseline, and this ratchet genuinely would not have caught it. The other three blind spots
(never-emitted class, indirect consumption, dispatch outside `app/`) are stated correctly.

**"14" traced.** The register snapshot carries **16** `DEAD-EVENT` rows (ES-12/14/20/21/25/48/75/77/
78/79/81/82/85/86/87/88) and its own class-count summary row says `**14**`. Either way the number is
register rows, not event classes. The docblock's tracing is defensible.

**TAMPER PROOF — reproduced by me.** Added a throwaway `ReviewerTamperProbeEvent` under
`app/Modules/POS/Domain/Events/` plus an emitter service:

```
RED:   Tests: 4, Assertions: 4, Failures: 1
       +    34 => 'App\Modules\POS\Domain\Events\ReviewerTamperProbeEvent',
GREEN: OK (4 tests, 4 assertions)   [after deleting both files]
```

`git status --porcelain` empty afterwards. The ratchet bites, and it names the offender.

---

## 3. The projector-emission ratchet — skip-list correct, both tamper directions reproduce

Six entries, matching the six registered POS-module projectors. Three cite register rows —
`PosCoreReceiptProjection` (ES-01, `:105-114`), `ZReportProjection` (ES-04, `:116-122`),
`ZSessionLifecycleProjection` (ES-03 + ES-02 + ES-05, three rows one class, `:124-135`). Three are
marked **`— NO REGISTER ROW`** with the do-not-invent instruction verbatim: *"Do not delete these
lines by inventing an event"* (`:92-103`) for `AccountChargeReceiptProjection`,
`AccountPaymentReceiptProjection`, `DepositReceiptProjection`. That is exactly the contract.

The full registered set is pinned on both sides (`:144-160`); the Treasury/Document bridges sit in
`writes_other_modules_projections` and are correctly **not** required to emit.

**TAMPER (a) — grow.** Registered a throwaway silent POS projector in `POSServiceProvider`'s
`tag([...], FiscalEventProjector::class)`:

```
Tests: 3, Assertions: 3, Failures: 2
+    4 => 'App\Modules\POS\Application\Projections\ReviewerProbeProjection',     (skip-list)
+        4 => 'App\Modules\POS\Application\Projections\ReviewerProbeProjection', (registered set)
```

Both assertions fire, exactly as claimed.

**TAMPER (b) — shrink.** Reproduced (see F-4 below for how, and why the *how* is itself a finding):
`Tests: 3, Assertions: 3, Failures: 1`, with `ZReportProjection` removed from the discovered list.

Both tampers reverted; `OK (3 tests, 3 assertions)`; tree clean.

---

## 4. CI wiring — the step is real, the *reach* is over-claimed

Verified true:

- Before this lane, `tests/Architecture` appeared in **exactly one** CI step,
  `.github/workflows/ci.yml:315-345` "Full backend suite (manual security gate)", guarded by
  `if: ${{ always() && github.event_name == 'workflow_dispatch' }}`. The claim that a ratchet placed
  in that directory alone would never gate anything but a manual dispatch is **correct**.
- The new step (`ci.yml:311-312`) runs **exactly the two files by path**, not the directory, and
  carries no `if:` of its own.
- The "unrelated inherited reds" justification is **correct and measurable**: I ran the whole
  directory on the default driver — `Tests: 53, Assertions: 238, Failures: 4` (the four are
  `QueueJobTenantContextTest` and siblings, nothing to do with this lane). Adding the directory
  would indeed turn the job red on landing.
- Both ratchet files are SQLite-safe: `OK (7 tests, 7 assertions)` on the default driver.

Over-claimed — see **F-1**.

---

## 5. Whole-lane evidence — spot-verified

**Shape.** `git diff --name-only a5520f23c..HEAD`: **18** files under `apps/api/app`, **19** under
`apps/api/tests`, **1** CI workflow, rest docs. `apps/api/database`, `apps/api/config`, `apps/web`,
`apps/pos`, `packages` → **empty**. Added `onQueue` lines → **none** (so no `horizon.php` entry is
owed; rule 20 satisfied). Added production lines containing `(float)`, `floatval`,
`number_format`, `parseFloat` or a bare no-arg `getScale()` → **none**; added production lines
containing any `bc*` call → **none** (rule 19 satisfied). All four counts match the evidence.

**Zero-line gates, re-measured by me over `a5520f23c..HEAD`:** `OutboxIngestor.php`,
`ParseFailureResolutionService.php`, `EnqueueResolvedEventProjectionsCommand.php`,
`RolesAndPermissionsSeeder.php` — all return **no numstat entry**, files present. `ZReportHashService`
also returns no entry — but only once measured against its **real** path; see **F-3**.

**Headline counts, re-run on PostgreSQL by path — every one reproduces exactly:**

| File | Mine | Evidence |
|---|---|---|
| `ImmutabilityTriggerPresenceTest` | 5 passed (10 assertions) | 5 / 10 ✔ |
| `FiscalEventIngestionEndpointTest` | 14 passed (69 assertions) | 14 / 69 ✔ |
| `VerifyEventChainCommandTest` | 34 passed (138 assertions) | 34 / 138 ✔ |
| `ParseFailureResumeTest` | 27 passed (209 assertions) | 27 / 209 ✔ |
| `ServerAuthoredChainContextScopingTest` | 6 passed (38 assertions) | 6 / 38 ✔ |
| `TreasuryReceiptBridgeTest` | 16 passed (54 assertions) | 16 / 54 ✔ |
| `DeadLetteredProjectionsControllerTest` | 12 passed (33 assertions) | 12 / 33 ✔ |

**Static gates re-run:** `pint --test` on the five M5-changed PHP files → `{"result":"pass"}`;
`phpstan --level=8` on the one changed production file → `[OK] No errors`.

**Inherited reds — four of five reproduce, one does not.** See **F-2**. The revert-proof *method*
is sound in principle but has an undisclosed edge; see **F-6**.

---

## 6. The M4 sweeps F-1 / F-2 / F-4 / F-7 — all closed as claimed

- **F-1.** `ServerAuthoredChainPlacementVerifier.php` diff is **comments only** (the whole M5
  production delta is comments). `deriveLinkageFailure()`'s docblock now states that both callers
  derive `$sequenceNumber`/`$previousHash` from the same `$prior` they pass in, that the hash arm
  re-verifies against the bytes that produced it, that **only the clock arm can fire**, that the
  scoped read is the actual defense, and that the arms **must not be cited** as evidence that
  mis-scoping is detectable. A pointer is added to the class docblock so the claim cannot be read out
  of context. Honest and complete.
- **F-2 — falsifiability confirmed by reading AND running.** `triggerNamesOn()` now branches:
  `pg_trigger` on pgsql (`:407-416`), `sqlite_master` on sqlite (`:418-426`), and **`$this->fail()`
  on any other driver** (`:428-434`) with a message that names the M4-F-2 failure mode. The non-PG
  arm asserts **both** tables (`:170-182`) and then performs the two mutations the `[PG]` tests prove
  are refused — an `UPDATE` of `current_hash` (`:189`) and a `DELETE` (`:198`) — and asserts each
  **SUCCEEDS** (`:190-196`, `:199-204`). That is a real behaviour that goes red the day enforcement
  reaches the driver. SQLite run: `4 skipped, 1 passed (4 assertions)` — assertion count up from 1 to
  4 exactly as claimed. **Yes, the SQLite arm asserts mutation SUCCESS, and yes, an unknown driver
  fails loudly.**
- **F-4.** `test_pg_the_fiscal_events_trigger_refuses_a_delete` (`:212-245`) now uses the nested
  `DB::transaction` savepoint pattern, matches the message against `/append-only ledger/`, and
  asserts the row **survives** (`:240-244`). Three of three two-sided.
- **F-7.** The standing ES-42 refusal test now asserts the fixture's `UserCompanyMembership` exists,
  that the principal genuinely lacks `pos.operate_terminal`, and that `error.code === 'FORBIDDEN'`
  (not `NO_COMPANY_ACCESS`). Three new assertions → 66 → 69, which is exactly the one deliberate
  count change disclosed in §4.1.
- The four "notes, not work" (`M4-F-5`, `M4-F-6`, `M4-F-8`, `M4-Minor`) are present under `findings:`
  at `es-wave-a0.progress.yaml:574-577`, each citing `M4-round1.md`.

---

## 7. TREASURY-LENS APPLICABILITY STATEMENT

**No treasury-lens blocker; the lens applies only through carried-forward notes.**

- The lane touches **zero** files under `apps/api/app/Modules/Treasury/**` — verified over the full
  `a5520f23c..HEAD` name list. The M5 slice's only production change is a docblock.
- The new projector ratchet classifies the four Treasury bridges and the Document bridge into
  `writes_other_modules_projections` and does **not** require them to emit — correct: the handover's
  clause is about POS projections, and `TreasuryReceiptBridge` *reads* `pos_receipts` (hence
  `priority=150` behind POS-core at `50`). The classification criterion is module ownership, stated
  rather than assumed, and the full set is pinned on both sides so a new Treasury projector is
  noticed.
- Treasury suites reproduce at HEAD: `TreasuryReceiptBridgeTest` 16/54, `DepositReceiptProjectionTest`
  and `TerminalRegistrySnapshotTest` unchanged in the lane's own accounting,
  `DeadLetteredProjectionsControllerTest` 12/33. `TreasuryDepositBridgeTest` is red (9 of 9) at HEAD
  and reproduces as inherited — PHPUnit reports it as **5 errors + 4 failures**, which the evidence
  collapses to "9 failed (5 assertions)"; the count is right, the wording is loose.
- The one live treasury exposure is **unchanged and correctly recorded, not fixed**: `M4-F-5` —
  `ServerAuthoredChainPlacementException extends RuntimeException`
  (`ServerAuthoredChainPlacementException.php:34`) thrown from
  `VirtualAdminFiscalEventService::appendDepositReceipt()`, which
  `RecordCustomerDepositService.php:108` calls inside its sealing transaction, so a `time_anomaly`
  verdict aborts a **customer money-in** flow as an unmapped 500 while `OutboxIngestor` treats the
  identical condition as accept-with-`time_anomaly`. Still open, still a note, still owed to a
  boundary-mapping lane. No money math, no scale resolution, no GL posting is touched anywhere in
  this lane.

---

## 8. FINDINGS

### F-1 (Important) — the CI reach is over-claimed: `backend-test` never runs on a push or PR to `dev`

`.github/workflows/ci.yml:185`:

```yaml
if: github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || (github.event_name == 'push' && github.ref == 'refs/heads/main')
```

and `ci.yml:2-8` triggers on `push: branches: [main]`, `pull_request: branches: [main, dev]`,
`workflow_dispatch`. So the new step gates **PR→main, push→main, workflow_dispatch** — and nothing
else. A push of local `dev` to `origin/dev` (the promotion path this whole program uses, rule 21)
fires **no workflow at all**; a PR→dev fires the workflow but **skips `backend-test`** on the
job-level `if`.

The evidence and the exit statement read otherwise. `M5-implementation-evidence.md:339-341`: *"An
ordinary push or PR never runs it"* — presented as the problem the new step fixes.
`ci.yml:296-302` (the step's own comment): *"a ratchet placed there alone would never gate an
ordinary push — i.e. it would not be 'in CI' in the sense the handover asks for"*. And
`M5-implementation-evidence.md:619` (§5.3, what A1 inherits): *"Two ratchets that fail on drift,
**wired into an ordinary-push CI step**"*. A1 will read that last sentence and believe its `dev`
work is gated. It is not.

The executor checked the **step-level** `if:` of the manual gate and did not check the **job-level**
`if:` one line above the steps. The improvement is real (workflow_dispatch-only → also PR→main /
push→main), but the guarantee handed forward is wider than the fact. Given the milestone's
deliverable *is* the exit statement, this is the finding that matters most.

**Fix:** state the actual trigger matrix in §3 and §5.3 and in the step comment ("gates PR→main,
push→main and manual dispatch; a push to `dev` runs no CI"), or — better — place the two-file step
in a job that runs on PR→dev.

### F-2 (Important) — `ReceiptReturnRefactorV3Test` is GREEN at HEAD **and** at the merge-base; the "2/9 red, inherited, unowned" story does not reproduce

Run twice on PostgreSQL by path at `b705b2bfd`:

```
OK (9 tests, 345 assertions)
OK (9 tests, 345 assertions)
```

The evidence records it twice as **`2 failed, 7 passed (253 assertions)`**
(`M5-implementation-evidence.md:403` and `:438`). I then reproduced the evidence's own base control
(`git checkout a5520f23c -- apps/api/app apps/api/tests`) and ran the same file: the first run
errored once inside `RefreshDatabase` bootstrap while the schema settled, the second gave
**`OK (9 tests, 345 assertions)`**. Restored; tree clean.

The other four inherited reds **do** reproduce at HEAD, exactly:
`TreasuryDepositBridgeTest` 9 red (5 assertions), `AccountStatusChangedServerOnlyTest` 1 (12),
`DepositReceiptAppendTest` 1 of 9 (26), `Task33FiscalFullFlowVerificationTest` 1 (14).

So §4.3's *"IDENTICAL, test for test and assertion for assertion … 14 failing tests across 5 files"*
is, today, **12 failing tests across 4 files**. The consequences run straight into the exit
statement:

- Exit item 11 (`:599-603`) calls this *"the one inherited red with a verification consequence"* and
  says *"**Someone has to own this**"*. The wave's only `blockers:` entry
  (`es-wave-a0.progress.yaml:595`) asks the parent to assign ownership of a red the parent cannot
  reproduce.
- The causal claim attached to it — *"its failure aborts the test **before** the post-Z-close
  `pos:verify-chains` assertion, which is how the context-flattening defect stayed invisible to the
  suite"* — is **unsupported** today: the test runs to completion and reaches that assertion.

The suite's shape explains why this is fragile rather than stable: the aggregate window is
`Carbon::now('UTC')`-relative (`ReceiptReturnRefactorV3Test.php:580-581`) and is compared as
`'Y-m-d H:i:s'` text against `pos_receipts.posted_at` (`:755-757`), with the pinned absolute at
`:778` (`bccomp($independentNet, '10.000')`). That is a time/environment-sensitive assertion, and the
historical red is far more likely environmental than inherited-by-code. Publishing it as an inherited
code red in a terminal exit statement is the kind of claim A1 will build on.

**Fix:** re-run it; correct §4.1, §4.3, exit item 11 and the `blockers:` entry to what reproduces
(12 / 4); and either retire the blocker or state precisely what makes it red.

### F-3 (Important) — one of the five headline zero-line gates in §4.0 is measured against a path that does not exist

`M5-implementation-evidence.md:372` publishes:

```
0  apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/ZReportHashService.php   (R-1 — the Z arm)
```

That file does not exist. The real path is
`apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php` (no `Fiscal/V3/` segment). A
reviewer running the published command against the published path gets an empty numstat **because
the path is wrong**, not because the gate holds.

The gate itself **does** hold — I re-measured `git diff --numstat a5520f23c..HEAD` against the real
path and it is empty, so R-1 is genuinely satisfied across the whole lane. But R-1 is the most
repeated constraint in this wave, §4.0 is its published proof, and this is precisely the
unfalsifiable-assertion class M4-F-2 faulted (an assertion that returns the expected answer without
touching the thing it claims to measure), in the exit milestone.

**Fix:** correct the path in §4.0.

### F-4 (Minor) — the projector-emission detector is plain source text: a COMMENT can retire a baseline entry, and a JOB dispatch reads as an emission

`ProjectorEmissionRatchetTest.php:285-293` matches three regexes against the raw file, with no
comment stripping and no token awareness.

Probe run and reverted — appending one line to `ZReportProjection.php`:

```php
// PROBE: event(new Something());
```

→ `Tests: 3, Assertions: 3, Failures: 1`, with `ZReportProjection` dropped from the discovered list
(this is also the shrink-direction tamper the evidence claims, reproduced). The failure message
(`:175-176`) then instructs: *"a projector started emitting. Delete its line AND close the register
row named in the comment above it."* A docblock edit on a 2 000-line projector can therefore talk a
future maintainer into closing **ES-04**.

Second half: pattern 2 is `/\b[A-Za-z_][A-Za-z0-9_\\]*::dispatch(?:If|Unless)?\s*\(/`, which matches
`SomeJob::dispatch(`. A new POS projector that dispatches a queued job and emits no domain event
would be classified as **emitting** and never enter the baseline — an **under**-report, which
contradicts the docblock's *"The direction of that error is conservative (it over-reports the gap)"*
(`:73-78`). Neither is live today: I confirmed all six POS projectors contain no `::dispatch`,
`->dispatch` or `event(` call.

**Fix:** strip comments before matching (or match on tokens), and name **both** directions in the
blind-spot list instead of asserting the error is one-directional.

### F-5 (Minor) — the never-registered-orphan table in §1 sums to 38 and omits the Vehicle cluster, while §5.3 says "forty"

Counted mechanically off the baseline's own annotations: 75 entries, of which **40** carry
`— no register row`. The evidence's cluster table (`M5-implementation-evidence.md:170-178`) lists
Scheduling 6, Taxation 6, WorkOrder 7, Technician 3, Voucher 4, Product 3, Catalog 3, and
Channel/Company/Loyalty 6 = **38**. Missing: `MileageAnomalyDetected` and `MileageReadingLogged`
(`OrphanedEventRatchetTest.php:236-238`). A1 is pointed at that table as its never-registered
inventory (§5.3: *"clustered by module in the baseline's comments"*), so the omission is small but
directly consumed.

### F-6 (Minor) — the whole-lane inherited-reds control is not quite "the merge-base"

`git checkout a5520f23c -- apps/api/app apps/api/tests` restores files that exist at the base; it
does **not** delete files this lane created (`ServerAuthoredChainPlacementVerifier.php`,
`QuarantineIncidentResolutionService/Controller`, the two new exceptions, the two ratchets,
`tests/Traits/ReadsCanonicalBytes.php`). The control is still adequate — with `FiscalServiceProvider.php`
and `routes.php` reverted those classes are unreferenced — but §4.3 describes it as re-running the
merge-base, which it is not exactly. I hit the practical edge reproducing it: the first post-checkout
run errored in `RefreshDatabase` bootstrap before settling green on the second, which is exactly the
kind of artifact that can manufacture a "red at base" line.

**Fix:** state the caveat, or run the control in a clean worktree checked out at the merge-base.

### F-7 (Minor) — the ES-88 sub-claim in the baseline comment is slightly wider than the measurement

`OrphanedEventRatchetTest.php:177-181` says *"it is the **V2** classes that are dispatched with no
listener"* for the row's five named names. But `PointsRedeemedV2` is **not** in the baseline, and
`PointsEarnedV2` — which is — is not one of the row's names. The census is right; the sentence
generalises past it.

---

## 9. Disposition

The contract in the M5 title is delivered in substance. Both ratchets exist, are **name-based** and
not count-based, and I proved both bite: the orphan ratchet went red naming a throwaway 76th and
green again on removal; the projector ratchet went red in the grow direction naming the probe in
**both** assertions, and red in the shrink direction. The census is not merely self-consistent — I
re-derived 75 independently, name for name, and separately confirmed the two assumptions the test
asserts (Spatie's `*` is the only wildcard; the Projectionist is empty) plus a third the test does
not name but needs (no deferrable providers, so the boot-time listener map is complete). Ten
baselined classes are genuinely orphaned. `PointsExpired` is genuinely never emitted, and the
correction-of-a-correction is real. The ES-21-shape disclaimer is accurate and I verified it from
both ends. F-3 — the wave's non-waivable item — is discharged properly, in both places the wave uses,
and the recount to 7/13/17 is right; I re-derived every branch column by column rather than trusting
the table. F-1, F-2, F-4 and F-7 are closed in code, and F-2's falsifiability specifically holds: the
SQLite arm asserts the mutations **succeed** and an unknown driver **fails loudly**. Every headline
count I re-ran on PostgreSQL reproduced exactly. Rules 19 and 20 are clean across the whole lane by
measurement, not assertion. The self-criticism in the opening commit ("THE M4 CLAIM WAS FALSE") is
accurate — I checked it at the M4 tip.

Where it does not hold is the artifact this milestone exists to produce. Three findings land on the
**exit statement and the whole-lane evidence**, which is what A1 will trust and what nobody re-reads
after an ACCEPT — the exact failure mode that produced M4-F-3 one milestone ago. The CI guarantee is
wider than the fact: the executor checked the manual gate's step-level `if:` and not the job-level
`if:` immediately above it, so "wired into an ordinary-push CI step" is false for the `dev` flow this
program actually uses. The inherited-red inventory is 12-across-4, not 14-across-5: the one suite the
exit statement singles out as *"the one inherited red with a verification consequence"* is green at
HEAD **and** at the merge-base on two runs each, and the causal story attached to it — that its
failure hides the post-Z-close `pos:verify-chains` assertion — is untrue today. And one of the five
zero-line gate lines is measured against a path that does not exist, which is a green obtained
without touching the thing being measured, in the milestone whose whole job is that the numbers can
be trusted.

None of this is a code defect and none of it changes a fiscal fact. All of it is one documentation
commit. But M5 is the last milestone: there is no next opening commit to sweep into, which is
precisely why M4's "it's ticketed" slipped through. So the sweeps land now.

**Owed in one fix-round commit — no production code change required:**

1. **F-1** — correct §3, §5.3 and the `ci.yml` step comment to the real trigger matrix (PR→main /
   push→main / workflow_dispatch; a push to `dev` runs no CI, a PR→dev skips `backend-test`), or wire
   a job that runs on PR→dev.
2. **F-2** — re-run `ReceiptReturnRefactorV3Test`; correct §4.1, §4.3, exit item 11 and the
   `blockers:` entry to what reproduces; retire the blocker or state the exact conditions that make
   it red, and drop or qualify the `pos:verify-chains`-never-reached causal claim.
3. **F-3** — fix the `ZReportHashService` path in §4.0.
4. **F-4** — strip comments before matching in `emitsADomainEvent()` (or match on tokens), and name
   both error directions in the blind-spot list.
5. **F-5 / F-6 / F-7** — add the Vehicle cluster to the §1 table (38 → 40), state the
   `git checkout <base> -- <path>` caveat in §4.3, narrow the ES-88 sentence.

VERDICT: CHANGES-REQUIRED
