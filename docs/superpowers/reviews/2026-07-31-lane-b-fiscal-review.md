# Lane B review record — fix/pos-payload-guards (first-tenant program)

**Reviewer:** fiscal-pos-reviewer (Opus). **Target:** `fix/pos-payload-guards` vs `origin/dev @ 711f3d79f`.
**Merged to local dev** 2026-07-31 (merge of `d215daaf2`). **Lane C code-phase fence UNBLOCKED.**

## Round 1 (@ 86a4eb6c4): APPROVE-WITH-FIXES
Manifest exact (4 files); fiscal-payload freeze intact. B1 parser reads the real private int-keyed
CAPS, both drift directions covered, existing key-set pin untouched. B2 real-SQLite via production
migrations, all 32 binds independently asserted, ±1-shift detectable. B3 asserts tolerance_summary
inside the REAL signed canonical_bytes; mock-widening proven safe. B4 spread works and
cartMutatorGuard test green — BUT [Important] the added stock/cartStore `ignores` removed those
files from the ENTIRE third block, silently deleting their BEGIN/COMMIT/ROLLBACK single-writer
guard (measured: rule `undefined` on cartIngress/stockGate/cartStore — latent regression on the
"database is locked" root-cause guard). Reviewer built + verified the fix pattern. 3 docblock
accuracy fixes. Confirmed pre-existing/unchanged: hardcodedColorSelector dead app-wide (ticket).

## Fix round (@ d215daaf2) → Round 2: APPROVE
Fourth block byte-for-byte the verified pattern; --print-config on six representative files:
stock/cartStore = TX only (restored), non-exempt = all 5, writeGate = cart-only (unchanged);
eslint src 0 errors; 26/26 tests across 5 files; docblocks accurate; nothing rode along.

## Carry-over tickets (out of lane scope)
1. `hardcodedColorSelector` dead app-wide (third block replaces the rule for tokenMigratedGlobs) —
   `docs/superpowers/tickets/2026-07-31-eslint-color-guard-inert.md`
2. `buildFiscalCloseInput` `zReport.tolerance_summary → closeInput.toleranceSummary` mapping has no
   direct test pin — `docs/superpowers/tickets/2026-07-31-z-close-input-mapping-unpinned.md`
