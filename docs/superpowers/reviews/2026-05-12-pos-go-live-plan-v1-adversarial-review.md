# POS Go-Live Plan v1 — Adversarial Review

Scope: `docs/superpowers/plans/2026-05-12-pos-go-live-plan-v1.md`, spot-checked against the current worktree on 2026-05-12.

## PR #1 — Z-Report Sync Preserves Per-Method Cash-Count Breakdowns

- [P1] The fix is real and correctly anchored, but the plan should explicitly handle the current schema-v2/v1 mismatch in the sync tests. `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:78-84` validates denomination-style fields only (`counted_quantity`, `counted_amount`) while `apps/pos/src/lib/offline/types.ts:66-73` and `apps/pos/src/lib/sync/syncService.ts:565` already carry per-method `currency_code`, `expected_amount`, `actual_amount`, `variance_amount`, `variance_direction`, and `transaction_count`. At `ZReportSyncController.php:216-272`, the controller explicitly aggregates `counted_amount`, sets `expectedAmount = '0.0000'`, sets `currencyCode = 'XXX'`, and derives variance from actual. The PR is necessary, but the TDD step should require a negative test proving old denomination payloads now 422.

- [P1] The deprecation/deletion language is too loose. `apps/pos/src/api/reportApi.ts:200` defines `generateZReportServer()` and repo-wide search found no `apps/pos/src` production callers, so deleting/deprecating the POS client helper is safe. However, `/pos/reports/z` still has web/admin and backend test usage (`apps/web/src/features/pos/api/shiftApi.ts`, `ReportController`, and many `GenerateZReport*` tests). The plan already says "zero callers in production POS", but its "delete entirely" option must not be read as deleting the backend route/service without a separate web-admin decision.

- [P2] The acceptance criteria are mostly measurable, but "Phpstan POS module" is ambiguous in this Laravel monorepo. Name the exact command, likely from `apps/api`, so Codex does not guess between full `./vendor/bin/phpstan` and a path-filtered run.

- [NIT] The prompt path `apps/api/app/Http/Controllers/POS/ZReportSyncController.php` is stale; the plan's anchor path under `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php` is correct.

Architectural verdict: server-as-archive is the correct decision under the user calibration. The 422 backward-compat choice is safe for the stated single-terminal reinstall, provided PR #1 includes the old-payload 422 test and the migration/reinstall note.

## PR #2 — Receipt Legal-Field Conditional Rendering

- [P1] The plan overstates the current legal-field surface. The Rust receipt company struct only has `name`, `address_line1`, `address_line2`, `city`, `postal_code`, `country`, `tax_id`, and `phone` (`apps/pos/src-tauri/src/printing/receipt_template.rs:145-153`). Of those, the template prints address line 1 unconditionally, address line 2 conditionally only on `Some`, postal/city unconditionally as a combined line, phone conditionally only on `Some`, and tax ID unconditionally (`receipt_template.rs:263-274`). `registration_number`, `capital`, address line 3, and RC number are not present in the current receipt data model or template. The TDD step "repeat for every conditional legal field ... registration_number, capital, address line 3, RC" is not executable without adding new schema/API/TS/Rust fields, which the plan does not scope.

- [P1] Conditional rendering should include empty-string guards for `Option<String>` fields, not just presence. The plan correctly identifies the `Tax ID:` orphan line at `receipt_template.rs:274`, but `address_line2` and `phone` can still render blank lines/orphan labels if upstream sends `Some("")`; voucher printing already guards address line 2 emptiness at `apps/pos/src-tauri/src/printing/voucher_ticket.rs:128-130`. The acceptance criteria should require trimming/empty checks for every rendered optional company field.

- [P2] The data-layer instruction "do not do both ways" is too rigid. Keeping current `buildReceiptData.ts` defaults for required Rust strings and guarding in Rust is acceptable for deploy-phase-1. Changing TS types to distinguish absent vs explicit empty is broader than needed unless new nullable Rust fields are introduced.

- [P2] The admin validation rule is under-anchored. The plan says verify `apps/api/.../Company` endpoints accept null/empty and reject whitespace-only sentinels, but does not name the actual request/resource files. Codex will need to re-read the worktree to find them.

Other legal/administrative fields currently in or implied by the template: company name, address line 1, address line 2, postal code, city, country (stored, not printed), phone, tax ID. VAT breakdown is fiscal detail, controlled separately by `show_vat_breakdown`, not a company legal header field.

Architectural verdict: conditional legal-field rendering is correct. The plan needs to separate "make current fields conditional" from "add missing legal fields if the accountant requires them"; the latter belongs in the Tunisia legal-pack/go-live prep unless explicitly promoted to PR #2.

## PR #3 — Discount Permissions Fail-Closed + Wire `discountApi`

- [P1] The plan misses two concrete integration requirements for `fetchDiscountPermissions()`. The backend endpoint requires `X-Terminal-Code` header or `terminal_code` query (`DiscountController.php:43-54`), and returns a Laravel `{ data: ... }` envelope (`DiscountController.php:97-106`). `apps/pos/src/api/discountApi.ts:9-10` currently calls `apiGet<DiscountPermissions>('/pos/discount-permissions')` with no terminal code and assumes a flat response. The TDD steps must include terminal-code injection and response shape validation, otherwise PR #3 can "wire" the API and still fail at runtime.

- [P1] The offline-cache step conflicts with the existing offline-first PIN path unless it is made explicit. `operatorStore.verifyPin` accepts cached SQLite operators before the API and returns immediately; only then it fire-and-forgets `/pos/auth/verify-pin`. If discount permissions are fetched only after online verify-pin, offline-accepted operators will never receive terminal-aware limits. The plan should require the same effective-limit resolution for both offline cached PIN success and online API success.

- [P2] The memory note is partially stale. It correctly says `discountApi.ts` exists and is never called, and that terminal-aware limits are ignored by the POS client. It is stale where it says super-admin bypass/defaults still need to be built: current `PosAuthController.php` grants admin/super-admin discount rights, and `2026_03_23_200000_fix_discount_permission_defaults.php` updates terminal default `max_discount_percent` to 100. The plan should say "reconcile note; do not re-implement stale items."

- [P2] The fail-closed architectural decision is correct, but the wording "both must be known and both must be positive" should account for the existing server rule that `cashier.max_discount_percent = null` means no individual limit and terminal limit wins. The plan's own test case covers that, so tighten the principle to "operator permission known; terminal limit known; effective limit > 0."

Architectural verdict: fail-closed discount behavior is correct. TDD concreteness is close, but not executable without the terminal-code/envelope/cache-path details above.

## PR #4 — Operator Runbooks

- [P1] The runbook scope is not complete for a manual Windows 11 swap update because it does not require release artifact provenance. For deploy-phase-1 with no updater, `manual-update.md` must tell the operator/Synerivia which exact artifact to install, where it came from, expected version, checksum, and how to verify the running app version after launch. Without this, preserving app data is documented but installing the intended build is not.

- [P1] The support runbook cannot leave the approved remote-support tool as "to-be-decided" while also being an acceptance item. The plan says Phase 0 will finalize it, but PR #4 output should include a placeholder decision record with the actual tool, consent model, authentication, unattended-access policy, and offline fallback before go-live.

- [P2] The backup file list is directionally right but should name the actual app data root and key location. The key command uses `com.syneriva.izipos` for `.izipos_key`, while the Tauri Store uses `izipos-settings`; docs should not rely on `%APPDATA%\\izipos` if the actual Windows paths differ by Tauri plugin. Acceptance should require the docs to verify paths on the real Windows 11 terminal.

- [P2] The chain-break SQL escape hatch is too underspecified to be safe. The plan says "UPDATE offline_receipts SET status='pending', retry_count=0 WHERE ..." but the WHERE clause is the entire safety boundary. The runbook must require backing up first, selecting the exact receipt(s), preserving fiscal sequence order, and escalating if previous-hash/fiscal-hash mismatch is confirmed rather than just a retry-cap/network artifact.

- [P2] Missing deploy-phase-1 item not covered by the plan or risk-accept list: OS clock/NTP setup and timezone verification. Fiscal hashes, receipt timestamps, shift close, Z-report timestamps, and voucher/audit records all depend on trustworthy local time. The broader v3 audit plan had this as a requirement; this go-live plan should include it in `install.md` or `support.md`.

Architectural verdict: operator runbooks are the right replacement for in-app updater/chain-break UX deferrals in phase 1, but the docs PR needs release provenance, real Windows paths, remote-support finalization, and clock verification to be go-live complete.

## Out-of-Scope Risk Accepts

- [P2] The deferrals are mostly bounded and aligned with the user calibration: permissive Tauri capabilities, production telemetry, batch-of-one sync, offline print-log sync, and in-app updater are explicit. However, the missing OS clock/NTP setup is not a deferrable platform hardening item; it is a basic installation prerequisite.

- [NIT] The no-telemetry risk accept should cross-link to the support runbook's log bundle command and retention policy once PR #4 exists, so the mitigation is auditable rather than prose.

## Overall Verdict

The four-PR framing is directionally correct and respects the calibrations: local Z-report source of truth, conditional legal fields, fail-closed discount permissions, and docs-first operational recovery are the right architectural choices for deploy-phase-1. The plan is not yet precise enough for implementation without re-reading the worktree in PR #2, PR #3, and PR #4. Fix the executable details above before handing it to Codex as an implementation plan.

REQUEST-CHANGES
