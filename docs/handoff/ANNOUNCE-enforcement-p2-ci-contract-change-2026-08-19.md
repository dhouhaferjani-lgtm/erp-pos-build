# P2 merge-announcement checklist — INPUT to the parent's final announcement

> **This file is not the announcement.** It is the M3 deliverable the parent builds the final
> announcement from, sent after M3/M4 acceptance and **before** promotion; `merge_announcement_ack`
> records the sent fact in the post-promotion admin commit (gate-r2 R2-H-1, gate-r3 R3-C-2). The
> executor never sends it.
>
> Lane impact below is **measured**, and the method is stated so you can falsify it — see §10 for
> the full inclusion rule. **All measurements in this file are stamped `dev` = `3d66be352`** and go
> stale as `dev` moves — re-derive before acting. In short: **all local branches** (no prefix filter), **minus those
> already merged into `dev`**, impact counted as **added** files against **`dev`**
> (`git diff --diff-filter=A --name-only dev...<branch>`).
>
> *Three earlier methods were retracted during review and must not be re-used **for Feature
> classes**: `--name-only` without `--diff-filter=A` (counts changed files, not added classes),
> `<p2-base>...` (47 commits stale), and enumerating `.worktrees/*` or `codex/*` (both
> incomplete). **Locale impact is the exception and is counted as CHANGED files on purpose** —
> see §3.*

---

## 1. ⚠️ READ THIS FIRST — **eight** in-flight lanes will go RED without a one-line edit

P2 adds a **non-growth ceiling** per `tests/Feature` group that no CI lane runs. Adding a Feature test
to such a group now **hard-fails** `backend-architecture`. This is the intended behaviour — the
laneless hole must not grow silently — and the remedy is one number, in the same commit.

> **Dropped from this table at the 2026-08-21 stale-A rebase, by this file's own §10 inclusion
> rule:** `codex/dn-consolidation-2026-08-12` (+9 `Document`) and `codex/es-wave-a0` (+6 `Fiscal`)
> are now ancestors of the base — their classes are in the shipped manifest's regenerated ceilings
> (74 / 79). **Executing their old rows would create 15 units of permanent, unreported slack.**

| Lane | groups it **adds classes to** | ceiling now | what you must do |
|---|---|---|---|
| `codex/openapi-contract-a-to-z` | **new group `OpenApi` +6** | — | **§1a — disposition the group**, then raise `debt_ceiling` by 6 if deferred |
| `l6-integration-verify` | `Http` **+2**, `Modules` **+4**, `Tenant` **+2** | 2 / 53 / 29 | raise all three **and** `debt_ceiling` by 8 |
| `fix/r2d-bcmath-hardening` | `Modules` **+2** | 53 | raise `Modules` **and** `debt_ceiling` by 2 |
| `feat/dpa-v8-supplier-goods-return` | `Inventory` **+2** | 105 | raise `Inventory` **and** `debt_ceiling` by 2 |
| `feat/r2f4-correcting-documents` | `Accounting` **+2** | — | **nothing** — `Accounting` is laned, no ceiling |
| `feat/scan-vat-configuration` | `Taxation` **+1** | 31 | raise `Taxation` **and** `debt_ceiling` by 1 |
| `feat/supplier-invoice-ocr` | `Inventory` **+1** | 105 | raise `Inventory` **and** `debt_ceiling` by 1 |
| `fix/r2f2-cancel-flow-prompt` | `Document` **+1** | 65 | raise `Document` **and** `debt_ceiling` by 1 |
| `feat/owner-dashboard-demo` | `Seeders` **+1** | 26 | raise `Seeders` **and** `debt_ceiling` by 1 |

> **Two different debt numbers appear in this package — they measure different things.** The gate
> prints `⚠ COVERAGE DEBT: 71 group(s) / 1131 class(es)` = every class in a group **no lane runs as
> a whole** (and `debt_ceiling: 1131` matches it — re-derived at the accepted tip after the
> stale-A rebase; the pre-rebase figure was 1114). The `ci.yml` census comment says **990** =
> classes reachable by **no CI job at all**. The full decomposition, all four figures re-derived:
>
> | measure | files | distinct names |
> |---|---|---|
> | all of `tests/Feature` | 1348 | 1335 |
> | in groups no lane runs as a whole (**what `debt_ceiling` enforces**) | **1131** | 1119 |
> | minus those individually named in the two `--filter` allowlists (111 of the 124 entries — the
> other 13 were never in the 1131 — 12 sit in **laned** `tests/Feature` groups and 1 (`VoucherLedgerTest`) has no `tests/Feature` class at all, resolving to `tests/Unit/Voucher/Domain/`, which the lane's `php artisan test -c phpunit-pgsql.xml` does run) | 1020 | **1008** (the checker-header/ci.yml census stamp of 990 is a base-time figure; the widening gap is classes added since) |
>
> *An earlier version said "subtracts the ~124", which lands on 990 only because a files-vs-distinct
> error and an over-subtraction nearly cancel.* **1131 is the number the ceilings enforce and the
> number to act on.**
>
> **The "ceiling now" column shows P2-base values.** The candidate itself now carries the ceilings
> regenerated against the merged tree (2026-08-21 stale-A rebase: `Inventory` 106, `CountryDefaults`
> 28, `Document` 74, `Fiscal` 79, `debt_ceiling` 1131 — §8 item 0), so at merge time the shipped
> manifest already matches `dev`. If your lane lands after further `dev` movement,
> **raise the value you find, not the value printed here** — the actions are relative for that reason.
>
> **Counted as ADDED classes (`git diff --diff-filter=A dev...<branch>`), not changed files.** An
> earlier version counted changed files and told `dn-consolidation` to raise `Partner` and `es-wave-a0`
> to raise `POS` — groups neither lane adds a class to. **Do not raise a ceiling you do not need:** the
> checker only fails on `count > ceiling`, so an over-raised ceiling is permanent, unreported slack —
> exactly what §8 item 0 and the round-8 "no slack" verification exist to prevent.

The failure message names the group and both numbers:

```
✗ COVERAGE DEBT GREW: group "Document" now holds 66 class(es), ceiling is 65.
  A group that no CI lane runs may shrink, never grow — put the new class in a lane,
  or get the lane funded (brief §6 F-2). Lowering the ceiling to match a real deletion is fine.
```

### 1a. ⚠️ The OpenAPI lane adds a NEW group — it hard-fails until dispositioned

**Adding a brand-new `tests/Feature/<Dir>/` fails until it is dispositioned** in the manifest (`lane`,
`excluded` + reason, or `deferred` + reason).

**`codex/openapi-contract-a-to-z` does exactly this.** It carries
`apps/api/tests/Feature/OpenApi/` with **6 classes** (`DocumentResponseContractTest`,
`FeasibilityInventoryGenerationTest`, `PilotGenerationTest`, `PilotVerificationTest`,
`RouteCoverageCleanCheckoutTest`, `RouteCoverageVerificationTest`), absent from P2's base. Whenever that lane rebases onto a landed P2,
`backend-architecture` will fail with:

```
✗ UNASSIGNED GROUP "OpenApi" (6 class(es), e.g. OpenApi/DocumentResponseContractTest.php).
  Every tests/Feature group must name a CI lane, or be excluded/deferred with a reason…
```

**What that lane must do, in the same commit as its rebase:** add an `OpenApi` entry to
`apps/api/tests/feature-lane-manifest.json` — either `"lane": "<its own lane id>"` if
`backend-openapi-contract` runs the whole directory (then also add the lane block with `selector`,
`job`, `runs_on_pr_dev`), or `{"deferred": true, "classes": 6, "reason": "…"}` — **and raise
`debt_ceiling` by 6 if deferred.**

> **An earlier version of this file said "No lane currently does this — verified across all
> worktrees."** That was **false**, and the reason it was false is the point: I enumerated
> `.worktrees/*` and the OpenAPI lane has no worktree. Corrected by enumerating **branches**
> (`git branch --format='%(refname:short)'`), which is the only complete roster.

---

## 2. What changes in CI

**Six** new steps in existing jobs (3 in `backend-architecture`, 3 in `frontend-lint`) and **one new job**. `frontend-lint` and `backend-architecture` have **no `if:` guard**, so
their steps run on **every event that starts the workflow** — PR→`main`, PR→`dev`, push→`main`,
`workflow_dispatch`. (A direct push to `dev` still starts nothing. Unchanged.)

| # | Job | Step / job | What fails you |
|---|---|---|---|
| 1 | `frontend-lint` | Fetch the pinned i18n baseline revision | tag `ci-pin/enforcement-p2-r1` must exist on the remote (owner-created at promotion — nothing for a lane to do) |
| 2 | `frontend-lint` | i18n completeness gate | a **new** missing `fr` key, a new CLDR plural gap, or any growth of `apps/web/tools/i18n-completeness-baseline.json` |
| 3 | `frontend-lint` | Detector liveness suites | `pnpm test:eslint-rules` (6 suites, was 3) or `pnpm test:tools` (8 files) going red — **both previously ran in no workflow**, so a rule test your branch broke has been failing silently |
| 4 | `backend-architecture` | Check tests/Feature CI-lane manifest | §1 above, plus: a lane whose selector is missing/narrowed/soft-failed, a job or step gated or softened, the workflow no longer starting on PR→dev, or the checker's own steps disabled |
| 5 | `backend-architecture` | Feature-lane checker liveness test | the checker's own **46-case** suite |
| 6 | `backend-architecture` | Event ratchets (orphaned events + projector emission — PR→dev lane) | a dispatched event with zero registered listeners beyond the 75 baselined BY NAME, or a registered `FiscalEventProjector` that writes a POS projection and emits no domain event beyond the 6 skip-listed — the es-A0 F-1 fold-in ticketed to this package (added at the 2026-08-21 stale-A rebase; the same two tests also run in `backend-test`, which PR→dev skips) |
| 7 | **`security-regression` (NEW JOB)** | Security regression suite | `tests/Feature/Security` (17 classes) now runs on **PR→dev**; it previously ran only on PR→main |

**Branch protection: no action.** Steps 1–6 sit inside jobs already in `all-checks-pass` `needs`. The
one new job, `security-regression`, was **added** to that list — the aggregate is main-only, so no
`dev` protection rule changes.

---

## 3. The three behaviour changes that will surprise people

**(a) Adding an English string now requires French — and Arabic in 33 of 55 namespaces.**

| ar namespace class | count | new English key there → |
|---|---|---|
| `own` or `english-spread` (Arabic is wired in) | 33 | **CI FAILS** until `ar` authors it |
| `en-aliased` (wired to the English bundle) | 22 | **passes** — one whole-namespace `aliased` baseline entry, not per-key |

`pos`, `sales`, `inventory`, `settings`, `finance`, `treasury`, `compliance`, `notifications`,
`locations`, `products`, `expenses`, `import` are all in the **33**.

**Lanes touching `apps/web/src/locales` — SEVEN, not two.** *Counted as **changed** files
(`git diff --name-only dev...<branch>`), deliberately: adding a translation key **modifies** an
existing bundle, so `--diff-filter=A` returns **0 for every lane** and would say nobody is affected.
The added-file rule in §10 applies to **Feature classes**, where a new test is a new file. Two
measures, two questions.*
`codex/ui-wave0-2026-08-11` (16), `codex/dn-consolidation-2026-08-12` (4),
`fix/r2f2-cancel-flow-prompt` (3), `feat/scan-vat-configuration` (3), `feat/rafiq-skin-experiment` (3),
`l6-integration-verify` (2), `feat/dpa-v8-supplier-goods-return` (2). **If your lane is in that list,
the block below is for you.**

Reproduce locally before you push — no repository variable needed:

```bash
./scripts/preflight.sh                      # now includes all of steps 2–5
cd apps/web && pnpm audit:i18n:local        # just the i18n gate
```

`audit:i18n:local` runs `scripts/i18n-baseline-authority.sh`, which re-derives the owner-pinned
protected blob from the reviewed seed commit.

**(b) `tests/Feature/Security` now gates PR→dev.** 17 module-gating and kill-switch classes that
previously only ran on PR→main. If your branch changes route middleware, module gating, or a
kill-switch, you will now find out on the dev PR instead of at the main merge. Cost: ~43 s of phpunit, but **~2–3 min of billed runner time** as a whole job (checkout + setup-php + composer + env).

**(c) The two event-sourcing ratchets now gate PR→dev** (added at the 2026-08-21 stale-A rebase —
the es-A0 M5-r1 F-1 fold-in the parent ledger ticketed to this package's ci.yml reconciliation).
`OrphanedEventRatchetTest` fails your dev PR if it dispatches a NEW event with zero registered
listeners (the existing 75 are baselined BY NAME); `ProjectorEmissionRatchetTest` fails it if a
registered `FiscalEventProjector` writes a POS projection and emits no domain event (existing 6
skip-listed BY NAME). Both previously ran only in `backend-test`, which PR→dev skips — the drift they
catch could land on `dev` and surface at the `main` boundary. SQLite-safe, no DB rows, ~4 s.

---

## 4. Merge-order and reconciliation — **TWO** open lanes still write `ci.yml` (P2 + OpenAPI); four of the original six landed

| Lane (branch) | `ci.yml` change | rewrites `needs:` | Reconciliation |
|---|---|---|---|
| **P2** (this package) | 5 steps + 1 job (`security-regression`) | ✅ | — |
| **`codex/ui-wave0-2026-08-11`** — **LANDED** (ancestor of the base) | the `route-manifest-drift` job | ✅ | Done: its job is in the shipped `needs:` line; P2's rebase reconciled both entries. |
| **`codex/openapi-contract-a-to-z`** | adds `backend-openapi-contract` | ✅ | Measured from the **branch**: the "no OpenAPI CI wiring" reading came from grepping P2's *base*, and this lane has no worktree. It is one of the four `needs:` rewriters. (An earlier draft called it "the last `ci.yml` writer … lands after P2 with certainty" — that was a scheduling assumption, not a measurement, and nothing in the repo establishes the order. The §1a remedy is order-independent either way.) **See §1a — it also adds a brand-new `tests/Feature/OpenApi/` group, which hard-fails until dispositioned.** |
| **`codex/enforcement-p1-dpa-guard`** — **LANDED** (promoted + closed 2026-08-21) | adds the DPA guard job | ✅ | Done: `backend-dpa-guard` is in the shipped `needs:` line. |
| **`codex/dn-consolidation-2026-08-12`** — **LANDED** | 1 | — | Done: its PG path-lane step survived the rebase (the checker now parses it — comment-stripping fix, Phase 5.5.1). |
| **`codex/es-wave-a0`** — **LANDED** | adds an Architecture-ratchet step | — | Done: its `backend-test` step survived; its PR→dev wiring gap is closed by this package's §2 step 6. |

**`needs:` reconciliation — one rewriter left.** Of the four original rewriters, `ui-wave0` and
`enforcement-p1-dpa-guard` have landed and P2's shipped `needs:` line (`ci.yml:1443`) carries all 15
members including theirs. The only remaining rewriter is `codex/openapi-contract-a-to-z`: when it
lands after P2 it must re-verify every listed job survives and add `backend-openapi-contract`.

**Pin-tag lockstep (for whoever re-pins the i18n baseline later):** `ci.yml` fetches the tag by
literal name (`git fetch origin tag ci-pin/enforcement-p2-r1`). Tags are never reused, so a re-pin
allocates a new name and must edit **`ci.yml` and `docs/handoff/progress/enforcement-p2.progress.yaml`
in the same commit**. Desync fails closed and is caught by a tools test, but it costs a red CI round.

---

## 5. ⛔ OPEN OWNER LINE — `tests/Feature` execution scope (F-2, escalated)

Recorded at `owner_gates.F-2-ci-minutes-budget`, `status: escalated_to_owner`. **Owed at promotion;
does not block this package.** Measured **6.24 s/class** (two independent samples) on SQLite — the
*fast* path; Option A means PostgreSQL and is slower.

| Option | Scope | Cost per triggering event |
|---|---|---|
| A — whole Feature suite on PG | 1 329 classes | ≈ 138 min |
| B-full — the 71 laneless groups | 1 131 classes | ≈ 118 min |
| B-partial — fund a subset (e.g. `POS` 143, `Inventory` 105, `Fiscal` 73) | owner's pick | ~10 min / 100 classes |
| **B-as-shipped — ADOPTED interim (parent ruling 2026-08-19)** | 0 new classes | **0 min** |

The `security-regression` move ruled YES separately and already landed: **~43 s of phpunit, ~2–3 min
billed** as a whole job. Additive and revisitable at any time under this package's protocol. Every structural guarantee already
holds at B-as-shipped: new directories are visible, laneless groups cannot grow, allowlists cannot
shadow by substring, and a lane cannot be certified by a commented-out step.

---

## 6. Two smaller notes worth one line each

- **Plural families** are now recognised only on real i18next evidence — `{{count}}` in a value, or an
  `_other` form plus a sibling. A key like `wizard.step_one` is deliberately **not** treated as a
  plural (it would otherwise demand six Arabic forms with no escape short of an owner re-pin). The
  narrowing is silent by design; recorded so nobody reads it as a detector gap.
- **`no-parsefloat-on-money` has a known gap**, now documented in its own RuleTester rather than
  papered over: `parseFloat(doc.total ?? '0')` is not flagged (the rule bails on non-Identifier/
  MemberExpression arguments). 35 such callsites exist, 29 money-named. Reported by the DN lane
  (M4 round-2 N2); fixing the rule is separately ledgered because it would move the lint-warning
  baseline.

---

## 7. Owner prerequisites — BOTH must exist before the merge lands

Otherwise `frontend-lint` reddens **every** open lane (correct fail-closed direction, but repo-wide):

1. repository variable **`I18N_BASELINE_PROTECTED_BLOB`** = `26a9ae1688d80e0f450215326b19ccd1701c9a8f` (seed `6a0c1cd72` — the merged-tree seed revision; the original `da151bbc5…`/`cb618c12c` pins were superseded by the 2026-08-21 stale-A rebase)
2. annotated tag **`ci-pin/enforcement-p2-r1`** at exactly the accepted SHA

Neither has ever executed on a real runner (the executor never pushes), so the pre-promotion
`workflow_dispatch` is their first exercise. The handback names the exact step ids to verify.

---

## 8. Owed at promotion — for the parent, not the lanes

0. ✅ **DISCHARGED IN-CANDIDATE (2026-08-21 stale-A rebase, Phase 5.5.1):** the package was rebased
   onto the then-current `dev` tip `a4a8c2293` and the ceilings REGENERATED against that merged
   tree (`CountryDefaults` 28 / `Document` 74 / `Fiscal` 79 / `Inventory` 106, `debt_ceiling`
   **1131**) — the checker exits 0 on the accepted tip. The instruction below stays as written for
   the residual case: if `dev` moves again between this acceptance and the merge, regenerate again
   (step 2) — never copy.
   ⚠️ **RE-BASELINE THE CEILINGS IMMEDIATELY AFTER THE MERGE — regenerate, do not copy the numbers
   below.** ⚠️ **`dev` MOVES.** During the M3 round-6 review alone it advanced twice
   (`41fb478c2` → `3d66be352`, the second being `merge codex/es-wave-a0`), which changed `Fiscal`
   73 → 79 and `debt_ceiling` 1114 → 1122 — **and moved a third time, to `47fdc72b9`, while this
   very correction was being written.** That is three advances across one review round and one
   edit. **No number written in this candidate can be correct at promotion time**, which is why
   the instruction is *regenerate*, not *apply the table*.

   **Do this, in order:**
   1. merge P2;
   2. regenerate the manifest against the **merged tree**;
   3. confirm `php tools/feature-lane-manifest-check.php` exits 0 **on `dev`** before any other lane
      opens a PR.

   The table below is an *illustration of the shape of the drift*, **stamped `dev` = `3d66be352`** —
   not a list to apply:

   | group | ceiling frozen at P2's base | on `dev` @ `3d66be352` |
   |---|---|---|
   | `Inventory` | 105 | 106 |
   | `CountryDefaults` | 27 | 28 |
   | `Fiscal` | 73 | **79** (landed mid-review) |
   | `debt_ceiling` | 1114 | **1122** |

   **The breakage this section originally predicted was closed in-candidate**: the 2026-08-21
   stale-A rebase re-based the whole series onto the then-current `dev` tip `a4a8c2293` and
   regenerated the ceilings against that merged tree (Phase 5.5.1), so the accepted SHA's manifest is
   self-consistent WITH `dev` at 1131 and the checker exits 0 at A. The prediction above ("the next
   PR→dev fails with `ceiling is 105`") described the pre-rebase candidate and no longer applies at A.

   **Required post-merge step — the ONLY instruction, no literal values:** if `dev` moved between this
   acceptance and the merge, regenerate the manifest against the **merged tree** and confirm
   `php tools/feature-lane-manifest-check.php` exits 0 **on `dev`** before any other lane opens a PR.
   Never copy numbers from this document or any other checkout — regeneration is the whole
   instruction. (If `dev` did not move, the shipped manifest is already correct and the checker's
   exit-0 on `dev` confirms it for free.)

1. **Owner prerequisites, BOTH before the merge lands** (§7): the repository variable and the annotated
   tag. Either missing reddens `frontend-lint` on every open lane — correct fail-closed direction, but
   repo-wide.
2. **The S-14 pre-promotion `workflow_dispatch` must show these executed and green**, by name:
   - `frontend-lint` steps — *Fetch the pinned i18n baseline revision*, *Run i18n completeness gate
     (tools/audit-i18n-completeness.mjs)*, *Run detector liveness suites (ESLint RuleTester + tools
     tamper tests)*;
   - `backend-architecture` steps — *Check tests/Feature CI-lane manifest*, *Feature-lane checker
     liveness test*, *Event ratchets (orphaned events + projector emission — PR→dev lane)*;
   - **the `security-regression` job.** Called out specifically: its environment is a strict *subset*
     of `backend-test`'s (no postgres/redis services, `pdo_sqlite` only). The reasoning is documented
     and the suite is green locally, but a latent service dependency would not surface on a developer
     box with a running stack, and the executor never pushes. This dispatch run is its first real
     execution.
3. **FOUR inherited red gates at `base_sha` — PARENT-OWNED, not P2's** (recorded in the progress YAML
   `blockers`): `backend-analyse` (PHPStan, LEDGER C-3), `backend-architecture` (deptrac, 99 → 174,
   **unrecorded**), and `frontend-lint`'s `lint:ratchet` step (`@autoerp/pos` 40 → 84, **unrecorded**).
   …and **`types-drift`** (found at M4): the PHP enum `MovementGlKind` exists at `base_sha` but is
   absent from `packages/shared/types/generated.d.ts` at `base_sha`. **P2 touched neither a PHP enum
   nor the generated file** — true by construction, and deliberately so: running preflight at M4
   regenerated `generated.d.ts` as a side effect and it was swept into a commit; the final gate
   caught it and the **parent ruled REVERT**, so that file is byte-identical to its base blob
   (`0c652f34a…`) at the accepted tip. **P2 does not regenerate it**, so the red stays attributable
   to its true source — the parent's red-gate reconciliation ledger, as the 3C generated-artifact
   overlap.

   P2 changed zero PHP files; all four predate this branch, and **none of the four jobs has an
   `if:` guard**, so all four run on the pre-promotion dispatch and block promotion until
   remediated, re-baselined, or explicitly waived.

## 9. Named residuals the lanes should know about

The guards are lexical analyses of a YAML file; they make the common failures loud and cheap, they do
not prove purity. The complete list is in `DECISION-enforcement-p2-ci-guards-2026-08-19.md` §(73):
N-4, N-5, R8-1, R8-2, R8-3. Two matter to other lanes:

- **N-4 — for P3-M2 specifically:** the anchoring/uniqueness lint only reads `--filter` inside
  `.github/workflows/ci.yml` `run:` blocks. **Wire the country-chart completeness test into a lane
  inside `ci.yml`** — an allowlist moved into a shell script or a second workflow leaves the lint's
  field of view silently.
- **The 2(b) reciprocal is NOT machine-enforced.** The brief requires that *"the landed P3 test must
  never be silently dropped from CI"*. Per-group ceilings plus the global `debt_ceiling` stop the debt
  growing silently, but a restructuring that lowers `debt_ceiling` in the same commit is legal.
  **P3-M2 must not assume machine protection it does not have.**

---

## 10. Open-lane roster — inclusion rule stated, merge state shown

**Inclusion rule, stated rather than implied** (this section has been wrong twice for exactly this
reason — first a worktree-only sweep, then an unstated `codex/*` filter):

- enumerate **all** local branches, `git branch --format='%(refname:short)'` — no prefix filter;
- **drop branches already merged into `dev`** (`git merge-base --is-ancestor <b> dev`) — a landed lane
  cannot perform a rebase action, and its classes are already `dev`'s drift, handled by §8 item 0;
- drop snapshot/backup refs that are not lanes: `*-pre-repin*`, `*-pre-rewrite`, `backup/*`,
  `triage/*`, `worktree-agent-*`;
- measure **Feature-class** impact as **added** files against `dev`
  (`git diff --diff-filter=A dev...<branch>`), not changed files against P2's base — **but the
  "locale files" column below is deliberately a CHANGED-file count** (`git diff --name-only`), because
  adding a translation key modifies an existing bundle and `--diff-filter=A` returns 0 for every lane;
- drop **this package's own branch** (`codex/enforcement-p2-ci-guards`) — it is the thing being
  announced, not a lane to notify.

**Three edges the rule does not cleanly cover, stated rather than hidden:**

- `factory/board` shares **no merge base** with `dev`, so `git diff dev...factory/board` exits
  `fatal: no merge base`. Assessed by direct inspection instead: 21 files, none under `tests/Feature`,
  `apps/web/src/locales` or `.github/workflows` → **nothing to do**.
- `l6-integration-verify` has **multiple** merge bases, so `dev...` is base-dependent. The counts shown
  are git's chosen base; a lane re-verifying should use `git diff --diff-filter=A $(git merge-base dev
  l6-integration-verify)..l6-integration-verify`.
- Snapshot refs are excluded by name pattern, which is a judgement call, not a property — if a
  `*-pre-repin` branch is in fact a live lane, it needs a row.

| lane (branch) | added Feature classes | locale files | `ci.yml` | `needs:` | action |
|---|---|---|---|---|---|
| `codex/openapi-contract-a-to-z` | **new `OpenApi` +6** | — | ✅ | ✅ | **§1a disposition**; §4 `needs:` (last rewriter standing) |
| `l6-integration-verify` | `Http` +2, `Modules` +4, `Tenant` +2 | 2 | — | — | §1 ceilings; §3(a) i18n |
| `feat/scan-vat-configuration` | `Taxation` +1 | 3 | — | — | §1 ceiling; §3(a) i18n |
| `feat/dpa-v8-supplier-goods-return` | `Inventory` +2 | 2 | — | — | §1 ceiling; §3(a) i18n |
| `fix/r2f2-cancel-flow-prompt` | `Document` +1 | 3 | — | — | §1 ceiling; §3(a) i18n |
| `fix/r2d-bcmath-hardening` | `Modules` +2 | — | — | — | §1 ceiling |
| `feat/supplier-invoice-ocr` | `Inventory` +1 | — | — | — | §1 ceiling |
| `feat/owner-dashboard-demo` | `Seeders` +1 | — | — | — | §1 ceiling |
| `feat/r2f4-correcting-documents` | `Accounting` +2 | — | — | — | **Nothing** — `Accounting` is laned |
| `feat/rafiq-skin-experiment` | — | 3 | — | — | §3(a) i18n only |
| `codex/pos-clean-workbench` | — | — | — | — | **Nothing to do.** |
| `factory/board`, `feat/accounting-gl-go-live`, `feat/db-per-tenant-deploy`, `feat/demo-pharmacy-account`, `feat/pos-prepaid-drawdown` | — | — | — | — | **Nothing to do.** |

**Already merged into `dev` — no action required by the rule above.** Roughly 70 local branches are
ancestors of `dev`; the ones a reader is most likely to look for are `codex/dpa-wave3-3c`,
`codex/dpa-wave3-3d`, `codex/country-defaults-phase-a`, `codex/sv-stage1`,
`codex/pos-receipts-2026-08-12`, `codex/accounting-gaps-cghi`, `codex/tenant-impersonation` — and,
since the 2026-08-21 stale-A rebase moved this package's base to `a4a8c2293`, **four lanes earlier
versions of this file billed as open: `codex/ui-wave0-2026-08-11`, `codex/dn-consolidation-2026-08-12`,
`codex/es-wave-a0` and `codex/enforcement-p1-dpa-guard`** (P1 promoted + closed). Their ceiling raises
are already in the shipped manifest (§8 item 0) — do NOT re-apply their old §1 rows. **That
is an illustrative subset, not an exhaustive list** — the exhaustive statement is the rule itself
(`git merge-base --is-ancestor <branch> dev` → no action). Their added classes are already part of
`dev`'s drift and are covered by **§8 item 0**, not by a lane action.

> **`codex/enforcement-p1-dpa-guard` adds no Feature classes of its own.** An earlier version credited
> it with 23 (the same set as `dpa-wave3-3d`) because that measurement was taken against P2's base and
> `dpa-wave3-3d` has since merged into `dev` — so P1 *inherited* them through `dev`. Following that row
> would have raised five ceilings for classes it never authored and double-raised against §8 item 0.
