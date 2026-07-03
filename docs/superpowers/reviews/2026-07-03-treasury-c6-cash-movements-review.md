# Treasury-reviewer gate — C6 cash-movements read-model

Branch: `feat/treasury-cash-movements-income` → local `post-demo`
Reviewer: `treasury-reviewer` agent. Orchestrated per `docs/handoff/HANDOVER-treasury-c6-c7-post-demo.md`.

## Round 1 — commit `2e964242f` — VERDICT: FAIL

Findings:
1. **[CRITICAL]** Dedup exclusion list (`PAYMENT_BACKED_SOURCE_TYPES`) omitted `advance` and `supplier_advance_refund` — both are payment-backed cash JE source types the GL projection actually emits (`GeneralLedgerService.php:347-360, 421`) → advance payments to cash double-counted.
2. **[IMPORTANT]** Dedup test exercised `source_type='payment'`, which no production flow emits (createPaymentEntry has zero production callers, writes no source_id) — green test, false confidence. Real paths (`customer_payment`/`supplier_payment`/`advance`) untested.
3. **[IMPORTANT]** No authz (401/403 for missing `reports.view`) or tenant-isolation tests.
4. **[IMPORTANT]** `VendorRefundService.php:155-166` posts the refund cash leg against `payment_repositories.account_id`, not `gl_account_id` (the canonical GL column used by PaymentController:641,667 and the C1 seeder fix) — refund JEs invisible to (or double-counted by) the report depending on column equality.
5. **[IMPORTANT]** `PaymentType::Refund` is sign-overloaded: supplier-advance refunds are cash IN but the report maps every Refund to `out`.
6. **[MINOR]** `partners` left-joins not company-scoped.

Verified clean in round 1: precision rule 19 (strings + `bcformatStrict` + injected resolver, no float casts), read-model scope (no tables/writes), filter consistency inside the dedup `whereNotExists` for the covered source types, route middleware + `can:reports.view`.

Disposition: all six findings dispatched back to implementation (Codex) for a fix round; re-gate follows.

## Round 2 — commits `2e964242f` + `f8b76805b` — VERDICT: PASS-WITH-NITS (gate passed)

All six round-1 findings verified genuinely fixed against code (file:line cited by reviewer):
1. Dedup set now covers all five payment-backed JE source types `GeneralLedgerService` emits with `source_id = payment.id` (`payment`, `customer_payment`, `supplier_payment`, `advance`, `supplier_advance_refund`); reviewer independently re-surveyed every emitted source_type and confirmed nothing else uses a payment id. Each covered type has a production-shaped dedup test.
2. Dedup tests exercise production source types (source_id = payment.id).
3. Authz (401 / 403 without `reports.view`) + cross-company isolation tests present and passing.
4. `VendorRefundService` cash leg now guards on and posts against `gl_account_id` (canonical: PaymentController:641,667 + PaymentRepositorySeeder:74); regression test asserts the cash line account; no other caller breaks (null-account no-GL path still passes).
5. Direction derives from the represented posted cash-leg JE (debit→in, credit→out): supplier-advance refund correctly reports `in`; customer refund `out`; payment_type fallback only when no posted JE.
6. Partners joins company-scoped.

Re-verified clean: precision rule 19 (strings, `bcformatStrict`, explicit-currency `getScale`), read-model scope (report path pure reads), company scoping on every join. 21 tests / 88 assertions green by path; PHPStan level 8 + Pint clean.

Non-blocking nits (accepted, on record): (a) no test covers the payment_type fallback direction for a `Refund` payment with an unposted JE — in practice the JE derivation always wins because the JE posts synchronously and requires the same `gl_account_id` the report requires; (b) `source_type='payment'` in the dedup set can never match (test-only writer, NULL source_id) — harmless.
