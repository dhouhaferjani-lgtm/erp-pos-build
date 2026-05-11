# PR D — Codex Round 5 Review

**Subject:** feat(pos): real-time catalog refresh via WebSocket (Bug 1)
**PR:** https://github.com/otospexsolutions/erp/pull/122
**Base:** `dev`
**Branch:** `feat/pos-catalog-websocket-sync` (head `cf9e4f29` — Codex r4 P2 closure)
**Date:** 2026-05-11

## Findings

### [P2] Avoid recreating Echo during cleanup

`apps/pos/src/hooks/useCatalogChannel.ts:75-77`

When logout runs, `authStore.logout()` calls `disconnectEcho()` before clearing `user/companyId`; this effect cleanup then calls `getEcho()`, which lazily creates a brand-new Echo instance just to leave the old channel. In that logout path the POS can remain connected with a fresh unauthenticated WebSocket after sign-out, so cleanup should only leave if an Echo instance already exists rather than creating one.

## Verdict

**REQUEST-CHANGES** — single P2 must close before merge.

## Resolution applied (round 6 prep)

Added `peekEcho()` to `apps/pos/src/lib/echo.ts` that reads `window.Echo` without creating one. The hook's cleanup now calls `peekEcho()` and only invokes `leave(channelName)` if an instance exists. This matches the logout path's intent: when `disconnectEcho()` has already run, `peekEcho()` returns null and the cleanup is a no-op.

New regression test:
- `cleanup does NOT touch echo when peekEcho returns null (logout path)` — flips the mock's `peekEcho` to null, unmounts the hook, asserts `leave` was never called.

## Round-budget status

Round 5 of 5 in the "kickoff under-spec" band per the STOP-3 rule. The PR D ingress list missed: composite items (r2), modifier groups + modifiers (r3), pivot writes (r4), and the logout-path lazy-create race (r5). All findings are real correctness issues; none required structural rework. If round 6 surfaces another P2, STOP-3 fires and I brief Opus before pushing further.

## Raw codex output

> The catalog broadcasting flow is largely covered, but the POS hook cleanup can recreate a WebSocket after logout, leaving an unintended connection open. This should be fixed before considering the patch correct.
>
> Review comment:
>
> - [P2] Avoid recreating Echo during cleanup — apps/pos/src/hooks/useCatalogChannel.ts:75-77
>   When logout runs, `authStore.logout()` calls `disconnectEcho()` before clearing `user/companyId`; this effect cleanup then calls `getEcho()`, which lazily creates a brand-new Echo instance just to leave the old channel. In that logout path the POS can remain connected with a fresh unauthenticated WebSocket after sign-out, so cleanup should only leave if an Echo instance already exists rather than creating one.
