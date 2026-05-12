# POS Production-Readiness Audit — Plan for the Next Session

> **For Codex (adversarial-reviewer-then-implementer) + Opus (orchestrator):**
>
> This is a **read-mostly forensic audit** of `apps/pos` (Tauri 2 / React 19 / TypeScript / Vitest / SQLite) and the POS surfaces in `apps/api` (Laravel 12 / PHP 8.4 / PostgreSQL 16). The goal is to surface every gap between the current state and a production-ready state for the Tunisia-deployed Otospex / IziPOS terminal. Output: a structured findings doc (`[BLOCKER] / [P1] / [P2] / [NIT]`) per area; fixes happen in follow-up PRs.
>
> Smoke tests for the just-merged PRs (#120 WAL + cascade fix, #121 cash-amount + void-refund, #122 catalog WebSocket) happen on the physical terminal — those are user-driven, not in this audit's scope.

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`
**Base branch:** `dev` (currently at `a16abb0a` post-merge of PRs #120 / #121 / #122)
**Audit branch:** `audit/pos-production-readiness` (created fresh off `dev`)
**Output doc:** `docs/superpowers/audits/2026-05-12-pos-production-readiness-findings.md`

---

## What "production-ready" means here

The terminal is going live in Tunisia, single device, single tenant for now. "Production-ready" requires:

1. **Correctness under cashier workload.** A trained cashier can ring 100 receipts/hour for 8 hours with zero data loss, zero phantom errors, zero "weird behavior."
2. **Fiscal compliance.** Hash chain, Z-reports, QR codes, NF525 / Tunisia fiscal requirements (whichever applies for the deploy country), audit log integrity.
3. **Resilience.** Network outages, power loss, app crashes, printer disconnects, SQLite hot restart — all degrade gracefully and recover without operator intervention.
4. **Performance budget.** P95 cashier-facing operations under 200 ms (tap → response). Catalog load under 3 s cold. Sync backlog of 100 receipts drains in under 60 s.
5. **Observability.** Every failure leaves a useful trail. The cashier banner never says "Unknown error." Sensitive data (PINs, tokens, customer PII) never leaks to console.
6. **Tenant / company / vertical isolation.** The recent tenant-isolation sweep landed; verify there are no remaining leaks specific to POS paths.

---

## Audit areas (12 sections)

For each area: enumerate concrete questions, walk the relevant code, file findings. Sections can be assigned to parallel subagents — each runs read-only.

### §1. Receipt rendering & printing

**Why care:** This is the single artefact the cashier hands to the customer. A wrong total, missing QR, broken alignment, or hidden change line is a fiscal-compliance and customer-trust failure.

**Anchor files:**
- `apps/pos/src/lib/buildReceiptData.ts` — derives the printable shape from the offline-receipt row
- `apps/pos/src/lib/escpos/` — ESC/POS command builder
- `apps/pos/src/lib/printing.ts` — printer transport (USB / Bluetooth / network)
- `apps/pos/src/lib/offline/getOfflineReceiptForPrint.ts` — read-path from SQLite
- `apps/pos/src/components/CustomerDisplayPage.tsx` — customer-facing display
- `apps/pos/src/lib/fiscal/v3/` — fiscal canonicalization + hash + QR token

**Questions to answer:**
1. Does the printed ticket exactly match the rendered preview for every payment shape (cash exact, cash over-tender, cash tolerance short-pay, card, split, voucher, gift card)?
2. Is the "Monnaie rendue" line correct post-PR B? **Smoke-verify** with an over-tender sale once the terminal is live.
3. Is the fiscal hash + QR token printed legibly and scannable?
4. Are the legal mentions (TVA breakdown, fiscal seal, terminal/operator IDs, training-mode banner, receipt-number format) compliant per `apps/api/.../FiscalCompliance` rules for FR + TN?
5. What happens on printer disconnect mid-print? Does the receipt re-queue? Is it marked "reprinted later"?
6. Are reprints distinguishable from originals (NF525 contract)?
7. Are customer-display updates synchronized with the cashier UI? Any race where the display shows a stale total during processing?
8. Multi-language: FR / AR (RTL) / EN — does the ESC/POS path correctly handle RTL + CP-857 / CP-864 character sets?

**Deliverable:** A `[BLOCKER]` for any wrong number on a printed ticket. `[P1]` for any compliance gap. `[P2]` for alignment / font / reprint nits.

### §2. Performance budget

**Why care:** A slow POS is a frustrated cashier, longer queues, missed sales. The terminal is a single-process Tauri app that has to feel native.

**Anchor files:**
- `apps/pos/src/stores/productStore.ts` — catalog cache + foreground/background fetch
- `apps/pos/src/lib/db/repositories/productRepository.ts` — SQLite queries
- `apps/pos/src/lib/sync/syncScheduler.ts` — sync cadence
- `apps/pos/src/lib/sync/syncService.ts` — push/pull loops
- `apps/pos/src/pages/HomePage.tsx` — cashier main screen
- `apps/pos/src/components/pos/ProductGrid.tsx` — catalog browser
- `project_pos_performance.md` memory note — historical perf work + open items

**Questions:**
1. Catalog cold-start: from `pnpm tauri dev` launch to interactive cashier screen with 5K products loaded — wall clock P50 / P95?
2. Search latency: barcode scan vs text search across 5K products?
3. Cart add latency: tap product → line appears + total updates — P50 / P95?
4. Receipt creation: cashier hits "OK" → success modal — P50 / P95?
5. Sync throughput: simulate 100-receipt backlog (offline queue), bring online, time to drain?
6. SQLite query plan: are the hot paths using indexes? Any unindexed `WHERE`?
7. Memory: 8-hour shift simulation — RSS growth? Leaks? Image cache eviction?
8. Image cache: where is it, how is it sized, what evicts?
9. T2.1 / T2.4 / C2 work from `project_pos_performance.md` — any remaining items?
10. Tauri's React bundle size: is the production build acceptably small?

**Tools:** `pnpm tauri dev` + browser devtools Performance panel. SQLite `EXPLAIN QUERY PLAN` on hot queries. Vitest stress tests (`paymentStore.stress.test.ts` already exists — add catalog + sync stress).

### §3. Fiscal compliance & hash chain

**Why care:** A non-compliant receipt voids the cashier's authority to sell. Tunisia has its own fiscal rules; France's NF525 is the current baseline.

**Anchor files:**
- `apps/pos/src/lib/fiscal/v3/` — v3 canonical fiscal hash (the current scheme)
- `apps/pos/src/lib/fiscal/hashService.ts` — chain advance
- `apps/pos/src/lib/db/repositories/terminalStateRepository.ts` — `terminal_state.last_hash` + `hash_sequence` + anti-regression guard
- `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php` — server-side verification
- `apps/api/.../FiscalCompliance/` — country-specific compliance rules
- `docs/conventions/`, `apps/erp/.claude/context/compliance.md`

**Questions:**
1. Is v3 canonicalization byte-stable across pretty-printers / locales? Run the golden-hash fixtures (`apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/*.json`).
2. Does the Z-report chain (separate from the receipt chain) advance correctly? `pullZChainState` / `upsertZChainState` semantics.
3. Tunisia fiscal: is there a TN-specific rule set, and is it active on this terminal? (`apps/api/.../FiscalCompliance` country code resolution.)
4. QR code format: scannable by Tunisian inspector apps? FR DGFiP for the FR-vertical? Wallet apps?
5. Hash-chain regression guards (`advanceHashChain`, `upsertTerminalState`) — are they strict enough to prevent the local chain from ever rewinding?
6. Fiscal schema versioning (`fiscal_schema_version` column): are existing rows v2-compatible? Migration v22 / v23 / v25 history clean?
7. Audit log (TimescaleDB): every fiscal-relevant event recorded? Tamper-evident?

**Deliverable:** Per-country compliance matrix (FR, TN at minimum). One `[BLOCKER]` per missing-legal-requirement.

### §4. Sync reliability (post-PR A WAL fix)

**Why care:** Sync drives reports, BI, refund history, and chain integrity. PR A fixed the SQLite lock cascade; this audit verifies the rest of the sync surface is also sound.

**Anchor files:**
- `apps/pos/src/lib/sync/syncService.ts` — push/pull for receipts, Z-reports, vouchers, PIN updates, cash-drawer ops, tables, menus, products
- `apps/pos/src/lib/sync/syncScheduler.ts` — 60 s tick + foreground triggers
- `apps/pos/src/stores/syncStore.ts` — UI state for sync (banner, error count, chain-break flag)
- `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts` — idempotency, retry, dead-letter
- `apps/api/.../ReceiptSyncService.php`, `VoucherSyncController.php`, `ZReportSyncController.php`

**Questions:**
1. Receipt batch sync: PR A's plan flagged a TODO (`go-live-followup`) — sync is batch-of-one. For a 100-receipt offline backlog, this is 100 round-trips. Is that acceptable, or should batch-N ship before go-live?
2. Idempotency key stability: is the key generated client-side once and persisted across retries? Confirmed.
3. Z-report sync chain: separate chain from receipt chain. Same break-failure modes?
4. Voucher ledger sync: complete? Edge cases (refunds, expiration, partial redemption)?
5. Cash-drawer-op sync: opening / closing / sale / refund / deposit / payout — all paths.
6. PIN-update sync: when an operator's PIN changes server-side, how fast does the terminal pick it up? Polling cadence vs WS?
7. Sync degradation (`computeDegraded`): what triggers the degraded banner? When does it clear?
8. Background vs foreground sync: any race between user-initiated pull and scheduled push?

**Deliverable:** A sync-flow matrix (every sync verb × every failure mode × is-it-handled-or-not).

### §5. Multi-tenant / company / vertical isolation

**Why care:** A POS that leaks data between tenants is a compliance + security incident. The tenant-isolation sweep landed recently; verify the POS-specific paths are clean.

**Anchor files:**
- `apps/pos/src/stores/authStore.ts` — `tenantId` + `companyId` resolution
- `apps/pos/src/stores/productStore.ts` — catalog scope
- `apps/pos/src/lib/db.ts` — per-company SQLite file naming (`izipos-${companyId}.db`)
- `apps/api/.../middleware/EnforceTokenTenantClaim.php`
- `apps/erp/.claude/context/architecture.md` (tenant-isolation rules)

**Questions:**
1. Switch companies on the terminal — does the SQLite singleton correctly close + reopen the right `.db` file? PR A's recovery hook runs on every boot — verify it doesn't fire across companies.
2. Cached state in stores: do `productStore` / `paymentStore` / `terminalStore` / `cartStore` clear on company switch?
3. Auth token: per-tenant scope baked in? See `tenant:<uuid>` ability check.
4. WebSocket channels: `private-tenant.{tenantId}.company.{companyId}.*` — auth rejects cross-tenant subscriptions?
5. The recent tenant-isolation sweep — were POS paths in scope? Any deferred items?

**Deliverable:** Confirm tenant isolation OR list every leak as `[BLOCKER]`.

### §6. Authentication & permissions

**Why care:** Manager overrides, discount limits, void privileges — these are how the business controls cashier authority. A weak permission check is a cash-skim opening.

**Anchor files:**
- `apps/pos/src/stores/operatorStore.ts` — operator PIN + activity timer
- `apps/pos/src/components/PinPad.tsx` — manager PIN modal
- `apps/pos/src/hooks/useTerminalActivation.ts` — terminal pairing
- `apps/api/.../identity/`, `apps/api/.../authorization/`
- `apps/erp/docs/conventions/03-AUTHORIZATION.md`

**Questions:**
1. Manager-PIN modal: how is the PIN validated locally vs server? Is offline manager-PIN validation safe (PIN hash sync)?
2. Discount limits: `user.can_discount` + terminal `max_discount_percent` chain — `project_discount_permissions.md` flagged "what's needed."
3. `usePermissions` hardcoded map (`feedback_usePermissions_hardcoded_map.md`) — custom Spatie roles silently denied on frontend.
4. Operator activity timer + auto-lock: configurable per-terminal? Reasonable defaults?
5. Logout cleanup: stores reset? WebSocket disconnected? PR D's `peekEcho` fix mitigates the WS-recreate but verify the whole logout path is clean.
6. Terminal activation: pairing token expiration, re-pairing flow.

### §7. Cash drawer & shift management

**Why care:** Drawer variance is the single most-watched cashier metric. A wrong cash count fires audits, accusations, and operator dismissals.

**Anchor files:**
- `apps/pos/src/lib/offline/zReportService.ts`
- `apps/pos/src/lib/offline/endOfDayPreview.ts`
- `apps/api/.../ReportGenerationService.php` (cash variance formula)
- `apps/api/.../CashCountToleranceVarianceRegressionTest.php` (the contract for `expected_cash`)
- `apps/api/.../CashDrawerService.php`

**Questions:**
1. **Post-PR B**, the `expected_cash` formula is now `opening + Σ(amount tendered) − Σ(change_due) − Σ(refunds)`. **Smoke-verify** end-to-end: open shift → ring 5 sales (mix of exact / over-tender / tolerance) → close shift → expected matches physical.
2. Z-report contents: every cash count input + variance row tested?
3. Tolerance write-off accounting: posts to GL 658, doesn't move drawer cash. Verified by PR B's tolerance test case.
4. Cash-drawer ops sync: PR B touched the void path; verify deposit / payout / opening / closing are unaffected.
5. `recordSale` cash-drawer operation: NEVER called from POS code (verified during PR B). Is that intentional? The variance formula doesn't depend on it, but if it's truly dead code it should be deleted OR wired up.

### §8. Payment flows

**Why care:** Wrong payment = wrong receipt = wrong everything downstream.

**Anchor files:**
- `apps/pos/src/stores/paymentStore.ts` (processCashCheckout, processCardCheckout, advancedSplit)
- `apps/pos/src/lib/offline/receiptService.ts`
- `apps/pos/src/components/pos/PaymentPanel.tsx`
- `apps/api/.../ReceiptPaymentService.php` (server-side finalization)

**Questions:**
1. **Post-PR B**, cash quick-path stores `amount = tendered`. Split-path already did. Card-path stores `amount = cart total` (no over-tender concept for card). Are all three internally consistent?
2. Voucher tenders: store voucher (with serial + ledger writeback) + restaurant voucher + gift card — each path covered?
3. Mixed-currency payments? Probably not in scope, but verify.
4. Loyalty redemption: points-as-tender vs points-as-discount — which is implemented?
5. Refunds: full receipt void (PR B) vs partial line refund (`refundDraftStore`)?
6. Card auth retry: if the terminal-side card processor returns "approved" but the receipt-creation transaction fails, what reconciles?

### §9. UI/UX & ergonomics

**Why care:** Cashier speed + comfort directly affect throughput.

**Anchor files:**
- `apps/pos/src/pages/HomePage.tsx`
- `apps/pos/src/components/pos/*`
- `apps/pos/src/i18n.ts` + `locales/`

**Questions:**
1. Touch targets: minimum 44×44 pt on a tablet, 60×60 on a counter terminal — measured?
2. Keyboard shortcuts: documented? Discoverable?
3. Error banners: all use `t()` translation keys (no hardcoded strings)? `formatCheckoutError` keeps banner text opaque enough to not leak SQLite internals but informative enough to act on?
4. Loading states: every async action has a spinner / disabled state?
5. Offline indicator: clear distinction between "WS down" vs "API down" vs "fully offline"?
6. Training mode: visually distinct banner? Receipts marked? Z-reports excluded?
7. Modals: fixed dimensions (per `feedback_modal_fixed_size.md`)? Don't resize on interaction?
8. RTL (Arabic) support — design tokens applied? Layout mirrored?

### §10. Resilience & crash safety

**Why care:** A POS that loses a single transaction loses the cashier's trust forever.

**Anchor files:**
- `apps/pos/src/lib/db.ts` (WAL + recovery hook from PR A)
- `apps/pos/src/lib/sync/syncScheduler.ts` (recovery from crashed sync)
- `apps/pos/src/stores/bootstrapStore.ts` (cold-start orchestration)

**Questions:**
1. App crash mid-checkout: does the partial state on disk leave the cashier in a recoverable spot? (T2.2 crash-safety small wins shipped — verify.)
2. Power loss mid-INSERT into `offline_receipts`: WAL gives durability — verify.
3. Stranded receipt UI: when a row hits `retry_count >= 5` for a non-lock reason, what does the cashier see? Is there a "stranded receipts" inbox?
4. Background sync vs foreground: any state where the cashier sees a stale view?
5. SQLite corruption recovery: detect + report, or silent failure?
6. Tauri update: does an in-place update preserve SQLite + WAL sidecars?

### §11. Observability

**Why care:** When something goes wrong in production, the audit trail decides whether you can debug it in 5 minutes or 5 days.

**Anchor files:**
- `apps/pos/src/lib/errorLogging.ts` (`serializeErrorForLog`)
- `apps/pos/src/lib/sync/coerceSyncError.ts` (PR A)
- `apps/api/storage/logs/` configuration
- `feedback_*.md` memory notes around error preservation

**Questions:**
1. After PR A's `coerceSyncError`, every error site in `syncService.ts` writes a non-`'Unknown error'` message. Verify the SAME audit applies to `apps/pos/src` outside `syncService.ts` — `errorLogging.ts`, `bootstrapStore`, `paymentStore.catch` paths, etc.
2. Console logs: any sensitive data (token, PIN, customer PII) leaked?
3. Sync log retention: `cleanupOldSyncLogs` (7-day default) — is that enough for forensics?
4. Server-side telemetry: are there `Log::warning` calls that should be `Log::error`?
5. Tauri crash reports: do they ship anywhere? Sentry-style integration?

### §12. Multi-vertical (Otospex automotive vs IziPOS retail)

**Why care:** The same codebase serves two verticals with different cashier workflows.

**Anchor files:**
- `apps/pos/src/stores/terminalStore.ts` (vertical detection)
- `apps/erp/.../verticals/`
- `project_otospex_pos.md`, `project_otospex_brand.md` memory notes

**Questions:**
1. Workshop integration (Otospex): work-order → invoice → POS receipt — flow complete?
2. Vehicle / customer lookup: phone / email / loyalty — `project_refund_flow_phases.md` flagged this as Phase 1 deferred.
3. Composite items + modifiers (Menu tenants): post-PR D, broadcasts cover this. UX-side support complete?
4. Vertical-scoped products: cross-vertical sale prevented?
5. Theme switching (copper for IziPOS, pink for Otospex): per-company config? See `localStorage`/auth-shared-on-localhost note in `apps/erp/CLAUDE.md`.

---

## Execution checklist

- [ ] Create `audit/pos-production-readiness` worktree branch off `dev`.
- [ ] For each section §1–§12, dispatch a read-only Explore subagent (or run sequentially) — return a structured findings list.
- [ ] Aggregate findings into `docs/superpowers/audits/2026-05-12-pos-production-readiness-findings.md` with the standard `[BLOCKER] / [P1] / [P2] / [NIT]` taxonomy.
- [ ] For each `[BLOCKER]` and `[P1]`: write a one-paragraph fix plan + estimate.
- [ ] Hand the audit doc to the user for prioritization.
- [ ] **Do not implement fixes in this session.** This is read-mostly forensics. Fixes happen in follow-up PRs after the user decides scope.

---

## Out of scope

- **Implementation of any fix.** Strictly an audit pass.
- **PR C (chain-break recovery UX).** Skipped per the user's "terminal reinstall" call; re-evaluate only if post-WAL chain-break is observed in production.
- **Performance optimization beyond identification.** Tier 3 work tracked in `project_pos_performance.md`.
- **The platform side** (`apps/platform`, `apps/platform-ml`, `apps/erp-ml`) — separate repos, separate audit.
- **The tenant-isolation sweep follow-ups** — separate workstream.

---

## Cross-references

- PR #120 — Bug 5 SQLite WAL + cascade fix (merged to `dev` 2026-05-11).
- PR #121 — Bug 2 cash-amount + void over-refund fix (merged to `dev` 2026-05-11).
- PR #122 — Bug 1 catalog WebSocket (merged to `dev` 2026-05-11).
- `project_pos_performance.md` — historical perf work + Tier 3 backlog.
- `project_current_priorities.md` — execution plan + go-live status.
- `project_prelaunch_audit_plan.md` — earlier pre-launch gate plan (this doc supersedes for the POS slice).
