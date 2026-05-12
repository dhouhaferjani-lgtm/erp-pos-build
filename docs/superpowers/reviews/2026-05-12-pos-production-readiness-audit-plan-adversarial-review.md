# Adversarial Review: POS Production-Readiness Audit Plan

Plan reviewed: `docs/superpowers/plans/2026-05-11-pos-production-readiness-audit-plan.md`

Scope spot-checks covered `apps/pos/src`, `apps/pos/src-tauri`, `apps/api/app/Modules/POS`, `apps/api/app/Modules/Menu`, `apps/api/app/Modules/Catalog`, `apps/api/app/Modules/Product`, shared compliance/currency helpers, rollout docs, and the cited Claude project memory notes under `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/`.

## §0. Production-Ready Definition / Overall Shape

- [BLOCKER] The plan defines production readiness almost entirely as cashier workflow + fiscal + sync, but the actual deliverable is a Tauri desktop binary on a physical Tunisian device. It omits release/installation readiness: signed/notarized installer, OS target, rollback binary, backup/restore of SQLite + `-wal` + `-shm`, device provisioning, local app-data permissions, auto-start/fullscreen policy, and support access. The repo has a Tauri bundle (`apps/pos/src-tauri/tauri.conf.json`) and rollout doc (`docs/superpowers/plans/2026-05-11-tunisia-customer-rollout.md`) that explicitly mention build, code signing, delivery, and first-48h monitoring; those need to be first-class audit sections, not incidental smoke work.

- [BLOCKER] The plan does not audit the Tauri security model despite shipping broad native permissions. `apps/pos/src-tauri/capabilities/default.json` grants broad HTTP access, `fs:default`, unrestricted appdata image access, SQL execute/select, OS, notification, log, store, and window-state permissions; `tauri.conf.json` has `csp: null` and asset protocol scope `["**"]`. That is a production gate for a desktop POS handling tokens, PIN hashes, customer data, local fiscal data, and receipts.

- [P1] The definition is missing data lifecycle and privacy requirements for a single-tenant Tunisia deployment: customer PII retention, receipt lookup index retention, local SQLite encryption/backups, incident log export, GDPR-style export/delete boundaries if EU customers are ever in the tenant, and Tunisia/data-residency hosting assumptions. "Sensitive data never leaks to console" is too narrow.

- [P1] Tunisia readiness is under-specified. The plan names TN fiscal but does not enumerate TN gates: TND scale 3 end-to-end, FR/TN locale defaults, Arabic RTL if required by customer, Tunisian VAT defaults/rates, tax identifier format, receipt legal fields for Tunisia, fiscal printer/certification obligations if any, e-invoicing/fiscal reporting mandates, and whether NF525 is only internal hardening or a contractual claim. `CurrencyScale` and POS `currency.ts` support TND=3, but the audit plan should force verification across print, Z-report, sync, refund, voucher, and server PDF paths.

- [P1] The execution model says every section can be read-only. Several questions cannot be answered credibly that way: P95 latency, 8-hour memory growth, printer disconnect behavior, QR scannability, SQLite `EXPLAIN QUERY PLAN`, crash/power-loss recovery, installer/update behavior, and multi-monitor customer-display behavior all require instrumentation or hardware/scripted smoke tests.

- [P2] The 12-section carving is generally useful, but it mixes concerns unevenly. "Observability" should precede and inform resilience/performance; "Tauri device operations/security/update/deploy" should be its own section; "catalog/menu/product data plane" deserves its own section instead of being split between perf, sync, and multi-vertical; and "compliance" should separate legal requirements from hash-chain implementation.

## §1. Receipt Rendering & Printing

- [BLOCKER] Two anchor paths are stale/wrong. The plan points to `apps/pos/src/lib/escpos/`, but ESC/POS lives under `apps/pos/src-tauri/src/printing/` (`escpos.rs`, `receipt_template.rs`, `voucher_ticket.rs`, transport modules). It also points to `apps/pos/src/components/CustomerDisplayPage.tsx`, but the actual file is `apps/pos/src/pages/CustomerDisplayPage.tsx`. A read-only subagent following the plan will miss the native formatter and customer display entrypoint.

- [P1] The section misses the backend print audit gap that is visible in `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php`: offline print logs are still a TODO. The plan asks whether reprints are distinguishable, but it does not force auditing the offline ESC/POS print-log path against server `ReceiptPrintAuditService` / `pos_receipt_prints`.

- [P1] The "printed ticket exactly matches preview" question is not answerable as written because the plan does not identify a preview component or golden-render harness. It should require byte/golden tests for `buildReceiptData.ts` -> Rust `receipt_template.rs`, plus printer snapshots for 58mm/80mm and TND/EUR scales.

- [P1] Arabic/RTL is framed as "does ESC/POS handle CP-857 / CP-864", but the actual Rust builder defaults to Windows-1252 and only switches through print settings. The question should explicitly cover code-page selection, bidi shaping, lossy character replacement, column-width calculation for non-Latin text, and whether Arabic is even in `apps/pos/src/locales` (only `en`/`fr` were present in this checkout).

- [P2] The plan should anchor `apps/pos/src-tauri/src/commands/printing.rs`, `apps/pos/src-tauri/src/printing/receipt_template.rs`, `apps/pos/src-tauri/src/printing/voucher_ticket.rs`, `apps/pos/src/stores/printerStore.ts`, `apps/pos/src/components/pos/CheckoutSuccessModal.tsx`, `apps/pos/src/components/pos/TodaySalesPanel.tsx`, and `apps/api/app/Modules/POS/Application/Services/ReceiptPrintAuditService.php`.

## §2. Performance Budget

- [P1] The plan asks for P50/P95 metrics but its proposed "read-only Explore subagent" cannot produce them. It should require a reproducible measurement harness: seeded 5K SKU SQLite DB, scripted UI actions, `pnpm tauri dev` or production build timing, sync backlog fixture, and captured metrics in the audit output.

- [P1] Performance should explicitly include Rust/native bottlenecks: printer discovery network scan, image-cache file I/O, customer display event throughput, Tauri HTTP plugin timeouts, and SQLite WAL checkpoint behavior. The current anchors only cover frontend stores and repositories.

- [P2] The plan should anchor `apps/pos/src/lib/images/imageCache.ts`, `apps/pos/src/lib/scan/resolveScannedCode.ts`, `apps/pos/src/hooks/useBarcodeScanner.ts`, `apps/pos/src/lib/db/migrations.ts` indexes, `apps/pos/src-tauri/src/printing/network.rs`, and `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php` for catalog payload shape.

- [P2] The order should move observability/instrumentation before performance, or make §2 explicitly responsible for adding/running temporary probes. Without timing hooks, this section will likely produce subjective "looks fast" findings.

- [P2] `project_pos_performance.md` is real and the plan's summary is directionally accurate, but the note is historical and partly stale: it says `SyncScheduler` is never instantiated, while current code instantiates and starts it in `apps/pos/src/stores/terminalStore.ts`. The audit prompt should tell subagents to reconcile memory-note claims against current code rather than treating the memory as authoritative.

## §3. Fiscal Compliance & Hash Chain

- [P1] The anchor `apps/api/.../FiscalCompliance/` is not a real path in this checkout. NF525-related code is under `apps/api/app/Modules/Compliance/Services/Nf525`, shared compliance DTOs, `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php`, and POS fiscal services. TN country fiscal-year rules are in `apps/api/app/Modules/Company/Application/Services/CountryFiscalRulesProvider.php`, not a POS FiscalCompliance tree.

- [P1] The section conflates "France NF525 baseline" with "Tunisia deployment" without requiring a source-of-truth legal matrix. It should require a TN compliance matrix with owner/source/date, and explicitly label NF525 as internal control unless the business is certifying against it.

- [P1] The plan asks whether QR codes are "scannable by Tunisian inspector apps" but provides no required data format, app name, sample payload, or legal source. This will lead to hand-wavy answers. Require concrete QR payload specs and physical scan evidence, or mark it as an unknown legal dependency.

- [P1] Fiscal audit should include server/client parity across TS and PHP v3 hash fixtures, but also offline-to-server failure behavior: what happens when a receipt is locally sealed and the server rejects schema version/hash, and whether operator recovery preserves a legal audit trail.

- [P2] Anchor additions: `apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php`, `apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/*`, `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php`, `apps/api/app/Modules/Compliance/Commands/VerifyFiscalChainsCommand.php`, `apps/api/app/Modules/Compliance/Services/Nf525/*`, `apps/api/app/Shared/Domain/CurrencyScale.php`, and `apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php`.

## §4. Sync Reliability

- [P1] The plan correctly spots the batch-of-one backlog risk, but it should require a measured backlog drain test because `ReceiptSyncService` already accepts batch payloads while `apps/pos/src/lib/sync/syncService.ts` still loops one receipt per request. Without timing data, "acceptable" is not answerable.

- [P1] The section omits offline print-log sync even though the backend has a visible TODO in `SyncController.php`. For NF525-style audit, printed/reprinted offline receipts are part of sync reliability, not just receipt rows.

- [P1] The plan should include local dead-letter operator/admin UX as a required sync question. `offlineReceiptRepository.ts` has a TODO for stranded receipts at `retry_count >= 5`; §10 mentions it, but §4 is the place to build the sync-flow matrix.

- [P2] The sync-flow matrix should include catalog/menu/product WebSocket invalidation and fallback pulls. After PR #122/C2 work, product/menu data correctness is a sync concern, not just a multi-vertical concern.

## §5. Multi-Tenant / Company / Vertical Isolation

- [P1] The anchor `apps/api/.../middleware/EnforceTokenTenantClaim.php` is stale. The actual file is `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php`.

- [P1] The plan points to `apps/erp/.claude/context/architecture.md`, which is not present in this checkout. If that context is required, the audit must say where to obtain it or quote the relevant rules directly.

- [P1] The local SQLite naming question is good, but the audit should explicitly cover Tauri Store keys too. `authStore.ts` persists `TOKEN`, `USER`, `COMPANY_ID`, `COMPANIES`, `TERMINAL`, and pending terminal IDs in one `izipos-settings.json`; company switch isolation is not only `izipos-${companyId}.db`.

- [P2] Add anchors for `apps/pos/src/lib/storage.ts`, `apps/pos/src/lib/echo.ts`, `apps/pos/src/hooks/useCatalogChannel.ts`, `apps/api/app/Modules/POS/Infrastructure/Broadcasting/CatalogChannelEvent.php`, and API route/channel authorization files.

## §6. Authentication & Permissions

- [P1] The anchor `apps/erp/docs/conventions/03-AUTHORIZATION.md` is missing from this checkout. Use actual local docs or quote the rule. Otherwise subagents cannot verify the referenced convention.

- [P2] `feedback_usePermissions_hardcoded_map.md` is real in the Claude project memory path and the plan quotes it accurately. The issue is accessibility and scope: the note is not repo-local, and the underlying code is `apps/web/src/hooks/usePermissions.ts`, not POS terminal enforcement. The plan should either include the full memory path or quote the relevant lines, and distinguish web-admin permission rendering from POS cashier authorization.

- [P1] The section should include token/device security and offline secret storage. POS auth stores the bearer token encrypted via a local AES key file (`apps/pos/src/lib/storage.ts`, `apps/pos/src-tauri/src/commands/crypto.rs`), and PIN hashes are synced for offline validation. Production readiness needs a threat model for stolen device, local appdata access, token lifetime, remote deactivation, and wiping/re-pairing.

- [P2] Add anchors for `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php`, `apps/api/app/Modules/POS/Presentation/Controllers/ManagerPinController.php`, `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php`, `apps/pos/src/lib/db/repositories/operatorPinRepository.ts`, and `apps/pos/src/lib/db/repositories/queuedPinUpdateRepository.ts`.

## §7. Cash Drawer & Shift Management

- [P1] The questions are concrete, but the section is too narrow for production drawer operations. It should require physical drawer-kick verification (`apps/pos/src-tauri/src/commands/printing.rs` `open_cash_drawer`), cash drawer settings, printer-dependent drawer failure modes, duplicate kicks, and audit separation between "drawer opened" and "sale recorded".

- [P1] The plan asks whether `recordSale` dead code should be deleted/wired, which is an implementation decision. For an audit plan, require evidence of every cash-affecting event source feeding expected cash exactly once: opening, cash sale tendered amount, change, refund, deposit, payout, tolerance write-off, void, and close.

- [P2] Add anchors for `apps/pos/src/stores/cashDrawerStore.ts`, `apps/pos/src/lib/db/repositories/cashDrawerRepository.ts`, `apps/pos/src/components/settings/CashDrawerSettings.tsx`, `apps/pos/src-tauri/src/printing/receipt_template.rs`, and `apps/api/app/Modules/POS/Presentation/Controllers/CashDrawerController.php`.

## §8. Payment Flows

- [P1] The anchor `apps/pos/src/components/pos/PaymentPanel.tsx` is stale; no such file exists. Payment UI is split across `CashPaymentScreen`, `CashTenderedModal`, `AdvancedPaymentsModal`, `CardPaymentModal`, `VoucherTenderModal`, `PaymentSummary`, and checkout success components.

- [P1] The section should explicitly audit third-party card-terminal reconciliation. It asks one question about approved-card/receipt-transaction failure, but there is no anchor to any card processor integration, settlement batch, authorization code storage, or manual reconciliation screen. If card is "manual external terminal", production-ready needs to state that and audit the manual workflow.

- [P1] Voucher/loyalty questions need sharper expected outcomes. "store voucher / restaurant voucher / gift card" and "points-as-tender vs points-as-discount" should become a matrix of supported/unsupported states with exact user-facing behavior, fiscal hash fields, sync behavior, GL impact, and refund behavior.

- [P2] Add anchors for `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`, `apps/pos/src/components/pos/VoucherTenderModal.tsx`, `apps/pos/src/lib/offline/voucherRepository.ts`, `apps/api/app/Modules/POS/Application/Services/VoucherLedgerPushService.php`, and `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`.

## §9. UI/UX & Ergonomics

- [P1] The anchor `apps/pos/src/i18n.ts` is stale; the actual i18n entrypoint is `apps/pos/src/lib/i18n.ts`. The locales directory exists, but only English/French were present; Arabic RTL cannot be audited as implemented unless locale files and layout direction support exist.

- [P1] The question "keyboard shortcuts: documented? discoverable?" is underspecified. For POS production readiness, the plan should list required scanner/keyboard flows: barcode scan focus behavior, Enter/Escape semantics in payment/refund/modals, numpad behavior, cash tender shortcuts, and stuck-focus recovery.

- [P1] The UI section needs scripted smoke coverage, not only file reading. Use Playwright or Testing Library with fixed viewport profiles for tablet/counter terminal, check modals do not resize, touch targets, RTL if added, offline banners, and high-frequency cashier paths.

- [P2] The plan should anchor duplicate component trees intentionally: both `apps/pos/src/components/pos/*` and newer `apps/pos/src/components/organisms/*`. The current broad glob hides the risk that the same concept exists in two locations and a subagent audits the wrong one.

## §10. Resilience & Crash Safety

- [BLOCKER] The section omits app update and install/rollback resilience even though it asks "Tauri update: does an in-place update preserve SQLite + WAL sidecars?" The repo declares `@tauri-apps/plugin-updater` in `apps/pos/package.json`, but `src-tauri/lib.rs` does not initialize the updater and `tauri.conf.json` has no updater configuration. The audit plan must include "is there an update mechanism at all, how are updates signed, and what is the rollback path?"

- [P1] Crash safety cannot be answered read-only. Require scripted crash tests: kill app mid-checkout, mid-receipt insert, mid-sync, mid-Z-report, mid-print, and during company switch; then restart and verify the operator-facing recovery state and server consistency.

- [P1] SQLite corruption/backup is too vague. The plan should require verification of WAL checkpoint/backup procedure, whether app-data backup includes `.db`, `.db-wal`, `.db-shm`, image cache, Tauri Store, encryption key, and how a terminal is restored/re-paired.

- [P2] Add anchors for `apps/pos/src-tauri/tauri.conf.json`, `apps/pos/src-tauri/capabilities/default.json`, `apps/pos/src-tauri/src/lib.rs`, `apps/pos/src/lib/storage.ts`, `apps/pos/src/lib/db/repositories/refundDraftRepository.ts`, and `docs/superpowers/plans/2026-05-11-tunisia-customer-rollout.md`.

## §11. Observability

- [BLOCKER] The plan calls for "server-side telemetry" and "Tauri crash reports" but does not require an actual telemetry pipeline. `tauri_plugin_log` is initialized, but there is no visible Sentry/crash-report upload path in the Tauri bootstrap. Production readiness needs a defined trail: local logs path, retention, export procedure, API log correlation IDs, device/terminal identifiers, and redaction policy.

- [P1] Console/log leakage needs a wider sweep than `syncService.ts`. Spot-checks show auth logs, WS logs, display payload logs, print logs, and payment failure logs across `authStore.ts`, `useTerminalActivation.ts`, Rust printing/display commands, and `HomePage.tsx`. The plan should require a structured grep/audit for token, PIN, customer PII, voucher serials, receipt QR tokens, receipt numbers, and raw SQL/error payloads.

- [P1] The anchor `apps/api/storage/logs/ configuration` is not a code anchor. Use Laravel logging config, Sentry config if any, API middleware/request-id code, and deployment log retention docs. Otherwise subagents will inspect a local runtime directory that may not exist or may be irrelevant.

- [P2] Observability should be placed before performance/resilience in execution order, or the audit should explicitly allow temporary instrumentation. Performance and crash-safety conclusions without logs/timers will be weak.

## §12. Multi-Vertical (Otospex Automotive vs IziPOS Retail)

- [P1] The anchor `apps/erp/.../verticals/` is not resolvable from this checkout. Actual vertical/product/menu surfaces are in `apps/api/app/Modules/Product`, `apps/api/app/Modules/Catalog`, `apps/api/app/Modules/Menu`, and `apps/pos/src/stores/terminalStore.ts`/company config.

- [P2] `project_otospex_pos.md`, `project_otospex_brand.md`, and `project_refund_flow_phases.md` are real in the Claude project memory path and the plan quotes their key points accurately: Otospex is a separate automotive/table/search-first POS, the canonical brand spelling/theme notes are present, and customer lookup by phone/email/loyalty is a Phase 1 deferred item. The plan should still quote the needed excerpts or provide the full memory path because those notes are not repo-local.

- [P1] "Workshop integration (Otospex): work-order -> invoice -> POS receipt" is too broad and not anchored to Workshop/Document/ERP files. A POS subagent limited to the plan's anchor list cannot answer it. Either add the actual work-order/document routes/services or move this to a separate vertical workflow audit.

- [P2] Multi-vertical should cover Menu/FnB tenants more explicitly. The plan mentions composite items/modifiers post-PR D, but it should require an exact matrix: standard retail `/products`, Menu `/active-menu`, composite IDs, modifiers, category selection, price/tax inheritance, stock availability, and broadcast invalidation.

## Execution Checklist / Out of Scope / Memory Hooks

- [P1] Memory-note hooks are real but not self-contained. The cited notes live under `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/`, not in the repo or `/Users/houssamr/.codex/memories`. The plan should quote the specific facts or include that full path. Accuracy spot-check: `project_pos_performance.md`, `project_discount_permissions.md`, `feedback_usePermissions_hardcoded_map.md`, `feedback_modal_fixed_size.md`, `project_otospex_pos.md`, `project_otospex_brand.md`, `project_refund_flow_phases.md`, and `project_prelaunch_audit_plan.md` are mostly summarized correctly; `project_current_priorities.md` is stale March Otospex-sprint context and should not be cited as current go-live status.

- [P1] The out-of-scope list wrongly excludes PR C / chain-break recovery UX categorically. Even if the current operational call is "terminal reinstall if chain-break happens," production readiness should audit whether that operational recovery is documented, who performs it, what data is preserved/lost, and how the cashier is blocked from continuing after a chain break.

- [P1] "Tenant-isolation sweep follow-ups" should not be wholly out of scope. The audit's own §5 asks whether POS paths are clean; any POS-specific tenant leak must be in scope even if the broader sweep is separate.

- [P1] The execution model should not use 12 isolated read-only subagents without an integration pass. Critical cross-section flows cut across boundaries: cash sale -> print -> sync -> server receipt -> Z-report -> fiscal chain -> analytics; refund -> voucher -> receipt QR -> sync; company switch -> SQLite/Tauri Store/Echo/product cache; offline period -> backlog drain -> dead-letter UX. Add end-to-end flow audits with scripted evidence.

- [P2] The checklist says create a fresh branch/worktree. For a read-mostly audit this is fine, but measurement/hardware scripts should run against a production build as well as dev mode; `pnpm tauri dev` is not representative for startup time, bundle size, CSP, updater, or installer behavior.

- [NIT] The plan references PR #120/#121/#122 and "PR A/B/D" shorthand. Preserve the PR numbers but avoid letter shorthand in the audit instructions, because subagents starting cold will not know the mapping.

REQUEST-CHANGES
