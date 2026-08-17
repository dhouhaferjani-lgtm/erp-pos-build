# Self-Review Harness — how a Codex wave gates itself with inline Opus reviews

**Purpose.** A wave dispatched under this harness reviews *itself* at every milestone by invoking
Opus (via the `claude` CLI) as an adversarial gate, instead of handing back to a human/orchestrator
between milestones. State lives on disk in a per-wave YAML that Codex reads and writes as it goes, so
progress survives crashes and any session can resume by reading the file.

You (Codex) own the loop end-to-end. Escalate to the human ONLY at the three STOP conditions below.

---

## The one-time setup (do this first, in order)

1. Open your wave's YAML: `docs/handoff/progress/<wave>.progress.yaml`. It lists your milestones,
   the review lenses per milestone, `max_fix_rounds`, and the `owner_gates` you may not cross alone.
2. Pin the base: set `base_sha` to the commit named in your brief's dispatch preconditions, verify it
   exists (`git rev-parse --verify <sha>`), create your `branch` from it, and write both into the YAML.
3. Run your brief's M0 preconditions (the brief lists them). If any fails, set the wave `status:
   blocked_precondition`, write why into `blockers:`, and STOP.

## The per-milestone loop

For each milestone `M<n>` in YAML order:

1. **Implement it** per the brief's `M<n>` section — TDD red-first, house rules (tests BY PATH only,
   never the full PHPUnit suite; no `git stash`; PG-before-green; en+fr; migrations unattended-safe).
   Commit when green. Set the milestone `status: review`, record the `commit` SHA.

2. **Self-review.** Run the bridge once per milestone (it applies all the milestone's lenses):
   ```
   scripts/adversarial-review.sh \
     --brief   <brief path from YAML> \
     --milestone M<n> \
     --lenses  "<comma list from the milestone's review_lenses>" \
     --range   <base_sha>..HEAD \
     --out     docs/handoff/reviews/<wave>/M<n>-round<r>.md \
     --round   <r>
   ```
   It writes the register to `--out` and exits: **0 = ACCEPT**, **2 = CHANGES-REQUIRED**,
   **3 = tool error (treat as CHANGES-REQUIRED — fail closed; do not proceed on a tool error)**.
   Record the verdict path in the milestone's `verdict:` and the exit result in `last_verdict:`.

3. **Branch on the verdict:**
   - **ACCEPT** → set `status: passed`, `updated:` to a stamp you pass in (see "No clock" below), move to the next milestone.
   - **CHANGES-REQUIRED** → read the register, do a SCOPED fix round addressing only its findings
     (red-first for behavioral fixes), commit, increment `fix_rounds`, and re-run step 2 with `--round <r+1>`.
   - If `fix_rounds` would exceed `max_fix_rounds` (default 5), STOP (condition A below).

4. **Owner gate?** If the milestone's `owner_gate` field is set (or a finding turns on an item listed
   in the wave's `owner_gates:`), you may not decide it. STOP (condition B).

## The three STOP conditions (escalate to human, do not improvise)

- **A — Fix rounds exhausted.** A milestone is still CHANGES-REQUIRED after `max_fix_rounds`.
  Set milestone `status: blocked_review`, summarize the surviving findings in `blockers:`, STOP.
- **B — Owner gate.** A decision the brief marks owner-only (e.g. account codes needing treasury
  approval, a spec read-through, a legal/deploy ruling). Set `status: blocked_owner`, name the exact
  question in `blockers:`, STOP. Do NOT guess accounting codes, deploy choices, or legal posture.
- **C — Architecture contradiction.** The milestone cannot be implemented without violating a settled
  plan/spec invariant, or two authority documents genuinely conflict. Set `status: blocked_architecture`,
  cite both sources with file:line in `blockers:`, STOP.

On any STOP: leave the tree clean (commit or revert WIP; never stash), make sure the YAML reflects
reality, and end your run. A human or a fresh session resumes by reading the YAML + the latest register.

## Standing rules baked into the harness

- The reviewer is Opus, read-only; the bridge captures its stdout. Never let the review edit the repo.
- One review invocation per milestone (it carries all that milestone's lenses). Don't skip a milestone's
  review because "it's small" — the review is the gate, not a formality.
- A review that emits no parseable `VERDICT:` line is a **tool error → CHANGES-REQUIRED**, not a pass.
- Every finding the reviewer marks P1 must be closed or explicitly ruled by an owner gate before the
  milestone passes. P2 close-before-merge; P3 may ship with a ticket recorded in the brief's tree.
- Keep the wave's whole-branch obligation (the brief's final milestone) as the last gate: it re-runs the
  full accumulated evidence and every lens once more over the integrated branch.

## No clock

Scripts here run in an environment without a wall clock. Do not call `date`. When the YAML wants an
`updated:` or a review stamp, use the commit SHA (`git rev-parse --short HEAD`) as the marker, not a
timestamp. This keeps runs reproducible and resumable.

## Deliverable (unchanged from the brief)

Branch NOT merged, NOT pushed. The wave's report file + the per-milestone registers under
`docs/handoff/reviews/<wave>/` are the evidence trail. The orchestrator/human merges after reading the
final whole-branch register and clearing any remaining owner gates.
