# CODEX DISPATCH — POS Receipts web view + receipt-level reporting (build)

**Revision:** **r3 — 2026-08-12 (consistency-gate fix round).** r2's execution-mode content is untouched; three gate findings (R-1/R-2/R-3) are applied to §1 and §3. See §10.
**Executor:** Codex Desktop session (implementation), running as a **fully autonomous self-reviewing wave**. The parent Claude session performs the **terminal audit** and owns the **merge** — it does not gate you between waves.
**Normative content:** `docs/handoff/SPEC-pos-receipts-reporting-2026-08-11.md` (**r5, gate-PASSED**). **This brief WRAPS that spec. It never restates or overrides it.** Where this brief and the spec appear to disagree, the spec wins — **except** for the items in §3 (ruling closures) and §4 (Addendum A), which are post-spec rulings and corrections and are **binding over the spec text they name**.
**Lane:** POS reporting / first-tenant launch. `apps/api` + `apps/web`. POS scope only.
**Reference SHA at writing:** `origin/dev` @ `7d85232cc`. **Do not build on it — fetch and branch off the fresh `origin/dev` tip at dispatch time** and record the actual base SHA in your handback.

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between waves. At the end of every milestone you run the adversarial review YOURSELF by invoking
> Opus through the CLI bridge `scripts/adversarial-review.sh` (it calls `claude -p --model opus`),
> read its register, and loop **scoped** fix rounds until ACCEPT — then move on.
> - **Track state in `docs/handoff/progress/receipts-build.progress.yaml`** — read it first, update it
>   after every milestone (status, commit SHA, verdict path, fix_rounds). It is the resume point if you crash.
> - Milestone ↔ wave map (the wave contents are the spec's `§7.5`, unchanged): **M0** = dispatch
>   preconditions · **M1** = wave 1 · **M2** = wave 2 · **M2b** = wave 2b (conditional) · **M3** =
>   whole-lane gate. Wave 3 is **not dispatched** (§2).
> - The bridge call, once per milestone (lenses come from that milestone's `review_lenses`).
>   **Exception — M0 is setup-only:** no bridge call, no adversarial register; M0 completion = YAML
>   `status: passed` with `base_sha` recorded (its `review_lenses` list is empty by design). The
>   first bridge call of the run is M1's. Bridge invocation:
>   ```
>   scripts/adversarial-review.sh \
>     --brief   docs/handoff/CODEX-DISPATCH-receipts-build-2026-08-12.md \
>     --milestone M<n> \
>     --lenses  "<comma list from the milestone's review_lenses>" \
>     --range   <base_sha>..HEAD \
>     --out     docs/handoff/reviews/receipts-build/M<n>-round<r>.md \
>     --round   <r>
>   ```
>   Exit **0 = ACCEPT** · **2 = CHANGES-REQUIRED** · **3 = tool error → treat as CHANGES-REQUIRED
>   (fail closed; never proceed on a tool error)**. Record the `--out` path in the milestone's
>   `verdict:` and the exit result in `last_verdict:`.
> - Wherever this brief says "handback → parent adversarial review", "the parent session runs the
>   adversarial review gate", or "handback → parent review" — **that is now this self-review loop**, run
>   with the milestone's `review_lenses`. A milestone is not closed until its register says ACCEPT.
> - **Resume semantics:** a fresh session resumes from the YAML + the newest register under
>   `docs/handoff/reviews/receipts-build/`. Never re-run a milestone whose `status: passed`.
> - **STOP and escalate only at the three harness STOP conditions:** (A) fix rounds exhausted
>   (`max_fix_rounds: 5`) → `blocked_review`; (B) an **owner gate** → `blocked_owner`; (C) an
>   architecture contradiction → `blocked_architecture`. Set the YAML `status` + `blockers` and end your run.
> - **The owner gates on this lane, each a hard STOP if it fires** (full text in the YAML `owner_gates:`):
>   **OI-3 / wave 3** — no cross-receipt total, footer sum, VAT roll-up or aggregate figure anywhere in
>   this build, not even temporarily; if a task appears to need one, STOP. **Wave 2b / Lane A0** — a
>   STOP-**and-check** at M2 close: verify in code whether A0 landed; if it has not, do **not** ship
>   screen (d) early "with captions", set M2b `blocked_owner` and end the run with waves 1+2 as the
>   delivered scope (that is the specced outcome, not a failure). **`event_version` 5 / OP-22 unit
>   lane** — excluded in every form. **F-1** — if the three accountant keys are already present on your
>   base or a conflicting edit appears, STOP; do not re-edit the seeder. **Device (`apps/pos/**`)** — if
>   a task appears to need a device change, STOP.
> - **Gate wiring, deliberate:** no milestone in the YAML carries an `owner_gate:` field — the harness
>   reads that field as an **unconditional** STOP (harness step 4 / condition B) and every gate here is
>   conditional or a standing exclusion. The `owner_gates:` list is the record; the firing condition
>   lives in the text. Stop only when a stated condition actually fires.
> - **No clock.** Do not call `date`. Use `git rev-parse --short HEAD` as the `updated:` marker.
> - Per-milestone registers go to `docs/handoff/reviews/receipts-build/`. **Branch NOT merged, NOT
>   pushed** — the parent Claude session runs the terminal audit and owns the merge into `dev`.

---

## 0. Read before starting, in this order

| # | Document | Why |
|---|---|---|
| 1 | `docs/handoff/SPEC-pos-receipts-reporting-2026-08-11.md` **r5** | **The build.** §3 screens, §4 backend/permissions/deploy, §5 i18n, §6 cleanup, §7 test plan + gates + wave table, §8 open items. Read it whole before writing a line. |
| 2 | `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md` | Ruling of record. Esp. the **2026-08-12** sections: post-gate rulings, research round 3 outcomes, the OI-17 reframe. |
| 3 | `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-research-open-question-best-practices.md` **Parts 2 and 3** | Source for **Addendum A**: the accountant grant table (§2.3), the three findings that correct spec assumptions (§2.5), the deploy steps (§2.7), and the currency rules (§3.3). |
| 4 | `docs/handoff/FINDINGS-other-problems-2026-08-11.md` rows **OP-22 / OP-23** | OP-23 folds into your A-1 work (§4). OP-22 is context for OI-17 only — **not** yours to fix. |
| 5 | `CLAUDE.md` rules 1–21 (esp. 2, 4, 7, 11, 12, 13, 18, 19, 20) + `docs/conventions/` | Non-negotiable house rules. Rule 19 (money/quantity precision) and rule 12 (route middleware + both-layer gating) are load-bearing here. |
| 6 | `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/18-research-nf525-chain-vs-sidecar.md` | **Context only.** Explains why receipts v1 ships unit-less. Do **not** implement anything from it (§3, OI-17). |

Research docs `12`, `14`, `15`, `16` are the spec's inputs. The spec consolidates them — read them only if you need the provenance of a specific claim.

---

## 1. Mission

Build `SPEC-pos-receipts-reporting-2026-08-11.md` **r5, exactly**, wave by wave:

- the POS receipts register `/pos/receipts` (SALE-only, disjoint),
- the receipt detail at `/pos/receipts/:id` — the URL two existing features already dead-link to,
- the separate refunds & voids register `/pos/receipts/refunds`,
- the backend items S-1…S-13 as specced (additive except S-4/S-5, which intentionally reshape `show()`),
- the launch-required accountant access repair **A-1** (compliance-export re-gate + nav entry) and **A-2** (the seeder grant),
- the **GATE-3 / GATE-5** POS sidebar least-privilege re-key, per the frozen child→route table at **§4.2.1**,
- the §6 cleanup items and the §5 i18n work.

You are not being asked to design anything. Every screen, wire field, permission key, index DDL, endpoint contract, test id and i18n key is already frozen in the spec.

### Scope guard — DO NOT TOUCH

| Area | Why |
|---|---|
| **Lane A0** (`ES-07` honest-verification fix) — sources: `docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md` **§3** lane table `:58` (A0 = ES-06, **ES-07**, ES-08, ES-09, ES-16, ES-17, ES-41, ES-42, ES-43 — *"FIRST. Blocks every other lane's exit criteria"*) and **§3** per-lane entry criteria `:75` (what A0 must produce before it exits: a `pos:verify-chains` that reads fiscal-era rows and cross-checks `pos_receipts.fiscal_hash` ↔ `fiscal_events.current_hash`); the ES-07 row itself is **§6** item 1, `:161`. The lane is dispatched separately as `docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md`. | Owned by the **fixes session** (Lane A0). Spec `NG-10`. Wave 2b consumes A0; it does not implement it. |
| `/pos/shift-history` (location scoping, `parseFloat` on money, date util) | `OP-15`, standalone FE fix. Spec `NG-6`. |
| Anything in the events / shift-variance dossiers (`docs/sessions/EVENT-SOURCING-AUDIT-2026-08-11/`, `docs/handoff/FINDINGS-shift-variance-gl-2026-08-11.md`, and every `docs/handoff/FINDINGS-other-problems-2026-08-11.md` row **except OP-23**) — **and `ES-31` is excluded from this row in the other direction** | Fixes session — **except `ES-31`, which is owned by the DN-consolidation build**, not by the fixes session and not by you (`docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md` §3 `:68`, Lane **X** *external-execution tracking* — *"ES-31 → the DN-consolidation build"* — and `:94`, the interactions row *"The DN-consolidation build owns ES-31"*; mirrored by `docs/handoff/CODEX-DISPATCH-dn-consolidation-build-2026-08-12.md` §0 row 5 and §1). Either way it is **not yours**: do not implement it, and do not report it as unowned. |
| Creating a POS module (`ModuleName::POS`, `module:POS`, `hasModule('POS')`) | Spec `NG-5` — separate packaging lane. Pages ship **permission-gated**. Leave the one-line forward comment the spec asks for; build nothing. |
| B2B refunds / credit notes | Spec `NG-1`. |
| New analytics dashboards, a totals strip, bulk/CSV export, any write surface on receipts, any row-level reprint button | `NG-2`, `NG-8`, `NG-9`, `NG-3`, `NG-4`. |
| The unit-of-measure (`event_version` 5) lane | §3 below. **Separate queued lane. Not this build.** |
| `apps/pos/**` (device) | No device change is in this build. If a task appears to need one, **stop and report**. |
| `RolesAndPermissionsSeeder` beyond the exact three accountant keys of A-2 | Spec `CL-8` (`pos.void_receipts` seeding) is flag-only. |

**If you find yourself needing a decision the spec reserved, stop and report.** Do not decide it yourself; §8 of the spec lists what is owner-owed and what is builder-owed.

---

## 2. Wave-3 is not dispatched

`OI-3` (totals convention `B-9`) is **still open with the owner**. Spec `NG-8` + §7.5 wave 3 (S-8 + totals strip) are therefore **out of this dispatch entirely**. Do not build a totals strip, a footer sum, or any aggregate figure on these screens — not even "temporarily". See also Addendum A(c) rule 2, which is the reason a naive aggregate is dangerous independently of OI-3.

---

## 3. Ruling closures the builder needs

These close open items the spec r5 recorded as owner-owed. They are the **ruling of record for this build**; the spec text they name is superseded to the extent stated and nowhere further.

| Spec item | Ruling | Consequence for you |
|---|---|---|
| **OI-13** — does "listed separately" permit duplication? (`SPEC…:797`) | **CONFIRMED — strict disjoint registers.** | Build exactly what §3.a specifies: `/pos/receipts` is **SALE-only**, **no type tabs**, refunds/voids live **only** on `/pos/receipts/refunds`. `BT-2`/`BT-2b`/`FT-16` stand unrelaxed. The spec's escape hatch (re-adding type tabs) is **closed** — do not build it. |
| **OI-14** — POS sidebar re-key changes what existing roles see (`SPEC…:798`, GATE-5) | **APPROVED.** | Ship the re-key per the frozen table at **§4.2.1**, including the six identity `MODULE_PERMISSIONS` entries and leaving the **parent** on the `pos` alias. Some roles will see **fewer** POS menu entries after deploy — all of them entries whose routes already denied them. That is the intended outcome; state it in the handback, do not soften it. `FT-14`/`FT-15` lock it. |
| **OI-16** — where the Compliance-export nav entry lives (`SPEC…:799`) | **APPROVED — top-level bottom-section entry**, sibling of Settings, immediately above it. | Implement §4.2 A-1 item 3 verbatim: the `complianceExport` leaf with `permission: 'compliance'`, `section: 'bottom'`, label key `common:navigation.complianceExport`. Do **not** create a "Settings → Compliance" group; do **not** extend `NavItem` with a `permissions[]` array. |
| **OI-17** — receipt detail lines show no unit of measure (`SPEC…:800`) | **CONFIRMED for v1 — receipts ship unit-less.** | Keep `unit_of_measure_code` out of the line allowlist (§3.b.5(iii)). ⚠️ The superseding path — a canonical `unit` field at fiscal **`event_version` 5** — is a **SEPARATE QUEUED LANE** (`18-research-nf525-chain-vs-sidecar.md`; `OP-22`). **Do not implement it, do not prepare for it, do not add a placeholder column, and do not read `pos_receipt_lines.unit` (it is the literal `'pc'`).** The prohibition on current product data is **scoped to the unit label**: **never derive or render a unit symbol/code from present-day master data** (`product.unitOfMeasure->symbol` / `->code` / `products.unit`) — that is the mutable-master-data leak the spec refuses at `SPEC…:335`. **What is expressly NOT prohibited, and IS required: S-4's `quantity_decimals` enrichment** — the spec's *one deliberate current-product enrichment* (`SPEC…:327-328`, S-4 at `SPEC…:433`): widen the `show()` eager-load from `lines.product` to **`lines.product.unitOfMeasure`**, read **`decimal_places` only**, fall back to **4** when the product, the relation or the FK is absent, and unset the relation before serialisation (the `ShiftController.php:305-330` precedent). Quantities render at the product unit's **precision** via that field (CLAUDE.md rule 19; `BT-6`, `FT-6`). **Display precision is not a unit label** — dropping the enrichment to satisfy this row is a spec violation, not caution. Pre-v5 sealed receipts stay unit-less permanently. |
| **OI-1 / A-2** — accountant POS scope (`SPEC…:785,512`) | **CONFIRMED — the three-key grant: `pos.view_receipts`, `pos.view_reports`, `deliveries.view`. `dashboard.owner` REFUSED.** | Edit the `accountant` block of `RolesAndPermissionsSeeder.php` (`:774-804`) adding **exactly those three keys**. Nothing removed, no other role's array touched (`17-research…` §2.3, §2.6). `BT-11` locks the `dashboard.owner` refusal. **The third key, `deliveries.view`, belongs to the DN-consolidation lane's `OI-1a` — see §7 F-1: this build owns the single seeder edit for all three so the two lanes do not both rewrite the generated permission map.** |
| **OI-5, OI-2, OI-4, OI-6, OI-7, OI-9, OI-10** | Already CLOSED in r5. | No action; do not re-open. |
| **OI-15** | Builder obligation, **wave 2b only**. | Re-read the post-A0 verify-chain response shape in code before writing wave-2b copy, i18n keys or tests (§3.d rule 3). |

---

## 4. Addendum A — research corrections (BINDING)

Source: `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-research-open-question-best-practices.md` (research 17), delivered **after** spec r5 was gated. Each item below **overrides** the spec text it names.

### (a) A-1's re-gate uses the NAMED composite compliance key — and OP-23 folds into the same change

**Source:** research 17 §2.5 **Finding 1** and **Finding 3**; `docs/handoff/FINDINGS-other-problems-2026-08-11.md` **OP-23**; spec §4.2 A-1 (design **N-4**).

1. The replacement gate is the **composite `MODULE_PERMISSIONS` key** the spec's N-4 design already froze — `'compliance': ['compliance.export_jet','compliance.verify_chains','compliance.view_reprint_log']` — used by the sidebar leaf, mirroring the route's any-of gate. There is **no** `compliance` moduleKey today; you are adding it. A single-permission fallback of `compliance.view_reprint_log` is **not acceptable** (it bounces a holder of only `export_jet`). Build A-1 items 1–4 exactly as §4.2 states them.
2. **Do not be misled by a side effect:** once `pos.view_receipts` is granted (A-2), the old `pos` alias starts passing for the accountant and the page would *accidentally* unblock. **Do the re-gate regardless** — a compliance page gated on a POS permission is the bug, not the symptom (research 17 §2.5 Finding 1).
3. **OP-23 is now IN SCOPE for this build, in the same change:** `/settings/compliance/fraud-alerts` and `/settings/compliance/fraud-settings` are gated `moduleKey="settings"` (`routes/index.tsx:2455-2463,2465-2473` → `settings.view`), which the accountant does **not** hold — while the backend gates the exact keys the role **does** hold (`Compliance/Presentation/routes.php:24,37`; seeder `:800`). This is the identical bug class as A-1. **Re-gate both routes the same way — on the exact permission keys / the composite pattern — never by granting `settings.view`.** This **supersedes** the spec's `§4.2 A-1` closing line ("note but **do not fix** the sibling `compliance/fraud-alerts`", `SPEC…:511`) and the corresponding `OI-12` cross-reference. Verify the current gate at the fresh SHA before editing — line numbers move.
   - **Nav is not extended for the fraud pages.** They are re-gated only; where they are surfaced in the IA is the OQ-10 Compliance-section work and is **not** yours. Report the residual in the handback.
   - Add route-gate coverage for both fraud routes alongside the A-1 route/permission tests under `tests/Feature/Compliance/` and the FE `RequirePermission` tests.

### (b) The lane-separation report needs NO grant — remove any contrary assumption

**Source:** research 17 §2.3 (the `reports.financial` row) and §2.5.

`/finance/lane-separation` is **already reachable** by the accountant: backend `Accounting/Presentation/routes.php:199`, frontend `routes/index.tsx` gates `permission="reports.financial"`, and the accountant **already holds** `reports.financial` (`RolesAndPermissionsSeeder.php:793`). If any brief, ticket or comment you encounter implies a grant is needed for it, that premise is **wrong** — do not add one, and do not touch that route. (It is also on the DN-consolidation lane's DO-NOT-TOUCH boundary; that lane owns its nav link.)

### (c) Two BINDING currency rules

**Source:** research 17 **Part 3**, esp. §3.1 and §3.3 rules 1–4. Currency is already canonical, required at v3/v4, ISO-validated, **hash-covered**, stored per receipt (`pos_receipts.currency`) and emitted by both endpoints. Nothing about the fiscal schema changes. What *is* fragile is presentation and aggregation:

1. **Never format money with the company-default currency. Format with the receipt's own `currency` / `currency_scale`.**
   Three of the four `formatCurrency` implementations silently **default to `'EUR'`** when called without a currency — `apps/web/src/lib/decimal.ts:179`, `apps/pos/src/lib/currency.ts:44`, `apps/pos/src/lib/decimal.ts:104`. Only `apps/web/src/lib/format.ts:118` defaults to the active company. **On this feature: use `apps/web/src/lib/format.ts` with an explicit `{ currency: receipt.currency }` at every render site** — list, detail, refunds register, any future export. On a TND-only tenant a wrong default is invisible; the day a second currency exists it is a wrong number on a fiscal register. The live instance of this failure mode is `ShiftReceiptsList.tsx:92` (company currency, not row currency) — that file is deleted by `CL-1` anyway.
   Server side, keep the spec's rule: emit money as decimal strings via `CurrencyScale::bcformatStrict` at **the receipt's own** currency scale (spec §3.b.4; CLAUDE.md rule 19 — never a float, never a bare no-arg `getScale()`).
   **Lock it with the cheap assertion** (research 17 §3.3 rule 4): in the index and detail tests, make the fixture's receipt currency **differ from the company currency** and assert the emitted/rendered currency is the **receipt's**. Add this to the wave-1 and wave-2 backend tests and the corresponding FE tests; name it in the handback.
2. **Never SUM across receipts without grouping by currency.**
   Every list footer, `filter-options` figure, refunds-register sum, VAT roll-up and export total must be either scoped to a single currency or grouped by it. A naive `SUM(pos_receipts.total)` is right **by accident** today and silently wrong the moment it is not — no schema, validator or hash will warn you. This is the same rule as the launch program's standing hard pre-enable gate on the 5 existing `SUM(pos_receipts.total)` aggregates: **treat it as one rule, not two.** Combined with §2 (wave 3 not dispatched) the practical instruction is: **this build emits no cross-receipt total at all**; if a spec'd endpoint needs a count, a count is not a sum.

**Also carried from research 17 §2.7 into the deploy note (spec §4.3):** re-running `RolesAndPermissionsSeeder` re-syncs **all 7 seeded roles**, revoking any grant added manually outside the seeder — snapshot per-tenant role→permission state before the first tenant reseed; and `php artisan permission:cache-reset` is **mandatory** after the reseed (the Spatie cache is tenant-blind). Ship the **GATE-5 re-key in the same release** as the grant, or the accountant's first login is a menu of bouncing links.

---

## 5. Addendum B — wave structure and gating

**The wave table is the spec's `§7.5`** (r5) — it is binding, and it is the unit of **self-review gating** (r2: it is no longer a unit of handback). Contents and exit criteria per wave are exactly as tabulated there; this addendum only fixes what surrounds it.

| Wave | Milestone | Dispatched? | Gate |
|---|---|---|---|
| **1** | `M1` | **YES — start here.** S-1, S-2, S-3, S-6, S-7, S-9 (incl. the regenerated permission map), S-13, S-11 (index + `filter-options` half) · screen (a) · **A-1 re-gate + its nav entry + OP-23** · GATE-3 map entries · GATE-5 re-key · CL-1/CL-2/CL-5/CL-6/CL-7 · i18n rename · BT-1…BT-5, BT-8…BT-11, BT-12(index/options), BT-16, BT-18 · FT-1…FT-3, FT-10, FT-11, FT-14…FT-16 | **Self-gate: run the bridge for `M1` with its `review_lenses` (frontend-conventions, tenancy-authz, treasury, general), loop scoped fix rounds until ACCEPT, then start wave 2.** No handback between waves. |
| **2** | `M2` | **YES.** S-4, S-5, S-12, S-11 (show/PDF half) · screens (b) and (c) · CL-3 + CL-4 · BT-6, BT-7, BT-12(show/PDF), BT-13…BT-15, BT-17 · FT-4…FT-8, FT-12, FT-13 | Self-gate for `M2` (fiscal-pos, frontend-conventions, treasury, tenancy-authz, general) until ACCEPT. Then run the A0 STOP-and-check below. |
| **2b** | `M2b` | **CONDITIONALLY.** Screen (d) chain-verify panel · FT-9 | ⚠️ **HARD-BLOCKED on Lane A0** (spec §3.d, `OI-7`, `NG-10`). A0 is the **fixes session's** honest-verification lane — you do **not** implement it. **STOP-and-check at M2 close:** determine A0's status from **code on your base/branch**, not from assumption. If A0 has **not** landed, **wave 2 ships without (d)**, `M2b` is set `blocked_owner` with the observed state in `blockers:`, and the run ends there — that is the specced outcome, not a failure. Do **not** ship (d) early "with captions": r5 explicitly retracted that option. If A0 **has** landed, `OI-15` obliges you to **re-read the post-A0 endpoint shape in code** before freezing copy, i18n keys or tests, then self-gate `M2b` (fiscal-pos, frontend-conventions, general). |
| **3** | — | **NO.** S-8 + totals strip | Blocked on owner ruling `OI-3` — a hard STOP, not a judgement call. Out of this dispatch (§2). |
| — | `M3` | **YES, always last.** Whole-lane gate: no new implementation | Re-run the whole §7 verification contract over the integrated branch and self-gate `M3` with **every** lens (fiscal-pos, frontend-conventions, tenancy-authz, treasury, general). Failure of any item blocks. |

**Wave-1 expectation to state plainly and not "fix":** voucher links (`CL-3`) are **still dead after wave 1**. They heal in wave 2, and the healing is **proven by test** (§7.3), never assumed.

---

## 6. Working rules (Codex Desktop session)

**Branch and worktree.**
- `git fetch origin` first, then branch off **`origin/dev` at the fresh tip** — do not assume `7d85232cc` is still current. Record the real base SHA at the top of your handback.
- Work in a **dedicated `git worktree`**. Never edit or commit in a shared `dev` worktree (CLAUDE.md rule 21) — parallel sessions mutate branch refs between commands.
- **Never commit to `dev`**, never force-push, never `reset --hard` / `branch -f` a shared ref. The `dev-push-guard` hook enforces part of this; do not work around it.
- **Never `git stash`** — the stash stack is repo-global across worktrees and would corrupt a parallel session.
- Suggested branch: `codex/pos-receipts-2026-08-12`.
- **Commit format — recorded exception, use it, do not ask.** `AGENTS.md:16` mandates `Phase <major.minor.patch>: <imperative summary>`. For this lane the parent assigns **`Phase 1.<wave>.<seq>:`** — wave 1 → `Phase 1.1.1`, `1.1.2`, …; wave 2 → `Phase 1.2.n`; wave 2b → `Phase 1.3.n`. Name the spec item in the summary, e.g.
  `Phase 1.1.4: Re-gate compliance export on the composite compliance key (A-1/OP-23)`.
  Do not invent another series and do not fall back to `fix(...)`/`feat(...)`.
- One logical spec item per commit. A commit that mixes the seeder change with unrelated UI work is not reviewable.

**Tests — TDD is not optional.**
- **Failing test first, always** (CLAUDE.md rule 2). A commit whose test was written after the implementation does not satisfy this brief. The spec's §7 numbers every test (BT-1…BT-18, FT-1…FT-16) — write them by those ids.
- **NEVER run the full PHPUnit suite** — it crashes the owner's laptop. Run **by path only**. Never `PREFLIGHT_SCOPE=full`.
- Backend tests are **PG-backed**, `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`. Zero mock/fake data in production code. Queued/projection contexts run with **no `CompanyContext`** — pass explicit currency to scale resolution (CLAUDE.md rules 19/20).
- Frontend: vitest **by path** while iterating; the scoped set only at wave close.
- Beware vitest zombies: hung worker pools survive the parent kill (`ps aux | grep 'node (vitest'`).

**Conventions (each CI-enforced — violations fail, they do not warn).**
- **Design tokens only** (`@/lib/designTokens`); new feature directories use them exclusively (rule 18).
- **Every user-facing string via `t()`** (rule 11). EN + FR in this build; **AR is a parallel own-pace lane** — hand its key list over in the handback, do not block on it.
- **`tenantScopedKey([...])`** for every tenant-data query key (rule 14) — audited in lint, preflight and CI.
- **No `any`**; `unknown` + type guards. **No `parseFloat`/`Number(...)` on money or quantity** (rule 19) — `MoneyInput`/`QuantityInput` + the formatters, payloads as strings.
- **Types flow from backend** — `php artisan typescript:transform` after DTO changes (rule 7); in a worktree it needs `CACHE_STORE=array`. Never hand-edit generated types.
- **Permission map is a COMMIT artefact, not a deploy step** — after the seeder edit run `(cd apps/api && php artisan permissions:export-frontend-map)` and commit `apps/web/src/hooks/permissionsMap.generated.ts` in the same change (spec §4.3 note 2b). Drift is a hard preflight/CI failure.
- **Routes:** `['api','auth:sanctum',SetPermissionsTeam::class]` (rule 12). `<RequirePermission moduleKey="…">` is **NOT** a module gate — it is a permission test against `MODULE_PERMISSIONS`; only `<ModuleGuard>` / `hasModule()` consult enabled modules (spec §4.2.1 anti-pattern note). `docs/architecture/vertical-module-gating.md:146-155` glosses over this and will mislead you.
- **Constructor injection only** (rule 13) — never `app()`, including for `LocationContext` and the scale resolver.

**Scope discipline.** One item at a time (rule 4). If a task needs a file outside its stated scope, note it and continue. If it appears blocked or wrong, **stop and report** — do not improvise a decision the owner reserved.

---

## 7. Verification contract

### Per wave (= per milestone)
Every acceptance claim in the report carries **either a pasted command + output, or a `file:line`**. "Verified" without evidence is not accepted — and it is now **your own bridge reviewer** who checks that, before the parent ever sees the branch.

**Preflight — this exact invocation, from the repo root** (spec §7.4; the bare form silently skips PHPUnit, and `PREFLIGHT_VITEST_PATHS` is part of the invocation, not optional):

```bash
PREFLIGHT_TEST_PATHS='tests/Feature/POS tests/Feature/Compliance tests/Unit/POS' \
PREFLIGHT_VITEST_PATHS='src/features/pos src/features/vouchers/components/__tests__ src/components/organisms/Sidebar' \
  ./scripts/preflight.sh
```

Preflight also runs PHPStan level 8, Pint, `typescript:transform` drift, **`permissions:export-frontend-map` drift**, tsc and ESLint. PHPStan needs a live-DB env in a worktree. If a stage is **environment-blocked** rather than passing, say **which** and **why** — do not report it as green.

**Frontend unit/component gate** (from `apps/web`, scoped — not the whole suite):

```bash
pnpm vitest run src/features/pos src/features/vouchers/components/__tests__ src/components/organisms/Sidebar
pnpm lint && pnpm typecheck
```

**Playwright — required, not optional.** Spec files, wave mapping and the exact scoped invocation are frozen in §7.4 (flow 1 in wave 1; all four in wave 2). These are **live-stack** flows using `e2e/money-campaign/helpers.ts` (`loginAsRole`), **not** `e2e/fixtures.ts` (whose `authenticatedPage` mocks `/auth/me` as `admin` and cannot exercise a permission gate at all). Do **not** use the unscoped `pnpm test:e2e` alias. If the E2E environment is unavailable to you, **state that explicitly with the blocker** — an omitted run is an incomplete handback, not a green one.

**Screenshots (repo convention, spec §7.4):** the list (empty + populated + training-included), the detail page (all six sections), the refunds register in each of its three capability-banner states, and the reprint confirm dialog.

### Self-certification is not accepted — you gate yourself, with an independent reviewer
Do **not** declare a wave complete on your own say-so. **At the end of every milestone you run the
adversarial review yourself** via `scripts/adversarial-review.sh` (Opus, read-only), with that
milestone's `review_lenses` from `docs/handoff/progress/receipts-build.progress.yaml` — the lens set
encodes what used to be dispatched as separate reviewer agents (fiscal-pos on the fiscal surfaces,
reprint audit and chain-verify screen; frontend-conventions on all FE work; tenancy-authz on A-1 /
GATE-5 / S-9 / S-11 / OP-23; treasury on the currency and totals rules). Expect a fix round. **Work
does not proceed to the next wave until the previous milestone's register says `VERDICT: ACCEPT`** and
the YAML records it. Every **P1** finding must be closed (or ruled by an owner gate) before a milestone
passes; **P2** close-before-merge; **P3** may ship with a ticket recorded in the report.

At the very end — and only there — **the parent Claude session performs the terminal audit** (a
full-branch adversarial review plus the specialized reviewer agents) and **owns the merge into `dev`**.
**You never merge, never push, never touch `dev`.**

---

## 8. Final report contract (written at completion or at a STOP — not between waves)

Write **one** implementer report to **`docs/handoff/HANDBACK-receipts-build-2026-08-12.md`**, appending a new section per wave as you close each one (do not start a second file). The report is no longer a handback that pauses you: you write the wave's section, update the YAML, and **continue**. It becomes the terminal deliverable when the lane completes or a STOP condition fires.

**On completion — or on any STOP:** finish the report, make sure
`docs/handoff/progress/receipts-build.progress.yaml` reflects reality (per-milestone `status`,
`commit`, `verdict`, `fix_rounds`; wave `status` + `blockers` + `findings`), leave the tree clean
(commit or revert WIP — **never** `git stash`), and end your run. **The parent Claude session then
performs the terminal audit — a full-branch adversarial review plus the specialized reviewer agents —
and owns the merge into `dev`. The executor NEVER merges and NEVER pushes.**

1. **Header** — base SHA branched from, branch name, worktree path, final SHA, commit list (one line each), wave being handed back.
2. **Per spec item** (S-n, screen, GATE-n, A-1, A-2, CL-n, i18n): status `DONE` / `BLOCKED` / `DEFERRED` / `PARTIAL` (no other values), what changed as `file:line`, the **failing-test-first evidence** (test file + what its failure looked like before the fix), and each acceptance criterion restated with its evidence.
3. **Addendum A evidence, itemised** — (a) the composite `compliance` key + the A-1 route/nav gate + the **OP-23** fraud-route re-gate, each with its test; (b) an explicit statement that no lane-separation grant was added; (c) the currency-rule proof: the differing-currency fixture assertion, and a statement that **no cross-receipt sum exists** in the diff (paste the grep you used).
4. **Owner-visible behaviour changes, stated plainly** — the GATE-5 re-key removing POS menu entries from existing roles (OI-14); the new top-level Compliance-export nav entry visible to admins/managers (OI-16); the accountant's three new grants.
5. **Whole-wave verification** — the preflight invocation above with its output, the scoped vitest/lint/typecheck output, the Playwright summary, and the screenshots.
6. **Anything you decided that the spec did not specify** — flag prominently. These are the highest-value review targets.
7. **Discovered findings not in scope** — `file:line` + one line each. Do not fix them.
8. **Deviations from the spec or this brief** — every one, with justification. A silent deviation found at gate time invalidates the handback.
9. **Deploy notes owed** (spec §4.3 + Addendum A): the per-tenant reseed, `permission:cache-reset`, the role→permission snapshot before the first reseed, and the A-1 acceptance check (load `/pos/receipts` **and** `/settings/compliance/export` **as the accountant user**).
10. **Completion language.** Do not write "receipts lane complete" while wave 2b is unshipped or wave 3 undispatched. Report **per wave and per spec item**.

Cross-reference, don't restate: cite spec section/item ids rather than re-arguing them.

---

## 9. Flags for the parent session (do not resolve these yourself)

**r2 execution-mode note:** these are the lane's **owner gates**. They are mirrored in
`docs/handoff/progress/receipts-build.progress.yaml` under `owner_gates:`. You may not decide any of
them. F-1 and F-2 are **conditional STOPs** — if the stated condition fires, set the YAML `status` /
milestone `status` to `blocked_owner`, name the exact question in `blockers:`, and end the run. F-3 and
F-4 are **standing exclusions**: record them under `findings:` and in the report; change nothing.

- **F-1 — the accountant seeder edit is shared with the DN-consolidation lane.** `deliveries.view` is that lane's `OI-1a`. The parent assigns the **single** three-key seeder edit + the regenerated `permissionsMap.generated.ts` to **this build** (wave 1, S-9), so two lanes do not both rewrite a generated file. **The DN lane has been told not to edit the `accountant` block.** If you find the keys already present on your base, or a conflicting edit at merge, **stop and report** — do not resolve it by re-editing the seeder.
- **F-2 — wave 2b depends on another session's lane (A0).** Its landing is not observable from outside the code you have. At **M2 close** this is a **STOP-and-check**: determine A0's status from code on your base/branch, state it plainly in the report, and act per the §5 wave table — A0 landed → run `M2b`; A0 not landed → `M2b` `blocked_owner`, waves 1+2 are the delivered scope, end the run. Do not ship screen (d) on an assumption in either direction.
- **F-3 — OP-23's IA home is not decided.** You re-gate the two fraud routes; where they surface in navigation is the OQ-10 Compliance-section work. Report the residual; do not add nav entries for them.
- **F-4 — `OP-22`** (unit-designation print obligation) and the **`event_version` 5 unit lane** are recorded, unowned by you, and must not leak into this build in any form.

---

## 10. Revision log

### r3 — 2026-08-12: consistency-gate fix round (gate register `docs/superpowers/reviews/2026-08-12-build-handovers-gate-r1.md`)

The gate reviewed the **pre-retrofit (r1)** text and returned **FIX-FIRST** with three findings on this
brief. All three are applied below to the current (retrofitted) text. **No execution-mode content was
touched** — the banner, the milestone/wave table, the YAML references, the bridge invocation, the
STOP conditions and the §8 report contract are byte-for-byte as r2 left them.

| Finding | Disposition | Where applied |
|---|---|---|
| **R-1** — §3's unqualified *"do not read present-day `product.unitOfMeasure`"* contradicts the spec's deliberate `quantity_decimals` enrichment (`SPEC…:327-328`; S-4 `:433` requires widening the eager-load to `lines.product.unitOfMeasure`) | **APPLIED.** The prohibition is now **scoped to the unit symbol/code** (`->symbol` / `->code` / `products.unit`), and the `quantity_decimals` enrichment — eager-load widening, `decimal_places` only, **fallback 4**, relation unset before serialisation — is restated as **required**, with *"display precision is not a unit label"* spelled out so the row cannot be read as a licence to drop S-4. | §3, `OI-17` row |
| **R-2** — the blanket event-dossier DO-NOT-TOUCH row swallowed **ES-31**, which the DN brief explicitly owns (`HANDOVER-event-sourcing-remediation…` §3 `:68` Lane X, `:94` interactions row) | **APPLIED.** `ES-31` is excluded from the blanket statement and the **DN-consolidation build** is named as its owner, with both handover citations and the cross-brief pointer. Still not yours in either direction — and not to be reported as unowned. | §1 scope guard, events/shift-variance row |
| **R-3** — the Lane A0 citation `HANDOVER-…:50,119` was root-relative and pointed at the wrong lines; the same table used unresolved root-relative `FINDINGS-*.md` paths | **APPLIED.** The A0 row now cites repo-valid `docs/handoff/HANDOVER-event-sourcing-remediation-2026-08-11.md` **§3 `:58`** (the A0 lane row naming ES-07) + **§3 `:75`** (A0 entry criteria / exit deliverables) + **§6 item 1 `:161`** (the ES-07 row), and names the dispatched lane brief `docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md`. Both findings documents are fully qualified as `docs/handoff/FINDINGS-shift-variance-gl-2026-08-11.md` and `docs/handoff/FINDINGS-other-problems-2026-08-11.md`, here and in §4(a). | §1 scope guard (A0 row + dossier row), §4(a) source line |

**Unchanged by r3:** every wave's contents and exit criteria, every ruling closure other than the
`OI-17` scoping above, Addendum A(a)/(b)/(c), the `Phase 1.<wave>.<seq>` series, the preflight /
vitest / Playwright / screenshot evidence contract, and all of §5–§9.

### r2 — 2026-08-12: execution-mode retrofit (owner ruling 2026-08-12)

**Task content is untouched.** No spec item, wave content, ruling closure, addendum, acceptance
criterion, test id, permission key or scope boundary changed from r1. What changed is *who gates the
work between waves*.

| # | Change | Where |
|---|---|---|
| 1 | Added the **EXECUTION MODE — SELF-REVIEWING WAVE** banner: the lane runs under `docs/handoff/SELF-REVIEW-HARNESS.md`, self-reviews at every milestone via `scripts/adversarial-review.sh` (`claude -p --model opus`), loops scoped fix rounds until ACCEPT, and tracks state in `docs/handoff/progress/receipts-build.progress.yaml`. | header block |
| 2 | Created the lane's progress YAML with one milestone per wave (**M0** preconditions · **M1** wave 1 · **M2** wave 2 · **M2b** wave 2b conditional · **M3** whole-lane gate) and a `review_lenses` set per milestone. | `docs/handoff/progress/receipts-build.progress.yaml` |
| 3 | Wave table: the per-wave **"handback → parent adversarial review"** gates replaced by the milestone self-gate with its lens set; a final whole-lane milestone row added. Wave contents unchanged (spec §7.5 remains binding). | §5 |
| 4 | Wave 2b's parent coordination replaced by an explicit **STOP-and-check at M2 close**, decided from code, with `blocked_owner` as the recorded outcome when A0 has not landed. Wave 3 restated as a hard STOP on `OI-3`. | §5, §9 F-2, banner |
| 5 | "Self-certification is not accepted" rewritten: the executor runs the review itself with the lens set that encodes the former reviewer-agent split; P1/P2/P3 disposition rules stated. | §7 |
| 6 | Handback contract rewritten as a **final report** contract: the report is appended per wave without pausing, and on completion or STOP the parent Claude session performs the **terminal audit** (full-branch adversarial review + specialized reviewer agents) and **owns the merge**. The executor never merges and never pushes. | §8 |
| 7 | §9 flags reframed as the lane's **owner gates**, mirrored in the YAML: F-1/F-2 conditional STOPs, F-3/F-4 standing exclusions recorded as findings. | §9 |

**Unchanged and still binding:** branch discipline (dedicated worktree off the fresh `origin/dev`
tip, never commit to `dev`, never force-push, never `git stash`), the `Phase 1.<wave>.<seq>` commit
series, TDD red-first, tests by path only, the preflight/vitest/Playwright/screenshot evidence
contract, and every scope guard in §1–§4.

### r1 — 2026-08-12
First dispatch of this lane, wrapping `SPEC-pos-receipts-reporting-2026-08-11.md` r5 (gate-PASSED).
