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
   **any new English key added to a namespace Arabic already partially covers fails CI immediately**
   unless it is translated — which is the actual enforcement goal. The matched-growth tamper (plant a
   gap, add its baseline entry) fails by construction because the comparison is against the pinned
   blob, not the editable file.
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
+  meta: OffsetPaginationMeta
 }
```

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
