# Fiscal event-chain verifier operability follow-ups

**Raised by:** ES Wave A0, M1 round-1 review. **Status:** OPEN — explicitly
out of M1's verifier-hardening scope. **Owners:** Fiscal domain owner and Fiscal
platform/operations owner. **Blocks M1 merge:** no; the findings are now durable
and bounded, but each item blocks its own operational readiness claim.

## FEV-OPS-01 — define remediation for permanently quarantined rows

`fiscal:verify-event-chain` correctly treats any `integrity_status` other than
`verified` as an incident. Today, however, production has no general remediation
path for `time_anomaly`, `sequence_gap`, or `canonical_hash_mismatch`: the only
`quarantined → verified` writer is `ParseFailureResolutionService`, and the
immutability trigger restricts it to `canonical_parse_failure` rows. A single
historical quarantine can therefore keep one chain, and consequently its fleet
run, red forever.

This milestone must not invent an approval, correction-event, or suppression
workflow. The Fiscal domain owner must rule on the compliant lifecycle and audit
record for each quarantine class. Acceptance for the later implementation:

- every quarantine class has a documented terminal state and accountable actor;
- resolved/superseded incidents remain auditable without rewriting sealed bytes;
- the verifier distinguishes an active incident from its approved terminal state;
- a real unresolved incident still produces a non-zero chain and fleet verdict.

## FEV-OPS-02 — bound and resumably execute fleet verification

The manifest driver currently enumerates all distinct
`(terminal_id, chain_context)` pairs and synchronously invokes the complete
single-chain walk for each pair. That is correct for launch-sized fixtures but is
an unbounded fleet walk: runtime and memory grow with tenant, chain, and event
counts, with no checkpoint or resume token after interruption.

The Fiscal platform/operations owner must first measure representative fleet and
chain sizes, then set an operator-facing execution budget. Acceptance for the
later implementation:

- enumeration and chain reads are demonstrably bounded (chunk/cursor sizes are
  explicit and measured);
- progress is checkpointed at an immutable coordinate and can resume without
  silently skipping a tenant or chain;
- interrupted and transient-failure runs return non-zero and name the unverified
  coverage;
- the existing manifest actor gate, per-tenant binding, and aggregate fail-closed
  semantics remain unchanged.
