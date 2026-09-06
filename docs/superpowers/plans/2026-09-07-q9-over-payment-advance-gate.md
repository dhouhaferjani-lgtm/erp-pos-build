# Lane brief — Q9 over-payment: explicit advance confirmation, aging visibility, refund path (rev 1)

Lane slug: `q9-overpay` · branch `lane/q9-overpay` · worktree `.worktrees/q9-overpay` (off local `dev`).
Queue item 4 (handover 2026-09-07). Ruling: `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md` § "Q9 — over-payment on a zero-balance document (ruled 2026-09-05)": **never over-allocate to the document; accept the surplus only as an explicit, operator-confirmed customer advance; advances visible in AR aging and the customer balance; supported refund path; standalone customer-account top-up stays reachable with the Sales module inactive.** Benchmark already attached to the ruling (Odoo outstanding-credit prompt; ERPNext unallocated amount as advance).
Reviewers (code gate): `treasury-reviewer` (+ `frontend-conventions-reviewer` for T4).

## Facts verified at local dev (2026-09-07)

- `PaymentAllocationService` (`apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php`): auto-allocation preview never allocates above an invoice balance (`:758-762`) and returns the surplus as `excess_amount` (`:804-815`); manual preview returns `excess_amount` + `excess_handling: 'credit_balance'` (`:852-910`); `applyAllocationFromCommand` books sales-order prepayments as customer advances (`:403-440`, `createCustomerAdvanceJournalEntry`) and then "Handle excess amount as customer advance" (`:447-450`) — i.e. today the surplus is booked as an advance **without any operator confirmation**, which is the behaviour the request-hygiene T12b gate measured (`docs/superpowers/reviews/2026-09-04-request-hygiene-t12b-gate-treasury.md:54-55,364`: "the backend still accepts the over-payment as a customer advance").
- `PaymentController::store` (`…/Presentation/Controllers/PaymentController.php:67-76,197-223`) reconstructs `excess_handling` on idempotent replays; the idempotency recorder from T12b must keep working.
- Standalone top-up paths (must stay reachable with Sales inactive, ruling constraint 2): `POST /payments/deposit` (`Treasury/Presentation/routes.php:224`, `MultiPaymentController::recordDeposit` `:269`), `POST /payments/on-account` (`:236`, `recordPaymentOnAccount` `:486`), `GET /partners/{partner}/account-balance/{currency}` (`:240`), apply-deposit (`:228`). These are Treasury routes, not Document routes; the sales-extra lane (item 6) leaves them ungated — this lane adds the test that proves it.
- AR aging: `AgedReceivablesService` (`apps/api/app/Modules/Accounting/Application/Services/Reports/AgedReceivablesService.php`) handles opening credit notes as negatives (`:157-176`) but has no arm for unallocated customer advances / on-account balances. Partner balance from GL is visible (`PartnerBalanceService::getPartnerBalance`).
- Refunds: `POST /payments/{payment}/refund|partial-refund|reverse` (`routes.php:194-210`, `PaymentRefundController`); whether they accept an on-account/advance payment with no document is **unverified** — T3 proves it.
- FE: `apps/web/src/features/treasury/components/PaymentAllocationForm.tsx` (no over-payment guard found by grep); the T12b banner for `balanceDue === 0` lives in the document payment component (find it: grep `balanceDue === 0` under `apps/web/src/features`).

## Scope

### T1 — Backend confirmation gate (rule: no silent advance)
- New request field on `POST /payments` (and the split/apply paths that can carry a surplus): `accept_excess_as_advance` (boolean, default false), validated in the FormRequest.
- In the store path, after preview: if `excess_amount > 0` (covers balance 0 and amount > balance) and the flag is false → **422** with a stable code `PAYMENT_EXCEEDS_BALANCE` and payload `{excess_amount, allocatable_amount, document_balance_due}` in the standard error envelope (`{message, error:{code,…}}` — reuse the envelope shape used by `DocumentAllocationStateGuard::assertAllocatable` `:40-50`); nothing is written (no payment, no movement, no JE) — assert with counts.
- Flag true → allocate up to balance, book the surplus as an explicit customer advance through the **existing** on-account/advance arm (`:447-450` path), persist the confirmation on the payment (new nullable column `excess_confirmed_at` + `excess_confirmed_by` on `payments`, additive, self-guarding migration) so audit can tell a confirmed advance from a legacy silent one.
- Idempotent replay: the T12b recorder must return the original result including `excess_handling`; a replay with a different flag value is a conflict (409 or the existing replay-mismatch behaviour — read `PaymentController::store` `:197-223` and keep its contract).
- Queue/worker parity: any queued allocation (`applyAllocationFromCommand` from a worker) must not gain a new throwing branch — read the comment at `:228,:252`; the gate lives at the HTTP boundary only.
- Tests (red first; sqlite lane, PG leg for the migration): zero-balance invoice + payment without flag → 422, zero rows; with flag → payment + advance JE + `excess_confirmed_*` set; amount > balance without flag → 422; with flag → allocation = balance, advance = surplus; exact-balance payment unaffected; supplier-invoice path unaffected; replay parity; second company (convention 09).

### T2 — Advances visible in AR aging and customer balance
- `AgedReceivablesService`: add an "advances / on-account credit" arm that subtracts unallocated customer advances (deposit + on-account payments with unallocated remainder, and confirmed excess advances) per partner, shown as a separate negative bucket/column, not netted invisibly into a period bucket; DTO `AgedReceivablesLineData` gains `advances` (numeric-string) — regenerate TS types (`php artisan typescript:transform`, `CACHE_STORE=array`).
- Customer balance endpoint `GET /partners/{partner}/account-balance/{currency}` already returns the on-account balance — verify the confirmed excess advance is included; add a test.
- FE: aging report shows the advances column (i18n keys en/fr/ar; design tokens on touched lines).

### T3 — Supported refund path for an advance
- Prove (tests) that an unallocated advance can be refunded via `POST /payments/{payment}/refund` / `partial-refund` with a cash-out movement through the treasury port and a reversing JE; if the controller refuses payments without a document, extend it minimally (keep the E-7 payout-cash-bound rule: the refund repository is the original payment's repository unless an override permission applies — read `PaymentRefundController` before deciding). Do not touch `TreasuryMovementService`.

### T4 — Frontend confirmation
- Document payment form: when `balanceDue === 0` or entered amount > balance, show a confirmation (`ConfirmDialog` from the design system; note it has no `role=dialog`, use its testid) stating the surplus amount and that it becomes a customer advance; only on confirm send `accept_excess_as_advance: true`. Money as strings (`<MoneyInput>`); no `parseFloat`.
- Standalone top-up page (deposit / on-account) reachable and functional with `all_enabled_modules` lacking `Sales` — vitest with the `CompanyConfigContext` mocked, plus a backend test hitting `/payments/deposit` on a parapharmacy tenant without the Sales extra.

### T5 — Census
- `treasury:census-silent-advances {--tenant=} {--json}`: counts payments whose allocations sum < amount with `excess_confirmed_at IS NULL` (legacy silent advances). Greenfield: expected 0 outside demo tenants; marker `SILENT-ADVANCES CENSUS tenant=<uuid> count=<n>`.

## Out of scope
POS variant (ruling: the document payment path is not a POS path); PR #214 credit-note consumption; lot/cash seam files.

## Verification
```bash
cd apps/api && php artisan test tests/Feature/Treasury/<new tests> tests/Feature/Treasury/PaymentAllocation* tests/Feature/Accounting/Reports/AgedReceivables* && ./vendor/bin/phpstan analyse <changed> --memory-limit=1G && ./vendor/bin/pint --test <changed>
cd apps/web && pnpm vitest run <touched specs> && pnpm typecheck && npx eslint <touched>
```
Handback: `docs/handoff/HANDBACK-q9-overpay-2026-09-07.md`. Deployment: additive migration (self-guarding), no flag; web block per manifest §3.
