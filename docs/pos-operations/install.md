# IziPOS Windows 11 First Install Runbook

This runbook is for deploy-phase-1 installation on the customer Windows 11 terminal.

## Inputs To Fill Before Install

- Expected release version: `TBD`
- Release notes URL: `TBD`
- Installer file name: `IziPOS-<version>-setup.exe`
- Expected SHA-256: `TBD`
- Signing certificate thumbprint, if signed: `TBD`; if unsigned, record `UNSIGNED - approved by release owner`
- API environment: `production` or `staging`
- Company ID after first login: `TBD`

## Prerequisites

- Windows 11 with administrator access for installation.
- Internet access to the Synerivia API.
- Receipt printer, cash drawer, scanner, and network configured.
- Pairing token or approved terminal assignment ready.
- Downloaded installer from the approved Synerivia release location only.

## Verify The Release Artifact

Run PowerShell from the folder containing the installer:

```powershell
$Installer = ".\IziPOS-<version>-setup.exe"
Get-FileHash -Algorithm SHA256 $Installer
Get-AuthenticodeSignature $Installer | Format-List Status,SignerCertificate
```

Continue only when the SHA-256 equals the release value and the signing result matches the release record. If the installer is unsigned, stop unless the release owner already recorded an explicit unsigned approval.

## Install

1. Close any existing IziPOS windows.
2. Run the installer as administrator.
3. Launch IziPOS from the Start menu.
4. Log in with the assigned operator account.
5. Enter the pairing token or select the assigned terminal when prompted.
6. Wait for initial sync to finish. Confirm products, categories, payment methods, and terminal settings are visible.
7. Set up the operator PIN.

## Windows Time And Timezone

Fiscal hashes, receipt timestamps, shifts, Z-reports, vouchers, and audit records depend on local clock correctness.

Run PowerShell as administrator:

```powershell
w32tm /query /status
w32tm /query /source
Get-TimeZone
```

Required state:

- Windows Time service is running.
- NTP source is `time.windows.com` or the approved local NTP server.
- Timezone is `(UTC+01:00) West Central Africa` for Tunisia. Tunisia is GMT+1 with no DST.

If correction is needed:

```powershell
Set-Service W32Time -StartupType Automatic
Start-Service W32Time
w32tm /config /manualpeerlist:"time.windows.com" /syncfromflags:manual /update
w32tm /resync
Set-TimeZone -Id "W. Central Africa Standard Time"
w32tm /query /status
Get-TimeZone
```

Record the final NTP source, timezone, and current local time in the install log.

## Post-Install Verification

1. Open IziPOS settings and record the About version shown.
2. Verify the installed executable version from PowerShell:

   ```powershell
   $Exe = "$env:ProgramFiles\IziPOS\IziPOS.exe"
   if (!(Test-Path $Exe)) { $Exe = "$env:LOCALAPPDATA\Programs\IziPOS\IziPOS.exe" }
   (Get-Item $Exe).VersionInfo | Format-List ProductVersion,FileVersion
   ```

3. Confirm the running version/file version matches the release record. If the in-app About value and executable metadata disagree, record both and escalate before handover.
4. Smoke test:
   - Open a shift.
   - Ring one small cash sale.
   - Print the receipt.
   - Void or refund the test sale according to the live procedure.
   - Confirm the receipt appears in local history and server receipts.
   - Confirm no red fiscal-chain or sync banner remains.

## Data Locations To Confirm

The app identifier is `com.syneriva.izipos` in `apps/pos/src-tauri/tauri.conf.json`.

Expected Windows data roots:

- App data root: `%APPDATA%\com.syneriva.izipos`
- Local app data root for AES key from `crypto.rs`, default Tauri logs, and WebView2 browser localStorage: `%LOCALAPPDATA%\com.syneriva.izipos`

After first login, confirm these files exist where applicable:

- `%APPDATA%\com.syneriva.izipos\izipos-<companyId>.db`
- `%APPDATA%\com.syneriva.izipos\izipos-<companyId>.db-wal`
- `%APPDATA%\com.syneriva.izipos\izipos-<companyId>.db-shm`
- `%APPDATA%\com.syneriva.izipos\izipos-settings.json`
- `%LOCALAPPDATA%\com.syneriva.izipos\.izipos_key`
- `%APPDATA%\com.syneriva.izipos\images\`
- `%LOCALAPPDATA%\com.syneriva.izipos\logs\` for app logs
- `%APPDATA%\com.syneriva.izipos\logs\` or `*.log` files under the app data root if present
- `%LOCALAPPDATA%\com.syneriva.izipos\` WebView2 browser data for persisted printer, scanner, cash-drawer, customer-display, device ID, and UI settings

## References

- Rollout plan: `docs/superpowers/plans/2026-05-11-tunisia-customer-rollout.md`
- Backup runbook: `docs/pos-operations/backup.md`
- Support runbook: `docs/pos-operations/support.md`
