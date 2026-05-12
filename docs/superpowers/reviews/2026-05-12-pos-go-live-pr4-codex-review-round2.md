# POS Go-Live PR #4 Codex Review - Round 2

Command: `codex review --base dev`

Scope reviewed: POS operator runbooks under `docs/pos-operations/`.

Result: CHANGES REQUESTED

Findings:

- P2: `docs/pos-operations/backup.md` allowed the backup script to continue if `izipos-settings.json` was missing, even though the settings file is required for a usable recovery.
- P2: The backup and restore procedures omitted the Tauri WebView data directory, which contains browser storage used by Zustand/localStorage-backed POS settings.

Resolution:

- Changed the settings-file backup copy to use `-ErrorAction Stop`.
- Added `%LOCALAPPDATA%\com.syneriva.izipos\EBWebView\**` to the backup and restore coverage.
- Updated install, manual update, and support notes so operators understand WebView data is part of full recovery data but not part of routine support bundles.
