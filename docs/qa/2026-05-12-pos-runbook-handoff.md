# POS Runbook Handoff — 2026-05-12

> **Plan reference:** `docs/superpowers/plans/2026-05-12-dev-go-live-remediation-plan.md` §M1.7
> **Status:** Runbook structure complete; operational values pending release-build artifacts and the live-terminal rehearsal.

This document tracks what M1.7 closed automatically vs what is queued for human handoff before the first-tenant gate. The remediation pass cannot generate real release-version strings, SHA-256 checksums, or rehearsal sign-offs; those land when the release-engineer produces the installer artifact and the operator+observer run through the rehearsal on the deployment terminal.

## Runbook structure

All eight POS operator runbooks under `docs/pos-operations/` are present and consistently formatted:

- `README.md`
- `install.md`
- `manual-update.md`
- `backup.md`
- `restore.md`
- `support.md`
- `chain-break-recovery.md`
- `walkthrough-rehearsal.md`

`<companyId>` / `<version>` path templates inside these files are deliberate substitution markers — they remain `<...>` so the runbook reads correctly across every tenant.

## Operational TBDs queued for release engineering

These rows in the runbooks MUST be filled before first-tenant launch. They are NOT placeholder template tokens — they require real values that don't exist yet:

| File | Marker | Source |
| --- | --- | --- |
| `install.md` line 7  | Expected release version | Release engineering (release tag) |
| `install.md` line 8  | Release notes URL | Release engineering |
| `install.md` line 10 | Expected installer SHA-256 | Release engineering (build pipeline) |
| `install.md` line 11 | Signing certificate thumbprint | Release engineering or `UNSIGNED - approved by release owner` |
| `install.md` line 13 | Company ID after first login | Tenant onboarding |
| `manual-update.md` line 9-10 | Current vs new version | Release engineering (when an update lands post-launch) |
| `backup.md` line 96-97 | Primary + secondary backup destination | Customer-success / Synerivia ops |
| `walkthrough-rehearsal.md` line 7-22 | Rehearsal facilitator/reader/terminal/version + per-runbook outcome/evidence | Filled DURING the live-terminal rehearsal |

## Tunisia legal-pack sign-off

If the first tenant operates under Tunisian fiscal rules (Otospex automotive vertical), the rehearsal log must include:

- Accountant / legal reviewer name
- Confirmed VAT rates per product class
- Receipt legal-field list (matricule fiscal, NIF, RNE, etc.) confirmed visible on a test receipt
- Register-certification scope (NF525 / Tunisia equivalent)
- Date of sign-off

If sign-off is not available before first tenant, the rehearsal facilitator records explicit risk acceptance with the responsible owner.

For an IziPOS retail pilot (generic vertical), no fiscal legal-pack sign-off is required for the launch itself; however, the receipt template must still match local-operator expectations before the rehearsal closes.

## Live-terminal smoke

The smoke protocol at `docs/qa/2026-05-12-first-tenant-smoke.md` is the source of truth for the live-terminal verification steps. It cannot be executed here because:

- It depends on a real Tauri build of the release artifact.
- It requires the actual terminal hardware and receipt printer.
- Several steps assume an authenticated tenant + paired payment terminal.

The rehearsal facilitator runs the smoke from the runbook on the deployment terminal and records evidence (screenshots, log paths, photo of printed receipt). The rehearsal log row in `walkthrough-rehearsal.md` lines 17-22 captures pass/fail per runbook.

## First-tenant gate satisfaction

The first-tenant gate in the remediation plan requires:

- "POS runbook operational values, Tunisia legal sign-off, and live-terminal smoke evidence are complete."

The remediation branch has:

- ✅ All eight runbook files exist with consistent structure.
- ✅ Operational TBDs catalogued above with owner per row.
- ⬜ Real release-version, SHA-256, signing thumbprint, backup destination, company-ID values to be entered by release engineering.
- ⬜ Tunisia legal-pack sign-off (only blocking for automotive vertical).
- ⬜ Live-terminal rehearsal completed and evidence attached.

The remaining items move with the release artifact — they don't gate this remediation branch from being merged into `dev`, but they DO gate first-tenant launch.

## Owner

- Release engineering: TBD
- Customer-success / Synerivia ops: TBD
- Tunisia legal reviewer (Otospex pilot only): TBD
