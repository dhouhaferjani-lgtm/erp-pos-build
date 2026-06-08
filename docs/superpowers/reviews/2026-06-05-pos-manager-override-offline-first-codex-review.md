# Adversarial Security Review: POS Manager Override Offline-First Fix

Review target: `git diff 2fc016324..cc8af5425 -- apps/pos`

Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/pos-multitenant-login`

## Verification Commands

Command:

```sh
cd apps/pos && pnpm vitest run src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts 2>&1 | tail -8
```

Actual output:

```text
 ✓ src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts (4 tests) 10ms

 Test Files  2 passed (2)
      Tests  10 passed (10)
   Start at  01:22:55
   Duration  1.38s (transform 369ms, setup 180ms, collect 548ms, tests 16ms, environment 1.02s, prepare 98ms)
```

Command:

```sh
cd apps/pos && pnpm tsc --noEmit 2>&1 | tail -3
```

Actual output:

```text

```

The TypeScript command produced no tail output.

## Findings

### 1. MINOR: Server-success audit test would not catch accidental `offline_approved` emission

Problem statement:

The production code returns immediately on server-confirmed success, so this is not a current implementation bug. However, the audit test named "does NOT emit when verification succeeds" only checks that `pos.manager_override_denied` was not emitted. If a future regression emitted `pos.manager_override_offline_approved` on the valid:true path before returning, this test would still pass.

Evidence:

- `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:114` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:118` returns the server-confirmed approval before the offline fallback audit block.
- `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:177` to `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:183` performs the successful verification test, but only asserts `lastDenied()` is undefined.

Concrete fix:

In the success test, also assert that `lastOfType('pos.manager_override_offline_approved')` is undefined, or assert that `recordAuditEvent` was not called at all for that success path.

### 2. HYPOTHESIS - MINOR: The all-4xx deny boundary may block offline-first behavior for infrastructure-generated 408/429 responses

Problem statement:

Under the owner-decided policy, all `ApiRequestError` statuses below 500 deny. The implementation follows that policy. The adversarial edge case is that not every possible 4xx necessarily means the application server made an authorization decision. For example, a gateway/proxy/client-facing layer could synthesize `408 Request Timeout` or `429 Too Many Requests`. If those reach this path as `ApiRequestError`, the POS will deny instead of falling back to the already local-validated manager PIN, even though the server may not have actually decided on the override.

Evidence:

- `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:120` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:138` treats every `ApiRequestError` with `status < 500` as a denial and rethrows.
- `apps/pos/src/lib/api.ts:140` to `apps/pos/src/lib/api.ts:163` wraps every non-OK HTTP response, including 408/429 if returned by the network path, as `new ApiRequestError(response.status, ...)`.

Concrete fix:

If product/security wants "could not decide" HTTP statuses to preserve offline-first behavior, split the boundary into explicit-denial statuses/codes versus degraded statuses. For example, continue denying app-level validation/authz failures such as 400/401/403/404/409/422, but fall back for 408/429 or for a server-provided code that means timeout/rate limit rather than authorization rejection. If the owner policy intentionally requires deny for 408/429 too, add focused tests for 408 and 429 so that this tradeoff is explicit and durable.

## Control Flow Review

- `valid:true` with matching `user_id` returns server-confirmed approval at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:114` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:118`. It does not enter the catch block, so it does not emit `pos.manager_override_offline_approved`.
- `valid:false` or `valid:true` with a mismatched/missing `user_id` throws `ApiRequestError(403, ...)` at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:106` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:112`, then the 4xx branch emits `pos.manager_override_denied` and rethrows at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:120` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:138`.
- A thrown 4xx `ApiRequestError` from `apiPost` follows the same denial branch at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:120` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:138`.
- A 5xx `ApiRequestError` skips the 4xx branch, emits `pos.manager_override_offline_approved` with `degraded_kind: 'server_error'` at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:149` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:162`, then falls through to the local approval at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:166` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:170`.
- A non-`ApiRequestError` transport/timeout error emits `pos.manager_override_offline_approved` with `degraded_kind: 'transport'` at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:149` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:162`, then falls through to the local approval at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:166` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:170`.
- I found no path that emits both `pos.manager_override_denied` and `pos.manager_override_offline_approved`: the 4xx branch rethrows at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:138` before the offline audit block.
- The expected paths that emit neither are server-confirmed success and local approval return after the catch completes. Server-confirmed success is intentional. Local fallback emits offline-approved before returning.

## Offline-First Integrity

Genuine offline transport failure still approves locally after the cached PIN and scope have already been validated. The relevant behavior is the non-`ApiRequestError` fallback at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:149` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:170`, and the transport test covers this at `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:95` to `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:102`.

The only offline-first boundary concern I found is the HYPOTHESIS above for infrastructure-generated 408/429 responses.

## Security And Audit

- Server-unconfirmed approvals now emit `pos.manager_override_offline_approved` before local fallback returns, at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:150` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:162`.
- `degraded_kind` correctly distinguishes 5xx from transport by checking `error instanceof ApiRequestError` at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:149`.
- The offline-approved payload contains only `scope`, `reason`, and `degraded_kind` at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:157` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:161`; it does not include the PIN or hash.
- The denied payload contains `scope`, `requested_amount`, `cart_total`, and `reason` at `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:131` to `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:136`; it does not include the PIN or hash.

## 4xx Boundary

The implementation matches the stated policy: valid:false is converted to a 403 denial, and any `ApiRequestError` with `status < 500` denies. The real `ApiRequestError` class has a required numeric `status` constructor parameter at `apps/pos/src/lib/api.ts:43` to `apps/pos/src/lib/api.ts:52`, and the shared API client constructs it from `response.status` at `apps/pos/src/lib/api.ts:163`.

Malformed `ApiRequestError` instances with missing/non-numeric status are not expected from the shared API client. If one were somehow constructed, `error.status < 500` would not be a reliable classifier; with the current real constructor and call sites, I did not find a practical mis-route.

## Catalog And Type Coverage

`pos.manager_override_offline_approved` is added to the POS audit event union at `apps/pos/src/lib/audit/eventTypes.ts:36` to `apps/pos/src/lib/audit/eventTypes.ts:38`. The taxonomy also contains the event and payload shape at `docs/superpowers/specs/2026-06-04-pos-fraud-event-taxonomy.md:46` to `docs/superpowers/specs/2026-06-04-pos-fraud-event-taxonomy.md:47`.

I found no additional production emit site that should reference this event. The only intended emit site is the offline-first fallback in `verifyScopedManagerPin`.

## Test Quality

The tests cover:

- explicit `valid:false` denial: `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:86` to `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:92`
- transport fallback approval: `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:95` to `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:102`
- 4xx denial: `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:105` to `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:108`
- 5xx fallback approval: `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:111` to `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.test.ts:125`
- 5xx offline audit with `server_error`: `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:140` to `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:158`
- transport offline audit with `transport`: `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:161` to `apps/pos/src/lib/operatorApproval/__tests__/scopedManagerPin.audit.test.ts:174`

They are not vacuous on the new fallback behavior, but the success-path audit assertion has the gap described in Finding 1.

## Verdict

APPROVE-WITH-MINOR-EDITS

## Confidence

high

## Severity Count

| Severity | Count |
|---|---:|
| BLOCKER | 0 |
| MAJOR | 0 |
| MINOR | 2 |
| NIT | 0 |

