# POS Go-Live PR #4 Codex Review - Round 3

Command: `codex review --base dev`

Scope reviewed: POS operator runbooks under `docs/pos-operations/`.

Result: CHANGES REQUESTED

Findings:

- P2: Required restore copies did not consistently use `-ErrorAction Stop`, so restore could appear successful after a failed copy.
- P2: The chain-break detection query did not cover all error signatures used by the POS sync code.
- P2: Optional WAL/SHM sidecar copy failures in the backup script were hidden by `-ErrorAction SilentlyContinue`.

Resolution:

- Updated required restore copy steps to fail fast with `-ErrorAction Stop`.
- Copied optional WAL/SHM sidecars only after `Test-Path`, and made each copy fail fast when the file exists.
- Expanded the chain-break detection predicate to match `hash chain`, `hash mismatch`, and `chain break` sync-error text.
