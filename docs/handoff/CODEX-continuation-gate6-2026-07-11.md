# Gate 6 Result + Leg 4 (FINAL) Brief — design-system unification

> Gate 6 review of c729930e1 + e54b44116, 2026-07-11. Two lanes.
> **Verdict: APPROVE-WITH-FIXES — no code blockers.** BL-1/BL-2 fully eradicated (zero matches branch-wide; new lint rule fires on probes; all 5 spot-checked sites restored to exact pre-leg-3 pixels). Conservation over src/components: ZERO class-multiset delta across all 63 shared files (token-expander with positive/negative controls). Baseline replay honest ×7 (183 → 182, both moves legitimate). All evidence numbers reproduced exactly. The headless gate-5 reviewer's APPROVE was independently validated as a genuine deep pass — the autonomous loop is trustworthy.
> The findings are scope-honesty, and the residual-debt map (below) defines ONE final leg.

## Step 1 — Gate-6 record fixes (small)

1. **CORRECTION (important):** the Wave-6 item calling `default_tax_configuration_id` a "dead family" was WRONG — it is a **live inbound API field** on products (32 refs across products/services/POS/documents; see `productPayload.ts`). Do NOT remove it. Only the services-DTO reads were dead and those were already handled at G2-3. Add this correction to the progress doc so no future sweep deletes it.
2. **(b) location/locations unification** — still open. DO IT in this leg: pick the survivor family, migrate the 5 call sites, resolve the singular/plural dir split, and fold the duplicate `AddLocationModal` (`features/location/` vs `components/organisms/AddLocationModal/`) into one.
3. **(c) CategorySelect vs CategorySelector** — review found they are genuinely distinct (single-select `number|null` vs multi-select `number[]`). Do NOT force-unify; instead rename for clarity if cheap (e.g. `CategorySelect` → `CategorySingleSelect`) or just document the distinction in both files' docblocks + the progress doc.
4. Minors: remove redundant `variants.bgAmber50Alpha50` (duplicates `caution.bgSubtleAlpha`); clean the unused eslint-disable directive; commit this brief.

## Step 2 — LEG 4 (the last sweep leg): structural C1–C6 in the never-swept directories

The residual 182 = 23 deferred PageHeader C1 headers (admin 10, auth 5, documents 5, legal 2, POS 1 — stay deferred for the owner's visual pass) + **159 structural entries in dirs no leg ever swept** (they were color-clean so they never made a color-driven leg list — the gap is C2/C3 raw form elements, C5 raw tables, C4 forms, a few C1/C6):

| Dir | Entries | Mix |
|---|---|---|
| scheduling | 37 | C1:3 C2:19 C3:14 C4:1 |
| workshop-bundles | 19 | C1:3 C2:10 C3:4 C4:2 |
| document-ingestions | 18 | C2:8 C3:8 C5:1 C6:1 |
| workshop-technicians | 16 | C1:1 C2:2 C3:10 C4:3 |
| purchases (leftovers) | 15 | C2:6 C3:9 |
| progression | 10 | C1:2 C3:7 C6:1 |
| finance | 9 | C4:2 C5:7 |
| enrichment | 8 | C1:1 C2:3 C3:3 C5:1 |
| treasury | 8 | C1:1 C2:2 C4:1 C5:4 |
| channels | 7 | C5:5 C6:2 |
| owner-dashboard | 5 | C2:3 C5:2 |
| menu, income, withholding, workshop-work-orders | 7 | C3/C4/C5/C6 small |

Rules for this leg:
- Same per-directory protocol (manifest zeros → baseline shrink → progress-doc entry). Same conservation bar: pixel/behavior-identical; BL-1 lint rule is live so interpolation can't recur; extend semanticColorTokens when a shade is missing, never substitute.
- **`finance/` and `treasury/` are financial-spine-adjacent UI**: styling/atom substitutions ONLY — do not touch any amount computation, payload construction, query, or projection logic in those dirs. If a C4 form conversion there would require touching submit logic, DEFER it with a note instead.
- C4 form conversions anywhere in this leg: payload-identity test required per form (lock the submit shape).
- **Close the ratchet gap:** promote ALL remaining feature dirs (the table above + any stragglers) into the full-palette ERROR block — end state should be effectively `src/**` at ERROR, at which point simplify the config to a single global ERROR rule with the (now tiny) exception list if that's cleaner.
- Evidence protocol note: reproduce vitest with the DEFAULT pool (never `--singleFork` — it produces false mass failures via cross-file pollution; verified at gate 6).

## Step 3 — STOP after leg 4 commits

Commit everything (including this brief and the progress-doc updates), leave the worktree clean, and STOP. **You do not need to run the gate script and you do not need the owner to relay:** the orchestrator is watching this worktree's HEAD and will launch the gate-7 review automatically when your commits land. If the review finds issues, a `CODEX-continuation-gate7-*.md` brief will appear in docs/handoff/ the same way this one did.

After gate 7 approves: the branch is done. Remaining items are owner/orchestrator-side: final whole-branch review, live E2E, the 23-header PageHeader adoption decision, device visual pass, merge + prevention layer.
