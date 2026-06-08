# Round-2 Re-Review: POS Manager Override Anti-Downgrade Fix
**Date:** 2026-06-03
**Reviewer:** Codex adversarial review
**Fix commit:** 05e517e4a
**Round-1 review:** 2026-06-08-pos-manager-override-anti-downgrade-codex-review.md

## MAJOR Finding Status
RESOLVED — the previous fail-open branch is now guarded by `isGenuineOfflineFailure(error)`, which only returns true for `FetchTimeoutError` or when `useConnectivityStore.getState().isOnline` is false (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:55`). In the non-`ApiRequestError` catch branch, genuine-offline evidence emits `pos.manager_override_offline_approved` and returns the local manager approval (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:243`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:248`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:260`). If that classifier is false, the code emits `pos.manager_override_denied` with `denied_kind: 'service_unavailable'` and throws a 503 `ApiRequestError` (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:271`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:283`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:286`). Therefore, an unexpected/wrapped/unknown non-`ApiRequestError` while `isOnline === true` fails closed and does not approve.

## Security Analysis: Catch Block Trace
The catch block first handles retryable `ApiRequestError`s: statuses `>= 500`, `408`, and `429` are classified by `isRetryableServerError` (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:66`). Attempts below the retry cap sleep and continue (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:184`); after exhaustion, the branch emits `pos.manager_override_denied` with `denied_kind: 'service_unavailable'` and throws a 503 (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:193`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:205`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:208`). No local approval is returned there.

Next, non-retryable `ApiRequestError`s are treated as explicit server denials: the branch emits `pos.manager_override_denied` with `denied_kind: 'server_rejected'` and rethrows the original error (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:218`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:231`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:234`). No retry and no local fallback occur.

Finally, non-`ApiRequestError` values reach the offline classifier. If `error instanceof FetchTimeoutError`, fallback is allowed (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:56`). If the connectivity store reports offline, fallback is also allowed (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:57`). Only that true classifier path emits `pos.manager_override_offline_approved` and returns the matched local operator (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:243`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:248`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:260`). Otherwise, the non-`ApiRequestError` branch emits a denial and throws a 503 (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:271`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:286`). The file ends after the infinite retry loop; there is no dead post-loop local-approval return (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:292`).

## Offline Classifier Soundness
The two accepted signals are reasonable for this security boundary. `FetchTimeoutError` is the typed overall/read-timeout path from `fetchWithTimeout`: the wrapper creates that class for timeout races (`apps/pos/src/lib/fetchWithTimeout.ts:46`, `apps/pos/src/lib/fetchWithTimeout.ts:136`) and translates timeout-triggered aborts into the same type (`apps/pos/src/lib/fetchWithTimeout.ts:154`). The connectivity flag is maintained from browser offline hints and a server health probe: `checkNow` immediately sets offline when `navigator.onLine` is false (`apps/pos/src/stores/connectivityStore.ts:25`) and otherwise sets `isOnline` from `checkServerHealth()` (`apps/pos/src/stores/connectivityStore.ts:32`); health probes themselves use a 5s timeout and return false on failed probes (`apps/pos/src/lib/connectivity.ts:17`, `apps/pos/src/lib/connectivity.ts:27`).

The classifier intentionally misses raw non-timeout Tauri/network errors while the store is still stale-online. That is visible in `fetchWithTimeout`, which documents and implements verbatim rethrow for non-timeout errors (`apps/pos/src/lib/fetchWithTimeout.ts:40`, `apps/pos/src/lib/fetchWithTimeout.ts:161`). This can hurt offline-first behavior for a first request during a connectivity race, but it fails closed rather than re-opening the PIN downgrade. Given the MAJOR security goal, that miss is acceptable: the user can retry after the connectivity monitor catches up, and the health probe cadence is short when offline (`apps/pos/src/stores/connectivityStore.ts:4`, `apps/pos/src/stores/connectivityStore.ts:47`).

## New Issues Found
None.

I specifically checked the catch-time connectivity read and did not find a new double-emit or swallowed-error path. Each catch branch either retries, emits one denial and throws, rethrows a server denial, or emits one offline approval and returns (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:184`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:218`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:243`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:271`). The unexpected-online path still surfaces the 503 to the caller via `throw new ApiRequestError(...)` (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:286`).

## Test Coverage Assessment
(a) PASS — the non-audit regression test sets the default connectivity mock to online (`apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:91`), makes `apiPost` throw a generic `Error`, and asserts rejection with status 503/code `MANAGER_OVERRIDE_SERVICE_UNAVAILABLE` (`apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:154`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:159`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:161`). The audit regression test separately asserts no `offline_approved`, a `service_unavailable` denial, and no PIN leakage (`apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:231`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:240`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:246`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:249`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:257`). These are non-vacuous because `apiPost` is the mocked failing dependency and the assertions check both thrown output and audit side effects.

(b) PASS — the connectivity-offline fallback test explicitly sets `isOnline=false`, throws a generic `Error`, expects local approval, and verifies no retry (`apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:125`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:127`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:128`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:130`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:136`). The audit companion proves the same branch emits `offline_approved` and not denied (`apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:191`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:195`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:200`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:201`).

(c) PASS — the timeout fallback test throws an actual `FetchTimeoutError`, expects local approval, and verifies one API attempt (`apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:139`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:142`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:146`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:151`). The audit companion asserts no denial and an `offline_approved` event for the same typed timeout (`apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:211`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:214`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:220`, `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:221`).

## Test Run Output
Command:
```sh
cd apps/pos && pnpm vitest run src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts 2>&1 | tail -8
```

Output:
```text

 ✓ src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts (10 tests) 44ms

 Test Files  2 passed (2)
      Tests  20 passed (20)
   Start at  12:53:56
   Duration  1.54s (transform 420ms, setup 128ms, collect 655ms, tests 55ms, environment 894ms, prepare 115ms)
```

## TypeScript Check
Command:
```sh
cd apps/pos && pnpm tsc --noEmit 2>&1 | tail -3
```

Output:
```text
```

The command exited 0 and produced no tail output.

## Verdict
APPROVE — Confidence: HIGH
