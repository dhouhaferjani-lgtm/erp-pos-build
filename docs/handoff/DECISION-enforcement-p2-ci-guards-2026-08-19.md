# Decision record — Enforcement Package 2 (CI runs the guards that already exist)

**Brief:** `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md` (r21), §3 Package 2.
**Progress YAML:** `docs/handoff/progress/enforcement-p2.progress.yaml`
**Branch:** `codex/enforcement-p2-ci-guards` · **worktree:** `.worktrees/enforcement-p2`
**base_sha:** `c97e0d1ada0ff73c7beb12dca478fa106df23128`

This file is the durable home for every decision the brief left to the executor. It is written
incrementally (M0 → M3) and is a review target at each milestone's gate.

---

## M0 — Ownership snapshot (brief §3 2(a), gate-r1 H-2 / F-3)

The 2(a) branch decision is derived from THIS snapshot, taken at `base_sha`, and from nothing later.

### (1) UI Wave 0 — `docs/handoff/progress/ui-wave0.progress.yaml`

**Two copies exist and they DIVERGE. The authoritative copy is the one in the live UI Wave 0
worktree** (`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-wave0/`), because that lane's
branch is unmerged; the main-checkout copy is the pre-dispatch state that landed on `dev`.

| Field | Main checkout (`apps/erp/docs/handoff/progress/ui-wave0.progress.yaml`) — STALE | `.worktrees/ui-wave0/docs/handoff/progress/ui-wave0.progress.yaml` — AUTHORITATIVE |
|---|---|---|
| `base_sha` | `d682b38ec9761a917b9716428091a482745795f6` | `d682b38ec9761a917b9716428091a482745795f6` |
| `branch` | `null` | `codex/ui-wave0-2026-08-11` |
| M0 / M0b / M1 / M2 / M3 | `pending` | `passed` |
| **M4 (owns T3(b), the manifest-drift CI job)** | `pending` | **`passed`**, `fix_rounds: 1`, `commit: 6747d9042c252883426f1ebc0f44c2d5c5e54d13`, `verdict: docs/handoff/reviews/ui-wave0/M4-round1.md`, `last_verdict: ACCEPT` |
| M5 | `pending` | `blocked_architecture` |
| M6 / M7 / M8 | `pending` | `pending` |
| wave `status` | `pending` | **`blocked_architecture`** |

**Divergence noted explicitly** (as the parent required): reading the main-checkout copy alone would
have shown M4 `pending` and driven a different (though same-outcome) branch of the decision rule.

### (2) `check-manifest-drift` / route-manifest-drift on `base_sha`

```
$ grep -n 'check-manifest-drift\|manifest-drift\|gen-route-manifest' .github/workflows/ci.yml
(no match — exit 1)
```

**ABSENT at base.** The job exists ONLY on the UI Wave 0 branch (`codex/ui-wave0-2026-08-11`, tip
`acf5dc6bd12d5ceee35596983d9c190253ab1a39`), where it is:

- job id `route-manifest-drift` (`ci.yml:1012`), running `bash scripts/factory/check-manifest-drift.sh` (`:1034`);
- already added to `all-checks-pass` `needs` (`:1130`) with the no-`if:`-never-skipped reasoning comment (`:1128-1129`).

### (3) OpenAPI lane CI-wiring state on `base_sha`

```
$ grep -rni 'openapi' .github/workflows/
(no match — exit 1)
$ ls .github/workflows/
ci.yml  react-doctor.yml  smoke-test.yml  sonarcloud.yml
```

**ZERO OpenAPI CI wiring at base.** That lane (`docs/handoff/HANDOVER-openapi-lane-orchestration-2026-08-07.md:32-36`)
owns CI drift/coverage wiring for its layer and has not landed any. Per the same ownership rule it is
**verify-only for P2**; the M3 merge-announcement checklist carries the explicit OpenAPI
rebase/merge-order line.

### (4) Derived 2(a) disposition — **VERIFY-ONLY + RECORD THE DEPENDENCY. Do NOT author the job.**

> ⚠️ **FLAGGED DECISION — the observed state is off the brief's literal predicate table.** The brief's
> four branches key on `{UI M4 status} × {job present on base}`:
> 1. `M4 passed / wave complete` **AND** job present on base → verify-only (+ fix aggregate membership).
> 2. `M4 pending/in_progress` (wave still owns it) → verify-only + record the dependency; do NOT author.
> 3. task relinquished, **or** wave complete WITHOUT the job on base → implement per T3(b).
> 4. ambiguous → `blocked_owner`.
>
> Observed: **M4 `passed`, wave `blocked_architecture` (NOT complete), job absent from base** (it lives
> on the unmerged UI branch). No branch matches literally: (1) fails on presence, (3) fails because the
> wave is neither complete nor relinquished.
>
> **Ruling taken: branch (2)'s outcome.** The rule's operative principle is stated in the brief and in
> F-3 as *ownership, not presence* — "Never both lanes authoring the same CI job". Ownership here is
> **not ambiguous in the slightest**: UI Wave 0 owns T3(b), has already authored it, and has passed an
> adversarial gate on it (M4 ACCEPT at `6747d9042`). The only thing that is off-table is the *status
> label* — `passed-on-an-unmerged-branch` rather than `pending`/`in_progress` — which does not change
> who owns the artifact. `blocked_owner` (branch 4) is reserved for **ownership** ambiguity; invoking it
> for a label mismatch whose substantive answer is fully determined would stall the package on a
> non-question. Authoring the job here would produce exactly the duplicate F-3 forbids, and would
> conflict textually with `ci.yml:1012-1034` + `:1130` on the UI branch.
>
> **Recorded dependency + obligation (goes into the M3 checklist):** P2 and UI Wave 0 BOTH edit
> `.github/workflows/ci.yml`, and both touch the `all-checks-pass` `needs` list. Whichever lands
> second must rebase and re-verify aggregate `needs` reconciliation — the landed `route-manifest-drift`
> entry must survive P2's edits, and P2's new/edited jobs must survive UI Wave 0's. Same rule as the
> OpenAPI line.

**Nothing verifiable-at-base for the drift job** (it is absent), so the "no `if:` + aggregate `needs`
membership" verification is recorded against the UI branch's own evidence above rather than re-run
here; it becomes P2's obligation only if the UI wave relinquishes the task.

### (5) `all-checks-pass` membership of `frontend-lint` at base (brief 2(c) del. 3, H-9)

```
ci.yml:1108  needs: [backend-lint, backend-analyse, backend-architecture, backend-test,
                     backend-test-pgsql, treasury-spine-pgsql, frontend-lint, frontend-typecheck,
                     frontend-test, pos-test, frontend-build, types-drift]
```

`frontend-lint` **is present** → no wiring work needed for it (the brief's conditional "if absent,
wiring it in is in scope" does not fire).

**Citation drift re-derived at `base_sha`** (the brief warned its line numbers would move):

| Brief's citation | At `c97e0d1ad` |
|---|---|
| `frontend-lint` job at `ci.yml:853` | **`:857`** |
| `run: pnpm audit:keys` `:876` | **`:880`** |
| `run: pnpm audit:design-system` `:879` | **`:883`** |
| `run: pnpm audit:quantity` `:882` | **`:886`** |
| `run: pnpm lint:ratchet` `:890` | **`:894`** |
| `all-checks-pass` `needs` `:1104` | **`:1108`** (job header `:1094`) |

### (6) True event graph re-verified at base (gate-r2 R2-C-2)

```
ci.yml:2-8
on:
  push:
    branches: [main]              # post-merge confirmation only
  pull_request:
    branches: [main, dev]         # cheap checks on PR→dev; full pipeline on PR→main
  workflow_dispatch:              # manual full run from the Actions tab
```

A direct push to `dev` never starts the workflow. Every "runs on every lane" claim below means "on
every event that starts the workflow".

### (7) Other M0 preconditions

| # | Precondition | State at base | Verdict |
|---|---|---|---|
| 1 | `quiet_window_ack` non-null (preliminary scope notice only) | filled by the parent, 2026-08-19 | PASS |
| 2 | `commit_series` non-null | `Phase 5.<milestone>.<seq>` | PASS |
| 3 | ownership snapshot recorded | this section | PASS |
| 4 | fresh worktree/branch off `base_sha` | `.worktrees/enforcement-p2`, `codex/enforcement-p2-ci-guards`, `git log -1` = `c97e0d1ad` | PASS |
| 5 | `ratchet_trust_model_ack` non-null | filled by the parent (4 write principals enumerated + non-authorship assertion) | PASS |
| 6 | `control_manifest` non-null AND manifest present at base with matching `sha256` | `shasum -a 256 docs/handoff/enforcement-control-manifest.yaml` → `709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a`, equal to the pin | PASS |

**M0 = PASS.** No `blocked_precondition`.

### (8) Executor substitution, recorded

The brief names a Codex desktop session as executor. Codex quota is exhausted; the parent dispatched
this package to a Claude implementation agent under the **same contract** (no push, no stash, no full
PHPUnit suite, harness-gated milestones, parent-invoked final gate). Recorded here as a deviation from
the brief's `Executor:` line — it changes who types, not any rule.

### (9) Local tool availability (brief §5 item 4 layer 1)

| Tool | State |
|---|---|
| `claude` CLI (bridge) | present, `2.1.235` |
| PyYAML | present |
| **`actionlint`** | **ABSENT on this machine** — recorded explicitly as the brief requires. Workflow YAML validity is proven by a Python `yaml.safe_load` parse instead; this layer is INSUFFICIENT for the Actions job/event graph either way. |

---

## M1 — 2(c) i18n completeness + 2(d) guard liveness

### (1) Detector design — why the merged `resources` object is never read

`apps/web/tools/audit-i18n-completeness.mjs` computes coverage from **authored-locale provenance**:
the per-locale source files `apps/web/src/locales/<locale>/<ns>.json`. It reads `src/lib/i18n.ts`
only for (a) the namespace list (the `ns:` array), (b) the locale list (the `resources` top-level
keys) and (c) a *provenance classification* of each `(locale, namespace)` assignment, used for
reporting and structural checks — never as a source of key coverage.

Measured at `base_sha`, the classification is the whole argument for gate-r1 H-5:

| locale | `own` | `en-aliased` | `english-spread` |
|---|---|---|---|
| en | 56 | 0 | 0 |
| fr | 56 | 0 | 0 |
| **ar** | **21** | **23** | **12** |

The 23 English-aliased Arabic namespaces are `auth, pricing, uom, parapharmacy, batches, catalog,
promotions, coupons, categories, crm, loyalty, withholding, marketing, countries, progression,
smart-prompts, enrichment, refund-policies, deposits, customer-history-audit, stock-transfers,
stock-adjustments, adminCountryDefaults`; the 12 spread-merged ones are `sales, inventory, treasury,
finance, expenses, import, settings, products, pos, compliance, notifications, locations`.

**A scanner importing `resources` would report ZERO Arabic gaps across all 35 of those namespaces.**
The authored-provenance scanner reports **4 594**.

### (2) Baseline statistics at the seed

| Metric | Value |
|---|---|
| Namespaces in the `ns` array | **56** |
| Authored leaf keys — **en** | **9 242** |
| Authored leaf keys — **fr** | **9 258** |
| Authored leaf keys — **ar** | **4 702** |
| Locale source files present | en 57, fr 57, **ar 34** |
| **Total baseline entries** | **4 631** |
| — `ar` `missing` | 4 585 |
| — `ar` `plural` | 9 |
| — `fr` `plural` | 29 |
| — `en` `plural` | 8 |
| Structural failures | **0** |

Largest Arabic gaps by namespace: `pos` 683, `sales` 569, `inventory` 418, `settings` 274,
`finance` 268, `adminCountryDefaults` 192, `withholding` 181, `loyalty` 178.

`fr` authors 16 more leaf keys than `en` (9 258 vs 9 242) — French-only orphans. **Not flagged and
not baselined:** the brief's contract is "missing key = failure"; orphan detection is not in scope and
adding it would be scope creep. Recorded here so the asymmetry is not mistaken for a scanner bug.

### (3) ⚠️ DECISION — the `ar` policy: **RATCHETED TOWARD PARITY**, not a full-parity gate

The brief requires this to be decided explicitly and forbids silently exempting `ar`. Ruling:
**Arabic is enumerated key-by-key in the checked-in baseline and is removal-only** — the same
treatment `fr` and `en` gaps get. It is NOT exempted, and it is NOT held to immediate full parity.

Reasons, in order of weight:

1. **A full-parity gate would land cold and red.** 4 594 Arabic findings would fail `frontend-lint`
   on every event that starts the workflow, on every open lane, from the merge onward. The brief's own
   sequencing rule is explicit: *"New gates land observe-first or baselined, never cold."* A gate
   nobody can pass gets disabled, which is how detectors die.
2. **The gaps are a translation-programme state, not a code defect.** 23 namespaces have no Arabic
   bundle at all; `i18n.ts:288-290` says so in a comment ("Non-AutoSpecs namespaces still fall back to
   EN until IziPOS localization closes those gaps (tracked separately)"). This package's remit is
   enforcement, not translation.
3. **Ratcheted ≠ exempt, and the ratchet is real.** The baseline is removal-only against an
   OWNER-PINNED protected blob, so (a) no existing Arabic gap can regrow once burned down, and (b)
   **any new English key added to a namespace Arabic is WIRED into fails CI immediately** unless it is
   translated — which is the actual enforcement goal. The matched-growth tamper (plant a gap, add its
   baseline entry) fails by construction because the comparison is against the pinned blob, not the
   editable file.

   **Scoping, stated exactly (M1 round-1 finding 6 — the round-1 wording said "already partially
   covers", which was both vague and, under the original per-key design, wrong).** New English keys
   behave differently per namespace class, and this is the line the M3 announcement must carry:

   | ar namespace class | count | New English key there → |
   |---|---|---|
   | `own` (`arX`) or `english-spread` (`{...enX, ...arX}`) | 33 | **CI FAILS** until `ar` authors it |
   | `en-aliased` (wired to the English bundle) | 23 | **CI passes** — the namespace carries ONE `aliased` entry, not per-key entries |

   Under the round-1 per-key design *every* new English key anywhere failed CI without an Arabic
   translation — an effective full-parity-on-every-new-key policy that contradicts the repo's standing
   en+fr posture. The `aliased` entry type (see §10) removes that: an unwired namespace is one fact,
   not N facts, so adding English keys to it changes nothing.
4. **The alternative was considered and rejected:** gating `ar` at full parity while baselining `en`
   and `fr` would be the same gate with a different, unenforceable threshold.

**Consequence the owner should see:** burning the Arabic baseline down is a translation deliverable
that nothing in this package schedules. It is visible, counted, and shrink-only — that is all this
gate claims.

### (4) i18next JSON v4 plural convention — the 8 English findings are real

`i18next@25` with no `compatibilityJSON` option uses **JSON v4** plural resolution: the singular form
is `key_one`, not the bare `key`. Eight English families author `key` + `key_other` (the pre-v4
shape), e.g. `batches:batchCount` + `batchCount_other`. The bare key still renders through the
generic missing-key fallback, so nothing is visibly broken — which is exactly why it went unnoticed.
These are baselined, not fixed here (fixing them is a translation-file change outside this package).

Full list: `batches:batchCount_one`, `batches:expiryWriteOff.confirm.message_one`,
`batches:expiryWriteOff.selectedCount_one`, `documentIngestions:review.pages_one`,
`finance:overview.upcoming.daysUntilDue_one`, `pos:receiptReporting.refunds.alertCount_one`,
`stock-transfers:create.batch.allocated_one`, `treasury:repositories.movements.subtitle_one`.

Categories are derived at runtime from `new Intl.PluralRules(locale).resolvedOptions()`, never
hardcoded (verified on this machine: `en → [one, other]`, `fr → [one, many, other]`,
`ar → [zero, one, two, few, many, other]`).

### (5) ⚠️ DECISION — 2(d) deliverable 4 lands as a **discrete step**, not a new job

The brief leaves step-vs-job to the executor, "justified in the decision doc". Chosen: a discrete
step inside `frontend-lint` running `pnpm test:eslint-rules && pnpm test:tools`.

- It satisfies deliverable 3's own convention — *"in the same CI lane as the detector"* — literally:
  the detectors (`audit:keys`, `audit:design-system`, `audit:quantity`, `audit:i18n`) and their
  liveness tests are steps of one job.
- It **inherits `frontend-lint`'s `all-checks-pass` membership** (`ci.yml:1108`), so the H-9
  aggregate-membership obligation is met with no `needs` edit — one less line of the aggregate for
  the UI Wave 0 lane to conflict with on rebase.
- A separate job would re-pay checkout + pnpm install + node setup (~4 steps) for two fast suites.

### (6) ⚠️ DEVIATION — one production type change, required to make the wiring land green

`pnpm test:tools` was **red at `base_sha`**: `tools/__tests__/offset-pagination-meta-consolidation.test.mjs`
fails on `features/treasury/statements/api.ts:112`, which redeclares the offset-pagination meta shape
inline instead of reusing `OffsetPaginationMeta`.

This is the package's own thesis proving itself: that guard has run in **no workflow**, so it rotted
in silence (the offending file was last touched 2026-07-20).

Wiring a knowingly-red suite into `frontend-lint` would turn every open lane red at merge — the exact
harm the quiet window exists to prevent — so the violation is closed here:

```diff
+import type { OffsetPaginationMeta } from '@/types/pagination'
 export interface StatementListResponse {
   data: BankStatementSummary[]
-  meta: { current_page: number; last_page: number; per_page: number; total: number }
+  meta: Pick<OffsetPaginationMeta, 'current_page' | 'last_page' | 'per_page' | 'total'>
 }
```

> **This block shows the SHIPPED shape.** The M1 round-1 gate correctly rejected a first
> attempt that used the bare `OffsetPaginationMeta`, which would have declared `from`/`to`
> that `BankStatementController::index` never emits — see §M1(14). Quote THIS diff in the
> M3 announcement, not the round-1 one.

Scope justification and containment: type-only; one interface; the type is a **response** type
(`api.get<StatementListResponse>`, `api.ts:178`) with **no construction sites** (`grep -rn
'StatementListResponse' src/` → 3 hits, all declaration/return/type-argument); `pnpm typecheck`
green; the test itself is the authority on what "correct" means here. **Recorded as a deviation
from the brief's guard-only posture.** No behaviour changes.

### (7) 2(d) deliverable 2 — ownership check before authoring

The brief's instruction is "check what exists in `tools/__tests__/` first; extend, don't duplicate".
All three named audit scripts **already** carry planted-violation tamper tests at `base_sha`:

| Script | Test file | Planted-violation coverage present at base |
|---|---|---|
| `audit-tanstack-keys.mjs` | `tools/__tests__/audit-tanstack-keys.test.mjs` (524 lines) | e.g. "flags bare array queryKey with no scope", "flags an unscoped queryKey inside useQueries.queries[]" |
| `audit-design-system.mjs` | `tools/__tests__/audit-design-system.test.mjs` (215 lines) | e.g. "flags bespoke page h1 headers", "flags raw form controls…", "separates baselined and new design-system violations" |
| `audit-quantity-display.mjs` | `tools/__tests__/audit-quantity-display.test.mjs` (200 lines) | e.g. "flags a raw member-rendered requested_qty", "fails on a NEW violation not present in the baseline" |
| `audit-pos-local-cache.mjs` | `tools/__tests__/audit-pos-local-cache.test.mjs` | present |

Nothing was duplicated. The genuinely missing case — **the C6 `Record<X, StatusTone>` detection** — is
**UI Wave 0 task T7's deliverable**, sitting in that wave's **M6 (`status: pending`)**. Under the same
ownership rule as 2(a) (F-3: never both lanes authoring the same artifact) it is **NOT authored here**;
the dependency is recorded and carried into the M3 announcement checklist. `STATUS_RE` is byte-identical
on the UI branch and at this base, confirming T7 has not landed.

### (8) Observation recorded, not fixed — `no-untranslated-literal` colon-form keys

Writing the RuleTester surfaced that the rule's "looks like a code token" heuristic excludes `.`, `_`
and `/` forms but **not** the `ns:key` colon form: a raw `<span>common:save</span>` is flagged as
untranslated copy. This package ships liveness tests and does not change rule behaviour, so the case
is pinned as documented-current-behaviour in `no-untranslated-literal.test.mjs` with an inline note.

### (9) Accepted coupling

The i18n checker reads the non-authoritative mirror pin from
`docs/handoff/progress/enforcement-p2.progress.yaml`. If that file is later moved or deleted, the gate
fails closed. That is the mirror-drift check the brief mandates (gate-r3 R3-C-1); the coupling is
recorded so a future mover knows the checker's `--mirror` default must move with it.

---

## M1 fix round 1 — response to `docs/handoff/reviews/enforcement-p2/M1-round1.md`

Verdict at round 1: **CHANGES-REQUIRED** (2 P1, 3 P2, 6 P3). Every finding was verified against the
code before acting; all were correct. Dispositions below.

### (10) ⚠️ F-1 (P1) — provenance was computed and then discarded → the `aliased` finding type

**The defect, confirmed:** `classifyAssignment()` produced `kind`, and `auditRoot()` never read it.
Coverage came purely from the file on disk. Live consequence: `src/locales/ar/catalog.json` authors
261 leaf keys while `src/lib/i18n.ts:397` wires `catalog: enCatalog` under `ar` — Arabic users are
served English for the whole namespace, and the audit reported only 22 gaps. Worse, it was a live
**ratchet bypass**: the Arabic baseline could be "burned down" by dropping unwired JSON files into
`src/locales/ar/`, shrinking the baseline and rotating the pin while not one string changed for an
Arabic user. That is the vacuous-parity failure gate-r1 H-5 exists to forbid, reached from the
opposite direction.

**Fix, and where it departs from the reviewer's suggested shape.** The reviewer proposed: for an
`en-aliased` `(locale, ns)`, treat **every** English key as `missing`. That enforces the invariant but
detonates finding 6 — it makes every new English key in any of the 23 unwired Arabic namespaces an
instant CI failure. Shipped instead: a **new finding type `aliased`, one entry per aliased
`(locale, namespace)`** (`ar|catalog|aliased|*`), with no per-key entries and no plural checks inside
an aliased namespace. This:

- enforces the H-5 invariant exactly as required — an aliased namespace is untranslated no matter what
  is on disk, so the file-dropping bypass is closed;
- is **strictly stronger** as an invariant than per-key entries: the entry clears only when a real
  bundle is **wired**, never by adding files;
- resolves finding 6 rather than trading it, and states the true blast radius in §3's table;
- reports the scale that per-key entries conveyed, in the summary line rather than the baseline
  (`English-aliased namespaces — ar: 23 ns / N keys served in English`).

`unknown` (an assignment shape the classifier does not recognise) is treated as aliased — fail closed.

**Residual, RESTATED after rounds 4 and 5 (the original wording understated it — see §M1(37)):** for
`english-spread` namespaces the audit trusts the locale file for any subtree the spread graph does not
contradict. Rounds 3–5 closed three of the four shapes that defeat this (English-last at top level,
English-last in a nested sibling, and a nested literal spreading only English). **The surviving shape
is a nested subtree assigned WITHOUT a spread at all** — `sections: enSettings.sections` as a bare
property. That is per-subtree and total, not per-key: the whole subtree is served in English while the
locale's keys for it sit dead on disk. Closing it needs a property-assignment arm in the classifier
(reading `ns.sub: enX.sub` as an alias of that subtree), which is a genuine scope call for whoever
extends this — recorded, not silently carried.

### (11) F-2 (P1) — the alias fixture passed for the wrong reason

Confirmed: the fixture shipped no `locales/ar/alpha.json` at all, so
"counts the English-aliased namespace as fully UNTRANSLATED" was proven by **file absence**, not by
aliasing — the brief's mandatory production-shaped case was non-discriminating, and deleting the whole
`kind` mechanism would have broken no behavioural assertion. This also failed the checklist in the
package's own `08-DETECTOR-LIVENESS.md`.

Fixed: `locales/ar/alpha.json` now authors **every** English key while `i18n.ts` keeps
`alpha: enAlpha` under `ar`. **Red-first evidence, against the pre-fix scanner at `3615b103b`:**

```
$ git show 3615b103b:apps/web/tools/audit-i18n-completeness.mjs > /tmp/old-audit-i18n.mjs
=== PRE-FIX SCANNER (commit 3615b103b) against the NEW discriminating fixture ===
kind ar.alpha = en-aliased
ar|alpha findings: []
  ^ EMPTY: a namespace the runtime serves in ENGLISH is credited as fully translated
```

### (12) F-3 (P2) — the surface-coverage invariant

Confirmed: `parseI18nWiring` is a line-oriented parse, a locale that stops matching is simply absent
from `wiring.locales`, and every one of its baseline entries then lands in `stale` — a `console.log`
that is explicitly never a failure. A prettier pass collapsing the `ar:` block would have made ~2 900
Arabic findings disappear **and be announced as burn-down progress**.

Three fixes: (i) `missingScannedSurface()` — every `locale|namespace` in the **pinned protected**
baseline must still be inside the scanned surface, else FAIL CLOSED (the protected blob is the one
description of the surface no candidate can edit); (ii) structural failure when a `locales/<locale>/`
directory has no parseable `resources` block; (iii) structural failure when a namespace wired under
`en` is missing from the `ns` array.

### (13) F-4 (P2) — `pnpm lint` broke for every developer, and preflight still never ran the detector

Both halves confirmed. `pnpm lint` gained `audit:i18n`, which fails closed without the repository
variable no developer machine has; and `scripts/preflight.sh` — the gate CLAUDE.md rule 10 makes
mandatory — never invoked the `lint` chain at all (it runs discrete steps and, for rule tests, only
the **POS** half). So the brief's "add it to the local lint chain for preflight parity" assumed
chain ≡ preflight; it is not.

Fixed with `scripts/i18n-baseline-authority.sh`: it reads the mirror pins, re-derives the blob from
the reviewed seed commit (`git rev-parse <seed>:<baseline-path>`), **asserts derived == mirror**, then
execs the checker with the value exported. `pnpm lint` now calls `audit:i18n:local` (the wrapper);
**CI keeps calling `audit:i18n` with `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}`**. `preflight.sh` gains
two discrete steps: the i18n gate (through the wrapper) and the **web** rule tests.

**Not a bypass:** the wrapper supplies the same value from the same reviewed seed commit and cannot
make CI pass. A candidate editing mirror + baseline together still fails CI (variable ≠ mirror →
MIRROR DRIFT; the variable's blob ≠ the working file → RATCHET GROWTH). It removes only the
developer-machine hard-fail, and it fails loudly on seed/mirror drift.

### (14) F-5 (P2) — the type change declared fields the endpoint never emits

Confirmed and my §6 justification was wrong on the facts. `BankStatementController::index`
(`apps/api/app/Modules/Treasury/Presentation/Controllers/BankStatementController.php:53-58`) emits
exactly `current_page / last_page / per_page / total`; widening to the full `OffsetPaginationMeta`
asserted `from`/`to` that are `undefined` on the wire — a false contract introduced by a guard-only
package. Now `Pick<OffsetPaginationMeta, 'current_page' | 'last_page' | 'per_page' | 'total'>`, which
the consolidation guard also accepts (it flags duplicate inline type **literals** of the four core
fields; a `TypeReference` is not one). `pnpm typecheck` green; the guard's 3 tests green.

### (15) F-7 (P3) — pin-tag drift between `ci.yml` and the progress YAML

Added a tools test asserting `ci.yml` fetches exactly the tag named in `i18n_baseline_pin_tag`. Desync
already failed closed in CI, but only after a wasted gate round.

**Pin-tag name after this seed-changing fix round: `ci-pin/enforcement-p2-r1`, UNCHANGED.** The
never-reuse rule binds tags that have been *created*; `git tag -l 'ci-pin/*'` is still empty, so the
pre-allocated name has never been used and remains the correct `n = 1` allocation.

### (16) F-8 (P3) — the checker's own authority path was untested

Confirmed: the suite exercised only pure helpers; `main()` — unset variable, mirror drift, unfetchable
blob, `git cat-file`, exit codes — had no coverage, in a package whose thesis is that untested guards
rot. Added 8 CLI tests that run the **real script** via `execFileSync` against the fixture root, with
the protected baseline written as a **genuine git blob** (`git hash-object -w --stdin`) so
`git cat-file blob` resolves exactly as it will in CI. And the `--no-ratchet` trapdoor is **deleted**
(a one-word bypass of the trust anchor), with a test asserting it stays deleted.

### (17) F-9 (P3) — a false motivating fact in a permanent convention doc

Confirmed. `vitest.config.ts:11` includes `tools/**`, and `frontend-test` runs `pnpm test`
(`ci.yml:988`) — but only on PR→main / push→main / `workflow_dispatch`. So the tools suite was
**PR→dev-dead**, not CI-dead; the `test:eslint-rules` half of the claim was correct (no workflow at
all). Both `08-DETECTOR-LIVENESS.md` and §6 above now state this precisely.

### (18) F-10 (P3) — rule-test completeness and the pinned false positive

`no-untranslated-literal` claims five user-facing attributes; three were covered. Added `alt` and
`label` (now 10 valid / 6 invalid). The pinned colon-form false positive now carries an explicit
TICKET pointer to §M1(8) of this document, so deleting that case when the rule is fixed reads as
intended rather than as a regression.

### (19) F-11 (P3) — one-directional key comparison

Accepted as noted; no action. `fr` orphans stay out of scope, recorded in §2.

### (20) Baseline regeneration and re-pin

F-1 changes what the scanner reports, so the seed baseline is regenerated under the **same two-step
topology** the protocol requires for a seed-changing fix round (gate-r4 R4-H-3): a seed-revision
commit containing only the baseline, then a distinct metadata commit updating
`i18n_baseline_seed_commit` / `i18n_baseline_protected_blob`. A single self-identifying fix commit is
forbidden. Both are re-reviewed at round 2.

---

## M1 fix round 2 — response to `docs/handoff/reviews/enforcement-p2/M1-round2.md`

Verdict at round 2: **CHANGES-REQUIRED** — but with **0 P1**, and all five round-1 findings verified
CLOSED against the code (the reviewer re-ran the probes rather than reading my report). One P2 and two
P3 remain. **All three fixes are BASELINE-NEUTRAL** — the regenerated baseline is byte-identical to
the pinned one (2 917 entries, `git diff` empty), so this round does **not** re-create the two-commit
seed topology and the pins stay at `cb618c12c` / `da151bbc5…` / `ci-pin/enforcement-p2-r1`.

### (27) ⚠️ R2-1 (P2) — the classifier read COMMENTS, so one comment could neuter the H-5 invariant

Confirmed, and the sharpest finding of the wave. `parseI18nWiring` captured the whole remainder of the
assignment line into `raw` — comments included — and `classifyAssignment` scanned that text for
locale-prefixed identifiers. So a **comment** counted as wiring:

```ts
alpha: enAlpha, // TODO: swap to arAlpha once the bundle lands
```

flips `ar.alpha` from `en-aliased` to `english-spread`. The audit then trusts the locale file, the
whole-namespace `aliased` entry falls into `stale` — and `stale` is a `console.log`, never a failure —
so **the loss of the invariant is announced as burn-down progress**. That is byte-for-byte the failure
mode `missingScannedSurface` was added to prevent, arriving through the one door it does not watch: it
verifies the `locale|ns` pair is still *scanned*, never that its *kind* is still honest.

Live blast radius: at HEAD `ar/catalog.json` holds 261 of 283 keys, so the same comment on
`i18n.ts:397` still leaves 22 fresh `missing` findings — **production is protected today only by an
incomplete translation file, not by the detector**. Author those last 22 Arabic keys (an ordinary,
wanted deliverable) and the comment clears the whole `catalog` namespace while `i18n.ts` still serves
`enCatalog` to every Arabic user. The likely real-world arrival is a revert comment
(`catalog: enCatalog, // reverted from arCatalog, RTL layout broken`).

Fixed: `stripComments()` removes `//…` and `/*…*/` before classification. `raw` is retained for
diagnostics; a new `code` field carries the stripped text and is what the classifier reads.
**Baseline-neutral by measurement:** zero production assignments contain a comment today
(`assignments containing comments: 0` across 3 locales × 56 namespaces).

**Red-first**, against the pre-fix scanner at `29b6043bd` on the new `comment-tamper` fixture — which
differs from `prod-shaped` by exactly one trailing comment:

```
=== RED-FIRST: pre-fix scanner (29b6043bd) on the comment-tamper fixture ===
kind ar.alpha = english-spread
ar|alpha findings: []
  ^ the aliased entry is GONE — one comment neutered the H-5 invariant

=== GREEN: fixed scanner on the same fixture ===
kind ar.alpha = en-aliased
ar|alpha findings: ["ar|alpha|aliased|*"]
```

### (28) R2-2 (P3) — the pinned surface cannot protect a namespace that has no pinned findings

Confirmed. `missingScannedSurface` derives the expected surface from the protected baseline's entries,
so a namespace with **no** pinned finding has nothing to be missed; and the `en`-block structural check
is escaped by removing the namespace from that block too. The 12 exposed namespaces are precisely the
fully-translated ones — `validation, expenses, income, workshop-work-orders, scheduling,
vehicle-ownership, pickers, vouchers, channels, replenishment, admin, notifications` — i.e. the ones
whose regression the gate most wants to catch.

Closed with the reviewer's suggested backstop: every `locales/en/<x>.json` must have a namespace in the
`ns` array, or be listed in `KNOWN_UNWIRED_LOCALE_FILES` with a reason.

**This immediately found a real orphan.** `users.json` exists in `en` and `fr` (4 keys) and is
**completely dead**: not in the `ns` array, imported by nothing (`grep -rn 'users.json' src/` → no
hits), and with no `t('users:…')` or `useTranslation('users')` callsite anywhere. Deleting translation
files is not this package's remit (guard-only), so it is recorded as the single documented exception
rather than removed. **🎫 Owed elsewhere: delete `src/locales/{en,fr}/users.json` and its
`KNOWN_UNWIRED_LOCALE_FILES` entry together.**

### (29) R2-3 (P3) — a key ending in a CLDR category name is not automatically a plural

Confirmed as PLAUSIBLE with no live instance, and closed in code rather than only in the announcement,
because the failure mode is a hard CI failure for an innocent lane with **no escape short of an owner
re-pin**: a new `wizard.step_one` would demand `step_other` in `en`, `_many` in `fr` and six forms in
`ar`.

A family now qualifies only on real i18next evidence — **`{{count}}` in an authored value, OR an
`_other` form plus at least one other category**. Both signals are needed, and the production tree
proves why:

| Case | Evidence | Verdict |
|---|---|---|
| `fr` `partners.countLabels.customer_one`/`_other` | no `{{count}}` (the number renders separately) | family, via the `_other` rule |
| `en` `batches.batchCount_other` | only ONE suffixed form | family, via `{{count}}` |
| `wizard.step_one` + `step_two` | two categories, **no `_other`**, no `{{count}}` | **not** a family |
| `tier_two` alone | one category, no `{{count}}` | **not** a family |

A `{{count}}`-only rule was tried first and **rejected**: it silently dropped the three genuine
`fr|sales|plural|partners.countLabels.*_many` findings. The shipped rule regenerates a baseline
**byte-identical** to the pinned one, so it removes a false-positive class without weakening any live
detection. New `edge-cases` fixture pins all four rows.

---

## M1 fix round 3 — response to `docs/handoff/reviews/enforcement-p2/M1-round3-attempt2.md`

Round 3 attempt 1 was a **tool error**, not a review: `scripts/adversarial-review.sh` exited 3 with
`no stdin data received in 3s`. The stub is preserved at `M1-round3.md`; the real round-3 register is
`M1-round3-attempt2.md`, produced by re-invoking the same command with `< /dev/null`. Per the harness a
tool error is fail-closed and never a pass; it is not a substantive fix round, so it did not consume a
`fix_rounds` increment.

Verdict at round 3: **CHANGES-REQUIRED** — 0 P1, 1 P2 (required), 1 further required item, 7 notes.
All three round-2 findings verified CLOSED by re-execution. **Every round-3 fix is again
BASELINE-NEUTRAL** (regenerated baseline byte-identical, 2 917 entries), so no seed revision, no
two-commit re-pin, no new pin tag.

### (30) ⚠️ R3-1 (P2) — spread ORDER decides who wins, and the classifier ignored it

Confirmed, and the sharpest finding of the wave. `classifyAssignment` decided `english-spread` from
*which* identifiers appeared, never from their **order**. In `{ ...arX, ...enX }` English is applied
last and wins **every** key — nothing the locale authored is ever served — yet the audit then trusted
`locales/<locale>/<ns>.json` wholesale.

Measured on the production tree, a single-token edit to `src/lib/i18n.ts`:

```
    notifications: { ...arNotifications, ...enNotifications },

PRE-FIX  kind ar.notifications = english-spread ; ar|notifications findings: 0
POST-FIX kind ar.notifications = en-aliased     ; ar|notifications findings: ["ar|notifications|aliased|*"]
```

`ar/notifications.json` holds 20 keys, none absent from `en`, so pre-fix **every Arabic user would see
English for the whole namespace with the detector silent**.

Why this is worse than a static parsing limitation, and why my round-1 residual disclosure did **not**
cover it (the reviewer is right): that residual scoped out *"a key authored under a subtree that is
not spread"* — bounded, per-key. This is **total, per-namespace, and a regression vector** (an edit,
not a pre-existing shape). Worse, it converges on the bad outcome: "spread `en` last so untranslated
keys fall back" is a natural — and wrong, `fallbackLng: 'en'` already does it — edit, and for a new
namespace the `missing` findings *force* the locale file to be completed, after which the gate goes
green over a namespace that is entirely English.

Fixed with `spreadsWithDepth()` + order-aware classification: an `...en*` spread applied AFTER a
same-locale spread **at the same brace depth** classifies the namespace `en-aliased`. Depth-aware
because the production graph nests (`sections`, `company`, `fraudSettings`, `overview.upcoming.buckets`).

**Baseline-neutral by measurement:** production is uniformly `en`-first at every depth, so zero live
findings move. New `spread-order` fixture pins it — and authors **every** English key in
`locales/ar/beta.json`, so a file-based diff would report full parity; the assertion is that the audit
reports `ar|beta|aliased|*` anyway. Red-first against `0e9e18540`: `english-spread`, no `aliased` entry.

### (31) ⚠️ R3-2 — the named deliverable-4 tamper proof was genuinely missing. Run and pasted now.

Correct and fairly caught: the brief (`:348`) requires *"plant a deliberately failing rule test → run
the exact command the CI step invokes → it fails; revert → green; paste both outputs."* §M1(5) argued
step-vs-job and §M1(7) enumerated *pre-existing* audit-script tamper tests (deliverable 2 — a different
requirement). Neither was this proof. It was executed during M1 but never recorded, which is the same
thing as not having it.

**RED** — planted an invalid case the rule does not flag (`parseFloat(width)`, a non-monetary name):

```
$ pnpm test:eslint-rules && pnpm test:tools
EXIT=1
AssertionError [ERR_ASSERTION]: Should have 1 error but had 0: []
```

**GREEN** — reverted, same command:

```
$ pnpm test:eslint-rules && pnpm test:tools
EXIT=0
no-dead-tailwind-token-interpolation: all RuleTester cases passed (5 valid, 5 invalid)
no-hardcoded-step:                    all RuleTester cases passed (6 valid, 3 invalid)
no-literal-decimal-places:            all RuleTester cases passed (6 valid, 3 invalid)
no-parsefloat-on-money:               all RuleTester cases passed (8 valid, 5 invalid)
no-untranslated-literal:              all RuleTester cases passed (10 valid, 6 invalid)
no-hardcoded-entity-route:            all RuleTester cases passed (8 valid, 4 invalid)
 Test Files  7 passed (7)
      Tests  140 passed (140)
```

The `&&` in the CI step propagates the non-zero exit — the new step is not a decorative green.

### (32) R3-3, R3-4, R3-5 (P3) — closed in the same touch

- **R3-3 orphan backstop watched only `locales/en/`.** Deleting the English file along with the wiring
  escaped it, orphaning the other locales' files unscanned. Now unions filenames across **every**
  locale directory and names the offending files.
- **R3-4 `KNOWN_UNWIRED_LOCALE_FILES` was an untested silencer.** A test now pins it to exactly
  `{'users'}` — per this package's own `08-DETECTOR-LIVENESS.md` checklist, the silencer goes red when
  it grows. A second test asserts the backstop is not `en`-only.
- **R3-5 `countBraces` ran on `raw`, not the stripped `code`.** A comment carrying an unbalanced brace
  desynchronised the line walk (38 structural failures when probed — fail-closed, but the
  comment-blindness was only half-applied). Both the initial and the continuation count now run on
  `stripComments(...)`.

### (33) R3-6 to R3-9 (P3 notes) — dispositions

- **R3-6 plural narrowing loses a family authoring exactly one non-`_other` form with no `{{count}}`.**
  Accepted, `lost: 0` verified live. The trade is deliberate: a false positive on `step_one` is a hard
  CI failure for an innocent lane with no escape short of an owner re-pin; this narrowing is silent and
  mostly re-surfaces as a `missing` finding from the English side. **Named out loud in the M3
  announcement.**
- **R3-7 `ar=4702` counted keys behind aliases.** Output now reads
  `ar=4702 authored (1998 behind aliases)`, so the two numbers no longer read as a partition.
- **R3-8 preflight ran only half the CI step.** `scripts/preflight.sh` now runs **both**
  `pnpm test:eslint-rules` and `pnpm test:tools`, matching the CI step exactly.
- **R3-9 the deviation record still showed the rejected shape.** §M1(6)'s diff now shows the shipped
  `Pick<>` form with a pointer to §M1(14), so the M3 announcement cannot quote the wrong one.

---

## M1 fix round 4 — response to `docs/handoff/reviews/enforcement-p2/M1-round4.md`

Verdict: **CHANGES-REQUIRED** — 0 P1, 2 P2, 3 P3; **finding 1 only** was required. All other round-3
findings verified CLOSED by re-execution. **Baseline-neutral again** (byte-identical, 2 917 entries):
no seed revision, no two-commit re-pin, pin tag unchanged.

### (34) ⚠️ R4-1 (P2) — my round-3 order fix was keyed by DEPTH; siblings collapse

Correct and important. `classifyAssignment` grouped spreads by **brace depth** and kept only the last
index per prefix, so sibling object literals inside one namespace — which all share a depth — masked
each other: a later English-**first** sibling overwrote the record of an earlier English-**last** one.
My own comment ("English-last at ANY brace depth") was false as implemented.

This is the shape production actually uses: `settings` has `sections`/`company`/`locations` siblings,
`finance.overview` has `cash`/`upcoming`/`trend`. So round 3 closed the top-level case and left the
realistic one open.

Measured on a copy of the real `apps/web/src`, reversing ONLY the nested `settings.sections` sibling:

```
PRE-FIX  (007b1a49c, depth-keyed)   kind ar.settings = english-spread   aliased entry? false
POST-FIX (scope-keyed)              kind ar.settings = en-aliased       aliased entry? true
```

Fixed by replacing `spreadsWithDepth()` with **`spreadsByScope()`** — a scope-id stack that gives each
individual object literal its own bucket — and comparing order **within a scope**. New
`sibling-scope` fixture carries two siblings at the same depth (one reversed, one normal) and asserts
the reversal is still caught; a second assertion pins `prod-shaped` (all English-first) as
`english-spread` so the fix cannot degenerate into flagging everything.

**Lesson recorded:** rounds 3 and 4 both fixed the *same* invariant through a different door
(comments, then top-level order, then sibling order). The invariant is "English must not be what the
runtime serves for a namespace the gate calls translated", and each round found another wiring shape
that defeats it. That is the detector-rot class this package exists to close, met inside the package.

### (35) R4-2 (P2) — the orphan-union fix was pinned by a source grep, not by behaviour

Correct, and it violated this package's own checklist (`08-DETECTOR-LIVENESS.md`: *"delete the
detection branch → the test goes red"*). The only assertion was
`expect(src).not.toMatch(/const enLocaleDir/)` — renaming the variable would have kept it green.

Replaced with a **behavioural** test: the `sibling-scope` fixture now carries
`locales/{ar,fr}/zeta.json` with **no `en` counterpart**, and the test asserts both filenames and the
namespace name appear in `structural`. An `en`-only `readdirSync` fails it under any variable name.

### (36) R4-3, R4-4, R4-5 (P3) — dispositions

- **R4-3 the pin tag is hardcoded in `ci.yml`.** True and by design (CI must fetch a literal ref).
  Every future re-pin allocates a never-reused name and must edit `ci.yml` and the YAML **in
  lockstep**; desync fails closed and is caught by the consistency test. **Added to the M3
  announcement** so the next re-pinner knows both files move together.
- **R4-4 none of this wiring has ever run on a real runner.** Accurate and unavoidable — the executor
  never pushes (H-7). The three first-execution surfaces are the pin-tag fetch under a depth-1
  checkout, the `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}` mapping, and `pnpm test:tools` under
  `frontend-lint`'s installer. **The handback names all three step ids explicitly** so the owner
  verifies steps, not just job-level green — and records that BOTH owner prerequisites (repository
  variable AND the annotated tag at exactly the accepted SHA) must exist **before** the merge lands.
- **R4-5 the gate anchors CI on a handoff artifact.** True: the mirror path is
  `docs/handoff/progress/enforcement-p2.progress.yaml`. Added a **"THIS FILE IS A CI INPUT — DO NOT
  MOVE"** banner at the top of that YAML naming every file that must move with it (checker default,
  `i18n-baseline-authority.sh`, the consistency test).

---

## M1 fix round 5 — response to `docs/handoff/reviews/enforcement-p2/M1-round5.md`

Verdict: **CHANGES-REQUIRED** — 0 P1, 1 P2 (required), 4 P3. `fix_rounds` 4 → 5, the last round
`max_fix_rounds` allows. **Baseline-neutral** (byte-identical, 2 917), so no seed revision, no re-pin,
pin tag unchanged.

### (37) ⚠️ R5-1 (P2) — my round-4 fix was a net DETECTION LOSS

The sharpest possible review outcome: the round-4 scope-keying **removed** a detection the commit it
replaced had. Depth-keying compared the *last* `en` index against the *last* own index across every
literal sharing a depth, which accidentally covered a second shape — **a nested literal containing
only `...en*`, sitting after a locale-spread sibling**. Scope-keying buckets per literal, so a scope
with `en` and no own spread matched nothing and the namespace fell through to `english-spread`.

Measured on a copy of the real tree, deleting `...arSettings.locations` from `src/lib/i18n.ts`:

```
007b1a49c (depth-keyed)   kind ar.settings = en-aliased      CLI: "1 NEW gap … ar|settings|aliased|*"  RED
2085fbdf8 (scope-keyed)   kind ar.settings = english-spread  CLI: "i18n completeness OK"  EXIT=0        GREEN  ← regression
HEAD      (+ predicate)   kind ar.settings = en-aliased      aliased entry present                      RED again
```

The arrival is a revert — `// RTL broken, drop ar locations` — after which `settings.locations` is
served 100% in English, `ar/settings.json`'s `locations.*` keys are dead, and the gate that went red
on that edit yesterday passes today.

Fixed with one predicate: inside `classifyAssignment`, a **nested** scope (`depth > 1`) holding an
`en` spread and **no** own spread returns `en-aliased`. **Provably neutral** — swept the whole
production graph and all five existing fixtures for that shape: zero matches, baseline byte-identical.

Pinned by a **third sibling** in a new `english-only-subtree` fixture, placed **after** a normal
sibling (first position would also pass under the old code, so it would not be a red-first proof), and
with the reversed sibling removed so the english-only literal is the only signal. Red-first against
`2085fbdf8`: `english-spread` → `en-aliased`.

**Lesson, recorded plainly:** rounds 3, 4 and 5 each closed a different door on ONE invariant —
"English must not be what the runtime serves for a namespace the gate calls translated" — and round 4
opened a door while closing another. A regex-and-heuristics parse of a hand-written wiring graph has
no closure property; each shape must be enumerated. §M1(10)'s residual is restated above accordingly.

### (38) R5-2..R5-5 (P3) — dispositions

- **R5-2 the recorded residual was understated.** Correct. Restated in §M1(10) as *per-subtree and
  total*, with the one surviving shape (a bare-property subtree assignment, `sections:
  enSettings.sections`) named explicitly as the open scope call. Neither depth- nor scope-keying ever
  caught it, so it is a standing limitation, not a regression.
- **R5-3 `lint:ratchet` swallows the lint chain's exit code.** `scripts/lint-ratchet.mjs` runs
  `pnpm --filter @autoerp/web lint` and parses the ESLint summary; a hard failure inside the chain's
  new tail would be invisible to it because the summary line is always present. Direction is safe (no
  false red; the discrete steps do the real gating), and the CI step ordering puts the discrete i18n
  and liveness steps BEFORE the ratchet, so a genuine failure reddens the job first. Recorded, not
  changed — altering `lint-ratchet.mjs` is outside this package's scope.
- **R5-4 housekeeping.** A stray untracked `apps/api/feature-lane-manifest.json` (an M2 dry-run
  artifact) was in the worktree; **deleted**. The M4 hand-over requires `git status --porcelain` to
  show exactly one entry — the untracked handback.
- **R5-5 carry-forward.** None of the CI wiring has executed on a real runner; the three
  first-execution surfaces and the two owner prerequisites are named in §M1(36) and go into the
  handback's event-graph list.

---

## M1 round 6 — STOP condition A (`blocked_review`). Fix rounds exhausted.

Verdict: **CHANGES-REQUIRED** — 0 P1, 1 P2 (required), 4 P3 (all carry-forward/documentation).
`fix_rounds` is **5 of `max_fix_rounds: 5`**. Another fix round would take it to 6, which the harness
defines as STOP condition A: *"A milestone is still CHANGES-REQUIRED after `max_fix_rounds`. Set
milestone `status: blocked_review`, summarize the surviving findings in `blockers:`, STOP."*

**I have NOT applied the round-6 fix.** Doing so would put an unreviewed change into the tree past the
cap and would misrepresent the round-6 register as covering it. The tree at handover is exactly the
state round 6 reviewed.

### The single surviving required finding, in full

**My round-5 fixture edit destroyed the round-4 liveness pin.** Round 5 added a third sibling
`englishOnly: { ...enBeta }` to the **`sibling-scope`** fixture — the fixture whose only job is to pin
the round-4 scope-keying fix. That sibling trips the *new* predicate (b), so the fixture now returns
`en-aliased` for reasons unrelated to scope-keying. The reviewer mutation-tested it: reverting
`spreadsByScope`'s `scope: stack[stack.length - 1]` to `scope: stack.length - 1` (i.e. undoing the
round-4 fix) leaves **every asserted `kind` in the whole suite identical** — nothing goes red — while
the production regression goes silent again:

```
fixture                 HEAD             depth-keyed mutant
prod-shaped             english-spread   english-spread
comment-tamper          english-spread   english-spread
spread-order            en-aliased       en-aliased
sibling-scope           en-aliased       en-aliased      <-- pin is DEAD
english-only-subtree    en-aliased       en-aliased
```

This violates this milestone's own deliverable, `docs/conventions/08-DETECTOR-LIVENESS.md`:
*"The guard has at least one test that FAILS if the guard is neutered."*

### The remedy, pre-verified by the reviewer — a 5-line deletion

1. Delete `englishOnly: { ...enBeta },` **and its comment** from
   `apps/web/tools/__fixtures__/i18n-completeness/sibling-scope/lib/i18n.ts` (the round-5 addition).
   The reviewer verified the restored fixture discriminates again
   (`HEAD = en-aliased` / `depth-keyed mutant = english-spread`).
2. Drop the now-stale sentence in §M1(34) claiming `sibling-scope` "asserts the reversal is still
   caught" — after (1) it does, but the sentence currently describes the broken state.

Predicate (b) keeps its own red-first pin in the dedicated `english-only-subtree` fixture, and
predicate (a) keeps its pin in `spread-order`, so nothing is left unpinned. Fixtures live outside the
audited root: **no reclassification, baseline byte-identical, no seed revision, no two-commit re-pin,
no new pin tag.**

### What the parent must decide

The remedy is smaller than any change already accepted in this milestone, and it is fully specified
and pre-verified. It needs one of:

- **raise `max_fix_rounds` to 6** (a parent/owner edit to the progress YAML) and let the executor apply
  the deletion and run round 7; or
- **apply the deletion as a parent-authorized scoped continuation** and re-run the M1 bridge; or
- **accept the milestone with the finding recorded** as a known-dead liveness pin (not recommended —
  it is precisely the detector-rot class this package exists to close).

### Carry-forward notes (not blockers)

- **R6-2** the bare-property subtree assignment (`sections: enSettings.sections`, no spread) is still
  silent — named as the open scope call in §M1(37); `spreadsByScope` only sees `...ident`.
- **R6-3** predicate (b) is prefix-only: any spread identifier starting with `en` counts as English. No
  live match (all 26 spread identifiers in `i18n.ts` are locale bundles) and the failure direction is a
  loud red finding, never a silent pass. Recorded for the next extender.
- **R6-4** none of the CI wiring has executed on a real runner — the three first-execution surfaces and
  the two owner prerequisites are in §M1(36).
- **R6-5** `lint:ratchet` swallows the lint chain's tail; accepted, the discrete steps gate first.
