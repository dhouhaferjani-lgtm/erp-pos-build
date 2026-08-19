# M5 round 2 — scoped verification of the round-1 fix round (lens: fiscal-pos)

**Slice reviewed:** `9ce7b2090..413166120` — one commit, `Phase 0.1.56: M5 fix round 1 — sweep the
seven round-one findings`.
**Branch tip at review:** `413166120fbec9f268259ebbe2c6ca7c32f94c16` on `codex/es-wave-a0`. Expected
tip matched. Tree clean at start and at end (`git status --porcelain` empty; `git diff --stat HEAD`
empty after every tamper was reverted).
**Merge-base re-derived:** `git merge-base HEAD dev` → `a5520f23ca39209f5b517723037e9516808f2bca`,
matching the evidence.

Scope: the seven round-1 findings, plus new-defect hunting **in the delta only**. Everything below
that says "measured" was executed by me in this worktree — PostgreSQL by path
(`phpunit-pgsql.xml`, `DB_DATABASE=autoerp_es_wave_a0_test`) or on the default SQLite driver.

**"Zero production files" — verified, not taken on trust.**
`git diff --numstat 9ce7b2090..413166120 -- apps/api/app` is **empty**. So are `apps/web`,
`apps/pos`, `packages`, `apps/api/database`, `apps/api/config`, `apps/api/routes`. The seven changed
paths are: `.github/workflows/ci.yml`, two ratchet test files, two new test fixtures, the ledger and
the evidence. No fiscal fact moved. Rule-19/20 hazard sweep over every added line (`onQueue`,
`(float)`, `floatval`, `parseFloat`, `number_format`, `bc*`, `toISOString`, bare `getScale()`,
`Number(`) → **no hits**. PHPStan is scoped to `app/` only (`phpstan.neon:6-8`), so there is nothing
for it to analyse; `pint --test` on the two changed test files and the fixtures directory →
`{"result":"pass"}`.

---

## 1. The seven findings — closure status

| # | Round-1 finding | Verified how | Status |
|---|---|---|---|
| F-1 | CI reach over-claimed | read `ci.yml:3-8` + `:185` + the new comment; checked all three published places | **CLOSED** |
| F-2 | `ReceiptReturnRefactorV3Test` red story | re-ran it 3 ways myself; isolated the variable with a PDO probe | **CLOSED** (attribution caveat → N-6) |
| F-3 | `ZReportHashService` bogus path | re-measured the gate against the real file; enumerated every occurrence of the bogus path | **CLOSED in §4.0; the frozen-register half is NOT recorded → N-2** |
| F-4 | raw-source matching | ran both controls **and** a pre-fix regression control | **CLOSED** |
| F-5 | cluster table 38 vs 40 | re-derived the 40 mechanically from the baseline source | **CLOSED** |
| F-6 | control-method caveat | caveat is present; its enumeration is short → N-4 | **CLOSED** |
| F-7 | ES-88 generalisation | grepped `PointsRedeemedV2` and every V1 dispatch site | **CLOSED** |

### 1.1 F-1 — the trigger matrix is now stated truthfully in all three places

I read the workflow myself rather than the evidence's quotation of it.

- `.github/workflows/ci.yml:3-8` — `on: push: branches: [main]`, `pull_request: branches: [main, dev]`,
  `workflow_dispatch`.
- `.github/workflows/ci.yml:185` (job-level, `backend-test`) —
  `if: github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || (github.event_name == 'push' && github.ref == 'refs/heads/main')`.

So the real matrix is: `workflow_dispatch` ✔, PR→`main` ✔, push→`main` ✔, PR→`dev` ✘ (workflow
starts, job skipped), push→`origin/dev` ✘ (no workflow at all). All three published places now say
exactly that:

1. **Evidence §3** (`M5-implementation-evidence.md:383-434`) — the five-row matrix table, with the
   job-level `if:` quoted verbatim and the deferral named.
2. **§5.3, what A1 inherits** (`:794-802`) — the "wired into an ordinary-push CI step" sentence is
   gone, replaced by "**⚠️ Corrected at M5 round 1 (F-1): they do NOT gate `dev` today**" plus the
   operative instruction "run the two ratchet files locally before promoting".
3. **The `ci.yml` step comment** (`ci.yml:296-313`) — states the matrix inline, states that a push to
   `dev` fires no workflow, and states the deferral to the enforcement-P2 `ci.yml` reconciliation
   with the rule-4 reason.

The deferral is the right call: widening `backend-test`'s job matrix from inside a fiscal-verifier
wave is exactly the scope creep rule 4 forbids. **F-1 closed.**

### 1.2 F-2 — I re-ran it three ways and isolated the variable

The brief asked me to confirm "red on Africa/Tunis session, green 9/345 on UTC". I ran it, and the
picture is slightly different from — but does not undermine — the executor's conclusion.

```text
(no PGTZ, this worktree)   OK (9 tests, 345 assertions)
PGTZ=UTC                   OK (8 tests, 9 assertions) [ratchets]  /  ReceiptReturnRefactorV3Test: OK (9 tests, 345 assertions)
PGTZ=Africa/Tunis          Tests: 9, Assertions: 253, Failures: 2
```

The two failures under `PGTZ=Africa/Tunis` are
`test_v3_sale_v4_refund_next_v3_sale_chain_verifies_green_starting_at_current_sequence_zero` and
`…_one`, both `Failed asserting that -1 is identical to 0` at
`tests/Feature/POS/ReceiptReturnRefactorV3Test.php:778` — i.e. **exactly** the historically recorded
red (`2 failed, 7 passed, 253 assertions`, `bccomp($independentNet,'10.000') === -1`), reproduced
on demand by flipping one environment variable.

**The mechanism is confirmed.** The root cause is real and the diagnosis is correct: a non-UTC
PostgreSQL *session* shifts `pos_receipts.posted_at` one hour out of a `Carbon::now('UTC')`-derived
text window, the three rows drop out, `$independentNet` becomes `0.000`.

**What I could not reproduce is the "default session is red" half** — see **N-6**. In this worktree
the default session is already UTC, so `ReceiptReturnRefactorV3Test` is green with no PGTZ set.

Everything the finding required is delivered and independently confirmed:

- **Exit item 11 is RETRACTED** (`M5-implementation-evidence.md:766-778`), the `pos:verify-chains`
  causal claim is explicitly **withdrawn**, and the harness rule ("run this lane's PG suites with a
  UTC session") is recorded both there and in the ledger.
- **`blockers:` is empty** — parsed, not eyeballed: `python3 -c "yaml.safe_load(...)"` on
  `docs/handoff/progress/es-wave-a0.progress.yaml` returns `blockers = []`, and the withdrawn entry
  is preserved verbatim as a comment. The file parses cleanly.
- **The inventory is 12-across-4** — I re-ran all four under `PGTZ=UTC`:

```text
TreasuryDepositBridgeTest             Tests: 9, Assertions: 5,  Errors: 5, Failures: 4
AccountStatusChangedServerOnlyTest    Tests: 1, Assertions: 12, Failures: 1
DepositReceiptAppendTest              Tests: 9, Assertions: 26, Failures: 1
Task33FiscalFullFlowVerificationTest  Tests: 1, Assertions: 14, Failures: 1
                                      -> 9 + 1 + 1 + 1 = 12 failing tests across 4 files
```

None clears under a UTC session. The spot-check the brief asked for is `DepositReceiptAppendTest`,
and it produced a **new finding about its stated cause** — see **N-3**.

### 1.3 F-3 — path corrected, gate re-measured against the real file

- The bogus path `.../POS/Domain/Services/Fiscal/V3/ZReportHashService.php` does **not** exist. The
  directory `app/Modules/POS/Domain/Services/Fiscal/V3/` *does* exist (it holds
  `CanonicalJsonEncoder.php`, `CanonicalPayloadBuilder.php`), which is why the wrong path looked
  plausible for three milestones.
- `find app -name 'ZReportHashService.php'` returns exactly one file:
  `app/Modules/POS/Domain/Services/ZReportHashService.php`.
- **Gate re-measured by me:** `git diff --numstat a5520f23ca..413166120 -- app/Modules/POS/Domain/Services/ZReportHashService.php`
  → **no entry**, file present. R-1's Z arm genuinely holds across the whole lane.
- The other four zero-line gates re-measured against their published paths — all four files exist and
  all four return no numstat entry.

§4.0 (`:461-469`) now publishes the correct path and a blockquote that states the class of the error
honestly ("a green obtained without touching the thing being measured"). **The half that is missing
is the inherited disclosure — see N-2.**

### 1.4 F-4 — I ran both controls, plus a control the fix round did not run

`ZReportProjection.php` backed up, tampered, restored; tree verified clean afterwards.

```text
CONTROL A  comment probe (// event(new …); // …::dispatch(); /* ->dispatch(new …) */)
           appended to ZReportProjection.php, POST-fix detector
           ->  OK (4 tests, 5 assertions)                      [does NOT bite — correct]

CONTROL A' same probe, PRE-fix detector (git show 9ce7b2090:…ProjectorEmissionRatchetTest.php)
           ->  Tests: 3, Assertions: 3, Failures: 1
               -    4 => 'App\Modules\POS\Application\Projections\ZReportProjection',
               [the hole was real, and the fix is load-bearing]

CONTROL B  a REAL emission (public function reviewerControlB(){ event(new \stdClass); })
           on the same file, POST-fix detector
           ->  Tests: 4, Assertions: 5, Failures: 1
               -    4 => 'App\Modules\POS\Application\Projections\ZReportProjection',
               [still bites — the stripping did not blind the detector]

RESTORED   ->  OK (4 tests, 5 assertions);  git status --porcelain empty
```

Control A' is mine, not the executor's: it proves the pre-fix detector **did** drop `ZReportProjection`
on a single comment line, so the round-1 finding was not theoretical and the token-stripping is
load-bearing rather than decorative.

- **Both fixtures exist** — `tests/Architecture/ProjectorEmissionFixtures/FixtureProjectorWithCommentedEmission.php`
  and `…/FixtureProjectorWithRealEmission.php`, both added by this commit. Neither is collected as a
  test (no `Test.php` suffix); the whole `tests/Architecture` directory still reports the same four
  inherited failures it did at round 1 (`AuthLifecycleTest`, `ConsoleCommandTenantContextTest`,
  `ControllerTenantContextTest`, `QueueJobTenantContextTest`) — the fixtures introduce none.
  The negative fixture's comment inventory does not match its description → **N-5**.
- **The `::dispatch` under-report direction is documented** — in the class docblock
  (`ProjectorEmissionRatchetTest.php:84-99`, "Blind spots — BOTH directions, named. The error is NOT
  one-directional"), in evidence §2 (`:300-313`) and in exit-statement item 6 (`:746-749`). Each
  names the queued-job case, says it is the direction that loses coverage silently, and gives the fix
  shape (resolve through the `use` map, require a `Domain\Events\` namespace). The first version's
  claim that the error was "conservative (it over-reports)" is explicitly retracted.
- I re-verified the supporting claim that no POS projector emits today: all six files in
  `app/Modules/POS/Application/Projections/` contain no real `::dispatch`, `->dispatch` or
  `event(new …)`. The only textual hits are inside doc-blocks and one string literal — which is
  itself worth a note, **N-8**.

**F-4 closed.**

### 1.5 F-5 — the cluster table, re-derived mechanically

I parsed `OrphanedEventRatchetTest::BASELINE` with a script (attribute each entry to its nearest
preceding contiguous comment block; mark it never-registered iff that block contains
`no register row`):

```text
TOTAL ENTRIES: 75
NO REGISTER ROW: 40
  Workshop 10 (= WorkOrder 7 + Technician 3)   Scheduling 6   Taxation 6   Voucher 4
  Catalog 3   Product 3   Channel 2   Company 2   Loyalty 2   Vehicle 2
UNANNOTATED: []
```

The evidence's table (`:168-181`) has **eleven** rows — 7 + 6 + 6 + 4 + 3 + 3 + 3 + 2 + 2 + 2 + 2 —
carries an explicit `| **Total** | **40** |` row, and includes the previously-omitted
**Vehicle** pair (`MileageAnomalyDetected`, `MileageReadingLogged`) in bold. It sums correctly and
matches my mechanical derivation entry for entry. The correction note names §5.3 as having been
right. **F-5 closed.**

I also confirmed the fix commit changed **only comments** in the baseline: the sorted list of the 75
entries at `9ce7b2090` and at `413166120` is byte-identical. No entry was added, removed or renamed.

### 1.6 F-6 — caveat stated

`M5-implementation-evidence.md:602-612` now states that `git checkout <base> -- apps/api/app
apps/api/tests` restores base content but does not delete lane-created files, that the control is
therefore "base plus this lane's new, unreferenced classes", that the description "re-running the
merge-base" was looser than the method, that the round-1 reviewer hit the `RefreshDatabase`-bootstrap
edge, and that a clean worktree at the merge-base is the stronger control. That is the finding.
Its parenthetical enumeration is short → **N-4**. **Closed.**

### 1.7 F-7 — ES-88 narrowed, verified by grep

```text
grep -rn "PointsRedeemedV2" app tests database routes config
  app/Modules/Loyalty/Domain/Events/PointsRedeemed.php:18: * If requirements change, create PointsRedeemedV2.
  (+ the two lines of the new baseline comment itself)
```

There is **no `PointsRedeemedV2` class** — `find app -name 'Points*.php'` lists `PointsEarned`,
`PointsEarnedV2`, `PointsExpired`, `PointsRedeemed` and no `PointsRedeemedV2`. Dispatch-site counts
in `app/` (excluding each class's own defining file):

```text
MemberEnrolled 0   TierUpgraded 0   TierDowngraded 0
RewardRedeemed 0   PointsRedeemed 0   PointsEarned 0
```

So every V1 is a never-emitted class, not an orphan by this ratchet's definition — exactly what the
narrowed comment (`OrphanedEventRatchetTest.php:177-196`) now says, name by name. The four V2
siblings it names (`MemberEnrolledV2`, `TierUpgradedV2`, `TierDowngradedV2`, `RewardRedeemedV2`) are
all in the baseline, and `PointsEarnedV2` is correctly marked "Census-only — ES-88 does not name this
one". **F-7 closed.**

### 1.8 Both ratchets — 8/9 on both drivers

```text
default (SQLite, phpunit.xml)          OK (8 tests, 9 assertions)
PGTZ=UTC (phpunit-pgsql.xml, by path)  OK (8 tests, 9 assertions)

per file:  OrphanedEventRatchetTest    OK (4 tests, 4 assertions)
           ProjectorEmissionRatchetTest OK (4 tests, 5 assertions)
```

As the brief specified. This is also the number that §4.1 fails to record → **N-1**.

---

## 2. NEW FINDINGS (delta only)

### N-1 (Important) — §4.1 still publishes the ratchets at their PRE-fix counts, contradicting §2 and the commit message of the same commit

`M5-implementation-evidence.md:474-475`:

```markdown
| `tests/Architecture/OrphanedEventRatchetTest` | 4 passed (4 assertions) |
| `tests/Architecture/ProjectorEmissionRatchetTest` | 3 passed (3 assertions) |
```

Measured at this tip: `ProjectorEmissionRatchetTest` is **4 passed (5 assertions)** — the fix round
added `test_a_commented_out_emission_does_not_count_as_emitting` with two assertions.

This is not an oversight the author was unaware of. The same commit's own message says *"both
ratchets 8 passed (9 assertions, **up from 7/7** by the new F-4 test)"*, and evidence §2's control
block three hundred lines earlier prints `4 passed (5 assertions)` twice (`:337`, `:339`). So the
document contradicts itself, and the stale number sits in **§4.1 — "Every test file this lane
touched, re-run on PostgreSQL by path"**, which is the published per-file record a reviewer diffs
against.

Why it matters beyond tidiness: `ProjectorEmissionRatchetTest` is the ratchet that guards
ES-01/02/03/04/05. The next person who re-runs it gets 4/5 against a published 3/3 and has to decide
whether the ratchet was tampered with. A count mismatch on a *ratchet* manufactures exactly the alarm
the ratchet exists to make meaningful. This is the same class as F-1 and F-3 — the artifact the
milestone exists to produce, wrong about the one thing this round changed.

**Fix:** set the two rows to `4 passed (4 assertions)` and `4 passed (5 assertions)`.

### N-2 (Minor) — F-3's disclosure stops at M5; the same non-existent path is still published in three FROZEN registers, unmarked

The correction blockquote (`:461-469`) and the ledger entry (`es-wave-a0.progress.yaml:580-586`) both
describe the bogus path as something "the first version of this block" did. It is older than that:

```text
docs/handoff/reviews/es-wave-a0/M3b-implementation-evidence.md:43
docs/handoff/reviews/es-wave-a0/M3b-round1.md:45
docs/handoff/reviews/es-wave-a0/M4-round1.md:31
```

Three frozen registers — two of them **review** registers, i.e. the record of a reviewer confirming
the gate — each publishing `0 lines apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/ZReportHashService.php`
as proof of R-1. A reader who opens M3b or M4 still gets the unfalsifiable green, with no pointer to
the correction. The parent's round-2 brief named this disclosure explicitly ("the inherited
bogus-path disclosure (three frozen registers) recorded honestly"), and it is not recorded anywhere
in the delta.

Nothing should be edited in the frozen registers. The disclosure belongs in the live artefacts.

**Fix:** one sentence in the §4.0 blockquote and in the ledger's F-3 entry naming the three inherited
sites by `file:line` and stating that they are frozen and left as-is, so the correction is reachable
from the record they contaminate.

### N-3 (Minor) — the fix round adds a NEW un-measured causal claim about `DepositReceiptAppendTest`, in the section rewritten to stop doing that

`M5-implementation-evidence.md:619-620` (the parenthetical is new in this commit):

```markdown
- `DepositReceiptAppendTest` (1) — a one-hour local-timezone artifact (and now understood as the
  same harness property that produced the `ReceiptReturnRefactorV3Test` false red, though this one
  does **not** clear under `PGTZ=UTC` and so has a second cause).
```

Measured, `PGTZ=UTC`, this tip:

```text
1) Tests\Feature\Fiscal\DepositReceiptAppendTest::test_append_deposit_receipt_creates_server_authored_virtual_admin_event
Failed asserting that two arrays are identical.
-    0 => 'actor_name'      +    0 => 'notes'
-    1 => 'actor_user_id'   +    1 => 'payment'
-    2 => 'business_date'   +    2 => 'customer'
…
tests/Feature/Fiscal/DepositReceiptAppendTest.php:74
```

The assertion at `:74` is the "16-key lean server contract, **lexicographically sorted**" payload-key
`assertSame`; the actual payload arrives in insertion order. There is **no timezone component at
all** — it is the same shape as `AccountStatusChangedServerOnlyTest` ("array key ORDER in an
`assertSame`"), which the very next line of §4.3 characterises correctly.

The count (1) is right and the inventory (12-across-4) is unaffected. But this is F-2's failure mode
reproduced in miniature: a cause asserted from an inherited note rather than from the failure output,
written into the terminal exit evidence, in the paragraph that was rewritten *because* an inherited
causal note turned out to be false.

**Fix:** replace with the measured cause — "an `assertSame` on the payload key list: the contract
expects lexicographic order, the payload arrives in insertion order (`DepositReceiptAppendTest.php:74`)"
— and drop the timezone attribution.

### N-4 (Minor) — the F-6 caveat's file list reads as exhaustive and is short by two-thirds

`M5-implementation-evidence.md:604-606` lists the lane-created files the control does not delete as
"(`ServerAuthoredChainPlacementVerifier.php`, `QuarantineIncidentResolutionService` / `Controller`,
the two new exceptions, the two ratchets, `tests/Traits/ReadsCanonicalBytes.php`)".

`git diff --diff-filter=A --name-only a5520f23ca..413166120 -- apps/api` returns **20** files —
9 production, 11 test. Not in the list: `ServerAuthoredChainPlacementException.php` (so it is **three**
new exceptions, not two), two new console commands (`AuthorizedFiscalChainCommand.php`,
`VerifyEventChainFleetCommand.php`), `ReceiptChainArmVerificationResult.php`, six added feature test
files and the two new fixtures.

The caveat exists precisely to state how far the control is from a clean base checkout, so
understating the residue by two-thirds blunts the finding it is closing. (Mitigating: the list is
copied verbatim from the round-1 reviewer's own illustrative parenthetical.)

**Fix:** prefix with "including" and point at `git diff --diff-filter=A --name-only <base>..HEAD --
apps/api`, or enumerate.

### N-5 (Minor) — the negative fixture is described as containing a control it does not contain

`M5-implementation-evidence.md:330-331`: *"one whose only emissions are inside `//`, **`#`** and
`/* */` comments and a doc-block"*.

`tests/Architecture/ProjectorEmissionFixtures/FixtureProjectorWithCommentedEmission.php` has two
`//` lines, one `/* */` block and one doc-block. `grep -n '#'` on it returns **nothing**. There is no
`#` comment.

Behaviour is unaffected — I verified `#` tokenises as `T_COMMENT`
(`token_get_all("<?php # event(new X());")` → `T_OPEN_TAG T_COMMENT …`), so the stripping would
handle it. The defect is that a *control* is described in the evidence and does not exist in the
fixture, which is the small end of the same class as F-3.

**Fix:** add a `# event(new SomethingHappened());` line to the fixture — that genuinely widens the
control and makes the sentence true — or drop `#` from the sentence.

### N-6 (Minor) — the F-2 root cause is located on the SERVER; measured here it is a CLIENT environment variable, and the "default session is red" line does not reproduce

`M5-implementation-evidence.md:551-553`: *"The scratch PostgreSQL server reports `show timezone` →
**`Africa/Tunis`** (UTC+1)"*; `:770`: *"the scratch server runs `timezone = Africa/Tunis`"*;
`es-wave-a0.progress.yaml:683`: same. And §4.3 publishes `default scratch session -> 2 failed …
[x3, deterministic]`.

Measured in this worktree against the same database:

```text
psql -d postgres                      show timezone -> UTC
psql -d autoerp_es_wave_a0_test       show timezone -> UTC
select * from pg_db_role_setting                    -> 0 rows      (no per-db / per-role override)

PDO, no PGTZ            -> "UTC"
PDO, TZ=Africa/Tunis    -> "UTC"          (the OS timezone does NOT propagate; /etc/localtime is Africa/Tunis)
PDO, PGTZ=Africa/Tunis  -> "Africa/Tunis"
PDO, PGTZ=UTC           -> "UTC"

ReceiptReturnRefactorV3Test, no PGTZ    -> OK (9 tests, 345 assertions)
ReceiptReturnRefactorV3Test, PGTZ=UTC   -> OK (9 tests, 345 assertions)
ReceiptReturnRefactorV3Test, PGTZ=Tunis -> Tests: 9, Assertions: 253, Failures: 2  (both at :778)
```

The server GUC is UTC; the only channel that produces a non-UTC session is the **client's `PGTZ`
environment variable** in the executor's shell. So (a) "the default session" is not a stable property
of the scratch database — in this worktree the default session **is** UTC and the suite is green with
no variable set, and (b) the diagnosis as written invites the next reader to go looking at, or
"fixing", a server that is already correct.

None of this weakens the conclusion. The mechanism reproduces exactly on demand — `PGTZ=Africa/Tunis`
gives the historically recorded `2 failed / 253 assertions` at the exact line — the retraction of
exit item 11 is right, the empty `blockers:` is right, 12-across-4 is right, and the carry-forward
rule (`PGTZ=UTC`) is correct and sufficient as written.

**Fix:** one clause — "the executor's client session sent `PGTZ=Africa/Tunis`; the server's
`timezone` GUC is UTC, so the default session is environment-dependent" — in §4.3, exit item 11 and
the ledger.

### N-7 (Minor) — the ledger still anchors the tamper evidence to `c533f005a`, which is no longer the last code commit

`es-wave-a0.progress.yaml` keeps `commit: c533f005a` and the A0 exit-statement comment still reads
*"Seven tamper shapes demonstrated red with green controls, as of the last CODE commit
`c533f005ae2c9a80302089f9d92b1f6542fc2f21`"*. `413166120` changed `ProjectorEmissionRatchetTest`'s
detector, so `c533f005a` is no longer the last code commit, and the projector ratchet at that commit
is the **pre-fix** detector — which, as Control A' above shows, behaves differently. Anyone
re-running the seven tamper shapes at the anchored commit reproduces the hole, not the fix. The
`# Fix-round-1 slice: 9ce7b2090..HEAD` line is a partial mitigation.

**Fix:** move `commit:` to `413166120`, or append "plus the F-4 detector change at `413166120`" to
the exit-statement comment.

### N-8 (Minor, hardening) — comments are stripped, string literals are not, and there is already a near-miss in the probed file

`emitsADomainEvent()` (`ProjectorEmissionRatchetTest.php:329`) now strips `T_COMMENT` /
`T_DOC_COMMENT` but leaves `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE` in the haystack.
`ZReportProjection.php:326` already contains the text `event(s)` inside a message string. It is
harmless today — pattern 1 is `/\bevent\s*\(\s*new\s+/` and requires `new` — but a string containing
`event(new …)` (a log line, an exception message, a code sample in a `<<<TXT` block) would read as an
emission and silently retire a baseline entry. That is the same **under-report** direction the
docblock now says it names exhaustively.

**Fix:** drop string tokens as well as comment tokens (one `||` in the `token_get_all` loop), or add
string literals to the under-report bullet.

---

## 3. Treasury-lens applicability

**Not applicable to this delta.** The commit changes zero production files and touches nothing under
`apps/api/app/Modules/Treasury/**`. The treasury observations recorded at round 1 (M4-F-5's
`ServerAuthoredChainPlacementException extends RuntimeException` reaching a customer money-in flow as
an unmapped 500) are unchanged, still recorded as notes, still owed to a boundary-mapping lane. The
one treasury-adjacent change in the delta is descriptive: `TreasuryDepositBridgeTest`'s "9 failed" is
now written as "9 red (5 errors + 4 failures, 5 assertions)", which matches what PHPUnit prints — I
reproduced `Tests: 9, Assertions: 5, Errors: 5, Failures: 4`.

---

## 4. Disposition

**The substance of all seven findings is closed, and I verified each one by execution rather than by
reading the claim.** F-4 is the strongest of them: I reproduced both controls and additionally ran the
pre-fix detector against the same comment probe, which went red and dropped `ZReportProjection` —
proving the hole was real and the token-stripping is load-bearing. F-2's root cause is genuinely
diagnosed, not hand-waved: flipping one environment variable reproduces the historically recorded
`2 failed / 253 assertions` at the exact assertion line, and the retraction, the empty `blockers:`
and the 12-across-4 inventory are each independently confirmed — I re-ran all four remaining reds
under a UTC session and none of them clears. F-5's forty is right, re-derived mechanically from the
baseline source with eleven clusters that sum. F-7's narrowing is right name by name — there is no
`PointsRedeemedV2` class anywhere, and all six V1s have zero dispatch sites. F-1 is corrected in all
three places against a workflow I read myself, with a defensible rule-4 deferral. F-3's gate holds
against the real file. Both ratchets are 8/9 on the default driver and on PG under `PGTZ=UTC`, the
baseline's 75 entries are byte-identical to the pre-fix list, the ledger parses, `pint` passes, and
the "zero production files" claim is true by `numstat`.

**Where it does not close is, once again, the artifact.** Round 1's whole argument was that M5's
deliverable *is* the exit statement, that A1 trusts it and nobody re-reads it after an ACCEPT, and
that this is the failure mode that produced M4-F-3. That argument still applies to the fix round.
§4.1 — the published per-file re-run table — still records the projector ratchet at 3/3 when it is
4/5, contradicting both §2 of the same document and the commit's own message, on the single file this
round changed; a stale count on a *ratchet* manufactures precisely the tamper alarm the ratchet
exists to make meaningful. And the F-3 disclosure the parent asked for by name is absent: the
non-existent path is still published as proof of R-1 in three frozen registers, two of them review
registers, with no pointer from them to the correction. The remaining five notes are small, but three
of them (N-3, N-5, N-6) are the same shape as the findings being closed — a cause or a control
asserted rather than measured — and N-3 was *added* by this commit inside the section rewritten to
stop doing that.

No code defect. No fiscal fact moved. No production file changed. Nothing here threatens the lane's
substance, and none of it needs a code change except the optional one-token hardening in N-8. It is
one documentation commit — the same shape as the last one, and for the same reason: this is the
terminal milestone and there is no next opening commit to sweep into.

**Owed in one fix-round commit:**

1. **N-1** — §4.1: `OrphanedEventRatchetTest` `4 passed (4 assertions)`, `ProjectorEmissionRatchetTest`
   `4 passed (5 assertions)`.
2. **N-2** — record the inherited bogus-path disclosure: name `M3b-implementation-evidence.md:43`,
   `M3b-round1.md:45`, `M4-round1.md:31` in the §4.0 blockquote and the ledger F-3 entry, stating
   they are frozen and left unedited.
3. **N-3** — replace `DepositReceiptAppendTest`'s characterisation with the measured cause (payload
   key-order `assertSame` at `:74`); drop the timezone attribution.
4. **N-4 / N-5 / N-6 / N-7** — mark the F-6 file list non-exhaustive; make the `#`-comment sentence
   true (preferably by adding the line to the fixture); state that the non-UTC session came from the
   client's `PGTZ`, not the server GUC; re-anchor `commit:` / the exit-statement comment to
   `413166120`.
5. **N-8** — optional but cheap: strip string tokens too, or list string literals under the
   under-report blind spot.

VERDICT: CHANGES-REQUIRED
