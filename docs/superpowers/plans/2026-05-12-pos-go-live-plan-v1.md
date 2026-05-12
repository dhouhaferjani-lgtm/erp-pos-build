# POS Go-Live Plan v1

> **For Codex (implementer) + Opus (final auditor):**
> Codex reviews this plan adversarially first. Once stable, Codex implements every PR in order, runs its own per-PR `codex review --base dev` to APPROVE, and saves the review trail. **Opus stays out of the per-PR review loop during implementation.** After every PR lands on `dev`, Opus runs a single final audit against the plan's acceptance criteria.

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`
**Base branch:** `dev` (currently at `2121f923` post v3 audit plan + PRs #120/#121/#122 merged)
**Audit cross-reference:** `docs/superpowers/audits/2026-05-12-pos-production-readiness-findings.md` (Codex audit, Opus accepted)
**Opus calibration:** `docs/superpowers/reviews/2026-05-12-pos-production-readiness-audit-findings-opus-review.md`

---

## Goal

Ship the four remaining code + docs items needed for TN deploy-phase-1, with explicit risk-accepts for everything else. Stay inside the user's calibrations:

1. **Z-reports are local-source-of-truth.** Server is an archive, not a recomputer. Owner's remote view shows what the cashier saw on-device — no recompute, no drift.
2. **Tax management is data, not code.** Existing `Modules/Taxation` admin endpoints handle VAT rate config; POS pulls per-product `tax_rate` via sync; no code change for VAT specifics.
3. **NF525 substance is in place.** Hash chain v3, audit log, immutable Z-reports — all shipped. Certification is bureaucratic and runs in parallel; not blocking this plan.
4. **E-invoicing is B2B-only.** Not in POS scope.
5. **Receipt legal fields are country-agnostic via conditional rendering.** If the admin fills the field, the line prints. If not, the line is skipped. One template serves FR, TN, anywhere.

---

## Production-ready definition for deploy-phase-1

| Requirement | Status post-plan |
|---|---|
| Cashier flow correct end-to-end (cash / split / refund / void) | Already shipped via PRs #120, #121, #122 |
| Local Z-report correct (offline-first) | Already correct (`endOfDayPreview.ts` formula) |
| Owner's remote Z-report view matches local | **PR #1 of this plan** |
| Receipt legal fields render only when configured (country-agnostic) | **PR #2 of this plan** |
| Discount permissions fail-closed when no operator | **PR #3 of this plan** |
| Operator runbooks: install / manual update / backup / restore / support / chain-break recovery | **PR #4 of this plan** |
| NF525 substance | Already present; certification independent |
| Tax management | Already complete via `Modules/Taxation` admin + per-product `tax_rate` sync |

---

## PR sequence (4 PRs, ≈ 2.5 – 3.5 coding days + 1.5 docs days)

PRs are causally independent and may be implemented in parallel branches if desired. The recommended order below reflects priority and risk.

---

### PR #1 — Z-report sync preserves per-method cash-count breakdowns

**Branch:** `fix/pos-zreport-sync-preserve-cash-counts`

**Why this fix:** Local POS already computes `expected_amount`, `actual_amount`, `variance_amount`, `variance_direction`, `currency_code`, and `transaction_count` per payment method (`zReportService.ts:195-223`). The wire payload at `syncService.ts:565` already includes the full `cash_counts` array. **The server-side controller throws those fields away** and substitutes `expected_amount = 0.0000`, satisfying the DB CHECK constraint via `variance = actual − 0` but destroying the audit/reporting value (`ZReportSyncController.php:228-272`).

**Architectural principle (locked):** The server is an **archive** of what the cashier-facing device computed. NF525-aligned, drift-free. The server-side `ReportGenerationService::buildExpectedPerMethod` recomputation path (`ReportGenerationService.php:481-517`) has **zero callers in production POS** (verified via grep — `generateZReportServer` is defined but never invoked) and is therefore deprecated / removed in this PR.

**Anchor files:**

- `apps/pos/src/lib/offline/zReportService.ts:195-223` — local computation of per-method entries (already correct)
- `apps/pos/src/lib/offline/types.ts:99,143` — `ZReportCountEntry` carries the full shape
- `apps/pos/src/lib/sync/syncService.ts:549-574` (`zReportToSyncPayload`) — wire payload (already sends `cash_counts`)
- `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:228-272` — **the fix site**
- `apps/api/app/Modules/POS/Presentation/Requests/SyncZReportRequest.php` (or wherever the request validation lives) — extend `cash_counts.*` rules to require the per-entry fields
- `apps/api/app/Modules/POS/Application/DTOs/CashCountBreakdownDTO.php` — verify it accepts the full shape; extend if needed
- `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:147-205,481-517` — `generateZReport` + `buildExpectedPerMethod` — **deprecate or delete**
- `apps/api/app/Modules/POS/routes.php:73` — `POST /pos/reports/z` route — **deprecate or delete**

**TDD steps:**

1. **Failing backend Feature test** — `apps/api/tests/Feature/POS/ZReportSyncCashCountBreakdownsTest.php`:
   - Seed a tenant + company + terminal + shift + 2 receipts: one exact (total=50, tendered=50, change=0) and one over-tender (total=50, tendered=120, change=70).
   - POST a Z-report sync payload with `cash_counts: [{ payment_method_id, currency_code, expected_amount, actual_amount, variance_amount, variance_direction, transaction_count }]` — values computed locally per the spec.
   - Assert `pos_cash_count_breakdowns` row has `expected_amount`, `actual_amount`, `variance_amount`, `currency_code`, `transaction_count` matching the payload **verbatim** (no recomputation).
2. **Failing backend test for over-tender Z-report variance** — same setup with an actual count slightly above expected, assert variance equals payload-supplied value, not `actual − 0`.
3. **Update request validation:** every `cash_counts.*.expected_amount`, `actual_amount`, `variance_amount`, `currency_code`, `transaction_count`, `variance_direction` becomes a required field. Backward-compat policy below.
4. **Update controller:** `buildBreakdownsFromSyncPayload` reads the supplied fields and constructs `CashCountBreakdownDTO` directly. No internal aggregation. Currency comes from the payload, not `'XXX'`.
5. **Frontend test for wire-payload completeness** — `apps/pos/src/lib/sync/__tests__/zReportSyncPayloadShape.test.ts`: serialize a representative `LocalZReport` and assert every required field is present on each `cash_counts` entry.
6. **Deprecate the server-recompute path:**
   - Add `@deprecated` PHPDoc on `ReportGenerationService::generateZReport` + `buildExpectedPerMethod` referencing this PR.
   - Add `@deprecated` PHPDoc on the `ReportController::generateZReport` action.
   - Add a Phpstan-level-8-clean assertion in the route file noting the route is preserved for back-compat only.
   - **Or delete entirely** if Codex verifies (via grep + repo-wide search) zero callers in `apps/pos`, `apps/web`, or any seeders / commands / CI. Codex's call.

**Backward compatibility decision:** the user has stated they will reinstall the single TN terminal, so there are no older POS builds in the field. **Reject sync payloads that omit the new required fields with 422**, with a clear error pointing at the new contract. Document this in the migration note. This avoids a long-tail fallback branch and keeps the server contract clean.

**Acceptance:**

- New Feature tests pass.
- `pnpm test` (apps/pos) — 0 regressions, lint baseline (41 warnings) preserved, typecheck 0 errors.
- `./vendor/bin/phpstan` POS module — 0 errors at level 8.
- `./vendor/bin/pint` — pass on touched files.
- `./vendor/bin/phpunit tests/Feature/POS/` — all pre-existing tests still pass (especially `CashCountToleranceVarianceRegressionTest`); new tests pass.
- `ReportGenerationService::buildExpectedPerMethod` is either deleted OR marked `@deprecated` with the cross-reference comment; corresponding controller action + route follow the same disposition.
- PR body documents the architectural decision: local is source of truth; server is archive.

**Codex review focus:** assert the wire shape, validate the back-compat decision, check the deprecation/removal scope. Standard cadence.

---

### PR #2 — Receipt legal-field conditional rendering

**Branch:** `fix/pos-receipt-conditional-legal-fields`

**Why this fix:** The Rust ESC/POS template at `receipt_template.rs:274` unconditionally prints `"Tax ID: "` even when `data.company.tax_id` is the empty string (it's defaulted to `""` in `buildReceiptData.ts:126`). The same template at `receipt_template.rs:264-266` and `:271-273` already uses `if let Some(ref ...)` conditional rendering for `addr2` and `phone` — **the pattern is right but not applied uniformly.**

**Architectural principle (locked):** Every legal/administrative field on the receipt is conditional. The admin enters the field if their jurisdiction requires it; the line renders when filled and is omitted when empty. One template serves any country.

**Anchor files:**

- `apps/pos/src-tauri/src/printing/receipt_template.rs:115,152,265-275` — template; convert all unconditional legal-field lines to `if !field.is_empty()` (or `if let Some(ref ...)` if the source type is already `Option<String>`)
- `apps/pos/src/lib/buildReceiptData.ts:120-140,220-225,295-305,378-390` — TS data builder; remove the `?? ''` defaults that turn `null` into empty-but-rendered, OR keep them and rely on Rust-side `is_empty()` guard, but do not do both ways
- `apps/pos/src/lib/buildReceiptData.ts` types — the `company` field shape should distinguish "absent" from "explicitly empty," even if the wire serializes both as empty strings
- `apps/pos/src-tauri/src/printing/voucher_ticket.rs` — verify the same pattern there
- `apps/api/.../Company` admin endpoints — verify they accept null / empty for legal fields the company doesn't have

**TDD steps:**

1. **Failing snapshot tests** — `apps/pos/src/lib/__tests__/receiptTemplateGoldenSnapshots.test.ts`:
   - Build a receipt with `company.tax_id = ''`. Render to ESC/POS bytes. Assert no `Tax ID` substring in output.
   - Repeat for every conditional legal field: tax_id, registration_number, capital, address line 2, address line 3, RC (registre du commerce) number, etc. — Codex enumerates the exact list by walking `receipt_template.rs`.
   - Positive test: `company.tax_id = '123456'` → output contains `Tax ID: 123456`.
2. **Apply the conditional pattern uniformly** in `receipt_template.rs`. The pattern is:
   ```rust
   if !data.company.tax_id.is_empty() {
       b.text_line(&format!("{} {}", data.label(|l| &l.tax_id, "Tax ID:"), data.company.tax_id));
   }
   ```
3. **Audit `buildReceiptData.ts`** for any field that gets defaulted to `''` and rendered unconditionally — fix at the data layer too (don't default; pass through `null` / `undefined`).
4. **Admin validation rule:** legal-field schema accepts `null` or non-empty string after trim; reject whitespace-only sentinels (no `" "` to suppress rendering).

**Acceptance:**

- Golden ESC/POS byte snapshots green for every legal-field × empty/filled combination.
- Vitest + Rust unit tests for `receipt_template.rs` green.
- No regression in `pnpm test`.
- Manual visual inspection: render a sample receipt with all legal fields filled, then with all empty, then with a typical TN mix (TVA + matricule filled, capital empty, RC empty). Each renders cleanly without orphan labels.
- The full list of legal fields the template supports is documented in the PR body as a side-effect of Codex's enumeration step.

**Codex review focus:** confirm no orphan labels remain; verify the data-builder + template don't double-default; check the admin validation rule.

---

### PR #3 — Discount permissions fail-closed + wire `discountApi`

**Branch:** `fix/pos-discount-permissions-fail-closed`

**Why this fix:** `apps/pos/src/pages/HomePage.tsx:200` defaults missing operator's `can_discount` to `true` and `max_discount_percent` to `100`. When no operator is loaded (initial render, lock screen race, store reset), the discount UI is fully unlocked. `apps/pos/src/api/discountApi.ts` exposes `fetchDiscountPermissions()` to read terminal-aware limits from the server, but **no production call site exists** (verified by grep against `apps/pos/src`).

**Architectural principle (locked):** Fail-closed for any privileged operation when the principal is missing. The discount limit is `min(operator_limit, terminal_limit)`; both must be known and both must be positive for any discount to be allowed.

**Anchor files:**

- `apps/pos/src/pages/HomePage.tsx:200` — the permissive default
- `apps/pos/src/stores/operatorStore.ts` — operator state, including `can_discount` + `max_discount_percent`
- `apps/pos/src/api/discountApi.ts` — `fetchDiscountPermissions()` exists, never called
- `apps/pos/src/stores/terminalStore.ts` — terminal-aware limit pull site (or wherever the terminal config arrives)
- `apps/pos/src/lib/db/repositories/` — optional offline cache for discount permissions
- `apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php` — server endpoint that returns the canonical limits
- **Memory note** at `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/project_discount_permissions.md` — describes the chain (`User.can_discount` + `Terminal.max_discount_percent`); some details may be stale, reconcile against current code

**TDD steps:**

1. **Failing Vitest tests** — `apps/pos/src/pages/__tests__/HomePage.discountFailClosed.test.tsx`:
   - operator = null → discount UI shows a disabled state with a tooltip explaining "Operator required."
   - operator.can_discount = false → discount UI disabled.
   - operator.can_discount = true, operator.max = null, terminal.max = 10 → effective limit = 10.
   - operator.can_discount = true, operator.max = 5, terminal.max = 10 → effective limit = `min(5, 10) = 5`.
   - operator.can_discount = true, operator.max = 10, terminal.max = 0 → effective limit = 0 → discount UI disabled (terminal blocks).
2. **Failing Vitest test for `discountApi` wiring** — `apps/pos/src/stores/__tests__/operatorStore.discountApi.test.ts`: after `verifyPin` resolves, the store should call `fetchDiscountPermissions` once and merge the terminal-aware limit into state.
3. **Apply fail-closed default** in `HomePage.tsx`: when operator is null, surface a disabled discount control with translation key `discount.requiresOperator`.
4. **Wire `fetchDiscountPermissions`** into `operatorStore.verifyPin` success path (or wherever the operator transition lands). Persist the resulting limit on the operator state object so other surfaces (`AdvancedPaymentsModal`, etc.) can read it.
5. **Offline cache:**
   - On successful `fetchDiscountPermissions`, persist `{ operator_id, can_discount, max_percent, fetched_at }` to a small SQLite table (or Tauri Store key — Codex picks the simpler shape).
   - If the API is unreachable AND a cache exists for the current operator AND the cache is < 24 h old, use it.
   - If unreachable AND no cache OR cache stale, **fail-closed**: surface the disabled state with a tooltip explaining "Discount permissions unavailable offline."
6. **i18n keys:** `discount.requiresOperator`, `discount.permissionsUnavailable`. Add `fr` + `en` translations.

**Acceptance:**

- All new Vitest cases pass.
- HomePage with operator=null shows a disabled discount control + tooltip.
- `discountApi.fetchDiscountPermissions` is now called on operator verify; cache populates.
- Cashier can apply a discount up to `min(operator_limit, terminal_limit)` and not beyond.
- Server enforces the same limit (already does per `DiscountController.php` + `DiscountEnforcementTest`); confirm no client-side bypass.
- Offline cashier with valid cache can still discount; without cache or with stale cache, fails closed.
- Lint baseline preserved.

**Codex review focus:** verify there's no remaining path where `can_discount` defaults true; check the cache freshness threshold; confirm i18n keys exist and both locales translate.

---

### PR #4 — Operator runbooks (docs)

**Branch:** `docs/pos-operator-runbooks`

**Why this work:** Audit findings doc flagged that manual update / backup / restore / support runbooks don't exist. Required for deploy-phase-1 because the terminal is in Tunisia, the operator is not the developer, and remote support is ad-hoc. Without written runbooks the on-site cashier can brick the install or lose data on a manual update.

**Output files (all under `docs/pos-operations/`):**

1. `install.md` — first-install procedure on Windows 11. Prerequisites, install steps, pairing-token entry, initial sync of catalog, PIN setup. Reference: `docs/superpowers/plans/2026-05-11-tunisia-customer-rollout.md`.
2. `manual-update.md` — swap-binary procedure for deploy-phase-1 (no in-app updater). Step-by-step, including:
   - Pre-update: stop the running POS, take a snapshot of app data.
   - Files to preserve: `izipos-${companyId}.db`, `.db-wal`, `.db-shm`, `izipos-settings.json`, `.izipos_key`, `$APPDATA/.../images/**`, log files.
   - Swap the new `.exe` (or installer-driven update).
   - Re-launch, verify catalog visible, verify Z-report continuity (chain didn't break), ring a test sale, void it.
   - Rollback procedure if the new build refuses to start: restore previous `.exe` from a backup folder.
3. `backup.md` — what files to copy, where they live on Windows 11 (`%APPDATA%\izipos`), how often, where to store backups.
4. `restore.md` — restore drill procedure with **checksums** (per Codex audit recommendation): pre-state checksums + row counts → restore → post-state checksums + row counts → diff = expected. Include the drill log as an example.
5. `support.md` — remote support policy: approved tool (per Phase 0 device profile note — to-be-decided), authentication, consent, log retrieval (operator emails a zipped log bundle if no remote access is available). Includes the bundle command and the contact procedure.
6. `chain-break-recovery.md` — operational runbook for the scenario PR C would have automated:
   - Symptom: red banner "Chaîne fiscale rompue" persists.
   - Triage: check sync status; look at `sync_log`; check connectivity.
   - Manual fix steps: a one-line SQL escape hatch (`UPDATE offline_receipts SET status='pending', retry_count=0 WHERE ...`) for the operator to run, OR the reinstall fallback.
   - When to call Synerivia vs when to self-heal.

**Acceptance:**

- All 6 documents written, screenshots optional (preferred for `install.md` and `manual-update.md`).
- **Walkthrough rehearsal:** another teammate (not the author) reads each doc and explains back what would happen step-by-step. Catches gaps.
- **Restore drill executed on the actual deployed Windows terminal:** pre-state captured (SQLite row counts per table, file checksums for `.db`, `.db-wal`, `.db-shm`, key file, Tauri Store, images), manual update procedure run on a backup, post-state captured, diff documented in `restore.md` as the canonical evidence.

**Codex review focus:** completeness of the file list, correctness of the chain-break SQL escape hatch, clarity of the restore-drill evidence section.

---

## Out-of-scope risk-accepts for deploy-phase-1

The user accepts the following items for deploy-phase-1 with a written disclosure in the PR body or a separate signed memo. **Each item ships in deploy-phase-2 or post-launch.**

1. **Tauri capabilities are permissive.** `capabilities/default.json` allows HTTP all-hosts, `fs:default`, `sql:allow-execute`, `notification`, `os`, `window-state`; `tauri.conf.json` has `csp: null` and asset protocol scope `["**"]`. Mitigations: device physically secured at the counter, approved-tool-only remote support, stolen-terminal runbook (covered in `support.md`). Tightening ships in deploy-phase-2.
2. **No production telemetry.** `tauri_plugin_log` writes locally; no Sentry / GlitchTip pipeline. Manual log retrieval via the runbook. Browser+Rust SDK Sentry ships post-launch.
3. **Sync batch-of-one** (`syncService.ts:307,333`). Measure 100-receipt drain on the unstable Wi-Fi dongle at Phase 0 device-profile-capture (or before go-live). Implement chunked push only if measured budget exceeded.
4. **Offline print-log sync** gap (`SyncController.php` TODO). Defer unless NF525 audit specifically asks. Server has `ReceiptPrintAuditService` for backend PDF prints; offline thermal prints are not server-audited.
5. **In-app updater** absent. `@tauri-apps/plugin-updater` declared but never initialized. Manual swap procedure for deploy-phase-1; in-app updater ships in deploy-phase-2 with the Synerivia-hosted "latest version" endpoint.

---

## Workflow

### Phase 0 — Codex adversarial review on this plan
- Codex runs `codex exec` on this plan: spot-check anchor paths, critique scope, identify any blind spots.
- Save trail to `docs/superpowers/reviews/2026-05-12-pos-go-live-plan-v1-adversarial-review.md`.
- Iterate to APPROVE or APPROVE-WITH-MINOR-EDITS. Same 1–3 round cadence as the audit plan.

### Phase 1 — Codex implements all 4 PRs
- Standard cadence per PR: branch off `dev`, TDD red anchor, implement, push, `codex review --base dev` to APPROVE, save trail to `docs/superpowers/reviews/`.
- PR order: #1 → #2 → #3 → #4 (or in parallel branches — they're causally independent).
- **Opus does NOT review per-PR during implementation.** Codex is the implementer; Codex's own adversarial review is the per-PR gate.

### Phase 2 — Opus final audit
- After all 4 PRs merged to `dev`, Opus runs a single audit pass against this plan.
- Verifies: every TDD step landed, every acceptance criterion met, no scope drift, no regression in preflight, the audit findings doc's blocker items are now resolved.
- Output: `docs/superpowers/reviews/2026-05-1X-pos-go-live-opus-final-audit.md` with verdict APPROVE / APPROVE-WITH-FOLLOWUPS / REQUEST-CHANGES.

### Phase 3 — Go-live preparation
- Phase 0 device-profile capture (per audit plan): finalize OS arch, printer model, drawer pulse, scanner, network, remote support tool.
- Execute restore drill on the actual terminal (the PR #4 acceptance step).
- Measure sync backlog drain on the actual Wi-Fi dongle (the out-of-scope #3 evidence step).
- Tunisia legal pack: accountant confirms VAT rates, register-certification scope, receipt legal-field list. Admin enters values via `Modules/Taxation` + company settings; no code change.
- Smoke pass on the live terminal (per PRs #120 / #121 / #122 acceptance smoke lists).

---

## Glossary

| Identifier | Meaning |
|---|---|
| PR #120 | `fix(pos): SQLite WAL + stuck-receipt recovery + sync_error preservation` — merged 2026-05-11 |
| PR #121 | `fix(pos): cash payment amount = tendered + void over-refund fix` — merged 2026-05-11 |
| PR #122 | `feat(pos): real-time catalog refresh via WebSocket` — merged 2026-05-11 |
| v3 audit plan | `docs/superpowers/plans/2026-05-12-pos-production-readiness-audit-plan-v3.md` (Codex r3 APPROVE-WITH-MINOR-EDITS) |
| Audit findings | `docs/superpowers/audits/2026-05-12-pos-production-readiness-findings.md` (Codex audit pass, Opus accepted) |
| Opus calibration | `docs/superpowers/reviews/2026-05-12-pos-production-readiness-audit-findings-opus-review.md` (reframes B1/B2 per user's local-first reasoning) |
| deploy-phase-1 | This deployment: one TN client, one terminal, single tenant, manual update procedure |
| deploy-phase-2 | Future deploy: Tauri capability tightening, in-app updater, telemetry pipeline |

---

## What changed vs the audit findings doc

The Codex audit framed the work as 3 BLOCKERs + 7 P1s + 2 P2s = ~12 items. After the user's calibration:

- **B1 Tunisia compliance** → tax management is data, not code; e-invoicing is B2B not POS; NF525 substance is shipped + certification is parallel. **Removed from code blockers.**
- **B2 server cash-count regression** → resolved by treating server as archive (PR #1 here), not by reimplementing the formula on the server.
- **B3 hardware-dependent phases** → correctly framed by Codex as a constraint, not a defect. Phase 0 device profile capture + on-terminal drill cover it pre-launch.
- **P1 Tauri capabilities** → risk-accept for deploy-phase-1, ship in deploy-phase-2.
- **P1 Manual runbooks** → PR #4 here.
- **P1 Offline print-log sync** → defer.
- **P1 Sync batch-of-one** → measure-first, fix only if needed.
- **P1 Telemetry** → post-launch.
- **P1 Discount permissive** → PR #3 here.
- **P2 Modal sizing** → post-launch UX polish.
- **P2 Workshop integration** → out of deploy-phase-1 scope.
- **Receipt legal fields (added by user)** → PR #2 here.

Net: **4 PRs (2.5–3.5 coding days + 1.5 docs days)** + the explicit risk-accept disclosure.
