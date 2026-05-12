# IziPOS Backup Runbook

Backups protect the local SQLite fiscal state, unsynced receipts, terminal pairing, encrypted token store, AES key, image cache, and local logs.

## Frequency

- Before every manual update.
- Before any Synerivia support session.
- Before any chain-break escalation.
- Daily at close of business during deploy-phase-1.

## Required Files

Replace `<companyId>` with the company ID shown in the operator handover record.

- `%APPDATA%\com.syneriva.izipos\izipos-<companyId>.db`
- `%APPDATA%\com.syneriva.izipos\izipos-<companyId>.db-wal`
- `%APPDATA%\com.syneriva.izipos\izipos-<companyId>.db-shm`
- `%APPDATA%\com.syneriva.izipos\izipos-settings.json`
- `%LOCALAPPDATA%\com.syneriva.izipos\.izipos_key`
- `%APPDATA%\com.syneriva.izipos\images\**`
- `%APPDATA%\com.syneriva.izipos\logs\**` if present
- `%LOCALAPPDATA%\com.syneriva.izipos\logs\**` for the default Tauri log directory
- Any `*.log` files under `%APPDATA%\com.syneriva.izipos` or `%LOCALAPPDATA%\com.syneriva.izipos`
- `%LOCALAPPDATA%\com.syneriva.izipos\**` for Tauri WebView2 user data and browser localStorage

The local app-data root contains the Tauri WebView2 user-data directory for this app. It includes browser localStorage, including persisted Zustand stores:

- `izipos-printer`
- `izipos-scanner`
- `izipos-cash-drawer`
- `izipos-customer-display`
- `izipos-settings`
- `izipos-device-id`

Do not omit `.db-wal` or `.db-shm`. WAL mode is enabled by the POS and those files can contain committed data that has not been checkpointed into the main `.db`.

## Backup Procedure

1. Close IziPOS.
2. Confirm no process is running:

   ```powershell
   Get-Process IziPOS -ErrorAction SilentlyContinue
   ```

   Continue only when this returns no process.

3. Run:

   ```powershell
   $CompanyId = "REPLACE_WITH_COMPANY_ID"
   $Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
   $ApprovedBackupBase = "C:\IziPOS-Backups" # replace with the approved destination before go-live
   $BackupRoot = Join-Path $ApprovedBackupBase $Stamp
   $RoamingRoot = Join-Path $env:APPDATA "com.syneriva.izipos"
   $LocalRoot = Join-Path $env:LOCALAPPDATA "com.syneriva.izipos"

   New-Item -ItemType Directory -Force $BackupRoot | Out-Null

   Copy-Item (Join-Path $RoamingRoot "izipos-$CompanyId.db") $BackupRoot -ErrorAction Stop
   $Wal = Join-Path $RoamingRoot "izipos-$CompanyId.db-wal"
   if (Test-Path $Wal) { Copy-Item $Wal $BackupRoot -ErrorAction Stop }
   $Shm = Join-Path $RoamingRoot "izipos-$CompanyId.db-shm"
   if (Test-Path $Shm) { Copy-Item $Shm $BackupRoot -ErrorAction Stop }
   Copy-Item (Join-Path $RoamingRoot "izipos-settings.json") $BackupRoot -ErrorAction Stop
   Copy-Item (Join-Path $LocalRoot ".izipos_key") $BackupRoot -ErrorAction Stop
   $WebViewBackup = Join-Path $BackupRoot "webview-data"
   New-Item -ItemType Directory -Force $WebViewBackup | Out-Null
   Get-ChildItem $LocalRoot -Force |
     Where-Object { $_.Name -ne ".izipos_key" -and $_.Name -ne "logs" -and $_.Name -notlike "*.log" } |
     Copy-Item -Destination $WebViewBackup -Recurse -Force -ErrorAction Stop
   Copy-Item (Join-Path $RoamingRoot "images") $BackupRoot -Recurse -ErrorAction SilentlyContinue
   Copy-Item (Join-Path $RoamingRoot "logs") (Join-Path $BackupRoot "roaming-logs") -Recurse -ErrorAction SilentlyContinue
   Copy-Item (Join-Path $LocalRoot "logs") (Join-Path $BackupRoot "local-logs") -Recurse -ErrorAction SilentlyContinue
   New-Item -ItemType Directory -Force (Join-Path $BackupRoot "roaming-root-logs") | Out-Null
   New-Item -ItemType Directory -Force (Join-Path $BackupRoot "local-root-logs") | Out-Null
   Copy-Item (Join-Path $RoamingRoot "*.log") (Join-Path $BackupRoot "roaming-root-logs") -ErrorAction SilentlyContinue
   Copy-Item (Join-Path $LocalRoot "*.log") (Join-Path $BackupRoot "local-root-logs") -ErrorAction SilentlyContinue

   Get-ChildItem $BackupRoot -Recurse -File |
     Get-FileHash -Algorithm SHA256 |
     Sort-Object Path |
     Export-Csv (Join-Path $BackupRoot "sha256.csv") -NoTypeInformation

   Compress-Archive -Path (Join-Path $BackupRoot "*") -DestinationPath "$BackupRoot.zip" -Force
   ```

4. Copy the `.zip` to the approved backup destination.
5. Record backup path, operator name, timestamp, company ID, and reason.

## Storage Destination

Use the customer-approved destination, filled before go-live:

- Primary destination: `TBD`
- Secondary destination: `TBD`
- Retention: keep at least the latest 7 daily backups and all pre-update/pre-support backups until Synerivia signs off.

Backups contain fiscal and authentication material. Do not email a full backup unless Synerivia explicitly requests it through the support process.
