# Lane D2-a review record — docs/launch-ops-runbook (first-tenant program)

**Reviewer:** Opus doc-accuracy pass. **Target:** `docs/launch-ops-runbook` vs `origin/dev @ 711f3d79f`.
**Merged to local dev:** `21d807392` (2026-07-31). Phase-E ownership transfer NOW IN EFFECT for the
six manifest paths (gate sheet, staging runbook, pos-operations, smoke, migration-audit, secret doc).

## Round 1: APPROVE-WITH-FIXES
Contract-critical checks ALL PASS: three verifier signatures verbatim incl. mandatory `--actor-id`
(zero remaining invocations of nonexistent `fiscal:verify-chain`); every cited artisan command
exists with exact signature (`--option='dry-run=1'` stress-tested safe against VALUE_NONE);
NO pre-filled prospective evidence anywhere; smoke protocol carries Gate class + Actual Outcome
columns, ≥10min/5+receipt offline, checksum/clock checks, P0 override declared; preflight exits 1
with exactly 35 hits, all mapped 1:1 to gate rows, no orphans; runbook ordering sound.
**`--company=` correction CONFIRMED REAL:** `treasury:backfill-location-attribution` requires
`--company=` (BackfillLocationAttributionCommand.php:20,:26-31); the predecessor
STAGING-DEPLOY-RUNBOOK-2026-07-28.md:79 invokes it fleet-wide → would FAIL on every tenant.
Findings: F1/F2/F4 line-anchor staleness in the gate sheet (self-inflicted by sibling edits),
F3/F5/F6 cosmetic anchors, F7 advisory (B.2 gate class), F8 advisory (sign-off/E-4 linkage OK).

## Fix round (@ 42c032b80), orchestrator-verified (mechanical scope confirmed by diff):
All anchors corrected (+1 extra found in residual sweep: E-10 duplicate stale anchor);
**B.2 promoted non-P0 → P0 by orchestrator ruling** (activation re-prompt is adjacent to terminal
re-claim/genesis-seed state → fiscal durability); A.4/H.2/H.3 stay non-P0; preflight unchanged at 35.

**VERDICT: APPROVE (per reviewer's merge-after-fixing recommendation) — merged.**
