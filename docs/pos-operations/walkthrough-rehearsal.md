# IziPOS Operator Runbook Walkthrough Rehearsal

Acceptance requires another teammate to read each runbook and explain back the procedure step by step.

> This file IS the evidence sink for gate **E-6** (walkthrough rehearsal) — see
> `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`. None of the still-open values below are
> derivable from the repository (they are the outcome of an actual rehearsal event that has not happened
> yet) and are NOT fabricated here.

## Rehearsal Record

- Date/time: `TBD`
- Reader: `TBD`
- Facilitator: `TBD`
- Terminal/location: `TBD`
- Release version reviewed: `TBD`

## Documents Reviewed

| Document | Reader can explain steps? | Gaps found | Follow-up |
| --- | --- | --- | --- |
| `install.md` | `TBD` | `TBD` | `TBD` |
| `manual-update.md` | `TBD` | `TBD` | `TBD` |
| `backup.md` | `TBD` | `TBD` | `TBD` |
| `restore.md` | `TBD` | `TBD` | `TBD` |
| `support.md` | `TBD` | `TBD` | `TBD` |
| `chain-break-recovery.md` | `TBD` | `TBD` | `TBD` |

## Required Explain-Back Prompts

Ask the reader to explain:

1. How to verify the installer version, checksum, and signing status before install.
2. How to verify Windows Time service, NTP source, and Tunisia timezone.
3. Which files must be backed up, including WAL/SHM and `.izipos_key`.
4. Why restore row counts are captured with a PowerShell loop and separate `COUNT(*)` calls.
5. What the remote support placeholder decision still needs before go-live.
6. What an operator is allowed to do during a chain-break and what is Synerivia-only.

## Rehearsal Outcome

```text
Summary:

Approved for go-live docs? YES/NO

Required edits:

Owner:
Due:
```
