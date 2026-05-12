# POS Production-Readiness Audit — Plan v2

> **Supersedes:** `2026-05-11-pos-production-readiness-audit-plan.md` (v1).
> v1 missed Tauri device security, deploy/install/update, telemetry, and several stale file anchors. Codex adversarial review (`2026-05-12-pos-production-readiness-audit-plan-adversarial-review.md`, REQUEST-CHANGES) prompted this rewrite. v1 stays in tree as a historical artefact.
>
> **For Codex (implementer) + Opus (reviewer):**
> Codex executes this audit end-to-end. Opus reviews the resulting findings doc + every implementation PR that follows. The audit is **read-mostly forensic + scripted-measurement** — for sections that can't be answered by static analysis (P95 latency, crash recovery, printer disconnects), this plan defines a measurement harness that produces evidence in the audit output. **No fixes in this pass.** Fixes ship in follow-up PRs after the user prioritizes findings.

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`
**Base branch:** `dev` (currently at `2121f923` post-merge of PRs #120 / #121 / #122 + v1 plan)
**Audit branch:** `audit/pos-production-readiness` off `dev`
**Output doc:** `docs/superpowers/audits/2026-05-12-pos-production-readiness-findings.md`
**Memory-note root:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/` — referenced notes are reproduced inline in this plan to keep subagents self-contained.

---

## Production-ready definition

The terminal is going live in Tunisia. **Initial scope: one client, one terminal, single tenant.** "Production-ready" means:

### Functional
- Cashier can ring 100 receipts/hour for 8 hours with zero data loss, zero phantom errors, zero "weird behavior."
- Every receipt prints correctly (total / change / TVA breakdown / QR / legal mentions / fiscal seal).
- Fiscal hash chain stays valid client + server.
- Sync drains a 100-receipt offline backlog within 60 s when network returns.
- Cash drawer variance is correct end-of-shift (post-PR #121 contract).

### Compliance
- Tunisia VAT / receipt-format rules satisfied.
- NF525 status: explicit decision — internal hardening only OR contractual claim. Documented either way.
- Audit log integrity (TimescaleDB events, fiscal events, no PII leakage to logs).

### Tauri device
- Native permissions are minimum-necessary (current state is over-permissive — see §2).
- Production build is signed (Windows / macOS / Linux depending on target). Decision documented.
- Backup / restore procedure for SQLite `.db` + `.db-wal` + `.db-shm` + Tauri Store `izipos-settings.json` + appdata images.
- **Updater: deferred for v1 of this client deploy.** Audit current state (declared in `package.json` but never initialized in `src-tauri/src/lib.rs`); track explicitly as a follow-up. v2 updater will call a Synerivia-hosted "latest version" endpoint and push notification when a newer version exists. The audit must document the v1 manual-update process (operator how-to + safety checklist).

### Operations
- Crash reports + structured telemetry shipping somewhere (§1 covers options-research).
- Sensitive data (tokens, PIN hashes, customer PII, voucher serials, receipt QR tokens) never written to console or logs.
- Operator-facing chain-break recovery is **documented** (PR C-style in-app UX deferred — see "Out of scope" — but the manual SQL escape hatch + reinstall procedure must be a written runbook the field operator can execute).

### Performance budget
- P95 cashier-facing operations under 200 ms.
- Catalog cold start under 3 s for 5 K SKUs.
- Sync 100-receipt backlog drain under 60 s.

---

## Audit structure: 16 sections in 5 blocks

### Block A — Foundation (audit these first; later sections depend on them)

- **§1. Observability & telemetry (options-research + current-state audit)**
- **§2. Tauri device security & native permissions**
- **§3. Build, deploy, install, manual-update procedure for v1**

### Block B — Compliance

- **§4. Fiscal hash chain (client + server v3 parity)**
- **§5. Tunisia compliance matrix (legal requirements + receipt fields + currency scale)**

### Block C — Functional surfaces

- **§6. Receipt rendering & printing (frontend builder + Rust ESC/POS + transports)**
- **§7. Cash drawer & shift management (post-PR #121 contract verification)**
- **§8. Payment flows (cash / card / split / voucher / loyalty / refund / void)**
- **§9. Sync reliability (post-PR #120 WAL + remaining surfaces)**
- **§10. Authentication, permissions, device threat model**
- **§11. UI/UX & ergonomics (incl. RTL + scripted smoke)**
- **§12. Multi-vertical (Otospex automotive vs IziPOS retail + Menu/FnB)**

### Block D — Quality

- **§13. Performance budget (measurement harness)**
- **§14. Resilience & crash safety (scripted scenarios)**
- **§15. Multi-tenant / company isolation (POS-specific paths)**

### Block E — Integration

- **§16. Cross-section flow audits (end-to-end scripted evidence)**

---

## §1. Observability & telemetry

**Why first:** Every later section produces stronger evidence if observability is in place. Even if we can't deploy a telemetry SDK in this pass, the audit defines the *target shape* and the *current gaps*.

### Current state to verify

- `apps/pos/src-tauri/src/lib.rs` — does it initialize `tauri_plugin_log`? Confirmed declared in deps; verify init + sink.
- `apps/pos/src/lib/errorLogging.ts` — `serializeErrorForLog` shape; does it redact sensitive fields?
- `apps/pos/src/lib/sync/coerceSyncError.ts` (PR #120) — applied at all `sync_error` write sites in `syncService.ts`. Verify the same coercion is used in non-sync error paths (bootstrap, payment, auth, terminal-activation).
- `apps/api/config/logging.php` — log channels, retention, Sentry integration if any.
- `apps/api/.../HandleExceptions` middleware — does it tag requests with terminal/operator IDs for correlation?
- Console logs — Tauri WebView's console output goes where in production? Currently it's only inspectable via devtools.

### Options-research (deliverable: a decision matrix)

| Option | Stack coverage | Cost | Data residency | Account model | Effort |
|---|---|---|---|---|---|
| **Sentry SaaS** (`@sentry/react` + `sentry-rust`) | Frontend + Rust + Laravel API | Free tier ≤ 5K errors/mo, paid tiers after | US or EU region | One Sentry org → one project per app (pos / api / web) → environments per company/terminal | Medium (3 SDK installs, release tagging, breadcrumb redaction) |
| **`tauri-plugin-sentry`** (community) | Same as Sentry SaaS, with Rust↔JS bridge | Free | Same | Same as above | Low (single plugin, auto Rust panic capture) |
| **GlitchTip** (self-hosted Sentry-compatible) | Same | Self-host infra cost | Wherever you host (e.g., your own TN/EU server) | Single org | High (self-hosting + maintenance) |
| **Highlight.io** | Full-stack + session replay | Pricier | US / EU | One workspace | Medium |
| **PostHog** | Product analytics + session replay (less crash-focused) | Generous free tier | EU available | One org | Medium |
| **Tauri-only logging** (no SaaS) | tauri-plugin-log → disk → manual cron upload to private S3 | Cheapest | You control | N/A | High (build the pipeline) |

### Recommendation (pre-decision)

Start with **`tauri-plugin-sentry`** wired to a Sentry SaaS EU project under whatever Synerivia org exists (or create one). Single org, one project per app, environments tagged per (tenant, company, terminal). Free tier suffices for one terminal. If a second org account is on the table — **don't**; environments + projects within one org are the right separation. Revisit cost after rollout.

Audit deliverable: confirm an org exists / decide create-new; pick frontend + Rust + API SDK; define the redaction list (token, PIN, customer PII, voucher serials, receipt QR tokens); define release tagging strategy.

### Questions to file as findings

1. Does `serializeErrorForLog` redact sensitive fields, or does it ship full payloads? Grep test.
2. Every catch block in `apps/pos/src` (not just `syncService.ts`) — does it use `coerceSyncError` or a similar non-`'Unknown error'` fallback? Repeat the PR #120 audit across `bootstrapStore`, `paymentStore`, `terminalStore`, `productStore`, `authStore`, `useTerminalActivation`, hooks, components.
3. Rust panics in `apps/pos/src-tauri/src/printing/*`, `commands/*`, `lib.rs` — do they propagate cleanly to JS or silently die? `panic = "abort"` vs `unwind`?
4. Server-side correlation IDs — does `apps/api` tag each sync request with a `terminal_id` + `idempotency_key`?
5. Log retention: `cleanupOldSyncLogs(7 days)` in SQLite. Server-side `daily.log` retention?
6. Console grep: search `apps/pos/src` for `console.log`, `console.warn`, `console.error` and verify no token / PIN / PII fields are interpolated. Same for Rust `println!` / `eprintln!` / `log::*`.

---

## §2. Tauri device security & native permissions

**Why care:** A POS terminal holds bearer tokens, encrypted PIN hashes, fiscal data, customer PII. The current capability set is over-permissive.

### Verified current state (Codex review confirmed)

- `apps/pos/src-tauri/capabilities/default.json` grants:
  - `core:default`, `core:event:default`, full `core:window:*` (set-fullscreen, set-decorations, set-always-on-top, set-skip-taskbar, set-position, set-size, set-focus, center, current-monitor)
  - `sql:default`, `sql:allow-execute`, `sql:allow-select`
  - `store:default`
  - `http:default` with allow list `[http://*:*/**, https://*:*/**, http://*/**, https://*/**]` — **all hosts, all ports**
  - `notification:default`, `os:default`, `window-state:default`, `log:default`
  - `fs:default` + `fs:allow-appdata-read/write-recursive` for `$APPDATA/images/**`
- `apps/pos/src-tauri/tauri.conf.json`: `"csp": null` (no Content-Security-Policy), asset protocol scope `["**"]`.

### Questions

1. Can the HTTP allow list be narrowed to the actual Synerivia API base URL(s) + the Reverb WebSocket origin? Cite the actual hosts.
2. Is `sql:allow-execute` necessary for app code, or could it be tightened to `sql:allow-select` for read-only contexts? (Probably not — migrations need execute — but documented.)
3. Is `os:default` and its bundled commands actually used? Audit the call sites; remove unused.
4. `csp: null` — what CSP would Tauri's WebView accept and not break Echo/Reverb WS + the API fetches? Propose a strict-but-functional CSP.
5. Asset protocol scope `["**"]` — narrow to `$APPDATA/images/**` (and any other specifically-needed paths).
6. AES-encrypted token at rest (`apps/pos/src-tauri/src/commands/crypto.rs` + `apps/pos/src/lib/storage.ts`) — key derivation, key storage location, salt, rotation policy?
7. PIN hashes: synced from server for offline validation. Hash algo + salt + comparison-time-safety (constant-time)?
8. Window-state plugin — does it persist coordinates that could leak operator habits? Probably fine but document.
9. Customer-display window scope — does it share storage / IPC with the main window? Any cross-window data leak?
10. Tauri-side `invoke` commands — every command exposed in `lib.rs` and `commands/*` — enumerate, audit each for arg validation + caller authorization.

### Audit deliverable

A capability-tightening proposal: minimal viable `capabilities/default.json`, working CSP string, narrowed HTTP allow list, narrowed asset protocol scope. **Not an implementation** — a proposal. Implementation lands in a follow-up PR.

---

## §3. Build, deploy, install, manual-update for v1

**Why care:** This terminal will be physically installed in Tunisia. The build/install path needs to be reproducible by someone who isn't the original developer.

### Anchor files

- `apps/pos/src-tauri/tauri.conf.json` — bundle config, identifier, version, icons, bundle targets
- `apps/pos/src-tauri/Cargo.toml` — Rust deps, profile config
- `apps/pos/package.json` — JS deps, build scripts (`tauri build` etc.)
- `docs/superpowers/plans/2026-05-11-tunisia-customer-rollout.md` — existing rollout doc, 303 lines
- `apps/pos/src-tauri/src/lib.rs` — boot order, plugin init, updater status

### Questions

1. What OS does the TN terminal run? Linux ARM / x86_64? Windows? — anchor the bundle target.
2. `pnpm tauri build` — produces what artefact? Is the build reproducible (deterministic flags, lock-file pinned, Rust toolchain pinned in `rust-toolchain.toml`)?
3. Code signing: required for the target OS? If yes, certificate management (where stored, who renews)? If not, document.
4. First-install flow: terminal pairing token, initial sync of catalog, PIN setup. Walk through end-to-end against `tunisia-customer-rollout.md`.
5. Backup procedure: which files must be copied? (`izipos-${companyId}.db`, `.db-wal`, `.db-shm`, `izipos-settings.json`, encryption key file, appdata images). Where do they live on the target OS?
6. Restore procedure: re-pair terminal vs preserve identity?
7. **Manual update for v1 (no in-app updater):** documented step-by-step? Operator + Synerivia-side handoff? Rollback procedure if a manual update bricks the terminal?
8. **Updater follow-up (v2 of this client deploy):** the audit should produce a one-page spec for the future updater — endpoint shape (Synerivia "latest version" API), signature verification, rollback storage, notification UX.
9. Auto-start / kiosk mode: is the terminal configured to launch the POS on boot in fullscreen? Window decorations off? Audit `fullscreen.ts` + window settings.
10. Crash recovery on relaunch: does cold-start surface stranded receipts, in-flight checkouts, partial Z-reports? Cross-reference §14.

### Audit deliverable

Two documents:
1. **Operator runbook for manual install / update / restore.** Step-by-step, screenshots optional.
2. **Updater follow-up spec.** Out-of-scope for v1 deploy implementation; explicit follow-up plan.

---

## §4. Fiscal hash chain (client + server v3 parity)

### Anchor files (verified)

- `apps/pos/src/lib/fiscal/v3/` — v3 canonical JSON + hash service
- `apps/pos/src/lib/fiscal/hashService.ts` — `computeGenesisHash`, advance helpers
- `apps/pos/src/lib/db/repositories/terminalStateRepository.ts` — `upsertTerminalState`, `advanceHashChain`, regression guards
- `apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php`
- `apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/`
- `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php`
- `apps/api/app/Modules/Compliance/Commands/VerifyFiscalChainsCommand.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php` — server-side verification
- Golden fixtures: `apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/*.json`

### Questions

1. Run the v3 golden-hash fixtures end-to-end (TS computer + PHP computer). Both must produce identical hashes for every fixture.
2. `apps/api/.../VerifyFiscalChainsCommand.php` — does it run in CI? On schedule? What does it check?
3. Hash-chain regression guards: `advanceHashChain` rejects non-increasing writes. PR C's hypothetical `rewindTerminalChainForRecovery` would bypass this — verify the guards are tight enough that ONLY a documented bypass can rewind.
4. Z-report chain: `pullZChainState` semantics; can a Z-report be partially-synced and leave the chain in a divergent state similar to the receipt cascade?
5. Schema versioning: `fiscal_schema_version` column on `offline_receipts`. v2 vs v3 receipts coexist?
6. Audit trail: every chain mutation logged where?

### Audit deliverable

A parity report: TS ↔ PHP golden-hash diff. Any drift is `[BLOCKER]`.

---

## §5. Tunisia compliance matrix

**Why a dedicated section:** Codex's review flagged that v1 conflated FR NF525 with TN deployment. They are distinct legal regimes.

### Required matrix (the audit fills this in)

| Requirement | Source / authority | Current state | Gap | Owner |
|---|---|---|---|---|
| Currency scale (TND = 3 decimals) | Central Bank of Tunisia | `CurrencyScale` PHP + `currency.ts` TS — both honor TND=3 | Verify across **every** numeric column / display / receipt template / Z-report / refund / voucher | — |
| VAT rates (TN: 7%, 13%, 19% as of 2024) | TN DGI | TVA breakdown per tax rate in receipt + Z-report | Confirm rate set is current; confirm rounding is per-tax-rate, not aggregated | — |
| Receipt legal fields (TVA number, fiscal number, address, operator ID, terminal ID, receipt number format) | TN DGI | Currently rendered: TODO list | Confirm legal completeness | — |
| Tax identifier format (matricule fiscal) | TN DGI | TODO verify validation regex | — | — |
| Arabic RTL on printed receipt | Customer requirement | `apps/pos/src/locales` has `en`, `fr` only (no `ar`) | If Arabic is required, add locale + RTL layout + ESC/POS code-page selection (CP-864 / Win-1256) | — |
| Fiscal printer certification | TN DGI (if applicable) | Unknown | Determine if Tunisia mandates certified fiscal printers for some retail categories | — |
| E-invoicing / fiscal reporting mandate | TN DGI | Unknown | TN's "Elyssa" / national e-invoice system status as of 2026; applicability to retail POS | — |
| Receipt retention period | TN tax law | Unknown | Server-side `pos_receipts` retention policy; local SQLite cleanup vs eternal? | — |
| Z-report frequency mandate | TN DGI | Currently driver-initiated | Confirm allowable cadence | — |
| Hash chain / tamper-evidence | NF525 (FR baseline) + TN — equivalent? | v3 hash chain implemented | Decide: is NF525 an internal control, or a contractual claim against the TN deployment? | — |
| QR code on receipt | TN DGI | Currently emitted | Verify TN inspector-app compatibility (or document if there's no standard format) | — |
| Data residency | TN regulations + GDPR if EU customers | API hosted where? | Document hosting region; document customer PII flow | — |

### Audit deliverable

Filled matrix. Every "Gap" row that maps to a regulatory mandate becomes a `[BLOCKER]` or `[P1]` depending on go-live blocker status.

---

## §6. Receipt rendering & printing

### Anchor files (Codex-corrected)

- **Frontend builder:** `apps/pos/src/lib/buildReceiptData.ts`
- **Print orchestrator (frontend):** `apps/pos/src/lib/printing.ts`, `apps/pos/src/stores/printerStore.ts`
- **Rust ESC/POS:** `apps/pos/src-tauri/src/printing/escpos.rs`, `apps/pos/src-tauri/src/printing/receipt_template.rs`, `apps/pos/src-tauri/src/printing/voucher_ticket.rs`
- **Transports:** `apps/pos/src-tauri/src/printing/usb.rs`, `apps/pos/src-tauri/src/printing/network.rs`, `apps/pos/src-tauri/src/printing/windows.rs`
- **Print commands:** `apps/pos/src-tauri/src/commands/printing.rs`
- **Offline read-path:** `apps/pos/src/lib/offline/getOfflineReceiptForPrint.ts`
- **Customer-facing display:** `apps/pos/src/pages/CustomerDisplayPage.tsx` (not `components/` — Codex correction)
- **Success modal:** `apps/pos/src/components/pos/CheckoutSuccessModal.tsx`
- **Today's sales / reprint list:** `apps/pos/src/components/pos/TodaySalesPanel.tsx`
- **Backend print audit:** `apps/api/app/Modules/POS/Application/Services/ReceiptPrintAuditService.php`, table `pos_receipt_prints`
- **Sync print log:** TODO in `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php` (offline print logs not yet synced)

### Questions

1. **Golden-render harness:** add a snapshot test that takes a seeded `offline_receipts` row and renders both the React preview AND the Rust ESC/POS byte stream. Diff against checked-in fixtures for 58 mm + 80 mm × cash exact / cash over-tender / cash tolerance short-pay / card / split / voucher (store + restaurant + gift) / refund / training-mode × EUR / TND.
2. **Post-PR #121 verification:** for an over-tender sale, the printed "Monnaie rendue" line matches `Σ(payments.amount) − total = tendered − total`. Smoke + golden test.
3. **Reprint distinguishability:** NF525 requires originals vs duplicates to be visually distinct. Audit `ReceiptPrintAuditService` writes + the printed banner.
4. **Offline print-log sync gap:** `pos_receipt_prints` writes are server-side only; offline reprints not yet pushed to server. Codex flagged this. File as `[P1]` until designed.
5. **Printer disconnect mid-print:** transport reports failure how? Receipt re-queue? Cashier UX?
6. **Code-page selection:** Rust builder defaults to Win-1252. Arabic needs CP-864 or Win-1256. Audit the selection + lossy-replacement behavior. RTL bidi shaping required.
7. **Column-width calculation** for multi-byte / non-Latin characters — measured in bytes vs grapheme clusters? Right-align logic correct?
8. **Customer display sync** — page-renders mirror cashier UI? Race conditions during processing?
9. **QR code rendering:** ESC/POS QR command vs raster image fallback? Quiet-zone size? Module size?
10. **Voucher ticket** path (`voucher_ticket.rs`) — separate template; audit fields + customer-facing language.

---

## §7. Cash drawer & shift management (post-PR #121)

### Anchor files

- `apps/pos/src/lib/offline/zReportService.ts`
- `apps/pos/src/lib/offline/endOfDayPreview.ts`
- `apps/pos/src/stores/cashDrawerStore.ts`
- `apps/pos/src/lib/db/repositories/cashDrawerRepository.ts`
- `apps/pos/src/components/settings/CashDrawerSettings.tsx`
- `apps/pos/src-tauri/src/commands/printing.rs` (`open_cash_drawer` ESC/POS command)
- `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php` (`buildExpectedPerMethod` — the variance formula)
- `apps/api/app/Modules/POS/Application/Services/CashDrawerService.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/CashDrawerController.php`
- `apps/api/tests/Feature/POS/CashCountToleranceVarianceRegressionTest.php` (the contract for `expected_cash`)

### Questions

1. **Smoke-verify the post-PR #121 contract:** open shift → ring 5 sales (mix of exact / over-tender / tolerance short-pay) → close shift → expected cash matches physical drawer. Document the exact arithmetic on a paper worksheet, then compare against the Z-report.
2. **Every cash-affecting event** is counted exactly once in `expected_cash`: opening, cash sale (tendered), change_due (subtract), refund/void (subtract net cash entering at sale time per PR #121 formula), deposit, payout, tolerance write-off (NOT counted — GL only). Build the matrix.
3. **Physical drawer kick** — `open_cash_drawer` ESC/POS pulse. Duplicate kicks (e.g., cashier hits "open" twice)? Drawer-open audit separation from sale recording (`'OPENING'` vs `'SALE'` types — and `'SALE'` is currently dead code; decide delete vs wire).
4. **Z-report contents:** every cash-count input, variance row, severity, tolerance summary. Match against `CashCountToleranceVarianceRegressionTest` golden.
5. **Manager override** for variance overrides — flow audit.
6. **Shift close** if a receipt is still pending sync — what happens? Z-report captures local-only state?

---

## §8. Payment flows

### Anchor files (Codex-corrected)

- `apps/pos/src/stores/paymentStore.ts` (`processCashCheckout` — post-PR #121, `processCardCheckout`, `processAdvancedSplit`)
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

1. **Internal contract consistency post-PR #121:** cash quick-path stores `amount = tendered`. Split-path already did. Card-path stores `amount = cart total` (card has no over-tender concept). All three branches consistent with `CashCountToleranceVarianceRegressionTest`.
2. **Voucher matrix:** store voucher (with serial + ledger writeback) vs restaurant voucher vs gift card. For each: supported / unsupported, user-facing flow, fiscal hash fields, sync behavior, GL impact, refund behavior.
3. **Card processing:** is the card path "manual external terminal" (cashier enters auth code) or integrated (terminal does the charge)? Audit `CardPaymentModal`. If manual: document the reconciliation flow (mismatched approvals).
4. **Refund / void differentiation:** full receipt void via `ReceiptVoidService` (PR #121 verified) vs partial line refund via `refundDraftStore` / `ReceiptReturnService`. Both paths preserve fiscal integrity?
5. **Loyalty redemption** — points-as-tender vs points-as-discount; which is implemented? Where does it land in the variance formula?
6. **Mixed-currency payments** — out of scope, but verify no path silently produces them.
7. **Split-payment edge cases:** all-voucher / all-card / cash + voucher + card. Behavior on partial failure (one tender approved, another fails).

---

## §9. Sync reliability (post-PR #120)

### Anchor files

- `apps/pos/src/lib/sync/syncService.ts` — push: receipts, Z-reports, cash-drawer ops, voucher ledger, PIN updates; pull: products, payment config, operators, terminal state, Z-chain state, tables, active menu, vouchers, voucher ledger, receipt QR index
- `apps/pos/src/lib/sync/syncScheduler.ts`
- `apps/pos/src/lib/sync/coerceSyncError.ts` (PR #120)
- `apps/pos/src/stores/syncStore.ts`
- `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts`
- `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php` (note the offline-print-log TODO)
- `apps/api/.../VoucherSyncController.php`, `ZReportSyncController.php`

### Questions

1. **Batch-of-one TODO (PR #120 carryover):** sync loops one receipt per round-trip even though `ReceiptSyncService` accepts batches. Measure backlog drain for N=100. Determine the threshold above which batch-N is required for go-live.
2. **Build the sync-flow matrix:** every sync verb × every failure mode (network timeout, server 4xx, server 5xx, hash mismatch, idempotency duplicate, lock retry) × handled-or-not.
3. **Offline print-log sync gap:** `pos_receipt_prints` writes server-side only; offline reprints not pushed. Codex `[P1]`.
4. **Stranded-receipt UX:** rows at `retry_count >= 5` for a non-lock reason (the v32 migration + recovery hook only covers lock signature). What does the cashier see? Is there an operator inbox?
5. **Z-report chain** — separate from receipt chain. Same break-failure modes? Recovery?
6. **PIN-update sync** — when an operator's PIN changes server-side, polling cadence to terminal? WS-pushed?
7. **WebSocket-vs-polling fallback** — PR #122's catalog channel. Verify the 60 s polling tick still picks up changes when WS is down.
8. **Background vs foreground sync** — race between user-initiated pull and scheduled push?
9. **`computeDegraded` triggers** — what flips the degraded banner? When does it clear?

---

## §10. Authentication, permissions, device threat model

### Anchor files

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
- `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php` (Codex-corrected path)
- **Memory note (inline):** `feedback_usePermissions_hardcoded_map.md` — POS terminal permissions are checked server-side (route middleware), but the web admin's `apps/web/src/hooks/usePermissions.ts` uses a hardcoded ROLE_PERMISSIONS map; custom Spatie roles get silently denied on the web admin frontend. **This audit is POS-specific.** Verify POS permission rendering doesn't have the same hardcoded gap.

### Questions

1. **Device threat model.** Stolen / lost terminal. What's recoverable from the local SQLite + Tauri Store + appdata? AES key derivation + storage: where's the key, can it be extracted from disk?
2. **Token lifetime + revocation.** Sanctum token expiration. Server-side revocation flow (e.g., re-pair terminal, deactivate user). Does the terminal honor revocation?
3. **PIN hash sync** — algo (bcrypt? argon2?), salt, comparison time-safety. Offline PIN validation: how does the terminal stay current when the server changes a PIN?
4. **Manager-PIN modal.** Validation path: local against synced hash, or server round-trip? What if the terminal is offline and a manager-only action is required?
5. **Discount permissions** chain — `user.can_discount` + terminal `max_discount_percent`. Memory note `project_discount_permissions.md` says "what's built vs what's needed". Audit current state.
6. **Operator auto-lock** activity timer — configurable? Reasonable defaults? Does the lock screen preserve cart state?
7. **Terminal pairing** — first-time pair flow, re-pair flow, server-side revocation of pairing tokens.
8. **Logout cleanup** post-PR #122's `peekEcho` fix — verify the whole logout path: stores reset, WS disconnected (peekEcho prevents lazy re-create), cart cleared, no token residue.
9. **`tenant:<uuid>` ability check** in `EnforceTokenTenantClaim.php` — verify every sync request validates.

---

## §11. UI/UX & ergonomics

### Anchor files (Codex-corrected)

- `apps/pos/src/pages/HomePage.tsx` — cashier main screen
- `apps/pos/src/components/pos/*` (older surface) AND `apps/pos/src/components/organisms/*` (newer surface) — **both exist; audit must cover both and identify duplicates**
- `apps/pos/src/lib/i18n.ts` (NOT `src/i18n.ts` — Codex correction)
- `apps/pos/src/locales/` (currently `en`, `fr` only — add `ar` if RTL required)
- `apps/pos/src/hooks/useBarcodeScanner.ts`
- `apps/pos/src/lib/scan/resolveScannedCode.ts`

### Questions (with scripted-smoke deliverable)

Add a Playwright suite (`apps/pos/e2e/`) that mounts the production build (or `pnpm tauri dev` if production build infra not ready) and exercises:

1. **Touch targets** — measure every button on `HomePage` at 1280×800 (tablet) and 1920×1080 (counter). Minimum 44×44 pt.
2. **Modals never resize** (per `feedback_modal_fixed_size.md`).
3. **Keyboard shortcuts** — Enter / Escape semantics in cash-tender modal, numpad behavior, scanner-focus stuck-recovery (scanner sends Tab/Enter).
4. **Offline banners** — distinct for "WS down" vs "API down" vs "fully offline."
5. **Training-mode banner** visible + receipts marked.
6. **Hardcoded strings** — `pnpm tauri build` + grep the output for any user-facing strings that bypass `t()`. The previous tenant-isolation sweep covered the web app; verify POS.
7. **RTL** — IF Arabic is required (per §5 matrix decision), audit layout direction support.

---

## §12. Multi-vertical (Otospex automotive vs IziPOS retail + Menu/FnB)

### Anchor files

- `apps/pos/src/stores/terminalStore.ts` — vertical detection
- `apps/pos/src/stores/productStore.ts` — Menu vs standard-retail fetch branches
- `apps/api/app/Modules/Product/` — standard product CRUD
- `apps/api/app/Modules/Catalog/` — composite items + modifiers (Menu tenants)
- `apps/api/app/Modules/Menu/` — menu structure, MenuCategory, MenuCategoryItem
- `apps/api/.../ActiveMenuController.php` — POS-facing menu fetch
- `apps/api/.../productApi` — POS-facing product fetch
- **Memory notes (inline):**
  - `project_otospex_pos.md`: Otospex automotive POS — 85% infra exists, workshop module needed, table-based UX. Vehicle/customer lookup deferred.
  - `project_otospex_brand.md`: canonical brand spelling = "Otospex" (NOT "OtospEx" or variants). Theme: pink. Tunisia deployment.
  - `project_refund_flow_phases.md`: Phase 1 = standard-retail (IziPOS); Phase 2 = automotive (Otospex). Customer lookup by phone/email/loyalty is Phase 1 deferred work.

### Questions

1. **Cross-vertical leak check** — does a Menu tenant ever see a standard-retail product (or vice versa)? Tenant-isolation sweep covered this; re-verify POS paths.
2. **Catalog matrix:**
   - Standard retail: `/api/v1/products` returns the company's products. POS bulk-pulls and caches.
   - Menu (FnB): `/api/v1/active-menu` returns the active menu with categories → items (composite items + modifiers). POS reconciles via `reconcileMenuProducts`.
   - Composite IDs: a sellable in two categories is two distinct rows in POS SQLite (C2 work). Verify post-merge.
   - Modifiers: serialized into active-menu. PR #122 broadcasts cover ModifierGroup + Modifier mutations.
   - Price/tax inheritance from CompositeItem to MenuCategoryItem override.
   - Stock availability: `is_available` per MenuCategoryItem.
3. **Theming:** Otospex pink vs IziPOS copper — per-company config? Where does the theme switch?
4. **Workshop integration (Otospex, deferred):** out of scope for this TN client (per `project_otospex_pos.md` — workshop module needed). Audit current stub state, document the gap.
5. **Vertical detection:** what flag determines IziPOS vs Otospex behavior? Per company? Per terminal? UX gates?

---

## §13. Performance budget (measurement harness)

### Why a harness, not just file reading

Codex correctly flagged that P95 / cold-start / drain-time cannot be answered by code reading. This section requires:

1. **Seeded SQLite fixture** — 5 K product rows representing realistic catalog shapes (with images / barcodes / SKUs / prices / tax rates).
2. **Scripted UI actions** — Playwright clicks driving the cashier flow.
3. **Timer hooks** — temporary `performance.mark` / `performance.measure` calls (removed after audit, no production cost).
4. **SQLite EXPLAIN QUERY PLAN** — for every hot query identified in `productRepository.ts`, `offlineReceiptRepository.ts`, `cashDrawerRepository.ts`, `voucherRepository.ts`.

### Anchor files

- `apps/pos/src/stores/productStore.ts` — foreground/background fetch
- `apps/pos/src/lib/db/repositories/productRepository.ts` — SQLite queries
- `apps/pos/src/lib/db/migrations.ts` — indexes
- `apps/pos/src/lib/images/imageCache.ts` — file I/O + eviction
- `apps/pos/src/lib/scan/resolveScannedCode.ts` — barcode lookup path
- `apps/pos/src/lib/sync/syncScheduler.ts` — sync cadence
- `apps/pos/src/lib/sync/syncService.ts` — push/pull
- `apps/pos/src-tauri/src/printing/network.rs` — printer discovery network scan
- `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php` — catalog payload shape
- **Memory note (inline):** `project_pos_performance.md` says SyncScheduler was "never instantiated" but recent code DOES instantiate + start it from `terminalStore.ts`. **Reconcile memory-note claims against current code; do not trust the note as authoritative.**

### Questions

1. Catalog cold start (P50, P95): from process spawn to first interactive frame with 5K products visible.
2. Barcode-scan latency (P50, P95): scanner sends → cart line added.
3. Cart-add latency: tap product → line appears + total updates.
4. Cash-checkout latency: cashier hits OK → success modal.
5. Sync 100-receipt backlog drain time.
6. 8-hour shift memory profile: RSS at start, mid, end. Look for leaks.
7. Image-cache eviction: triggers, size limit, working under steady-state.
8. EXPLAIN QUERY PLAN for every hot query — any seq-scans without WHERE indexes?
9. Tauri bundle size — what does `pnpm tauri build` produce? Acceptable for the target hardware?
10. SQLite WAL checkpoint behavior — auto-checkpoint cadence, manual checkpoint on shutdown?

### Audit deliverable

A measurement-results doc with P50/P95 numbers per scenario. Findings tagged `[BLOCKER]` for any metric > budget.

---

## §14. Resilience & crash safety (scripted scenarios)

### Required scripted tests

1. **Kill app mid-checkout** (right after `BEGIN`, after `INSERT offline_receipts`, after `advanceHashChain`, before `COMMIT`). Restart; verify state.
2. **Kill app mid-sync** (after server has accepted but before client marks `'synced'`). Restart; verify dedup catches the re-push as 'duplicate'.
3. **Kill app mid-Z-report.**
4. **Kill app mid-print.**
5. **Kill app during company switch** (SQLite singleton swap).
6. **Power loss** simulation — `kill -9` on the SQLite shutdown path. WAL must preserve durability.
7. **SQLite corruption** — inject a corrupt page; verify detection + cashier-facing message + recovery procedure.
8. **App update** — for v1, document the manual swap procedure preserves SQLite + WAL sidecars + Tauri Store + appdata images + encryption key.

### Anchor files

- `apps/pos/src/lib/db.ts` (post-PR #120 WAL + recovery hook)
- `apps/pos/src/stores/bootstrapStore.ts`
- `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts` — `markStuckReceiptsAsFailed`-type recovery on cold start
- `apps/pos/src/lib/db/repositories/refundDraftRepository.ts` — partial-refund draft recovery
- `apps/pos/src-tauri/src/lib.rs` — boot order, shutdown handlers

### Audit deliverable

A scripted-recovery results doc. Each scenario: pre-state, kill point, post-state expectation, observed post-state, pass/fail.

---

## §15. Multi-tenant / company isolation (POS-specific)

**In scope:** any tenant-leak in POS paths. **Out of scope:** the broader tenant-isolation sweep tracking (separate workstream).

### Anchor files

- `apps/pos/src/stores/authStore.ts` — `tenantId` + `companyId` resolution; persists token / user / companyId / companies / terminal in `izipos-settings.json` (one shared Tauri Store file)
- `apps/pos/src/stores/productStore.ts` — catalog scope
- `apps/pos/src/lib/db.ts` — per-company SQLite file naming `izipos-${companyId}.db`
- `apps/pos/src/lib/storage.ts` — Tauri Store wrapper
- `apps/pos/src/lib/echo.ts` — Echo client, per-tenant channels
- `apps/pos/src/hooks/useCatalogChannel.ts` (PR #122)
- `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php`
- `apps/api/routes/channels.php` — channel auth (`canAccessCompanyChannel`)

### Questions

1. **Company switch flow:** SQLite singleton closes + reopens correct `.db`. Echo channel re-subscribes correct private channel. `izipos-settings.json` updates `COMPANY_ID`. PR #120's `runStuckReceiptRecovery` does NOT cross companies.
2. **Cached state per store** — `productStore` / `paymentStore` / `terminalStore` / `cartStore` — all clear on company switch?
3. **Tauri Store isolation** — single `izipos-settings.json` file holds token + user + multiple companies. Is anything tenant-leak-vulnerable in there? E.g., a stale `lastReceiptIdempotencyKey` from previous company?
4. **WebSocket channel auth** rejects cross-tenant subscriptions — verify against the broker.
5. **API request auth** — every POS-facing endpoint enforces `tenant:<uuid>` ability + active UserCompanyMembership.

---

## §16. Cross-section flow audits (end-to-end scripted evidence)

**Why a dedicated section:** Codex correctly flagged that 12 isolated subagents miss the flows that cut across boundaries.

### Required flows (each gets its own end-to-end script + findings entry)

1. **Cash sale → print → sync → server receipt → Z-report → fiscal chain → analytics.** One receipt, traced through every layer. Confirm every artefact matches.
2. **Refund → voucher writeback → receipt QR → sync.** Voucher partial-redemption ledger. Refund preserves chain.
3. **Company switch → SQLite swap → Tauri Store update → Echo re-subscribe → productStore re-fetch.** No stale state.
4. **Offline period → backlog accumulation → connectivity returns → backlog drain → dead-letter UX.** Including the post-PR #120 recovery hook self-heal scenario.
5. **Receipt void (PR #121 verified) end-to-end.** Mark voided → reverse stock → batch allocation reversal → cash-drawer REFUND with the new universal formula → server-side ledger.
6. **Catalog mutation in admin → Reverb broadcast → POS receives → debounce → fetchProducts → POS reflects.** Cover Product, Menu, MenuCategory, MenuCategoryItem (incl. bulk delete), CompositeItem, ModifierGroup, Modifier, pivot writes (PR #122 covers each).
7. **PIN change in admin → queued update → POS pull → operator can authenticate offline with new PIN.**
8. **Z-report → fiscal chain → server-side sync → analytics report.** Z-chain integrity.

### Audit deliverable

A flow-audit results doc with one section per flow, each containing: steps executed, expected artefacts, observed artefacts, diffs, findings.

---

## Execution model

**This is NOT 16 isolated read-only subagents.** It's a structured multi-phase audit:

### Phase 1 — Inventory (read-only, low cost)
- Walk the worktree against every section's anchor list. Verify paths exist.
- For each section, produce a short "current-state snapshot" (200–500 words) — what's there, what's missing.
- This phase produces no findings yet; it surfaces gaps in the plan itself.

### Phase 2 — Static-analysis findings (read-only)
- Per section, walk the anchor files + cross-references.
- File findings as `[BLOCKER] / [P1] / [P2] / [NIT]`.
- For questions answerable statically (anchor reviews, hardcoded-string grep, capability audit, options-research), this is the entire deliverable.

### Phase 3 — Measurement scaffolding (light implementation, evidence-only)
- Build the Playwright e2e harness (§11, §13, §14, §16).
- Build the seeded SQLite fixture (§13).
- Build the golden-render harness (§6).
- These scaffoldings ARE implemented (in the audit branch, not merged) because they produce evidence the static phase can't.

### Phase 4 — Measured findings
- Run the scripted scenarios. Capture P50/P95, RSS, recovery state.
- File evidence-based findings.

### Phase 5 — Cross-section flow audits (§16)
- Run the 8 end-to-end flows.
- File flow-level findings.

### Phase 6 — Aggregation
- Single `findings.md` with everything tagged + ordered by severity.
- Per `[BLOCKER]` and `[P1]`: a one-paragraph fix plan + estimate.
- Hand to Opus + the user for prioritization.

**No code-level fixes in any phase.** Fixes ship in follow-up PRs.

---

## What v2 explicitly puts IN scope (vs v1 categorical exclusions)

- **Operator-facing chain-break recovery runbook.** PR C in-app UX implementation stays out of scope; the operational recovery procedure (manual SQL, when to reinstall, who performs it) is now an audit deliverable. Codex `[P1]` closure.
- **POS-specific tenant-isolation findings.** Even though the broader sweep is a separate workstream, any POS-path tenant leak surfaced during this audit is a finding. Codex `[P1]` closure.
- **Tauri device security, build, deploy, manual-update procedure.** Codex `[BLOCKER]` closures.
- **Telemetry options-research + recommendation.** Codex `[BLOCKER]` closure.
- **Tunisia compliance matrix with TN-specific enumeration.** Codex `[P1]` closure.
- **Memory-note quotes inline** (full path: `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/`). Codex `[P1]` closure.
- **Cross-section integration flow audits (§16).** Codex `[P1]` closure.
- **Measurement harness for perf + resilience.** Codex `[P1]` closure.

## What v2 keeps OUT of scope

- **PR C in-app chain-break recovery implementation** — operational runbook IS in scope; in-app UX is deferred until post-WAL chain-break is observed in production.
- **In-app updater implementation** — current state audit + future-spec ARE in scope; building the v2 updater is post-this-client-deploy.
- **Code-level fixes** — strictly an audit pass.
- **The platform repos** (`apps/platform`, `apps/platform-ml`, `apps/erp-ml`) — separate audit if/when relevant.
- **Web admin tenant-isolation follow-ups** — POS slice is in scope here; web slice is the existing sweep workstream.

---

## Glossary of cross-references

| Identifier | Meaning |
|---|---|
| **PR #120** | `fix(pos): SQLite WAL + stuck-receipt recovery + sync_error preservation` — merged to `dev` 2026-05-11. Fixed Bug 5 + cascade (Bugs 3, 4). |
| **PR #121** | `fix(pos): cash payment amount = tendered + void over-refund fix` — merged to `dev` 2026-05-11. Fixed Bug 2 + introduced the universal cash-refund formula in `ReceiptVoidService`. |
| **PR #122** | `feat(pos): real-time catalog refresh via WebSocket` — merged to `dev` 2026-05-11. Fixed Bug 1 (catalog 60s polling latency). 6-round Codex closure. |
| **PR C (deferred)** | In-app chain-break recovery UX — not built; operational runbook is in scope here. |
| **Tunisia rollout doc** | `docs/superpowers/plans/2026-05-11-tunisia-customer-rollout.md` (303 lines) — existing rollout doc. v2 audit cross-references it in §3 + §5. |
| **Memory-note root** | `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/` |

**No "PR A/B/D" shorthand from this doc forward.** PR numbers only.
