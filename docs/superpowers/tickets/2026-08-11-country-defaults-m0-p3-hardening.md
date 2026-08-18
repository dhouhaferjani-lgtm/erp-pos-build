# Country Defaults Phase A — M0 P3 hardening carry-forward

Source: `docs/handoff/reviews/country-defaults-phase-a/M0-round3.md` (ACCEPT).

These are non-blocking P3 notes. M0 is accepted; M4/M7 must explicitly close or adjudicate them.

## 1. Bind reconciliation to the intended source revision

The committed M0 command proves that reflected application classes come from the active worktree,
but its printed `base_sha` is resolved independently from the source bytes it reads. Before M4
uses the same method for certified-fixture deltas, its evidence must fail if the chart definition
content differs unexpectedly from the frozen base.

M1 intentionally adds deprecation docblocks to the frozen seeder files, so a whole-file
`git diff --quiet` against `7d85232cc` is too broad. M4 should compare the extracted
`getAccountsDefinition()` content (or its canonical exported rows) to the pinned base/goldens and
also print both `HEAD` and the certification base. M7 must cite the binding check.

## 2. State reviewer permission guarantees precisely

`scripts/adversarial-review.sh` now uses Claude `--permission-mode plan`, which removes direct
Write/Edit tools in observed bridge runs. Plan mode was not proven to block every mutating Bash
command in headless execution. Keep the reviewer prompt read-only and the plan-mode restriction,
but do not describe it as a complete filesystem sandbox unless an explicit read-only tool allowlist
or equivalent enforcement is added. M7 must verify the working tree remains unchanged across every
review invocation.
