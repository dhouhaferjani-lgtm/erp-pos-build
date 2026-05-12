# POS Production-Readiness Audit — Plan v3

> **Supersedes:** `2026-05-12-pos-production-readiness-audit-plan-v2.md` (v2) and `2026-05-11-pos-production-readiness-audit-plan.md` (v1).
>
> v1 → v2 closed 5 BLOCKERs and most P1s from Codex round 1. v2 → v3 closes ~10 P1 precision findings from Codex round 2 (memory-note inline quotes, dated legal sources, Phase 0 target-device profile, harness commit boundary, crash-injection mechanism, per-phase artefact contracts, target-device supportability, NTP clock sync). v1 + v2 stay in tree as historical artefacts.
>
> **For Codex (implementer) + Opus (reviewer):**
> Codex executes this audit end-to-end. Opus reviews the resulting findings doc + every implementation PR that follows. The audit is **read-mostly forensic + scripted-measurement** — for sections that can't be answered by static analysis, this plan defines a concrete measurement harness with explicit commit boundaries. **No code-level fixes in any phase.** Fixes ship in follow-up PRs after the user prioritizes findings.

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`
**Base branch:** `dev` (currently at `7fdb826b` post-merge of PRs #120 / #121 / #122 + v1 + v2 plans + r1 + r2 reviews)
**Audit branch:** `audit/pos-production-readiness` off `dev`
**Output doc:** `docs/superpowers/audits/2026-05-12-pos-production-readiness-findings.md`
**Memory-note root:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/`

### Glossary (used throughout this doc)

| Identifier | Meaning |
|---|---|
| **PR #120** | `fix(pos): SQLite WAL + stuck-receipt recovery + sync_error preservation` — merged 2026-05-11. Fixed Bug 5 + cascade for Bugs 3, 4. |
| **PR #121** | `fix(pos): cash payment amount = tendered + void over-refund fix` — merged 2026-05-11. Fixed Bug 2 + universal cash-refund formula. |
| **PR #122** | `feat(pos): real-time catalog refresh via WebSocket` — merged 2026-05-11. Fixed Bug 1 (60s catalog polling latency). |
| **PR C** (deferred) | In-app chain-break recovery UX — NOT built. Operational runbook IS in scope for this audit. |
| **deploy-phase-1** | This deployment: one TN client, one terminal, single tenant, manual update procedure. |
| **deploy-phase-2-updater** | Future Synerivia-hosted in-app updater (out of scope for this audit's implementation; one-page spec IS a deliverable). The previous draft of this plan called it "v2 updater"; renamed here to avoid confusion with audit-plan v2. |

---

## §0. Phase 0 — Target-device profile (gate to everything else)

**Why first:** Codex round 2 correctly flagged that every later section depends on knowing the physical deployment. Telemetry SDKs differ by OS. Backup paths differ. Print transports differ. Without this, the audit produces hand-wavy answers.

### Required inputs (captured BEFORE Phase 1 begins)

| Attribute | Captured value | Source |
|---|---|---|
| Target OS + arch | TBD (Windows / Linux / macOS) | Client confirms |
| Screen resolution + DPI | TBD | Hardware spec |
| Printer model + transport | TBD (USB / network / Bluetooth) | Hardware spec |
| Cash drawer wiring | TBD (printer-driven via ESC/POS pulse vs USB) | Hardware spec |
| Barcode scanner type | TBD (USB HID keyboard wedge / serial) | Hardware spec |
| Network topology | TBD (always-on / intermittent / offline-only) | Site survey |
| Operator account model | TBD (single OS account / per-cashier) | Site survey |
| Remote support access | TBD (TeamViewer / AnyDesk / SSH / none) | Site policy |
| Local timezone + locale | Africa/Tunis, ar-TN or fr-TN | Site config |
| NTP / clock source | TBD (OS-managed / manual / offline) | Site config |

**Deliverable:** filled table committed to the audit branch as `audit/phase-0-target-device-profile.md` before Phase 1 starts.

---

## Production-ready definition

The terminal is going live in Tunisia — single client, single terminal, single tenant — deploy-phase-1.

### Functional
- Cashier rings 100 receipts/hour for 8 hours with zero data loss, zero phantom errors, zero "weird behavior."
- Every receipt prints correctly (total / change / TVA breakdown / QR / legal mentions / fiscal seal).
- Fiscal hash chain stays valid client + server.
- Sync drains a 100-receipt offline backlog within 60 s when network returns.
- Cash drawer variance is correct end-of-shift (post-PR #121 contract).

### Compliance
- Tunisia VAT / receipt-format rules satisfied (dated legal sources per §5).
- NF525 status: explicit decision — internal hardening only OR contractual claim. Documented either way.
- Audit log integrity, no PII leakage to logs.

### Tauri device
- Native permissions are minimum-necessary (current state is over-permissive — see §2).
- Production build is signed where the target OS requires it.
- Backup / restore procedure for SQLite `.db` + `.db-wal` + `.db-shm` + Tauri Store `izipos-settings.json` + AES encryption key file + appdata images. Verified with a restore drill, not just prose.
- **Manual update procedure** for deploy-phase-1: documented operator runbook + Synerivia-side handoff + rollback procedure.
- **deploy-phase-2-updater follow-up spec** as a separate deliverable (not built in this audit).

### Operations
- **Telemetry / crash reporting pipeline decided** (§1 options-research). Even if implementation is a follow-up PR, the audit picks the vendor + project structure + redaction list.
- Sensitive data never written to console or logs (token, PIN hashes, customer PII, voucher serials, receipt QR tokens).
- Operator-facing chain-break recovery runbook (manual SQL + reinstall fallback) is written.

### Target-device supportability (Codex r2 P1)
- **Remote support access policy:** who can connect to the terminal, how they authenticate, whether unattended access is allowed, what tooling is approved.
- **Log retrieval procedure:** how Synerivia retrieves device logs when the client reports an issue. Both online (via telemetry SaaS) and offline (operator emails a zipped log bundle).
- **Offline support workflow:** what the cashier does when the internet is down AND something is wrong. Documented script.
- **Field-replaceable parts:** what can be swapped without a Synerivia visit (printer / scanner / drawer / terminal itself).

### Time integrity (Codex r2 P2)
- **NTP / OS clock setup verified** — fiscal chains, receipt numbers, Z-reports, shift close, vouchers, audit logs depend on trustworthy timestamps.
- **Server-vs-terminal clock drift handling** — what happens during sync if the client's clock is N hours ahead/behind?
- **Offline clock drift** — terminal clock wrong, no network — what does the cashier see, what does sync do on reconnect?

### Performance budget
- P95 cashier-facing operations under 200 ms.
- Catalog cold start under 3 s for 5K SKUs.
- Sync 100-receipt backlog drain under 60 s.

---

## Audit structure: 16 sections in 5 blocks

(Section count unchanged from v2; content tightened per Codex r2.)

### Block A — Foundation (audit these first; later sections depend on them)
- **§1. Observability & telemetry** (options-research + current-state audit)
- **§2. Tauri device security & native permissions**
- **§3. Build, deploy, install, manual-update procedure for deploy-phase-1**

### Block B — Compliance
- **§4. Fiscal hash chain (client + server v3 parity)**
- **§5. Tunisia compliance matrix (dated legal sources)**

### Block C — Functional surfaces
- **§6. Receipt rendering & printing**
- **§7. Cash drawer & shift management**
- **§8. Payment flows**
- **§9. Sync reliability**
- **§10. Authentication, permissions, device threat model**
- **§11. UI/UX & ergonomics**
- **§12. Multi-vertical**

### Block D — Quality
- **§13. Performance budget** (measurement harness with commit boundary)
- **§14. Resilience & crash safety** (scripted scenarios with explicit injection mechanism)
- **§15. Multi-tenant / company isolation** (POS-specific)

### Block E — Integration
- **§16. Cross-section flow audits** (with shared fixture contract)

---

## §1. Observability & telemetry

### Current state to verify

- `apps/pos/src-tauri/src/lib.rs` — does it initialize `tauri_plugin_log`? Sink configured?
- `apps/pos/src/lib/errorLogging.ts` (`serializeErrorForLog`) — redacts what?
- `apps/pos/src/lib/sync/coerceSyncError.ts` (PR #120) — applied in `syncService.ts`; verify all OTHER catch blocks in `apps/pos/src` use a similar non-`'Unknown error'` fallback.
- `apps/api/bootstrap/app.php` — Sentry integration via `Sentry\Laravel\Integration` if any.
- `apps/api/config/sentry.php` — verify whether this file exists, what's configured.
- `apps/api/config/logging.php` — channels, retention.

### Options-research — verify each cell at execution time (do NOT trust the table below as authoritative; SaaS pricing/regions/features change)

| Option | Stack coverage | Notes (verify at execution) | Effort |
|---|---|---|---|
| **Sentry SaaS, Browser SDK + Rust SDK separately** (the conservative baseline) | `@sentry/react` (frontend) + `sentry-rust` (Tauri Rust side, manual integration) + `sentry/sentry-laravel` (API) | Sentry's own docs say there is no official Tauri SDK; recommended approach is to wire Browser + Rust separately. Free tier quotas, EU region availability, PII scrubbing config — VERIFY current pricing + region + retention at audit time. | Medium (3 SDK installs; release tagging; redaction rules) |
| **`tauri-plugin-sentry`** (community) | Auto-bridges Rust panics to JS-side Sentry | **EXPERIMENTAL.** `tauri-plugin-sentry 0.5.0` on docs.rs is labeled experimental with 0% documented public items. KEEP in matrix but DO NOT pre-recommend. If considered, audit the plugin's source + maintenance velocity + security history. | Low (single plugin) but high risk |
| **GlitchTip** (self-hosted) | Sentry-compatible API; can reuse Sentry SDKs | Drop-in compatible. Self-host on Synerivia infra or TN-resident host (data-residency wins). | High (self-host + maintain) |
| **Highlight.io** | Full-stack + session replay | Heavier on frontend. Pricier. | Medium |
| **PostHog** | Product analytics + session replay (less crash-focused) | EU region available. Self-hostable. | Medium |
| **Homegrown** (tauri-plugin-log + cron upload) | Disk → manual upload | Cheapest dollar cost; highest engineering cost. | High |

**Recommendation framing:** the audit should NOT pre-decide. Output a decision matrix with verified-at-audit-time values + recommendation. Default to "Browser SDK + Rust SDK separately" as the conservative path until the community plugin matures.

### Account/project structure (whichever vendor wins)

- **Single Synerivia organization.** Multiple Sentry/GlitchTip orgs is the wrong separation; environments + projects within one org do the right thing.
- **One project per app** (pos / api / web).
- **Environments tagged per `(tenant, company, terminal)`.** Single terminal in TN deploy-phase-1; environments scale with future client adds.

### Questions to file as findings

1. Does `serializeErrorForLog` redact token / PIN / PII / voucher serials / receipt QR tokens? Grep test.
2. Every catch block in `apps/pos/src` (NOT just `syncService.ts`) — does it use `coerceSyncError` or equivalent? Repeat the PR #120 audit across `bootstrapStore`, `paymentStore`, `terminalStore`, `productStore`, `authStore`, `useTerminalActivation`, all hooks/components.
3. Rust panics in `apps/pos/src-tauri/src/printing/*`, `commands/*`, `lib.rs` — propagate cleanly to JS or silently die? `panic = "abort"` vs `"unwind"`?
4. Server-side correlation IDs in `apps/api` — every sync request tagged with `terminal_id` + `idempotency_key`?
5. SQLite log retention: `cleanupOldSyncLogs(7 days)`. Server-side `daily.log` retention?
6. Console-grep sweep across `apps/pos/src` (TS) + `apps/pos/src-tauri/src` (Rust) for sensitive fields.

---

## §2. Tauri device security & native permissions

### Verified current state

`apps/pos/src-tauri/capabilities/default.json` grants:
- `core:default`, `core:event:default`, full `core:window:*`
- `sql:default`, `sql:allow-execute`, `sql:allow-select`
- `store:default`
- `http:default` with allow list `[http://*:*/**, https://*:*/**, http://*/**, https://*/**]` — **ALL hosts, ALL ports**
- `notification:default`, `os:default`, `window-state:default`, `log:default`
- `fs:default` + `fs:allow-appdata-read/write-recursive` for `$APPDATA/images/**`

`apps/pos/src-tauri/tauri.conf.json`: `"csp": null` (no CSP), asset protocol scope `["**"]`.

### Questions

1. **HTTP allow list** — narrow to the actual Synerivia API base URL(s) + Reverb WebSocket origin. Cite the actual hosts.
2. **SQL permissions** — `sql:allow-execute` necessary for migrations; otherwise tight is `sql:allow-select`. Audit code paths.
3. **OS / notification / window-state** — every Tauri command using these — enumerate, audit.
4. **CSP** — propose a strict-but-functional CSP that allows Echo/Reverb WS + the API fetches and nothing else.
5. **Asset protocol scope** — narrow to `$APPDATA/images/**`.
6. **AES token at rest** — key derivation, key file location, salt, rotation. `apps/pos/src-tauri/src/commands/crypto.rs` + `apps/pos/src/lib/storage.ts`.
7. **PIN hashes** — algo (bcrypt? argon2?), salt, constant-time comparison.
8. **Window-state plugin** — persists coordinates; verify no leak.
9. **Customer-display window scope** — IPC isolation from main window.
10. **`invoke` command surface** — every command in `lib.rs` + `commands/*` — enumerate, argument-validate, caller-authorize.
11. **(Codex r2 P2)** Classify which local data is **plaintext after filesystem access**: SQLite rows, Tauri Store contents, image cache, log files. AES applies only to the bearer token blob — everything else (offline_receipts, terminal_state, products, vouchers, sync_log, operator_pins) is plaintext SQLite. Document the threat-model implication.

### Audit deliverable

A capability-tightening proposal (NOT implementation): minimal viable `capabilities/default.json`, working CSP string, narrowed HTTP allow list, narrowed asset protocol scope. Plus a per-table data-sensitivity classification.

---

## §3. Build, deploy, install, manual-update for deploy-phase-1

### Anchor files

- `apps/pos/src-tauri/tauri.conf.json` — bundle config, identifier, version, icons, targets
- `apps/pos/src-tauri/Cargo.toml` — Rust deps, profile
- `apps/pos/package.json` — `tauri build` script
- `docs/superpowers/plans/2026-05-11-tunisia-customer-rollout.md` (303 lines) — existing rollout doc
- `apps/pos/src-tauri/src/lib.rs` — boot order, plugin init, updater status (declared but never initialized)

### Questions

1. (Phase 0 answers): OS + arch confirmed.
2. `pnpm tauri build` — produces what artefact? Reproducible (deterministic flags, lock-file pinned, `rust-toolchain.toml` present)?
3. **Code signing** required for the target OS? If yes, certificate management. If no, document.
4. First-install flow walk-through against `tunisia-customer-rollout.md`.
5. **Backup procedure** — files: `izipos-${companyId}.db`, `.db-wal`, `.db-shm`, `izipos-settings.json`, AES key file, `$APPDATA/images/*`. Per-OS locations.
6. **Restore drill (Codex r2 P2)** — actually copy app-data to a second machine OR clean install, restore from backup, verify: SQLite row count matches, encryption key works (token still decryptable), images render, Tauri Store keys present. Documented procedure + observed outcome.
7. **Manual update procedure for deploy-phase-1:** step-by-step. Operator-side + Synerivia-side handoff. Rollback procedure if a manual swap bricks the terminal. Required preservation list (same as backup).
8. **deploy-phase-2-updater follow-up spec** — one-page document: endpoint shape (Synerivia "latest version" API JSON contract), signature verification, rollback storage location, notification UX. NOT implemented in this audit.
9. **Auto-start / kiosk mode** — terminal launches POS on boot, fullscreen, no window decorations. Audit `fullscreen.ts` + window config.
10. **Crash recovery on relaunch** — stranded receipts, in-flight checkouts, partial Z-reports surfaced? Cross-reference §14.

### Audit deliverable

1. **Operator runbook** for manual install / update / restore. Step-by-step, screenshots optional.
2. **Restore drill report** — pre-state, drill procedure, observed outcome, checksums/row-counts for SQLite + Tauri Store + key file + images.
3. **deploy-phase-2-updater follow-up spec.**

---

## §4. Fiscal hash chain (client + server v3 parity)

### Anchor files

- `apps/pos/src/lib/fiscal/v3/` — v3 canonical JSON + hash service
- `apps/pos/src/lib/fiscal/hashService.ts` — `computeGenesisHash`, advance helpers
- `apps/pos/src/lib/db/repositories/terminalStateRepository.ts` — `upsertTerminalState`, `advanceHashChain`, regression guards
- `apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php`
- `apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/`
- `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php`
- `apps/api/app/Modules/Compliance/Commands/VerifyFiscalChainsCommand.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php`
- Golden fixtures: `apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/*.json`

### Questions

1. Run TS + PHP v3 computers against golden fixtures. Identical hashes per fixture — any drift is `[BLOCKER]`.
2. `VerifyFiscalChainsCommand.php` — runs in CI? On schedule? What does it check?
3. **Hash-chain regression guards** — `advanceHashChain` rejects non-increasing writes. PR C's hypothetical `rewindTerminalChainForRecovery` would bypass — verify the guards are tight enough that ONLY a documented bypass can rewind.
4. **Z-report chain** — `pullZChainState` semantics. Same break-failure modes as receipt chain? Recovery?
5. **Schema versioning** — `fiscal_schema_version` column. v2 + v3 receipts coexist?
6. **Audit trail** — every chain mutation logged where (TimescaleDB? sync_log? both)?

---

## §5. Tunisia compliance matrix

### Required matrix — each row needs **dated legal source** (Codex r2 P1)

| Requirement | Source URL / document title / access date | Owner | Current state | Gap |
|---|---|---|---|---|
| Currency scale (TND = 3 decimals) | _TBD by audit_ | _TBD_ | `CurrencyScale` PHP + `currency.ts` TS honor TND=3 | Verify across every numeric column / display / receipt template / Z-report / refund / voucher |
| VAT rates (TN: 7%, 13%, 19% reportedly) | _TBD by audit — TN DGI official rate table_ | _TBD_ | TVA breakdown per tax rate | Confirm rate set current; per-tax-rate rounding |
| Receipt legal fields (TVA #, fiscal #, address, operator ID, terminal ID, receipt # format) | _TBD by audit_ | _TBD_ | _audit fills_ | _audit fills_ |
| Tax identifier format (matricule fiscal) | _TBD by audit_ | _TBD_ | _audit fills_ | _audit fills_ |
| Arabic RTL on printed receipt | **Customer decision required** | **Client** | `apps/pos/src/locales` has `en`, `fr` only (no `ar`) | If client says French-only, mark Arabic as documented out-of-scope. If Arabic required, add locale + RTL layout + ESC/POS code-page selection. |
| Fiscal printer certification | _TBD by audit_ | _TBD_ | Unknown | Determine if TN mandates certified printers for retail |
| E-invoicing / Elyssa / national e-invoice system | _TBD by audit_ | _TBD_ | Unknown | TN's e-invoice mandate status as of 2026; retail applicability |
| Receipt retention period | _TBD by audit_ | _TBD_ | `pos_receipts` server-side eternal; SQLite cleanup? | Document policy |
| Z-report frequency mandate | _TBD by audit_ | _TBD_ | Driver-initiated | Allowable cadence |
| Hash chain tamper-evidence | NF525 (FR baseline) — is it a TN requirement? | _TBD_ | v3 implemented | Decide: NF525 = internal control OR contractual claim |
| QR code on receipt | _TBD by audit — TN-inspector-app format spec_ | _TBD_ | v3 QR emitted | Verify inspector-app compatibility OR document absence of standard |
| Data residency | TN regulations + GDPR if EU customers | _TBD_ | API hosted where? | Document hosting region + PII flow |

### Audit deliverable

Filled matrix. Every row with an unresolved Gap mapping to a regulatory mandate becomes `[BLOCKER]` or `[P1]` depending on go-live blocker status.

---

## §6. Receipt rendering & printing

### Anchor files (Codex-corrected against worktree)

- **Frontend builder:** `apps/pos/src/lib/buildReceiptData.ts`
- **Print orchestrator (frontend):** `apps/pos/src/lib/printing.ts`, `apps/pos/src/stores/printerStore.ts`
- **Rust ESC/POS:** `apps/pos/src-tauri/src/printing/escpos.rs`, `receipt_template.rs`, `voucher_ticket.rs`
- **Transports:** `apps/pos/src-tauri/src/printing/usb.rs`, `network.rs`, `windows.rs`, `mod.rs`
- **Print commands:** `apps/pos/src-tauri/src/commands/printing.rs`
- **Offline read-path:** `apps/pos/src/lib/offline/getOfflineReceiptForPrint.ts`
- **Customer-facing display:** `apps/pos/src/pages/CustomerDisplayPage.tsx`
- **Success modal:** `apps/pos/src/components/pos/CheckoutSuccessModal.tsx`
- **Today's sales / reprint list:** `apps/pos/src/components/pos/TodaySalesPanel.tsx`
- **Backend print audit:** `apps/api/app/Modules/POS/Application/Services/ReceiptPrintAuditService.php`, table `pos_receipt_prints`
- **Sync print log:** TODO in `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php` (offline print logs not yet synced)

### Golden-render harness (Codex r2 P2 clarification)

**Note:** there is no separate "React receipt preview" component. The cashier sees the success modal (`CheckoutSuccessModal.tsx`) and the today's-sales reprint list (`TodaySalesPanel.tsx`). The golden-render harness therefore compares:

1. **`buildReceiptData` output JSON** — checked into golden fixtures.
2. **Rust ESC/POS byte stream** from `receipt_template.rs` — checked into golden fixtures (binary diff per scenario).
3. **`CheckoutSuccessModal` rendered HTML** — Vitest snapshot per scenario.

Scenarios: 58 mm + 80 mm × cash exact / cash over-tender / cash tolerance short-pay / card / split / voucher (store + restaurant + gift) / refund / training-mode × EUR / TND.

### Questions

1. Run the golden-render harness. Any byte-level diff is `[BLOCKER]`.
2. **Post-PR #121 verification:** over-tender sale's printed "Monnaie rendue" matches `Σ(payments.amount) − total = tendered − total`. Smoke + golden test.
3. **Reprint distinguishability** — NF525 requires originals vs duplicates visually distinct. Audit `ReceiptPrintAuditService` writes + printed banner.
4. **Offline print-log sync gap** — `pos_receipt_prints` writes server-side only; offline reprints not pushed. `[P1]` until designed.
5. **Printer disconnect mid-print** — transport reports failure how? Receipt re-queue? Cashier UX?
6. **Code-page selection** — Rust builder defaults to Win-1252. Arabic needs CP-864 or Win-1256. Audit selection + lossy-replacement behavior. RTL bidi shaping.
7. **Column-width calculation** for multi-byte / non-Latin — measured in bytes vs grapheme clusters? Right-align logic correct?
8. **Customer display sync** — page renders mirror cashier UI? Race conditions during processing?
9. **QR code rendering** — ESC/POS QR command vs raster fallback? Quiet-zone? Module size?
10. **Voucher ticket path** — separate template; audit fields + customer-facing language.

---

## §7. Cash drawer & shift management (post-PR #121)

### Anchor files (CashDrawerService path Codex-corrected)

- `apps/pos/src/lib/offline/zReportService.ts`
- `apps/pos/src/lib/offline/endOfDayPreview.ts`
- `apps/pos/src/stores/cashDrawerStore.ts`
- `apps/pos/src/lib/db/repositories/cashDrawerRepository.ts`
- `apps/pos/src/components/settings/CashDrawerSettings.tsx`
- `apps/pos/src-tauri/src/commands/printing.rs` (`open_cash_drawer` ESC/POS pulse)
- `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php` (`buildExpectedPerMethod`)
- **`apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php`** (Codex r2 P1 — this is the actual path, NOT `Application/Services/`)
- `apps/api/app/Modules/POS/Presentation/Controllers/CashDrawerController.php`
- `apps/api/tests/Feature/POS/CashCountToleranceVarianceRegressionTest.php` (the contract for `expected_cash`)

### Questions

1. **Smoke-verify post-PR #121 contract** — open shift → ring 5 sales (mix of exact / over-tender / tolerance) → close → expected = physical. **Output requirement (Codex r2 P2): a machine-readable fixture in the audit findings**: TSV or JSON table with `(scenario, opening, tendered, change_due, tolerance, expected, actual, variance, severity)` per row. Paper-only worksheets are not acceptable.
2. **Cash-affecting event matrix** — every event counted exactly once in `expected_cash`: opening, cash sale (tendered), change_due (subtract), refund/void (subtract net cash entering at sale time per PR #121 formula), deposit, payout, tolerance write-off (NOT counted — GL only). Build matrix.
3. **Physical drawer kick** — `open_cash_drawer` ESC/POS pulse. Duplicate kicks? Drawer-open audit vs sale recording. `'SALE'` cash-drawer-op type is currently dead code (no caller) — decide: delete OR wire up.
4. **Z-report contents** — every cash-count input, variance row, severity, tolerance summary. Match against `CashCountToleranceVarianceRegressionTest` golden.
5. **Manager override** for variance overrides — flow audit.
6. **Shift close with pending sync** — receipt still pending sync, shift closes — what happens?

---

## §8. Payment flows

### Anchor files (Codex-corrected, `refundDraftStore` added)

- `apps/pos/src/stores/paymentStore.ts` (`processCashCheckout` post-PR #121, `processCardCheckout`, `processAdvancedSplit`)
- `apps/pos/src/stores/refundDraftStore.ts` (Codex r2 P2 — was named in question 4 but not anchored)
- `apps/pos/src/lib/offline/receiptService.ts`
- `apps/pos/src/components/pos/CashTenderedModal.tsx`
- `apps/pos/src/components/pos/CardPaymentModal.tsx`
- `apps/pos/src/components/pos/VoucherTenderModal.tsx`
- `apps/pos/src/components/pos/PaymentSummary.tsx`
- `apps/pos/src/components/pos/CheckoutSuccessModal.tsx`
- `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`
- `apps/pos/src/lib/offline/voucherRepository.ts`
- `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php`
- `apps/api/app/Modules/POS/Application/Services/VoucherLedgerPushService.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php` (post-PR #121 net-drawer-cash formula)

### Questions

1. **Internal contract consistency post-PR #121** — cash quick-path stores `amount = tendered`. Split-path already did. Card-path stores `amount = cart total`. All three internally consistent with `CashCountToleranceVarianceRegressionTest`.
2. **Voucher matrix** — store voucher (with serial + ledger writeback) vs restaurant voucher vs gift card. For each: supported / unsupported, user-facing flow, fiscal hash fields, sync behavior, GL impact, refund behavior.
3. **Card processing** — manual external terminal (cashier enters auth code) or integrated? Audit `CardPaymentModal`. If manual: document reconciliation flow for mismatched approvals.
4. **Refund / void differentiation** — full receipt void via `ReceiptVoidService` (PR #121 verified) vs partial line refund via `refundDraftStore` / `ReceiptReturnService`. Both paths preserve fiscal integrity?
5. **Loyalty redemption** — points-as-tender vs points-as-discount; which is implemented? Variance-formula impact?
6. **Mixed-currency** — out of scope, but verify no path silently produces them.
7. **Split-payment edge cases** — partial-failure paths.

---

## §9. Sync reliability (post-PR #120)

### Anchor files (exact paths per Codex r2 P2)

- `apps/pos/src/lib/sync/syncService.ts` — push: receipts, Z-reports, cash-drawer ops, voucher ledger, PIN updates; pull: products, payment config, operators, terminal state, Z-chain state, tables, active menu, vouchers, voucher ledger, receipt QR index
- `apps/pos/src/lib/sync/syncScheduler.ts`
- `apps/pos/src/lib/sync/coerceSyncError.ts` (PR #120)
- `apps/pos/src/stores/syncStore.ts`
- `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts`
- `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php` (note offline-print-log TODO)
- `apps/api/app/Modules/POS/Presentation/Controllers/VoucherSyncController.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php`

### Questions

1. **Batch-of-one TODO (PR #120 carryover)** — sync loops one receipt per round-trip. Server already accepts batches. Measure backlog drain for N=100. Threshold above which batch-N is required for go-live.
2. **Sync-flow matrix** — every sync verb × failure mode (network timeout, server 4xx, server 5xx, hash mismatch, idempotency duplicate, lock retry) × handled-or-not. **Codex r2 P2: each row must explicitly trace the idempotency-key through server logs/DB rows** for duplicate-safe replay proof (required by §16 Flow 4).
3. **Offline print-log sync gap** — `pos_receipt_prints` writes server-side only.
4. **Stranded-receipt UX** — rows at `retry_count >= 5` for a non-lock reason. Cashier sees what? Operator inbox?
5. **Z-report chain** — same break-failure modes? Recovery?
6. **PIN-update sync** — polling cadence? WS-pushed?
7. **WS-vs-polling fallback** — PR #122 catalog channel. 60s polling tick still picks up changes when WS is down?
8. **Background vs foreground sync** — race conditions?
9. **`computeDegraded` triggers** — what flips banner? When clears?

---

## §10. Authentication, permissions, device threat model

### Anchor files (middleware path Codex-corrected)

- `apps/pos/src/stores/authStore.ts`
- `apps/pos/src/stores/operatorStore.ts`
- `apps/pos/src/components/PinPad.tsx`
- `apps/pos/src/hooks/useTerminalActivation.ts`
- `apps/pos/src/lib/storage.ts` (token at-rest encryption)
- `apps/pos/src-tauri/src/commands/crypto.rs` (AES key file)
- `apps/pos/src/lib/db/repositories/operatorPinRepository.ts`
- `apps/pos/src/lib/db/repositories/queuedPinUpdateRepository.ts`
- `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/ManagerPinController.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php`
- `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php`

### Memory notes — inline quotes (Codex r2 P1)

**Verified quote from `feedback_usePermissions_hardcoded_map.md`:**

> "`apps/web/src/hooks/usePermissions.ts` uses a hardcoded `ROLE_PERMISSIONS` map instead of reading the auth payload's permission list."
>
> Why: "Custom roles with granted permissions are silently denied on the frontend."
>
> How to apply: "When working on permission-gated UI, do not assume usePermissions reflects backend Spatie state. Verify with the user before relying on it for non-default roles."

**Audit assertion to verify (NOT in the note):** the POS terminal does not consume `usePermissions` (it's web-admin-only). POS permission checks happen server-side via route middleware. **Codex must verify this assertion against current code; do not treat as memory-note content.**

**Verified quote from `project_discount_permissions.md` (relevant excerpts):**

> "`users.can_discount` boolean, defaults `false` — NO user has this enabled by default"
> "`pos_terminals.max_discount_percent` decimal defaults `0.00` — BLOCKS all discounts"
>
> Permission chain: "User.can_discount (must be true) + Terminal.max_discount_percent (ceiling) + User.max_discount_percent (optional further restriction) = effective limit = min(terminal_limit, user_limit)"
>
> "`discountApi.ts` — `fetchDiscountPermissions()` EXISTS but NEVER CALLED (dead code)"
> "`HomePage.tsx` — reads operator.can_discount, blocks if false, passes maxDiscountPercent to modals. Does NOT call the `/pos/discount-permissions` endpoint (terminal limits ignored)"
>
> What needs to be built (relevant subset): "Wire discountApi: POS desktop should call `/pos/discount-permissions` to get terminal-aware limits. Fix defaults: super admins get `can_discount=true`, terminals default to `max_discount_percent=100`."

**Codex must reconcile these excerpts with current code** — the memory note is dated 2026-03-23 and may be stale.

### Questions

1. **Device threat model** — stolen / lost terminal. What's recoverable from SQLite + Tauri Store + appdata? AES key derivation + storage location — extractable from disk?
2. **Token lifetime + revocation** — Sanctum expiration; server-side revocation; terminal honors revocation?
3. **PIN hash sync** — algo, salt, time-safe comparison. Offline validation correctness.
4. **Manager-PIN modal** — local hash vs server round-trip; offline manager-only actions.
5. **Discount permissions chain** — reconcile memory-note excerpts against current code. Verify wired-vs-unwired status.
6. **Operator auto-lock** — configurable? Reasonable defaults? Lock preserves cart?
7. **Terminal pairing** — first pair + re-pair + revocation flows.
8. **Logout cleanup** post-PR #122 `peekEcho` fix — stores reset, WS disconnected, cart cleared, no token residue.
9. **`tenant:<uuid>` ability check** in `EnforceTokenTenantClaim` — every sync request validates.

---

## §11. UI/UX & ergonomics

### Anchor files (Codex-corrected: `lib/i18n.ts`, both component trees)

- `apps/pos/src/pages/HomePage.tsx`
- `apps/pos/src/components/pos/*` (older surface) — anchor explicitly
- `apps/pos/src/components/organisms/*` (newer surface) — anchor explicitly. **Codex must identify duplicates and audit the canonical one.**
- `apps/pos/src/lib/i18n.ts`
- `apps/pos/src/locales/` — confirmed `en` + `fr` only; no `ar` locale present
- `apps/pos/src/hooks/useBarcodeScanner.ts`
- `apps/pos/src/lib/scan/resolveScannedCode.ts`

### Memory note — inline quote (Codex r2 P1)

**Verified quote from `feedback_modal_fixed_size.md`:**

> "Modals must never change size when elements inside them are clicked or toggled. Use fixed `h-[Xvh]` heights per size tier, not `max-h` that shrink-wraps to content."
>
> Why: "The user finds it frustrating and unprofessional when modals jump in size as you interact with them (e.g. AdvancedPaymentsModal growing when a payment method is selected and the config panel appears). This was flagged as a systemic issue across all POS modals."
>
> How to apply: "When building or modifying any modal in the POS app, ensure it uses the Modal component's fixed-height sizes (sm=45vh, md=55vh, lg=70vh, xl=80vh, full=85vh). If a modal has conditionally rendered content (touch-mode NumPad, expandable sections, error messages), the base size must be large enough to accommodate the maximum content without resizing. Use a larger `size` prop when touch mode adds extra content (e.g. CashTenderedModal uses `lg` in touch mode, `md` otherwise)."

### Playwright-for-Tauri approach (Codex r2 P1)

`apps/pos/e2e/` does NOT currently exist. Decision matrix the audit must resolve before Phase 3:

| Approach | Coverage | Cost | Recommended for |
|---|---|---|---|
| **Vite web surface only** (browser Playwright against `pnpm dev`) | Most React component behavior, modals, RTL, i18n | Low | UI invariants that don't need Tauri APIs |
| **Tauri WebDriver** (`tauri-driver` + Playwright via WebDriver) | Full Tauri window incl. native dialogs, file system | Medium (requires `tauri-driver` install + per-OS quirks) | Anything touching `invoke`, native menus, window state |
| **Tauri mock layer in tests** (existing Vitest pattern) | Component-level only | Lowest | Already used in `useTerminalActivation`, `useCatalogChannel` tests |

**Recommendation:** Use the Vite web surface for UX/RTL/touch-target/keyboard invariants (the questions below). Use Vitest mocks for component-level. Reserve Tauri WebDriver for §14 crash scenarios that need native lifecycle (see §14's injection mechanism).

### Questions

1. **Touch targets** — measure every button on `HomePage` at 1280×800 and 1920×1080. Minimum 44×44 pt.
2. **Modals never resize** (per the quoted memory note).
3. **Keyboard shortcuts** — Enter/Escape in cash-tender, numpad, scanner Tab/Enter, stuck-focus recovery.
4. **Offline banners** — distinct for WS down / API down / fully offline.
5. **Training-mode banner** + receipts marked.
6. **Hardcoded strings** — POS-side grep for user-facing strings bypassing `t()`. Tenant-isolation sweep covered web; verify POS.
7. **RTL** — IF Arabic added per §5 decision, audit layout direction.

---

## §12. Multi-vertical (Otospex automotive vs IziPOS retail + Menu/FnB)

### Anchor files (exact paths per Codex r2 P2)

- `apps/pos/src/stores/terminalStore.ts` — vertical detection
- `apps/pos/src/stores/productStore.ts` — Menu vs standard-retail branches
- `apps/pos/src/api/productApi.ts` — POS-facing product fetch (frontend)
- `apps/api/app/Modules/Menu/Presentation/Controllers/ActiveMenuController.php` — POS-facing menu fetch (backend)
- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php` — admin product CRUD
- `apps/api/app/Modules/Product/` — Product module root
- `apps/api/app/Modules/Catalog/` — composite items + modifiers (Menu tenants)
- `apps/api/app/Modules/Menu/` — menu structure
- `apps/api/app/Modules/Workshop/` — expected ABSENT (per `project_otospex_pos.md`); confirm absence as expected current state

### Memory notes — inline quotes (Codex r2 P1, with the Otospex/customer-lookup distinction corrected)

**Verified quote from `project_otospex_pos.md` — critical MVP gaps:**

> "**Critical gaps for MVP**:
> - Workshop module (empty scaffold — needs WorkOrder entity, status machine, technician assignment)
> - Automotive POS layout (table-based, not grid-based — separate from IziPOS)
> - Labor hour logging (estimated vs actual, technician tracking)
> - Core charge tracking (deposit/return lifecycle for remanufactured parts)
> - **Vehicle selection in POS receipt flow**"
>
> Key decisions: "Otospex POS is a separate app/layout from IziPOS (fundamentally different UX). F&B features already scoped via `hasModule(config, 'Menu')` — no code changes needed. Workshop module will be new module at `apps/api/app/Modules/Workshop/`."

**Verified quote from `project_refund_flow_phases.md` — Phase 1 deferred customer lookup (DISTINCT from Otospex vehicle selection):**

> "Phase 1 = standard-retail (IziPOS vertical). What's IN Phase 1 scope:
> - Standard-retail refund + exchange flow on a single offline-first terminal (PR #76 closed this).
> - Voucher tender with `instrument_type === 'store_voucher'` (PR #76 closed this end-to-end).
> - **Customer lookup by phone / email / loyalty card at the till — the cashier-facing identifier flow that the spec calls for. Currently not shipped — see "Phase 1 deferred items" below.**"
>
> Phase 1 deferred item still pending: "**Customer lookup by phone / email / loyalty card.** Status as of 2026-05-01: Phase 1 scope, not shipped. The original `ReceiptLocatorScreen` 'Find by customer' tab was wired to a partner UUID textbox the cashier could never produce. Codex flagged it as broken; M2-UI commit `220dc4ea` dropped the tab in PR #76. The dispatch note framed it as 'Phase 2 will rebuild with phone/email/loyalty mirror' — that framing was wrong; it's Phase 1 work, just deferred from PR #76."

**Two distinct deferred items — Codex r2 P1 closure:**

| Item | Vertical | Status | Source memory note |
|---|---|---|---|
| **Vehicle selection in POS receipt flow** | Otospex (automotive) | Critical MVP gap, not built | `project_otospex_pos.md` |
| **Customer lookup by phone / email / loyalty card at till** | IziPOS (standard-retail) | Phase 1 scope, deferred from PR #76 | `project_refund_flow_phases.md` |

### Summary of `project_otospex_brand.md`:

> Canonical spelling: **"Otospex"** (capital O, lowercase rest — not "otospex", not "Otospexx", not "Otospexsolutions"). Theme: **pink**. IziPOS is the sibling retail product (copper theme). Tunisia deployment.

### Questions

1. **Cross-vertical leak check** — Menu tenant ever sees standard-retail product (or vice versa)? Re-verify POS paths despite recent sweep.
2. **Catalog matrix:**
   - Standard retail: `/api/v1/products` returns company products. POS bulk-pulls + caches.
   - Menu (FnB): `/api/v1/active-menu` returns menu with categories → composite items + modifiers. POS reconciles via `reconcileMenuProducts`.
   - Composite IDs: sellable in two categories = two distinct POS SQLite rows (C2 work). Verify post-merge.
   - Modifiers: serialized into active-menu. PR #122 broadcasts cover ModifierGroup + Modifier.
   - Price/tax inheritance from CompositeItem to MenuCategoryItem override.
   - Stock availability: `is_available` per MenuCategoryItem.
3. **Theming** — Otospex pink vs IziPOS copper. Per-company config? Where does the theme switch happen?
4. **Workshop integration (Otospex)** — `apps/api/app/Modules/Workshop/` expected ABSENT. Confirm absence as expected current state. Documented gap, not a finding.
5. **Vertical detection** — what flag determines IziPOS vs Otospex? Per company? Per terminal? UX gates?

---

## §13. Performance budget (measurement harness with commit boundary)

### Harness commit boundary (Codex r2 P1)

**Audit branch `audit/pos-production-readiness` retains:**
- Seeded SQLite fixture (`apps/pos/src/test/fixtures/perf-5k-products.sqlite.json` — generator script + checked-in seed data)
- Playwright e2e harness (`apps/pos/e2e/perf/*`)
- SQLite EXPLAIN script (`apps/pos/scripts/explain-hot-queries.ts`)
- Golden-render harness (`apps/pos/src/lib/__tests__/golden-receipts/*`)
- Measurement output (`docs/superpowers/audits/2026-05-12-pos-production-readiness-findings.md` includes the perf evidence)

**Audit branch DOES NOT retain:**
- Temporary `performance.mark` / `performance.measure` instrumentation inside production code paths. These are added during the audit, captured in the findings doc, and **removed before any merge back to `dev`**. The findings doc preserves the measurements as text + screenshots.

**Implementation PRs (follow-ups to the audit) ship their own instrumentation if production telemetry is recommended.**

### Anchor files

- `apps/pos/src/stores/productStore.ts` — foreground/background fetch
- `apps/pos/src/lib/db/repositories/productRepository.ts` — SQLite queries
- `apps/pos/src/lib/db/migrations.ts` — indexes
- `apps/pos/src/lib/images/imageCache.ts` — file I/O + eviction
- `apps/pos/src/lib/scan/resolveScannedCode.ts` — barcode lookup
- `apps/pos/src/lib/sync/syncScheduler.ts` — sync cadence
- `apps/pos/src/lib/sync/syncService.ts`
- `apps/pos/src-tauri/src/printing/network.rs` — printer discovery network scan
- `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php` — catalog payload shape

### Memory note — inline quote (Codex r2 P2 confirmed accurate; reconcile against current code)

**Verified quote from `project_pos_performance.md`:**

> "POS app needs a performance overhaul to be snappy with 1,000-5,000+ products (auto parts retailers). Current architecture is fetch-then-render (API call blocks UI). Products load only on shift open. No memoization on ProductGrid/ProductCard. No image caching. SyncScheduler defined but never instantiated."
>
> Key findings (2026-03-23): "ProductStore has 5-min in-memory cache + SQLite write-through fallback. No `React.memo` on ProductGrid or ProductCard — every state change re-renders all cards. Images loaded naively via `<img src>` with no lazy loading or disk cache. **SyncScheduler class exists but is never instantiated anywhere.**"

**Reconciliation requirement:** The "SyncScheduler never instantiated" claim is from 2026-03-23 and is **stale** — current code DOES instantiate + start it from `apps/pos/src/stores/terminalStore.ts`. Codex must reconcile every memory-note claim against current code; do not trust the note as authoritative.

### Questions

1. Catalog cold start (P50, P95): process spawn → first interactive frame with 5K products.
2. Barcode-scan latency (P50, P95): scanner → cart line.
3. Cart-add latency: tap product → line + total update.
4. Cash-checkout latency: hit OK → success modal.
5. Sync 100-receipt backlog drain time.
6. 8-hour shift memory profile: RSS start/mid/end. Leak detection.
7. Image-cache eviction: triggers, size limit, steady state.
8. EXPLAIN QUERY PLAN for every hot query — any seq-scans without WHERE indexes?
9. Tauri bundle size — acceptable for target hardware?
10. SQLite WAL checkpoint behavior — auto cadence, manual on shutdown?

---

## §14. Resilience & crash safety (scripted scenarios with explicit injection)

### Crash-injection mechanism (Codex r2 P1)

**Random `kill -9` is unacceptable** for proving each state boundary. The audit defines two injection mechanisms:

1. **Debug-only fault-injection flags** — add a feature flag `POS_FAULT_INJECTION=BEGIN|INSERT|ADVANCE|COMMIT|SYNC_BEFORE_ACK|SYNC_AFTER_ACK|PRINT|ZREPORT|COMPANY_SWITCH` that, when set, panics/exits at the named code point inside `apps/pos/src-tauri/src/commands/*.rs` and the relevant TS sites. The flag is debug-build-only (compile-time gated) and stripped from production builds. Implementation lives in the audit branch and is reverted before merge.

2. **Tauri-driver lifecycle hooks** — for native-level scenarios (app kill mid-print, mid-company-switch), use Tauri WebDriver to issue OS-level signals at predictable lifecycle states.

### Required scripted scenarios

1. **Kill mid-checkout**: panic at each of `BEGIN`, `INSERT offline_receipts`, `advanceHashChain`, before `COMMIT`. Restart; verify state.
2. **Kill mid-sync**: panic AFTER server accepted but BEFORE client marks `'synced'`. Restart; verify dedup returns `'duplicate'`.
3. **Kill mid-Z-report.**
4. **Kill mid-print.**
5. **Kill during company switch** (SQLite singleton swap).
6. **Power loss simulation**: `kill -9` on the SQLite shutdown path. WAL preserves durability.
7. **SQLite corruption injection**: corrupt a page mid-shift; detection + cashier-facing message + recovery.
8. **Manual update preservation**: Codex r2 P2 — **before/after checksums** for `izipos-${companyId}.db`, `.db-wal`, `.db-shm`, `izipos-settings.json`, AES key file. Row counts for every SQLite table. Image-file inventory checksum. Documented + observed.

### Anchor files

- `apps/pos/src/lib/db.ts` (post-PR #120 WAL + recovery hook)
- `apps/pos/src/stores/bootstrapStore.ts`
- `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts`
- `apps/pos/src/lib/db/repositories/refundDraftRepository.ts`
- `apps/pos/src-tauri/src/lib.rs` — boot order, shutdown handlers
- `apps/pos/src-tauri/src/commands/*` — fault-injection points

### Audit deliverable

Scripted-recovery results doc per scenario: pre-state, injection point, post-state expectation, observed post-state, pass/fail.

---

## §15. Multi-tenant / company isolation (POS-specific)

**Scope is correct per Codex r2 (no remaining P1 in this section).** POS-specific leaks IN; broader sweep OUT.

### Anchor files

- `apps/pos/src/stores/authStore.ts` — `tenantId` + `companyId`; persists token / user / companyId / companies / terminal in `izipos-settings.json`
- `apps/pos/src/stores/productStore.ts` — catalog scope
- `apps/pos/src/lib/db.ts` — per-company SQLite naming `izipos-${companyId}.db`
- `apps/pos/src/lib/storage.ts` — Tauri Store wrapper
- `apps/pos/src/lib/echo.ts` — Echo client
- `apps/pos/src/hooks/useCatalogChannel.ts` (PR #122)
- `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php`
- `apps/api/routes/channels.php` — channel auth (`canAccessCompanyChannel`)

### Questions

1. **Company switch flow** — SQLite singleton closes + reopens correct `.db`. Echo channel re-subscribes correct private channel. `izipos-settings.json` updates. PR #120's recovery hook does NOT cross companies.
2. **Cached state per store** — `productStore` / `paymentStore` / `terminalStore` / `cartStore` all clear on company switch?
3. **Tauri Store isolation** — single `izipos-settings.json` file holds token + user + multiple companies. Stale `lastReceiptIdempotencyKey` from previous company?
4. **WebSocket channel auth** — broker rejects cross-tenant subscriptions.
5. **API request auth** — every POS endpoint enforces `tenant:<uuid>` ability + active UserCompanyMembership.

---

## §16. Cross-section flow audits

### Shared audit-fixture contract (Codex r2 P1)

**Before Phase 5 begins, the audit branch commits a shared fixture used by all 8 flows:**

```
apps/pos/src/test/fixtures/audit/
├── tenants/tenant-tn-1.json        # single TN tenant
├── companies/company-coffee-1.json # single Menu/FnB company
├── companies/company-retail-1.json # single standard-retail company (if scope adds)
├── users/cashier-1.json            # operator user
├── users/manager-1.json            # manager-PIN holder
├── users/admin-1.json              # web-admin user for mutation flows
├── terminals/terminal-tn-1.json    # paired terminal
├── tax-rates/tnd-rates.json        # TN VAT rates per §5 matrix
├── products/coffee-shop-50.json    # 50-product seed
├── products/retail-5k.json         # 5K-product seed (for §13 perf)
├── vouchers/store-voucher-set.json # store-voucher fixtures
└── menus/coffee-shop-menu.json     # Menu/FnB structure
```

**Each fixture file is a JSON blob the audit branch's seeder consumes.** Codex must commit the seeder + the JSON before flow audits start. Flows reference fixtures by ID, not by ad-hoc setup.

### Required flows

1. **Cash sale → print → sync → server receipt → Z-report → fiscal chain.** _(Analytics removed per Codex r2 P2 — no anchor existed in v2; add back if §1 telemetry decision points at a specific analytics surface.)_
2. **Refund → voucher writeback → receipt QR → sync.** Voucher partial-redemption ledger. Refund preserves chain.
3. **Company switch → SQLite swap → Tauri Store update → Echo re-subscribe → productStore re-fetch.** No stale state.
4. **Offline period → backlog accumulation → connectivity returns → backlog drain → dead-letter UX.** Including post-PR #120 recovery hook self-heal.
5. **Receipt void (PR #121) end-to-end.** Mark voided → reverse stock → batch allocation reversal → cash-drawer REFUND with universal formula → server-side ledger.
6. **Catalog mutation in admin → Reverb broadcast → POS receives → debounce → fetchProducts → POS reflects.** Cover Product, Menu, MenuCategory, MenuCategoryItem (incl. bulk delete via PR #122 r1 fix), CompositeItem, ModifierGroup, Modifier, pivot writes (per PR #122 closures).
7. **PIN change in admin → queued update → POS pull → operator authenticates offline with new PIN.**
8. **Z-report → fiscal chain → server-side sync.** Z-chain integrity.

### Per-flow deliverable

Each flow gets a section in the findings doc with: steps executed, expected artefacts, observed artefacts, diffs, findings tagged severity.

---

## Execution model — 6 phases with per-phase artefact contracts (Codex r2 P1)

### Phase 0 — Target-device profile (deliverable already specified in §0)

**Output:** `audit/phase-0-target-device-profile.md` with the filled table.
**Acceptance:** All 10 attributes captured. §1, §3, §11, §13, §14 cannot proceed without these. §2 may proceed device-agnostically for the capability/CSP/threat-model analysis, with OS-specific follow-up items deferred to a Phase 0 sub-block once the target OS is captured.

### Phase 1 — Inventory + audit-setup findings (Codex r2 P1 — corrected from v2)

**Output:** `audit/phase-1-inventory.md` per section §1–§16. Each section gets:
- Current-state snapshot (200–500 words).
- Anchor-path verification table (`path | exists? | notes`).
- **Audit-setup findings list** — stale anchors, missing files, contradictory memory notes, fixtures missing — tagged `[BLOCKER] / [P1] / [P2] / [NIT]`. These are findings about audit readiness, filed immediately.

**Acceptance:** Every anchor path in this plan checked. Every audit-setup finding filed. No code-level findings yet.

### Phase 2 — Static-analysis findings

**Output:** Section-by-section additions to `docs/superpowers/audits/2026-05-12-pos-production-readiness-findings.md`.
**Acceptance:** Every question in every section answered OR explicitly deferred to Phase 4 (measured). Findings tagged severity.

### Phase 3 — Measurement scaffolding (light implementation, audit branch only)

**Output:** committed scaffolding in the audit branch — Playwright harness, seeded fixtures (§16 contract), golden-render harness, EXPLAIN script, fault-injection flags.
**Acceptance:** Each piece of scaffolding runs end-to-end against the audit-branch app build. CI green on the audit branch.

### Phase 4 — Measured findings

**Output:** Per-scenario evidence in the findings doc — P50/P95 tables, RSS profile, crash-recovery state per scenario, golden-render byte diffs.
**Acceptance:** Every quantitative question in §13 + §14 answered with measurement evidence. Each finding cites the captured artefact.

### Phase 5 — Cross-section flow audits (§16)

**Output:** §16 flow-results doc.
**Acceptance:** All 8 flows executed against the shared fixture. Each flow produces a pass/fail per artefact.

### Phase 6 — Aggregation

**Output:** Final `findings.md` with:
- Severity-ordered list across all sections.
- Per `[BLOCKER]` and `[P1]`: one-paragraph fix plan + estimate.
- Executive summary (1 page) for the user.

**Acceptance:** Single file. No code-level fixes. Hand to Opus + the user.

---

## Memory-note honesty note (Codex r2 P1 closure)

Some memory notes in this plan are **summarized inline**, not quoted verbatim, because the full notes are long. Where a note is summarized, this plan says "Verified quote from X" only when the inline text is a literal extract; otherwise it says "Summary of X" or "Audit assertion to verify (NOT in the note)." Codex must keep the same discipline in the findings doc: every memory-note claim labeled as quote / summary / inference.

---

## In scope (vs v1 exclusions)

- Operator-facing chain-break recovery runbook (PR C in-app UX deferred).
- POS-specific tenant-isolation findings.
- Tauri device security, build, deploy, manual-update procedure.
- Telemetry options-research + recommendation.
- Tunisia compliance matrix with dated legal sources.
- Memory-note quotes / summaries with full source path and quote-vs-summary labeling.
- Cross-section integration flow audits.
- Measurement harness with explicit commit boundary.
- Target-device supportability (remote support, log retrieval, offline workflow).
- NTP / clock-drift handling.

## Out of scope

- PR C in-app chain-break recovery implementation — operational runbook IS in scope; in-app UX deferred until post-WAL chain-break is observed in production.
- **deploy-phase-2-updater implementation** — current-state audit + one-page future spec ARE deliverables; building the updater is post-deploy-phase-1.
- Code-level fixes — strict audit pass.
- Platform repos (`apps/platform`, `apps/platform-ml`, `apps/erp-ml`).
- Web admin tenant-isolation follow-ups — POS slice IS in scope here.

---

## Cross-references

- Bugs cascade work: PRs #120 / #121 / #122 merged 2026-05-11 (see Glossary).
- Tunisia rollout doc: `docs/superpowers/plans/2026-05-11-tunisia-customer-rollout.md` (303 lines).
- v2 plan: `docs/superpowers/plans/2026-05-12-pos-production-readiness-audit-plan-v2.md`.
- v1 plan: `docs/superpowers/plans/2026-05-11-pos-production-readiness-audit-plan.md`.
- Codex r1 review: `docs/superpowers/reviews/2026-05-12-pos-production-readiness-audit-plan-adversarial-review.md` (5 BLOCKERs, REQUEST-CHANGES → closed in v2).
- Codex r2 review: `docs/superpowers/reviews/2026-05-12-pos-production-readiness-audit-plan-v2-adversarial-review.md` (0 BLOCKERs, ~10 P1s, REQUEST-CHANGES → closed in this v3).
