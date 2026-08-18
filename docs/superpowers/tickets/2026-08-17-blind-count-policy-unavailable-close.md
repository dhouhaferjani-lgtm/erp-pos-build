# Blind-count policy unavailable during shift close

## Finding

The M4 disclosure fix deliberately fails closed when the online fraud-settings request fails and the device has no cached company policy. The end-of-day preview stays hidden and the operator is told to connect once and retry. This prevents an unknown policy from falling through to the legacy expected-cash card, but it also means the device cannot close the shift or generate its Z report until policy synchronization succeeds.

The empty-cache state is reachable after activation while offline or after a failed pre-warm. Before M4 it degraded to the legacy preview; after M4 it is a hard availability block. That security/availability trade-off needs explicit fiscal operations ownership rather than being treated as an incidental UI state.

A terminal-record refresh while the modal is open also creates a transient unavailable/pending interval. The disclosure-safe implementation resets the count payload, readiness, and Commit Counts boundary because the reconciliation component is unmounted. Any physical counts, variance reason, or manager verification already entered are therefore discarded and the operator must recount. Preserving those values safely or warning before refresh is a separate workflow decision; stale committed state must never be restored across the policy boundary.

## Owner ruling — Option 1 approved

Fail-closed stands. When a device has no cached fraud policy and cannot complete the online policy
fetch, it must block shift close and Z generation until a policy sync succeeds. This state is limited
to a never-synced or wiped device; sealing a Z under an unknown fraud policy would recreate the
silent-bypass class this program exists to eliminate.

The ruling carries two binding operational conditions:

1. The SV-11 device build must be deployed fleet-wide before the SV-9 server migration reaches any
   promotion. Because `tenants:migrate` rides `origin/dev` pushes unattended, missing device-rollout
   evidence is a hard promotion blocker. The deploy record is
   `docs/superpowers/tickets/2026-08-12-sv9-blind-count-default-deploy.md`.
2. A freshly provisioned device must complete one successful cash-count policy sync before its first
   shift close or Z generation. The operator procedure is in `docs/pos-operations/install.md`.

No fallback may silently assume non-blind mode or render the legacy expected-cash card.

## Carried display P3s

These M4-round5 display defects remain ticketed and are non-blocking for this ruling:

- While `phase === 'confirming'`, a policy refresh that is still pending can replace the modal body
  with a blank state even though close/Z generation is already in flight.
- While `phase === 'confirming'`, a failed refresh can show the false "policy has not been synced"
  error and an inert Cancel action even though the authoritative confirmation may still succeed.

The confirmation result must remain authoritative. A later UX fix must not re-enable dismissal or
expose expected/variance values while the close is locked.

## Follow-up acceptance

1. Add deployment/support guidance for identifying an empty policy cache and restoring it without exposing expected cash.
2. Add monitoring for repeated policy-unavailable close attempts if the device telemetry surface supports it.
3. Preserve the existing Header integration test for the real trigger (online fetch rejects and cache lookup returns null) and the modal tests proving preview values stay absent in the unavailable state.
4. Decide whether a mid-count terminal refresh should be deferred, warn the operator, or preserve a versioned draft. Acceptance must prove that count input is either restored only under the same trusted policy version or explicitly discarded with operator-visible guidance, while expected/variance values stay concealed.
5. Resolve the two carried `confirming` display P3s above without weakening the authoritative confirmation result or disclosure gate.
6. Harden any future non-policy preview reload (shift or terminal preview inputs changing while open) with the same versioned payload/readiness/commit invalidation used for policy refreshes. That path is not reachable through current store update flows, but it must not grow into a second stale-reveal boundary.

Any recovery must fail closed for disclosure: it may restore a trusted policy, but it must not silently assume non-blind mode or render the legacy expected-cash card.

## References

- `apps/pos/src/components/Header.tsx:120-246`
- `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx:113-124,226-256`
- `apps/pos/src/components/__tests__/Header.test.tsx`
- `apps/pos/src/components/pos/EndOfDayPreviewModal.test.tsx`
- `docs/handoff/reviews/sv-stage1/M4-sv10-leak-audit.md`
