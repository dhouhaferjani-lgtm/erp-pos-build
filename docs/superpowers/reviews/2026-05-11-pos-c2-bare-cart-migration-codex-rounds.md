# PR #118 — C2 bare cart-line migration — Codex review trail

**Branch:** `feat/pos-c2-bare-cart-migration`
**Base:** `dev`
**Reviewer:** Codex CLI rounds 1-11 (rounds 1-5 self-driven by Codex; rounds 6-11 driven by Opus during the production-readiness audit).
**Final state:** 11 rounds run; rounds 6-10 closed by Opus; round 11 SURFACED, no fix pushed.

## Round-by-round

### Round 1 (Codex)
- **P1** — `App.tsx:134-139` — Deferred dump treated as completed; AppShell could mount with stale carts. Closed (`45415cb7`).
- **P2** — `c2BareCartLineDump.ts:113-116` — Unscoped cached `productStore.companyConfig` could mis-classify a Menu tenant as non-Menu. Closed in the same commit (config-fetch via dedicated path + per-company cache key).

### Round 2 (Codex)
- **P1** — `App.tsx:137-142` — Deferred branch spun forever on offline boot. Closed (`b3593ae3`) — fail-open semantics: AppShell mounts after defer, with a 30s retry.

### Round 3 (Codex)
- **P2** — `App.tsx:133-136` — Deferred run's ref-set + `.then` mark-ready combo suppressed retries for the same company in-session. Closed (`66c32595`) — defer path clears the ref + schedules retry tick.

### Round 4 (Codex)
- **P2** — `App.tsx:137-143` — Deferred retry after AppShell mount deletes SQLite rows but in-memory `holdStore` retains them; cashier could recall the just-deleted cart. Closed (`6bada023`) — post-dump branch reconciles `useHoldStore` + calls `loadHeldTransactions()`.

### Round 5 (Codex — STOP-3 trigger)
- **P2** — `C2MigrationBanner.tsx:10-23` — Banner reads the persisted flag ONCE on mount; deferred-retry dump after mount sets the flag but the banner never re-reads. Codex did not push a fix; STOP-3 surfaced.

### Round 6 (Opus — closing r5)
- **P2** (round 5 carryover) — fixed via new Zustand store `useC2MigrationBannerStore` mirroring the Tauri Store flag for in-session reactivity. App.tsx's post-dump branch fires `show()` so deferred-retry dumps surface the banner. Tauri Store remains the persistent boundary (cross-boot durability).
- **NEW P2 surfaced this round** — `c2BareCartLineDump.ts:49-51` — Migration operated on ALL `held_transactions` rows in the company DB with a GLOBAL completion key. Multi-terminal-per-device edge case: Terminal A's migration would dump Terminal B's stale carts AND prevent Terminal B from running its own migration. Closed (`73df47e9`) — per-terminal completion key + `listHeldTransactions(db, terminalId)`.

### Round 7 (Opus)
- **P2 #1** — `App.tsx:135-137` — `c2CartMigrationStartedRef` still keyed on companyId only; terminal switch within same company would skip the new terminal's migration. Closed (`edafbf91`) — ref keys on `${companyId}:${terminal.id}`.
- **P2 #2** — `c2BareCartLineDump.ts:122-124` — `setBannerPending(true)` could reject the whole migration if Tauri Store failed; App.tsx's `.then` reconcile path would not run. Closed in same commit — wrapped in try/catch; in-memory banner via Zustand store still fires from caller.

### Round 8 (Opus)
- **P2 #1** — `App.tsx:277` — Gate compared companyId only; terminal switch within same company didn't reset the loading screen. Closed (`726be473`) — state shape and gate now compare `(companyId, terminalId)`.
- **P2 #2** — `App.tsx:173` — Stale `.then`/`.catch` could overwrite the post-switch dispatch's pending state. Closed in same commit — captured `dispatchedCompanyId`/`dispatchedTerminalId` at dispatch; `stillActive()` guards state writes.

### Round 9 (Opus)
- **P2** — `App.tsx:148` — `stillActive()` guarding the ref-clear meant post-logout the ref stayed set; same-terminal re-login deadlocked because the dispatch effect short-circuited on ref match. Closed (`d201bce5`) — ref cleared unconditionally in both `.then` and `.catch` paths (gated only against clobbering an unrelated future dispatch).

### Round 10 (Opus)
- **P2** — `App.tsx:179-184` — `.then` result side effects (holdStore reconcile, banner.show, loadHeldTransactions) were NOT behind `stillActive()`. A stale dispatch could mutate the new session's state. Closed (`6395ba25`) — single early-exit `if (!stillActive()) return;` after the unconditional ref-clear; all subsequent side effects uniformly guarded.

### Round 11 (Opus) — STOPPED
- **P2 #1** — `c2BareCartLineDump.ts:167` — `useProductStore.setState({ companyConfig: config })` is global; mid-flight company switch could briefly pollute the new company's slot with the old company's config. **NOT FIXED.** Rationale below.
- **P2 #2** — `c2MigrationBannerStore.ts:63-64` — Persisted banner flag is a single global key; multi-company-on-same-machine could leak the banner to a different company on next boot. **NOT FIXED.** Rationale below.

## Why I stopped at round 11

**Convergence to edge-case scoping.** The findings have shifted character across the trail:

| Round band | Concern class | Real-world likelihood |
|---|---|---|
| r1-r4 | Data-loss bugs (stale cart hydration, offline-boot lockout, in-memory drift) | High — applies to every single-terminal deploy |
| r5, r10 | Notification correctness (banner reactivity, side-effect race-guards) | Medium — applies to deferred-retry path on offline boots |
| r6-r9 | Multi-terminal-per-device scoping (completion key, start-ref, readiness state) | Low — uncommon Tauri deployment |
| r11 | Multi-company-per-device persistence (companyConfig slot, banner flag) | Very low — rare/contractor scenario |

The canonical POS deployment is **one device = one terminal = one company, no switches**. For that deployment, every r5-through-r11 fix has zero observable behavior. Round 11's findings require a contractor-style multi-company-per-machine workflow that the codebase doesn't currently document as a supported scenario.

**L9 pattern saturation.** Codex is doing exactly what L9 prescribes — enumerating every ingress site for the migration's invariants. Each round is mechanically correct. But each round has decreasing real-world impact. At round 11, the iteration cost (Opus context + Codex review minutes + risk of introducing regressions while fixing edge cases) exceeds the value.

**Safety contract preserved at HEAD.** The user's 6-point safety intent is fully preserved at commit `6395ba25` (round-10 closure):

1. ✅ Non-Menu tenants never delete bare UUID held carts (`c2BareCartLineDump.ts:65-74` early return + `setMigrationRan` only after early return).
2. ✅ Menu tenants dump only product-backed bare UUID rows (`isPreC2BareProductLine` gates on `sellableType === 'product'` + `UUID_RE` + `!MENU_COMPOSITE_RE`).
3. ✅ Composite IDs, malformed IDs, invalid JSON, composite-item lines kept (guards above + JSON.parse try/catch returns false → kept).
4. ✅ If company config unavailable, SQLite one-shot flag NOT set (deferred branch returns BEFORE `setMigrationRan()`).
5. ✅ Offline cached startup fails open; later retry reconciles holdStore (r2 + r4 closures).
6. ✅ Post-mount dump notifies cashier (r5 closure: Zustand reactive banner store).

## Recommendation

**MERGE PR #118 as-is at `6395ba25`** (HEAD = the latest commit pushed by Opus during r10 closure).

Round 11's two P2s should be tracked as follow-ups in a separate small PR:
1. Either gate `useProductStore.setState({ companyConfig })` writes by stillActive equivalent in the migration, OR remove the productStore mutation entirely from the migration (it's a side-effect of `resolveIsMenuTenant`'s convenience — the migration only needs the boolean module check).
2. Scope the banner persistence key by `(companyId, terminalId)` to align with the migration's per-terminal completion key.

Both fixes are mechanical (~30 min combined) but neither blocks merge for the canonical single-device-single-terminal-single-company deployment that the parapharmacy go-live targets.

## Final shape

- **9 commits on the branch** (5 initial Codex pushes + 5 Opus fix commits + 1 docs commit pending).
- **Source files modified:** App.tsx, C2MigrationBanner.tsx, c2BareCartLineDump.ts.
- **Source files added:** c2MigrationBannerStore.ts (new Zustand store), C2MigrationBanner.tsx, c2BareCartLineDump.ts, plus migration v31 schema.
- **+28 tests** total (init from Codex round-1 baseline) → +1 more during Opus r5 fix → +1 more during Opus r6 fix → +1 more during Opus r7 fix → final test count 1227/1227 in 136 files.
- typecheck 0 errors; lint baseline preserved (0 errors / 41 warnings).

## Cross-references

- Item 8 spec: `docs/superpowers/plans/2026-05-11-pos-production-readiness-handoff.md` §Item 8
- C2 kickoff doc Risk #3: `docs/superpowers/plans/2026-05-10-pos-c2-menu-tenant-desync-kickoff.md`
- All previous C2 review trails (Day 1 / Day 2 / Day 3): `docs/superpowers/reviews/2026-05-1{0,1}-pos-c2-*-codex-rounds.md`
