# ADVERSARIAL GATE REVIEW — Bank Directory, GATE 2 (Phase 2)

- **Scope reviewed:** `git diff bank-gate-1..HEAD` (single commit `76851938f` "Phase 2.0.0: Wire bank validation into repositories").
- **Reviewer:** Opus 4.8 (standard reviewer per brief §"Autonomous audit gates").
- **Contract:** brief §3 ground rules + §5 verification contract + design doc Phase 2 scope.
- **Method:** every claim below is cited to `file:line` against the committed tree; the mod-97 math was independently re-derived by hand (not trusted from the tests).
- **Fable escalation:** NOT triggered — no BLOCKER/HIGH on the validator math and no reviewer uncertainty about it (see §1). Per brief §3-escalation, escalation is reserved for validator-math blockers/uncertainty only.

Files in scope (25): the `app/Shared/Banking/*` validator + DTOs + interface, `AppServiceProvider` binding, `PaymentRepository` model + controller + `bank_id` migration, `PaymentRepositorySeeder`, `BankController` (unchanged since Gate 1 but consumed by the new hook — sanity-checked for shape only), the FE `BankPicker` + `useBanks` + `useBankAccountValidation` hooks + `AddRepositoryModal`, i18n (`pickers`/`treasury` en/fr/ar), generated types, and 3 test files.

---

## 1. Validator math — HIGHEST-STAKES SURFACE — VERIFIED CORRECT

The brief (§3.10, §5) singles out the mod-97 RIB/IBAN math as the one surface static analysis cannot guard. Findings:

### 1a. String/bcmath end-to-end — no int/float leakage (PASS)
`app/Shared/Banking/Domain/BankAccountValidator.php` performs **all** modular arithmetic through `bcmod`/`bcsub` on string operands with an explicit scale of `0`:
- RIB clé: `bcsub('97', bcmod($body.'00', '97', 0), 0)` (`BankAccountValidator.php:45`).
- IBAN check digits: `bcsub('98', bcmod($rib.$countryNumeric.'00', '97', 0), 0)` (`:124`).
- IBAN validation: `bcmod($numeric, '97', 0) !== '1'` — string compare against `'1'` (`:92`).
- Letter expansion builds a **string** via `(string) (ord($character) - ord('A') + 10)` (`:138`); `ord()`'s int is bounded to `10..35`, never reaches the big number, which only ever flows through `bcmod`.

There is **no** `intval`, `(int)`/`(float)` cast, `+`, or numeric coercion anywhere on the 20/24-digit operands. The explicit `0` scale on `bcmod`/`bcsub` is correct integer-modular arithmetic (rule 19's `ForbidHardcodedBcmathScale` legitimately does not apply — these are not currency-scaled decimals; the brief anticipates this and requires review enforcement, satisfied here).

### 1b. Algorithm correctness — re-derived by hand against the design-doc vector (PASS)
Design-doc canonical vector `07040005810111129653` (Amen Bank, `rib_bank_code=07`) → `TN59 0704 0005 8101 1112 9653`:
- Hand-computed `07040005810111129653 mod 97 = 0` ⇒ the implemented "whole-RIB divisible by 97" convention (Tunisian clé RIB) is correct; `key = 97 − (body·100 mod 97)` yields `53`, matching the vector.
- Hand-computed IBAN check: `(rib · "2923" · "00") mod 97 = 39`, `98 − 39 = 59` ⇒ derives `TN59…`, exactly the design-doc IBAN.
- IBAN re-validation rearranges BBAN+country+check, expands letters, asserts `mod 97 == 1` (`:90-92`) — the ISO 13616 algorithm, correct.
- Check-digit range is provably `02..98` (`98 − [0..96]`) and clé range `01..97`, so `str_pad(...,2,'0')` never truncates or under-pads.

The unit test independently **re-derives** expected values with bcmath rather than hardcoding (`tests/Unit/Shared/Banking/BankAccountValidatorTest.php:24-46`), and `test_validates_a_derived_rib_that_exceeds_the_64_bit_integer_range` asserts `bccomp($rib, (string) PHP_INT_MAX, 0) === 1` before validating (`:66-74`) — genuinely exercising the overflow path §5 demands, not just theoretically. Corrupted-key/19-digit/21-digit/non-numeric and foreign-IBAN-no-throw cases are all present (`:88-101, 116-124`).

**Verdict on math: correct. No Fable escalation warranted.**

---

## 2. Ground-rule compliance (brief §3)

| Rule | Status | Evidence |
|---|---|---|
| §3.3 Module boundary — validator in `app/Shared/Banking`, consumers depend on the **interface** | ✅ | `Contracts/BankAccountValidatorInterface.php`; `PaymentRepositoryController` constructor-injects `BankAccountValidatorInterface` (`PaymentRepositoryController.php:23`), never the concrete class or Treasury internals. No cross-module `Bank`-model import in Phase 2 (only the Treasury-internal `PaymentRepository::belongsTo(Bank)` — same module — and the seeder, which is not a module). |
| §3.4 / rule 13 Constructor injection, no `app()` | ✅ | Binding in `AppServiceProvider.php:102`; controller injects via ctor. No `app()` in the diff. |
| §3.6 Route middleware tuple + no `can:`/`module:` gate | ✅ (n/a to diff) | Route/`BankController` were landed in Phase 1; **not modified in this diff**. Confirmed no `routes.php` change in the Gate-2 range. Out of Gate-2 re-review scope; covered by Gate 1. |
| §3.7 Types flow from backend | ✅ | `packages/shared/types/generated.d.ts` regenerated with both DTOs (`generated.d.ts` new `App.Shared.Banking.Domain.ValueObjects` block); no hand-edit. |
| §3.8 No double-unwrap in new hooks | ✅ | `useBanks.ts` uses `apiGet<Bank[]>('/banks', …)`; `BankController@index` returns `{ data: [...] }`, so the single unwrap yields the array. |
| §3.9 / rule 18 Design tokens only in new `BankPicker` | ✅ | `BankPicker.tsx` uses `colors`/`textColors`/`borderColors`/`semanticColorTokens` exclusively; every token referenced exists in `lib/designTokens.ts` (verified: `colors.primary[50]`, `colors.white`, `colors.hover.gray50`, `textColors.brand/hoverPrimary/error`, `borderColors.default/light`, `surface.base`). Zero hardcoded `bg-*/text-*/border-*` color classes; only layout/spacing utilities. Modal indicators use `colorTokens.intent.success/caution.textStrong` + `tokens.alert.error`. |
| §3.10 Precision — strings everywhere, no MoneyInput/coercion | ✅ | See §1. FE mirror (`useBankAccountValidation.ts`) computes `mod97` digit-by-digit (`remainder = ((remainder*10)+digit) % 97`), which never exceeds `97*10+9` — no JS big-int/float hazard. |
| §3.11 i18n en+fr (+ar) for every new label | ✅ | `locales/{en,fr,ar}/pickers.json` all carry `bank.*` keys; `treasury.json` en+fr add `repositories.validation.*`. All picker/modal strings via `t()`. No hardcoded UI text. |
| §3 TDD both layers | ✅ | Validator RED→GREEN unit tests; `PaymentRepositoryTest::test_bank_reference_is_persisted_while_invalid_account_details_only_warn`; `AddRepositoryModal.test.tsx` two flows. |
| Strict typing, no `any` (hand-written) | ✅ | Hand-written TS has no `any` (`useBanks`, `useBankAccountValidation`, `BankPicker`, modal all typed). See §4.1 for the generated-type note. |

---

## 3. Warn-but-allow — VERIFIED ON BOTH LAYERS (design §6)

The single most important behavioral contract of Phase 2 is that invalid RIB/IBAN **must never block the save**. Confirmed:

- **Server:** `PaymentRepositoryController::store/update` keep `account_number`/`iban`/`bic` as `nullable string` with **no format/regex rule** (`:73-81, 131-141`). Validation is surfaced as non-blocking metadata via `formatRepository` → `bank_account_validation` (`:266, 275-289`), computed from the injected validator, never as a 422. `bank_id` uses `ScopedExists::tenant('banks', …)` (nullable) — an integrity check on the FK only, not a RIB/IBAN gate.
- **Server test proves it:** posting `account_number: 'not-a-valid-rib'`, `iban: 'not-a-valid-iban'` returns **201** with `bank_account_validation.rib.valid=false`/`iban.valid=false` and the row persisted (`PaymentRepositoryTest.php:215-251`).
- **Client:** the Save button is disabled **only** on `mutation.isPending` (`AddRepositoryModal.tsx` footer), never on validation status. Invalid input renders a `caution` warning, not a block.
- **Client test proves it:** `'shows an invalid RIB warning without disabling Save'` asserts the warning text **and** `getByRole('button', {name:'Save'}).toBeEnabled()` (`AddRepositoryModal.test.tsx:100-110`).

No hard-blocking validation was introduced anywhere. ✅

---

## 4. Findings (numbered, by severity)

### LOW-1 — Generated DTO `errors` field types as `Array<any>`
`packages/shared/types/generated.d.ts` emits `errors: Array<any>` for both `IbanValidationResult`/`RibValidationResult`. Rule 3 forbids `any` in TypeScript, but this is **generated** output (rule 7 forbids hand-editing it), produced by spatie/typescript-transformer from `public array $errors` (`RibValidationResult.php` / `IbanValidationResult.php`). **Non-blocking.** Optional future polish: if the transformer honors it, type the PHP property as a typed collection/`DataCollection` of an error enum so the emitted TS narrows to a string-literal union; not required for this gate and out of Phase-2 scope.

### INFO-2 — `toIban()` returns `''` sentinel on invalid input
`BankAccountValidator::toIban()` returns `''` when the RIB is invalid (`:65-73`). It is unused in the Phase-2 diff (the controller uses `validateRib`/`validateIban`; the FE derives IBAN client-side). Acceptable as an interface method reserved for Phase 3; flagging so the Phase-3 Partner consumer treats `''` as "no IBAN" rather than a valid empty IBAN. **Non-blocking, informational.**

### INFO-3 — Seed `rib_bank_code` values are data, not verified against `TN.json` here
`PaymentRepositorySeeder` now links `bank_id` by looking up `(tenant, country, rib_bank_code)` with codes `05/10/08` for BT/STB/BIAT (`PaymentRepositorySeeder.php:240-273`). The linkage is **graceful** — a miss yields `bank_id = null` (`:76-108`), never an error, and the seeder test asserts every seeded bank repository resolves to an existing `banks` row (`PaymentRepositorySeederTest.php:70-78`), so a wrong code would surface there. The correctness of those specific 2-digit codes vs. the committed `TN.json` clearing map is Phase-1 data and outside this diff's logic; the test guards the contract. **Non-blocking.**

No MEDIUM/HIGH/BLOCKER findings.

---

## 5. Out-of-scope discipline (brief §6)

- **No `PaymentInstrument` file touched** — confirmed; the diff touches `PaymentRepository*` only.
- **No FR data/fixtures added** — the validator is country-parameterized via `COUNTRY_CONFIG` (`BankAccountValidator.php:16-22`) with **TN only**; FR seed IBANs were *removed/blanked*, not sourced. FR remains deferred.
- **No FormRequest RIB/IBAN rejection added** — see §3.

---

## 6. Verification contract (brief §5) — coverage assessment

| §5 requirement | Met | Where |
|---|---|---|
| Validator unit tests w/ real TN fixture, re-derived (not hardcoded) | ✅ | `BankAccountValidatorTest.php:24-46` |
| Corrupted-key / wrong-length / non-numeric / foreign-IBAN-no-throw | ✅ | `:88-124` |
| A fixture that exceeds 64-bit int range, genuinely exercised | ✅ | `:60-74` (asserts `bccomp > PHP_INT_MAX`) |
| Picker acceptance: select bank → BIC autofill → valid RIB green + derived IBAN → Save enabled; invalid RIB warns + Save enabled | ✅ (as Vitest, per rule §3.12 — no unbounded Playwright) | `AddRepositoryModal.test.tsx:60-110` |
| Seeder linkage/idempotency | ✅ (Phase-2 delta: bank linkage) | `PaymentRepositorySeederTest.php:57-78`; BanksSeeder idempotency itself was Gate-1 |

Note: I did **not** re-execute the suites (review-only, and rule §3.12 forbids the full suite). Test *content* was read and judged sufficient and honest; the progress file records GREEN runs (validator 13/41, repository 15/44, seeder 1/28, modal 2, typecheck/lint/audits clean). This review does not independently re-confirm those pass/fail counts.

---

## 7. Summary

Phase 2 delivers exactly the specced surface: a Shared-module, string/bcmath-only bank-account validator (math independently re-derived and correct), interface-based DI into Treasury, a nullable `bank_id` FK with a re-run-safe migration, a token-only accessible `BankPicker` with directory + free-text fallback, and warn-but-allow validation proven on both client and server. Module boundaries, injection, i18n (en/fr/ar), design tokens, no-double-unwrap, and out-of-scope discipline all hold. The only findings are one non-actionable generated-type note and two informational observations — none blocking.

VERDICT: APPROVE
