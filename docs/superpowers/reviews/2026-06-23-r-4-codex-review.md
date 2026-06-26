# R-4 Codex Review

Scope reviewed:

- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
- `apps/api/tests/Feature/Voucher/VoucherLedgerGlActorFallbackTest.php`

Findings:

- No blocking issues found.
- The production change is minimal and keeps all voucher GL line construction
  unchanged.
- A resolvable `voucher_ledger.user_id` still follows the actor-aware posting
  path and preserves `posted_by`.
- An unresolvable `voucher_ledger.user_id` now follows the existing
  system-generated posting path, which posts the entry, assigns hash-chain
  fields, and leaves `posted_by` null.
- Regression coverage includes both worklist-required voucher events,
  `Redeemed` and `Voided`, and a valid-user control.
- Invoice/media attachment wiring is untouched.

Verification:

- RED before implementation:
  `php artisan test tests/Feature/Voucher/VoucherLedgerGlActorFallbackTest.php`
  failed both unresolvable-actor cases with `ModelNotFoundException`.
- GREEN after implementation:
  `php artisan test tests/Feature/Voucher/VoucherLedgerGlActorFallbackTest.php`
  passed 3 tests / 16 assertions.
- Scoped voucher regression:
  `php artisan test tests/Feature/Voucher/VoucherLedgerGlActorFallbackTest.php tests/Feature/Voucher/VoucherRedemptionServiceTest.php tests/Feature/Voucher/VoucherCascadeServiceTest.php tests/Feature/Voucher/VoucherIssuanceServiceTest.php`
  passed 42 tests with 1 existing incomplete test / 254 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php tests/Feature/Voucher/VoucherLedgerGlActorFallbackTest.php`
  reported no errors.
- `./vendor/bin/pint app/Modules/Accounting/Domain/Services/GeneralLedgerService.php tests/Feature/Voucher/VoucherLedgerGlActorFallbackTest.php`
  passed.
