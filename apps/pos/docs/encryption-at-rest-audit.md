# Encryption-at-Rest Audit — POS SQLite

**Date:** 2026-04-18
**Scope:** `apps/pos/` SQLite database used by IziPOS / Otospex Tauri desktop
**Status:** Audit complete. **Decision recorded 2026-04-19: ship P0 with FDE mandate, defer SQLCipher to P1 pending tax-counsel input for TN/MA/CI.** See §0 Decision Log.

---

## 0. Decision log

### 2026-04-19 — Launch decision: FDE-only, SQLCipher deferred

**Decision:** P0 launch proceeds without SQLCipher. Interim mitigation is full-disk encryption on every deployed POS terminal. SQLCipher remains a P1 deliverable gated on tax-counsel confirmation for Tunisia, Morocco, and Côte d'Ivoire.

**Why this is defensible today:**
- **France (NF525):** Inalterability requirement is satisfied by the hash chain (§5 below). No explicit at-rest encryption mandate in DGFiP rules.
- **UK / EU broadly:** No fiscal mandate for at-rest encryption. GDPR Article 32 requires "appropriate" technical measures — FDE on the host satisfies the stolen-laptop-at-rest threat model for the overwhelming majority of real-world breaches.
- **PCI DSS:** The POS stores `card_last_four` + auth reference only (not full PAN / stripe / CVV). If the attached payment terminal handles the actual card read, the POS is out of PCI scope. Confirm with a QSA for each deployment.
- **Hash chain ≠ encryption.** The chain protects integrity (the fiscal concern). Encryption protects confidentiality (the data-protection concern). FDE covers the confidentiality threat for a powered-off stolen device. SQLCipher adds defence-in-depth for a powered-on but logged-in device.

**Gates before launching in other jurisdictions:**
- **Tunisia, Morocco, Côte d'Ivoire:** Do not go live until local tax counsel confirms in writing that FDE + hash chain is acceptable. Specific question: *"Does [country] fiscal certification or data-protection law require encryption-at-rest on the POS terminal database, or is full-disk encryption + a tamper-evident hash chain sufficient?"*
- If any jurisdiction comes back with "app-level encryption required", SQLCipher becomes blocking for that country and we prioritize the P1 work.

**Non-negotiables that fall out of this decision** (see §8 Operator Onboarding FDE Checklist):
- Every deployed terminal has FDE on before it enters production.
- The deployment team enforces this via a checklist + verification step — no exceptions.
- A privacy incident response plan names the DPO (or equivalent) responsible for GDPR breach notification within 72h.

---

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

**Interim mitigation (P0, in effect as of 2026-04-19):** every deployed POS terminal must have FDE enabled before it enters production. See §8 for the onboarding checklist.

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

---

## 8. Operator onboarding FDE checklist

**Owner:** Deployment team / IT onboarding lead.
**Gate:** No terminal enters production until every box below is checked for that device.

Record the result per-terminal in the operator-onboarding tracker (device serial + date + person who verified).

### 8.1 macOS terminal

- [ ] System Settings → Privacy & Security → **FileVault is On**.
- [ ] Recovery key is stored in the company's key-escrow solution (not just written on paper in the store).
- [ ] Auto-login is **disabled**. A logged-out Mac with FileVault on is encrypted at rest; a logged-in Mac that never prompts for a password is not.
- [ ] Screen lock after inactivity ≤ 5 minutes (System Settings → Lock Screen).
- [ ] Firmware password set (if the hardware supports it — most Apple Silicon devices don't expose this, but older Intel Macs do).

### 8.2 Windows terminal

- [ ] Settings → Privacy & Security → Device encryption **On**, OR BitLocker enabled on the system drive (Pro / Enterprise editions).
- [ ] Recovery key escrowed in Azure AD / company key management (not stored locally, not emailed).
- [ ] Auto-login is **disabled**.
- [ ] Screen lock after inactivity ≤ 5 minutes.
- [ ] TPM 2.0 is enabled in BIOS/UEFI (BitLocker leans on this for pre-boot authentication).

### 8.3 Linux terminal

- [ ] LUKS is enabled on the root volume. Confirm with `lsblk -o NAME,TYPE,MOUNTPOINT,FSTYPE` — the root device should show as `crypto_LUKS`.
- [ ] Boot partition (`/boot`) strategy is acknowledged: it's typically unencrypted, which is OK if the threat model is "device at rest" and Secure Boot is active. Document per-terminal.
- [ ] Auto-login disabled; screen lock ≤ 5 minutes.

### 8.4 Universal checks

- [ ] POS app version + terminal activation status logged in the onboarding tracker.
- [ ] Shift-closed test run performed on-site before handoff to staff.
- [ ] Staff signed off on the incident response contact card (who to call if the device is lost, stolen, or misbehaves).

### 8.5 If any box cannot be checked

**Do not deploy the terminal.** Escalate to the deployment lead. A terminal deployed without FDE is, under the current decision, **a compliance violation** and must be pulled from service the moment it's detected.

---

## 9. Review cadence

- **Quarterly:** deployment lead audits a random sample of 10% of deployed terminals to confirm FDE is still on (users can disable it; policy must verify).
- **On every new country onboarding:** revisit §0 Decision Log and update the jurisdiction table in §6 with the tax-counsel response.
- **On SQLCipher landing (whenever P1 ships):** mark this audit superseded and replace §4 Recommendation with a link to the SQLCipher implementation doc.
