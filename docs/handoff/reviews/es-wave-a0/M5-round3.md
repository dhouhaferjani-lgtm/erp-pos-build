# M5 round 3 — scoped verification of fix round 2, and the N-6 adjudication (lens: fiscal-pos)

**Slice reviewed:** `4029656fc..b4b6fef78` — one commit, `Phase 0.1.58: M5 fix round 2 — sweep the
eight round-two notes`.
**Branch tip at review:** `b4b6fef78c40b070d77ddb684fe319c4295a2a29` on `codex/es-wave-a0`. Expected
tip matched. Tree clean at start and at end (`git status --porcelain` empty).

Scope: the eight round-2 notes (N-1…N-8), the **adjudication of the disputed N-6**, and new-defect
hunting in the delta only. Everything below marked "measured" was executed by me in this worktree.

**"Zero production files" — verified, not trusted.**
`git diff --numstat 4029656fc..b4b6fef78 -- apps/api/app` is **empty**; so are `apps/web`,
`apps/pos`, `packages`, `apps/api/database`, `apps/api/config`, `apps/api/routes` and `.github`. The
delta is **three files**: `apps/api/tests/Architecture/ProjectorEmissionRatchetTest.php`, the ledger,
and the M5 evidence. The test-file change is **docblock-only** — every added line that is not a `*`
continuation, extracted mechanically, is the empty set. Rule-19/20 hazard sweep over every added line
in the whole delta (`(float)`, `floatval`, `parseFloat`, `Number(`, `number_format`, `onQueue`,
`toISOString`, bare `getScale()`, `bcformat(`) → **no hits**. `pint --test` on the touched test file
→ `{"result":"pass"}`. The ledger parses (`yaml.safe_load`) and `blockers` is `[]`.

---

## 1. THE N-6 ADJUDICATION — I ran the probe on both ports myself

The executor disputed my round-2 attribution with measurement. I re-ran it independently, on both
instances, and then ran the one experiment neither side had run.

### 1.1 The probe, both ports, `PGTZ` explicitly unset

My shell had `PGTZ` unset to begin with (`PGTZ=[<unset>]`); I still forced `env -u PGTZ`.

```text
env -u PGTZ, same query, same client, same database name:

port 5432   server_version 15.15 (Homebrew)
  select setting, reset_val, source, boot_val from pg_settings where name='TimeZone'
  ->  Africa/Tunis | Africa/Tunis | configuration file | GMT
  show config_file -> /opt/homebrew/var/postgresql@15/postgresql.conf
  grep in that file -> timezone = 'Africa/Tunis'

port 5433   server_version 16.10 (containerised, docker name autoerp_postgres)
  ->  UTC | UTC | configuration file | GMT

select datname from pg_database where datname='autoerp_es_wave_a0_test'
  port 5432 -> autoerp_es_wave_a0_test
  port 5433 -> autoerp_es_wave_a0_test        [BOTH instances host it]
```

The 5432 config file **literally contains `timezone = 'Africa/Tunis'`**. That is a server GUC set in
`postgresql.conf`, reachable with no client variable of any kind.

### 1.2 The discriminator logic, proven rather than asserted

The executor's stated signature is that `source = configuration file` marks a server GUC while a
client `PGTZ` would report `source = client`. I tested that directly against the UTC instance:

```text
port 5433, PGTZ=Africa/Tunis  ->  Africa/Tunis | Africa/Tunis | client   | GMT
port 5433, PGTZ=UTC           ->  UTC          | UTC          | client   | GMT
port 5433, PGTZ unset         ->  UTC          | UTC          | configuration file | GMT
```

`source` discriminates exactly as claimed.

### 1.3 The decisive experiment — flip only the port

Neither round had run this. Same shell, same command, `PGTZ` unset in both, **only `DB_PORT`
changed**:

```text
env -u PGTZ DB_PORT=5433 DB_DATABASE=autoerp_es_wave_a0_test \
  phpunit -c phpunit-pgsql.xml tests/Feature/POS/ReceiptReturnRefactorV3Test.php
  ->  OK (9 tests, 345 assertions)

env -u PGTZ DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test \
  phpunit -c phpunit-pgsql.xml tests/Feature/POS/ReceiptReturnRefactorV3Test.php
  ->  Tests: 9, Assertions: 253, Failures: 2
      …_starting_at_current_sequence_zero  Failed asserting that -1 is identical to 0
      …_starting_at_current_sequence_one   Failed asserting that -1 is identical to 0
      both at ReceiptReturnRefactorV3Test.php:778
```

The historically recorded red — `2 failed / 253 assertions`, both at `:778` — **reproduces with
`PGTZ` never set**, purely by pointing at the other instance.

### 1.4 RULING

**The executor's attribution STANDS. My round-2 attribution is REFUTED by measurement, and I
withdraw it.**

Round 2 wrote: *"the only channel that produces a non-UTC session is the client's `PGTZ`
environment variable in the executor's shell."* That is false on this machine. It was true only of
the instance round 2 happened to reach. The discriminating variable is the **instance**, exactly as
the executor said: two local PostgreSQL servers, both hosting a database named
`autoerp_es_wave_a0_test`, one with a `postgresql.conf` timezone of `Africa/Tunis` and one UTC.
Round 2 generalised from a single instance to "the server", which is the same
identify-the-variable error it was faulting elsewhere. The executor was right to refuse to write my
proposed clause into the terminal exit evidence: it would have been a false statement in the
artifact.

**The recorded carry-forward rule is the right durable statement** — pin the instance
(`DB_PORT=5433`) or set `PGTZ=UTC`, and verify with
`select setting, reset_val, source from pg_settings where name='TimeZone'` before trusting a red. It
is correct as written, and it is the operative half: it does not ask the reader to believe either
side's attribution, it asks them to measure. The `source` column is already in the recommended
query, which is the column that actually discriminates.

**Two refinements to the record (neither changes the ruling):**

1. **The `reset_val` half of the executor's signature is wrong.** The commit message and the ledger
   say *"a client PGTZ would show source=client and leave reset_val alone"*. Measured, `PGTZ` moves
   `reset_val` too (`PGTZ=Africa/Tunis` on the UTC server → `reset_val=Africa/Tunis`). The
   load-bearing discriminator is **`source` alone**; `reset_val` is not a marker. Recorded here for
   whoever next reads that sentence. The conclusion is unaffected because `source = configuration
   file` is definitive on its own — and §1.3 settles it without either marker.
2. **The rule is better justified than the executor knew.** `apps/api/.env` in this worktree is a
   **symlink** to `apps/erp/apps/api/.env` in the main repo (`.env -> …/apps/erp/apps/api/.env`),
   currently `DB_PORT=5433`, and `phpunit-pgsql.xml` pins no `DB_PORT`. So the instance a run reaches
   is set by a file shared with every other worktree and mutable by parallel sessions between
   rounds. That is very likely how the two rounds reached different servers, and it is precisely why
   "pin the instance and verify `pg_settings`" is the correct durable rule rather than a courtesy.

**A dispute resolved by measurement on both sides.** The executor did the right thing: it re-measured
rather than deferring to the reviewer, published the probe output, marked the note
`NOT ADOPTED AS PROPOSED`, flagged it to the parent, and still adopted the reviewer's underlying
point ("the scratch server" was never a sufficient identifier) in a stronger form than the reviewer
had proposed. That is the behaviour this program is built to produce.

---

## 2. The eight notes — closure status

| # | Note | Verified how | Status |
|---|---|---|---|
| N-1 | §4.1 published the projector ratchet at pre-fix 3/3 | re-ran both ratchets per-file on PG | **CLOSED** |
| N-2 | frozen-register disclosure absent | read all three cited lines; confirmed unedited | **CLOSED** |
| N-3 | un-measured `DepositReceiptAppendTest` cause | read `:73-74` in the test | **CLOSED** |
| N-4 | F-6 residue understated | re-ran the `--diff-filter=A` count | **CLOSED** |
| N-5 | `#` control described, absent | reproduced the pint rewrite empirically | **CLOSED** |
| N-6 | disputed attribution | probed both ports + port-flip experiment | **ADJUDICATED — executor stands** |
| N-7 | exit-statement SHA anchor | numstat since `c533f005a` | **CLOSED** |
| N-8 | string literals not stripped | read the detector; read `ZReportProjection.php:326` | **CLOSED (recorded as limitation)** |

### 2.1 N-1 — the table now matches what the ratchets actually do

Measured at this tip, PG by path with a UTC session, and on the default SQLite driver:

```text
both files together, PG (DB_PORT=5433)   OK (8 tests, 9 assertions)
both files together, default driver      OK (8 tests, 9 assertions)

OrphanedEventRatchetTest                 OK (4 tests, 4 assertions)
ProjectorEmissionRatchetTest             OK (4 tests, 5 assertions)
```

§4.1 now publishes `4 passed (4 assertions)` and `4 passed (5 assertions) — corrected at round 2,
N-1`. Both match. **The brief's "both ratchets 8/9" is confirmed on both drivers.** The sentence
above the table now reads *"with two deliberate exceptions"* and names both — the
`FiscalEventIngestionEndpointTest` 66→69 one (F-7 attribution assertions in `75f978674`) and this
one (`test_a_commented_out_emission_does_not_count_as_emitting`, F-4). Closed.

### 2.2 N-2 — the frozen-register disclosure, with the rationale I was hoping for

All three citations are exact. I read each line:

```text
M3b-implementation-evidence.md:43  0 lines  apps/api/…/Fiscal/V3/ZReportHashService.php
M3b-round1.md:45                   0        apps/api/…/Fiscal/V3/ZReportHashService.php  (R-1)
M4-round1.md:31                    0        apps/api/…/Fiscal/V3/ZReportHashService.php  (R-1)
```

`git diff --name-only 4029656fc..b4b6fef78` over those three paths is **empty** — they are genuinely
left unedited, as stated. The disclosure is recorded in both live artefacts (evidence §4.0
blockquote, ledger F-3 entry).

The rationale is the correct one and is better than the fix I proposed: *"a review register is the
record of what a reviewer actually wrote, and rewriting one to be correct destroys exactly the
evidence it exists to preserve."* That is right, and it generalises — it is the principle that makes
this whole review chain auditable. The disclosure also notes that two of the three are **review**
registers, so the unfalsifiable green was independently re-published rather than merely copied, and
it tells the reader what to re-measure against.

The plausibility note is also verified: `app/Modules/POS/Domain/Services/Fiscal/V3/` **does** exist
and contains exactly `CanonicalJsonEncoder.php` and `CanonicalPayloadBuilder.php`, and
`find … -name 'ZReportHashService.php'` returns exactly one file, at
`app/Modules/POS/Domain/Services/ZReportHashService.php`. Closed.

### 2.3 N-3 — the measured cause, and correctly ungrouped

`DepositReceiptAppendTest.php:73-74`, read directly:

```php
// 16-key lean server contract, lexicographically sorted.
$this->assertSame([
    'actor_name',
    'actor_user_id',
```

Exactly the cause now published: an `assertSame` on the payload **key list**, contract expects
lexicographic order, payload arrives in insertion order, failing at `:74`, no timezone component.
The timezone attribution and the invented "second cause" are gone.

Critically, the **ungrouping** is done in both places: §4.3's carry-forward bullet and the ledger
both now say the M4 register's "one-hour local-TZ artifact" note on this test is *a different defect
and does not belong to this class … Round 1 grouped the two together; they are unrelated.* That was
the substantive half of N-3 — a false shared cause would have sent the next reader chasing a
timezone for a key-order bug. Closed.

### 2.4 N-4 / N-5 / N-7 / N-8 — the four sweeps

**N-4.** Re-measured: `git diff --diff-filter=A --name-only a5520f23c..HEAD -- apps/api` → **20**;
`-- apps/api/app` → **9**; `-- apps/api/tests` → **11**. The published "20 files (9 production, 11
test)" is exact. The three exceptions are real —
`QuarantineAlreadyResolvedException`, `QuarantineIncidentNotFoundException`,
`ServerAuthoredChainPlacementException` — so "it said two; there are three" is correct. The list is
now marked *"including"* and the reader is told to run the command rather than trust any list, which
is the right shape for a residue that will keep growing. Closed.

**N-5.** I reproduced the pint claim rather than accepting it. Wrote a probe file containing
`# event(new SomethingHappened());` and ran the lane's own pint:

```text
{"result":"fixed","fixers":["single_line_comment_style"]}
resulting line ->  // event(new SomethingHappened());
```

So a `#` comment genuinely **cannot survive** in this codebase, and correcting the sentence rather
than the fixture is the right call — adding the line back would have been silently undone by the
next `pint` run, re-creating the same false description. `grep -c '#'` on the fixture is `0`, as
stated. The recorded reason is true and now independently verified. Closed.

**N-7.** `git diff --numstat c533f005a..b4b6fef78` over `apps/api/app`, `apps/web`, `apps/pos`,
`packages`, `apps/api/database`, `apps/api/config`, `apps/api/routes` is **empty**. The five commits
after the anchor touch only `ci.yml`, the two ratchet tests, the two fixtures and docs/ledger. So the
verifier anchor holds, and the added warning — verifiers and ratchets are on different clocks,
*"re-run either ratchet at the BRANCH TIP, never at a milestone SHA"* — is both correct and the
useful form. Keeping `commit: c533f005a` as the implementation-slice name, with that stated
explicitly, is defensible. Closed.

**N-8.** `ZReportProjection.php:326` reads
`'pos_receipts rows for %d verified v%d+ SALE_RECEIPT event(s) on terminal %s '.` — the near-miss is
real and at the cited line. The harmlessness argument checks out: pattern 1 is
`/\bevent\s*\(\s*new\s+/` and requires `new`. The strip condition at
`ProjectorEmissionRatchetTest.php:374` is `T_COMMENT || T_DOC_COMMENT`, exactly as the docblock
describes. All `{@see}` targets in the new docblock resolve — `sourceWithoutComments()` at `:368`,
`FixtureProjectorWithCommentedEmission` present — so this round did not repeat the F-3 class of
describing something that does not exist. Recorded as a known limitation with the fix shape written
down, per the round-2 disposition ("optional") and the parent's instruction. Closed.

> **Carry-forward observation (not a finding, same N-8 family).** The recorded limitation describes
> the exposure as a string containing `event(new …)`. Pattern 2,
> `/\b[A-Za-z_][A-Za-z0-9_\\]*::dispatch(?:If|Unless)?\s*\(/`, does **not** require `new`, so a
> string literal containing `SomeClass::dispatch(` would trip the detector more easily than the text
> implies. This does not widen the live risk (no baselined POS projector carries such a string) and
> it falls inside the limitation already recorded; noting it so whoever implements the documented
> fix shape sizes it correctly.

---

## 3. New-defect hunt in the delta

Nothing found. The delta is one docblock and two documentation files. No production file, no
executable line, no fiscal fact, no schema, no queue, no money or quantity path. The docblock's
factual claims were each checked against the code they describe (`:368`, `:374`, the three patterns,
the fixture names, `ZReportProjection.php:326`) and all hold.

**Treasury lens: not applicable to this delta** — zero production files, nothing under
`apps/api/app/Modules/Treasury/**`. The M4-F-5 observation (`ServerAuthoredChainPlacementException
extends RuntimeException` reaching a customer money-in flow as an unmapped 500) is unchanged, still
recorded as a note, still owed to a boundary-mapping lane.

---

## 4. Disposition

All eight notes close, each verified by execution rather than by reading the claim. The two that
carried weight are closed in the strongest available form: N-1's stale ratchet count is gone and the
published table now matches a run I did myself on both drivers, and N-2's frozen-register disclosure
landed with a rationale — *rewriting a review register to be correct destroys the evidence it exists
to preserve* — that is more useful than the correction I asked for.

**On N-6, the executor was right and I was wrong.** I ran the probe on both ports, confirmed a
literal `timezone = 'Africa/Tunis'` in the 5432 `postgresql.conf`, confirmed both instances host
`autoerp_es_wave_a0_test`, proved the `source` discriminator against the UTC instance, and then
flipped only `DB_PORT` with `PGTZ` unset and watched the exact historical red (`253 assertions`, both
failures at `:778`) appear and disappear. The instance is the discriminator. My round-2 clause would
have put a false statement into the terminal exit evidence, and refusing to write it was correct.
The recorded carry-forward rule — pin the instance or `PGTZ=UTC`, and verify `pg_settings` before
trusting a red — is the right durable statement, and the shared-symlink `.env` makes it more
necessary than either round realised. Two refinements are recorded above (`reset_val` is not a
marker; `source` is), neither of which disturbs the ruling.

The wave's substance has now verified clean three times: the seven M5 findings closed and
independently re-executed at round 2, the eight documentation notes closed and re-executed here, both
ratchets 8/9 on both drivers at this tip, zero production files across the whole fix chain, the
verifier anchor intact by numstat, the ledger parsing with empty `blockers`. No new defect of any
family in this delta.

**ACCEPT.**

VERDICT: ACCEPT
