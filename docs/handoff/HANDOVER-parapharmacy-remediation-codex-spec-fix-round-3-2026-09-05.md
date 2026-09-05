# Handover to Codex — parapharmacy remediation spec, fix round 3 (v3 → v4), narrow

Date: 2026-09-05. HEAD `fa000edc3` (+ committed v3 docs). Role split unchanged: Fable orchestrates, gates, commits, merges, promotes; Codex revises the spec in the working tree and stops. No code, tests, migrations, commits, pushes.

## Read first

`docs/superpowers/reviews/2026-09-05-parapharmacy-remediation-gate-r3-synthesis.md` and the four r3 lens files it links. All six r2 blockers and all seventeen majors are CLOSED; do not reopen them. This round fixes two blockers your v3 corrections introduced, plus the listed majors.

## Required in v4

**R3-B1 (spec:165, W2 non-money class).** Keep the shared POS payment journal entry for every leg. The `non_money_destination` class overrides only the debit account (voucher liability, loyalty liability, customer receivable, instrument collection) and suppresses the payment-repository movement. That is the shipped maturity-leg shape: `TreasuryReceiptBridge.php:1457-1458` (`$cashAccountOverrideId`) and `:1556` (`$shouldRecordMovement = false`). Cite `GeneralLedgerService.php:3944-3966` as the sole writer of the sale's proportional revenue and VAT lines and `ReceiptPaymentService.php:372-385` / `PosCoreReceiptProjection.php:1616-1632` for the voucher clearing debit that must keep its offset. Add a W2 acceptance row: voucher-tendered sale → revenue + per-rate VAT + clearing debit posted, zero repository movement, W4-9 identity holds. Name the account purposes LOYALTY and MEAL_VOUCHER need (none exist at HEAD) as a W2 seeding obligation.

**R3-B2 (spec:143, 188, terminal-scoped projection).** State the terminal authority explicitly. Today `terminal_id` is caller-supplied and only tenant+company validated (`ClaimTerminalRequest.php:33-40`); the payment-policy precedent is company-wide and ungated (`PosPaymentPolicyController.php:13-20`); no terminal middleware exists. v4 must specify: the projection is filtered by the claimed terminal's **persisted** `location_id` read server-side from the terminal record, the session/token binding that proves the caller is that terminal, and that both are W2 prerequisites to build, not existing behaviour. Add acceptance: branch-A terminal cannot read or bind branch-B custody; forged `terminal_id` refused.

**Majors** (each closed by a spec change, or rejected with a citation in the notes): ProductDetailDrawer `stock_lots` tab into W6 seams + R2 gating; spec:202 drain clause replaced by seal-time authored classification; `treasury.manage` reseed clause for the rebind surface; the blocked-row writer for unknown entitlement (registry excludes silently today); where the company-ownership entitlement check actually executes (not inside the central-only resolver); `expiring()` `location_id` validation; W5/L4 positive-arm DEFAULT auto-credit (`StockAdjustmentService.php:1446-1447`) vs L9 ordering; L6 test obligation for the PG-only drift query; `PaymentInstrumentKind` declared as an existing classification source; resolve the W1↔W2 circular dependency rows by naming one owner of the terminal projection.

**Minors:** fix the `zReportService.ts:753` pointer and the inventory/authz/treasury minors listed in the synthesis.

## Deliverables

Spec v4 in place with a v3→v4 change log; glossary touch-ups if needed; `docs/superpowers/reviews/2026-09-05-parapharmacy-remediation-codex-fix-round-3-notes.md` with per-item disposition and the unchanged twelve-row OPEN register. Stop. Fable runs gate r4 (treasury + authz lenses).
