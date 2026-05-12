# POS Go-Live PR #4 Codex Review - Round 9

Command: `codex review --base dev`

Scope reviewed: POS operator runbooks and Tauri Windows storage path assumptions.

Result: CHANGES REQUESTED

Findings:

- P2: The backup and restore procedures assumed WebView2 data lived under `%LOCALAPPDATA%\com.syneriva.izipos\EBWebView`, but current Tauri 2 behavior supplies `%LOCALAPPDATA%\com.syneriva.izipos` as the WebView2 user-data directory when no explicit window `dataDirectory` is configured.

Resolution:

- Updated backup, restore, install, manual-update, and support runbooks to treat `%LOCALAPPDATA%\com.syneriva.izipos` as the WebView2 local app-data root.
- Changed backups to copy non-log, non-key local app-data contents into `webview-data`.
- Changed restore to clear and restore that `webview-data` subtree while preserving separately restored key and log handling.
