# Task 8 Opus Second-Pass Adversarial Review

Commits reviewed:

- `522b049e8 Phase 3.8.1: Bridge account charges to AR`
- `167638db8 Phase 3.8.2: Validate charge replay journal lines`

Codex self-review reviewed: `docs/superpowers/reviews/2026-05-21-task-8-codex-review.md`

Latest R2 verdict: **APPROVE**

R1 verdict: **REQUEST-CHANGES**

## Findings

### REQUEST-CHANGES — Idempotency replay accepts a header-only or wrong-line AR journal as successful

`TreasuryAccountChargeBridge::existingJournalEntryForEvent()` treats any single journal entry with `source_type = pos_account_charge` and `source_id = $event->id` as the replay candidate, then `assertExistingJournalEntryMatches()` validates only header fields: tenant, company, entry date, description, and Draft status. It never validates the journal lines. See `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountChargeBridge.php:155` and `:171`.

That means a stale/corrupt row with the correct source id and matching header but no AR/revenue/VAT lines, wrong CustomerReceivable partner, wrong totals, missing SalesDiscount, or wrong VAT line will be silently accepted as an idempotent replay. `apply()` returns cleanly at `:75`, so the projection job would mark the bridge applied even though the required AR posting shape does not exist.

This violates the Task 8 fail-loud/idempotency contract. The plan says to assert no conflicting existing journal entry for `source_type='pos_account_charge'` / `source_id=$event->id`, and the spec requires exact AR posting shape: debit CustomerReceivable with partner, optional debit SalesDiscount, credit ProductRevenue, optional credit VatCollected, and no payment rows. The current conflict test only mutates the description (`apps/api/tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php:113`), so it misses the more important idempotency-tail case.

Smallest fix:

- Load `lines.account` for the existing entry.
- In `assertExistingJournalEntryMatches()`, compare the expected line set against the sealed payload and resolved partner: exactly one CustomerReceivable debit for `totals.total` with the resolved partner id, exactly one ProductRevenue credit for `totals.subtotal`, VatCollected present only when `vat_total > 0`, and SalesDiscount present only when `transaction_discount_amount > 0`.
- Add a regression that inserts a header-matching `pos_account_charge` journal entry with missing or wrong lines, calls `apply()`, expects `idempotency_conflict`, and asserts no new row is written.
- Consider scoping `existingJournalEntryForEvent()` by `tenant_id` and `company_id` as well as `source_type/source_id`; the subsequent header check fails loud today, but the hot replay probe should still be tenant/company-shaped.

## Attack Vectors Checked

- Cross-tenant customer safety: the final Partner lookup is scoped by `(tenant_id, company_id, partner_id)` and customer-compatible type. Pending aliases are looked up by `(tenant_id, company_id, client_customer_uuid)`, with a same-tenant foreign-company diagnostic branch that fails loud.
- Account path safety: account selection remains delegated to `GeneralLedgerService::createPOSChargeEntry()`, which resolves company-scoped system accounts.
- Fail-loud refresh semantics: `GeneralLedgerService` now calls `PartnerBalanceService::refreshPartnerBalance()` inside the journal transaction, so the Task 8 refresh-failure test proves journal rows roll back instead of leaving a stale idempotency anchor.
- Dead-path/live registration: `TreasuryAccountChargeBridge` is tagged in `TreasuryServiceProvider`, returns `requiresModule(): 'Treasury'`, and has priority `150`.
- CI PG sentinel: `.github/workflows/ci.yml` includes `TreasuryAccountChargeBridgeTest` in the PG fiscal filter with an explicit Task 8 comment.
- Canonical handoff: production code uses `CanonicalPayloadReader::forAccountCharge($event)` and passes `$view->vatBreakdown` plus `$view->lineItems` into `CreatePOSChargeJournalEntryCommand`.
- No payment side effects: the bridge itself does not create `Payment` / `ReceiptPayment` rows and does not call `createPOSPaymentEntry()`.
- D16 bounded-module seam: POS/Fiscal core files are not modified to import Treasury/Accounting operational services; the operational bridge lives under Treasury Application.
- Rule 13: no production `app()`, `App::make()`, or `resolve()` calls were introduced in the touched production files.
- Deptrac: Task 8 did not increase `ModuleDomain -> ModuleApplication`; the remaining raw ratchet failure is the unrelated current-tree `SharedContracts -> ModuleDomain` growth.

## Verification

- `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php` — PASS, 10 tests / 43 assertions.
- `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 app/Modules/Treasury app/Modules/Accounting app/Modules/Fiscal tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php --memory-limit=1G` — PASS.
- `./vendor/bin/pint --test app/Modules/Treasury/Application/Projections/TreasuryAccountChargeBridge.php app/Modules/Treasury/Providers/TreasuryServiceProvider.php app/Modules/Accounting/Domain/Services/GeneralLedgerService.php tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php` — PASS.
- `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` — FAILS only on unrelated `SharedContracts on ModuleDomain` growth; Task 8-owned categories hold at baseline.

## R2 Re-Review

Verdict: **APPROVE**

The R1 finding is closed. Commit `167638db8` changes the replay path to scope the existing journal probe by `tenant_id`, `company_id`, `source_type`, and `source_id`, eager-loads `lines.account`, and validates the expected line set before allowing an idempotent return.

The new validation derives its shape from the sealed canonical payload and the resolved partner:

- CustomerReceivable is always required, debiting `totals.total`, with `partner_id` equal to the resolved customer.
- ProductRevenue is always required, crediting `totals.subtotal`, with no partner.
- VatCollected is required only when `totals.vat_total > 0` at the event currency scale.
- SalesDiscount is required only when `transaction_discount_amount > 0` at the event currency scale.
- The exact line count must match that expected set.

This is the locked Task 8 AR shape. I do not see a legitimate additional replay line type under the current spec, so the `line_count` guard is correct rather than over-strict. A zero VAT or zero discount replay correctly omits those optional lines; a stray zero-valued VAT/discount line now fails loud instead of silently blessing drift.

### R2 Attack Vectors Checked

- The new regression `test_bridge_fails_loud_on_header_matching_existing_entry_with_wrong_lines` reproduces the R1 bug: it seeds a header-matching entry with no lines, expects `idempotency_conflict:line_count`, keeps one journal entry, and writes no journal lines.
- Tenant/company replay scoping is correct. It avoids cross-tenant source-id poisoning and does not create a Task-owned duplicate risk for a valid fiscal event because the bridge only treats rows in the same tenant/company as replay candidates.
- Numeric handling is fail-loud enough for this path. Canonical payload validation owns strict money format; the bridge checks `is_numeric()` before `bccomp()`, and a malformed decimal would throw before an idempotent replay can be accepted.
- `lines.account` assumptions are sound for this check. Missing or unexpected account purposes fail through `line_count` or `line_purpose`, and PHPStan accepts the nullsafe enum-purpose access.
- Existing standing patterns remain intact: Treasury-gated projector, priority 150, advisory-lock guarded transaction, no Payment/ReceiptPayment rows, no `createPOSPaymentEntry()`, canonical reader handoff, constructor injection only, and no new POS/Fiscal core operational dependency.

### R2 Verification

- `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php` — PASS, 11 tests / 47 assertions.
- `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 app/Modules/Treasury app/Modules/Accounting app/Modules/Fiscal tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php --memory-limit=1G` — PASS.
- `./vendor/bin/pint --test app/Modules/Treasury/Application/Projections/TreasuryAccountChargeBridge.php app/Modules/Treasury/Providers/TreasuryServiceProvider.php app/Modules/Accounting/Domain/Services/GeneralLedgerService.php tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php` — PASS.
- `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` — FAILS only on unrelated `SharedContracts on ModuleDomain`; Task-owned categories held (`ModuleDomain on ModuleApplication` remains 22/22).
