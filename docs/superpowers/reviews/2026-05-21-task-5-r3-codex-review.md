# Task 5 R3 Codex Self-Adversarial Review — Account Charge Net Balance Contract

Commits reviewed:

- `a41e9ddf0 Phase 3.5.1: Author account charges on device`
- `cc6f8477f Phase 3.5.2: Harden account charge authoring`
- `8cb3da6b7 Phase 3.5.3: Align account charge net balance contract`

Prior Opus reviews:

- `docs/superpowers/reviews/2026-05-21-task-5-opus-review.md` — REQUEST-CHANGES.
- `docs/superpowers/reviews/2026-05-21-task-5-r2-opus-review.md` — REQUEST-CHANGES.

## Verdict

APPROVE

## R3 Finding Closure

The R2 Opus blocker was PHP/POS contract drift for fully credit-offset `ACCOUNT_CHARGE` payloads. POS correctly emitted `projected_net_balance_after = max(projected_receivable_balance_after - projected_credit_balance_after, 0)`, but PHP expected raw subtraction and rejected valid payloads when credit exceeded projected receivable.

R3 closes that drift:

- PHP `FiscalPayloadConstraintValidator::validateAccountChargeArithmetic()` now clamps negative projected net to zero.
- Phase 3 spec now states the clamp explicitly.
- PHP validator coverage now accepts a fully credit-offset payload: receivable `0.000`, credit `100.000`, charge `50.000`, projected net `0.000`.
- POS service coverage now asserts the same fully offset authoring math.
- POS fiscal-engine coverage now seals the same fully offset payload before sync.

## Standing-Pattern Checks

- Cross-language drift: PASS. The server validator, POS validator, POS authoring service, and spec now agree on the clamped projected-net rule.
- Fail-loud before append: PASS. Invalid arithmetic still rejects; the new valid fully offset case is accepted consistently on device and server.
- R2/R3 new-defect risk: PASS. The change is narrow and covered by both positive and existing negative arithmetic tests.
- D16 bounded modules: PASS. No module dependencies changed.
- Constructor injection / service location: PASS. No `app()`, `App::make()`, or `resolve()` usage was introduced.
- Skip hygiene: PASS. No skipped tests were added.

## Verification Evidence

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit --filter 'account_charge_(accepts_fully_credit_offset_projected_net|rejects_amount_balance_mismatch)' tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` — 2 tests, 5 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` — no errors.
- `./vendor/bin/pint --test app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` — pass.
- `pnpm test -- accountChargeService.test.ts FiscalEventEngine.test.ts accountChargeCanonicalParity.test.ts` — 92 tests passed.
- `pnpm typecheck` — passed.
- Full gate:
  - `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — 1174 tests, 4001 assertions, 107 skipped, 2 incomplete, 16 deprecations.
  - `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` — no errors.
  - `./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal tests/Feature/POS tests/Feature/Fiscal tests/Unit/Fiscal` — pass.
  - `pnpm test` — 168 files, 1500 tests passed.
  - `pnpm typecheck && pnpm lint` — typecheck passed; lint passed with 41 pre-existing warnings and 0 errors.
  - `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh` — pass.
