# Phase 3 Stage A Spec v1 R3 Opus Review

Reviewed artifact: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`

Prior R2 Opus review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r2-opus-review.md`

R3 self-review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r3-codex-review.md`

Verdict: APPROVE

## Findings

No blocking, request-changes, or minor findings.

## R3 Verification

The R2 minor finding is resolved. The Treasury bridge journal shape now explicitly includes a `SystemAccountPurpose::SalesDiscount` debit when `transaction_discount_amount > 0` (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:379`-`386`), and the test matrix requires a discounted-charge bridge test with AR, SalesDiscount, ProductRevenue, VatCollected, currency-scale balance, and no payment rows or payment lines (`:435`-`:436`).

The discounted journal balances under the canonical invariant:

- Debits: `totals.total + transaction_discount_amount`.
- Credits: `totals.subtotal + totals.vat_total`.
- The locked invariant is `subtotal + vat_total == total + transaction_discount_amount` (`:383`), so the specified debit and credit sides balance at `currency_scale` (`:384`).

No new D16 dependency is introduced. The SalesDiscount lookup is part of the optional `TreasuryAccountChargeBridge`, which is gated by `requiresModule() === 'Treasury'` (`:369`-`:377`). POS-core remains always active, reads only the canonical payload, and must not import Treasury, Accounting, B2B, Partner, Customer, or Contact operational modules (`:360`-`:367`). Accounting readiness remains behind a Treasury-owned readiness check/shared contract (`:377`), and the shared-contract boundary still forbids operational module imports plus `app()`/`App::make()`/`resolve()` in production (`:400`-`:403`).

Prior R1/R2 findings remain resolved:

- Payload sale-evidence completeness remains present: nullable SALE_RECEIPT-compatible `buyer`, first-class `buyer.codice_fiscale`, required `line_items[].product_id`, nullable `line_items[].non_collected_subtype`, exact-key validation, Italy analysis, and test coverage (`:139`, `:180`-`:185`, `:187`-`:202`, `:256`-`:285`, `:311`-`:314`, `:430`-`:431`).
- AR GL semantics remain locked to AR/revenue/VAT/discount posting with partner attribution, idempotency by `fiscal_event_id`, tenant/company scoped FKs, and no Treasury Payment, POS ReceiptPayment, payment line, or `createPOSPaymentEntry()` path (`:375`-`:387`, `:435`-`:436`).
- D8 remains intact: POS does not author a Tax Invoice; B2B facture work stays in the web-B2B aggregate bridge (`:69`-`:80`, `:301`-`:304`, `:390`-`:398`).
- Contract drift controls remain explicit: exact top-level/nested key validation, no `payments` key anywhere, parser rejection on extras/missing keys, and canonical parity gates (`:133`, `:260`-`:264`, `:428`-`:431`, `:439`-`:440`).

## Verification Commands

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3`:

- `git status --short`
- `nl -ba docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r2-opus-review.md`
- `nl -ba docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r3-codex-review.md`
- `rg -n "SalesDiscount|discounted|transaction_discount_amount|CustomerReceivable|ProductRevenue|VatCollected|D16|Treasury-owned|Accounting-readiness|buyer|codice_fiscale|product_id|non_collected_subtype|Tax Invoice|web-B2B|payments block|createPOSPaymentEntry" docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '120,210p'`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '248,320p'`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '360,442p'`
- `rg -n "case CustomerReceivable|case VatCollected|case ProductRevenue|case SalesDiscount|createPOSPaymentEntry" apps/api/app/Modules`

No test suite was run; this was a spec/document review.
