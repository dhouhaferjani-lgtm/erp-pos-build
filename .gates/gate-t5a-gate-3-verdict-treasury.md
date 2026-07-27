GATE VERDICT: APPROVE

Adversarial review executed by the pinned `treasury-reviewer` agent (Opus) over the full `t5a-gate-2..HEAD` range (`a99a26855..0dc08a2fa`), with every load-bearing citation independently re-verified by me against the source. No Critical or Important defects.

## Findings (by severity)

**Gate focus — all eight verified pass:**

1. **Deferred-supplier double-post suppressed.** `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:1006-1067` posts exactly one action-keyed issue JE (Dr 401 partner-tagged / Cr `ChecksToPay`|`EffetsPayable`), posted synchronously in-transaction via `postEntryNow`. The branch is an `elseif` shadowing the legacy `createSupplierPaymentJournalEntry` path (`PaymentController.php:1068`), and the cash-movement writer excludes deferred suppliers (`PaymentController.php:1185`, `&& ! $isDeferredSupplier`). `GeneralLedgerService.php:778-801` shows no bank line. Proven by `DeferredSupplierPaymentTest.php:107-116` (401 debit + no bank line + no movement + unchanged repository balance).
2. **Repository validation precedes posting.** `PaymentController.php:624-645` runs `OutboundRepositoryValidator` before the transaction; `OutboundRepositoryValidator.php:25-44` enforces tenant/company, BankAccount type, active, non-null `gl_account_id`, currency, bank consistency; non-bank repo → `INVALID_OUTBOUND_REPOSITORY` (`DeferredSupplierPaymentTest.php:136-155`).
3. **Idempotency double-layered.** Request-key short-circuit before any transaction (`PaymentController.php:308-316`); in-transaction `instrument:{id}:issue` action key with `assertSemanticDigest` (`InstrumentEvent.php:54-58`) reuses the original JE on replay and throws on semantic collision. Retry proves one payment / one instrument / one JE / one event (`DeferredSupplierPaymentTest.php:208-227`).
4. **Regressions intact.** Deferred-customer keeps portfolio debit + zero movement; immediate-supplier keeps 401 debit + bank credit + exactly one movement (`DeferredSupplierPaymentTest.php:157-206`).
5. **Thin delegation.** All four actions in `PaymentInstrumentController.php:356-448` delegate to `OutboundInstrumentService` (not inbound internals), canonical 404 (`:446-457`) / 422 (`:459-467`); permission matrix, inbound→422, invalid-transition→422, malformed-uuid→404 covered (`OutboundInstrumentEndpointsTest.php:82-147`).
6. **Reconcile check 4 correct and alert-only.** Only financially linked outbound Received/Bounced counted (`ReconcileTreasuryCommand.php:296-310`); credit-normal via `bcsub('0', comparableBalance, $scale)` with `getScaleSafe($company->currency, 3)` (`:362-368`); inbound remittance circuit excluded for outbound (`:425-427`); `portfolioMismatches` only calls `alertPortfolioDrift`, never a freeze (`:198-221`; `ReconcilePortfolioCheckTest.php:140-163`). The `expense_metadata.payment_instrument_id` column exists (migration `2026_07_18_100200`).
7. **Outbound maturity alerts distinct.** Separate `treasury.maturity.outbound_due` event, audit + notification with outbound deep link (`InstrumentMaturityAlertsCommand.php:135-236`); only actionable due Received/Bounced paper; distinct EN/FR/AR "must be funded by" copy in all three locale files; no Expense model writes.
8. **No test weakening.** `RefreshDatabase` + real models + seeder throughout; concrete JE line shapes, movement counts, balances, audit payloads asserted; no trivial assertions or mocked subject-under-test.

**Minor (non-blocking):**
- `routes.php:150-158` — `bounce-outbound` and `represent` share `can:instruments.clear-outbound` (only clear/cancel got dedicated perms, `RolesAndPermissionsSeeder.php:224-225`). Matches the intended 2-permission split; confirm the grouping is deliberate.
- `NotificationPanel.tsx:36-39` — `Number(value)` on `outbound_due_count` is fine: it's a count, not money/quantity (no rule-19 violation).
- Note: the `represent` replay guard added at `OutboundInstrumentService.php:385-387` is a legitimate fix, not scope creep — it prevents `represent` from replaying a prior `clear` event; clear/represent action keys stay disjoint.

**Caveats:** PHPUnit execution was permission-blocked for both the reviewer agent and me in this session, so the verdict rests on full-file control-flow reading plus the implementer's fresh path-scoped green evidence (SQLite + PostgreSQL as claimed in the gate request). Also, writing `.gates/gate-t5a-gate-3-verdict-treasury.md` was permission-blocked — the verdict file is still the empty placeholder; this message is the verdict of record until that write is approved.

VERDICT: spec ✅ + quality APPROVED
Before Wave 4: nothing blocking — optionally confirm the shared `instruments.clear-outbound` permission for bounce/represent is the intended authorization grouping.
