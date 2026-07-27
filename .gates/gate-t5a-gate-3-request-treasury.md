# Gate t5a-gate-3 — Treasury Phase ⑤a Wave 3 treasury review

You are reviewing `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5` on branch `feat/treasury-phase5` at HEAD.

## Reviewer persona (controlling)

Act as the **treasury-reviewer**: adversarial, code-grounded, and focused on defects in treasury, payments, documents, GL, maturity alerts, and money precision. Verify every claim against files you read. Every finding must cite `file:line`. Severity is Critical (wrong money/data loss/auth bypass), Important (correctness/required contract/boundary), or Minor. A Critical or Important finding means REJECT. You gate; you never merge.

Treasury truths:

- Money is numeric-string plus bcmath only; console scale resolution always receives explicit currency.
- A deferred-supplier issue must post exactly one JE: Dr 401 with partner tag / Cr `ChecksToPay` (cheque) or `EffetsPayable` (effet). It must post no bank-account line and no repository movement.
- For deferred suppliers, the legacy `createSupplierPaymentJournalEntry` immediate-settlement path MUST be suppressed. The issue JE replaces it; executing both is a Critical double-post.
- Existing immediate-supplier and deferred-customer posting/movement behavior must remain unchanged.
- Outbound lifecycle HTTP methods are thin delegations to the already-gated `OutboundInstrumentService`; they must not reuse inbound internals.
- Reconcile check 4 is alert-only. Outbound `Received` + `Bounced` linked nominal totals compare to the credit-normal (credit minus debit) balances of `4035`/`403`; a finding must never freeze a repository.
- Maturity alert coverage includes outbound `Received` + `Bounced` paper with separate “must fund by” wording. Treasury must not write Expense models.

## Authority and review scope

Read before judging:

1. `docs/handoff/CODEX-treasury-phase5-2026-07-18.md`
2. `docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md`, especially §4.1–§4.4 and §9
3. all three `docs/superpowers/reviews/2026-07-18-treasury-phase5-plans-*.md`
4. `docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md`, Tasks 6–8
5. `CLAUDE.md`, especially rules 1–6, 9, 13, 19–21

Review the entire Wave 3 diff:

```bash
git diff t5a-gate-2..HEAD -- \
  apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php \
  apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php \
  apps/api/app/Modules/Treasury/Presentation/routes.php \
  apps/api/app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php \
  apps/api/app/Modules/Treasury/Presentation/Console/InstrumentMaturityAlertsCommand.php \
  apps/api/app/Modules/Treasury/Application/Services/OutboundInstrumentService.php \
  apps/api/app/Modules/Treasury/Domain/Enums/InstrumentEventType.php \
  apps/api/database/seeders/RolesAndPermissionsSeeder.php \
  apps/api/tests/Feature/Treasury/DeferredSupplierPaymentTest.php \
  apps/api/tests/Feature/Treasury/OutboundInstrumentEndpointsTest.php \
  apps/api/tests/Feature/Treasury/ReconcilePortfolioCheckTest.php \
  apps/api/tests/Feature/Treasury/InstrumentMaturityAlertsTest.php \
  apps/web/src/features/notifications/components/NotificationPanel.tsx \
  apps/web/src/features/notifications/components/NotificationPanel.test.tsx \
  apps/web/src/locales/en/notifications.json \
  apps/web/src/locales/fr/notifications.json \
  apps/web/src/locales/ar/notifications.json \
  docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md
```

Gate focus:

1. Trace `PaymentController` end-to-end for deferred supplier cheque and effet. Confirm the legacy supplier JE branch and movement writer both exclude deferred suppliers, while the replacement issue JE is action-keyed and posted synchronously in the same transaction.
2. Confirm repository validation happens before issue posting and enforces bank-account type, active state, tenant/company, currency, GL link, and bank consistency.
3. Confirm retry with the same request idempotency key cannot create a second payment/instrument/issue JE; the issue action key cannot silently collide semantically.
4. Confirm immediate supplier and deferred customer regressions prove their existing JE and movement shapes remain intact.
5. Confirm all four HTTP actions delegate to the dedicated outbound service and preserve canonical 404/422 behavior without adding an alternate financial implementation.
6. Confirm reconciliation counts only financially linked outbound `Received`/`Bounced` instruments, uses credit-normal liability balances with bcmath and explicit scale, preserves inbound behavior, and alerts without freezing.
7. Confirm outbound maturity alerts include only actionable due paper, create a distinct audit/notification, and render separate EN/FR/AR “must fund by” copy.
8. Inspect tests for weakened assertions, SQLite-only blind spots, false green fixtures, or missing rollback/accounting evidence.

Implementation evidence (fresh, path-only; no full suite):

- Task 6 focused SQLite: 33 passed / 155 assertions; deferred-customer+tender+supplier regressions: 22 / 113; PostgreSQL combined: 50 / 233.
- Task 7 endpoints + legacy guards: SQLite 36 passed / 116 assertions; PostgreSQL 36 / 116.
- Task 8 reconciliation command + portfolio + maturity: SQLite 34 / 149; PostgreSQL aggregate/maturity paths 14 / 61.
- Notification panel Vitest: 8 passed; full web lint exited 0 with zero new key/design violations; typecheck exited 0; task-scoped React Doctor 100/100.
- PHPStan level 8 on every Wave 3 touched PHP path: no errors. Pint on touched PHP paths: pass.

Run additional tests strictly by path and read-only checks as needed. Never run the full PHPUnit suite. Do not accept test weakening.

## Required output

First line exactly one of:

- `GATE VERDICT: APPROVE`
- `GATE VERDICT: REJECT`

Then findings ordered by severity with `file:line` evidence. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and one line stating what must be fixed before Wave 4.
