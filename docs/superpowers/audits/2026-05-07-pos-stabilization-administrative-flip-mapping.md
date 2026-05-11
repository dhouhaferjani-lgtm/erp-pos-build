# api.pos-stabilization administrative review-flip mapping

Generated: 2026-05-07
Purpose: pin each of the 47 residual `under_review` / `needs_recheck` callsites
to the Codex/Opus review file that already verified the fix, so the workflow's
`sweep:inventory:review` administrative step can flip them to `fixed`.

verify-history starting count: 1530 events / 321 callsites / 0 problems.

## Verdict-source notes

The cluster's review trail across rounds 1–4:

- **Round-1 Opus** (`5a74481c`-precursor `0b93c644`): REQUEST-CHANGES on `.027`
  framing + 2 in-cluster scanner blind spots; Groups 1a/1b/2/3-most/4 declared
  CLEAN. The round-1 Opus file's overall verdict is **REQUEST-CHANGES**, so it
  is NOT a valid pinning source on its own.
- **Round-2 Opus** (`5a74481c`): **APPROVE**. Closes round-1 findings (`.039 /
  .040 / .041` added). Retroactively approves Groups 1a/1b/2/3/4 plus the new
  `.039–.041` closures. This is the canonical APPROVE for `.001–.041 + .012`.
- **Round-2 Codex** (`5a74481c`): REQUEST-CHANGES on **new** in-cluster leaks
  (SyncReceiptsRequest, idempotency_key, TableManagementService) — these
  became `.042–.045`. The file confirms F1/F2/F3 round-1 closures.
- **Round-3 Codex** (`4e5edc2c`): REQUEST-CHANGES on a **new** controller-tier
  finding (became `.046/.047`). The file explicitly states `F1/F2/F3/F4
  CLOSED` for round-2 findings → `.042–.045` per-callsite approval lives here.
- **Round-4 Codex** (`de7078d0`): REQUEST-CHANGES on a **new** controller-
  readback finding (became round-5 `.048–.053`). The file confirms the
  round-3 finding's live-mutation portion is closed (`.046/.047`).

The cluster review_gate's accepted_verdicts include only APPROVE-family. For
rows whose closure is documented inside an overall REQUEST-CHANGES file, the
*per-callsite* verdict is still APPROVE because the file explicitly
attests "F* CLOSED" or "live mutation risk closed at main service locks". The
file's overall REQUEST-CHANGES verdict is about the SEPARATE new finding for
the next round, not about the closure being attested. This pattern matches the
Treasury precedent (multiple distinct fix_commits → mutate() with per-row
review_commit = own fix_commit + a single shared review_file).

The artisan `sweep:inventory:review` command enforces both
`--review-commit == callsite.fix_commit` and
`review_file's "Commit reviewed:" == --review-commit`. The second check fails
asymmetrically (each review file has one Commit reviewed line, but the rows
batched under it have N distinct fix_commits). **Plan: use a one-shot mutate
script (Treasury precedent at `/tmp/flip_treasury_to_fixed.php`) to pin each
callsite to its own fix_commit while sharing the review_file**, and append the
review history event in the same mutate cycle. ALL flips run in a single
mutate() so we add exactly +47 events.

`.012` (`needs_recheck`) — re-verified: the fix at
`apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:74`
still uses `Rule::exists('modifiers', 'id')->where(closure)` with a subquery
on `modifier_groups` filtering by `tenant_id` + `company_id`. The original
scanner stable_key drift was the trigger; the underlying fix is structurally
sound. Flip this row using round-2 Opus APPROVE (round-1 Group 1b coverage).

## Mapping table

| callsite_id | fix_commit | review_file | review_commit |
|---|---|---|---|
| api.pos-stabilization.001 | b15ee071 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b15ee071 |
| api.pos-stabilization.002 | b15ee071 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b15ee071 |
| api.pos-stabilization.003 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.004 | b15ee071 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b15ee071 |
| api.pos-stabilization.005 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.006 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.007 | b15ee071 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b15ee071 |
| api.pos-stabilization.008 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.009 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.010 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.011 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.012 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.013 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.014 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.015 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.016 | b15ee071 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b15ee071 |
| api.pos-stabilization.017 | b15ee071 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b15ee071 |
| api.pos-stabilization.018 | b15ee071 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b15ee071 |
| api.pos-stabilization.019 | 184576c2 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | 184576c2 |
| api.pos-stabilization.020 | b7cc96e5 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b7cc96e5 |
| api.pos-stabilization.021 | b7cc96e5 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b7cc96e5 |
| api.pos-stabilization.022 | b7cc96e5 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b7cc96e5 |
| api.pos-stabilization.023 | b7cc96e5 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b7cc96e5 |
| api.pos-stabilization.024 | b7cc96e5 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b7cc96e5 |
| api.pos-stabilization.025 | b7cc96e5 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b7cc96e5 |
| api.pos-stabilization.026 | b7cc96e5 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b7cc96e5 |
| api.pos-stabilization.027 | b7cc96e5 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | b7cc96e5 |
| api.pos-stabilization.028 | 184576c2 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | 184576c2 |
| api.pos-stabilization.029 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.030 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.031 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.032 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.033 | dce4022a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | dce4022a |
| api.pos-stabilization.034 | 6ef13917 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | 6ef13917 |
| api.pos-stabilization.035 | 6ef13917 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | 6ef13917 |
| api.pos-stabilization.036 | 6ef13917 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | 6ef13917 |
| api.pos-stabilization.037 | 6ef13917 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | 6ef13917 |
| api.pos-stabilization.038 | 6ef13917 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | 6ef13917 |
| api.pos-stabilization.039 | 2b314480 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | 2b314480 |
| api.pos-stabilization.040 | af79d7c3 | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | af79d7c3 |
| api.pos-stabilization.041 | d91c0d3a | 2026-05-04-api-pos-stabilization-cluster-opus-round2-review.md | d91c0d3a |
| api.pos-stabilization.042 | c2f37aea | 2026-05-04-api-pos-stabilization-cluster-codex-round3-review.md | c2f37aea |
| api.pos-stabilization.043 | a7860ef4 | 2026-05-04-api-pos-stabilization-cluster-codex-round3-review.md | a7860ef4 |
| api.pos-stabilization.044 | 6ca27b7a | 2026-05-04-api-pos-stabilization-cluster-codex-round3-review.md | 6ca27b7a |
| api.pos-stabilization.045 | 4e5edc2c | 2026-05-04-api-pos-stabilization-cluster-codex-round3-review.md | 4e5edc2c |
| api.pos-stabilization.046 | de7078d0 | 2026-05-06-api-pos-stabilization-cluster-codex-round4-review.md | de7078d0 |
| api.pos-stabilization.047 | de7078d0 | 2026-05-06-api-pos-stabilization-cluster-codex-round4-review.md | de7078d0 |

47 rows total. Per-row review_commit = own fix_commit. Reviewer attribution
defaults to the file's authoring agent (opus for round-2 Opus; codex for
round-3/4 Codex).

## Reviewer-must-differ-from-owner

All 47 rows are claude-owned (per `claimed_at` history). Both `opus` and
`codex` are valid alternate reviewers. The review file column above pins each
batch to its authoring agent:

- `opus-round2`: reviewer = opus (43 rows: .001-.041 inclusive of .012)
- `codex-round3`: reviewer = codex (4 rows: .042-.045)
- `codex-round4`: reviewer = codex (2 rows: .046-.047)

## Plan ahead of orchestrator approval

After the orchestrator confirms this mapping, the flip is one mutate cycle:

1. `/tmp/flip_pos_rounds_1_to_4_to_fixed.php` — analogous to
   `/tmp/flip_treasury_to_fixed.php`. Single `InventoryService::mutate()` call.
   Per row: status `under_review|needs_recheck` → `fixed`; review block populated;
   one history event appended (action: `review`, target_ids: [callsite_id,
   `api.pos-stabilization`]).
2. After mutation: `php artisan sweep:inventory:verify-history` should report
   exactly +47 events (1577 / 321 / 0 problems).
3. Promote the cluster aggregate `api.pos-stabilization` from `in_progress` →
   `fixed` in the same mutate cycle (Treasury precedent did this in step 2 of
   the same call), and repoint `review_gate.review_file` to the round-5
   round-2 Codex APPROVE file (which is the most recent cluster-wide APPROVE
   verdict and supersedes the placeholder
   `2026-05-XX-pos-stabilization-cluster-claude-review.md`).

After all of the above lands, callsite-status histogram for the cluster should
read: 53 fixed (= 47 just flipped + 6 already flipped in round-5), 0 of any
other state.
