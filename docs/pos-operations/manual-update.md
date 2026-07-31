# IziPOS Manual Update Runbook

Deploy-phase-1 uses a manual installer or binary swap. There is no in-app updater.

## Pre-Update Release Record

> This is a per-update-event form, not a one-time value — it is re-filled for every future update and is
> expected to show an open marker again after each cycle. None of the values below are derivable from the
> repository (they depend on which release is being installed and by whom) and are NOT fabricated here.
> The FIRST fill of this record happens as part of gate **E-8** (target-device rollout, the initial
> install); every subsequent update repeats the record outside the launch-gate scope. See
> `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`.

Fill this before touching the terminal:

- Current installed version: `TBD`
- New release version: `TBD`
- Release notes URL: `TBD`
- Installer or executable path: `TBD`
- Expected SHA-256: `TBD`
- Signing certificate thumbprint, if signed: `TBD`
- N-1 rollback installer/executable path: `TBD`
- Operator performing update: `TBD`
- Synerivia approver: `TBD`

Verify the new artifact:

```powershell
$Artifact = "C:\Path\To\IziPOS-<version>-setup.exe"
Get-FileHash -Algorithm SHA256 $Artifact
Get-AuthenticodeSignature $Artifact | Format-List Status,SignerCertificate
```

Stop if checksum or signing status does not match the release record.

## Stop The Running POS

1. Finish the current sale.
2. Sync pending receipts if online.
3. Close IziPOS.
4. In Task Manager, confirm no `IziPOS.exe` process is running.

Do not update while a shift close or Z-report is in progress.

## Snapshot App Data

Use `backup.md` and create a pre-update backup. Required files:

- `%APPDATA%\com.syneriva.izipos\izipos-<companyId>.db`
- `%APPDATA%\com.syneriva.izipos\izipos-<companyId>.db-wal`
- `%APPDATA%\com.syneriva.izipos\izipos-<companyId>.db-shm`
- `%APPDATA%\com.syneriva.izipos\izipos-settings.json`
- `%LOCALAPPDATA%\com.syneriva.izipos\.izipos_key`
- `%APPDATA%\com.syneriva.izipos\images\**`
- `%LOCALAPPDATA%\com.syneriva.izipos\logs\**` for the default Tauri log directory
- `%APPDATA%\com.syneriva.izipos\logs\**` and any `*.log` files under either app data root if present
- `%LOCALAPPDATA%\com.syneriva.izipos\**` for WebView2 localStorage-backed printer, scanner, cash-drawer, customer-display, device ID, and UI settings

Record checksums for the backup before installing the update.

## Install Or Swap

Preferred path:

1. Run the new installer as administrator.
2. Let the installer preserve app data.
3. Do not delete `%APPDATA%\com.syneriva.izipos` or `%LOCALAPPDATA%\com.syneriva.izipos`.

Manual binary-swap fallback:

1. Copy the existing `IziPOS.exe` to the rollback folder.
2. Replace only the application binary with the approved new binary.
3. Do not modify the app data files listed above.

## Post-Update Verification

1. Launch IziPOS.
2. Verify version:

   ```powershell
   $Exe = "$env:ProgramFiles\IziPOS\IziPOS.exe"
   if (!(Test-Path $Exe)) { $Exe = "$env:LOCALAPPDATA\Programs\IziPOS\IziPOS.exe" }
   (Get-Item $Exe).VersionInfo | Format-List ProductVersion,FileVersion
   ```

3. Confirm Settings > About shows the expected release label or record any mismatch.
4. Confirm the previous terminal is still paired.
5. Confirm catalog and payment methods are visible.
6. Confirm the last Z-report is still visible in Z-report history.
7. Ring a small test sale and void/refund it.
8. Confirm no fiscal-chain, sync, or terminal-not-ready banner remains.

## Rollback

Rollback is allowed only if post-update verification fails before live trading resumes.

1. Close IziPOS and confirm `IziPOS.exe` is not running.
2. Restore the N-1 installer or executable from the rollback folder.
3. Restore the pre-update app data snapshot only if the updated app changed local data before the failure.
4. Launch IziPOS.
5. Run the post-update verification again against the previous version.
6. Notify Synerivia with the failed release version, rollback version, and support bundle.

Keep the N-1 build available locally for every deploy-phase-1 update.
