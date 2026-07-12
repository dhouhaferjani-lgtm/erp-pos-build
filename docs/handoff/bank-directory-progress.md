# Bank Directory Progress

## Branch preparation

- Rebased `feat/bank-reference-verification` onto `origin/dev` at `3b4f11434` before implementation.
- Verified rebased design commit `1691c7f7d` has `3b4f11434` as its direct parent.
- No merge or push performed.

## Phase 1 — Foundation

### Delivered

- Added the rerunnable-safe tenant `banks` table with a partial unique clearing-code index.
- Reconciled the upstream Tunisia SWIFT directory and clearing-code map into 32 canonical `TN.json` rows. France remains deferred.
- Added the Treasury `Bank` model and generated `BankData` DTO contract.
- Added an idempotent `BanksSeeder` and wired it into tenant initialization before payment repositories.
- Added authenticated `GET /api/v1/banks?country=&q=` with tenant/country scoping, active filtering, fuzzy name/short-name search, stable ordering, and no permission/module gate.

### TDD evidence

- RED: `./vendor/bin/phpunit tests/Feature/Treasury/BankDirectoryTest.php` — 2 expected failures because `BanksSeeder` did not exist.
- GREEN: same command — 2 tests, 13 assertions, 0 failures.

### Verification

- `CACHE_STORE=array php artisan typescript:transform` — generated `BankData`; 430 PHP types transformed.
- `./vendor/bin/phpstan analyse --no-progress <Phase 1 PHP paths>` — no errors.
- `./vendor/bin/pint <Phase 1 PHP paths>` — clean after one automatic formatting pass.
- `./vendor/bin/phpunit tests/Feature/Treasury/BankDirectoryTest.php` — 2 tests, 13 assertions, 0 failures.
- `pnpm typecheck` — all four workspace targets completed successfully.
- `pnpm lint` — completed with 0 errors; existing warnings only. Tenant-query audit reported 0 new findings and the design-system audit reported 0 new/stale baseline findings.
- Targeted Vitest: not applicable in Phase 1; no frontend runtime code was added (only the generated DTO declaration).

### Deviations and decisions

- Followed the newer handoff phase split: validator and picker are Phase 2, despite the older design document grouping those primitives into its original Phase 1.
- Reused the Treasury module and existing `treasury`/future consuming namespaces; no new i18n namespace was introduced in this backend-only phase.

### Gate 1

- RC: `bank-gate-1-rc1` (`d97fa7eff`).
- Review: `docs/handoff/gate-reviews-bank/GATE-1-rc1.md`.
- Verdict: `APPROVE`.
- Findings: no blocking findings; one MEDIUM process observation that `origin/dev` advanced during the audit, plus LOW/INFO polish notes. The branch will be rebased onto the new `origin/dev` tip before Phase 2, followed by type regeneration and fresh verification.
- Fable escalation: not invoked; Gate 1 contains no validator math and Opus reported no validator-math uncertainty.
- Post-gate sync: cleanly rebased all three branch commits onto `origin/dev` at `1223dcc37`; type regeneration produced no diff. Re-ran the Phase 1 PHPUnit test (2 tests, 13 assertions), targeted PHPStan (0 errors), workspace typecheck, lint, query-key audit, and design-system audit successfully before starting Phase 2.

## Phase 2 — Validator and PaymentRepository wiring

### Delivered

- Added the shared `BankAccountValidatorInterface`, string/bcmath-only PHP validator, and typed RIB/IBAN result DTOs under `app/Shared/Banking`.
- Added a rerunnable-safe nullable `payment_repositories.bank_id` FK, model relation/fillable field, scoped controller validation, persistence, response formatting, and non-blocking server-side validation metadata.
- Replaced fake-looking repository seed RIB/IBAN values with blank account identifiers and linked the Tunisian seed repositories to canonical banks.
- Added tenant-scoped `useBanks`, a string-only client mod-97 mirror, and the reusable token-only `BankPicker` molecule with searchable directory and free-text fallback modes.
- Replaced the repository bank-name input with the picker, BIC autofill/read-only behavior, live RIB/IBAN indicators, derived IBAN, and warn-but-allow submission.
- Reused the existing `pickers` and `treasury` namespaces; added English/French UI copy and matching Arabic picker keys for enforced picker coverage.

### TDD evidence

- RED validator: 12 expected failures because `BankAccountValidator` did not exist.
- GREEN validator: 13 tests, 41 assertions, including the Amen vector and a separately derived valid RIB that genuinely exceeds `PHP_INT_MAX`.
- RED repository: `bank_id` response path was null; GREEN persisted the FK and returned invalid RIB/IBAN metadata without rejecting the save.
- RED repository seeder: fake account numbers remained; GREEN blanked RIB/IBAN and linked all TN bank repositories to canonical bank rows.
- RED frontend: modal tests failed because the bank combobox and warning indicator were absent; GREEN: 2 tests covering selection/autofill/derived IBAN/payload and invalid-RIB save availability.

### Verification

- `CACHE_STORE=array php artisan typescript:transform` — 432 PHP types transformed, including both validator DTOs.
- Targeted PHPStan over Shared Banking and touched Treasury/provider/seeder files — 0 errors.
- Targeted Pint over all touched Phase 2 PHP/tests — clean after formatting.
- `BankAccountValidatorTest.php` — 13 tests, 41 assertions.
- `PaymentRepositoryTest.php` — 15 tests, 44 assertions.
- `PaymentRepositorySeederTest.php` — 1 test, 28 assertions.
- `AddRepositoryModal.test.tsx` — 2 tests passed.
- Picker Arabic coverage slice — 1 test passed; the broader file has unrelated pre-existing gaps in `common`, `workshop-technicians`, and `vehicles`.
- Workspace `pnpm typecheck` and `pnpm lint` — completed successfully; query-key and design-system audits both reported 0 new findings.
- React Doctor scoped to `bank-gate-1` — 100/100, no issues.
- Static cast scan of validator paths — no `intval`, integer/float casts, `parseFloat`, or `Number` calls.
- Out-of-scope scan — no `PaymentInstrument` file touched.

### Gate 2

- RC: `bank-gate-2-rc1` (`76851938f`).
- Review: `docs/handoff/gate-reviews-bank/GATE-2-rc1.md`.
- Verdict: `APPROVE`.
- Findings: no blocking findings; one LOW generated-type observation and two informational notes. Opus independently re-derived the canonical RIB and IBAN mod-97 arithmetic and confirmed the PHP and TypeScript implementations avoid unsafe numeric coercion.
- Fable escalation: not invoked; Opus reported no BLOCKER/HIGH validator-math finding and no uncertainty about the validator math.

## Phase 3 — Partner bank accounts

### Delivered

- Added the rerunnable-safe tenant `partner_bank_accounts` table with Partner, Bank, Tenant, and creator references.
- Added the Partner-owned `PartnerBankAccount` entity, generated DTO, nested input DTO, and `Partner::bankAccounts()` relation without importing Treasury internals or the Treasury `Bank` model.
- Added a constructor-injected Partner account service that transactionally synchronizes nested rows, derives a missing IBAN from a valid RIB, preserves invalid legacy values, and normalizes multiple primary requests to one primary row.
- Extended create/update FormRequests with structurally typed nested rules, tenant-scoped bank lookup, and constructor-injected shared validator calls that record validity without adding checksum rejection.
- Extended Partner responses with per-row RIB, IBAN, and BIC validity metadata and loaded bank accounts on create, show, and update.
- Added a repeatable token-based Bank Accounts section inside `B2BFieldsSection`, reusing `BankPicker`, client mod-97 validation, derived IBAN, free-text fallback, canonical form atoms, and a single-primary control.
- Added English and French Partner labels in the existing `sales` namespace and regenerated backend-owned TypeScript types.

### TDD evidence

- RED backend: 4 expected failures/errors for the missing table, relation, persistence, and validity response.
- GREEN backend: RC1 had 4 tests/27 assertions; RC2 has 5 tests/31 assertions after adding the tenant-tier FK regression and explicit default-primary/observable validity coverage.
- RED frontend: the Partner form test could not find the additive bank-account control.
- GREEN frontend: 8 PartnerForm tests, including edit-page picker selection/BIC autofill/derived IBAN submission and invalid-RIB warning with Save enabled.

### Verification

- `CACHE_STORE=array php artisan typescript:transform` — 433 PHP types transformed, including `PartnerBankAccountData` and the typed `PartnerData.bank_accounts` collection.
- Targeted PHPStan over all touched Partner PHP paths — 0 errors.
- Targeted Pint over all touched Partner PHP, migration, and test paths — clean.
- Partner account + B2B/create/update/list regression slice — RC1: 63 tests, 228 assertions; RC2: 64 tests, 232 assertions.
- Partner frontend form + broader Partner regression slice — 56 tests passed (8 PartnerForm + 48 Partner management).
- Workspace `pnpm typecheck` — all targets completed successfully.
- Query-key audit — 0 new findings. Design-system audit — 0 new/stale baseline findings after replacing the primary toggle with the canonical Checkbox atom.
- React Doctor scoped to `bank-gate-2` — 91/100 with an empty diagnostics report.
- Static boundary/out-of-scope scans — no Phase 3 Partner import of Treasury internals and no `PaymentInstrument` file touched.

### Deviations and decisions

- The older design document's invoice/quote pay-to rendering was not implemented because the newer 2026-07-10 handoff explicitly scopes Phase 3 to the sub-table, Partner API/DTO, and `B2BFieldsSection`; customer-facing PDF expansion remains outside this three-phase track.
- Empty BIC values are reported as unverified rather than valid; they remain nullable and never block persistence.

### Gate 3

- RC1: `bank-gate-3-rc1` (`e625efca1`).
- Review: `docs/handoff/gate-reviews-bank/GATE-3-rc1.md`.
- Verdict: `CHANGES-REQUIRED`.
- Finding: BLOCKER — the tenant-tier migration incorrectly referenced the central-only `tenants` table. SQLite's combined test database masked the production tenant-database failure.
- Fable escalation: not invoked; the blocker concerns migration topology, and Opus reported no validator-math BLOCKER/HIGH or uncertainty.
- RC2 response (test-first): added a regression asserting tenant migrations do not reference the central `tenants` table, removed that FK while retaining tenant-local FKs, made FormRequest validity results observable in response metadata, and defaulted the first account to primary when a non-empty collection requests no primary. Focused account tests pass (5 tests, 31 assertions) and targeted PHPStan remains at 0 errors.
- RC2: `bank-gate-3-rc2` (`b35a2b3d5`).
- Review: `docs/handoff/gate-reviews-bank/GATE-3-rc2.md`.
- Verdict: `APPROVE`.
- RC1 blocker status: resolved and protected by a tenant-tier regression test.
- Findings: no blocking findings; one LOW future multi-country consistency note and two informational observations. The authoritative response-body validity already uses company country, and the current shipped validator remains TN-only.
- Fable escalation: not invoked; neither RC1 nor RC2 reported a validator-math BLOCKER/HIGH or uncertainty.
- Final confirmations: Partner imports no Treasury internals; warn-but-allow remains non-blocking; English/French i18n and design tokens pass; `PaymentInstrument` is untouched; the branch is local, unmerged, and unpushed.
