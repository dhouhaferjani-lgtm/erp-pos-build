# Opus Final Audit — POS Go-Live PRs #1-#4 Merged to Dev

**Plan reviewed against:** `docs/superpowers/plans/2026-05-12-pos-go-live-plan-v2.md` (Codex r3 APPROVE)
**Local dev tip:** `289570ec` (8 commits ahead of `origin/dev`)
**Audit branch:** dev (not pushed to origin yet — operational, not part of this audit)
**Date:** 2026-05-12
**Auditor:** Opus 4.7

---

## Verdict

**ACCEPT.** All four PRs match the plan's locked architecture. Preflight green across the board. No BLOCKER findings. No P1 findings. Five P2 findings, none gating go-live.

| PR | Codex rounds | Final verdict | Preflight |
|---|---|---|---|
| #1 Z-report cash-count contract | 5 | No findings (r5) | ✓ |
| #2 Receipt legal-field conditional rendering | 1 | No findings (r1) | ✓ |
| #3 Discount fail-closed | 7 | No findings (r7) | ✓ |
| #4 Operator runbooks | 12 | APPROVED (r12) | ✓ |

Preflight on local dev tip:
- Phpstan `app/Modules/POS` level 8 — 0 errors
- Phpunit POS Feature targeted: `ServerSideZReportOverTenderTest` (3 passed), `ZReportSyncControllerSchema2Test` (12 passed), `CashCountToleranceVarianceRegressionTest` (1 passed)
- POS typecheck — 0 errors
- POS lint — 0 errors / 41 warnings (baseline preserved)
- POS Vitest — 1274 / 1274 passing across 143 files (+44 over pre-PR baseline)

---

## Plan acceptance check

### PR #1 — Z-report cash-count contract (`ZReportSyncController` + `ReportGenerationService`)

| Acceptance criterion | Status | Evidence |
|---|---|---|
| Path A: server reads expected / actual / variance / currency / count verbatim from sync wire | ✓ | `ZReportSyncController.php:244-265` |
| Path A: arithmetic-consistency 422 guard | ✓ | `validateCashCountBreakdownArithmetic` at `:271-301`; validation rules at `:85-90` |
| Path A: old denomination-only payload → 422 | ✓ | `ZReportSyncControllerSchema2Test` 12 cases incl. backward-compat rejection |
| Path B: `buildExpectedPerMethod` subtracts `change_due` once per receipt for cash | ✓ | `ReportGenerationService.php:500-533` with MAX-per-receipt-id subquery (split-cash safe) |
| Path B: cash discriminator uses `payment_method_code = 'CASH'` (snapshot) | ✓ | `:509` `UPPER(pos_receipt_payments.payment_method_code) = 'CASH'` |
| POS-client `generateZReportServer` deprecated; backend route preserved | ✓ | `apps/pos/src/api/reportApi.ts:199-207` `@deprecated`; backend `POST /pos/reports/z` still active for `apps/web/src/features/pos/api/shiftApi.ts:109` |
| `CashCountToleranceVarianceRegressionTest` continues to pass | ✓ | Re-run: 1 passed, 5 assertions |
| Wire-payload shape test in POS | ✓ | `syncService.test.ts:1406-1544` covers `cash_counts` array shape |

### PR #2 — Receipt legal-field conditional rendering (`receipt_template.rs`)

| Acceptance criterion | Status | Evidence |
|---|---|---|
| `trim().is_empty()` guard (not just `is_empty()`) | ✓ | `non_empty_trimmed()` helper at `:645-651` |
| Applied uniformly to `tax_id`, `phone`, `address_line2`, `postal_code`+`city` | ✓ | `:266-293` |
| `name` and `address_line1` unconditional (plan: mandatory fields) | ✓ | `:262, :266` |
| Rust unit tests for empty/whitespace/trimmed | ✓ | `receipt_header_omits_blank_optional_legal_fields` + `receipt_header_trims_present_optional_legal_fields` |
| Vitest data-builder test | ✓ | `buildReceiptData.test.ts:253-263` |

### PR #3 — Discount permission fail-closed + terminal context wired

| Acceptance criterion | Status | Evidence |
|---|---|---|
| `resolveDiscountAccess` fail-closed when operator null | ✓ | `discountPermissions.ts:19-25` |
| `fetchDiscountPermissions(terminalCode, operatorId)` with query params + envelope unwrap | ✓ | `discountApi.ts:14-22`; query via `apiGet`'s params arg; envelope auto-unwrapped by `apiGet` |
| Offline-first PIN path resolves cached discount perms | ✓ | `operatorStore.ts:159-180` |
| Online refresh after offline-cached accept (fire-and-forget) | ✓ | `operatorStore.ts:199-214` |
| Transient vs authoritative refresh-error differentiation | ✓ | `isTransientDiscountPermissionError` at `:72-79` (network/408/429/5xx = transient; 4xx = authoritative = flip cache closed) — addresses Codex r5 P2 cleanly |
| Terminal-derived fields read LIVE (not cached) — addresses Codex r6 P2 | ✓ | `HomePage.tsx:202-211` reads `terminal?.max_discount_percent`, `terminal?.allow_line_discounts`, `terminal?.allow_transaction_discounts` from store every render |
| 24h cache TTL | ✓ | `resolveCachedDiscountStatus` at `discountPermissions.ts:97-111` |
| Migrations extend `operator_pins` | ✓ | v33-v36 in `migrations.ts:831-885` |
| i18n keys (en + fr) | ✓ | `requiresOperator`, `permissionsUnavailable`, `permissionsStale` in both locales |
| Effective limit = `min(operator_limit, terminal_limit)`; null operator-cap = terminal wins | ✓ | `discountPermissions.ts:79-80` |
| HomePage.discountFailClosed test cases | ✓ | All 7 plan-mandated cases covered |

### PR #4 — Operator runbooks

| Acceptance criterion | Status | Evidence |
|---|---|---|
| 6 documents present | ✓ | `install.md`, `manual-update.md`, `backup.md`, `restore.md`, `support.md`, `chain-break-recovery.md` + `README.md` + `walkthrough-rehearsal.md` |
| `install.md`: Windows Time / NTP via `w32tm` + timezone `UTC+01:00` | ✓ | `:45-75` |
| `install.md`: pre-install artifact SHA-256 | ✓ | `:5-13` + `Get-FileHash` step |
| `manual-update.md`: pre/post version verification + rollback | ✓ | `:5-95` |
| `backup.md`: file list (SQLite + WAL + SHM + Tauri Store + AES key + images + logs) | ✓ | Inspected; matches plan |
| `restore.md`: PowerShell per-table COUNT(*) loop (closes r2 NIT on invalid SQL) | ✓ | `:31-45` `foreach ($Table in $Tables)` with quoted identifier |
| `restore.md`: fail-fast sidecar cleanup with `-ErrorAction Stop` | ✓ | Codex r10/r11 P2s explicitly closed |
| `support.md`: placeholder remote-tool decision record + no unattended access | ✓ | `:5-12` |
| `support.md`: log bundle command | ✓ | Inspected |
| `chain-break-recovery.md`: **escalation-only**, no operator UPDATE SQL | ✓ | `:14` "Do not run any UPDATE, DELETE, or manual edit against `offline_receipts` or `terminal_state`"; `:91` "Do not execute any SQL update" |
| `chain-break-recovery.md`: backup → capture → escalate triage | ✓ | `:86-99` |
| Recognises `chain_broken` / `chain broken` error signatures (r10 closure) | ✓ | Inspected |
| `walkthrough-rehearsal.md` acceptance evidence stub | ✓ | Present |

---

## Findings (P2 only)

### [P2] Local dev is 8 commits ahead of `origin/dev`

All four PRs are committed locally but not pushed to origin. CI hasn't run, the PRs aren't visible to teammates, and the protective layer of remote review is bypassed. The user explicitly asked me to audit the local dev branch post-merge, so this is acknowledged operational state — not a code defect. **Recommendation:** push to `origin/dev` after this audit signs off so CI runs and the work is visible.

### [P2] Path B's legacy-row exclusion is correct but under-documented

`buildExpectedPerMethod` filters `WHERE UPPER(payment_method_code) = 'CASH'` for the change_due subquery, which means rows with `payment_method_code = ''` (legacy / pre-snapshot data) are silently excluded from the cash-change subtraction. This is the **correct** behavior — legacy rows used to store `amount = cart total` (already net of change), so they shouldn't have `change_due` subtracted again. The test `test_server_side_z_report_does_not_subtract_change_due_from_legacy_net_cash_rows` validates this intent. However, the plan's wording suggested a `payment_methods.code` fallback for legacy rows, which is NOT what was implemented. **Recommendation:** add an inline comment in `buildExpectedPerMethod` explaining the deliberate legacy-row skip so a future engineer doesn't try to "fix" it by adding the fallback.

### [P2] `name` and `address_line1` printed without trim

Per the plan these are mandatory fields, so the template prints them unconditionally. If admin-side validation ever lets through a whitespace-only value, an orphan blank line appears. Defense in depth would `.trim()` the values before printing (no-op for properly-validated data). **Risk: very low** — admin validation is expected to reject these. **Recommendation:** track as a hardening item for deploy-phase-2.

### [P2] STOP-3 budget exceeded on PR #3 (7 rounds) and PR #4 (12 rounds)

The plan stated "STOP-3 fires past round 5 — brief, do not push past, hand to Opus." Codex pushed through on both. Reading the round trails: all findings were legitimate (no thrashing observed — each round closed a real edge case: r5/r6 on PR #3 closed authoritative-vs-transient refresh errors and live-terminal-derivation; r9/r10/r11/r12 on PR #4 closed WebView2 dir conventions, stale sidecar replay, and fail-fast restore cleanup). The work product is solid. But the workflow contract was violated. **Recommendation:** for future cycles, enforce STOP-3 with an explicit hand-off rather than continuing — the additional rounds had value here, but the discipline matters when findings ARE thrashy.

### [P2] Six runbook files contain `TBD` placeholders requiring fill-in before go-live

The plan explicitly designed these as placeholder decision records, but their unfilled state blocks operational readiness:
- `install.md`: expected release version, release notes URL, SHA-256
- `manual-update.md`: current / new version IDs, SHA-256
- `restore.md`: company ID, backup zip path, drill operator, Synerivia observer
- `support.md`: approved remote tool, support email contact
- `chain-break-recovery.md`: Synerivia contact details
- `walkthrough-rehearsal.md`: rehearsal record fields

**Recommendation:** treat these as a single pre-go-live checklist. None blocks code review; all block actual deployment. The user (or designated owner) fills them in before the terminal ships.

---

## Round-trail observation

PR #1 ran 5 rounds, PR #2 only 1 round (clean shot — small surgical change), PR #3 ran 7 rounds, PR #4 ran 12 rounds. The disparity is healthy: small-scope, well-bounded PRs (like #2) require less iteration; broader-scope PRs (like #4 with 6 documents covering Windows-specific paths, sidecar handling, and chain-break safety) need more. Codex's review trail discipline was strong throughout — every round produced a specific actionable finding (or APPROVE), no padding, no scope drift.

The two real Opus calibration lessons from this cycle:

- **L11 (proposed):** When deprecating an internal helper that has external callers in sibling apps (`apps/web`, `apps/api`, tooling), do a repo-wide caller check across all sibling apps before the deprecation/deletion call. PR #1's plan got this right after r1's correction; the lesson is to bake the cross-app check into the plan template, not the review.
- **L12 (proposed):** "Don't recompute" architectures need explicit data-flow comments at every place the recompute used to happen. PR #1's `buildExpectedPerMethod` is now correctly recomputing for Path B — but `ZReportSyncController` explicitly does NOT recompute for Path A (`buildBreakdownsFromSyncPayload` reads from the wire). The two paths are intentionally asymmetric and could confuse a future engineer; a header comment in `ZReportSyncController` calling out "this is an archive, see ReportGenerationService for the recompute path" would help. (Same family as the legacy-row finding above.)

---

## Recommendation

**Push `dev` to `origin/dev`** so CI runs and the four PRs land in the remote branch. Then proceed with the **Phase 3 go-live preparation** items from the plan:

1. Phase 0 device-profile finalization on the deployed terminal.
2. Restore drill on the deployed terminal (the `restore.md` evidence step).
3. Sync backlog drain measurement on the unstable Wi-Fi dongle.
4. Tunisia legal pack: accountant confirms VAT rates / register-certification scope / receipt legal-field list. Admin enters values via `Modules/Taxation` + company settings.
5. Fill the `TBD` placeholders in the six runbooks.
6. Smoke pass on the live terminal — the cashier-facing flows from PRs #120/#121/#122 + the four new ones from this cycle.

Per the plan's Phase 3 framing, none of these require additional engineering before go-live.

---

## Out of scope (deliberately not audited)

- `com.syneriva.izipos` identifier naming — internal, approved as-is per the audit prompt.
- Tauri capability tightening / CSP — deploy-phase-2.
- In-app updater — deploy-phase-2.
- Production telemetry pipeline — post-launch.
- NF525 certification process — non-code parallel track.
- Sync batch-of-N — measure-first, defer.
- Offline print-log sync — defer unless NF525 audit asks.
