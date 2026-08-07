# Gate record — R2-H deliverable 1: zero-effective-withholding fiscal-certificate guard

- **Commit gated:** `8b4556ef9` ONLY (`fix(taxation): refuse fiscal certificate creation on a zero effective withholding amount`)
- **Branch / worktree:** `fix/r2h-withholding` @ `/Users/houssamr/Projects/syneriva/apps/erp.fix-r2h-withholding`
- **Diff base:** `a1952aa23` (siblings `550f626f8` web-unwrap and `b41ab525c` route-gating are gated separately)
- **Spec:** `docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md` #1, §20-69 (MTP-WHT-04)
- **Reviewer:** fiscal-pos-reviewer (adversarial, code-grounded). Gate only — no code modified.
- **Date:** 2026-08-07

## VERDICT: spec ✅ + quality APPROVED (5 Minor follow-ups, none blocking)

No Critical and no Important finding. Every load-bearing claim in the commit message was
verified against the code and re-proved on live PostgreSQL.

---

## 1. Guard placement — VERIFIED (no sequence, no row, no chain write before the guard)

`apps/api/app/Modules/Taxation/Application/Services/WithholdingCertificateService.php`

| Path | guard | `generateCertificateNumber()` | `repository->create()` |
|---|---|---|---|
| `create()` (manual-override percentage) | `:75` | `:79` | `:85` |
| `createFromPayment()` (fraction rate) | `:182` | `:186` | `:192` |

- Nothing before the guard on either path writes: `WithholdingCalculationService::calculateWithOverride()` is pure bcmath (`WithholdingCalculationService.php:87-101`); `calculateForPayment()` only reads rules (`:34-72`); `WithholdingCertificateController::store()` only reads a Partner before delegating (`WithholdingCertificateController.php:113-125`).
- The "sequence" is not a DB sequence: `EloquentWithholdingCertificateRepository::generateCertificateNumber()` `:108-125` derives `MAX(certificate_number)+1` per `(company_id, year)`. So the only thing that could ever create a gap is the **persisted phantom row**, which the guard prevents. Guard placement is therefore strictly stronger than required.
- Hash-chain writes happen only in `issue()` (`:283-309`), reachable only for an already-persisted row — a refused creation can never touch the chain.
- Exactly two writers of `withholding_certificates` exist: `WithholdingCertificate::create()` at `EloquentWithholdingCertificateRepository.php:129`, called only from service `:85` and `:192` (grep across `app/`, `database/`). No bypass path.
- Nested-transaction safety: the guard throws inside `DB::transaction` at `:155`, which is nested inside `PaymentController::store()`'s outer transaction → Laravel savepoint rollback, outer transaction survives. Proven empirically: `PaymentTest` 27/27 green on live PG (a PostgreSQL "current transaction is aborted" would have failed the whole request).

## 2. The payment-path swallow — VERIFIED, unchanged, narrow

- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:960` is `catch (\DomainException $e)` — a **narrow class**, not `\Throwable`/`\Exception`, and it is **pre-existing**: `git diff --stat a1952aa23 8b4556ef9` lists 5 files and `PaymentController.php` is not one of them.
- Nothing was broadened. The new guard simply adds one more `\DomainException` shape to a catch that already swallowed `'No applicable withholding rule found for this payment'` (service `:178`). Other error classes (e.g. `\InvalidArgumentException` from `normalizeWithholdingRate()` `:106-117`) still propagate exactly as before.
- No money divergence: the certificate is informational on this path — the payment amount comes from the request (`:906`), and no GL leg reads the certificate (grep for `withholding` in `PaymentController.php` shows no accounting coupling). Only `$payment->withholding_certificate_id` (`:958`) stays null, plus a `logger()->warning` (`:963`).

## 3. Zero-EFFECTIVE-amount semantics — VERIFIED with one documented mismatch

- Resolver is **constructor-injected** `private readonly CurrencyScaleResolverInterface $scaleResolver` (`:35`), never `app()`. Explicit currency is always passed (`:260`); `WithholdingCalculation::$currency` is typed non-nullable `string` (`WithholdingCalculation.php:34`), so the no-arg `getScale()` `UnboundCompanyContextException` trap (`CurrencyScaleResolverInterface.php:22`) is unreachable here. Correct for a context-free caller.
- `CurrencyScale::for()` (`app/Shared/Domain/CurrencyScale.php:62-65`) never throws (defaults to 2), so the guard cannot introduce a non-`DomainException` escape that the payment catch would miss.
- No float anywhere: `bccomp` on numeric strings, `$scale` is a variable (PHPStan `ForbidHardcodedBcmathScale` clean — verified by running PHPStan level 8 on the file: **no errors**).
- **Sub-scale dust — safe direction:** `withholding_amount` is computed at hardcoded bcmath scale 3 (`WithholdingCalculation.php:54`) and stored `decimal(15,3)` (`database/migrations/tenant/2026_01_08_172147_create_withholding_certificates_table.php:37`). No ISO currency has scale > 3, so a value that is zero at the resolved scale can never be a nonzero stored value — **no under-refusal is possible**; dust below 0.001 truncates to `0.000` and is refused. That is the fiscally important direction and it holds.
- **Documented mismatch (Minor):** the guard compares at the *currency's display scale*, not the *storage* scale. For a 2-dp currency (EUR/USD) a withholding of `0.004` — which the column WOULD store nonzero — is judged zero and refused; for a 0-dp currency (XOF/JPY/KRW) anything below `1.000` is refused. Conservative (no fictitious document), but it is an over-refusal, the exception text ("Withholding amount is zero") is then factually wrong, and the new docblock at `:224` states "rounds to `0.000` **at currency scale 3**", which contradicts the code at `:260` for every non-3-dp currency. For the launch currency (TND, scale 3 == storage scale) the guard is exactly "would store 0.000".

## 4. Legitimate-zero (exemption) question — JUDGED: guard does not over-refuse a supported flow

- Withholding exemption is already modelled *upstream* and already produced **no** certificate: `WithholdingCalculationService::calculateForPayment()` `:42-44` returns `null` when `$partner->withholding_exempt`, which the service turns into `'No applicable withholding rule found'` (`:71`, `:178`). So the module has never issued a zero-amount "exemption attestation" — the guard removes nothing that existed.
- Residual (Minor): `CreateWithholdingRuleRequest.php:32` / `UpdateWithholdingRuleRequest.php:30` still allow `rate` `min:0`, so a tenant can configure a 0% rule. Post-fix such a rule silently yields no certificate on the payment path (log-only) and a 422 on direct create. Recommend forbidding rate `0` at rule ingress, or documenting the semantics.
- Whether TN law ever requires a 0-withheld certificate **cannot be verified from code**; the ticket §20-69 asserts the opposite (a stream of `0.000` certificates is a stream of fictitious tax documents), and the module's own exemption semantics agree.

## 5. Test reruns — live PostgreSQL (`phpunit-pgsql.xml`, by path)

| Suite | Result |
|---|---|
| `tests/Feature/Taxation/WithholdingZeroRateGuardTest.php` | OK 3 tests / 10 assertions |
| `tests/Feature/Taxation/WithholdingCertificateTest.php` | OK 11 tests / 47 assertions |
| `tests/Feature/Treasury/PaymentTest.php` | OK 27 tests / 104 assertions |
| Regression: `WithholdingLifecycleTest` + `WithholdingPrecisionTest` + `SalesWithholdingTrackingTest` + `TEJExportServiceTest` | OK 51 tests / 188 assertions |
| PHPStan level 8 on the changed service | OK, no errors |
| Pint `--test` on the 4 touched PHP files | pass |

Notes:
- One earlier batch run reported 2 errors raised inside `RefreshDatabase::migrate` in `setUp` (framework stack, no assertion failure); a clean re-run of the identical batch was 51/51 green. Transient environment flake, not attributable to the diff.
- Runs were executed at worktree HEAD `b41ab525c` (siblings present). Siblings are disjoint from these assertions: `550f626f8` is web-only, `b41ab525c` adds `can:` middleware to `Taxation/routes.php` — the new HTTP test authenticates as `admin` and passes both with and without gating. The result therefore holds for `8b4556ef9`.
- MTP-WHT-04 tripwire flip verified in `apps/web/e2e/money-campaign/withholding.spec.ts`: title changed to "…with NO certificate (fix #1)", the junk-certificate void-cleanup loop removed, assertion now `expect(observed).toEqual([])`, payment still expected `201` and invoice `balance_due '0.000'`. The tripwire was updated, not deleted, and the historical comment is retained.

## 6. Nonzero-path fiscal payload — VERIFIED unchanged

- Only one `app/` file changed (`git diff --stat a1952aa23 8b4556ef9`), and inside it only: one constructor parameter (`:35`), two guard call-sites (`:75`, `:182`), one new private method (`:254-265`). No field feeding `repository->create()` (`:85-102`, `:192-209`) changed; `issue()` and its hash inputs (`:283-309`) are untouched; `WithholdingHashChainService` is not in the diff.
- Pinned by test: `WithholdingZeroRateGuardTest:166-183` (1000.000 @ 1.50% → `withholding_amount '15.000'`, 201) and `WithholdingLifecycleTest` (issue + chain) green.
- Constructor-signature change is safe: no `new WithholdingCertificateService(` anywhere outside vendor (grep); resolved via the container (`TaxationServiceProvider.php:79`), and `CurrencyScaleResolverInterface` is bound at `AppServiceProvider.php:86`.

---

## Findings (ordered by severity)

**No Critical. No Important.**

1. `[Minor]` `apps/api/app/Modules/Taxation/Application/Services/WithholdingCertificateService.php:224` vs `:260` — the docblock claims the threshold is "`0.000` at currency scale 3", but the code resolves the *currency's* scale (2 for EUR/USD, 0 for XOF/JPY) while the column is `decimal(15,3)` (migration `:37`) and the amount is computed at bcmath scale 3 (`WithholdingCalculation.php:54`). For non-3-dp currencies a storable nonzero (`0.004` EUR, `0.500` XOF) is refused as "zero" — conservative, but an over-refusal with a factually wrong message, and silent on the payment path. Fix: compare at the storage scale (`max($scale, 3)`), or keep the currency scale and correct the comment.
2. `[Minor]` `apps/api/tests/Feature/Taxation/WithholdingZeroRateGuardTest.php:119-120,157-158` — asserts only `422` + `error.code = CREATION_FAILED`, not `error.message`. `CREATION_FAILED` is also emitted for `'No applicable withholding rule found'` (service `:71`), so the HTTP test would still pass if `'0'` ever stopped being treated as a manual override (`CreateWithholdingCertificateData::isManualOverride()` uses `!== null` and `fromArray()` uses `isset()`, so `'0'` is an override today — verified). Fix: add `assertJsonPath('error.message', 'Withholding amount is zero; no certificate is created.')`.
3. `[Minor]` No test covers the `create()` / `createFromPayment()` **non-override** branch reaching the guard via a configured **0%-rate rule** — the only way a zero amount arises from configuration rather than user input, and the one still permitted by `CreateWithholdingRuleRequest.php:32` (`min:0`). Fix: add a rule-driven zero case, or forbid `rate = 0` at rule ingress.
4. `[Minor]` No test pins the currency-scale boundary of the guard (e.g. EUR `0.004`); finding 1's behaviour is entirely unpinned.
5. `[Minor]` `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:407-408` — `withholding_enabled: true` with `withholding_rate: '0'` remains a valid request that now 201s with no certificate and no response-level signal (log-only at `:963`). This *is* what MTP-WHT-04 specifies, so it is not a defect; but rejecting `rate = 0` at ingress when `withholding_enabled` is true would make the contract explicit instead of silent.

**Out of scope, pre-existing, untouched (no action required for this commit):** `EloquentWithholdingCertificateRepository::generateCertificateNumber()` `:108-125` derives the next number with an unlocked `MAX` read while `(company_id, certificate_number, year)` is uniquely indexed (migration `:74`) — concurrent creations in the same company/year can collide.

## What to fix before merge

Nothing blocking — optionally reconcile the scale-3 comment with the currency-scale code (finding 1) and add the `error.message` assertion (finding 2) in a follow-up.
