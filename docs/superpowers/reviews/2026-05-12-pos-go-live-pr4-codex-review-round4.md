# POS Go-Live PR #4 Codex Review - Round 4

Command: `codex review --base dev`

Scope reviewed: POS operator runbooks under `docs/pos-operations/`.

Result: CHANGES REQUESTED

Findings:

- P2: The support bundle missed the default Tauri log location on Windows, `%LOCALAPPDATA%\com.syneriva.izipos\logs`.

Resolution:

- Updated the support bundle script to collect logs from both Roaming AppData and LocalAppData.
- Updated install, manual update, and backup documentation to mention both log roots.
- Backups now preserve Roaming logs under `roaming-logs` and LocalAppData logs under `local-logs`.
