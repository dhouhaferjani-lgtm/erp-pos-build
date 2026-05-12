# IziPOS POS Operations Runbooks

Deploy-phase-1 operator runbooks:

- `install.md` - Windows 11 first install, release provenance, NTP/timezone verification, post-install smoke test.
- `manual-update.md` - manual update, backup, post-update verification, rollback.
- `backup.md` - app data backup files, frequency, checksum capture.
- `restore.md` - restore drill with checksums and per-table row counts.
- `support.md` - remote support rules, placeholder decision record, log bundle command.
- `chain-break-recovery.md` - chain-break triage, backup-first capture, Synerivia-only hash mismatch recovery.
- `walkthrough-rehearsal.md` - acceptance evidence for teammate explain-back.

The app identifier is `com.syneriva.izipos`. Confirm all Windows paths against the deployed terminal before go-live.
