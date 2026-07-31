# IziPOS Restore Drill Runbook

The restore drill is the acceptance evidence for the backup process. Execute it on the deployed terminal once it is in Tunisia and before go-live.

## Inputs

> The restore drill is a real-device event that has not run yet — none of these values are derivable from
> the repository and they are NOT fabricated here. They close via gate **E-2** (P0 real-device smoke,
> section G "Backup + Restore Drill" of the repaired `docs/qa/2026-05-12-first-tenant-smoke.md`) — see
> `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`.

- Company ID: `TBD`
- Backup zip path: `TBD`
- Restore drill operator: `TBD`
- Synerivia observer: `TBD`
- Drill date/time: `TBD`

## Pre-State Capture

Close IziPOS before capture.

```powershell
$CompanyId = "REPLACE_WITH_COMPANY_ID"
$Root = Join-Path $env:APPDATA "com.syneriva.izipos"
$Out = "D:\IziPOS-Restore-Drill\pre"
New-Item -ItemType Directory -Force $Out | Out-Null

Get-ChildItem $Root -File -Filter "izipos-$CompanyId.db*" |
  Get-FileHash -Algorithm SHA256 |
  Sort-Object Path |
  Export-Csv (Join-Path $Out "file-sha256.csv") -NoTypeInformation
```

Capture SQLite row counts per table. This is intentionally a PowerShell loop that enumerates tables and runs a separate `COUNT(*)` for each table. Do not replace it with one dynamic SQL query.

```powershell
$CompanyId = "REPLACE_WITH_COMPANY_ID"
$Root = Join-Path $env:APPDATA "com.syneriva.izipos"
$Db = Join-Path $Root "izipos-$CompanyId.db"
$Out = "D:\IziPOS-Restore-Drill\pre"
$Sqlite = "sqlite3"

$Tables = & $Sqlite $Db "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name;"
$Rows = foreach ($Table in $Tables) {
  $Quoted = '"' + ($Table -replace '"','""') + '"'
  $Count = & $Sqlite $Db "SELECT COUNT(*) FROM $Quoted;"
  [pscustomobject]@{ table = $Table; row_count = [int64]$Count }
}
$Rows | Export-Csv (Join-Path $Out "row-counts.csv") -NoTypeInformation
```

If `sqlite3` is not installed, stop and install the Synerivia-approved SQLite CLI bundle before continuing.

## Restore Procedure

1. Close IziPOS and confirm no process is running.
2. Create one more backup using `backup.md`.
3. Extract the selected backup zip to a temporary folder.
4. Copy restored files into place:

   ```powershell
   $CompanyId = "REPLACE_WITH_COMPANY_ID"
   $Restore = "D:\Path\To\Extracted\Backup"
   $RoamingRoot = Join-Path $env:APPDATA "com.syneriva.izipos"
   $LocalRoot = Join-Path $env:LOCALAPPDATA "com.syneriva.izipos"

   New-Item -ItemType Directory -Force $RoamingRoot | Out-Null
   New-Item -ItemType Directory -Force $LocalRoot | Out-Null

   # Remove the target SQLite set first. A checkpointed backup may not include
   # WAL/SHM files; leaving old sidecars beside the restored main DB is unsafe.
   $SqliteTargets = @(
     (Join-Path $RoamingRoot "izipos-$CompanyId.db"),
     (Join-Path $RoamingRoot "izipos-$CompanyId.db-wal"),
     (Join-Path $RoamingRoot "izipos-$CompanyId.db-shm")
   )
   foreach ($Target in $SqliteTargets) {
     if (Test-Path $Target) { Remove-Item $Target -Force -ErrorAction Stop }
   }
   Get-ChildItem $LocalRoot -Force -ErrorAction Stop |
     Where-Object { $_.Name -ne ".izipos_key" -and $_.Name -ne "logs" -and $_.Name -notlike "*.log" } |
     Remove-Item -Recurse -Force -ErrorAction Stop

   Copy-Item (Join-Path $Restore "izipos-$CompanyId.db") $RoamingRoot -Force -ErrorAction Stop
   $Wal = Join-Path $Restore "izipos-$CompanyId.db-wal"
   if (Test-Path $Wal) { Copy-Item $Wal $RoamingRoot -Force -ErrorAction Stop }
   $Shm = Join-Path $Restore "izipos-$CompanyId.db-shm"
   if (Test-Path $Shm) { Copy-Item $Shm $RoamingRoot -Force -ErrorAction Stop }
   Copy-Item (Join-Path $Restore "izipos-settings.json") $RoamingRoot -Force -ErrorAction Stop
   Copy-Item (Join-Path $Restore ".izipos_key") $LocalRoot -Force -ErrorAction Stop
   Copy-Item (Join-Path $Restore "webview-data\*") $LocalRoot -Recurse -Force -ErrorAction Stop
   Copy-Item (Join-Path $Restore "images") $RoamingRoot -Recurse -Force -ErrorAction SilentlyContinue

   $RoamingLogs = Join-Path $Restore "roaming-logs"
   $RoamingLogDest = Join-Path $RoamingRoot "logs"
   if (Test-Path $RoamingLogs) {
     New-Item -ItemType Directory -Force $RoamingLogDest | Out-Null
     Copy-Item (Join-Path $RoamingLogs "*") $RoamingLogDest -Recurse -Force -ErrorAction SilentlyContinue
   }
   $LocalLogs = Join-Path $Restore "local-logs"
   $LocalLogDest = Join-Path $LocalRoot "logs"
   if (Test-Path $LocalLogs) {
     New-Item -ItemType Directory -Force $LocalLogDest | Out-Null
     Copy-Item (Join-Path $LocalLogs "*") $LocalLogDest -Recurse -Force -ErrorAction SilentlyContinue
   }
   Copy-Item (Join-Path $Restore "roaming-root-logs\*.log") $RoamingRoot -Force -ErrorAction SilentlyContinue
   Copy-Item (Join-Path $Restore "local-root-logs\*.log") $LocalRoot -Force -ErrorAction SilentlyContinue
   ```

5. Launch IziPOS.
6. Confirm terminal pairing, catalog, last receipt, and last Z-report are visible.
7. Let one sync cycle complete if online.
8. Close IziPOS again before post-state capture.

## Post-State Capture

Repeat the checksum and row-count capture with output folder `D:\IziPOS-Restore-Drill\post`.

```powershell
$CompanyId = "REPLACE_WITH_COMPANY_ID"
$Root = Join-Path $env:APPDATA "com.syneriva.izipos"
$Out = "D:\IziPOS-Restore-Drill\post"
New-Item -ItemType Directory -Force $Out | Out-Null

Get-ChildItem $Root -File -Filter "izipos-$CompanyId.db*" |
  Get-FileHash -Algorithm SHA256 |
  Sort-Object Path |
  Export-Csv (Join-Path $Out "file-sha256.csv") -NoTypeInformation

$Db = Join-Path $Root "izipos-$CompanyId.db"
$Sqlite = "sqlite3"
$Tables = & $Sqlite $Db "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name;"
$Rows = foreach ($Table in $Tables) {
  $Quoted = '"' + ($Table -replace '"','""') + '"'
  $Count = & $Sqlite $Db "SELECT COUNT(*) FROM $Quoted;"
  [pscustomobject]@{ table = $Table; row_count = [int64]$Count }
}
$Rows | Export-Csv (Join-Path $Out "row-counts.csv") -NoTypeInformation
```

## Diff And Acceptance Evidence

Compare:

```powershell
Compare-Object (Import-Csv "D:\IziPOS-Restore-Drill\pre\row-counts.csv") (Import-Csv "D:\IziPOS-Restore-Drill\post\row-counts.csv") -Property table,row_count
Compare-Object (Import-Csv "D:\IziPOS-Restore-Drill\pre\file-sha256.csv") (Import-Csv "D:\IziPOS-Restore-Drill\post\file-sha256.csv") -Property Path,Hash
```

Expected result:

- Row counts are identical unless the post-restore sync legitimately adds server-side rows.
- Data diffs are limited to `synced_at` or `updated_at` fields touched by post-restore sync.
- Fiscal chain state, receipt count, last receipt number, last Z-report, and terminal pairing are preserved.

Record the completed evidence here after executing on the deployed terminal:

```text
Restore drill evidence:
- Date/time:
- Operator:
- Synerivia observer:
- Backup used:
- Pre row-count file:
- Post row-count file:
- Pre checksum file:
- Post checksum file:
- Differences:
- Accepted by:
```
