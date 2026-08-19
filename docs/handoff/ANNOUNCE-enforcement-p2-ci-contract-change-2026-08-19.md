# P2 merge-announcement checklist — INPUT to the parent's final announcement

> **This file is not the announcement.** It is the M3 deliverable the parent builds the final
> announcement from, sent after M3/M4 acceptance and **before** promotion; `merge_announcement_ack`
> records the sent fact in the post-promotion admin commit (gate-r2 R2-H-1, gate-r3 R3-C-2). The
> executor never sends it.
>
> Lane impact below is **measured**, not guessed: `git diff --name-only <p2-base>...<lane-tip>` per
> worktree, run 2026-08-19.

---

## 1. ⚠️ READ THIS FIRST — three in-flight lanes will go RED without a one-line edit

P2 adds a **non-growth ceiling** per `tests/Feature` group that no CI lane runs. Adding a Feature test
to such a group now **hard-fails** `backend-architecture`. This is the intended behaviour — the
990-class hole must not grow silently — and the remedy is one number, in the same commit.

| Lane | Feature dirs it touches | Ceiling status | What you must do |
|---|---|---|---|
| **dn-consolidation** | `Document` (65), `Partner` (20) | both **DEFERRED** | bump `classes` in `apps/api/tests/feature-lane-manifest.json` for each group you add a class to |
| **es-wave-a0** | `Fiscal` (73), `POS` (143) | both **DEFERRED** | same |
| **dpa-wave3-3d** | `Fiscal`, `POS`, `Inventory` (105), `BatchExpiry` (16), `CountryDefaults` (27) | all **DEFERRED** | same |
| dpa-wave3-3d | `Accounting` | **laned** (`treasury-spine-pgsql`) | nothing — laned groups have no ceiling |

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
`RouteCoverageCleanCheckoutTest`, `RouteCoverageVerificationTest`), absent from P2's base. Because that
lane is also the **last** `ci.yml` writer, it lands after P2 with certainty, and
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

Six new steps and one new job. `frontend-lint` and `backend-architecture` have **no `if:` guard**, so
their steps run on **every event that starts the workflow** — PR→`main`, PR→`dev`, push→`main`,
`workflow_dispatch`. (A direct push to `dev` still starts nothing. Unchanged.)

| # | Job | Step / job | What fails you |
|---|---|---|---|
| 1 | `frontend-lint` | Fetch the pinned i18n baseline revision | tag `ci-pin/enforcement-p2-r1` must exist on the remote (owner-created at promotion — nothing for a lane to do) |
| 2 | `frontend-lint` | i18n completeness gate | a **new** missing `fr` key, a new CLDR plural gap, or any growth of `apps/web/tools/i18n-completeness-baseline.json` |
| 3 | `frontend-lint` | Detector liveness suites | `pnpm test:eslint-rules` (6 suites, was 3) or `pnpm test:tools` (7 files) going red — **both previously ran in no workflow**, so a rule test your branch broke has been failing silently |
| 4 | `backend-architecture` | Check tests/Feature CI-lane manifest | §1 above, plus: a lane whose selector is missing/narrowed/soft-failed, a job or step gated or softened, the workflow no longer starting on PR→dev, or the checker's own steps disabled |
| 5 | `backend-architecture` | Feature-lane checker liveness test | the checker's own **46-case** suite |
| 6 | **`security-regression` (NEW JOB)** | Security regression suite | `tests/Feature/Security` (17 classes) now runs on **PR→dev**; it previously ran only on PR→main |

**Branch protection: no action.** Steps 1–5 sit inside jobs already in `all-checks-pass` `needs`. The
one new job, `security-regression`, was **added** to that list — the aggregate is main-only, so no
`dev` protection rule changes.

---

## 3. The two behaviour changes that will surprise people

**(a) Adding an English string now requires French — and Arabic in 33 of 56 namespaces.**

| ar namespace class | count | new English key there → |
|---|---|---|
| `own` or `english-spread` (Arabic is wired in) | 33 | **CI FAILS** until `ar` authors it |
| `en-aliased` (wired to the English bundle) | 23 | **passes** — one whole-namespace `aliased` baseline entry, not per-key |

`pos`, `sales`, `inventory`, `settings`, `finance`, `treasury`, `compliance`, `notifications`,
`locations`, `products`, `expenses`, `import` are all in the **33**. Lanes touching locales:
**ui-wave0 (16 files)** and **dn-consolidation (4 files)**.

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

---

## 4. Merge-order and reconciliation — **SIX** lanes write `ci.yml`; **FOUR** rewrite the same `needs:` line

| Lane | `ci.yml` change | Reconciliation |
|---|---|---|
| **P2** (this package) | 6 steps + 1 job + `all-checks-pass` `needs` | — |
| **ui-wave0** | 1 — the `route-manifest-drift` job **and** an `all-checks-pass` `needs` entry | Both P2 and UI edit the same `needs` list. **Whichever lands second rebases and re-verifies that BOTH entries survive.** P2 deliberately did not author the drift job (F-3 ownership); UI's T7 C6 regex fix is likewise still UI's. |
| **dn-consolidation** | 1 | Same rule — rebase and re-verify aggregate `needs`. |
| **OpenAPI lane** | none at P2's base (`grep -rni 'openapi' .github/workflows/` → nothing) | Owns CI drift/coverage wiring for its layer. No conflict today, but it is the *last* `ci.yml` writer: rebase onto P2 and add your own jobs to `all-checks-pass` `needs` yourself. |

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
| B-full — the 71 laneless groups | 1 114 classes | ≈ 116 min |
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

1. repository variable **`I18N_BASELINE_PROTECTED_BLOB`** = `da151bbc51edcd066d2153b51a1df8ae1ab5bd32`
2. annotated tag **`ci-pin/enforcement-p2-r1`** at exactly the accepted SHA

Neither has ever executed on a real runner (the executor never pushes), so the pre-promotion
`workflow_dispatch` is their first exercise. The handback names the exact step ids to verify.

---

## 8. Owed at promotion — for the parent, not the lanes

0. ⚠️ **RE-BASELINE THE CEILINGS IMMEDIATELY AFTER THE MERGE — otherwise P2 breaks `dev` for every
   lane.** P2's `base_sha` is **47 commits behind `dev`**, and two deferred groups have grown on `dev`
   since:

   | group | ceiling frozen at P2's base | on `dev` today |
   |---|---|---|
   | `Inventory` | 105 | **106** (`CountCorrectionGlPostingTest.php`) |
   | `CountryDefaults` | 27 | **28** (`ChartOfAccountsParityTest.php`) |
   | `debt_ceiling` | 1114 | **1116** |

   The promotion protocol merges the accepted SHA **unchanged**, so the moment P2 lands, the next
   PR→dev from *any* lane fails `backend-architecture` with
   `✗ COVERAGE DEBT GREW: group "Inventory" now holds 106 class(es), ceiling is 105.` — blaming that
   lane for debt that predates its branch. **The pre-promotion `workflow_dispatch` will NOT catch
   this**: it runs on the accepted SHA, where the tree is self-consistent and green.

   **Required post-merge step, in the first commit on `dev` after the merge:** set `Inventory` → 106,
   `CountryDefaults` → 28, `debt_ceiling` → 1116 — or, better, regenerate the manifest against the
   merged tree and confirm `php tools/feature-lane-manifest-check.php` exits 0 **on `dev`** before any
   other lane opens a PR.

   *(Deliberately not pre-raised in the candidate: a ceiling set from another checkout's `dev` would
   introduce slack the reviewer specifically verified absent, and would be stale again by merge time
   since `dev` keeps moving. The re-baseline has to happen against the merged tree.)*

1. **Owner prerequisites, BOTH before the merge lands** (§7): the repository variable and the annotated
   tag. Either missing reddens `frontend-lint` on every open lane — correct fail-closed direction, but
   repo-wide.
2. **The S-14 pre-promotion `workflow_dispatch` must show these executed and green**, by name:
   - `frontend-lint` steps — *Fetch the pinned i18n baseline revision*, *Run i18n completeness gate
     (tools/audit-i18n-completeness.mjs)*, *Run detector liveness suites (ESLint RuleTester + tools
     tamper tests)*;
   - `backend-architecture` steps — *Check tests/Feature CI-lane manifest*, *Feature-lane checker
     liveness test*;
   - **the `security-regression` job.** Called out specifically: its environment is a strict *subset*
     of `backend-test`'s (no postgres/redis services, `pdo_sqlite` only). The reasoning is documented
     and the suite is green locally, but a latent service dependency would not surface on a developer
     box with a running stack, and the executor never pushes. This dispatch run is its first real
     execution.
3. **Inherited red gates at `base_sha` — PARENT-OWNED, not P2's** (recorded in the progress YAML
   `blockers`): `backend-analyse` (PHPStan, LEDGER C-3), `backend-architecture` (deptrac, 99 → 174,
   **unrecorded**), and `frontend-lint`'s `lint:ratchet` step (`@autoerp/pos` 40 → 84, **unrecorded**).
   P2 changed zero PHP files; all three predate this branch. All three run on the dispatch, so they
   block promotion until remediated, re-baselined, or explicitly waived.

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

## 10. Complete open-lane roster — every lane gets a line, including "nothing to do"

Derived from **branches**, not worktrees (`git branch --format='%(refname:short)'`), after the
worktree-only enumeration missed the OpenAPI lane entirely. Impact measured per branch with
`git diff --name-only <p2-base>...<branch>`, so it is that lane's **own** changes.

| lane / branch | web files | locale files | Feature tests | `ci.yml` | rewrites `needs:` | **what you must do** |
|---|---|---|---|---|---|---|
| `codex/ui-wave0-2026-08-11` | 78 | 16 | 0 | ✅ | ✅ | §3(a) i18n for the 33 wired ns; §4 three-way `needs:` reconciliation; your `route-manifest-drift` job must survive P2's edits |
| `codex/dn-consolidation-2026-08-12` | 35 | 4 | 13 (`Document`, `Partner`) | ✅ | — | §1 **raise both ceilings**; §3(a) i18n; §4 rebase `ci.yml` |
| `codex/es-wave-a0` | 0 | 0 | 16 (`Fiscal`, `POS`) | ✅ | — | §1 **raise both ceilings**; §4 rebase `ci.yml` (adds an Architecture-ratchet step) |
| `codex/dpa-wave3-3d` | 0 | 0 | 23 (`Accounting`†, `BatchExpiry`, `CountryDefaults`, `Fiscal`, `Inventory`, `POS`) | — | — | §1 **raise five ceilings** (†`Accounting` is laned — no ceiling) |
| `codex/openapi-contract-a-to-z` | 0 | 0 | 6 (**new group `OpenApi`**) | ✅ | ✅ | §1a **disposition the new group — hard fail otherwise**; §4 you are the last `ci.yml` writer |
| `codex/enforcement-p1-dpa-guard` | 0 | 0 | 23 (same set as 3D) | ✅ | ✅ | §1 **raise five ceilings**; §4 `needs:` reconciliation. Sibling enforcement package — the brief allows P1 and P2 in parallel, so coordinate the aggregate edit |
| `codex/dpa-wave3-3c` | 0 | 0 | 0 | — | — | **Nothing to do.** |
| `codex/country-defaults-phase-a` | 0 | 0 | 0 | — | — | **Nothing to do.** |
| `codex/sv-stage1` | 0 | 0 | 0 | — | — | **Nothing to do.** |
| `codex/pos-receipts-2026-08-12` | 0 | 0 | 0 | — | — | **Nothing to do** *today* — but receipts work adds user-facing strings, so §3(a) applies the moment it does. |
| `codex/accounting-gaps-cghi` | 0 | 0 | 0 | — | — | **Nothing to do.** |
| `codex/pos-clean-workbench` | 0 | 0 | 0 | — | — | **Nothing to do.** |
| `codex/tenant-impersonation` | 0 | 0 | 0 | — | — | **Nothing to do.** |

**"Nothing to do" is stated explicitly on purpose.** Silence is indistinguishable from "overlooked",
and being overlooked is exactly the mechanism that produced the two P1s this checklist was corrected
for — the OpenAPI lane's new group and its `ci.yml` edit were both missed by a worktree-only sweep.

**`needs:` reconciliation is FOUR-way**, not two: P2, `ui-wave0`, `openapi-contract-a-to-z` and
`enforcement-p1-dpa-guard` all rewrite `all-checks-pass.needs`. Whoever lands last must confirm every
earlier lane's job is still in the list — `route-manifest-drift`, `security-regression`,
`backend-openapi-contract`, and P1's DPA guard job.
