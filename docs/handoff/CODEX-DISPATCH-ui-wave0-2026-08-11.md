# CODEX DISPATCH — UI Audit Wave 0 (audit integrity + zero-decision quick wins)

**Revision:** **r4 — 2026-08-12, execution-mode retrofit** (owner ruling 2026-08-12). **Task content is untouched** — no task, acceptance criterion, evidence rule, grep, baseline contract or scope boundary changed from r3; only the *execution mode* did (this wave now self-gates under the harness instead of handing back between tasks). Itemised in §7 (`r3 → r4`). Builds on **r3 — 2026-08-11, post gate r2** (`docs/superpowers/reviews/2026-08-11-wave0-brief-gate-r2.md`, FAIL on one D3 residual): the three T12/§4 references to "three" intentional `HubCard.test.tsx` `/finance` literals corrected to the verified **seven** (lines 12, 22, 35, 54, 63, 87, 103); nothing else changed. Builds on r2 — 2026-08-11, post gate r1, which supersedes r1 in full. Every change from r1 is itemised in §7 (revision log) against the defect number in `docs/superpowers/reviews/2026-08-11-wave0-brief-gate-r1.md`. Where that review **refuted or corrected** an r1 claim, the review's version is adopted verbatim — it was verified against code and r1 was not.
**Date:** 2026-08-11
**Executor:** Codex desktop session (implementation), running as a **fully autonomous self-reviewing wave** (r4). The parent Claude session performs the **terminal audit** and owns the **merge** — it does not gate you between tasks.
**Lane:** `apps/web` only (plus `.github/workflows/ci.yml`, `scripts/factory/`, `apps/web/tools/`, `apps/web/e2e/`, `docs/`).
**Verification base for every citation in this brief:** `dev` @ `0c00cf526`. Every `file:line` below was re-read at that SHA — they are not copied forward from the audit unchecked. Where the audit's citation was wrong, this brief carries the corrected one and says so. The r2 corrections were additionally verified by the gate reviewer against the same tree.

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between tasks. At the end of every milestone you run the adversarial review YOURSELF by invoking Opus
> through the CLI bridge `scripts/adversarial-review.sh` (it calls `claude -p --model opus`), read its
> register, and loop **scoped** fix rounds until ACCEPT — then move on.
> - **Track state in `docs/handoff/progress/ui-wave0.progress.yaml`** — read it first, update it after
>   every milestone (status, commit SHA, verdict path, fix_rounds). It is the resume point if you crash.
> - **Milestone grouping is an execution-mode construct for the review gates only. It changes no task,
>   no acceptance criterion and no ordering** — it is exactly §2's binding dependency chain
>   (`T2 → {T10,T11,T12,T13} → T3(a) → T3(b) → T9`, and `T1 → T9`), batched into reviewable units:
>   **M0** preconditions · **M1** = T1 · **M2** = T2 · **M3** = T10–T13 (the four deletions) ·
>   **M4** = T3(a)+T3(b) · **M5** = T4 + T15 (the gating pair) · **M6** = T5, T7, T8-c, T14 ·
>   **M7** = T9 · **M8** = the §4 whole-branch gate + the §5 report. "One commit per implementable
>   task" and the two authorised exceptions in §2 are **unchanged**.
> - The bridge call, once per milestone (lenses come from that milestone's `review_lenses`).
>   **Exception — M0 is setup-only:** no bridge call, no adversarial register; M0 completion = YAML
>   `status: passed` with `base_sha` recorded (its `review_lenses` list is empty by design). The
>   first bridge call of the run is M1's. Bridge invocation:
>   ```
>   scripts/adversarial-review.sh \
>     --brief   docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md \
>     --milestone M<n> \
>     --lenses  "<comma list from the milestone's review_lenses>" \
>     --range   <base_sha>..HEAD \
>     --out     docs/handoff/reviews/ui-wave0/M<n>-round<r>.md \
>     --round   <r>
>   ```
>   Exit **0 = ACCEPT** · **2 = CHANGES-REQUIRED** · **3 = tool error → treat as CHANGES-REQUIRED
>   (fail closed; never proceed on a tool error)**. Record the `--out` path in the milestone's
>   `verdict:` and the exit result in `last_verdict:`.
> - Wherever this brief says "the parent session runs the adversarial review gate", "stop and report",
>   or "handback" **for a review** — that is now this self-review loop. ("Stop and report" for an
>   **owner decision** still means STOP: see below.) A milestone is not closed until its register says
>   `VERDICT: ACCEPT`.
> - **`M3` deliberately reviews a drifted manifest.** §2 exception 2 permits the deletion commits to
>   leave `scripts/factory/manifests/` stale and `check-manifest-drift.sh` exiting 1; that is expected
>   at M3 and is **not** a finding. The drift check must pass at **M4** and again at **M8** — and only
>   the final branch state is contractually bound (§4 item 2).
> - **Resume semantics:** a fresh session resumes from the YAML + the newest register under
>   `docs/handoff/reviews/ui-wave0/`. Never re-run a milestone whose `status: passed`.
> - **STOP and escalate only at the three harness STOP conditions:** (A) fix rounds exhausted
>   (`max_fix_rounds: 5`) → `blocked_review`; (B) an **owner gate** → `blocked_owner`; (C) an
>   architecture contradiction → `blocked_architecture`. Set the YAML `status` + `blockers` and end your run.
> - **The owner gates on this wave, each a hard STOP** (full text in the YAML `owner_gates:`):
>   **F-1 / the parked Rafiq directory** — `T6` (`UI-14`) and `T8-b` (`BarcodeHero`) **remain
>   non-executable BLOCKED records**: no commit, excluded from the task count, and the gate-r1
>   reviewer's merge-risk assessment is **not** authorisation. The three baseline lines
>   (`audit-design-system-baseline.json:224-225`, `:665`) must still be **present** at final state.
>   **F-2b / `touchOptimized`** — unassigned: do not implement it, and **neither Wave 0 nor `UI-43` may
>   be reported complete** (§5 item 7). **F-6 / the commit series** — the parent fills
>   `commit_series:` in the YAML **before** dispatch; if it is still `null` at M0, that is
>   `blocked_precondition` and you STOP. Do not invent a phase number. Plus the standing
>   **DO-NOT-TOUCH** set of §1 (incl. `apps/api/**` — a task that appears to need a backend change is a STOP).
> - **Gate wiring, deliberate:** no milestone in the YAML carries an `owner_gate:` field — the harness
>   reads that field as an **unconditional** STOP (harness step 4 / condition B), and these are standing
>   constraints rather than per-milestone stops. Stop only when a stated condition actually fires.
> - **F-3 and F-5 are not stops.** They are user-visible navigation changes (T4 tightens, T15 widens)
>   that must be **stated plainly** at gate time and in the report — acknowledged, never softened.
> - **No clock.** Do not call `date`. Use `git rev-parse --short HEAD` as the `updated:` marker.
> - Per-milestone registers go to `docs/handoff/reviews/ui-wave0/`. **Branch NOT merged, NOT pushed** —
>   the parent Claude session runs the terminal audit and owns the merge into `dev`.

## 0. Read before starting (in this order)

| # | Document | Why |
|---|---|---|
| 1 | `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/00-EXECUTIVE-REPORT.md` **rev 5** | §2 findings register, §4 census reliability, §5 Wave 0 (entry criteria, closes-list, sequencing, the `UI-40` carve-out note). **Authoritative over section files 01–04, which are stale (§4 residual-risk note).** |
| 2 | `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md` | The ruling of record. Adds owner-ruled deletions beyond rev 5's Wave 0. |
| 3 | `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/10-verification-orphans-duplicates.md` | Evidence for the deletions (items 1, 5, 6a). |
| 4 | `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/11-research-finance-hub.md` | Evidence for the `/finance` deletion + the one i18n key that must survive it (§1f). |
| 5 | `CLAUDE.md` rules 1–20 (esp. 4, 7, 11, 12, 13, 18, 21) and `docs/conventions/` | Non-negotiable house rules. |

**Do not read 01–04 standalone.** They carry refuted numbers (`00 §4`). If you need a number from them, re-derive it.

---

## 1. Mission and scope guard

### Mission

Wave 0 is **audit integrity + zero-decision quick wins**. Two classes of work, nothing else:

1. **Audit integrity** — make the tooling that measures this codebase tell the truth (re-census, manifest generator, manifest CI job, design-system detector), so Wave 3's scoping decision rests on real numbers.
2. **Zero-decision quick wins** — changes with no owner decision left open: a fail-open gate contract, two nav-parity defects (one that over-shows, one that under-shows), a cosmetic defect, a dead-code sweep, a doc refresh, the app-name config placeholder, and the four deletions the owner already ruled.

Entry criteria: **none for the fourteen implementable tasks.** Every implementable task in §2 can start immediately.

**Two entries in §2 are NOT implementable tasks.** `T6` (`UI-14`) and `T8-b` (`BarcodeHero`) are **non-executable escalation records**: they are blocked pending a parent/owner ruling (§6 F-1), they produce **no commit**, and they are **excluded from the implementable task count**. Do not treat them as "independently committable". This corrects the r1 statement that nothing in this brief was blocked.

If you find yourself needing an owner ruling anywhere else, stop and report — do not decide it yourself.

### DO NOT TOUCH

| Area | Why | Owner |
|---|---|---|
| `apps/web/src/features/products/editor/**` | Rafiq product-editor skin round 2 sits **unmerged** in a separate worktree; owner ruled **OQ-4 PARKED** (`OWNER-DECISIONS:14`). Editing here creates merge conflicts against work nobody has evaluated. `00 §E` states the same constraint. | parked |
| `apps/web/src/features/finance/pages/LaneSeparationReportPage.tsx` and the `/finance/lane-separation` route (`routes/index.tsx:2010`) | It is an orphan, but it is **claimed by the DN-consolidation build** (`11-research-finance-hub.md:185`, owner ruling "DN-consolidation (13) APPROVED as proposed" → *link lane-separation*). It is **not** a Wave-0 deletion and **not** a Wave-0 wiring. | DN-consolidation lane |
| Anything in the events / shift-variance dossiers: `docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/`, `docs/handoff/FINDINGS-shift-variance-gl-2026-08-11.md`, `docs/handoff/FINDINGS-other-problems-2026-08-11.md` | Owned by the existing fixes session (`OWNER-DECISIONS:71`). | fixes session |
| Any listing-pattern migration (`ListPageLayout`, `FilterBar`, `DataTable`, `OffsetPagination` adoption, badge unification, empty-state unification) | **Wave 3**, and Wave 3 is explicitly gated on T1's re-census landing first (`00 §5`). Measuring is Wave 0; migrating is not. | Wave 3 |
| Removing the `colorClasses` ESLint carve-outs, or migrating the 52 importers | `00 §5` carve-out note: Wave 0 is detector fix + **plan only**. Removing the carve-outs turns 52 quarantined importers into lint failures. | unscheduled |
| `apps/api/**` | No backend change is in this wave. If a task appears to need one, stop and report. | — |

### Two conflicts you must respect (do not resolve them yourself)

Both are flagged in §6. In short: **T6 (`UI-14`) and T8-b (`BarcodeHero`) both target files inside `features/products/editor/`**, which is on the DO-NOT-TOUCH list. Their default disposition in this brief is **BLOCKED — do not implement, report as blocked**. Read §6 before touching either.

### Not dispatched, deliberately

- **`UI-43`'s `touchOptimized` claim** (15 TSX files) is **out of scope** — see T8 and §6 F-2b. **This is an unresolved ownership gap, not a clean deferral:** rev 5 assigns all four split `UI-43` claims to Wave 0 (`00 §5:240-248`), and a size argument is a scheduling recommendation, not a source-backed ownership transfer. It therefore needs an **owner/orchestrator reassignment on the record** — see §6 F-2b. Until that exists, **the handback must not report Wave 0 or `UI-43` as complete.**

**`UI-07` is no longer omitted.** r1 left it unassigned as F-2; r2 dispatches it as **T15**. See §6 F-2.

---

## 2. Tasks

**Sixteen numbered entries. Fourteen are implementable tasks** — `T1`, `T2`, `T3`, `T4`, `T5`, `T7`, `T8` (its `T8-c` claim), `T9`, `T10`, `T11`, `T12`, `T13`, `T14`, `T15` — **and two are non-executable BLOCKED escalation records** — `T6` and `T8-b` (§6 F-1), which produce no commit and are not counted.

Every task is **TDD**: write the failing test first, watch it fail, then implement. A task whose "test" is only a manual grep is not done — where a runtime assertion is impossible (docs, plans), the acceptance criterion is a reproducible command whose output you paste into the handback.

### Dependency chain and manifest ownership (binding — r1's ordering rules contradicted each other)

r1 simultaneously said "everything except T2→T3 is order-free", "T3's manifest commit changes nothing else", "every deletion regenerates the manifest", and "one commit per task". Those four cannot all hold. **The following model replaces all of them.**

**One and only one component owns `scripts/factory/manifests/`: T3.** No other task commits a manifest file.

**Required order:**

```
T2  (generator fix — no manifest change)
 └─> T10, T11, T12, T13  (route deletions — source only, NO manifest regen)
      └─> T3(a)  (single manifest housekeeping commit: regenerate, commit manifests and nothing else)
           └─> T3(b)  (CI drift-guard job commit)
                └─> T9  (docs — consumes T1's report and T3's regenerated manifest)
T1  (independent — but T9 consumes its report, so T1 precedes T9)
T4, T5, T7, T8, T14, T15  (order-free)
```

Only these edges are binding: `T2 → {T10,T11,T12,T13} → T3(a) → T3(b) → T9`, and `T1 → T9`. Everything else is order-free.

**Two explicit exceptions to "one commit per task", both authorised:**

1. **T3 is two commits** — `T3(a)` manifest regeneration (manifests only, nothing else) and `T3(b)` the CI job. This is what makes "T3's manifest commit changes nothing else" satisfiable.
2. **Route-deletion commits (T10–T13) will temporarily leave the manifest drifted, and that is expected and permitted.** Do **not** run `gen-route-manifest.mjs` inside them; do not treat their intermediate `check-manifest-drift.sh` exit 1 as a failure. **Only the final branch state must pass the drift check** (§4 item 2). Each deletion commit must still be green on `pnpm typecheck` / `pnpm lint` / `pnpm test`.

If you believe every commit must independently pass the drift check, **stop and report** — do not unilaterally switch to per-deletion regeneration; that would create four conflicting manifest commits and is the exact ambiguity r1 shipped.

---

### T1 — `CX-4`: component-graph-aware listing re-census (tooling + report)

**Finding:** `CX-4` (P2 / M), `00 §2:130`, `00 §4:232`.

**What.** Rebuild the listing census as a **script**, not a hand count. The current numbers are file-local: a page that delegates its body to an organism was scored on the wrong file, so `UI-28` (pagination) and `UI-29` (empty states) are refuted/unusable and `UI-34` (orphans) is method-limited.

**Evidence.**
- `00 §4:223-228` — the provisional-numbers table: "27 of 46 unpaginated" refuted; "24 of 46 no empty state" **withdrawn from use**; filter sub-tallies internally contradictory (02 `:15` vs `:130`); orphan set method-limited.
- `00 §2:130` (CX-4 row) — worked examples: `ExpenseListPage.tsx:119-170` → `ExpenseList.tsx:20-53`; `BundleListPage.tsx:1-9` → `BundleList.tsx:23-40`.
- `00 §5:248` — "`CX-4` gates all Wave-3 scoping."
- Baseline claims to re-derive: `02-design-system-consistency.md:15-19` (F-1, F-3, F-4, F-5) — treat as the **hypothesis**, not the input set.

**Deliverables.**
1. `apps/web/tools/audit-listing-census.mjs` — a reusable, exported-function-per-stage script (mirror the shape of `apps/web/tools/audit-design-system.mjs`: pure exported functions + a `main`, so it is unit-testable).
2. `apps/web/tools/__tests__/audit-listing-census.test.mjs` — `node --test` style, matching the existing tools tests (`apps/web/tools/__tests__/audit-design-system.test.mjs`).
3. `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md` — the report, with a machine-readable table and a one-line "how to reproduce".

**Required behaviour of the script.**
- **Discovers** the listing-page set itself from `apps/web/src` (do not hardcode a list of 46). State the discovery rule in the script docblock and in the report; if your rule yields a different total than 46, that is a finding — report the delta with the per-page reason, do not force the number.
- **Follows imports one or more levels into organisms** before classifying a page. A page that renders `<ExpenseList />` inherits `ExpenseList`'s pagination/empty-state/filter classification. Resolve relative imports and `@/` aliases; record the resolved file that produced each verdict.
- Classifies, per page: pagination control (which component, or none), empty state (which mechanism: `EmptyState` molecule / `DataTable emptyTitle` / bespoke / none), filter pattern (which of the ~6), `ListPageLayout` usage, `DataTable` usage.
- **Orphan reachability, param routes included.** Re-derive the orphan set from `routes/index.tsx` cross-referenced against every inbound reference (`<Link to>`, `navigate(...)`, sidebar hrefs, command-palette hrefs, hub-card hrefs, breadcrumb parents). `00 §3 Problem 4` shows the two failure modes the old method had — it excluded parameterized routes (missing `CX-2`) and discounted breadcrumb/back-link reachability (overstating `UI-33`/`UI-34`). Both must be handled.
- **Emits an exit code of 0 always.** This is analysis tooling, not a gate. **Do not** add it to `apps/web` `package.json`'s `lint` script (`lint` currently chains `lint:eslint`, `audit:keys`, `audit:design-system`, `audit:quantity`, `test:eslint-rules`) and do not add it to CI. Add an `audit:listing-census` script entry only if it is not chained into `lint`.

**Acceptance criteria.**
- The script runs from a clean checkout: `cd apps/web && node tools/audit-listing-census.mjs` produces the table in under ~30s with no network.
- The report re-derives and **explicitly supersedes** `UI-28`, `UI-29` and `UI-34` with new numbers, each with the method that produced it, and states which of `00 §4`'s provisional rows are now decision-grade.
- The two CX-4 worked examples (`ExpenseListPage` → `ExpenseList`, `BundleListPage` → `BundleList`) are classified via the organism, not the page file — assert this in the tests.
- The report names, per refuted number, whether the new figure is higher or lower and why.

**Tests required (written first).**
- Fixture-based: a synthetic page file that delegates to an organism which renders `OffsetPagination` → expect the page classified "paginated (via organism)". Same for empty state and filter pattern.
- A fixture route tree containing a parameterized route with a single inbound `<Link to={`/x/${id}`}>` → expect **not** orphaned; and one with zero inbound references → expect orphaned.
- A fixture where the only inbound path is a breadcrumb parent → expect **not** orphaned (this is the `UI-33` correction).

**Out of scope.** Migrating anything. Writing the listing canon (that is T9's doc stub + Wave 3). Funding decisions.

---

### T2 — `UI-11`: manifest generator records the wrong component for `/sales/invoices/:id`

**Finding:** `UI-11` (P2 / S), `00 §2:101`, `00 §3 C:179`.

**What.** `scripts/factory/gen-route-manifest.mjs` looks through a fixed allow-list of "pure wrappers" when resolving a route's component. `KeyedByRouteId` — a local wrapper defined inside the router — is **not** on that list, so the generator stops at it and records the wrapper as the component.

**Evidence (re-verified at `0c00cf526`).**
- `scripts/factory/gen-route-manifest.mjs:31-34` — `const WRAPPERS = new Set([...])`: `SuspenseWrapper`, `Suspense`, `RequireAuth`, `RequireAdminAuth`, `ModuleGuard`, `RequirePermission`, `ThemeProvider`, `ErrorBoundary`. No `KeyedByRouteId`.
- `apps/web/src/routes/index.tsx:325` — `function KeyedByRouteId({ children }: { children: React.ReactNode })`.
- `apps/web/src/routes/index.tsx:699-708` — the `invoices/:id` route wraps `<InvoiceDetailPage />` in `<KeyedByRouteId>`.
- **Live proof, not inference:** running `bash scripts/factory/check-manifest-drift.sh` today produces, among the drift, exactly:
  ```
     - path: /sales/invoices/:id
  -    component: InvoiceDetailPage
  +    component: KeyedByRouteId
  ```
  The committed manifest (`scripts/factory/manifests/routes-web.yaml:754-757`) is currently **correct** only because it predates `KeyedByRouteId`. Regenerating before this fix bakes the wrong component in permanently — which is precisely why `00 §5:248` orders T2 before T3.

**Acceptance criteria.**
- `KeyedByRouteId` added to `WRAPPERS`, with a one-line comment naming why (a keying-only wrapper, no visual output — cross-reference `routes/index.tsx:325`).
- After the fix, regenerating produces `component: InvoiceDetailPage` for `/sales/invoices/:id`. Paste the before/after diff hunk in the handback.
- No other manifest entry changes as a result of *this* task (the other drift is T3's).

**Tests required (written first).**
- Extend `scripts/factory/gen-route-manifest.test.mjs` (run: `node --test scripts/factory/gen-route-manifest.test.mjs`) with a fixture route wrapped in `KeyedByRouteId` and assert the resolved component is the inner page. The test must fail before the allow-list change.
- Add a second fixture asserting the *general* contract you rely on: an unknown local wrapper still resolves to the wrapper (i.e. you did not silently switch to "always take the innermost element"). If you choose a more general fix than the allow-list entry, that decision must be stated and tested explicitly — but the allow-list entry is what `00` specifies, and it is the lower-risk change.

---

### T3 — `UI-10`: regenerate the manifest (after T2) + a standalone manifest drift CI job

**Finding:** `UI-10` (P2 / S), `00 §2:100`, `00 §3 C:177-179`.

**What.** Two halves: (a) regenerate and commit the manifests as their own housekeeping commit, **strictly after T2**; (b) add a CI job that runs the drift check **on every lane**, because today nothing does.

**Evidence — the gap is real and narrower than rev 1 claimed.**
- `scripts/preflight.sh:193-195` invokes `bash "$ROOT_DIR/scripts/factory/check-manifest-drift.sh"`. So the check exists and is wired into local preflight.
- **No workflow invokes `preflight.sh` or `check-manifest-drift.sh`.** `grep -rn "manifest\|preflight" .github/workflows/*.yml` at `0c00cf526` returns only three comment lines (`ci.yml:65`, `:75`, `:184`), none of them a manifest invocation. `ci.yml:184` is a comment on the backend-test job's `if:`, not a manifest gate. Do **not** repeat the refuted "PR→dev skipped it for two weeks" story (`00 §3 C:177`).
- Live drift at `0c00cf526`, measured by running the check (exit 1): **6 new routes** (`/admin/support-access`, `/finance/lane-separation`, `/inventory/stock-adjustments`, `/inventory/stock-adjustments/:id`, `/inventory/stock-adjustments/new`, `/settings/support-access`), **8 permission corrections** (the `/finance/*` `reports.view`/`accounts.view` → `reports.operational`/`reports.financial`/`ledger.view` catch-up), **1 `module_gate` correction** (`/settings/setup` `null` → `settings`), and **1 component regression** (`/sales/invoices/:id`, which T2 removes). Only `routes-web.yaml` drifts; the POS manifest is clean.

**Acceptance criteria.**
- (a) `node scripts/factory/gen-route-manifest.mjs` run **once**, at the point in the chain fixed by §2's dependency model: **after T2 AND after all four route deletions (T10–T13) are on your branch.** `scripts/factory/manifests/` committed in a commit that changes nothing else — this is `T3(a)`, and it is the **only** manifest commit on the branch. `bash scripts/factory/check-manifest-drift.sh` then exits 0. If you regenerate before the deletions land you will have to redo it; the deletions' intermediate drift is expected and permitted (§2).
- The regenerated manifest shows `/sales/invoices/:id → InvoiceDetailPage` (proof T2 landed first). If it shows `KeyedByRouteId`, you regenerated too early — revert and redo.
- (b) `T3(b)`, a separate commit: a new job in `.github/workflows/ci.yml` — suggested id `route-manifest-drift`, name "Route Manifest Drift Guard" — that:
  - has **no `if:` guard**, so it runs on every push and every PR regardless of base branch (this is the whole point of the finding);
  - installs only what the generator needs. Note `gen-route-manifest.mjs:5-7` resolves `typescript` via `createRequire(join(repoRoot, 'apps/web/package.json'))` and imports `js-yaml` — so the job needs the workspace install, not just bare node. Model it on the lean-job discipline of `types-drift` (`ci.yml:1012`): no DB, no Redis, no `.env`.
  - runs `bash scripts/factory/check-manifest-drift.sh` and fails on drift (the script already prints the fix command).
- The job is added to `all-checks-pass`'s `needs` list (`ci.yml:1090`+). Read the comment block above that `needs` list first: it explains that a `needs` entry which comes back `skipped` breaks the aggregate gate. A job with no `if:` always runs, so it never sits `skipped` — state that reasoning in a comment next to your addition, matching the existing style.
- Runtime target: the check is a ~seconds-scale node script. If your job takes minutes, you over-provisioned it.

**Tests required.** CI jobs cannot be unit-tested. The acceptance evidence is: (1) `bash scripts/factory/check-manifest-drift.sh` exits 0 locally post-regen (paste the command + exit code); (2) a **deliberate negative check** — temporarily add a throwaway route, confirm the script exits 1 and prints the fix line, then revert. Paste both outputs. Do not commit the throwaway route.

---

### T4 — `UI-01` + `UI-02`: `canAccessModule` fails **open** on any unrecognised key

**Finding:** `UI-01` (**P1** / S) + `UI-02` (P3 / S, folded in), `00 §2:88,131`, `00 §3 A Problem 2:154`.

**What.** Make the module-gate contract fail **closed** and make a bad key a **compile error**, then fix the sites that surface.

**Evidence (re-verified).**
- `apps/web/src/hooks/usePermissions.ts:130-134`:
  ```ts
  const canAccessModule = (moduleKey: string): boolean => {
    const requiredPermissions = MODULE_PERMISSIONS[moduleKey]
    if (!requiredPermissions) return true // No restrictions
    return hasAnyPermission(requiredPermissions)
  }
  ```
  `MODULE_PERMISSIONS` is declared `Partial<Record<string, Permission[]>>` at `usePermissions.ts:21` and carries **42 keys**. Any key outside those 42 returns `true`.
- **Six call surfaces** feed it, all typed `string` (r1 said five and missed the last one — corrected):
  1. `features/auth/components/RequirePermission.tsx:11,51-53` (`moduleKey?: string`);
  2. `components/organisms/Sidebar/Sidebar.tsx:432` (the nav item's `permission` field, via `isNavItemVisible`);
  3. `components/organisms/CommandPalette/useCommandPalette.ts:42,130-131`;
  4. the hub pages' `permissionModule` (`features/pos/pages/PosHubPage.tsx:72`, `features/inventory/pages/InventoryHubPage.tsx:135`, `features/marketing/pages/MarketingHubPage.tsx:75`, `features/finance/pages/FinanceHubPage.tsx:184`);
  5. a direct call in `features/settings/pages/PosRefundPoliciesPage.tsx:130` (key `'settings'` — valid);
  6. **a direct call in `features/treasury/components/CashPositionWidget.tsx:124-127`** — `canAccessModule('treasury')` (key `'treasury'` — valid). It adds no invalid key, but it **is** a compile surface and must be in the typecheck census.

**The four invalid keys, across five literal occurrences.** Enumerated mechanically at `0c00cf526` by extracting every literal passed to `canAccessModule` through all six surfaces and subtracting the 42 known keys. Four **distinct** keys; `parts_catalog` appears at two sites, so the compiler will report **five** diagnostics, not four. (r1 said "exactly four sites" — that was wrong and is corrected here.)

| # | Key | Site | Correct resolution |
|---|---|---|---|
| 1 | `goods-receipt.create-standalone` | `Sidebar.tsx:182` (nav item "newGoodsReceipt") | It is a **real backend permission** (`hooks/permissionsMap.generated.ts:89` → `['admin','manager']`). Add `'goods-receipt.create-standalone': ['goods-receipt.create-standalone']` to `MODULE_PERMISSIONS`, mirroring the existing self-mapped entries (`'inventory.transfers.view'`, `'replenishment.view'`, `'batches.write-off'`). **This tightens the nav for non-admin/manager roles — that is the intended, previously-broken behaviour. Call it out in the handback.** |
| 2 | `inventory.view` | `Sidebar.tsx:215` (nav item "stockByLocation") | Real permission (`permissionsMap.generated.ts:118`). Add `'inventory.view': ['inventory.view']`. |
| 3 | `parts_catalog` (×2) | `routes/index.tsx:1431` and `:1443` | No such permission and no such module key — this is `UI-43`'s "2 dead `moduleKey="parts_catalog"` attributes". Both routes **already** carry `<ModuleGuard module="PlatformIntegration">` (`:1429`, `:1441`), so the real gate is intact. Delete the `RequirePermission moduleKey="parts_catalog"` wrapper on both, keeping `ModuleGuard`. |
| 4 | `partners` | `routes/index.tsx:2777` (`/crm/companies`) | `UI-02`. The component is a pure redirect: `features/crm/pages/CompanyListPage.tsx:1-11` renders `null` and `navigate('/sales/customers', { replace: true })`. Replace `moduleKey="partners"` with `permission="partners.view"` (a real permission, `permissionsMap.generated.ts:149`). **Do not delete the route** — route dedup is `UI-35`, Wave 4. |

**Acceptance criteria.**
- `canAccessModule` returns `false` for an unrecognised key. The `// No restrictions` comment is replaced with one explaining the fail-closed contract.
- `MODULE_PERMISSIONS` is declared so `keyof typeof` is a **literal union**, not `string` — e.g. `as const satisfies Record<string, readonly Permission[]>` with an exported `export type ModuleKey = keyof typeof MODULE_PERMISSIONS`. Losing the `Partial<Record<string, …>>` erasure is the point.
- **Readonly compatibility, required first.** `as const` makes the map values `readonly Permission[]`, which does **not** satisfy the current mutable parameter types. Widen both helpers in the same file before the union lands: `hasAnyPermission(permissions: readonly Permission[])` (`usePermissions.ts:116`) and, for symmetry and to prevent the next caller tripping the same wall, `hasAllPermissions(permissions: readonly Permission[])` (`usePermissions.ts:123`). Note the real name is `hasAllPermissions` (plural). This widening is non-breaking: every existing mutable-array caller still assigns. **If you skip it, you will see an extra, unrelated local error and mis-report the diagnostic set.**
- `canAccessModule(moduleKey: ModuleKey)`, and all six call surfaces are re-typed: `RequirePermission`'s `moduleKey?: ModuleKey`, the command-palette nav-item type, the hub-card `permissionModule?: ModuleKey`, the Sidebar nav item's `permission?: ModuleKey` (and `isNavItemVisible`'s `permission` parameter, `Sidebar.tsx:425`), plus the two direct callers (`PosRefundPoliciesPage.tsx:130`, `CashPositionWidget.tsx:127`) — both of which pass valid keys and should therefore compile clean, which is itself part of the proof.
- **The exact expected diagnostic set**, after the readonly widening and before you fix the sites: `pnpm typecheck` in `apps/web` fails on **exactly these five locations and nothing else** —
  1. `src/components/organisms/Sidebar/Sidebar.tsx:182` (`goods-receipt.create-standalone`)
  2. `src/components/organisms/Sidebar/Sidebar.tsx:215` (`inventory.view`)
  3. `src/routes/index.tsx:1431` (`parts_catalog`)
  4. `src/routes/index.tsx:1443` (`parts_catalog`)
  5. `src/routes/index.tsx:2777` (`partners`)

  Paste that failing typecheck output in the handback — it is the proof the union is real and the proof the list is complete. **If you see any sixth diagnostic, stop and report it**: either the readonly widening is incomplete, or the invalid-key census missed something and the brief is wrong.
- All four keys resolved as tabulated (five edits, because `parts_catalog` has two sites). `pnpm typecheck` green afterwards.
- Note in the handback: the hub-page `permissionModule` typing touches `MarketingHubPage.tsx` and `FinanceHubPage.tsx`, both of which T11/T12 delete. Sequence to avoid churn: do T11/T12 first, or accept the throwaway edit.

**Tests required (written first).**
- `apps/web/src/hooks/__tests__/` — a test asserting `canAccessModule` returns `false` for an unknown key (cast through `as ModuleKey` to reach the runtime path), and `true`/`false` correctly for a known key by role. Must fail before the change.
- A `RequirePermission` render test: unknown `moduleKey` → children are **not** rendered (permission-denied path), where today they are.
- Sidebar test: with a role lacking `goods-receipt.create-standalone`, the "newGoodsReceipt" item is absent. Must fail before the change.
- Keep/extend the existing `Sidebar` and `useCommandPalette` tests green.

---

### T5 — `UI-12`: voucher filter chips have both ternary branches empty

**Finding:** `UI-12` (P2 / S), `00 §2:102`.

**Evidence (path corrected — the report cites the bare filename).** `apps/web/src/features/vouchers/pages/VoucherListPage.tsx:104-112`:
```tsx
className={`inline-flex items-center rounded-full px-3 py-1 text-sm font-medium transition-colors ${
  source === filter.value
    ? ``
    : ``
}`}
```
Selected and unselected chips are visually identical. Filtering itself works (`handleSourceFilter`, and `data-testid={`source-filter-${filter.value || 'all'}`}` at `:113`).

**Acceptance criteria.**
- Selected state is visually distinct from unselected, expressed **only** through design tokens from `@/lib/designTokens` (CLAUDE.md rule 18). No raw Tailwind palette classes — `apps/web/tools/audit-design-system.mjs` and the ESLint palette rules will catch you.
- Prefer the existing selected/unselected treatment already used by chip/tab affordances elsewhere in `apps/web` (find one and match it — this is a consistency fix, not a design exercise). Name the component you matched in the handback.
- The chips remain `Button variant="secondary"` unless matching the house pattern requires otherwise; if you change the variant, say why.
- `aria-pressed` (or equivalent) reflects selection, so the state is not colour-only.

**Tests required (written first).** A test on `VoucherListPage` asserting the selected chip's rendered class list / `aria-pressed` differs from an unselected one. Per CLAUDE.md rule 17, prefer asserting rendered output and accessible state over exact CSS class strings — assert "differs" and assert the aria attribute, not a literal token name.

---

### T6 — `UI-14`: unconditional green `successDot` in the product hero — **BLOCKED, see §6**

**Finding:** `UI-14` (P2 / S), `00 §2:103`.

**Evidence.** `apps/web/src/features/products/editor/components/ProductEditHero.tsx:137`:
```tsx
<span className={cn('inline-flex h-2.5 w-2.5 rounded-full', tokens.productHero.successDot)} aria-hidden="true" />
```
Stateless, unlabelled, unconditional. It renders green next to the barcode input regardless of any state, and reads as status. Its only consumer is `features/products/sections/ProductHeroSection.tsx:1,24`.

**Why blocked.** The file is inside `features/products/editor/` — the parked Rafiq-skin directory (§1 DO-NOT-TOUCH; `00 §E:203`; owner ruling OQ-4 PARKED). Rev 5 put `UI-14` in Wave 0 without reconciling it against that constraint.

**Default disposition: DO NOT IMPLEMENT.** This is a **non-executable escalation record**: it produces **no commit** and is not one of the fourteen implementable tasks (§2). Record it in the handback as blocked, with this evidence, and move on. If — and only if — the parent session rules explicitly in-thread to proceed, the change is a one-line deletion of `:137` plus the token entry if it becomes unused; it is not a restyle, and you must not touch anything else in that file. Unlike T8-b, this deletion carries **no design-system baseline cost** — the `successDot` span is not a baselined C1–C6 entry (the two `ProductEditHero` C2 entries at `baseline.json:226-227` and the two C3 entries at `:666-667` cover the inputs and buttons, not this span, and must all survive untouched).

**Do not act on §6 F-1's merge-risk assessment on your own authority.** It lowers the estimated risk; it is not the authorisation.

---

### T7 — `UI-40` (partial): C6 detector regex fix + `colorClasses` migration/baseline **plan**

**Finding:** `UI-40` (P2 / S), `00 §2:128`, and the binding carve-out note at `00 §5:246`.

**Scope is two things and no more.** (a) fix the C6 detector; (b) write a migration/baseline **plan**. **Removing the ESLint carve-outs is explicitly NOT Wave 0** — dropping them without the migration or a recorded baseline turns 52 quarantined importers into lint failures on the next run.

**Evidence.**
- (a) `apps/web/tools/audit-design-system.mjs:59-63`:
  ```js
  const STATUS_RE = [
    /(status|state)\w*(Colors?|Classes?|Maps?|Styles?)\s*:\s*Record</gi,
    /switch\s*\(\s*\w*[Ss]tatus\w*\s*\)/g,
    /const\s+\w*(status|state)\w*(Colors?|Classes?|Maps?|Styles?|Config|Badge\w*)\s*[:=]/gi,
  ]
  ```
  `Tone`/`Tones` is absent from every alternation, so C6 reports **0** while 25+ live `Record<…, StatusTone>` maps exist (`02:22` F-8).
- (b) `apps/web/eslint.config.js:209-229` — the `no-restricted-imports` ban on `colorClasses` carries `ignores: ['src/features/documents/**', 'src/features/admin/**', 'src/lib/designTokens.ts']`. **All 52** real importers live inside those two directories (`00 §2:128` corrects `02`'s "43 of 52").

**Acceptance criteria — (a) detector.**
- The regex family matches `Tone`/`Tones` suffixes; C6 stops reporting 0.
- **The baseline must be updated in the same commit**, because `pnpm lint` chains `audit:design-system` and CI's `frontend-lint` job (`ci.yml:853`) runs `pnpm lint`. Procedure: capture `node tools/audit-design-system.mjs` per-category counts **before**, run `node tools/audit-design-system.mjs --write-baseline` **after**, capture counts again.
- **Guard against over-baselining.** `--write-baseline` rewrites `tools/audit-design-system-baseline.json` wholesale (`audit-design-system.mjs:398,415-417`), so it will silently swallow any *other* new violation that crept in. Acceptance: in the handback, show the per-category before/after table and demonstrate that **only C6 grew**, with **no C1–C5 movement at the T7 commit**. If any of C1–C5 moves *here*, stop and report — do not commit that baseline.
- **The C1–C5 rule is commit-local, not branch-global.** T10 (and T8-b if ever authorised) legitimately *shrink* C2/C3 by deleting baselined source files. That branch-level movement is expected and is governed by §4 item 3, not by this criterion. Do not conflate the two: **T7 must not move C1–C5; the branch may, but only in the deletion direction and only from the files T10 deletes.**
- The report states the new C6 count explicitly (this number becomes the burn-down target for a later wave).

**Acceptance criteria — (b) plan.**
- A plan document at `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/18-colorclasses-migration-plan.md` containing: the exact importer list (file + imported symbols + occurrence count, script-derived, not hand-typed); the reproduction command; a proposed sequencing (which files first, and why); the mechanical-vs-manual split; the two options for lifting the carve-out (migrate-then-lift vs lift-with-recorded-baseline) with their consequences; and an explicit statement that **the migration remains unscheduled and must be assigned to a later wave and budgeted** (`00 §5:246` — say it in those terms).
- **No change to `eslint.config.js` in this task.** None.

**Tests required (written first).** Extend `apps/web/tools/__tests__/audit-design-system.test.mjs` with a fixture containing `const orderStatusTones: Record<OrderStatus, StatusTone> = {...}` and assert it is reported as C6. Must fail before the regex change.

---

### T8 — `UI-43`: dead-code sweep, split into independent claims

**Finding:** `UI-43` (P3 / S), `00 §2:135` — note the report's own instruction: *"Bundles several independently closable claims — split before ticketing"*. Each sub-claim below is its own commit.

**T8-a — `parts_catalog` dead gate attributes.** **Already assigned to T4 (row 3).** Do not do it twice; do not touch `routes/index.tsx:1431,1443` here. Recording it in two places is the mistake the "split before ticketing" note warns about — after T4's fail-closed change, a bogus key locks everyone out, so the deletion and the contract change must land together.

**T8-b — delete `BarcodeHero` — BLOCKED (non-executable escalation record), see §6 F-1.** `apps/web/src/features/products/editor/components/BarcodeHero.tsx` + `BarcodeHero.test.tsx` have **no non-test consumer** (verified: the only other hits are comments in `features/inventory/ProductForm.test.tsx:135` and `features/inventory/__tests__/ProductForm.opening.test.tsx:226,249`, which refer to the *hero region of `ProductForm`*, not this component). `00 §E:195` warns that leaving it alive guarantees the next person restyles the wrong file. **But it lives in `features/products/editor/`** — the parked directory. Default disposition: **do not delete; report as blocked. This produces no commit and is not one of the fourteen implementable tasks.**

**If — and only if — the parent explicitly authorises it later**, note the deletion is not source-only: `BarcodeHero` carries **two C2 baseline entries** (`apps/web/tools/audit-design-system-baseline.json:224-225`) and **one C3 entry** (`:665`), all of which must be removed in the same commit or the audit fails on stale entries (`audit-design-system.mjs:443-450`). That baseline file is also the one file the Rafiq branch is known to touch — see §6 F-1 for the gate reviewer's merge-risk assessment. **Do not act on this paragraph without that explicit authorisation.**

**T8-c — prune the 4 orphaned "coming soon" locale key groups.** Verified at `0c00cf526`: each of these has **zero** code usages outside `src/locales/`:

| Key | Files |
|---|---|
| `pos:…discountComingSoon` | `locales/en/pos.json:8`, `locales/fr/pos.json` |
| `inventory:…stockComingSoon` | `locales/en/inventory.json:214`, `locales/fr/inventory.json` |
| `inventory:…detailsComingSoon` | `locales/en/inventory.json:612`, `locales/fr/`, `locales/ar/` |
| `inventory:…categorySelectionComingSoon` + `…categorySelectionComingSoonHint` | `locales/en/inventory.json:709-710`, `locales/fr/`, `locales/ar/` |

**Do not touch** the three live ones: `common:pos.featureComingSoon` (`common.json:1122`, used by `features/pos/layouts/ShiftOperationsMenu.tsx:122` — that is `UI-45`, owner-ruled "hide until real", **Wave 4**); `pricing:…addItemComingSoon` / `…assignPartnerComingSoon` (`pricing.json:43-44`, used by `PriceListDetailPage` — that is `UI-13`, **Wave 1**); `parts-catalog:vinPlate.comingSoon` (used at `PartsCatalogPage.tsx:348`).

**Out of scope — `touchOptimized`.** `UI-43`'s fourth claim (a `touchOptimized` prop threaded through 15 TSX files whose outer caller passes `false`) is **not** in this dispatch. It is a 15-file API change, not a sweep. Do not start it.

**But record it correctly.** Rev 5 assigns it to Wave 0 (`00 §5:240-248`); the "too big for a sweep" argument is a scheduling recommendation, **not** an ownership transfer, and no owner ruling reassigns it. So it is an **unresolved ownership gap** (§6 F-2b), not a settled deferral. Consequence for the handback: **you must not state that Wave 0 is complete, and you must not state that `UI-43` is closed.** Report `UI-43` as `PARTIAL` with `T8-a` (in T4), `T8-c` (here), `T8-b` (blocked) and `touchOptimized` (unassigned) itemised.

**Acceptance criteria (T8-c).**
- The four key groups are gone from every locale file that carried them (en/fr/ar as applicable), with valid JSON preserved.
- A grep proving zero code references remains, for each key, pasted in the handback.
- `pnpm test` for `apps/web` i18n coverage tests stays green — in particular `src/lib/i18nRawKeyCoverage.test.tsx`, which pins specific raw keys and will fail loudly if you delete one it guards.
- EN/FR key alignment does not regress (`00 §F:209` records EN/FR as near-perfectly aligned — deleting from one locale and not the other breaks that).

**Tests required (written first).** Add (or extend) a test asserting the removed keys are absent from all three locale bundles, and that the three *live* keys above still resolve. Run the existing i18n coverage tests before and after.

---

### T9 — `UI-44`: documentation debt

**Finding:** `UI-44` (P3 / S), `00 §2:136`.

**Evidence (re-verified).**
- `docs/architecture/design-system.md` — grepping it for `listing`, `ListPageLayout`, `FilterPanel` returns **zero hits**. There is no written listing-page canon for anyone to follow (`02:24` F-10).
- `docs/architecture/frontend-navigation.md:1-6` — "Document Version: 1.0 / Last Updated: **2026-01-02** / Milestone: 8". Stale (`01 §7.8`).

**Acceptance criteria.**
- `docs/architecture/design-system.md` gains a **listing-page composition** section that documents what exists today and what the recommended canon is: `ListPageLayout` shell → filter bar → `DataTable` (empty state passed in) → `OffsetPagination` in the layout slot, per `00 §D:189`. It must be honest about adoption (`ListPageLayout` 12 of 46 — the one number `00 §4:217` marks decision-grade) and must **link to T1's re-census report** for current figures rather than restating numbers that will go stale.
- **Do not invent the canon beyond `00 §D`.** The `FilterBar` wrapper and `DataTableColumn.sortable` are *proposed*, not built — describe them as proposed, and mark the whole section "canon proposed, Wave 3 gated on the CX-4 re-census".
- `docs/architecture/frontend-navigation.md` refreshed: correct the version/date header, and reconcile the content with what T3's regenerated manifest and T1's orphan re-derivation actually show. Where you cannot verify a claim, delete it rather than carry it forward.
- Both docs cross-link to `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/00-EXECUTIVE-REPORT.md` as the audit of record.

**Tests required.** None runnable. Acceptance evidence = the grep that returned zero now returns the new section, plus a diff summary in the handback.

---

### T10 — Owner-ruled deletion: `/pos/shifts` + `POSShiftsDashboard`

**Ruling.** `OWNER-DECISIONS:39` — *"DELETE — confirmed web-POS-era remnant."* Evidence: `10-verification-orphans-duplicates.md` §1 (verdict at `:63`, summary row at `:339`).

**Why it is safe (from the evidence file, re-verified where cited).** Its five mutating actions are double-blocked server-side: `EnsureWebPosDemoTenant` 403s browser callers on any non-demo tenant, and `ShiftController` hard-409s `SHIFT_DEVICE_AUTHORITY_REQUIRED` for terminals at `fiscal_schema_version >= 3` — which is now the default. The page itself computes `isDeviceAuthoritative` purely to disable its own buttons. `/pos/shift-history` is canonical and already linked (`Sidebar.tsx:239`, `PosHubPage.tsx:61`).

**Delete (verified inventory at `0c00cf526`).**
- Route: `apps/web/src/routes/index.tsx:3180-3190` (`path="/pos/shifts"`) and the lazy import at `:284`.
- `apps/web/src/pages/POS/POSShiftsDashboard.tsx` and its barrel export `apps/web/src/pages/POS/index.ts:5`.
- `apps/web/src/features/pos/pages/ShiftDashboardPage/` (the presentational component the dashboard wraps: `ShiftDashboardPage.tsx`, `ShiftDashboardPage.test.tsx`, `ShiftDashboardPage.colorDrift.test.tsx`, `index.ts`) **only after** you re-verify it has no other importer. Its exports appear in two public barrels — `apps/web/src/features/pos/index.ts:41` and `apps/web/src/features/pos/pages/index.ts:1` — both of which must be updated; also fix the doc comment at `features/pos/index.ts:14`.
- Comment references that become false: `apps/web/src/pages/POS/_invalidation.ts:13`, `apps/web/src/pages/POS/__tests__/tenantScope.test.tsx:102`.
- **Design-system baseline entries for the two deleted files — mandatory, same commit.** The audit fails on *stale* baseline entries, not just new violations (`apps/web/tools/audit-design-system.mjs:443-450`, exit 1). Remove exactly two lines from `apps/web/tools/audit-design-system-baseline.json`:
  - `:202` — `C2|src/features/pos/pages/ShiftDashboardPage/ShiftDashboardPage.tsx|…`
  - `:717` — `C3|src/pages/POS/POSShiftsDashboard.tsx|…`

  Remove them **by hand** (delete the two lines). **Do not** run `--write-baseline` here — that rewrites the file wholesale and would swallow unrelated drift, which is exactly what T7 forbids. Paste the two-line diff in the handback; it is the C2/C3 movement that §4 item 3 expects and authorises.
- **Current-tense documentation that becomes false.** `apps/web/src/features/pos/README.md` presents `ShiftDashboardPage` as a live public export and route (`:20`, `:97`, `:147`, `:268`, `:518`), and `apps/web/src/features/pos/IMPLEMENTATION_SUMMARY.md` does the same (`:107`, `:304-306`, `:365`). A dead-code deletion must not leave `import { ShiftDashboardPage } from '@/features/pos'` documented as working. Choose **one** and say which in the handback:
  - **(preferred)** edit both files to remove the component from the current-tense API/route/tree sections; or
  - explicitly retain them as **historical** records — in which case add a dated one-line "superseded 2026-08-11: `ShiftDashboardPage` deleted, see `/pos/shift-history`" note at the top of each, and narrow the T10 grep accordingly (below).

  Either way, `apps/web/src/features/pos/index.ts:14`'s doc comment must be corrected — that one is not optional.

**Do NOT delete.** `apps/web/src/features/pos/api/shiftApi.ts` — its `/pos/shifts/*` calls (`:77,88,95,156`) are API paths, not routes, and other consumers exist. `shiftHistoryApi.ts` likewise. Deleting API clients is out of scope.

**Owner caveat to carry, not to decide.** The evidence file (`:65`, `:339`) flags: *if the demo tenant still needs a browser shift console for sales demos, keeping it behind `is_demo` is an owner call — flag, don't assume.* The owner's resolution table already ruled DELETE, so **delete**; but state in the handback that this caveat was raised and superseded by the ruling, so the parent can re-check it at gate time.

**Acceptance criteria.**
- **Code grep (must be zero).** `grep -rnE "pos/shifts\"|'/pos/shifts'|POSShiftsDashboard|ShiftDashboardPage" apps/web/src --include='*.ts' --include='*.tsx'` returns **zero** hits outside the `shiftApi.ts`/`shiftHistoryApi.ts` API path strings, which you call out explicitly. Paste it. Note the `--include` filters and `-E`: they are what make this grep answerable — the unfiltered r1 version also sweeps `.md` and could never return zero.
- **Documentation grep (disposition-dependent).** `grep -rn "ShiftDashboardPage" apps/web/src --include='*.md'` — under the preferred disposition this returns zero; under the "retain as historical" disposition it returns only lines inside the two files you annotated, and you paste them with the annotation as justification. A bare unqualified "zero references" claim is not accepted for the docs.
- **Baseline grep.** `grep -n "ShiftDashboardPage\|POSShiftsDashboard" apps/web/tools/audit-design-system-baseline.json` returns zero. Paste it.
- Orphaned i18n keys removed: enumerate the `pos:` keys the deleted components were the sole consumers of, and delete them from **all** locales. Do not delete a key any surviving component uses — prove per key with a grep.
- `pnpm typecheck`, `pnpm lint`, `pnpm test` green in `apps/web`. (`pnpm lint` chains `audit:design-system`, so it is also the proof the baseline shrink was complete.)
- **No manifest regeneration in this commit.** T3 owns the manifest (§2). The route's disappearance from `routes-web.yaml` is verified once, at `T3(a)`, and asserted at §4 item 2. This commit's drift-check exit 1 is expected.

**Tests required.** Delete the tests belonging to deleted components. Add/extend a routing test asserting `/pos/shifts` no longer resolves to a page (it falls through the `path="*"` catch-all to `/dashboard`, `routes/index.tsx:3164`). Confirm `/pos/shift-history` still renders and is still linked from the sidebar and POS hub.

---

### T11 — Owner-ruled deletion: `/marketing` + `MarketingHubPage`

**Ruling.** `OWNER-DECISIONS:44` — *"DELETE — pure duplicate."* Evidence: `10-verification-orphans-duplicates.md` §6 (`:205`, summary `:344`): six hrefs set-identical to the `customersAndMarketing` sidebar group, zero inbound links, and the route carries **no `RequirePermission` at all**.

**Delete (verified).**
- Route `apps/web/src/routes/index.tsx:1982-1989` and the lazy import at `:98`.
- `apps/web/src/features/marketing/` in full: `index.ts`, `pages/MarketingHubPage.tsx`, `pages/MarketingHubPage.test.tsx`.
- The `marketing` i18n namespace becomes fully orphaned — verify with a grep for `marketing:` outside `src/locales` (at `0c00cf526` the only hits are the `i18n.ts` registrations). Removing a namespace touches **three places** in `apps/web/src/lib/i18n.ts` plus the files: imports at `:33` (en) and `:89` (fr), resource entries at `:197` (en), `:254` (fr), `:402` (ar-fallback, which reuses `enMarketing`), and the `ns` array at `:442`. Then delete `src/locales/en/marketing.json` and `src/locales/fr/marketing.json`.

**Acceptance criteria.**
- `grep -rnE "/marketing\b|MarketingHubPage|marketing:" apps/web/src` returns zero hits. Paste it.
- No sidebar / command-palette / quick-create entry pointed at `/marketing` (verified: there were none — confirm and say so).
- `pnpm typecheck`, `pnpm lint`, `pnpm test` green; i18n coverage tests green.
- **No manifest regeneration in this commit** — T3 owns it (§2). Intermediate drift is expected.

**Tests required.** Remove `MarketingHubPage.test.tsx`. Add a routing assertion that `/marketing` no longer resolves. Assert the six destinations it used to list are still reachable from the `customersAndMarketing` sidebar group (that is the entire justification for deleting it — prove it holds).

---

### T12 — Owner-ruled deletion: `/finance` + `FinanceHubPage`, **outright, no redirect**

**Ruling.** `OWNER-DECISIONS:58` — *"**DELETE outright — no redirect.** Greenfield; nothing links to `/finance`, so no broken-bookmark risk. (Research had proposed delete+redirect; owner simplified.)"* This **supersedes** `11-research-finance-hub.md`'s recommendation (a) at `:112,142,182`, which proposed `<Navigate to="/finance/overview" replace />`. **Do not add the redirect.** If you find yourself writing `Navigate` for `/finance`, you are implementing the superseded proposal.

**Consequence, accepted by the ruling:** a typed or bookmarked `/finance` falls through the `path="*"` catch-all to `/dashboard` (`routes/index.tsx:3164`).

**Delete (verified).**
- The index route `apps/web/src/routes/index.tsx:1992-2000` and the lazy import at `:99`. **Keep the `<Route path="finance">` parent** (`:1993`-ish) and every `/finance/*` child — only the index element goes.
- `apps/web/src/features/finance/pages/FinanceHubPage.tsx` + `FinanceHubPage.test.tsx`.

**Three non-production consumers, each with a required disposition (r1 claimed a global zero; that was refuted).**

| Consumer | Disposition | Why |
|---|---|---|
| `apps/web/e2e/money-campaign/finance-permissions.spec.ts:296-353` — test `MTP-GL-28 (P2): the finance hub is a permission-filtered navigator that surfaces NO money`, which does `page.goto('/finance')` at `:300` and again at `:343` | **DELETE the whole `MTP-GL-28` test block.** It asserts the existence of the hub the owner ruled deleted; there is nothing left for it to assert. Do **not** repoint it at `/finance/overview` — that would be inventing a replacement contract nobody specified. Leave every other test in the file untouched. | The hub is gone; the test cannot pass and is not weakened-but-kept, it is obsolete. |
| `apps/web/tools/ui-audit-shots.mjs:40` — `['24-finance', '/finance']` | **DELETE that one array entry.** Do not renumber the neighbouring entries. | Post-deletion it screenshots the `/dashboard` catch-all under a `finance` filename — a silently wrong audit artefact. |
| `apps/web/src/components/molecules/HubCard/HubCard.test.tsx` — **seven** `to="/finance"` fixture literals (`:12`, `:22`, `:35`, `:54`, `:63`, `:87`, `:103`) used as generic `HubCard` fixture data | **RETAIN UNCHANGED. Do not touch this file.** | The string is arbitrary test data for a generic component; it is not a reference to the deleted route. Changing it would be out-of-scope churn (rule 4). It is the single reason a naive global `/finance` grep can never return zero. |

Deleting an E2E test is a deviation-grade action: record it explicitly in the handback §6 (Deviations) with the ruling that authorises it (`OWNER-DECISIONS:58`).

**The one i18n key that MUST survive.** `finance:hub.cards.treasuryOverview.title` is borrowed by the sidebar: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:297` uses it as the `labelKey` for the Treasury Overview entry (`11-research-finance-hub.md:87` §1f). **Preserve it** in `src/locales/{en,fr,ar}/finance.json`.

**The two guard tests protect two different things — do not conflate them** (r1 said both pin the key; that was overstated):

| Test | What it actually guarantees | What it does NOT guarantee |
|---|---|---|
| `apps/web/src/lib/i18nRawKeyCoverage.test.tsx:62-82,98-110` | That the raw key **exists** and that its **en/fr/ar values** are exactly "Treasury" / "Trésorerie" / "الخزينة". **This is the only test that protects the locale values.** | — |
| `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx:134-165` | That the sidebar still **wires** that raw label key to the correct href. | **Nothing about the locale values.** Its `t` is mocked to return the key unchanged (`:25-30`), so it passes identically whether the value is correct, corrupted, or deleted entirely. A green Sidebar suite is **not** proof the key survived. |

Both stay unmodified, but only `i18nRawKeyCoverage` may be cited as value-survival evidence.

**Pruning the other `hub.*` keys is MANDATORY, not optional.** The deletion checklist (§3, item 5) requires every newly-orphaned i18n key to go, in all locales, and this task has exactly one named exception — the preserved key above. r1's "may be pruned" is withdrawn. For **each** `finance:hub.*` key other than `hub.cards.treasuryOverview.title`: paste a per-key grep proving zero surviving references outside `src/locales/`, then delete it from en/fr/ar. If a key turns out to have a surviving consumer, keep it and **name that consumer with a `file:line`** — that is the only acceptable reason for a key to survive.

**Explicitly NOT in this task.** `/finance/lane-separation` (`routes/index.tsx:2010`) — orphaned, but claimed by the DN-consolidation build (§1 DO-NOT-TOUCH). Do not link it, do not delete it, do not mention it in the route change.

**Acceptance criteria.**
- `grep -rn "FinanceHubPage" apps/web/src` returns zero hits. Paste it.
- **Production-only inbound-link grep** — tests and fixtures excluded, because `HubCard.test.tsx` legitimately keeps `/finance` fixtures (see the disposition table):
  ```
  grep -rnE "to=\"/finance\"|to=\{'/finance'\}|navigate\('/finance'\)|href=\"/finance\"" apps/web/src \
    --include='*.ts' --include='*.tsx' \
    | grep -vE "\.test\.tsx?:|__tests__/|/test/"
  ```
  Must return zero. Then, **separately**, enumerate the intentional non-production literals you are leaving behind (the **seven** `HubCard.test.tsx` literals at lines 12, 22, 35, 54, 63, 87, 103) so the zero is honest rather than filtered-into-existence. A filtered zero without that enumeration is not accepted.
- A grep showing **no redirect** was introduced: `grep -rn "Navigate" apps/web/src/routes/index.tsx | grep -i finance` returns zero.
- `i18n.t('finance:hub.cards.treasuryOverview.title')` still resolves in en/fr/ar; the sidebar Treasury Overview label still renders. `i18nRawKeyCoverage.test.tsx` and `Sidebar.test.tsx` green **unmodified** — if you had to edit either to make it pass, you broke the borrowed key. Cite `i18nRawKeyCoverage` (not `Sidebar`) as the value-survival proof.
- Every `/finance/*` child route still resolves.
- `MTP-GL-28` removed from `finance-permissions.spec.ts`; the rest of that spec unchanged; `/finance` removed from `ui-audit-shots.mjs`; `HubCard.test.tsx` byte-identical. Paste `git diff --stat` for the three files.
- **No manifest regeneration in this commit** — T3 owns it (§2). The `/finance` index entry disappears at `T3(a)`.

**Tests required.** Remove `FinanceHubPage.test.tsx`. Add a routing assertion that `/finance` does not render a hub. Do **not** assert a redirect target. Keep the two i18n/sidebar tests untouched as the regression guard — noting they guard different things (table above).

---

### T13 — Owner-ruled deletion: the duplicate `/settings/chart-of-accounts` mount

**Ruling.** `OWNER-DECISIONS:43` — *"Duplicate mount deleted in favour of `/finance/chart-of-accounts`."* Evidence: `10-verification-orphans-duplicates.md` §5 (`:160`, summary `:343`).

**Facts (re-verified).** Two mounts of the **same component with a byte-identical guard**: `routes/index.tsx:2030` under `finance` (canonical) and `:2327` under `settings` (duplicate). Every inbound reference points at the finance mount — `Sidebar.tsx:299`, `useCommandPalette.ts:80`, `FinanceHubPage.tsx:103` (the last disappears with T12). Zero references to the `/settings/…` path outside its own route declaration.

**Delete.** The `settings` mount route block (`routes/index.tsx:2326-2334`).

**Do not add the "optional courtesy" redirect** the evidence file floats at `:162`. The owner's posture on `/finance` was explicitly "no redirect"; do not introduce an inconsistent redirect here. If you think a bookmark case exists, raise it — do not implement it.

**Acceptance criteria.**
- `grep -rn "settings/chart-of-accounts" apps/web/src` returns zero hits. Paste it.
- `/finance/chart-of-accounts` still resolves, still gated `permission="accounts.view"`, still linked from sidebar + command palette.
- **No manifest regeneration in this commit** — T3 owns it (§2).

**Tests required.** Routing assertion: `/settings/chart-of-accounts` no longer resolves; `/finance/chart-of-accounts` still does.

---

### T14 — `OQ-1`: the app name becomes a config value, not a hardcoded brand string

**Ruling.** `OWNER-DECISIONS:9` — *"ERP product names are NOT final… the app name in privacy/support copy must become a **placeholder/config value**, not a hardcoded string. **Do not bake any brand string into copy.**"* Related finding: `UI-37` (`00 §2:125`) — but note `UI-37`'s *brand-spelling* half (`Syneriva` vs `Synerivia` in the enrichment copy) is **Wave 4** and is **not** in this task. This task is the app-name placeholder only.

**The config surface already exists — use it, do not build a new one.** `apps/web/src/contexts/ProductConfigContext.tsx` reads `VITE_APP_PRODUCT` (`:59`, values `izipos` | `otospex`, default `izipos`) and exposes `productName` from a single `PRODUCT_INFO` map (`:31-40`) via `useProductConfig()`. That map is the one place a renamed product gets renamed.

**Hardcoded `AutoERP` in scope (verified at `0c00cf526`).**

| Site | Note |
|---|---|
| `src/locales/{en,fr,ar}/common.json:2` — `"appName": "AutoERP"` | **Zero code consumers** (`grep -rn appName apps/web/src` outside locales returns nothing). Dead key carrying a wrong brand. |
| `src/locales/en/common.json:1301` (privacy `section1.body`), `:1325` (`purpose1`) | Privacy-policy copy, rendered by `src/pages/legal/PrivacyPolicyPage.tsx`. |
| `src/locales/fr/common.json:1318`, `:1342` | French equivalents. |
| `src/locales/{en,fr,ar}/support-access.json:4` — `subtitle` | Support-access copy ("AutoERP support may enter your workspace"). |

**Acceptance criteria.**
- Privacy and support-access copy interpolate the name: locale strings use `{{appName}}` and the rendering components pass `{ appName: productName }` from `useProductConfig()`. All three locales (en/fr/ar) updated consistently — the Arabic support-access subtitle included.
- No brand literal (`AutoERP`, `Otospex`, `IziPOS`, `Synerivia`, `Syneriva`) remains in privacy or support-access copy in any locale.
- The dead `appName` key: either delete it from all three locales, or repoint it to the config value if you find a consumer. Prove which with a grep.
- No new config mechanism, no new env var. `PRODUCT_INFO` stays the single source.
- Precision/i18n rules hold: all user-facing text through `t()` (CLAUDE.md rule 11); no hardcoded strings introduced.

**Out of scope — but report it.** `src/pages/legal/TermsOfServicePage.tsx` hardcodes the brand **six times in inline non-`t()` strings** (`:37-38`, `:49-50`, `:107-108`, `:131-132`), and does its own French/English branching in JSX instead of using i18n. That is both a brand leak and a rule-11 violation, and it is **larger than this task** — do not fix it here. Record it in the handback as a discovered finding for the parent to ticket.

**Every rendering component you change needs its own failing-first proof.** There are **two** of them, not one. r1 required a test only for the privacy page; the support-access subtitle is rendered by `apps/web/src/features/support-access/pages/TenantSupportAccessPage.tsx:29` (`subtitle={t('subtitle')}`, no interpolation today), and that page had **no** coverage of the change.

**Harness warning — this will break an existing green test if you skip it.** `TenantSupportAccessPage.test.tsx:73-81` renders through a bare `QueryClientProvider` + `MemoryRouter` with **no `ProductConfigProvider`**, and `useProductConfig` **throws** when the provider is absent (`ProductConfigContext.tsx:128-135`). The moment the page calls the hook, that whole suite throws. Fix the wrapper as part of this task — either switch `renderPage()` to `renderWithProviders` (which forwards `productConfig.product` as `initialProduct`, `renderWithProviders.tsx:88,108`) or wrap explicitly in `<ProductConfigProvider initialProduct={…}>`. Note in the handback which you chose.

(For reference: `AdminSupportAccessPage.tsx:71` uses `t('supportAccess.subtitle')` from the **`admin`** namespace and carries no brand literal — it is **not** in scope. Verified by `grep -rn "AutoERP" apps/web/src/locales/`, whose full hit set is exactly the eight sites tabulated above.)

**Tests required (written first).**
- A render test on `PrivacyPolicyPage` asserting the interpolated product name appears and the literal `AutoERP` does not, for a seeded product config (`ProductConfigProvider` accepts an `initialProduct` test seed — `ProductConfigContext.tsx:43-51` — and `renderWithProviders` forwards it).
- **A seeded-product render test on `TenantSupportAccessPage`** that (a) **fails against the current literal copy** — write it first and paste that failure — (b) asserts the seeded product's name appears in the rendered subtitle, and (c) asserts the literal `AutoERP` is absent. Seed a non-default product so the assertion cannot pass by coincidence. This is the second component-level proof, not a substitute for the privacy one.
- A locale-content test asserting no brand literal remains in the privacy/support-access key subtrees across en/fr/ar. This is a **data** assertion; it does not prove either component passes `{ appName: productName }` — only the two render tests do.

---

### T15 — `UI-07`: the `/expenses` nav item is gated on `treasury`, hiding it from roles that hold `expenses.view`

**Finding:** `UI-07` (P2 / S — re-rated P1→P2), `00 §2:98`, `00 §3 Problem 3:156`. Listed in rev 5's Wave 0 closes-list (`00 §5:244`).

**Added in r2.** r1 left this unassigned and recorded the omission as F-2. The gate reviewer confirmed the omission, so it is dispatched here rather than deferred. **Do not renumber T1–T14.**

**What.** The reverse of `UI-06`: an **entitled capability is invisible**. `cashier`, `operator` and `viewer` all hold `expenses.view`, and the `/expenses` route lets them in — but the sidebar hides the entry from them, so they must know the URL.

**Evidence (re-verified at `0c00cf526`).**
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:280` — `{ key: 'expenses', href: '/expenses', icon: Receipt, permission: 'treasury' }`. The nav item's `permission` field is a **module key** fed to `canAccessModule` via `isNavItemVisible` (`Sidebar.tsx:425-436`).
- `apps/web/src/hooks/usePermissions.ts` — `treasury: ['treasury.view']` vs `expenses: ['expenses.view']`. Both keys already exist; nothing needs adding to `MODULE_PERMISSIONS`.
- `apps/web/src/hooks/permissionsMap.generated.ts:81` — `'expenses.view': ['accountant','admin','cashier','manager','operator','viewer']`, against `treasury.view` = `['accountant','admin','manager']`. **Three roles are wrongly excluded: `cashier`, `operator`, `viewer`.**
- `apps/web/src/routes/index.tsx:1675-1683` — the `/expenses` index route is gated `<RequirePermission permission="expenses.view">`. The route already admits all six roles; only the nav disagrees. This is a **pure rule-12 parity fix**, not a permission change.
- **The house pattern is already right next door:** the sibling `expenseAnalytics` item at `Sidebar.tsx:281` uses `permission: 'expenses'`. `:280` is the outlier.
- `01 §4a G-19` — the audit's own row, which reaches the same resolution.

**The fix.** One line: `Sidebar.tsx:280`, `permission: 'treasury'` → `permission: 'expenses'`. Nothing else.

**Do NOT change** any of the other `permission: 'treasury'` siblings in the `bankingAndPayments` group (`payments`, `repositories`, `instruments` at `:275-277`) — those are genuinely treasury-gated and are **not** part of `UI-07`. Do not touch the `/expenses` route guard; it is already correct. Do not touch `MODULE_PERMISSIONS`.

**Acceptance criteria.**
- `Sidebar.tsx:280` reads `permission: 'expenses'`; the diff for this task is exactly one line of production code plus its test.
- A role holding `expenses.view` but **not** `treasury.view` (use `cashier`) now sees the `/expenses` nav item.
- A role holding neither still does **not** see it — the fix must not widen the gate to everyone. (After T4 lands, an unrecognised key fails closed, so state which of T4/T15 landed first in your branch and confirm both orders leave the assertion true.)
- No other nav item's visibility changes: the existing `Sidebar` suite stays green **unmodified**.
- **User-visible nav change — call it out in the handback** alongside `F-3`. This makes `/expenses` appear for `cashier`/`operator`/`viewer`, who previously could not see it. That is the intended fix, but it is not a refactor.

**Tests required (written first).**
- A `Sidebar` test: with roles/permissions for `cashier` (holds `expenses.view`, lacks `treasury.view`) and the `Treasury` backend module enabled, assert the `expenses` nav item **renders**. It must **fail** before the one-line change — paste that failure.
- A second case: a role holding neither `expenses.view` nor `treasury.view` does **not** see the item.
- Extend, do not rewrite, `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx`. Note that suite mocks `t` to return keys (`:25-30`), so assert on the item's `href`/label **key**, not on translated text.

**Discovered-constraint to REPORT, not to fix.** The `bankingAndPayments` group carries `module: 'Treasury'` (`Sidebar.tsx:272`), so the item stays hidden for any tenant without the Treasury **backend module** enabled, whatever the role. That is a company-module gate, not a role gate, and it is outside `UI-07`'s scope (rule 4). Record it in the handback's discovered-findings section for the parent to judge.

---

## 3. Working rules (Codex desktop session)

**Branch and worktree.**
- Fetch first, then branch off **`origin/dev` at the fresh SHA at dispatch time** — do not assume `0c00cf526` is still tip. Record the actual base SHA at the top of your handback.
- Work in a **dedicated `git worktree`**. Never edit or commit in a shared `dev` worktree — parallel sessions mutate branch refs between commands (CLAUDE.md rule 21).
- **Never commit to `dev`**, never force-push anything, never `reset --hard` or `branch -f` a shared ref. The `dev-push-guard` PreToolUse hook enforces part of this; do not work around it.
- **Never `git stash`.** The stash stack is repo-global and shared across worktrees — a stash here corrupts a parallel session.
- Suggested branch name: `codex/ui-wave0-2026-08-11`.
- One commit per implementable task, **except the two authorised exceptions in §2** (T3 is two commits; T6/T8-b produce none).
- **Commit message format — repository standard, not conventional commits.** `AGENTS.md:16` requires `Phase <major.minor.patch>: <imperative summary>` (example: `Phase 0.1.8: Create compliance tables`), and recent `dev` history contains conforming commits (`Phase 0.1.3: Guard fiscal identity settings UI`, `Phase 0.1.4: Document company identity guard completion`). r1's `fix(web): …` examples violated that and are withdrawn. Name the finding id in the imperative summary, e.g.:
  ```
  Phase <x.y.z>: Fail closed in canAccessModule and type the ModuleKey union (UI-01/UI-02)
  ```
  ⚠️ **PARENT/OWNER DECISION NEEDED before your first commit — do not guess.** The `<major.minor.patch>` series for this wave has not been assigned, and live `dev` history is mixed (many recent commits use `docs(...)`, `fix-round-N: …`, `integrate: …` instead). Ask the parent session for **either** (a) the phase number to start from, **or** (b) an explicit recorded exception permitting one of the observed non-phase prefixes. Record the answer verbatim at the top of your handback. Do not invent a phase number and do not fall back to `fix(web):` on your own authority.
  **r4 mechanics (the answer arrives before you start, not mid-run):** the parent writes it into `commit_series:` in `docs/handoff/progress/ui-wave0.progress.yaml` **before dispatch**. Read it at `M0` and quote it verbatim in the report. If it is still `null` at `M0`, set the wave `status: blocked_precondition`, name it in `blockers:`, and STOP — an autonomous run has no in-thread parent to ask.

**Tests.**
- **TDD, always: failing test first.** A commit whose test was written after the implementation does not satisfy this brief.
- **NEVER run the full PHPUnit suite** — it crashes the owner's laptop. This wave touches no backend code, so you should not be running PHPUnit at all. If you must, run **targeted tests by path only**.
- Frontend: run vitest **by path** while iterating (`pnpm vitest run src/features/vouchers`), full `pnpm test` only at task close.
- **UI E2E is required before merge** (`AGENTS.md:12-16`): run `pnpm --filter @autoerp/web test:e2e` at final branch state and **document any intentionally skipped spec with a screenshot**. T12 deletes the `MTP-GL-28` block from `e2e/money-campaign/finance-permissions.spec.ts`, so this run is not optional theatre — it is the proof that deletion left the rest of that spec intact. If the E2E environment is unavailable to you, say so explicitly and name the blocker; **do not silently omit it**, and do not report the branch green without it.
- Beware vitest zombies: hung worker pools survive the parent kill. If the machine gets slow, `ps aux | grep 'node (vitest'` and kill the workers.

**Frontend conventions (each is CI-enforced — violations fail, they do not warn).**
- **Design tokens only.** Import `tokens`, `textColors`, `borderColors`, `semanticColorTokens` from `@/lib/designTokens`. No hardcoded Tailwind palette classes (CLAUDE.md rule 18). Enforced by ESLint + `tools/audit-design-system.mjs`.
- **All user-facing text via `t()`** with react-i18next (rule 11). Adding a namespace touches three places in `lib/i18n.ts`; removing one touches the same three plus the `ns` array (`i18n.ts:442`).
- **TanStack keys:** every tenant-data query key uses `tenantScopedKey([...])` (and `locationScopedKey` where location scope applies) so tenant/company are suffixes. Enforced by `apps/web/tools/audit-tanstack-keys.mjs` in lint, preflight and CI.
- **No `any`**; `unknown` + type guards. `pnpm typecheck` is strict.
- **No `parseFloat`/`Number(...)` on money or quantity** (rule 19). Not expected to arise in this wave; if it does, use `MoneyInput`/`QuantityInput` and the formatters.
- Types flow from backend — never hand-edit generated types (rule 7). Not expected in this wave.

**Deletions — the full checklist.** For each deleted route or page, all six of these, or the deletion is incomplete:
1. the route declaration **and** its lazy import;
2. the component file(s) and any component that becomes unimported as a result (verify, do not assume);
3. every barrel/index export of those components;
4. sidebar, command-palette, quick-create, hub-card and breadcrumb references;
5. i18n keys that become orphaned, in **all** locales — **except `finance:hub.cards.treasuryOverview.title`**, which is preserved (T12);
6. the tests belonging to the deleted components (and only those — do not weaken a surviving test to make it pass).

**Scope discipline.** One task at a time (CLAUDE.md rule 4). If a task needs a file outside its stated scope, note it and continue; do not expand. If a task appears blocked or wrong, **stop and report** — do not improvise a decision the owner reserved.

**Before you claim completion.** Run `./scripts/preflight.sh` from the repo root and paste the result. Note it invokes the manifest drift check at `scripts/preflight.sh:193-195`, which is one of your acceptance gates.

**Exact invocation and expected outcome for this no-backend lane** (r1 said "preflight green", which is unreachable here — corrected):

- **Invocation:** plain `./scripts/preflight.sh`, i.e. the default `PREFLIGHT_SCOPE=paths` with **no** `PREFLIGHT_TEST_PATHS`. Do **not** set `PREFLIGHT_SCOPE=full` — that runs the entire PHPUnit suite and crashes the owner's laptop.
- **Expected outcome:** the script prints a loud red **`SKIPPING PHPUnit … this preflight is INCOMPLETE`** block (`scripts/preflight.sh:85-98`) and sets `PHPUNIT_SKIPPED=1`. **That is the correct, accepted result for this wave** — it touches no `apps/api/**` code, so there is nothing for PHPUnit to cover.
- **How to report it:** `PREFLIGHT: PHPUnit SKIPPED (no backend change in this wave); all other stages green.` Report it as **skipped**, never as green, and paste the skip banner alongside the tail. A handback that says "preflight green" without that qualification is rejected at gate.
- **Every non-PHPUnit stage must genuinely pass** — Pint, PHPStan, TypeScript, ESLint, the TanStack-key audit, Vitest, the fiscal-fixture parity check, and the manifest drift check all run unconditionally in both modes (`scripts/preflight.sh:14-24`). Note that Pint and PHPStan need a working backend env; if either is environment-blocked rather than passing, say **which** and **why**, and treat the frontend commands in §4 item 5 as the load-bearing evidence.

---

## 4. Verification contract

### Per task
As specified in each task's **Acceptance criteria** and **Tests required** — unchanged by r4. Every acceptance claim in the report carries either a pasted command + output, or a `file:line`. "Verified" without evidence is not accepted — and it is now **your own bridge reviewer** who checks that, at the close of the milestone the task belongs to, before the parent ever sees the branch.

### Whole branch (all six, at final state) — this is milestone `M8`

1. **`./scripts/preflight.sh` run, with the PHPUnit skip reported as a skip.** Paste the tail **and** the red skip banner. Per §3, `PREFLIGHT_SCOPE=paths` with no `PREFLIGHT_TEST_PATHS` is the correct invocation for this no-backend lane, and the resulting "INCOMPLETE" banner is the expected outcome — **but it must be reported as skipped, never as green.** Every non-PHPUnit stage must pass; name any stage that is environment-blocked rather than passing.
2. **Manifest drift check green post-regeneration:** `bash scripts/factory/check-manifest-drift.sh` exits **0**. Paste the command and exit code. The regenerated `routes-web.yaml` must show `/sales/invoices/:id → InvoiceDetailPage` (proves T2 landed before `T3(a)`) and must **not** contain `/pos/shifts`, `/marketing`, the `/finance` index entry, or `/settings/chart-of-accounts`. This is the **only** state at which the drift check must pass; intermediate deletion commits are permitted to drift (§2).
3. **Design-system audit clean, with a categorised delta.** `cd apps/web && node tools/audit-design-system.mjs` exits 0 with `0 new, 0 stale`. Paste the **per-category before/after table** for the whole branch and account for every category that moved. The **only** movements permitted are:
   - **C6 grows** — T7's detector fix, expected;
   - **C2 shrinks by exactly 1** and **C3 shrinks by exactly 1** — T10's two hand-removed baseline lines (`baseline.json:202` `ShiftDashboardPage`, `:717` `POSShiftsDashboard`), because their source files no longer exist;
   - **C1, C4, C5 do not move at all**, in either direction.

   Any other movement — including a C2/C3 *increase*, or a C2/C3 decrease not traceable to those two exact deleted files — is a **fail**: stop and report. Present it as a categorised diff with the cause named per line. **Do not** report "only C6 moved" — that claim is now false by construction, and asserting it means you did not actually look. *(If the parent later authorises T8-b, this contract must be amended to add its two C2 (`:224-225`) and one C3 (`:665`) entries; until then those three lines must still be present.)*
4. **A grep per deleted route proving zero remaining references.** Quote every pattern and use `-E` for alternation — an unquoted or mis-escaped pattern producing a false zero is a known way to burn a "verified" claim. Restrict to source extensions where stated; a filtered zero must be accompanied by the enumeration of what the filter excluded.
   - `grep -rnE "/pos/shifts" apps/web/src --include='*.ts' --include='*.tsx'` — the only permitted hits are the API path strings in `features/pos/api/shiftApi.ts` and `shiftHistoryApi.ts`; call them out explicitly. Plus the `.md` disposition grep and the baseline grep from T10.
   - `grep -rnE "/marketing\b|MarketingHubPage|marketing:" apps/web/src` — zero.
   - `grep -rn "FinanceHubPage" apps/web/src` — zero; **plus** T12's production-only inbound-link grep (tests excluded) returning zero, **plus** the explicit enumeration of the seven intentional `HubCard.test.tsx` fixture literals (lines 12, 22, 35, 54, 63, 87, 103) that the filter excludes, **plus** the no-`Navigate`-for-`/finance` grep. A bare global `/finance` grep is *expected* to be non-zero and is **not** an acceptance artefact.
   - `grep -rn "settings/chart-of-accounts" apps/web/src` — zero.
5. **`apps/web`: `pnpm typecheck`, `pnpm lint`, `pnpm test` all green.** `pnpm lint` chains the tanstack-keys, design-system and quantity audits plus the ESLint-rule tests — all must pass.
6. **UI E2E run: `pnpm --filter @autoerp/web test:e2e`** (`AGENTS.md:12-16`). Paste the summary. `e2e/money-campaign/finance-permissions.spec.ts` must pass with `MTP-GL-28` removed and every other test in it untouched. **Any intentionally skipped spec must be documented with a screenshot**, per the repository rule. If the E2E environment is unavailable, state that explicitly with the blocker — an omitted E2E run is an incomplete handback, not a green one.

### Self-certification is not accepted — you gate yourself, with an independent reviewer
Do not declare the wave complete on your own say-so. **At the end of every milestone you run the
adversarial review yourself** via `scripts/adversarial-review.sh` (Opus, read-only) with that
milestone's `review_lenses` from `docs/handoff/progress/ui-wave0.progress.yaml` — `frontend-conventions`
on the FE work, `tenancy-authz` on the gating pair (`T4`/`T15`) and at the whole-branch gate, plus a
general adversarial lens throughout. Expect a fix round. **The next milestone does not start until the
previous one's register says `VERDICT: ACCEPT`** and the YAML records it. Every **P1** finding must be
closed (or ruled by an owner gate) before a milestone passes; **P2** close-before-merge; **P3** may ship
with a ticket recorded in the report.

At the very end — and only there — **the parent Claude session performs the terminal audit** (a
full-branch adversarial review plus the specialized reviewer agents) and **owns the merge into `dev`**.
**You never merge, never push, never touch `dev`.**

---

## 5. Final report format (written at completion or at a STOP — not between tasks)

Write an implementer report to **`docs/handoff/HANDBACK-ui-wave0-2026-08-11.md`**, appending each
milestone's per-task sections as you close it — the report no longer pauses you. **On completion, or on
any STOP:** finish the report, make sure `docs/handoff/progress/ui-wave0.progress.yaml` reflects reality
(per-milestone `status`, `commit`, `verdict`, `fix_rounds`; wave `status` + `blockers` + `findings`),
leave the tree clean (commit or revert WIP — **never** `git stash`), and end your run. **The parent
Claude session then performs the terminal audit and owns the merge into `dev`; the executor NEVER
merges and NEVER pushes.** Structure:

1. **Header** — base SHA branched from, branch name, worktree path, final SHA, commit list (one line each), **and the parent's verbatim answer on the commit-message format (§3)** — as of r4 that answer is the `commit_series:` value the parent wrote into `docs/handoff/progress/ui-wave0.progress.yaml` before dispatch; quote it verbatim.
2. **Per-task section**, T1…T15 (T6 and T8-b appear as BLOCKED records with no commit), each with:
   - status: `DONE` / `BLOCKED` / `DEFERRED` / `PARTIAL` (no other values);
   - what changed, as `file:line` references;
   - the failing-test-first evidence: the test file, and what its failure looked like before the fix;
   - each acceptance criterion, restated, with its evidence (command + output, or `file:line`);
   - anything you decided that the brief did not specify — flag these prominently; they are the highest-value review targets.
3. **Whole-branch verification section** — the **six** contract items in §4, each with pasted output.
4. **Blocked items** — T6 and T8-b at minimum (see §6), with the evidence and the decision needed. State plainly that they produced no commit.
5. **Discovered findings not in scope** — at minimum: `TermsOfServicePage.tsx` hardcoded brand copy (T14), the `touchOptimized` **unassigned-ownership gap** (T8 / §6 F-2b), the `bankingAndPayments` Treasury module-gate constraint on `/expenses` (T15), and anything else you tripped over. Each with `file:line` and a one-line description. Do not fix them.
6. **Deviations from this brief** — every one, with justification. A silent deviation found at gate time invalidates the handback. **The deletion of the `MTP-GL-28` E2E block (T12) must appear here** even though this brief authorises it.
7. **Completion language — a hard constraint.** Do **not** write "Wave 0 complete" or "`UI-43` closed" anywhere in the handback. Rev 5's Wave-0 closes-list is not fully dischargeable by this dispatch: `UI-14` (T6) and `UI-43`'s `BarcodeHero` claim (T8-b) are blocked on F-1, and `UI-43`'s `touchOptimized` claim is unassigned (F-2b). Report per-finding status instead, and mark `UI-43` `PARTIAL` with its four claims itemised.

Cross-reference, don't restate: cite `00-EXECUTIVE-REPORT.md` finding ids rather than re-arguing the findings.

---

## 6. Flags for the parent session (do not resolve these yourself)

**r4 execution-mode note:** these are the wave's **owner gates**, mirrored in
`docs/handoff/progress/ui-wave0.progress.yaml` under `owner_gates:`. You may not decide any of them.
**F-1** and **F-2b** are **standing constraints** — `T6` and `T8-b` produce no commit, `touchOptimized`
is not implemented, and neither Wave 0 nor `UI-43` may be reported complete; record them under
`findings:` and in the report. **F-6** is a **pre-dispatch** gate: the parent writes `commit_series:`
into the YAML before you start, and a `null` value at M0 is `blocked_precondition` → STOP. **F-3**,
**F-4** and **F-5** are informational records to be **stated plainly**, not stops. If a *new* owner
question appears anywhere else, that is STOP condition B — set `blocked_owner`, name the exact question
in `blockers:`, end the run.

**F-1 — Wave 0 collides with the parked Rafiq directory, twice.** Rev 5 lists `UI-14` and `UI-43` in Wave 0, but:
- `UI-14`'s target is `apps/web/src/features/products/editor/components/ProductEditHero.tsx:137`;
- `UI-43`'s `BarcodeHero` claim targets `apps/web/src/features/products/editor/components/BarcodeHero.tsx` (+ its test).

Both sit inside `features/products/editor/`, which owner ruling **OQ-4 (PARKED)** and `00 §E:203` put off-limits until the unmerged Rafiq round-2 worktree is evaluated. `00 §E` scoped its own carve-out to the *hero band restyle* (`ProductHeroShell` / `tokens.productHero`, which live in `features/products/sections/` and `lib/`) — it did **not** clear the editor directory itself. Default disposition in this brief: **both BLOCKED, not implemented**. The parent must either (a) confirm the block and reschedule both behind the Rafiq decision, or (b) explicitly authorise the two deletions as merge-conflict-tolerable. Neither is the executor's call.

**Gate-r1 reviewer's merge-risk assessment (added in r2 — informs the decision, does NOT unblock it).** The escalation scope was independently **CONFIRMED**: the ruling of record parks the Rafiq skin (`OWNER-DECISIONS:13-15`), rev 5 says work under `features/products/editor/` waits on it (`00 §4:193-203,261-269`), and both targets are inside that directory (`ProductEditHero.tsx:115-136` for the unconditional dot; `BarcodeHero.tsx:44-71` as a standalone component). The reviewer's read-only findings:

- **The source deletions are safely separable.** The registered Rafiq worktree points at `feat/rafiq-skin-experiment` (`.git/worktrees/erp.rafiq-skin/HEAD:1`), that worktree was clean, and a read-only comparison of the branch against its merge base found **no branch-unique change under `features/products/editor/`**. The production hero path imports `ProductEditHero`, not `BarcodeHero` (`features/products/sections/ProductHeroSection.tsx:1-2,20-39`). Deleting the unused `BarcodeHero` and removing the one `successDot` span are therefore **low-risk, mechanically separable source changes**, not substantive conflicts with present Rafiq work.
- **The real shared-file risk is the generated baseline, not the source.** Deleting `BarcodeHero` forces removal of its two C2 (`audit-design-system-baseline.json:224-225`) and one C3 (`:665`) entries, and the Rafiq branch **does** change that shared generated file — so a baseline merge/regeneration conflict remains plausible.
- **Reviewer's recommendation to the owner:** it is reasonable to authorise these two narrow deletions as merge-conflict-tolerable, **provided the authorisation is explicit and the baseline reconciliation is assigned to a named owner.**

**This assessment lowers the estimated risk; it does not constitute the authorisation.** Both items **remain BLOCKED** and unimplemented in r2. The executor must not act on the recommendation. Only an explicit parent/owner ruling in-thread changes the disposition — and if it comes, it must also name who reconciles the baseline against the Rafiq branch.

**F-2 — `UI-07`: RESOLVED in r2.** r1 left `UI-07` (`00 §5:244`) unassigned. Gate r1 confirmed the omission, so r2 **dispatches it as T15**. No parent action remains on F-2. Kept here as the audit trail for why T15 exists out of numeric order.

**F-2b — `UI-43`'s `touchOptimized` claim has no owner. PARENT/OWNER DECISION NEEDED.** Rev 5 assigns **all four** split `UI-43` claims to Wave 0 (`00 §5:240-248`). This brief dispatches `T8-a` (via T4) and `T8-c`, blocks `T8-b` (F-1), and **defers `touchOptimized` (15 TSX files) with no recorded reassignment**. The "it is a 15-file API change, not a sweep" argument is a **scheduling recommendation, not a source-backed ownership transfer** — no owner ruling moves it out of Wave 0. The parent must either (a) dispatch it, (b) obtain and record an explicit owner/orchestrator reassignment to a named later wave, or (c) accept it as a standing Wave-0 gap. Until one of those exists, **neither Wave 0 nor `UI-43` may be reported complete** (§5 item 7). This is a new flag in r2; r1 asserted F-2 was the only ownership omission, which gate r1 refuted.

**F-3 — T4 tightens live navigation for real roles.** Making `canAccessModule` fail closed turns `goods-receipt.create-standalone` (`Sidebar.tsx:182`) from "visible to everyone" into "visible to admin/manager only", and `inventory.view` (`Sidebar.tsx:215`) into a real check. That is the intended fix, but it is a **user-visible nav change**, not a pure refactor. Worth an explicit gate-time acknowledgement.

**F-4 — `/finance` deletion produces a catch-all fallthrough.** With no redirect (owner-ruled), a bookmarked or typed `/finance` lands on `/dashboard` via `routes/index.tsx:3164`. The research file's `OQ-1` (viewer-role regression, `11-research-finance-hub.md:192`) is moot under "delete outright" — there is no redirect target to be bounced from. Recorded so nobody re-opens it.

**F-5 — T15 also changes live navigation, in the opposite direction to T4** (added in r2). Where T4 *removes* nav items from roles that never held the permission, T15 *adds* `/expenses` for `cashier`, `operator` and `viewer` — roles that already hold `expenses.view` and can already reach the route directly (`routes/index.tsx:1679`). It closes a rule-12 parity gap rather than widening access, but it is user-visible on the same gate-time footing as F-3.

**F-6 — the commit-message format for this wave is unassigned** (added in r2). `AGENTS.md:16` mandates `Phase <major.minor.patch>: <imperative summary>`; live `dev` history is mixed. The parent must supply the phase number or a recorded exception before the executor's first commit (§3). Blocking for commit hygiene only — no implementation work waits on it.

---

## 7. Revision log

### r3 → r4 — 2026-08-12 (execution-mode retrofit)

| Change | Rationale | Where |
|---|---|---|
| **Execution-mode retrofit per owner ruling 2026-08-12 — task content untouched.** The wave now runs autonomously under `docs/handoff/SELF-REVIEW-HARNESS.md`: an EXECUTION MODE banner, self-review at every milestone via `scripts/adversarial-review.sh` (`claude -p --model opus`) looping scoped fix rounds until ACCEPT, state tracked in the new `docs/handoff/progress/ui-wave0.progress.yaml` (M0–M8 = §2's binding dependency chain batched, each with `review_lenses`), the per-task "parent runs the gate" handbacks replaced by that self-gate, and the report contract rewritten so the parent Claude session performs only the **terminal audit** and owns the **merge**. Owner gates (F-1 Rafiq, F-2b `touchOptimized`, F-6 commit series, the §1 DO-NOT-TOUCH set) are preserved as **hard STOPs**. | Owner ruling 2026-08-12: the three UI-lane Codex handovers become fully autonomous runs with per-milestone adversarial self-review gates by Opus, no per-milestone handback. | header, banner, §4 self-certification, §5, §6 preamble, `docs/handoff/progress/ui-wave0.progress.yaml` |

**No task definition, acceptance criterion, test requirement, grep, baseline contract, deletion checklist or scope boundary changed in r4.** Every §2 entry, the §3 working rules and the six §4 whole-branch items are byte-identical in substance to r3; the gate-r2 verdict on r3's content therefore still stands.

### r1 → r2

Source of every change below: `docs/superpowers/reviews/2026-08-11-wave0-brief-gate-r1.md` (gate verdict **REVISE**). Defect numbers are that review's §3 numbering. Where the review refuted or corrected an r1 claim, the review's version was adopted verbatim — it was verified against the tree and r1 was not.

| Defect | Review's finding | What changed in r2 | Where |
|---|---|---|---|
| **D1** | The design-system final gate is unsatisfiable after T10: it deletes one C2 and one C3 baselined source (`baseline.json:202,717`), the audit fails on stale entries (`audit-design-system.mjs:443-450`), yet r1 forbade any C1–C5 movement. | Baseline shrink **assigned to T10** as two hand-removed lines, `--write-baseline` explicitly forbidden there. §4 item 3 rewritten as a **categorised delta contract**: C6 grows, C2 −1 and C3 −1 traceable to the two deleted files, C1/C4/C5 frozen; anything else fails. "Only C6 moved" explicitly withdrawn. T7's C1–C5 rule re-scoped as **commit-local**. T8-b's baseline cost (`:224-225`, `:665`) recorded against a possible future authorisation. | T7 accept., T10 delete-list, §4.3, T8-b |
| **D2** | Manifest ownership/ordering/commit rules were mutually unsatisfiable (order-free vs T2→T3 vs per-deletion regen vs one-commit-per-task); T9 also consumes T1/T3. | New binding **dependency chain + single-manifest-owner model** in the §2 preamble: `T2 → {T10..T13} → T3(a) → T3(b) → T9`, `T1 → T9`. Two authorised exceptions to one-commit-per-task (T3 is two commits; deletion commits may drift). T3(a) re-specified to run after the deletions. "Manifest regenerated" struck from T10/T11/T12/T13 acceptance and replaced with "T3 owns it". | §2 preamble, T3, T10–T13 |
| **D3** | T12's zero-reference grep must fail on unrelated `HubCard.test.tsx` fixtures, and it missed two real stale consumers (`finance-permissions.spec.ts:296-353`, `ui-audit-shots.mjs:30-45`). | Added a **three-row disposition table**: delete the `MTP-GL-28` E2E block, delete the `ui-audit-shots.mjs:40` entry, retain `HubCard.test.tsx` byte-identical. Acceptance grep replaced with a **production-only** search plus mandatory enumeration of the excluded test literals. §4 item 4 says a bare global `/finance` grep is expected non-zero and is not an artefact. | T12, §4.4 |
| **D4** | T10's inventory omitted current-tense docs (`features/pos/README.md`, `IMPLEMENTATION_SUMMARY.md`) and the baseline entries, so its promised grep was unachievable. | Both doc files added to T10's target list with an explicit **two-option disposition** (edit, or retain-as-historical with a dated superseded note + narrowed grep); `features/pos/index.ts:14` correction made non-optional. T10 grep split into **code / documentation / baseline** greps with `-E` and `--include` filters. | T10 |
| **D5** | "Exactly four sites" is false — four keys, **five** literal occurrences; the readonly map breaks `hasAnyPermission`; the call-surface census missed `CashPositionWidget`. | Wording corrected to "**four invalid keys across five literal occurrences**". Call surfaces **five → six**, adding `CashPositionWidget.tsx:124-127`. New mandatory pre-step widening `hasAnyPermission` **and** `hasAllPermissions` to `readonly Permission[]` (with the correct plural name). The **exact expected five-diagnostic set** enumerated by `file:line`, with "stop and report on a sixth". | T4 |
| **D6** | r1 credited `Sidebar.test.tsx` with protecting the locale key; its `t` mock returns keys, so it cannot. | Replaced with a **two-row table** separating what each test guarantees; only `i18nRawKeyCoverage` may be cited as value-survival evidence; a green Sidebar suite explicitly declared insufficient. Restated in T12's acceptance criteria. | T12 |
| **D7** | F-2 was not the only ownership omission — rev 5 assigns split `UI-43` (incl. `touchOptimized`) to Wave 0. | `UI-07` **dispatched as T15** (F-2 resolved). `touchOptimized` reframed from "deferred" to an **unresolved ownership gap**, raised as new flag **F-2b** with three parent options, and a hard prohibition on reporting Wave 0 or `UI-43` complete added to §1, T8 and §5 item 7. | §1, T8, T15, §6 F-2/F-2b, §5.7 |
| **D8** | T14 had no failing-first proof for `TenantSupportAccessPage` and would break its provider-less test harness. | Added a **required seeded-product render test** on `TenantSupportAccessPage` (fails on the current literal, asserts the product name, asserts `AutoERP` absent) and a mandatory wrapper fix via `renderWithProviders` / explicit `ProductConfigProvider`, with the `useProductConfig` throw cited. Privacy test retained as the second proof; the locale-content test explicitly demoted to a data assertion. `AdminSupportAccessPage` recorded as verified out-of-scope. | T14 |
| **D9** | r1 recommended conventional commits (repo requires `Phase x.y.z:`) and omitted the required UI E2E run. | Commit examples replaced with the `AGENTS.md:16` format; the unassigned phase number raised as **F-6, parent/owner decision needed before the first commit**, with "do not invent a phase number" stated. `pnpm --filter @autoerp/web test:e2e` added to §3 and as **new §4 item 6**, including the screenshot rule for skipped specs. | §3, §4.6, §6 F-6 |
| **D10** | "Nothing is blocked" and "fourteen independently committable tasks" contradict T6/T8-b. | Entry criteria rewritten: T6 and T8-b are **non-executable escalation records** producing no commit and **excluded from the task count**. §2 now reads "sixteen numbered entries, fourteen implementable tasks", enumerated. Restated at T8-b and in §5. | §1, §2, T8-b, §5 |
| **D11** | T12 made orphan-key pruning optional while the global checklist made it mandatory. | "may be pruned" **withdrawn**. Pruning is mandatory with **per-key grep evidence**; the single named exception is `finance:hub.cards.treasuryOverview.title`; a surviving key requires a named `file:line` consumer. | T12 |
| **D12** | "Preflight green" is unreachable for a frontend-only lane — default preflight skips PHPUnit and calls itself incomplete. | Exact invocation and expected outcome specified (`./scripts/preflight.sh`, default `PREFLIGHT_SCOPE=paths`, no `PREFLIGHT_TEST_PATHS`), the red INCOMPLETE banner declared the **correct** result, and the required reporting string given: **skipped, never green**. `PREFLIGHT_SCOPE=full` forbidden. §4 item 1 aligned. | §3, §4.1 |
| **F-1** | Escalation scope CONFIRMED; reviewer added a merge-risk assessment recommending the owner *may* authorise the two deletions. | Reviewer's assessment incorporated in full (clean Rafiq worktree, no branch-unique change under `features/products/editor/`, production hero imports `ProductEditHero`, baseline is the real shared-file risk, recommendation + baseline-reconciliation condition). **Both items remain BLOCKED** — the assessment is explicitly labelled as not constituting authorisation. | §6 F-1, T8-b |
| **F-2** | UI-07 omission CONFIRMED. | Added as **T15** with acceptance criteria drawn from the exec report's `UI-07` row (`00 §2:98`) and `01 §4a G-19`, re-verified at `0c00cf526`: one-line fix at `Sidebar.tsx:280`, role-level tests, an explicit do-not-touch list for the treasury siblings, and the `bankingAndPayments` module-gate constraint recorded as report-only. T1–T14 **not renumbered**. | T15 |

**Not changed, deliberately:** the review's §2 rows verdicted **CONFIRMED** (the invalid-key set, the self-map resolutions, the `parts_catalog` and `partners` resolutions, the live manifest drift and its `InvoiceDetailPage`→`KeyedByRouteId` hunk, the T11/T13 evidence, the preserved treasury key, the `useProductConfig` mechanism) were already correct in r1 and are carried forward untouched. The review's §5 finding that **T10–T14 are authorised expansion, not scope creep** (refuting that charge) required no edit. The §6 residual risk on T1's discovery rules is already answered by T1's standing per-page provenance and method-disclosure requirement.
