# Adversarial merge gate — R2 (fix round) — `fix/p0-tn-matricule-regex-convergence`

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/p0-tn-mf-regex`
- **Range under review:** `909f8705f..6090ec823` (one commit: "gate R1 fix round — device guard actually runs, legacy partners stay editable"); base `d80ee2375`
- **Round 1:** `docs/superpowers/reviews/2026-08-23-p0-tn-mf-regex-gate-r1.md` (superset/table-identity core VERIFIED there; not re-litigated here)
- **Reviewer mode:** read-only on the lane except two mutation probes, each restored and verified clean. Every result below was executed.

## 0. Class-resolution proof (WORKTREE, not main)

`ReflectionClass::getFileName()` from the worktree's own `vendor/autoload.php`:

```
App\Shared\Domain\Validation\CountryTaxNumberRules            => .../.worktrees/p0-tn-mf-regex/apps/api/app/Shared/Domain/Validation/CountryTaxNumberRules.php
App\Modules\Partner\Presentation\Requests\UpdatePartnerRequest => .../.worktrees/p0-tn-mf-regex/apps/api/app/Modules/Partner/Presentation/Requests/UpdatePartnerRequest.php
App\Modules\Partner\Presentation\Requests\CreatePartnerRequest => .../.worktrees/.../CreatePartnerRequest.php
App\Modules\Partner\Domain\Services\TaxIdValidationService     => .../.worktrees/.../TaxIdValidationService.php
App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator => .../.worktrees/.../FiscalPayloadConstraintValidator.php
App\Modules\Company\Services\CompanyContext                    => .../.worktrees/.../CompanyContext.php
```

TS side: `apps/pos/node_modules` in the worktree is a directory of per-entry symlinks into the main checkout (`@vitest`, `vitest`, …) with a real `.bin/vitest`; vitest reports `RUN v3.2.4 /Users/…/.worktrees/p0-tn-mf-regex/apps/pos`, and the test resolves both `../FiscalEventEngine.ts` and `../../../../../api/app/Shared/Domain/Validation/CountryTaxNumberRules.php` relative to its own file — i.e. the **lane's** sources on both sides of the mirror. All results below are lane code.

## 1. F-1 — CLOSED (executed, mutation-probed)

`apps/pos/src/lib/fiscal/__tests__/taxNumberPatterns.test.ts:13-24` now uses the repo idiom (`dirname(fileURLToPath(import.meta.url))` + `resolve`), with the jsdom/whatwg-`URL` trap documented in-file.

| Run (from the worktree) | Result |
|---|---|
| `npx vitest run src/lib/fiscal/__tests__/taxNumberPatterns.test.ts` | **5 passed (5)** — incl. the new `anchors like PHP /D — a trailing newline is rejected` |
| Mutation probe: device literal `[A-Z]{2,3}` → `[A-Z]{2,4}` (`FiscalEventEngine.ts:1420`) | **2 failed / 3 passed** — `mirrors the server canonical rule byte-for-byte` + `still rejects non-canonical shapes`; restored, `git status --porcelain` empty |
| `npx vitest run src/lib/fiscal` (collateral slice) | **28 files / 371 tests passed** — exactly the lane's claim |
| `npx tsc --noEmit` (whole POS project) + `eslint` on both changed TS files | clean |

The guard is real, executes, and is non-vacuous.

## 2. F-2 — CLOSED (grandfather clause is correct AND non-vacuous)

**Implementation (read, not inferred):**
- `UpdatePartnerRequest.php:49-66` — `prepareForValidation()` returns untouched when the submitted `vat_number` is byte-identical to the stored one; otherwise it normalizes to storage form.
- `UpdatePartnerRequest.php:113-121` — the closure grandfathers when `(string) $value === (string) $this->storedVatNumber()`.
- `UpdatePartnerRequest.php:214-235` — the stored row is fetched **once**, `whereKey($partnerId)->where('company_id', $companyId)`, memoized.

**Attack (a) — same matricule, different case/separators.** The ordering makes this a non-issue: normalization runs *before* the grandfather comparison, so a stored-compact `1234567AM000` resubmitted as `1234567/A/M/000`, `1234567 am 000` or `1234567am000` is normalized to the stored bytes first and *then* grandfathered — accepted, and the stored bytes are unchanged. For non-TN countries `normalizeForStorage()` is the identity, so a re-cased FR value is treated as CHANGED and validated — which is correct, because it *is* a different stored value.

**Is byte-identical sufficient for the web round trip? Yes — verified.** `PartnerForm.tsx:311-351` hydrates with `reset({ … vat_number: partner.vat_number ?? '' … })` — the persisted bytes verbatim — and the field is a plain `<Input {...register('vat_number')} />` (`PartnerForm.tsx:556-562`) with no transform; submit does `vat_number: data.vat_number || null` (`:406`). No server-side resource rewrites `vat_number` (no Partner resource transform found), and `apiPatch('/partners/${id}')` at `PartnerForm.tsx:383` is the **only** PATCH caller in `apps/web` and `apps/pos`. So an untouched field submits stored bytes exactly. Byte-identical is sufficient **and** simpler — no false 422 on the round trip.

**(b) NULL/absent:** `$value === null` returns at `:109-111`; an absent field never runs (`sometimes`, `:100`). `prepareForValidation` returns for non-string/empty (`:53`). Stored-NULL + submitted-empty degenerates to `'' === (string) null` → grandfathered → nothing to validate. Safe.
**(c) CREATE still fully validates:** `CreatePartnerRequest.php:88-105` has **no** grandfather branch — the closure goes straight to `resolvedTaxCountryCode()` + `validateVatNumber()`. Confirmed by reading the file.

**Unbound-context safety:** `CompanyContext::getCompanyId(): ?string` (`CompanyContext.php:36-39`) and `getCompany(): ?Company` (`:83-93`) both return null rather than throwing, so a request outside company context degrades to "no stored row, no fallback country" → no validation, no exception. (`requireCompanyId()` — the throwing one — is not used here.)

**Red-first evidence, re-run by me:**

| Run | Result |
|---|---|
| `tests/Feature/Partner/PartnerTunisianMatriculeTest.php` (17 tests, incl. the exact web-form payload cases) | **17 passed** (inside the 280-pass Feature/Partner run below) |
| Mutation probe: grandfather closure → `if (false) {` | **2 failed / 15 passed** — exactly `name only edit of a legacy null country partner is not blocked` (TN company) and its FR-company twin; restored, tree clean |

The two legacy-edit tests are load-bearing, not decorative.

## 3. F-5 — CLOSED (verified against BASE, byte-for-byte)

`git show d80ee2375:.../FiscalPayloadConstraintValidator.php` line 3098:

```php
if ($countryCode === 'TN') { return str_replace('/', '', $value); }
```

HEAD `CountryTaxNumberRules.php:87-94` is byte-identical (`$countryCode === 'TN'`, case-SENSITIVE). The seal path is unchanged in behaviour: `assertTaxNumberForCountry()` (`:3054-3057`) normalizes then looks up `self::TAX_NUMBER_PATTERNS[$countryCode]` case-sensitively (`:3060`). The retroactive-validity reasoning is documented at `CountryTaxNumberRules.php:78-85` and it is correct. Note the entry side stays *stricter*: `matches()` uppercases the country first (`:58`) and only then dispatches, so a lowercase `'tn'` at entry is pattern-checked while the seal would skip it — divergence in the safe direction (never accept-at-entry / reject-at-seal).

## 4. F-7 — MOSTLY closed; one honest caveat (see R2-3)

The defect F-7 named is gone: `createRequestAcceptsTn()` / `updateRequestAcceptsTn()` — the reflective pokes at the *request classes* that skipped `prepareForValidation`, the fallback and the closure — are deleted (`TunisianMatriculeConvergenceTest.php`, `-18` lines), replaced by `CountryTaxNumberRules::matches(...)` with a docblock pointing at the HTTP coverage.

The new `PartnerTunisianMatriculeTest::test_every_accepted_matricule_persists_a_value_the_seal_gate_accepts` (`:105-160`) does the real round trip: real `POST /api/v1/partners` for 7 input shapes → read the PERSISTED `vat_number` → feed it to the seal gate as **both** `buyer.tax_number` and `seller.tax_number`.

Two caveats, both stated for honesty rather than as blockers:
- **Reflection is not gone, it moved.** The seal gate is still invoked through `ReflectionMethod(FiscalPayloadConstraintValidator::class, 'assertTaxNumberForCountry')` (`:117-119`). That *is* the real method the production paths call (`FiscalPayloadConstraintValidator.php:1540, 1748, 2092, 2140`), the class has no constructor so `newInstanceWithoutConstructor()` is equivalent to `new`, and the method is `private` so there is no public alternative short of building a full golden payload. Acceptable — but the round trip is "entry → storage → the tax-format assertion", not "entry → storage → `validatePerEventConstraints()`".
- **The country is hardcoded `'TN'` on both ends of the test.** In production the seal-time country for a buyer is `countryCodeFromAddress($buyer['address']) ?? sellerCountryCodeFromPayload()` (`FiscalPayloadConstraintValidator.php:2131-2133`), and for the seller it is the jurisdiction (`:2092`) — neither is the partner's `country_code`, which is what the entry rule uses. So the convergence proof is airtight at the **pattern** level (which is the P0 this lane exists for) and silent at the **country-selection** level. Pre-existing, out of scope, but it should not be implied closed.

## 5. F-4 / F-6 / F-8

- **F-4 — documented.** `TaxIdValidationService.php:95-108` now carries an explicit ADVISORY-ONLY block naming the single consumer and stating that the TN arm applies the *matricule fiscale* pattern to `business_registration_number`. Matches the code (R1 verified the single-consumer claim).
- **F-6 — documented, citations spot-checked.** `CountryTaxNumberRules.php:19-25` states the KNOWN GAP: company `tax_id` has no format rule. Verified: `CreateCompanyRequest.php:28` and `UpdateCompanyRequest.php:45` are both `['nullable','string','max:50']`. The `CoffeeShopSeeder` counter-example is now stated as a counter-example rather than as evidence (`:105-109`) — value `1234567A` confirmed at `CoffeeShopSeeder.php:283` and **332** (the docblock cites 331 — off by one). See R2-5.
- **F-8 — fixed and provably aligned.** The lookup is now company-scoped (`UpdatePartnerRequest.php:219-227`), mirroring `PartnerController::update()` (`PartnerController.php:263-265`), which itself 404s any partner whose `company_id` differs — so the request-level scope can never be narrower than what is reachable. Memoization added on both `resolvedTaxCountryCode()` (`:170-172`, `CreatePartnerRequest.php:161-163`) and the stored row (`:216-228`), killing the repeated `Company::find`.

## 6. F-3 disposition — ACCEPTED RESIDUAL (blast radius is contained on UPDATE)

The question the brief poses: *does the grandfather contain F-3 to new/changed values?* On the UPDATE path, **yes, provably** — every unchanged submission short-circuits before `resolvedTaxCountryCode()` is consulted (`:113-121`), and the two legacy-edit tests fail the moment that branch is removed. I found no existing flow that 422s on an unrelated edit:
- Only `PartnerController::store/update` consume the two FormRequests (`PartnerController.php:19-20, 201, 258`); no service, job or console reuse.
- The migration wizard bypasses validation entirely — `PartiesRowMapper::toPartnerData()` (`PartiesRowMapper.php:16-28`) writes `vat_number` plus `country` (the ADDRESS country), never `country_code`, and persists through the model, not the FormRequest. So bulk import cannot 422 on the new rule (and this confirms R1's premise that imported rows carry `country_code = NULL`).

What remains uncontained is the **CREATE** path, which is country-agnostic by design: a create that omits/nulls `country_code` now gets the company-country rule, including the unaudited `FR|IT|GB` literals in `validateVatNumber()` (`UpdatePartnerRequest.php:~205`, `CreatePartnerRequest.php:~182`). Mitigation verified: the web form defaults `country_code` to the company country (`PartnerForm.tsx:168, 220-221`), so at base most creates were already validated under that same country — the new enforcement bites only when the operator explicitly clears the country field. Note also that the FR entry arm (`/^FR[0-9A-Z]{2}[0-9]{9}$/`) is **stricter** than the FR seal pattern (`/^([0-9]{9}|[0-9]{14})$/D` + the buyer intracom alternative), i.e. divergence in the safe direction for sealability.

**Disposition:** acceptable as a residual, on the condition that it is actually ticketed (see R2-2) — F-3 is currently disclosed only in prose.

## 7. Runs

| Gate (all executed in the worktree) | Result |
|---|---|
| `phpunit tests/Feature/Partner` + `tests/Unit/Shared/TunisianMatriculeConvergenceTest` + `tests/Unit/Partner/TaxIdValidationServiceTest` | **280 passed, 4 skipped, 1 failed** — the failure is the disclosed inherited `PartnerReferenceSchemaSweepTest` |
| `phpunit tests/Unit/Shared tests/Unit/Partner` | **292 passed** (8857 assertions), 3 deprecations |
| `phpunit tests/Feature/Fiscal/{FiscalPayloadConstraintValidatorTest,SaleReceiptV3PayloadConstraintTest,DepositReceiptPayloadConstraintTest}` | **171 passed, 1 FAILED — lane-caused, see R2-1** |
| POS vitest (touched file / `src/lib/fiscal`) | 5 passed / **371 passed in 28 files** |
| POS `tsc --noEmit` + `eslint` (changed TS) | clean |
| `phpstan analyse` level 8, the 7 changed PHP files | **[OK] No errors** |
| `pint --test`, the 9 changed PHP files | `{"result":"pass"}` |
| PG spot-check | **not executed** — `phpunit.xml:44-45` pins `sqlite`/`:memory:` and there is no `.env.testing` in the lane. Same caveat as R1; this lane contains no aggregate SQL (regex + one scoped `first(['country_code','vat_number'])`), so SQLite masking is not a plausible risk here. The lane's "PG 123" claim is **unverified by me**. |

**Inherited red — confirmed non-causal.** `PartnerReferenceSchemaSweepTest:69` fails with exactly one unaccounted column: `supplier_goods_return_notes.partner_id` — a table from the dpa-v8 supplier-returns lane. `git diff --name-status d80ee2375..6090ec823` contains **no migration**, no `PartnerReferenceCounter`, and nothing under the Supplier module, so this branch cannot have produced it. Disclosure accurate.

## 8. Scope audit

`git diff --stat 909f8705f..6090ec823` = **7 files, +425 / −61**, all of them fix surfaces named in R1: the two partner FormRequests, `TaxIdValidationService` (docblock only), `CountryTaxNumberRules` (docblock + the F-5 revert only — the `PATTERNS` table is untouched in this round), the two test files, and the POS test. No `.github/`, no key sets, no non-TN pattern changes, no migrations. Whole-branch manifest (`d80ee2375..6090ec823`) is still the 11 files R1 audited. Clean.

---

# Findings

### R2-1 [CRITICAL — merge blocker] The widening turns an existing seal-gate test red: a TN "must-reject" fixture is now accepted
`apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:705` (test at `:700-726`)

```php
'TN' => '1234567ABC000',   // fixture asserted to FAIL Phase 1.5.2 validation
```

Executed at HEAD:

```
FAILED  FiscalPayloadConstraintValidatorTest > phase 1 5 2 rejects country specific seller tax number mismatches
-'payload_tax_number_format_mismatch:field=seller.tax_number:country=TN:value=1234567ABC000'
+'TN seller tax number 1234567ABC000 should fail Phase 1.5.2 validation.'
at tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php:721
```

Causation is direct and proven, not inferred:

```
value=1234567ABC000   HEAD /^[0-9]{7,8}[A-Z]{2,3}[0-9]{3}$/D = 1
                      BASE /^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/D   = 0
```

`1234567ABC000` is 7 digits + **3** letters + 3 digits — precisely the arm this lane legalises. The file is **not** in the branch manifest, so it was green at base and is red at HEAD *because of this commit*. This is the seal gate's own constraint test — the file that guards the very table the lane edits — and it is absent from the lane's disclosed reds (R1 disclosed 3 in `tests/Unit/Fiscal`; the lane's "sqlite 316" run evidently never covered `tests/Feature/Fiscal`). It lands the API merge gate red.

*Why it matters beyond CI:* the superset proof answers only "old-accepted → new-rejected = 0". The opposite direction (old-rejected → **new-accepted**) is the whole point of the change, and every negative fixture in the repo encodes the old boundary. One of them is now a lie about the system's behaviour. Shipping it red also means the next reader cannot tell an intended semantic change from a regression.

*Fix:* replace the TN fixture with a shape that is genuinely invalid under the converged rule (e.g. `'1234567ABCD000'` — 4 letters — or `'1234567A000'`), with a one-line comment that the 3-letter arm is now canonical and why the old fixture stopped being a negative. Then sweep the remaining negative fiscal fixtures for the same class of stale boundary (I did the repo-wide sweep for `[0-9]{7,8}[A-Z]{3}[0-9]{3}` string literals: this is the **only** stale negative; `TreasuryReceiptBridgeRoundingGlTest.php:1070` and `TrainingReceiptTreasuryContainmentTest.php:634` use `1234567AAM000`, which moves in the accept direction only). Re-run `tests/Feature/Fiscal` before the next gate.

### R2-2 [IMPORTANT] The "residual ticket" is asserted in a docblock but no ticket exists on the branch
`apps/api/app/Shared/Domain/Validation/CountryTaxNumberRules.php:25` — "The seller-side hole is tracked as a residual ticket, not fixed here."

`git diff --name-status d80ee2375..6090ec823` contains no `docs/` file at all. The repo has an established convention (`docs/superpowers/tickets/`) that sibling lanes in this same session used on the same day (`2026-08-23-autosave-residuals.md`, `2026-08-23-c7-residuals-committed-state-siblings.md`). A claim of tracking with nothing tracked is worse than an undisclosed gap, because it stops the next reader from looking.

*Fix:* commit `docs/superpowers/tickets/2026-08-23-p0-tn-mf-residuals.md` on the branch covering, at minimum: (i) the company `tax_id` / `vat_number` seller-side entry hole (F-6), (ii) the F-3 cross-country create-path enforcement with the unaudited `FR|IT|GB` literals, (iii) the advisory `business_registration_number` / matricule semantic collision (F-4), (iv) the entry-vs-seal **country-selection** divergence from §4. Reference the ticket path from the docblock instead of the bare phrase "a residual ticket".

### R2-3 [MINOR] The round-trip test's own limits should be stated in the test, not just here
`apps/api/tests/Feature/Partner/PartnerTunisianMatriculeTest.php:105-160`

The docblock claims "If this passes, no matricule that the partner boundary accepts can be refused at seal time." That is true for the pattern, but the test pins `'TN'` on both ends while production derives the seal-time country from `buyer.address.country_code` with a seller fallback (`FiscalPayloadConstraintValidator.php:2131-2133`) and the entry country from `country_code` → stored partner → company (`UpdatePartnerRequest.php:168-195`). Add one sentence to the docblock scoping the claim to the pattern dimension and naming the country-selection divergence as the residual (ticketed per R2-2). Optional but stronger: assert the same values through the public `validatePerEventConstraints()` on a golden payload, which would also cover the routing.

### R2-4 [MINOR] Docblock citation drift and an incomplete gap statement
`CountryTaxNumberRules.php:106` cites `CoffeeShopSeeder.php:283,331`; the second occurrence is at **332** (`331` is the `country_code` line). And the KNOWN GAP at `:19-25` names only company `tax_id`, while `CreateCompanyRequest.php:30` shows company `vat_number` is equally unvalidated (`['nullable','string','max:50']`). Fix both while touching the file for R2-1/R2-2.

---

## Status of the R1 findings

| R1 | Status at `6090ec823` |
|---|---|
| F-1 device guard never executes | **CLOSED** — 5/5 pass, mutation-probed 2-red, 371/28 dir green, tsc+eslint clean |
| F-2 legacy partners uneditable | **CLOSED** — grandfather correct (normalize-then-compare ordering), byte-identical is sufficient given verbatim form hydration, non-vacuous under mutation, CREATE still fully validates |
| F-3 cross-country scope escape | **ACCEPTED RESIDUAL** — contained on UPDATE; CREATE path uncontained by design; needs the R2-2 ticket |
| F-4 advisory / registration-number semantics | **CLOSED (documented)** |
| F-5 normalizer case divergence | **CLOSED** — byte-identical to base, reasoning documented |
| F-6 docblock evidence + seller-side hole | **CLOSED (with R2-4 nits)** |
| F-7 unit test bypasses new logic | **CLOSED for the request layer**; caveats in §4 / R2-3 |
| F-8 unscoped lookup + repeated queries | **CLOSED** — company-scoped, single fetch, memoized |

## What to fix before merge
Fix R2-1 (a TN negative fixture in the seal gate's own constraint test is now a positive — the API merge gate is red, caused by this commit and not disclosed) and commit the residual ticket claimed at `CountryTaxNumberRules.php:25` (R2-2); R2-3/R2-4 can ride along.

VERDICT: CHANGES-REQUIRED
