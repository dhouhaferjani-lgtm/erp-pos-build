# Fiscal event-chain verifier operability follow-ups

**Raised by:** ES Wave A0, M1 round-1 and round-4 reviews. **Status:** OPEN — explicitly
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

## FEV-OPS-03 — cover quarantine-only terminal/context pairs in fleet verification

**Owner:** Fiscal platform owner, with Fiscal domain owner sign-off on what
constitutes complete incident coverage. **Current status:** OPEN. M1 deliberately
retains the brief-pinned enumeration source: distinct `(terminal_id,
chain_context)` pairs from `fiscal_events` only.

Malformed envelopes can be written to `fiscal_event_quarantine` without any
corresponding `fiscal_events` row. A quarantine-only pair is therefore invisible
to the fleet wrapper, because `reportQuarantineIncidents()` runs only after that
pair has been enumerated from `fiscal_events`.

Exact false-green scenario: a device's first `z_session` envelope is malformed.
The tenant receives one unresolved quarantine row at `(terminal T, z_session)`
and no fiscal-event row for that pair. If `(T, operational)` has events, the fleet
enumerates only the operational pair, prints `TENANT X: VERIFIED 1 chain(s)` and
`fleet chain verification completed: 1 tenant(s), 1 chain(s), no failures`, and
exits 0. A direct single-chain invocation for `T/z_session` would report the
quarantine, so the gap is specifically fleet target coverage.

Acceptance contract for any future union/coverage change:

- after authorization inside bound tenancy, targets include the set union of
  distinct `(terminal_id, chain_context)` pairs from `fiscal_events` and
  unresolved `fiscal_event_quarantine` rows, or an equivalently complete source;
- a quarantine-only pair invokes the existing child verifier and produces a
  named incident plus non-zero aggregate exit;
- duplicate pairs across both tables invoke exactly one child verification;
- resolved quarantine-only rows follow an explicitly ruled inclusion policy and
  do not silently distort active-incident coverage;
- the manifest actor gate, tenant binding, per-target child delegation, and
  brief-pinned fiscal-event enumeration behavior remain intact for existing
  fiscal chains;
- tests include the exact first-malformed-`z_session` false-green scenario and a
  clean control.

## FEV-OPS-04 — preserve transient semantics at the fleet boundary

**Owner:** Fiscal platform/operations owner. **Current status:** OPEN. The child
command documents exit 2 as transient/retryable, but the fleet currently maps
every non-zero child result to aggregate exit 1. An automation reading only the
fleet exit code cannot distinguish infrastructure retry from a verified chain
incident.

Acceptance contract:

- the fleet's public exit-code policy explicitly distinguishes retryable child
  failure from chain/validation failure, or documents a richer machine-readable
  result channel with equivalent information;
- mixed results have a ruled precedence (for example, incident plus transient)
  and name every unverified tenant/chain;
- human-readable per-tenant output and automation-facing semantics agree;
- the existing single-chain 0/1/2 contract remains unchanged.

## FEV-OPS-05 — migrate ongoing server-authored builders to seal chain context

**Owner:** Fiscal domain owner. **Current status:** OPEN. The verifier compatibility
path is not merely historical: `TerminalRegistrySnapshotService` and
`VirtualAdminFiscalEventService` currently continue to author the 14-key shape
without `chain_context`. Every future `TERMINAL_REGISTRY_SNAPSHOT`,
`ACCOUNT_STATUS_CHANGED`, and `DEPOSIT_RECEIPT` row therefore uses the carve-out,
including new partner-money deposit receipts. Existing production comments that
describe only a historical shape are inaccurate framing; the code gate itself is
shape-based and correctly remains fail-closed outside the sanctioned set.

Acceptance contract for a forward-only builder migration:

- all three authoring paths include the resolved `chain_context` in new canonical
  bytes and pass the ordinary strict parser path;
- already sealed 14-key rows remain immutable and continue to verify through the
  compatibility path;
- tests discriminate old compatible rows from newly authored fully sealed rows;
- the migration is coordinated with M4's eventual company/context chain-head
  correctness work rather than changing chain-head behavior inside M1;
- no historical bytes are rewritten and payload/signature semantics are
  unchanged.

## FEV-OPS-06 — rule on zero-event success for an existing context

**Owner:** Fiscal domain and launch-readiness owners. **Current status:** OPEN,
pre-existing behavior. For an existing terminal and requested context with no
events and no unresolved quarantine incidents, `fiscal:verify-event-chain`
prints `chain verified — … 0 events walked` and exits 0. The count is honest and
the command is not quarantine-blind, but a launch checklist may treat the word
`verified` as proof that a chain existed.

Acceptance contract:

- product/operations explicitly rule whether an empty existing context means
  success, no-data/non-zero, or a distinct machine-readable result;
- output cannot be mistaken for verification of event rows that do not exist;
- an empty context with an unresolved quarantine still fails;
- terminal ownership, actor authorization, and non-empty-chain behavior remain
  unchanged.

## FEV-OPS-07 — reclaim physical tenant databases after abnormal test termination

**Owner:** Test infrastructure owner. **Current status:** OPEN, plausible and not
reproduced. `VerifyEventChainFleetCommandDbPerTenantTest` creates a physical
PostgreSQL tenant database plus central `tenants`/`domains` rows and performs
best-effort cleanup in `tearDown()`. A fatal process death, SIGKILL, or OOM can
bypass teardown and leave both database and directory residue.

Acceptance contract:

- the PG acceptance lane uses a run-scoped, mechanically discoverable database
  and directory-row naming/metadata convention;
- setup or an external test-runner finalizer safely reclaims stale resources from
  prior aborted runs without deleting non-test tenants;
- cleanup failures are visible rather than swallowed as a green run;
- normal teardown remains idempotent, and an induced-abort integration check or
  equivalent harness test demonstrates stale-resource reclamation.

## FEV-OPS-08 — align the legacy timestamp comparison rationale

**Owner:** Fiscal domain owner. **Current status:** OPEN, documentation-quality
only. The verifier's legacy `event_time_device` branch is justified in PHP as a
non-UTC connection/session compatibility rule, but both sanctioned authoring
services and the supported app configuration currently pin UTC. Round-5 review
could demonstrate neither a false green nor a false red, including with a
`Europe/Paris` database default timezone.

Acceptance contract:

- the comment states the actual invariant the branch preserves, backed by a
  regression that distinguishes it from the ordinary UTC-normalized path; or
- the dead branch is removed after proving ordinary UTC normalization verifies
  every sanctioned 14-key envelope shape;
- sealed timestamps are never rewritten.

## FEV-OPS-09 — rule the terminal state for resolved parse divergences

**Owner:** Fiscal domain owner under owner decision D-8. **Current status:** OPEN.
After `ParseFailureResolutionService` successfully resolves a
`canonical_parse_failure`, the immutable canonical bytes still disagree with the
new stored parsed payload. M1 intentionally detects that divergence, so the
single-chain and fleet verifiers remain red even though the row is no longer
quarantined. This is distinct from FEV-OPS-01's still-quarantined classes.

Acceptance contract:

- D-8 rules whether a second approver and a new correcting fiscal event are
  required; no sealed row is rewritten in place;
- the verifier distinguishes an active unresolved divergence from its approved,
  auditable terminal state without hiding the original mismatch;
- both pre-approval and approved-terminal-state behaviors have production-path
  tests, and a genuine unapproved payload/canonical mismatch remains non-zero.
