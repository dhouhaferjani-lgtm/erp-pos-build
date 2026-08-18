# Country-defaults specialist verdict freshness

Source: terminal audit round 1, finding F-7 (P3).

## Risk

The committed M7 treasury, tenancy/authz, and frontend specialist verdicts are frozen at
`c6dc598c5`, five commits before the terminal-audit tip. Later commits were reviewed as
documentation-only by the M7 adversarial gate, but the specialist artifacts themselves do not
attest to the final merge candidate. A future promoter must reconstruct path relevance instead of
reading a verdict issued at the exact terminal SHA.

## Owner and status

- Owner: execution harness / release integrator
- Status: OPEN; non-blocking terminal-audit evidence hardening

## Acceptance criteria

- Rerun every required specialist against the exact frozen merge-candidate SHA after the final fix,
  or make the harness produce a machine-checked path-delta attestation proving that no file in that
  specialist's scope changed after its verdict.
- Commit each verdict or attestation with the reviewed full SHA and exact commands/results.
- Make the terminal register link those final artifacts directly.
