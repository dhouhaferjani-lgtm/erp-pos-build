# OWNER ACTION O-31 — arm the enum↔CHECK parity anti-growth ceiling (Session B2, 2026-08-25)

**Why (LEDGER O-31, D-1 tenancy gate ruling (4), D-1 r2 R2-2):** the Slice-D parity ratchet
(`apps/api/tests/Architecture/EnumCheckParityTest.php`) is shrink-only against three artifacts under
`apps/api/tests/Architecture/baselines/` — `enum-check-parity-baseline.json` (tenant, 188 keys),
`enum-check-parity-central-baseline.json` (11), `enum-check-parity-acknowledgements.json` (2 named entries incl.
the fiscal ledger/quarantine partition). Without an owner-held pin, a diff can add an uncovered column AND its
baseline entry together (matched growth), or widen the quarantine CHECK AND delete its acknowledgement together,
and stay green. Lane C-26 (`fix/sb2-c26-parity-preconditions`) added the reader side: the test compares the
working copies against the three files **at an owner-pinned seed COMMIT** and FAILS CLOSED while the pin is unset.
One variable + one tag cover all three artifacts (the DPA ratchet's design, `DocumentPerActionBaselineRatchetTest`,
but per-commit instead of per-blob).

## The seed (prepared — verify, do not trust)
Seed commit: **`da5ae13792e2a5067edde96f85858d2ea37efccf`** (`da5ae1379`, "parity baseline: SHRINK 189->188 at
merge — documents.status now COVERED …"). It is the last commit that moved any of the three files; the blobs are
identical at every later dev tip checked (`dabf8fbde`, `7cd024946`):

| artifact | blob at the seed |
|---|---|
| `apps/api/tests/Architecture/baselines/enum-check-parity-baseline.json` | `47e77720a11e9d9e5acd1143a203a78a6c38141b` |
| `apps/api/tests/Architecture/baselines/enum-check-parity-central-baseline.json` | `d3623927c3257630801c9559d5577aacfdd7308e` |
| `apps/api/tests/Architecture/baselines/enum-check-parity-acknowledgements.json` | `3df370e19e44544f9df36160cf2bacc9da2758e5` |

Verify on your machine before pinning (all three must print the blobs above):
```bash
git fetch origin dev
for f in enum-check-parity-baseline.json enum-check-parity-central-baseline.json enum-check-parity-acknowledgements.json; do
  echo "$f $(git rev-parse da5ae1379:apps/api/tests/Architecture/baselines/$f)  dev:$(git rev-parse origin/dev:apps/api/tests/Architecture/baselines/$f)"
done
```
If a later Slice-D batch has already shrunk a baseline on `origin/dev` when you do this, the pin is STILL
`da5ae1379` — a ceiling only needs to be ≥ the working copy; the working copies may only shrink below it.
Re-pin (new seed + new tag `ci-pin/enum-check-parity-r2`) only when an acknowledgement is deliberately ADDED
with review, since the ceiling also refuses acknowledgement changes.

## Steps (both required, in this order)
1. **Repository variable** (GitHub → Settings → Secrets and variables → Actions → Variables):
   `ENUM_CHECK_PARITY_PROTECTED_SEED` = `da5ae13792e2a5067edde96f85858d2ea37efccf`.
   The mirror `docs/handoff/progress/slice-d-parity.progress.yaml` (field `enum_check_parity_seed_commit`) must
   equal it byte-for-byte — the test treats a mismatch as a tamper signal, not a skip.
2. **Durable pin tag** (so CI can always reach the seed object even after history is rewritten):
   ```bash
   git tag ci-pin/enum-check-parity-r1 da5ae13792e2a5067edde96f85858d2ea37efccf
   git push origin ci-pin/enum-check-parity-r1
   ```
   The tag name is pre-allocated in the YAML (`enum_check_parity_pin_tag`); the CI step fetches exactly that ref.
3. **CI wiring (S-14 dispatch leg — Session A's promotion, not this doc):** the parity gate runs in NO job until
   the `ci.yml` hunk in `docs/sessions/session-B-2026-08-23/REPORT-C26-implementer.md` §5 lands
   (`treasury-spine-pgsql`: pin-tag fetch + `EnumCheckParityTest` with the variable mapped in + liveness PG arm;
   `backend-architecture`: liveness driver-free arm). Until then the ceiling is honoured locally only:
   ```bash
   cd apps/api && ENUM_CHECK_PARITY_PROTECTED_SEED=$(git rev-parse da5ae1379) \
     DB_CONNECTION=pgsql DB_PORT=5433 DB_DATABASE=<throwaway> ./vendor/bin/phpunit tests/Architecture/EnumCheckParityTest.php
   ```
   Expected armed: `OK (11 tests, 829 assertions)`. Unset the variable → 1 failure "FAILS CLOSED" (by design).

## What this buys, and its limit
- Matched growth (new column + baseline key in one diff) → RED. Acknowledgement deleted/relabelled/predicate-moved
  → RED (R2-2 closed). Baseline shrink → still green (the burn-down proceeds without re-pinning).
- LIMIT: the pin itself is only as strong as the variable + tag being owner-held; a diff cannot edit either.
- Precondition it satisfies: Slice D batch 1 (B2-4) may start once (1) + (2) are done — LEDGER C-26 (iii).
