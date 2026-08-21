# DESIGN — F-2 / O-29: making the laneless Feature tests execute

**Date:** 2026-08-21 · **Branch:** `feat/f2-feature-lane-execution` · **LEDGER rows:** O-29 (ruling), S-14 (promotion protocol), S-17 (CI-blind window)
**Supersedes nothing.** Extends `docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md` §M2 — the census this design spends.

---

## 1. The problem, in one paragraph

The enforcement-P2 census found **1 329 `tests/Feature` classes in 74 top-level groups, of which only 326 were
reachable by any CI job on any event**. P2 landed the *visibility* half: `apps/api/tests/feature-lane-manifest.json`
gives every group an explicit disposition, enforces a per-group non-growth ceiling, and prints a counted
COVERAGE DEBT warning on every run. It deliberately did **not** land the *execution* half — that was reserved as
the F-2 owner decision. The owner ruled on 2026-08-21 (LEDGER O-29): **line the laneless groups up to execute**,
under a hard constraint — **the GitHub Actions monthly quota is exhausted** (LEDGER S-17), so the cost of the
ruling cannot be paid in hosted minutes.

Today's tree: **1 350 Feature classes**, **1 131 of them in 71 groups no lane runs as a whole**.

At the measured **6.24 s/class** (two independent P2 samples: a 40-class random sample at 6.18, `Accounting` at
6.29) that remainder is **~118 min of PHPUnit per triggering event on SQLite**, and materially more on
PostgreSQL. That is the number that makes this a runner-topology problem and not a YAML problem.

---

## 2. The lane partition

Eight lanes, one per module family, balanced on class count. **Family first, balance second** — a lane is also the
unit of triage ownership, so "the POS lane is red" has to mean something to a person.

| # | Lane (CI job id) | Groups | Classes | est. SQLite | est. PostgreSQL | Runner |
|---|---|---|---|---|---|---|
| 1 | `feature-lane-pos` | POS, Cart, Voucher, Loyalty, Coupon, Promotion, Progression, Pricing, SmartPrompts | **198** | 20.6 min | ~33 min | self-hosted |
| 2 | `feature-lane-inventory` | Inventory, BatchExpiry, Procurement, Replenishment, PurchaseHub, Uom, Location, Partner | **188** | 19.6 min | ~31 min | self-hosted |
| 3 | `feature-lane-tenancy` | Identity, Tenant, Company, CompanyConfig, Admin, SupportAccess, Permissions, Authorization, Subscription, Billing, Modules | **174** | 18.1 min | ~29 min | self-hosted |
| 4 | `feature-lane-documents` | Document, DocumentIngestion, Workshop, Service, Services, Scheduling, Contact | **162** | 16.8 min | ~27 min | self-hosted |
| 5 | `feature-lane-fiscal-finance` | Fiscal, Compliance, Taxation, Expense, Income | **157** | 16.3 min | ~26 min | self-hosted |
| 6 | `feature-lane-catalog` | Catalog, Product, Channel, Marketplace, Vehicle, Menu, ModelUpdates | **123** | 12.8 min | ~20 min | self-hosted |
| 7 | `feature-lane-data-console` | Console, Seeders, Import, Migration, Migrations, Schema, CountryDefaults | **96** | 10.0 min | ~16 min | self-hosted |
| 8 | `feature-lane-platform-misc` | PlatformIntegration, Api, Http, Architecture, Bootstrap, Broadcasting, Communication, Notification, Dashboard, EventSourcing, Events, Jobs, Performance, Precision, Shared, T2 | **32** | 3.3 min | ~5 min | self-hosted |
| — | *(unlaned residue)* | `(root files)` | **1** | — | — | — |

**Total laned: 1 130 of 1 131.** Sum of the lanes ≈ **118 min SQLite / ~3 h PostgreSQL** of PHPUnit, but the
lanes run **in parallel**, so the wall clock is the longest lane plus queueing (§4.3), not the sum.

**How the estimates were derived.** `classes × 6.24 s`, the P2 census figure, which is a SQLite in-memory number.
The PostgreSQL column applies a **×1.6** factor, chosen as a deliberately conservative reading of P2's own
observation that `Treasury` (119 classes) blew past a 10-minute cap on the fast path.

**First measurement (2026-08-21, `feature-lane-platform-misc` on the local docker PG, §5).** 32 classes,
16 groups, 175 tests, **441 s wall = 13.8 s/class**. That is above the ×1.6 estimate, but the lane is the
pathological case for overhead: 12 of its 16 groups hold a single class each, and each *group* pays a full
PHPUnit boot (≈8–10 s). Netting ~140 s of boot overhead gives **≈9.4 s/class of actual test time — ×1.5 of the
SQLite figure, which is the ×1.6 estimate confirmed.** The estimates for the seven bigger lanes (whose groups
average 15–20 classes and amortise the boot) stand; `platform-misc`'s own row should be read as ~7–8 min, not
5. Each lane's estimate is replaced by a measurement as it is first executed (`summary.tsv`).

**Why 8 and not 71 or 2.** One job per *group* would mean 71 jobs, 71 runner check-outs and 71 composer installs
— the per-job overhead (checkout + setup-php + composer ≈ 2–3 min, the figure P2 quoted for
`security-regression`) would exceed the test time. One job for everything would be a single ~3 h serial job with
no failure attribution. Eight is where per-job overhead (~20 min total) is small against test time and each lane
still names one owner-legible area.

**Why one STEP per group inside a job** (70 steps across 8 jobs): the manifest checker resolves a lane by
prefix-matching its `selector` against a live `run:` line and then refuses any tail it cannot prove neutral. A
matrix expression (`tests/Feature/${{ matrix.group }}`) resolves to nothing, and a multi-directory step
(`phpunit tests/Feature/A tests/Feature/B`) fails the neutral-tail rule. Both would mean a manifest certifying
coverage the checker cannot verify — the exact failure P2's N-3 finding closed. One step per group also gives
clean failure attribution and matches the "SERIAL EXECUTION IS LOAD-BEARING" rule already on
`treasury-spine-pgsql` (two pgsql `RefreshDatabase` suites racing the same schema throw fake 2BP01 errors).

**Trailing slashes are load-bearing.** Every new selector ends `tests/Feature/<Group>/`. Lane resolution is a
prefix match, so a slashless `tests/Feature/Service` also matches the `tests/Feature/Services` step (likewise
`Migration`/`Migrations`) and the checker correctly rejects the pair as ambiguous. Regression-proofed by
`test_it_fires_when_a_lane_selector_becomes_ambiguous`.

**The one group that cannot be laned.** `(root files)` — a `lane` disposition requires a whole-*directory*
selector ending in `tests/Feature/<Group>`, and loose files at the root of `tests/Feature` have no such
directory. The single class there is the Laravel scaffold `ExampleTest.php`. It stays `deferred` with a ceiling
of 1, so a *second* root-level file fails CI. `debt_ceiling` drops **1131 → 1**.

---

## 3. Database engine: PostgreSQL, not SQLite

The lanes set `DB_CONNECTION=pgsql` at job level and run against a PG 16 service container, exactly the way
`treasury-spine-pgsql` does (`phpunit.xml` pins `DB_CONNECTION=sqlite` via `<env>` *without* `force="true"`, so a
real environment variable wins). Reasons, in order:

1. **PG is what production is.** SQLite-green is the weaker claim, and the P2 census explicitly warns that its
   own SQLite directory runs are *not* the CI lanes.
2. **PG-only tests stop skipping.** Triggers, advisory locks, savepoints, partial unique indexes and
   CHECK-constraint tests `markTestSkipped` on SQLite — a lane that skips them is a lane that certifies nothing.
3. **Minutes are free on the target runner**, so the ×1.6 slowdown buys parity at no billed cost.

The cost is honestly stated: PG roughly doubles the estimate, and the first execution will surface PG-specific
failures in tests only ever exercised (if at all) on SQLite. That is precisely what §6 is for. The local harness
keeps a `--sqlite` flag for fast triage loops, but SQLite is never the lane's definition.

---

## 4. Runner topology

### 4.1 What goes where

| Class of job | Where | Why |
|---|---|---|
| Static gates: `backend-lint`, `backend-analyse`, `backend-architecture` (incl. the manifest checker + its liveness suite), `backend-dpa-guard`, `chokepoint-gate`, `route-manifest-drift`, `types-drift`, all frontend lint/typecheck/test/build | **hosted `ubuntu-latest`, unchanged** | Seconds-to-low-minutes each, they gate PR→dev, and they must keep working even if the self-hosted box is down. Moving them would trade a quota problem for an availability problem. |
| The 8 Feature lanes | **`runs-on: [self-hosted, linux, x64, autoerp-heavy]`** | ~118–190 min of PHPUnit per event. This is the only cost that actually broke the budget, and the only one worth an owned machine. |

**Zero-Actions-minutes rationale.** GitHub bills Actions minutes for GitHub-hosted runners only; jobs executed on
a self-hosted runner consume **no** minutes from the account's monthly allowance, whatever their duration. Moving
the ~2–3 h/event of Feature testing onto owned hardware therefore takes the entire recurring cost of the O-29
ruling to zero on the quota that is exhausted, while leaving the cheap gates where they already work. The trade
is that the owner now owns availability, patching and isolation of that machine — §4.2.

### 4.2 Runner provisioning spec (OWNER OPS)

**Sizing.** Hetzner CCX23 / CPX41-class or the existing AX42: **8 dedicated vCPU, 32 GB RAM, 100 GB NVMe, Ubuntu
24.04 LTS, Docker Engine installed.** Rationale: each concurrent lane needs ~1 core for PHPUnit (single-threaded)
plus a PG container; 4 concurrent lanes ≈ 8 cores and ≈ 12 GB with headroom. Disk is dominated by the composer
cache and PG data dirs, not the repo.

**Concurrency plan.** Register **4 runner instances** with the same labels. Eight lanes then execute in two waves
of four; wall clock ≈ 2 × the longest lane ≈ **60–70 min** per triggering event. With a single runner the same
work serializes to ~3 h — correct but slow, and acceptable as a starting point. Scale by adding runner instances,
never by matrixing a lane (§2).

**Ephemeral, container-based.** Use `--ephemeral` runners (each accepts exactly one job then exits) started from
a container image, e.g. `myoung34/github-runner` under systemd, or Actions Runner Controller if the owner
prefers Kubernetes. Ephemeral is not a nicety: a persistent self-hosted runner shares a filesystem between jobs,
so one job's `.env.testing`, vendor tree, or leftover PG database silently becomes the next job's starting state
— the class of defect that makes self-hosted CI results untrustworthy. Prerequisites for the workflow as
written: Docker (for `services:` containers), `git`, `curl`, `unzip`, `sudo` (for `shivammathur/setup-php`), and
outbound HTTPS.

**Service containers** (`postgres:16`, `redis:7-alpine`) are declared per lane job exactly as on
`treasury-spine-pgsql` and require Docker on the runner host. Each lane uses its own database name
(`autoerp_<lane>_test`), so two lanes sharing a host never share a schema.

**Security notes.**
* **Repo-scoped registration.** Register at the repository level, not the org level. A repo-scoped runner cannot
  be reached by workflows in other repositories.
* **No fork PRs. Ever.** This is a **private** repository, so there are no untrusted forks today — but that is a
  property of the current setting, not of the design, and flipping the repo to public would immediately expose
  the runner to arbitrary code execution from any PR author. State it explicitly in the runbook, and if the repo
  ever goes public, set *Require approval for all outside collaborators* and disable
  `pull_request_target`-style patterns before the runner is re-enabled.
* **Least privilege.** Run the runner as a non-login user, `GITHUB_TOKEN` permissions default to read; nothing in
  these lanes needs `contents: write`. The S-14 promotion protocol already rejects a candidate whose workflow
  diff grants `contents: write` (gate-r6 R6-H-5) — this design grants none.
* **No production secrets.** The lanes need `APP_KEY` (a throwaway literal, as today), a local PG password and
  Redis. No repository secret is referenced by any new job. Verify none is added later: a self-hosted runner is a
  worse place to hold a production secret than a hosted one, because the filesystem outlives the job.
* **Network.** The box needs outbound HTTPS to GitHub, packagist and ghcr; it needs **no inbound** ports (the
  runner polls). Firewall inbound to SSH-only, keys-only.

### 4.3 The skip guard — and why the obvious approach does not work

A job that declares `runs-on: [self-hosted, autoerp-heavy]` when **no runner with those labels is registered does
not skip. It queues.** GitHub holds the job waiting for a matching runner and only abandons it after the
workflow's 24-hour limit. Wiring the lanes without a guard would therefore leave every PR with eight permanently
pending required checks — strictly worse than the status quo.

The guard is therefore a **repository variable**, evaluated in the job `if:` — a condition GitHub resolves before
it ever looks for a runner:

```yaml
if: ${{ vars.SELF_HOSTED_RUNNER_READY == 'true' && (github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || (github.event_name == 'push' && github.ref == 'refs/heads/main')) }}
runs-on: [self-hosted, linux, x64, autoerp-heavy]
```

With the variable unset the expression is false, the job is **cleanly skipped** (neutral, not red, not queued),
and no runner is ever requested. Flipping one repository variable switches all eight lanes on at once — and off
again just as fast if the box misbehaves, which is the reversibility the S-14 protocol wants from any CI change.

The event arm deliberately mirrors `all-checks-pass` exactly (dispatch / PR→main / push→main). **PR→dev stays the
cheap gate** and gains nothing from this change; `runs_on_pr_dev: false` is recorded for every new lane and
verified by the checker against the real job `if:`.

**How the gate declaration is verified — corrected after gate-r1 R1-2.** The first version of this design
claimed the checker "verifies the declared gate against the real job `if:`", and the reviewer falsified it: the
verification was `str_contains($jobIf, $declared_gate)`, and **containment can only prove a fragment is present,
never that nothing was added around it.** The proven bypass was one token —

```yaml
if: ${{ vars.NEVER_FIRES == 'true' && vars.SELF_HOSTED_RUNNER_READY == 'true' && (…) }}
```

— which still *contains* the declared gate, so B4 passed, B4a counted the classes as merely "parked", and B5
tolerated the skip in the aggregate, while the lane could never execute no matter what the owner flipped. Two
independent rules replace it:

1. **`execution_gate` now carries the WHOLE canonical `if:` expression** and must **equal** the job's real `if:`
   after whitespace normalization. Equality cannot be satisfied by addition. A semantically-equivalent
   reordering false-blocks — the accepted trade, since a one-line manifest edit then states the intended
   expression explicitly, and this checker has no GitHub expression evaluator and must not pretend to.
2. **A lane job's `if:` may reference exactly ONE distinct `vars.*`** — enforced independently of the manifest,
   because rule 1 alone could be satisfied by editing both files. A second repository variable is a second
   switch nobody is tracking.

Both are pinned by liveness cases (`…gated_by_a_second_never_enabled_variable`, `…if_drifts_from_the_declared_gate`).

### 4.4 The aggregate had to change, and the change is a strengthening

`all-checks-pass` must list every lane job (the manifest checker enforces aggregate membership — a gate outside
the aggregate does not gate). But **GitHub skips every dependent of a skipped job**, so eight legitimately-skipped
lanes would skip the aggregate — the required check would never be fulfilled. The aggregate now:

* carries `if: always() && (<the original event condition>)`, and
* **evaluates its dependencies' results in a step** instead of leaning on the implicit rule.

This is stronger than what it replaced in a second, independent way: under the implicit rule a **failed**
dependency left `all-checks-pass` *skipped*, and a skipped required check is not a red one. Now a failed
dependency fails the aggregate out loud.

Skip tolerance is **enumerated, never wildcarded**: `ALLOW_SKIPPED_JOBS` names exactly the eight flag-gated lane
jobs, any other skipped dependency is a hard failure, and the manifest checker asserts **set equality** between
that list and the set of lanes declaring an `execution_gate`. Adding a name there to quiet a red job fails the
checker (`test_it_fires_when_the_aggregate_tolerates_a_skip_it_should_not`).

**And the script must not fail open — corrected after gate-r1 R1-1.** The first version evaluated results in a
`while … done < <(jq …)` loop with `status=0` initialised up front. A process substitution's exit status is
unobservable, so **an absent or malformed `needs` context produced zero iterations and a green
"All CI checks have passed!"** — the reviewer reproduced it. The step now (a) parses with the exit code
observed and refuses anything that is not a JSON object, (b) refuses a context that parses to zero jobs, and
(c) requires the parsed key set to **equal `EXPECTED_JOBS`**, so a truncated or partial context cannot pass by
simply not mentioning a failing job. `EXPECTED_JOBS` is itself pinned to the job's own `needs:` list by the
checker (B5a) so it cannot silently go stale. Eight liveness cases now **execute the real script** lifted out of
the workflow — happy path, allowed skips, malformed JSON, empty `{}`, absent context, missing job, missing
`result` key, failed/non-allowlisted-skipped dependency.

---

## 5. Interim execution: the local harness

Until the runner exists, **`scripts/run-feature-lane-local.sh <lane>` is the execution path.** It reads the lane's
group list from the manifest — never a duplicated list in the script, which is how a local run and a CI lane
drift into meaning different things — and runs the same groups, by path, in the same order, against the
docker-compose PostgreSQL on **127.0.0.1:5433** with `AUTOERP_QUARANTINE=1`, exactly as the CI job will.

```
scripts/run-feature-lane-local.sh --list
scripts/run-feature-lane-local.sh feature-lane-platform-misc
scripts/run-feature-lane-local.sh feature-lane-pos --group POS
scripts/run-feature-lane-local.sh feature-lane-catalog --sqlite     # fast triage loop
```

It writes `docs/sessions/feature-lanes/<lane>-<timestamp>/` with one log per group plus `summary.tsv`
(group, classes, result, seconds, tests, failures, errors, skipped) — which is the raw material for the §6
baseline.

**Destructive-run guards — added after gate-r1 R1-3/R1-4.** These are `RefreshDatabase` suites: they **drop
every table in whatever database they are pointed at.** The first version defaulted each connection variable
from the caller's environment (`${DB_HOST:-127.0.0.1}`), so a shell that had exported `DB_*` for the dev or a
staging database silently redirected the entire lane onto it — and `--sqlite` did not save you, because
`phpunit.xml` pins `DB_CONNECTION=sqlite` via `<env>` *without* `force="true"`, so an inherited
`DB_CONNECTION=pgsql` still won. Now:

* **The ambient environment is not trusted at all.** Every `DB_*`/`REDIS_*` variable is `unset` and rebuilt in
  the script. Deliberate overrides use a `LANE_*` namespace that no application tooling exports.
* **Loopback only.** A non-loopback `LANE_DB_HOST` is refused, not "defaulted away".
* **Throwaway database names only.** The database must match `autoerp_*test`; `autoerp`, `autoerp_dev` and every
  `tenant_<uuid>` fail by construction.
* **`--sqlite` forces** `DB_CONNECTION=sqlite` and `:memory:` rather than relying on absence.
* **`--docker-db-limits` refuses the shared `autoerp_postgres` outright** (it used to clamp the container every
  other project on the machine shares, and never restore it). For a dedicated container passed via
  `LANE_PG_CONTAINER`, the prior `NanoCpus`/`Memory` are recorded and restored from a `trap … EXIT INT TERM`.

Proven by executed probes (2026-08-21): hostile `DB_HOST=10.9.9.9 DB_DATABASE=autoerp` in the ambient
environment → the run still reports `postgres 127.0.0.1:5433/autoerp_lane_test` and passes;
`LANE_DB_HOST=10.9.9.9` → refused; `LANE_DB_DATABASE=autoerp` → refused; `--docker-db-limits` on the shared
container → refused; `--sqlite` under `DB_CONNECTION=pgsql` → `sqlite :memory: (forced)`, 5 s instead of 44 s.

**Resource envelope, and the honest account of it.** PHPUnit runs on the host PHP under `nice -n 19`, one process
at a time, with a per-group wall-clock alarm (`perl -e alarm`, since macOS ships no `timeout(1)`); memory is
capped by `phpunit.xml`'s own `<ini name="memory_limit" value="2G">`. It never runs the whole suite — there is no
flag that reaches it (MEMORY: `feedback_no_full_test_suite`, "never run the full PHPUnit suite without
permission — crashes the laptop"), and it never runs two PHPUnit processes at once.

**Why PHPUnit itself is not containerized here** (the brief allows this if justified):

* `apps/api/Dockerfile` is a **production** image whose composer stage installs `--no-dev` — the image contains
  no `phpunit` at all. Containerizing would mean a new test stage plus a second Linux-native vendor tree,
  rebuilt on every `composer.lock` change, for minutes per run.
* The components that benefit from containment already are: PostgreSQL and Redis run in the compose stack inside
  the Docker Desktop VM with its own CPU/RAM allocation. `--docker-db-limits` clamps that container to 2 CPU /
  2 GB for the run when PG is the noisy neighbour.
* What actually needs bounding on a laptop is PHPUnit's appetite, and PHPUnit is single-threaded: **one process
  is a hard one-core ceiling.** `nice -n 19` puts it behind every interactive process; the alarm stops a hung
  test sitting on the machine. That is a real envelope, not a promise.
* On the self-hosted runner the same lane **is** fully containerized (ephemeral runner + service containers), so
  the container property exists where it is load-bearing.

---

## 6. First-execution triage posture

**The premise: these lanes will be red on first execution, and that is not a reason to postpone them.** ~1 130
classes that no lane has ever run, moved onto PostgreSQL, will surface pre-existing failures in bulk (the P2
census already observed `Fiscal` at 31 errors + 14 failures and `Accounting` at 5 + 1 on a local SQLite run).
The repo already carries inherited reds of exactly this shape — PG-only 51, Treasury Spine 78 — waived at
promotion with recorded evidence. A red wall that blocks every merge is not a gate; it is a gate everyone turns
off.

So each lane goes live through a **baseline**, mirroring the repo's ratchet idiom:

**Step 1 — measure.** Run the lane locally (§5). `summary.tsv` plus the per-group logs are the inventory.

**Step 2 — classify, per failing class.** Exactly two outcomes, no third:
* **FIX** — the failure is real, small, or in code this session owns. Fix it. Preferred, always.
* **QUARANTINE WITH A TICKET** — the failure is pre-existing, unrelated and not this lane's job to fix. Add one
  entry to `apps/api/tests/quarantine.json` naming the exact class (`Tests\Feature\X\FooTest`) or the exact
  method (`…FooTest::test_bar`), with the `lane`, a `reason`, and the `opened` date — and raise `ceiling` to the
  new count **in the same commit**.

**Step 3 — go green, then flip.** A lane is eligible for the execution gate when it is green *with* its
quarantine applied.

**The mechanism, and why it is a ratchet and not a waiver.**

| Property | How it is enforced |
|---|---|
| **Off by default** | Nothing is skipped unless `AUTOERP_QUARANTINE=1`, which only the eight lane jobs and the local harness set. Every other suite reads the file never. |
| **Exact targets only** | An entry is one fully-qualified class, or a class plus one method. No globs, directories or regexes — a quarantine cannot widen by accident, and a NEW failure in a quarantined class's *other* methods still fails the lane. |
| **Shrink-only** | `ceiling` is a non-growth ceiling on the entry count, enforced by `tools/feature-lane-manifest-check.php` (which runs ungated in `backend-architecture`). Growing it is a visible, deliberate edit; `test_it_fires_when_the_quarantine_grows` proves the guard fires. |
| **Cannot rot into fiction** | Every entry must carry a declared `lane`, a `reason` and an `opened` date. **The target is resolved for real** (gate-r1 R1-5): the class through the autoloader and, for a `::method` entry, by `method_exists` reflection — file existence is not identity, and `…FooTest::test_renamed_last_month` used to pass validation while skipping nothing and occupying a ceiling slot forever. The declared `lane` must also be the lane that actually runs the target's group, or the entry is debt filed where its owner never sees it. |
| **Visible in the run** | A quarantined test appears as a PHPUnit **skip** in the lane's own output, and the checker prints a counted QUARANTINED line on every CI run. |
| **Deleting the file is safe** | No file means nothing is skipped — strictly safer — so absence is not an error. There is no way to *widen* the quarantine by deleting something. |

**Why the skip lives in `Tests\TestCase::setUp()` and not in a PHPUnit flag.** The manifest checker rejects a
whole-directory lane whose run line carries anything it cannot prove neutral — `--filter`, `--exclude-group`,
`-c`, a narrower path — because every one of those can silently empty a lane while the manifest still certifies
the directory (P2's R-3 finding, worth up to 215 classes). Adding `--exclude-group` to the neutral allowlist
would reopen exactly that hole. So the lane step stays *exactly* `./vendor/bin/phpunit tests/Feature/<Group>/`,
and quarantining happens **inside** the run, where it is visible as a skip and countable in a ratcheted file,
instead of as an invisible argument in YAML.

**Shrink obligations.** Each quarantine entry is debt with a name on it. The lane owner reviews their entries at
each milestone; the ceiling only ever comes down. Today's file ships **empty, ceiling 0** — the baselines are
produced lane-by-lane as each is first executed.

**Mechanism proven, not asserted** (2026-08-21, `tests/Feature/EventSourcing`, entry added and reverted):

```
# no env var — the quarantine file is inert
$ ./vendor/bin/phpunit tests/Feature/EventSourcing/
ERRORS!  Tests: 7, Assertions: 3, Errors: 4.

# same tree, same file, AUTOERP_QUARANTINE=1
$ AUTOERP_QUARANTINE=1 ./vendor/bin/phpunit tests/Feature/EventSourcing/
SSSSSSS   OK, but some tests were skipped!  Tests: 7, Skipped: 7.

$ php tools/feature-lane-manifest-check.php
  ⚠ QUARANTINED: 1 test target(s) are skipped when AUTOERP_QUARANTINE=1 …
```

### 6.1 Worked example — the `platform-misc` first-execution baseline

The smallest lane was executed locally to prove the whole path (§5). **13 of 16 groups green on the first try;
three red, 13 errors, zero failures** — and every one of the three is a genuine, pre-existing PostgreSQL-vs-SQLite
defect that no CI job could ever have caught, which is the argument for this whole exercise in one screen:

| Group | Errors | Root cause | Disposition under §6 |
|---|---|---|---|
| `PlatformIntegration` | 3 | The tests introspect **`sqlite_master`** directly (`select sql from sqlite_master …`). They are SQLite-only by construction and error out on PG. | **FIX** — make the introspection driver-agnostic, or `markTestSkipped` on non-sqlite the way the PG-only tests skip on sqlite. Not a quarantine: the test is wrong, not the code. |
| `EventSourcing` | 4 | A document id is passed as the literal `'inv-123'`; PostgreSQL rejects it (`invalid input syntax for type uuid`) where SQLite silently accepted it. This is the exact pitfall MEMORY records as *"UUID cols in PG: validate before `where('uuid', $val)`"*. | **FIX** — use a real UUID in the fixture. |
| `Events` | 6 | `RefreshDatabase`'s teardown drops ~300 tables in one transaction and PG answers `SQLSTATE[53200] out of shared memory … increase max_locks_per_transaction`. | **INFRASTRUCTURE, not a test defect** — see Q5. Re-measure on a clean PG before deciding; quarantine only if it survives that. |

Note what this table is *not*: a red wall. Three named, understood, individually-dispositioned problems out of a
lane of 32 classes, produced in eight minutes by a script anyone can re-run. That is the shape every lane's
phase-1 baseline should take.

---

## 7. Rollout order, and the S-14 / S-17 interaction

### 7.1 Order

| Phase | What | Gate to the next phase |
|---|---|---|
| **0 — landed by this branch** | Manifest lanes + 8 parked CI jobs + aggregate rework + checker extensions + local harness + quarantine ratchet. **Zero execution, zero minutes, zero behaviour change on any event.** | Manifest checker green; liveness suite green; smallest lane proven locally. |
| **1 — local baselines** | Run each lane locally, cheapest first (`platform-misc` → `data-console` → `catalog` → `fiscal-finance` → `documents` → `tenancy` → `inventory` → `pos`). Fix or quarantine per §6. One commit per lane. | Every lane green locally with its quarantine applied. |
| **2 — owner ops** | Provision the VPS, register 4 ephemeral repo-scoped runners with `self-hosted,linux,x64,autoerp-heavy` (§4.2). | A `workflow_dispatch` run with the variable still **off** shows the eight lanes cleanly skipped and `all-checks-pass` green. |
| **3 — flip** | Set repository variable `SELF_HOSTED_RUNNER_READY=true`. | A dispatch run shows all eight lanes executing and green. |
| **4 — tighten** | With the gate flipped, the per-group ceilings become informational and `gated_ceiling` is removed from the manifest; new Feature classes are picked up by their lane automatically — the property this whole exercise was for. | — |

Phase 1 can proceed today; phases 2–4 are owner ops and are listed as such in §8.

### 7.2 S-14 and S-17

`.github/workflows/ci.yml` is a **workflow-touching** surface, so **S-14's dispatch-verification leg applies to
whatever promotion carries this branch**: the promotion sequence requires a `workflow_dispatch` run at exactly
the accepted SHA, verified green with the new jobs/steps observed, before the fast-forward. **That leg cannot be
performed while the quota is out** — which is precisely the CI-blind window S-17 records.

The consequence, stated plainly so nobody discovers it at promotion time:

* Everything in this branch is **locally verified only** — the manifest checker, its 52-case liveness suite, a
  YAML parse of `ci.yml`, and a real local lane execution. **CI cannot verify itself right now.** `actionlint` is
  not installed on this host; the workflow is validated by the same Symfony YAML parse the checker performs (it
  fails closed on an unparseable `ci.yml`) plus the checker's own job/step/`needs`/`if:` graph assertions, which
  read the parsed YAML rather than the file text. That is *syntax and graph shape*, not GitHub's evaluation of
  it — S-14 says so explicitly and this design does not claim otherwise.
* This work is therefore **CI-UNVERIFIED** under the S-17 regime and must be recorded as such at merge.
* **Before promotion to `origin/dev` after quota or the runner returns**, the S-14 dispatch leg is owed on the
  promotion candidate: run `workflow_dispatch`, confirm head == the promoted SHA, confirm the eight lanes are
  present in the run graph and **skipped** (variable still off), and confirm `all-checks-pass` is **green with
  the skips tolerated** — that last check is the one that proves §4.4 works, and it costs a single dispatch.
* Flipping `SELF_HOSTED_RUNNER_READY` (phase 3) is itself a CI-contract change and gets its own dispatch
  verification; it is trivially reversible by unsetting the variable.

**No enforcement pin tag, close tag or ratchet variable is touched by this branch.**

---

## 8. Open questions / owner actions

**OWNER OPS — nothing below can be done by an agent.**

1. **Provision the runner host.** Hetzner CCX23/CPX41-class or AX42: 8 dedicated vCPU, 32 GB RAM, 100 GB NVMe,
   Ubuntu 24.04, Docker Engine, outbound HTTPS, inbound SSH-only. (§4.2)
2. **Register 4 ephemeral, repository-scoped runners** with labels `self-hosted,linux,x64,autoerp-heavy`
   (`--ephemeral`, one job per process, container-based). Confirm they appear under *Settings → Actions →
   Runners* for **this repository**, not the org. (§4.2)
3. **Confirm the fork policy in writing.** The repo is private today; if it is ever made public the runner must be
   disabled or fork-PR execution blocked first. (§4.2)
4. **Set the repository variable** `SELF_HOSTED_RUNNER_READY=true` (*Settings → Secrets and variables → Actions →
   Variables*) — the single switch that turns all eight lanes on. Leave it unset until phase 2's dispatch shows a
   clean skip. (§4.3, §7.1)
5. **Discharge S-14's dispatch leg** on whatever promotion carries this branch, once quota returns. (§7.2)

**OPEN QUESTIONS**

* **Q1 — concurrency budget.** 4 concurrent runners (≈60–70 min wall per event) or 1 (≈3 h)? Affects VPS sizing
  only; the workflow is identical either way.
* **Q2 — should the lanes also run on PR→dev once the runner exists?** They cost no minutes there either, but
  they cost *wall clock* on the day-to-day merge gate. Recommendation: **no** at first; revisit after one week of
  measured PR→main runs. Changing it is a one-line edit to the event arm plus a `runs_on_pr_dev` flip in the
  manifest.
* **Q3 — nightly instead of per-event?** A `schedule:` trigger would catch drift at zero PR latency, at the cost
  of a slower feedback loop. Not designed in; it is additive and revisitable.
* **Q4 — `(root files)`.** Delete `tests/Feature/ExampleTest.php` (the Laravel scaffold) and `debt_ceiling` goes
  to 0, closing the census completely. Not done here: deleting a test is outside this lane's scope.
* **Q5 — `max_locks_per_transaction` on the lane's PostgreSQL (surfaced by the first execution, §6.1).**
  `RefreshDatabase` teardown drops ~300 tables in one transaction; the local docker PG answered
  `SQLSTATE[53200] out of shared memory … you might need to increase max_locks_per_transaction`. Lock slots are
  an **instance-wide** shared-memory resource, and the local `autoerp_postgres` also hosts every other dev
  database, so this may be a local-stack artifact rather than a property of the lane — **it must be re-measured
  on a clean PG before anything is quarantined for it.** If it reproduces, the remedy is server config
  (`max_locks_per_transaction = 1024`), and that is awkward inside a GitHub `services:` block: the setting is
  postmaster-context, so it needs `-c` command arguments at container start, which `services:` cannot supply.
  Two workable options, for the owner to pick at phase 2: **(a)** pin the lane's `services.postgres.image` to a
  small custom image built `FROM postgres:16` with the setting baked into `postgresql.conf` (one Dockerfile, one
  registry push, no workflow complexity); or **(b)** drop `services:` on the runner and start PG from a compose
  file in a job step, which buys full config control at the cost of diverging from every other PG job in this
  workflow. **(a)** is recommended.

---

## 9. What landed on this branch

| File | Change |
|---|---|
| `apps/api/tests/feature-lane-manifest.json` | 70 groups moved `deferred` → `lane`; 70 new lane entries across 8 jobs; `debt_ceiling` 1131 → **1**; new `gated_ceiling` **1130**; new per-lane `execution_gate`. |
| `apps/api/tools/feature-lane-manifest-check.php` | **Extended, never relaxed.** B4 flag-gated-lane declaration + canonical-equality verification against the real job `if:` and a one-`vars.*` rule (gate-r1 R1-2); B4a parked-lane per-group ceilings (identical strictness to `deferred`) + global `gated_ceiling`; B5 aggregate skip-tolerance set-equality; B5a `EXPECTED_JOBS` pinned to `needs:` (gate-r1 R1-1); E quarantine ratchet validation incl. autoloader/reflection resolution and lane ownership (gate-r1 R1-5); two new loud report lines. |
| `.github/workflows/ci.yml` | 8 new self-hosted, flag-gated lane jobs (70 lane steps); `all-checks-pass` reworked to `always()` + explicit per-dependency result evaluation with an enumerated skip-tolerance list. |
| `apps/api/tests/quarantine.json` | New. Empty, ceiling 0. |
| `apps/api/tests/Support/QuarantinedTests.php` | New. Reads the ratchet; no-op unless `AUTOERP_QUARANTINE=1`. |
| `apps/api/tests/TestCase.php` | Three-line hook in `setUp()`, before `parent::setUp()`. |
| `apps/api/tests/Architecture/FeatureLaneManifestCheckerTest.php` | 19 new liveness cases (6 round 0 + 13 gate-r1, incl. 8 that EXECUTE the aggregate's real shell script); 1 existing case de-brittled (its fixture group was laned by this change). |
| `scripts/run-feature-lane-local.sh` | New. The interim execution path. |
| `.github/actionlint.yaml` | New. Declares the custom runner label so `actionlint` stops reporting it as unknown. |

**Local verification actually run** (S-17: this is all there is until quota returns):

```
$ php tools/feature-lane-manifest-check.php                       EXIT=0
  tests/Feature lane manifest OK — 1350 Feature classes in 74 groups; …
  ⚠ PARKED BEHIND AN EXECUTION GATE: 70 group(s) / 1130 class(es) …
  ⚠ COVERAGE DEBT: 1 group(s) / 1 class(es) …

$ ./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php
  OK (65 tests, 389 assertions)              # 46 pre-existing + 6 (round 0) + 13 (gate-r1)

$ actionlint .github/workflows/ci.yml
  10 findings — all SC2086 `>> $GITHUB_OUTPUT`, all pre-existing (base ci.yml: 10). ZERO new.

$ scripts/run-feature-lane-local.sh feature-lane-platform-misc
  16 groups, 32 classes, 175 tests, 441 s — 13 PASS / 3 FAIL (§6.1)
```

### 9.1 gate-r1 fix round (Codex adversarial gate, 2026-08-21)

Five findings, all confirmed with executed probes, all accepted without pushback. Each is now pinned by a
liveness case that fails on the pre-fix code.

| # | Sev | Defect | Fix | Pinned by |
|---|---|---|---|---|
| R1-1 | P1 | `all-checks-pass` **failed open**: jq in an unchecked process substitution + `status=0` init → malformed `needs` JSON exited 0, green. | Parse with the exit code observed; refuse a non-object, a zero-job context, or a key set ≠ `EXPECTED_JOBS`; checker pins `EXPECTED_JOBS` to `needs:` (B5a). | 8 cases that execute the real script (§4.4) + `…expected_jobs_drifts…` |
| R1-2 | P1 | B4 reduced `if:` semantics to substring containment → a lane gated by an **additional never-enabled** variable passed every check while never running. | Canonical whitespace-normalized **equality**, plus exactly-one-`vars.*` enforced independently. | `…second_never_enabled_variable`, `…if_drifts_from_the_declared_gate` |
| R1-3 | P1 | Harness could run `RefreshDatabase` against an **arbitrary inherited database**; `--sqlite` did not unset an inherited `DB_CONNECTION=pgsql`. | Ambient `DB_*`/`REDIS_*` unset and rebuilt; `LANE_*` override namespace; loopback-only; `autoerp_*test`-only; `--sqlite` forces. | executed probes (§5) |
| R1-4 | P2 | `--docker-db-limits` clamped the **shared** `autoerp_postgres` with no restore. | Refuse the shared container; record + `trap`-restore prior limits for a dedicated one. | executed probe (§5) |
| R1-5 | P2 | Method-level quarantine entries **rotted silently** — existence checked by file only. | Resolve FQCN via autoloader + `method_exists` reflection; require the declared lane to own the target's group. | `…nonexistent_method`, `…wrong_lane` |

Verified clean by the same gate and deliberately not re-touched: aggregate rejection of
failed/cancelled/non-allowlisted-skipped with valid JSON · `vars.` gate skip semantics vs GitHub docs ·
`AUTOERP_QUARANTINE` isolation to the eight new jobs · census math · no `permissions` grants · actionlint zero
new.

**No existing gate, ceiling, ratchet, pin tag or repository variable was weakened or removed.**
