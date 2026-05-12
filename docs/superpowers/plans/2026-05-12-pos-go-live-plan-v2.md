# POS Go-Live Plan v2 (+ Codex r2 amendments applied inline)

> **Supersedes:** `2026-05-12-pos-go-live-plan-v1.md`. v1 stays in tree as a historical artefact.
> v2 closes Codex round-1 findings (REQUEST-CHANGES, 0 BLOCKERs, ~12 P1s, ~7 P2s, 1 NIT).
> **Codex round-2 amendments applied inline 2026-05-12** (REQUEST-CHANGES, 0 BLOCKERs, 2 P1s, 2 P2s, 2 NITs): Path A test values now match Path B (`100.000`, not `170.000`); cash discriminator uses `payment_method_code = 'CASH'` (NOT `is_physical`); legal-field guards use `trim().is_empty()`; terminal-code source named (`useTerminalStore.getState().terminal?.code`); chain-break B1 SQL **removed from operator runbook** and downgraded to Synerivia-escalation-only; restore-drill row-count syntax fixed to a per-table PowerShell loop.
> Round-2 review: `docs/superpowers/reviews/2026-05-12-pos-go-live-plan-v2-adversarial-review.md`. Key changes:
> - **PR #1 scope corrected.** `POST /pos/reports/z` has active web-admin callers (`apps/web/src/features/pos/api/shiftApi.ts:109`). Backend route + service must stay alive; `buildExpectedPerMethod` must be FIXED (not deprecated) to subtract `change_due` for cash. Only the POS-client helper `generateZReportServer` is deprecated.
> - **PR #2 scope narrowed.** Conditional rendering for existing legal fields only (`name`, `address_line1`, `address_line2`, `city`, `postal_code`, `country`, `tax_id`, `phone`). New legal fields (registration, capital, RC) move to Tunisia legal-pack out-of-band, not PR #2.
> - **PR #3 wires terminal context.** `fetchDiscountPermissions` needs `X-Terminal-Code` header + Laravel `{ data: ... }` envelope unwrap. Offline-first PIN path gets the same effective-limit resolution as online verify-pin.
> - **PR #4 adds release provenance + NTP/clock.** Operator runbook covers artifact verification (version + checksum + post-install version check), remote-support placeholder decision record, and OS clock + timezone verification.

**For Codex (implementer) + Opus (final auditor):**
- Codex reviews this v2 plan adversarially. Iterate to APPROVE / APPROVE-WITH-MINOR-EDITS.
- Once stable, Codex implements all 4 PRs in order. Per-PR `codex review --base dev` is the per-PR gate. **Opus stays out of the per-PR loop during implementation.**
- After every PR lands on `dev`, Opus runs a single final audit against the plan's acceptance criteria.

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`
**Base branch:** `dev` (currently at `704dcfad`)
**Cross-references:** as in v1; plus Codex r1 review at `docs/superpowers/reviews/2026-05-12-pos-go-live-plan-v1-adversarial-review.md`.

---

## Goal (unchanged from v1)

Ship the four remaining items needed for TN deploy-phase-1, with explicit risk-accepts for everything else. Calibrations locked:
1. Z-reports are local source of truth for the POS-close path; web admin uses server-side recompute for remote close. **Both must compute identically.**
2. Tax management is data via `Modules/Taxation` admin endpoints.
3. NF525 substance shipped; certification parallel track.
4. E-invoicing is B2B-only.
5. Receipt legal fields render conditionally (current fields only in this plan; new fields → legal pack).

---

## Production-ready definition for deploy-phase-1 (unchanged from v1)

| Requirement | Status post-plan |
|---|---|
| Cashier flow correct end-to-end | Shipped via PRs #120, #121, #122 |
| Local Z-report correct (offline-first) | Already correct (`endOfDayPreview.ts`) |
| **Z-report archived server-side preserves device values; server-recompute path matches local formula** | **PR #1 of this plan (scope corrected)** |
| Receipt legal fields render only when configured (existing fields conditional) | **PR #2 of this plan** |
| Discount permissions fail-closed when no operator; terminal context wired | **PR #3 of this plan** |
| Operator runbooks: install (incl. NTP/clock) / manual update (incl. artifact provenance) / backup / restore (with checksums) / support (placeholder remote tool decision) / chain-break recovery | **PR #4 of this plan** |

---

## PR sequence

PRs are causally independent; recommended order below reflects priority.

---

### PR #1 — Z-report cash-count contract: archive verbatim from sync + same formula on server recompute

**Branch:** `fix/pos-zreport-cash-count-contract`

#### Why this fix (corrected from v1)

Two code paths produce Z-report cash-count breakdowns server-side, with two different bugs:

**Path A — POS-device close (offline-first), then sync:**
- Local POS computes per-method `expected_amount`, `actual_amount`, `variance_amount`, `variance_direction`, `currency_code`, `transaction_count` in `zReportService.ts:195-223`.
- The full shape is on `LocalZReport.cash_counts` (`types.ts:99,143`).
- `syncService.ts:565` sends `cash_counts: report.cash_counts ?? []` over the wire.
- **`ZReportSyncController::buildBreakdownsFromSyncPayload:228-272` throws away the per-method fields** and substitutes `expected_amount = '0.0000'`, `currency_code = 'XXX'`, derives variance from `actual − 0`. The DB CHECK passes; the data is destroyed.
- **Fix:** read every field from the payload, persist verbatim. No recomputation.

**Path B — Web-admin remote shift close:**
- `POST /pos/reports/z` (`routes.php:73`) routes to `ReportController::generateZReport` → `ReportGenerationService::generateZReport:147 → buildExpectedPerMethod:481-517`.
- Active web-admin callers exist: `apps/web/src/features/pos/api/shiftApi.ts:109`.
- `buildExpectedPerMethod` does `SUM(pos_receipt_payments.amount)` per method with **no `change_due` subtraction**.
- After PR #121's contract (`amount = tendered`), this overstates expected cash for over-tender sales.
- **Fix:** subtract `change_due` for cash-method receipts. Same formula the device uses (`opening + Σ(payment.amount) − Σ(receipt.change_due) − Σ(refunds)` for cash; non-cash uses `Σ(payment.amount)` only).

**Architectural principle (locked):** For Path A, the device is the source of truth; the server is an archive. For Path B, the server recomputes from synced receipts using the same formula the device uses. Same numbers either way given the same inputs.

#### Anchor files (verified)

- `apps/pos/src/lib/offline/zReportService.ts:195-223` — local computation (correct, no change)
- `apps/pos/src/lib/offline/types.ts:99,143` — `ZReportCountEntry` carries the shape (correct, no change)
- `apps/pos/src/lib/sync/syncService.ts:549-574` (`zReportToSyncPayload`) — wire payload (already sends full `cash_counts`; verify property names match server expectations)
- `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:78-84` — request validation rules (denomination-style fields only; needs extension)
- `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:228-272` — `buildBreakdownsFromSyncPayload` (the Path A fix site)
- `apps/api/app/Modules/POS/Application/DTOs/CashCountBreakdownDTO.php` — verify shape; extend if needed
- `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:481-517` — `buildExpectedPerMethod` (the Path B fix site)
- `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:205` — call site of `buildExpectedPerMethod` from `generateZReport`
- `apps/pos/src/api/reportApi.ts:200` — POS-client `generateZReportServer` helper (zero callers; deprecate)
- **Backend route + service stay alive:** `apps/api/app/Modules/POS/routes.php:73`, `ReportController::generateZReport`, `ReportGenerationService::generateZReport`, `buildExpectedPerMethod` — all used by web admin

#### TDD steps

1. **Failing backend Feature test for Path A (sync archive)** — `apps/api/tests/Feature/POS/ZReportSyncCashCountBreakdownsTest.php`:
   - Seed tenant + company + terminal + shift (`opening_cash = '0.000'`).
   - Seed 2 cash receipts: exact (total=50, tendered=50, change=0) and over-tender (total=50, tendered=120, change=70).
   - Local formula: `opening + Σ(payment.amount) − Σ(change_due) − Σ(refunds) = 0 + (50+120) − (0+70) − 0 = 100.000`.
   - POST sync payload with `cash_counts: [{ payment_method_id, currency_code: 'TND', expected_amount: '100.000', actual_amount: '100.000', variance_amount: '0.000', variance_direction: 'balanced', transaction_count: 2 }]` — values match the local formula.
   - Assert persisted `pos_cash_count_breakdowns` row matches the payload **verbatim** (not `expected=0`, not `currency='XXX'`).
   - **Codex r2 P1 closure:** Path A test values now match Path B (`100.000`) — both paths compute the same expected for the same input.

2. **Failing backend test for Path A — old denomination-style payload should 422.** (Codex r1 P1 closure)
   - POST a legacy payload with only `counted_amount` / `counted_quantity` per entry, missing per-method `expected_amount` etc.
   - Assert response is 422 with a clear error pointing at the new required fields.
   - Justification: user accepted reinstall of the single TN terminal; no older builds in field.

3. **Failing backend Feature test for Path B (server recompute)** — `apps/api/tests/Feature/POS/ServerSideZReportOverTenderTest.php`:
   - Seed the same shift + receipts.
   - Call `POST /pos/reports/z` with the cashier's actual count.
   - Assert `expected_amount` in the returned/persisted Z-report breakdown for the cash method = `Σ(payment.amount) − Σ(change_due) = 170 − 70 = 100`. (Current behavior would compute 170, overstating by the change_due.)

4. **Update request validation** (`SyncZReportRequest` or wherever): every `cash_counts.*.{expected_amount, actual_amount, variance_amount, variance_direction, currency_code, transaction_count}` becomes required.

5. **Implement Path A fix:** `buildBreakdownsFromSyncPayload` reads supplied fields and constructs `CashCountBreakdownDTO` directly. Currency from payload, not `'XXX'`. Expected/variance from payload, not recomputed.

6. **Implement Path B fix:** `buildExpectedPerMethod` subtracts `change_due` for **cash** payment-method receipts only. Non-cash methods continue with `SUM(amount)` only.
   - **Cash discriminator (Codex r2 P1 closure):** use the immutable snapshot `pos_receipt_payments.payment_method_code = 'CASH'` as the primary discriminator. Fall back to live `payment_methods.code = 'CASH'` only for legacy rows where the snapshot was not set (rare; check `payment_method_code IS NULL OR payment_method_code = ''`). **Do NOT use `is_physical = true`** — that flag covers checks, vouchers, and other physical-but-non-cash tenders; subtracting `change_due` from those would understate them on split tenders.
   - Implementation shape: per-method aggregation with `SUM(amount)`, and for cash rows additionally subtract the receipt-level `change_due` once per receipt (a `LEFT JOIN` against a per-receipt cash-change subquery, or a conditional aggregation pattern that avoids double-subtraction on split-tender receipts).
   - Codex verifies the shape against `CashCountToleranceVarianceRegressionTest.php` (tolerance short-pay scenario must still pass — `change_due=0` there, so no arithmetic change).

7. **Frontend wire-payload completeness** — `apps/pos/src/lib/sync/__tests__/zReportSyncPayloadShape.test.ts`: serialize a representative `LocalZReport` and assert every required field is present on each `cash_counts` entry. Confirm property names match server.

8. **Deprecate POS-client helper:** add `@deprecated` JSDoc to `apps/pos/src/api/reportApi.ts:200` (`generateZReportServer`). Note: no production caller; kept for future tooling that may need a server-side fallback. **Backend route + service stay alive** (web admin uses them).

#### Acceptance

- Backend tests pass:
  - Path A archive correctness (new fields persisted verbatim).
  - Path A backward-compat rejection (old denomination payload → 422).
  - Path B recompute matches local formula for over-tender.
  - Pre-existing `CashCountToleranceVarianceRegressionTest` still passes (tolerance short-pay scenario unaffected by the `change_due=0` arithmetic).
- Frontend test passes (wire shape complete).
- `pnpm typecheck` 0 errors, lint baseline (41 warnings) preserved.
- `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/POS --memory-limit=1G` → 0 errors at level 8.
- `cd apps/api && ./vendor/bin/pint --test` on touched files → pass.
- `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/` → all green; new tests included.
- PR body documents the architectural decision: both paths use the same formula; Path A = archive verbatim, Path B = server recompute matching local formula.

#### Codex review focus

- Confirm Path B fix touches only `buildExpectedPerMethod` (the cash-only `change_due` subtraction), not other report aggregations.
- Verify the back-compat 422 decision is explicit + the migration note is in the PR body.
- Cross-check that `apps/web/src/features/pos/api/shiftApi.ts:109` callers still work post-fix (the response shape doesn't change, only the per-method expected number).

---

### PR #2 — Receipt legal-field conditional rendering (scope narrowed per Codex r1 P1)

**Branch:** `fix/pos-receipt-conditional-legal-fields`

#### Why this fix (scope corrected from v1)

`apps/pos/src-tauri/src/printing/receipt_template.rs:263-274` renders `Tax ID:` unconditionally even when `data.company.tax_id` is empty. The conditional pattern already exists for `address_line2` (`if let Some(...)`) and `phone` (`if let Some(ref phone)`); apply uniformly. Plus harden against `Some("")` empty-string cases (per Codex r1 P1 — current pattern only guards `None`).

**Scope is the existing receipt company struct only** (verified at `receipt_template.rs:145-153`):

- `name`, `address_line1` — always printed (mandatory for any business; if empty, that's a data error not a rendering decision).
- `address_line2`, `phone` — conditional today (`Option<String>`); add empty-string guard for `Some("")`.
- `postal_code` + `city` — printed together; if both empty, omit the line.
- `country` — stored but not currently printed.
- `tax_id` — **the main fix:** make conditional with `!is_empty()` guard.

**Out of scope for this PR** (per Codex r1 P1): adding new legal fields (`registration_number`, `capital`, address line 3, RC number, etc.) — those move to the Tunisia legal-pack out-of-band, not PR #2. PR #2 is "make existing fields conditional"; new-field-add is a separate concern.

#### Anchor files (verified)

- `apps/pos/src-tauri/src/printing/receipt_template.rs:115-153,263-274` — Rust template + company struct
- `apps/pos/src-tauri/src/printing/voucher_ticket.rs:128-130` — already empty-string-guards `address_line2`; use as reference pattern
- `apps/pos/src/lib/buildReceiptData.ts:120-140,220-225,295-305,378-390` — TS data builder

#### TDD steps

1. **Failing Rust unit tests** in `apps/pos/src-tauri/src/printing/receipt_template.rs` test module:
   - `data.company.tax_id = ""` → output bytes contain NO `Tax ID` substring.
   - `data.company.tax_id = "1234"` → output contains `Tax ID: 1234`.
   - `data.company.address_line2 = Some("")` → output omits the line (currently rendered as blank line).
   - `data.company.phone = Some("")` → output omits the phone line.
   - `data.company.postal_code = ""` AND `city = ""` → omit the combined line.

2. **Apply conditional pattern uniformly** in `receipt_template.rs`. Use `trim().is_empty()` (Codex r2 NIT closure) so whitespace-only values are also treated as empty:
   ```rust
   if !data.company.tax_id.trim().is_empty() {
       b.text_line(&format!("{} {}", data.label(|l| &l.tax_id, "Tax ID:"), data.company.tax_id));
   }
   ```
   For `Option<String>` fields, combine `is_some()` + `trim().is_empty()`:
   ```rust
   if let Some(ref addr2) = data.company.address_line2 {
       if !addr2.trim().is_empty() {
           b.text_line(addr2);
       }
   }
   ```

3. **Vitest snapshot test** for `apps/pos/src/lib/buildReceiptData.ts`: with empty `tax_id` from server, the produced receipt-data JSON either omits `tax_id` (preferred) or includes empty value that Rust-side guard then omits. Either approach is acceptable per Codex r1 P2; pick one and document the decision in the PR body.

4. **Audit `buildReceiptData.ts`** for any other defaulted `?? ''` legal field; trace through to the Rust template. Document the full list of conditional fields in the PR body.

#### Acceptance

- Rust unit tests + Vitest snapshots green for every existing legal-field × empty/filled combination.
- No orphan label lines (`Tax ID: `, `Tel: `, etc.) printed for empty fields.
- `pnpm test` (apps/pos) — 0 regressions, lint baseline preserved.
- Rust `cargo test --manifest-path apps/pos/src-tauri/Cargo.toml` → green.
- PR body lists every conditional-rendered field in the receipt template + the data-layer decision (omit-or-empty).

#### Out of scope (deliberately, per Codex r1 P1)

- Adding new legal fields (`registration_number`, `capital`, address line 3, RC number, `matricule_fiscal` if distinct from `tax_id`, etc.). If the Tunisia accountant pack requires these, they ship in a separate PR scoped as "extend receipt legal-field schema." This PR is conditional rendering only.

#### Codex review focus

- Verify no orphan labels remain.
- Confirm scope discipline (no new fields added).
- Check that `Some("")` is guarded (not just `None`).

---

### PR #3 — Discount permissions fail-closed + wire `discountApi` with terminal context

**Branch:** `fix/pos-discount-permissions-fail-closed`

#### Why this fix (corrected from v1)

Three concrete gaps (Codex r1 P1):
1. `HomePage.tsx:200` defaults missing operator `can_discount` to `true`, `max_percent` to `100` — cash-skim risk.
2. `apps/pos/src/api/discountApi.ts:9-10` calls `apiGet<DiscountPermissions>('/pos/discount-permissions')` with no terminal context. Backend `DiscountController.php:53-58` requires `X-Terminal-Code` header (or `terminal_code` query) and returns Laravel `{ data: ... }` envelope. **Current wiring would 4xx if called.**
3. Offline-first PIN path: `operatorStore.verifyPin` accepts cached SQLite operators BEFORE the server verify, then fire-and-forgets the API. If discount permissions are fetched ONLY after online verify, offline cashiers never get terminal-aware limits.

**Architectural principle (locked):** Fail-closed when the principal is missing or the terminal context is unknown. Effective limit = `min(operator_limit, terminal_limit)` where:
- Operator must be loaded.
- `operator.can_discount` must be true.
- `terminal.max_discount_percent` must be known and > 0.
- `operator.max_discount_percent = null` means "no individual cap; terminal limit wins" (per Codex r1 P2 — current server contract).
- Effective limit > 0 → discount allowed up to that ceiling; otherwise UI disabled.

#### Anchor files (verified)

- `apps/pos/src/pages/HomePage.tsx:200` — permissive defaults
- `apps/pos/src/api/discountApi.ts:9-10` — current call (missing terminal code + envelope unwrap)
- `apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php:38,43-58,97-106` — server contract
- `apps/pos/src/stores/operatorStore.ts` — `verifyPin` offline-first cached-then-online path
- `apps/pos/src/lib/db/repositories/operatorPinRepository.ts` — cached operator rows; verify whether discount fields are persisted

**Memory-note reconciliation (per Codex r1 P2):** the note at `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/project_discount_permissions.md` says super-admin bypass + default terminal `max_discount_percent` = 100 still need to be built. **This is stale** — current `PosAuthController.php` grants admin/super-admin discount rights, and migration `2026_03_23_200000_fix_discount_permission_defaults.php` already set the default. Codex must reconcile against current code; do NOT re-implement these.

#### TDD steps

1. **Failing Vitest tests** — `apps/pos/src/pages/__tests__/HomePage.discountFailClosed.test.tsx`:
   - operator = null → discount UI disabled, tooltip `discount.requiresOperator`.
   - operator.can_discount = false → discount UI disabled.
   - operator.can_discount = true, operator.max = null, terminal.max = 10 → effective limit = 10 (per server contract where null operator-cap = terminal wins).
   - operator.can_discount = true, operator.max = 5, terminal.max = 10 → effective limit = 5.
   - operator.can_discount = true, terminal.max = 0 → effective limit = 0 → UI disabled.

2. **Failing Vitest test** for `discountApi` wiring — `apps/pos/src/api/__tests__/discountApi.terminalContext.test.ts`:
   - Mock fetch; call `fetchDiscountPermissions(terminalCode)`.
   - Assert outbound request includes header `X-Terminal-Code: <code>` (or query `?terminal_code=`).
   - Assert response shape `{ data: DiscountPermissions }` is unwrapped correctly.

3. **Failing Vitest test** for `operatorStore.verifyPin` discount-permissions resolution:
   - Offline-cached operator path → discount permissions resolved from cached SQLite (last-known terminal-aware values).
   - Online-API operator path → calls `fetchDiscountPermissions(terminal_code)` after verify-pin success, merges into operator state.

4. **Apply fail-closed default** in `HomePage.tsx`: when operator is null OR `can_discount` is false OR terminal limit unknown/0, render discount UI in disabled state with tooltip.

5. **Wire `fetchDiscountPermissions` with terminal context:** update the signature to take `terminalCode: string`, inject the header, unwrap the envelope. Call from operatorStore after verify-pin success path (BOTH online + offline-cached).
   - **Terminal-code source (Codex r2 NIT closure):** read from `useTerminalStore.getState().terminal?.code` at the call site. The terminal is loaded before operator-verify-pin via `useTerminalActivation` / pairing flow, so it's safely available. If `terminal` is null at the moment of verify-pin (impossible in normal flow but defensive), fail-closed (operator loads with no terminal-aware discount perms; UI shows the `discount.permissionsUnavailable` tooltip).

6. **Offline cache strategy:**
   - On successful `fetchDiscountPermissions`, persist `{ operator_id, can_discount, max_percent, fetched_at }` to SQLite (extend `operator_pins` row or add a small `operator_discount_perms` table — Codex picks the simpler shape).
   - On offline-cached PIN success, read the cached discount perms for that operator.
   - Cache freshness: 24 h. Past 24 h, fail-closed with tooltip `discount.permissionsStale`.
   - No cache for this operator → fail-closed with tooltip `discount.permissionsUnavailable`.

7. **i18n keys:** `discount.requiresOperator`, `discount.permissionsUnavailable`, `discount.permissionsStale`. Add `fr` + `en` translations. (Arabic: out of scope unless added in §5 of audit plan.)

#### Acceptance

- All new Vitest cases pass.
- Operator-null state: discount UI disabled with tooltip.
- Terminal-context wiring works against the real backend (mocked in test; verified by Codex in the request shape).
- Offline-cached operator path resolves discount perms correctly.
- 24 h freshness threshold enforced; older cache fails closed.
- Server-side enforcement unchanged (still rejects via `DiscountEnforcementTest` paths); confirm no client-side bypass.
- Lint baseline preserved.

#### Codex review focus

- Verify no remaining code path defaults `can_discount=true` with null operator.
- Confirm cache freshness threshold + offline-path resolution.
- Check that the memory-note stale items (super-admin bypass, default 100%) are NOT re-implemented.

---

### PR #4 — Operator runbooks (with release provenance + remote-support decision + NTP/clock)

**Branch:** `docs/pos-operator-runbooks`

#### Why this work (expanded per Codex r1 P1)

Same rationale as v1 — operator is in TN, not the developer, manual update has no in-app updater. Expanded scope per Codex round 1:

- **Release artifact provenance:** manual update must verify which exact `.exe` is being installed (version + checksum + post-install version check).
- **Remote support placeholder decision record:** Phase 0 device profile leaves the remote-support tool as "ad hoc / TBD." The runbook must include a placeholder decision (e.g., "AnyDesk with consent prompt, 30-day session timeout, log retrieval via operator-zipped bundle") that the user fills in before go-live.
- **OS clock / NTP setup** (Codex r1 P2): fiscal hashes, receipt timestamps, shift close, Z-report timestamps, voucher / audit records all depend on trustworthy local time. Windows Time service config + timezone verification at install. Must be in `install.md`.
- **Chain-break SQL hardening** (Codex r1 P2): the one-line escape hatch needs explicit triage steps — backup first, identify exact receipt(s) by `id` + `hash_sequence`, preserve sequence order, escalate if hash mismatch is confirmed (vs retry-cap/network artifact).

#### Output files (all under `docs/pos-operations/`)

1. **`install.md`** — Windows 11 first install. Includes:
   - Prerequisites + download source.
   - **Artifact verification:** version, SHA-256 checksum, signing-cert thumbprint if any.
   - Install steps, pairing-token entry, initial sync of catalog, PIN setup.
   - **Windows Time / NTP verification:** confirm Windows Time service running, NTP source configured to `time.windows.com` or local NTP server, timezone = `(UTC+01:00) West Central Africa` (Tunisia GMT+1 no DST).
   - **Post-install verification:** confirm running version matches expected; smoke-test a cash sale + void.
   - Reference: `docs/superpowers/plans/2026-05-11-tunisia-customer-rollout.md`.

2. **`manual-update.md`** — swap-binary procedure for deploy-phase-1. Includes:
   - **Pre-update:** identify the new artifact (version, checksum, Synerivia release notes URL), confirm signing if applicable.
   - Stop the running POS; take a full snapshot of app data.
   - Files to preserve (exact Windows paths — Codex verifies on the deployed terminal):
     - `%APPDATA%\com.syneriva.izipos\izipos-${companyId}.db`
     - `%APPDATA%\com.syneriva.izipos\izipos-${companyId}.db-wal`
     - `%APPDATA%\com.syneriva.izipos\izipos-${companyId}.db-shm`
     - `%APPDATA%\com.syneriva.izipos\izipos-settings.json` (Tauri Store)
     - `%APPDATA%\com.syneriva.izipos\.izipos_key` (AES key)
     - `%APPDATA%\com.syneriva.izipos\images\**`
     - log files (per `tauri-plugin-log` configured sink)
   - **Swap procedure:** install new `.exe` (or installer-driven); files above are preserved by the installer OR manually copied.
   - **Post-update verification:** launch, verify version match in About screen, verify catalog visible, verify last Z-report still in history, ring a test sale → void it.
   - **Rollback:** restore previous `.exe` from backup folder. Required: keep N-1 build available locally.

3. **`backup.md`** — what files to copy, where they live on Windows 11 (verified paths), backup frequency, where to store.

4. **`restore.md`** — restore drill procedure with **checksums + row counts** (Codex r2 NIT closure on row-count syntax):
   - Pre-state: list of files + SHA-256 checksum + SQLite row counts per table.
   - **Row-count capture procedure (NOT a single SQL query):** the operator runs a PowerShell loop (or equivalent script) that enumerates table names via `SELECT name FROM sqlite_master WHERE type='table'` and, for each table, runs `SELECT COUNT(*) FROM <name>` separately. The runbook includes the script verbatim. (Reason: SQLite's `[name]` in a subquery is an identifier literal, not dynamic SQL.)
   - Procedure: copy backup → relaunch → re-check.
   - Diff = expected (only `synced_at` / `updated_at` timestamps may diff for rows updated by post-restore sync; otherwise identical).
   - **The restore drill IS the acceptance evidence** — must be executed on the deployed terminal once it's in TN.

5. **`support.md`** — remote support runbook:
   - **Placeholder decision record (filled in before go-live):**
     - Approved tool: TBD (recommendation: AnyDesk or TeamViewer with explicit consent prompt; user fills in)
     - Authentication: per-session code generated by operator at request time
     - Consent: explicit verbal + on-screen prompt
     - Unattended access: NOT permitted at deploy-phase-1
     - Offline fallback: operator zips log bundle (one command in the runbook) and emails to `support@otospex.com` (or the actual support address; user fills in)
   - **Log bundle command:** PowerShell one-liner that zips `%APPDATA%\com.syneriva.izipos\*.log` + `izipos-settings.json` (token redacted) + last 24 h `sync_log` SQLite export.

6. **`chain-break-recovery.md`** — operational runbook (replaces PR C):
   - **Symptom triage:**
     - Red banner "Chaîne fiscale rompue."
     - Cashier sees intermittent "Échec du paiement" OR receipts don't appear in `/pos/receipts` server-side.
   - **Diagnosis steps (run in order):**
     - Check connectivity (POS → API → Reverb).
     - Inspect `sync_log` for recent errors: `SELECT * FROM sync_log WHERE created_at > datetime('now', '-1 hour') ORDER BY created_at DESC;`
     - Check `offline_receipts` for stranded rows: `SELECT id, receipt_number, hash_sequence, status, retry_count, sync_error FROM offline_receipts WHERE status = 'failed' ORDER BY hash_sequence;`
   - **Recovery decision tree:**
     - **A. Retry-cap saturation only** (`sync_error LIKE '%database is locked%'`) → PR #120's auto-recovery hook handles it on next app launch. Operator action: restart the app. Verify rows transition to `pending`.
     - **B. Hash-chain mismatch** (`sync_error LIKE '%Hash chain%'`) → fiscal-state divergence. **Operator action (Codex r2 P2 closure): STOP and escalate to Synerivia.** Do NOT execute any `UPDATE` on `offline_receipts` or `terminal_state` from the operator side. Hash-chain recovery requires reconciling local `terminal_state.last_hash`/`hash_sequence`, subsequent receipts' `previous_hash` values, and local Z-report totals — none of which is safe to attempt from an operational SQL escape hatch. Synerivia performs the reconciliation out-of-band (eventual PR C work, executed manually by Synerivia engineers).
     - **C. Unknown error class** → escalate to Synerivia with log bundle.
   - **Pre-escalation checklist (always):**
     - Backup the SQLite database files (`.db` + `.db-wal` + `.db-shm`) BEFORE Synerivia connects.
     - Run the support log-bundle command (per `support.md`).
     - Capture: exact receipt IDs in failed state (`SELECT id, receipt_number, hash_sequence, status, retry_count, sync_error FROM offline_receipts WHERE status = 'failed' ORDER BY hash_sequence;`), current `terminal_state.last_hash` + `hash_sequence`, the last 10 entries of `sync_log`.
     - Email the bundle + capture to `support@otospex.com` (or actual support address) with subject `CHAIN_BREAK <terminal_code> <YYYY-MM-DD>`.
     - Document the action in a recovery log (operator name, timestamp, captured state).
   - **Hash-mismatch recovery is Synerivia-only at deploy-phase-1.** A future deploy-phase-2 may ship in-app recovery UX (PR C as drafted in the bugs-cascade plan), but for now the operator's job is back-up + capture + escalate.

#### Acceptance

- All 6 documents written.
- **Walkthrough rehearsal:** another teammate reads each doc and explains back what would happen step-by-step. Document the rehearsal feedback as a section in each doc OR a single `walkthrough-rehearsal.md` evidence file.
- **Restore drill executed on the deployed terminal:** pre-state checksums + row counts captured, manual update procedure run against a backup, post-state captured, diff documented in `restore.md` as the canonical evidence section.
- **Remote support placeholder decision record filled in** by the user before go-live. The PR can ship with TBD placeholders; the user signs off on the actual choices in a separate document or PR amendment.
- **NTP / clock verification** included in `install.md` with explicit Windows Time + timezone steps.

#### Codex review focus

- Completeness of the file list.
- Real Windows paths (Codex spot-checks against `tauri.conf.json` identifier `com.syneriva.izipos` — confirms App Data location).
- Chain-break runbook safety (backup-first + exact ID selection + escalation criteria).
- NTP/clock instructions concrete enough for a non-developer operator.

---

## Out-of-scope risk-accepts (deploy-phase-2 or post-launch)

Unchanged from v1, plus cross-link improvement per Codex r1 NIT:

1. **Tauri capabilities are permissive.** Mitigations in `support.md` (device physical security + approved-tool remote support + stolen-terminal runbook). Tightening ships in deploy-phase-2.
2. **No production telemetry.** Local logs only; manual retrieval via `support.md` log bundle command (cross-link added per Codex r1 NIT). Sentry+Browser+Rust SDK post-launch.
3. **Sync batch-of-one.** Measure at Phase 0 device-profile or pre-launch on the unstable Wi-Fi dongle. Implement chunked push only if budget exceeded.
4. **Offline print-log sync** gap. Defer unless NF525 audit specifically asks.
5. **In-app updater** absent. Manual swap procedure (`manual-update.md` with release provenance) for deploy-phase-1.

---

## Workflow (unchanged from v1)

### Phase 0 — Codex adversarial review on this v2 plan
- Iterate to APPROVE or APPROVE-WITH-MINOR-EDITS.
- Save trail to `docs/superpowers/reviews/2026-05-12-pos-go-live-plan-v2-adversarial-review.md`.

### Phase 1 — Codex implements all 4 PRs
- Standard cadence per PR: branch, TDD red, implement, push, `codex review --base dev` to APPROVE, save trail.
- PR order: #1 → #2 → #3 → #4 (or parallel — they're causally independent).
- **Opus does NOT review per-PR during implementation.**

### Phase 2 — Opus final audit
- After all 4 PRs merged to `dev`, Opus runs a single audit pass against this plan.
- Output: `docs/superpowers/reviews/2026-05-1X-pos-go-live-opus-final-audit.md`.

### Phase 3 — Go-live preparation
- Phase 0 device-profile finalization (OS arch, printer model, drawer pulse, scanner, network, remote support tool).
- Restore drill on the deployed terminal (the PR #4 acceptance step).
- Sync backlog drain measurement (out-of-scope #3 evidence step).
- Tunisia legal pack: accountant confirms VAT rates, register-certification scope, legal-field list.
- Smoke pass on the live terminal.

---

## Glossary (unchanged from v1)

| Identifier | Meaning |
|---|---|
| PR #120 / #121 / #122 | Bugs-cascade fixes merged 2026-05-11 |
| v3 audit plan | `docs/superpowers/plans/2026-05-12-pos-production-readiness-audit-plan-v3.md` |
| Audit findings | `docs/superpowers/audits/2026-05-12-pos-production-readiness-findings.md` |
| Opus calibration | `docs/superpowers/reviews/2026-05-12-pos-production-readiness-audit-findings-opus-review.md` |
| Go-live plan v1 (superseded) | `docs/superpowers/plans/2026-05-12-pos-go-live-plan-v1.md` |
| Codex r1 review | `docs/superpowers/reviews/2026-05-12-pos-go-live-plan-v1-adversarial-review.md` |
| deploy-phase-1 | This deployment: one TN client, one terminal, single tenant, manual update procedure |
| deploy-phase-2 | Future deploy: Tauri capability tightening, in-app updater, telemetry pipeline |
