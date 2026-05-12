# PR #98 — Codex 5-round Adversarial Review Summary

**Final verdict (round-5):** APPROVE-WITH-MINOR-EDITS-APPLIED.

> "The scan cache integration is tenant-gated, invalidated on the relevant product-store update paths, and all POS tests/typecheck pass. I did not find a discrete regression introduced by this patch."

## Round-by-round closure trail

| Round | Severity | Finding | Closure SHA | Fix shape |
|---|---|---|---|---|
| 1 | P2 | Stale data after sync — Tier 0 hits return cached products even after a sync tick tombstones / updates them | `d42115f9` | `clearScanCache()` in productStore.refreshFromSQLite + foreground/menu fetch paths (and tombstone wipe). Static import to avoid microtask-flush flakes in T2.1 Step A test A.4. |
| 2 | P2 | Cross-tenant cache leakage — process-global cache let a barcode cached in company A resolve in company B after a session switch | `8213d824` | `companyId` param on `getCachedScan` / `setCachedScan` / `ResolveScannedCodeDeps`. Mismatched read returns null AND evicts the stale entry. Same code can independently cache for two companies (correct behaviour). |
| 3 | P2 | Cache cleanup only fired from `productStore.reset()` — sync scheduler's confirmed-401 path calls `authStore.logout()` directly | `0f69d729` | `clearScanCache()` added to `authStore.logout()` (canonical session-ending hook). `productStore.reset()` retains the call as defense-in-depth. |
| 3 | P3 | Chooser pick stored `chooserState.scannedCode` raw while the resolver `code = rawCode.trim()`s — whitespace-padded scanners broke chooser preference | `0f69d729` | `chooserState.scannedCode.trim()` at the cache-write site, matching the resolver's normalization. |
| 4 | P2 | Initial SQLite hydration in `fetchProducts` (Step 1) replaced in-memory products without clearing the cache — stale cache could outlive a fresh SQLite catalog | `55c64fff` | `clearScanCache()` in Step 1 when the `diffProducts` reports the cached vs in-memory snapshot changed. Same diff-gate as the foreground-pull path so unchanged catalogs don't thrash the cache. |
| 5 | — | APPROVE | — | No new findings. |

## Final state

- **Cache shape:** module-level Map<code, { companyId, product }> with insertion-order LRU. Capacity 100 entries.
- **Tenant scoping:** every `get` / `set` requires `companyId`. Cross-tenant collisions evict the stale entry on miss.
- **Invalidation paths:** sync-tick refresh (`refreshFromSQLite`), foreground pull (`doStandardForegroundPull` non-empty + tombstone branches), Menu-mode fetch, initial SQLite hydration in `fetchProducts` Step 1, `productStore.reset()`, `authStore.logout()`.
- **Chooser-pick preference:** trimmed `scannedCode` matched against the resolver's normalization.

## Test coverage

- **scanResolutionCache.test.ts:** 12 tests. LRU mechanics (promotion on read + re-set, capacity eviction), tenant scoping (cross-company miss + evict, same code per-company), evict + clear primitives, capacity sanity bounds.
- **resolveScannedCode.test.ts:** 7 new B.10–B.16 tests. Tier 0 hit short-circuits later tiers; Tier 1/2/3 hits write back to cache; chooser results NOT auto-cached; chooser-pick preference round-trip; miss not memoized.
- **productStore.scanCacheInvalidation.test.ts:** 5 tests. refreshFromSQLite invalidation (changed/unchanged/empty), foreground pull invalidation, tombstone-wipe invalidation.

**Pre-PR:** 1043/1043 in 118 files.
**Post-PR:** 1067/1067 in 120 files (+24 new tests, +2 new files).
typecheck clean.

## Out of scope (carried)

- Per-tier cache (e.g., separate Tier 1 cache for in-memory hits vs Tier 3) — not warranted for the current cashier scan volume; LRU is sufficient.
- Tier 3 negative-result memoization — explicitly out of scope; a newly-onboarded product must be resolvable mid-session without app restart.
- Concurrency hardening — single-threaded JS event loop; no race scenarios that the existing tests don't already cover.
