# ORCHESTRATOR RULING — M1 T11c pairs 7–10 sequencing (2026-08-11)

**Status: RESOLVED — resume M1. This ruling re-sequences the T11c green gate across the
M1/M2 boundary. It is authorized by the blocker's own escalation clause ("Owner/orchestrator
must re-sequence the T11c green-after gate") and changes NO scope, NO invariant, and NO
member of the M2 atomic cutover commit.**

## The contradiction (confirmed against both authorities)

- Brief (M1 section, T11c bullet): acceptance for the ten lock-order pairs is
  **red-before/green-after**, with pairs 7 & 8 keyed to **T16d** and pairs 9 & 10 keyed to
  **T16e** — i.e. the brief itself states those pairs only turn green after T16d/T16e land.
- Brief (M2 section): **T16d and T16e are members of the indivisible cutover commit**
  (T14 + T15 + T16 + T16b + T16d + T16e + T17, one commit, no split).
- Therefore demanding all ten pairs GREEN as an M1 exit condition is impossible without
  either splitting the atomic cutover (forbidden) or smuggling T16d/T16e into M1 (forbidden).
  The stop was correct per the harness protocol.

## Ruling

The red-before/green-after acceptance for pairs 7–10 **spans the M1/M2 boundary**:

1. **M1 exit condition (amended):** the full ten-pair T11c suite must EXIST and run.
   - Pairs 1–5 (incl. re-proving 4 & 5 on the merged tree) and pair 6 (under its existing
     D-28 condition, which is M1 scope): **GREEN required**.
   - Pairs 7–10: **RED required, with the failing output captured as M1 evidence**
     (this is the red-before half of the brief's own acceptance). The inline Opus reviewer
     must verify each red fails **for the right reason** — the missing T16d/T16e
     buffering/reorder — not because the test itself is broken, and that the pairs encode
     the corrected I-1 invariant and the GR `Received`-status arm as the brief requires.
   - The carried cannot-verify proof (PG advisory lock inside an aborting subtransaction)
     remains an M1 obligation, unchanged.
2. **M2 exit condition (hardened):** **all ten T11c pairs GREEN post-cutover** is a hard
   gate item. Any red pair at the M2 review = CHANGES-REQUIRED. If any pair cannot be made
   green, the brief's existing stop rule applies unchanged: D-9's architecture is wrong and
   3C STOPS — report, do not work around.
3. The M2 atomic commit membership is untouched. Nothing from T16d/T16e moves into M1.

## Instructions to the wave on resume

- Set M1 back to in_progress (done in the progress YAML alongside this ruling), proceed with
  the amended M1 exit condition, and cite this ruling file in the M1 review prompt so the
  inline reviewer gates against the amended condition, not the original impossible one.
- Record pairs 7–10 red-before evidence in `docs/handoff/reviews/wave3-3c-3d/` (same
  pattern as M0 evidence).
