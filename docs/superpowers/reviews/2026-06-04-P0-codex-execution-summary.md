# P0 Branch Tax-ID Codex Execution Summary

Date: 2026-06-05
Branch: `feat/branch-tax-id-spec`
Scope: `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` Tasks 1-18

## Outcome

Implemented the P0 branch seller tax-identity path end to end:

- Nullable branch tax identity overrides on `locations`.
- Backend model, validation, resource, store/update persistence, and country tax-rule parity.
- Server-side branch identity resolution for receipt PDF and FacturX.
- NF525 header null-SIRET fix.
- Web Location API/types/settings form support.
- POS terminal resource/type propagation.
- POS fiscal seller sourcing prefers `terminal.location.tax_id` with company fallback for live `SALE_RECEIPT` and `ACCOUNT_PAYMENT`.
- Cross-language validator acceptance and fixture parity confirmed without fiscal payload schema/version changes.

`ACCOUNT_CHARGE` remained untouched and out of scope per the spec; no live POS seller source path exists for it in P0.

## Commits

| Task | Implementation commit | Review commit |
| --- | --- | --- |
| 1 | `bccf8da5c` add nullable tax-identity columns to locations | `2c6eb5ce4` |
| 2 | `8c1ce606f` Location model fillable/casts/docblock | `e91b09cc7` |
| 3 | `41325830a` shared CountryTaxNumberRules with fiscal parity | `e21727cb3` |
| 4 | `27e955302` per-country tax-identity config + reader | `5329a0d30` |
| 5 | `7dadf61bf` CreateLocationRequest validation | `28aa8bc66` |
| 6 | `14e18eb74` UpdateLocationRequest validation | `e56951a1f` |
| 7 | `65a9b3d69` LocationController store persistence | `6b196607c` |
| 8 | `836e43c82` LocationResource exposure | `420483005` |
| 9 | `b63dbcefb` TaxIdentityResolver + TaxIdentityData | `1fd8f124b` |
| 10 | `86f9b7bd8` receipt PDF branch seller identity | `6eb9b3d24` |
| 11 | `c14826d65` FacturX branch seller identity | `38797d9be` |
| 12 | `366dea6ec` NF525 SIRET/address header fix | `f9dec71d5` |
| 13 | `5f4ba0bb3` web Location types/API | `2950f7649` |
| 14 | `79caf5ee3` Location settings form | `361e0246d` |
| 15 | `550af4abb` TerminalResource location tax fields | `733f5a79c` |
| 16 | `46117f25c` POS Terminal.location type | `cb6487ca8` |
| 17 | `d526d4edf` POS fiscal seller tax source | `7f3eef34d` |
| 18 | `1875dd5cb` cross-language validator acceptance | `759870849` |

## Review Gates

All task review artifacts were written under `docs/superpowers/reviews/2026-06-04-P0-task-XX-opus-review.md`.

- Tasks 1, 3, 6, 9, and 17 returned `APPROVE-WITH-EDITS`; requested edits were applied before continuing.
- Task 18 returned `APPROVE` for the phase-end gate.
- No task ended with unresolved `NEEDS-REWORK`.

## Verification Run

Backend focused/API verification included:

- `php artisan test` for each task's focused tests.
- `php artisan test tests/Feature/Fiscal/BranchSellerTaxNumberValidationTest.php`
- `php artisan test tests/Feature/Fiscal/BranchSellerTaxNumberValidationTest.php tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php --filter='branch|seller_tax|account_payment_validator_accepts_branch|sale_receipt_validator_accepts_branch|phase_1_5_2_accepts_per_country_seller_tax_numbers'`
- `./vendor/bin/pint` on touched PHP files.
- `./vendor/bin/phpstan --no-progress --memory-limit=2G`

Web verification included:

- `pnpm typecheck`
- `pnpm lint`
- `pnpm test`

POS verification included:

- `pnpm typecheck`
- `pnpm lint` (0 errors, 40 existing warnings)
- Focused Vitest suites for terminal and payment-store changes.
- `bash scripts/check-fiscal-fixture-parity.sh`

Known residual: `cd apps/pos && pnpm test` fails in `src/lib/db/__tests__/migrations.v37.test.ts` on two pre-existing migration v37 assertions (`fiscal_event_genesis_seed` expected empty string but received `seed`, and the canonical chain index assertion). The migration v37 files were not touched by this work; focused Task 16-18 tests and fiscal parity passed.

## Final State

No push was performed.
