# Encryption-at-Rest Audit — POS SQLite

**Date:** 2026-04-18
**Scope:** `apps/pos/` SQLite database used by IziPOS / Otospex Tauri desktop
**Status:** Audit complete, implementation scoped for P1

## 1. Current state

- SQLite file lives in Tauri's app-local data directory (per-OS: `~/Library/Application Support/<app>`, `%APPDATA%\<app>`, `~/.local/share/<app>`).
- Database is accessed via `@tauri-apps/plugin-sql`. No encryption extension is configured.
- `src-tauri/Cargo.toml` lists `aes-gcm` as a dependency, but a grep of `src-tauri/src/` confirms it is **not used for SQLite encryption** — it is unused or used for unrelated signing (to be confirmed by inspection).

## 2. Sensitive columns inventory

| Table | Columns | Sensitivity | Regulator concern |
|---|---|---|---|
| `operator_pins` | `pin_hash` | High | PCI-scope if considered credential; GDPR (identifier) |
| `payment_repositories` | `account_number`, `iban`, `bic` | High | PCI; GDPR; national banking secrecy laws |
| `offline_receipts` | `lines` (contains customer names, modifiers) | Medium | GDPR (transaction data linked to individuals if loyalty) |
| `offline_receipts` | `total`, `tax_amount`, etc. | Medium | Commercially sensitive if device stolen |
| `products` | `sale_price` | Low | Commercially sensitive aggregated |
| `z_reports` | `report_data`, `receipt_snapshots`, `grand_totals` | High | Fiscal data; NF525 audit trail |

## 3. Evaluated options

### 3.1 SQLCipher (recommended)

- AES-256-CBC block encryption with HMAC-SHA-512 for integrity.
- Transparent — replaces libsqlite3 at link time. Application code unchanged.
- Key derived from passphrase via PBKDF2 (default 256k iterations).
- Tauri plugin: the current `tauri-plugin-sql` does not ship with SQLCipher. Options:
  - Fork plugin-sql to statically link SQLCipher (~1–2 days engineering).
  - Use `tauri-plugin-sqlcipher` (community plugin — evaluate maintenance status).
  - Ship our own rust binding (more control, more work).
- Key management: store derived key in OS keychain (macOS Keychain / Windows Credential Locker / Linux Secret Service) via `keyring` crate. Passphrase = device identifier + user-supplied PIN on first-run.

### 3.2 Column-level encryption (app code)

- Encrypt only sensitive columns (PIN hashes, IBAN) using `aes-gcm` at write time.
- Pros: no build-system changes, selective.
- Cons: SQL joins/filters on encrypted columns don't work; increases complexity; `offline_receipts.lines` JSON blob is hard to selectively encrypt.

### 3.3 OS-level full-disk encryption

- Already present on modern macOS (FileVault), Windows (BitLocker), Linux (LUKS) when IT policy mandates.
- Sufficient when the adversary model is "stolen laptop at rest", but not for "laptop snatched while unlocked".

## 4. Recommendation

**P1 task — SQLCipher full-database encryption.** One build-system change, zero application code changes, covers every column. Key stored in OS keychain; first-run migration copies plaintext DB to encrypted DB and deletes original.

**Cost estimate:** 3–5 engineering days (fork plugin, wire keychain, migration script, test matrix).

**Interim mitigation (P0+):** document that the POS MUST be deployed on a machine with FDE enabled. Ship a README note to that effect.

## 5. NF525 considerations

NF525 does not mandate encryption-at-rest specifically — it mandates inalterability and tamper evidence, both provided by the hash chain. SQLCipher is defence-in-depth. Tax counsel confirmation recommended before production rollout in France.

## 6. Open questions for tax counsel

- Does Morocco / Côte d'Ivoire compliance require encryption-at-rest explicitly?
- GDPR: is SQLite file on a stolen POS laptop a reportable breach? (Probably yes.)

## 7. Next steps (P1)

- Spike SQLCipher integration in a feature branch.
- Decide fork vs. community plugin.
- Key management + rotation story.
- DB migration from plaintext to encrypted on first boot post-upgrade.
