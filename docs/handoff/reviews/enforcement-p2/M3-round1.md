## Adversarial merge-gate register — P2-M3, round 1

**Scope reviewed:** brief §3 "2(a)" + the `p2-M3` milestone line (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:308-317,391`) and the M3 YAML title (`docs/handoff/progress/enforcement-p2.progress.yaml:256`). M3's own diff is `0fce1206d` + `e91f845e5`: three files (`ANNOUNCE-…-2026-08-19.md` new, `DECISION-…` §(80)-(82), progress YAML). Amending authority: none.

**Lens — frontend-conventions:** M3 adds zero `apps/web` code, so the component/token/RHF/tenant-key surface does not apply. What the lens *does* bite on is the i18n contract the checklist asserts to other lanes; I verified it: 56 wired namespaces (`apps/web/src/lib/i18n.ts:459`), 23 `aliased` baseline entries, 33 ar-wired — the §3(a) split and its named-namespace list are exactly right.

---

### 1. P1 — CONFIRMED — the merge target already breaches two ceilings; the checklist never says so
`docs/handoff/ANNOUNCE-enforcement-p2-ci-contract-change-2026-08-19.md:20-27,150-170` · `apps/api/tests/feature-lane-manifest.json` (`Inventory: 105`, `CountryDefaults: 27`, `debt_ceiling: 1114`)

`base_sha` `c97e0d1a` is 47 commits behind `dev`. On `dev` today: `apps/api/tests/Feature/Inventory` = **106** classes (`CountCorrectionGlPostingTest.php`), `CountryDefaults` = **28** (`ChartOfAccountsParityTest.php`) — both **deferred** groups whose ceilings P2 froze at the stale base. The checker enumerates every `*Test.php` under the group (`apps/api/tools/feature-lane-manifest-check.php:121-132`) and hard-fails on growth (`:337-346`), plus the global debt (1116 > 1114, `:786-793`).

Failure scenario: promotion protocol merges the accepted SHA **unchanged** (brief §5), so the moment P2 lands on `dev`, the next PR→dev from *any* lane runs `backend-architecture` → `✗ COVERAGE DEBT GREW: group "Inventory" now holds 106 class(es), ceiling is 105.` The pre-promotion `workflow_dispatch` runs on the accepted SHA, where the tree is self-consistent, so it goes **green and hides this**. §1 then tells lanes "you added a class, bump the number" — blaming them for debt that predates their branch. This is exactly the "never land cold" prohibition the checklist exists to enforce. §8 "Owed at promotion" has no line for it. Fix is documentary + a post-merge re-baseline instruction (Inventory→106, CountryDefaults→28, debt_ceiling→1116), not code.

### 2. P1 — CONFIRMED — "No lane currently does this — verified across all worktrees" is false
`ANNOUNCE-…:29-31`

The claim covers the hardest failure mode (a brand-new `tests/Feature/<Dir>/` with no disposition = hard fail). The OpenAPI lane adds a whole new group: `codex/openapi-contract-a-to-z` carries `apps/api/tests/Feature/OpenApi/` with **6** classes (`DocumentResponseContractTest`, `PilotGenerationTest`, `RouteCoverageVerificationTest`, …), absent from base. §4 designates that same lane the **last** `ci.yml` writer — i.e. it lands after P2 with certainty — and its row tells it only to fix aggregate `needs`. Failure scenario: OpenAPI rebases onto the landed P2, `backend-architecture` fails with an unassigned-group error nobody warned it about, mid-fix-round, in a lane already on its third gate round.

### 3. P2 — CONFIRMED — "THREE lanes write `ci.yml`, not two" / "two lanes edit `all-checks-pass` `needs`" — it is four and three
`ANNOUNCE-…:110-121`

`git diff base...codex/openapi-contract-a-to-z -- .github/workflows/ci.yml` adds job `backend-openapi-contract` **and** rewrites the same `needs:` line P2 and ui-wave0 both rewrite (`ci.yml:1249`). The row's "none at P2's base" is literally true, but the file's own methodology note (`:8-10`) claims lane impact is measured per worktree — and it *was* measured from branch tips for ui-wave0 and dn-consolidation, then base-greped for OpenAPI. Failure scenario: the parent sequences a three-lane reconciliation and gets a three-way conflict on one line, with the fourth writer unbriefed.

### 4. P2 — CONFIRMED — the milestone's named invariant "every open lane, one line each" is not met
`enforcement-p2.progress.yaml:256`; brief `:391` ("which lanes are open, what they must rebase for")

The checklist gives lines to 5 lanes (dn-consolidation, es-wave-a0, dpa-wave3-3d, ui-wave0, OpenAPI). Active lane worktrees with no line at all: `dpa-wave3-3c`, `enforcement-p1`, `country-defaults-phase-a`, `receipts-build`, `sv-stage1`, `accounting-gaps-cghi`, `pos-clean-workbench`. There is no roster of open lanes anywhere in the file. Failure scenario: a lane that needed only "nothing to do" instead gets silence and cannot tell whether it was assessed or overlooked — and the omission is precisely the mechanism that produced findings 2 and 3.

### 5. P2 — CONFIRMED — the owner-facing PR→dev aggregate proposal rests on a wrong fact, and its recommended `needs` list drops a job that really runs
`DECISION-enforcement-p2-ci-guards-2026-08-19.md` §(81) (M3 addition, lines ~1885-1905)

The doc states "on PR→dev, `backend-test`, `frontend-test`, `frontend-build` and `pos-test` are `if:`-gated off" and "the aggregate `needs` twelve jobs". `pos-test`'s guard is `github.event_name == 'workflow_dispatch' || github.event_name == 'pull_request' || (push && ref==main)` (`ci.yml:1099`) — `pull_request` is true on PR→dev, so **`pos-test` runs on PR→dev**. Gated-off is three, not four; `needs` now holds thirteen, not twelve. Consequence: recommended Option 1's `all-checks-pass-dev` `needs` list omits `pos-test`, so an owner adopting it verbatim creates a "single required check for dev" that silently excludes POS Vitest — the same certified-by-omission class this package exists to end.

### 6. P3 — CONFIRMED — note only
`ANNOUNCE-…:97` claims `pnpm test:tools` = "7 files / 145 tests"; 7 files is right, a crude `it(`/`test(` count gives ~139. Not load-bearing, but it is a number a lane may quote.

---

### Bypasses I tried that FAILED (executor is clean on these)
- **2(a) authorship**: `grep -n 'manifest-drift|route-manifest|gen-route-manifest' .github/workflows/ci.yml` → exit 1. P2 authored no drift job, no C6 test, no manifest regeneration. Verify-only disposition is honestly executed, and the M0-snapshot binding with the recorded `blocked_architecture → review` motion is the right call (the outcome is the same under either snapshot).
- **Unilateral aggregate change**: `git diff base..HEAD -- ci.yml | grep 'if:|needs:'` → the aggregate `if:` is byte-identical to base; the only `needs` edit is `+security-regression`. The proposal really is a proposal.
- **Ceiling numbers in §1**: Document 65 / Partner 20 / Fiscal 73 / POS 143 / Inventory 105 / BatchExpiry 16 / CountryDefaults 27 / Accounting laned — all match the base tree and the manifest exactly. Lane→group impact (dn-consolidation +9 Document, es-wave-a0 +6 Fiscal, dpa-wave3-3d +CountryDefaults/+Inventory) reproduces.
- **Quoted failure message**: matches `feature-lane-manifest-check.php:340-342` verbatim, including the `  ✗ ` prefix at `:804`.
- **§8 dispatch step/job ids**: "Fetch the pinned i18n baseline revision", "Run i18n completeness gate (tools/audit-i18n-completeness.mjs)", "Run detector liveness suites (ESLint RuleTester + tools tamper tests)", "Check tests/Feature CI-lane manifest", "Feature-lane checker liveness test", job `security-regression` — all exact matches in `ci.yml`.
- **§7 prerequisites**: `I18N_BASELINE_PROTECTED_BLOB = da151bbc…` equals the YAML mirror (`:60`); tag `ci-pin/enforcement-p2-r1` equals the literal in `ci.yml`'s fetch step and the YAML pin (`:61`).
- **§3 i18n split**: 56 namespaces, 23 aliased, 33 wired; none of the twelve namespaces named as "in the 33" is actually aliased. §3(b) `~43 s` phpunit / `~2–3 min` billed matches the `security-regression` job comment.
- **ui-wave0 "16 files"**: exactly 16 under `apps/web/src/locales`.

### Standing checks
Rule 19 (float/money), tenant scoping, constructor injection, migrations, Horizon queues: **not applicable** — M3 changes two markdown files and one YAML, no PHP/TS. Red-first evidence: not applicable, M3 is a verification-and-documentation milestone with no behavioural change. Process hygiene is correct: `status: review`, `fix_rounds: 0`, `commit: 0fce1206d…` recorded in a separate metadata commit, no parent register committed into the tree.

VERDICT: CHANGES-REQUIRED
