# Adversarial merge gate — R1 — `fix/p0-tn-matricule-regex-convergence`

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/p0-tn-mf-regex`
- **Commit:** `909f8705f` (single) on base `d80ee2375`
- **Authority:** `docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md` §3.3
- **Lens:** sealed-bytes adjacency (retroactive validity of the fiscal payload gate)
- **Reviewer mode:** read-only on the lane; every claim below was executed, not inferred, except where marked "not executed".

## 0. Class-resolution proof (WORKTREE, not main)

`vendor/` in the worktree is a real directory (not the stale-symlink trap). `ReflectionClass::getFileName()` for all five changed PHP classes resolves under
`/Users/houssamr/.../.worktrees/p0-tn-mf-regex/apps/api/app/...` — e.g.
`App\Shared\Domain\Validation\CountryTaxNumberRules => .../.worktrees/p0-tn-mf-regex/apps/api/app/Shared/Domain/Validation/CountryTaxNumberRules.php`.
All PHP results below are therefore lane code.

## 1. Retroactive-validity invariant — VERIFIED (the load-bearing claim holds)

**Table identity (value-preserving refactor).** At runtime:
- `FiscalPayloadConstraintValidator::TAX_NUMBER_PATTERNS === CountryTaxNumberRules::PATTERNS` → `true` (byte-identical arrays).
- Versus the base table (transcribed verbatim from `git show d80ee2375:...`): `FR` IDENTICAL, `SA` IDENTICAL, `DE` IDENTICAL, `IT` IDENTICAL, `TN` CHANGED; key order preserved; **no keys added or removed**.
- Note for the brief: there is **no `GB` entry** in this table at base or at HEAD (GB lives only in the partner-request `match`, unchanged).

**The only edit is the quantifier.** `/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/D` → `/^[0-9]{7,8}[A-Z]{2,3}[0-9]{3}$/D` (`CountryTaxNumberRules.php:41`).

**Superset proof, run independently of the lane's test:**
- Structured exhaustion over the language's only varying dimension: both digit arms (6 lead groups) × **all 676 ordered `[A-Z]` pairs** × 5 tail groups = **40,560 witnesses**; old-accepted-but-new-rejected = **0**.
- Differential fuzz: **400,000** random strings over the adversarial alphabet `0 1 9 A Z a z / space tab \n \r - .` lengths 0–16; old-accepted-but-new-rejected = **0**.
- Edge cases: `"1234567AM000\n"` → rejected by BOTH (the `/D` modifier is present on both sides); `"1234567/A/M/000"` rejected by both pre-normalization; new-only acceptances are exactly the 3-letter arm (`1234567AMN000`, `12345678AMN000`).

**Conclusion:** no historical sealed payload can become retroactively invalid through the pattern change. The lane's central claim is sound and independently reproduced.

The lane's own guard (`tests/Unit/Shared/TunisianMatriculeConvergenceTest.php:68`, 4,056 witnesses) runs green: `43 tests, 8254 assertions OK`.

## 2. Normalizer delegation — VERIFIED outcome-preserving (one latent divergence, F-5)

Differential run of the OLD inline normalizer (transcribed from base `FiscalPayloadConstraintValidator`) against `CountryTaxNumberRules::normalizeForMatching()` over 11 country codes × 12 values (incl. lowercase, embedded spaces/slashes/tabs, trailing newline, empty, `//`): **4 divergences of 132**, all of one class — country code `'tn'` / `'Tn'` (old compared case-sensitively `=== 'TN'`, new does `strtoupper(...) === 'TN'`).

That divergence cannot change a seal-path outcome: `assertTaxNumberForCountry()` normalizes at `FiscalPayloadConstraintValidator.php:3057`, then looks the pattern up **case-sensitively** at `:3060`, and returns at `:3066` (`if ($patterns === []) return;`) before `$normalized` is ever used; the thrown message uses the raw `$taxNumber`, not the normalized one (`:3080-3085`). `$normalized` feeds `preg_match` and nothing else (grep: the only uses of the normalizer/table are `:3057`, `:3060`, `:3098`).

## 3. Device mirror — **BROKEN** (see F-1), literal itself correct

Executed for real. Harness (no writes to the main checkout or the lane): scratch root at `…/scratchpad/posrun/apps/pos` with `node_modules` symlinked to the main checkout's `apps/pos/node_modules`, the lane's `src/` copied in, the lane's `vitest.config.ts` copied verbatim (it is byte-identical to main's), and the lane's `CountryTaxNumberRules.php` symlinked at the `apps/api/...` relative location the test expects. Harness validated by running an existing analogous lane test — `src/lib/stock/__tests__/homePageIngressPin.test.ts` → **4 passed**.

- `apps/pos/src/lib/fiscal/__tests__/taxNumberPatterns.test.ts` → **FAIL at collection**, `TypeError: The URL must be of scheme file`, `taxNumberPatterns.test.ts:15`. Zero tests execute. Reproduced with `--no-cache`.
- With the one-line path-idiom fix applied in the scratch copy → **4 passed (4 tests)**, including the byte-mirror assertion against the server literal and the 3,380-witness superset loop.

So the *content* of the device guard is correct; its file-path idiom is not.

The **PHP half of the mirror does run and is green**: `TunisianMatriculeConvergenceTest::test_pos_device_tn_pattern_mirrors_the_server_pattern` (`:248-267`) reads `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` and asserts equality with `substr(PATTERNS['TN'], 0, -1)`. So the mirror is not unguarded — but it is guarded on one side only, contrary to the lane's claim of "cross-language literal-reading guards on BOTH sides".

`$`-without-`m` end-anchoring: the TS test asserts the server literal ends with `/D` and that `deviceLiteral === serverLiteral.slice(0, -1)` — correct, and because the capture is `(\S+)` an added JS flag would break the byte-comparison. However the equivalence to PHP's `/D` is asserted only in a comment; there is **no** test that the device regex rejects a trailing newline. Fold into the F-1 fix.

The widened TS literal (`FiscalEventEngine.ts:1420`) byte-matches the PHP literal minus the `D` modifier — confirmed by both the (green) PHP guard and the (fixed) TS guard. `TN:` occurs exactly once in the engine, so the literal-extraction regex is unambiguous.

## 4. `resolvedTaxCountryCode()` — one PROVEN P1 regression (F-2) + scope escape (F-3)

- **(a) Non-HTTP reuse / unbound CompanyContext: clean.** `CreatePartnerRequest` / `UpdatePartnerRequest` are referenced only by `PartnerController::store` (`:201`) and `::update` (`:258`) — no service, job or console reuse. And `CompanyContext::getCompany()` (`CompanyContext.php:86-93`) returns `null` when unbound (it is `requireCompanyId()` that throws), so an unbound context degrades to `''` → "no fallback", never a throw.
- **(b) Route binding: the stored-country leg is live.** `PartnerController::update(UpdatePartnerRequest $request, string $partner)` — plain string, no route-model binding — so `$this->route('partner')` is the id string as the code assumes.
- **(c) The regression is real and proven on the HTTP path.** See F-2.

`vat_number` is `sometimes` (`UpdatePartnerRequest.php:83`), so a request that omits it is not validated — but that does not save the flow, because the web client always sends it together with `country_code: data.country_code || null` (`apps/web/src/features/partners/PartnerForm.tsx:405`), and `resolvedTaxCountryCode()` treats an explicit `null` exactly like an omission (`is_string(null) === false`).

## 5. Advisory endpoint — confirmed non-blocking; test rewrite honest

`TaxIdValidationService` has exactly one consumer: `PartnerController::validateTaxId` (`:440-482`), which returns `$result->toArray()` in the response body and never rejects a write. The narrowing therefore blocks nothing.

The test rewrite is honest: the two shapes the old third pattern accepted (`1234567A000`, `9876543BABC`) were not deleted — they were converted into explicit rejection assertions with a docblock stating why (`tests/Unit/Partner/TaxIdValidationServiceTest.php`, `test_rejects_matricule_shapes_the_seal_boundary_refuses`). Good practice.

One substantive note → F-4: the endpoint validates `business_registration_number` (`PartnerController.php:460`), not `vat_number`, so the matricule-fiscale pattern is now applied to a different identifier.

## 6. Full-run picture

| Gate | Result |
|---|---|
| `phpunit tests/Unit/Shared/TunisianMatriculeConvergenceTest.php tests/Unit/Partner/TaxIdValidationServiceTest.php` | **OK (43 tests, 8254 assertions)** |
| `phpunit tests/Feature/Partner/PartnerTunisianMatriculeTest.php` | **OK (11 tests, 29 assertions)** |
| `phpunit tests/Unit/Fiscal` | 220 tests, **3 failures** (disclosed) |
| `phpunit tests/Unit/CountryDefaults/FrozenSeederDocblockTest.php` | **OK (3 tests, 6 assertions)** |
| `pint --test` (9 changed PHP files) | `{"result":"pass"}` |
| `phpstan analyse` (6 changed source files, level 8) | **No errors** |
| `apps/pos` vitest — new mirror test | **FAIL (collection error)** → F-1 |

**The 3 Fiscal Unit reds** are `FiscalEventPayloadRegistryTest::test_returns_event_version_one_for_implemented_types`, `SaleReceiptV3KeySetTest::test_registry_supports_versions_one_two_and_three_and_authors_three` (both: registry now reports `[1,2,3,4]`), and `StrictCanonicalParserTest::test_rejects_envelope_with_event_version_mismatch_to_registry`. I did **not** execute them at base (that would require mutating repo state). Non-causation is established from the diff manifest instead: none of the files those tests exercise (`FiscalEventPayloadRegistry`, `StrictCanonicalParser`, their test files) appear in `git diff --name-only d80ee2375 HEAD`; the only fiscal source touched is `FiscalPayloadConstraintValidator`, whose delta is proven value-preserving (§1) and outcome-preserving (§2), and none of the three failures reference tax-number logic. Disclosure is accurate.

**Frozen-seeder guard:** green. `DemoPharmacySeeder` is not in the frozen list (`FrozenSeederDocblockTest.php:21-26` yields only the Tunisia/France/Generic chart seeders), so the docblock touch is safe.

**PG spot-check:** not executed (suite is configured `DB_CONNECTION=sqlite`, `:memory:` in `phpunit.xml:44-45`; no `.env.testing` in the lane). Low risk here: this lane contains no aggregate SQL — the logic is regex + one `value('country_code')` lookup.

## 7. Scope audit

Single commit, 11 files, `+897 / −34`. No `.github/` changes, no key-set changes, no b2b literal changes, no non-TN pattern changes (proven byte-wise in §1). Server-side convergence is complete: a repo-wide sweep for residual TN regex literals returns none outside the shared class, and the shared class now has 7 consumers (`FiscalPayloadConstraintValidator`, both partner requests, `TaxIdValidationService`, and both location requests, which already consumed it at base and inherit the widening safely).

One scope escape → **F-3**.

---

# Findings

### F-1 [CRITICAL — merge blocker] Device-side mirror guard never executes and turns the POS merge gate red
`apps/pos/src/lib/fiscal/__tests__/taxNumberPatterns.test.ts:15-22`
```ts
const ENGINE_PATH = fileURLToPath(new URL('../FiscalEventEngine.ts', import.meta.url));
```
Under the POS project's own `environment: 'jsdom'` (`apps/pos/vitest.config.ts`), the global `URL` is jsdom's whatwg-url class, not Node's; `fileURLToPath` rejects that object with `TypeError: The URL must be of scheme file` **at module evaluation**, so the whole suite fails to collect and **zero assertions run**.

*Why it matters:* (i) the lane's headline safety claim — a cross-language literal guard on BOTH sides — is not true in the shipped configuration (only the PHP side guards the mirror); (ii) `.github/workflows/ci.yml:2333` "POS Tests (Vitest)" runs `pnpm test` (= `vitest run`, whole suite) **on every pull request** and is described in-file as "the merge gate that runs them", so this lands CI red.

*Fix (verified: 4/4 pass with it applied):* use the idiom already established in this repo at `apps/pos/src/lib/stock/__tests__/homePageIngressPin.test.ts:17-23`:
```ts
import { dirname, resolve } from 'node:path';
const HERE = dirname(fileURLToPath(import.meta.url));   // string arg — Node's own parser
const ENGINE_PATH = resolve(HERE, '../FiscalEventEngine.ts');
const SERVER_RULES_PATH = resolve(HERE, '../../../../../api/app/Shared/Domain/Validation/CountryTaxNumberRules.php');
```
While there, add the missing `/D`-equivalence assertion: `expect(deviceTn.test('1234567AM000\n')).toBe(false);`.

### F-2 [CRITICAL — merge blocker] The company-country fallback makes legacy null-`country_code` partners uneditable (proven 422 on an untouched field)
`apps/api/app/Modules/Partner/Presentation/Requests/UpdatePartnerRequest.php:92-110` and `:161-181`

Three facts combine:
1. The migration/import path never sets a country: `apps/api/app/Modules/Import/Services/PartiesRowMapper.php:23` maps `'vat_number' => $data['tax_id'] ?? null` and writes **no** `country_code`. Every partner onboarded through the migration wizard has `country_code = NULL` plus an arbitrary tax id.
2. The web client always sends both fields, with the country coerced to null: `apps/web/src/features/partners/PartnerForm.tsx:405` → `country_code: data.country_code || null`. So `sometimes` on `vat_number` does not protect anything, and `resolvedTaxCountryCode()` cannot distinguish "explicit null" from "omitted".
3. The fallback then applies the **acting company's** country rule to that foreign/unknown VAT.

Executed against the lane over real HTTP (`RefreshDatabase`, real models, `RolesAndPermissionsSeeder`, `actingAs(...,'sanctum')`), submitting exactly the payload shape the web form sends and changing only `name`:

- TN company, partner `country_code = NULL`, `vat_number = 'FR12345678901'` → **HTTP 422**, `{"vat_number":["The VAT number format is invalid for the selected country."]}`
- FR company, partner `country_code = NULL`, `vat_number = '732829320'` → **HTTP 422**, same error.

At base this passed unconditionally (`$countryCode = $this->input('country_code'); if ($countryCode === null) { return; }`). So a rename — or any other field edit — now fails on a field the operator never touched, and the message names "the selected country" when none is selected. The record is only recoverable by an operator who knows to fill the country field or blank the VAT.

*Why it matters:* the migration wizard is the first-client onboarding path; this is a live-flow break, not a theoretical one. It is also not covered by the new tests: `PartnerTunisianMatriculeTest` only exercises partners whose stored `country_code` is `'TN'` (`:248`, `:268`) and never the null-country legacy row.

*Fix (choose one, then add the missing test):*
- (a) **Grandfather on update** — run the format check only when the submitted `vat_number` differs from the stored one; an unchanged legacy value is never re-litigated. This preserves the spec's intent (a *new or changed* MF must be sealable) while leaving historical rows editable.
- (b) If (a) is rejected, restrict the fallback to the create path and to updates that explicitly submit a non-empty `country_code`, and file the legacy-row cleanup as a separate data lane.
- In either case make the message name the inferred country ("…invalid for TN (inferred from your company)"), and add a feature test using the exact web-form payload (`country_code: null` + unchanged `vat_number`) against a null-country partner in a TN **and** an FR company.

### F-3 [IMPORTANT] Scope escape: the fallback silently activates FR/IT/GB entry enforcement, outside the declared TN scope
`UpdatePartnerRequest.php:183-196`, `CreatePartnerRequest.php:168-182` — `validateVatNumber()` matches on `FR|TN|IT|GB`. The lane is scoped "TN matricule convergence", but `resolvedTaxCountryCode()` is country-agnostic, so partners with a null `country_code` in FR/IT/GB companies now get format enforcement that was dormant before (demonstrated for FR in F-2). Either narrow this lane's fallback to TN, or amend the commit message/spec note to disclose that the hole closure is cross-country and audit the FR/IT/GB literals with the same rigor the TN one received.

### F-4 [IMPORTANT] The advisory narrowing now applies the *matricule fiscale* pattern to `business_registration_number`
`apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php:459-475` feeds `business_registration_number` into `TaxIdValidationService::validate()`, whose TN arm now delegates to the **vat_number** rule (`TaxIdValidationService.php:96-99`). In Tunisia the matricule fiscale and the RNE / registre de commerce are different identifiers, and the old advisory pattern was looser. Non-blocking (confirmed: single consumer, response body only), but operators will now see "invalid" on legitimately-stored registration numbers. Either scope the delegation to the `vat_number` semantic or state in the docblock that TN `business_registration_number` is expected to carry the matricule.

### F-5 [MINOR] Latent case-sensitivity divergence introduced into the shared normalizer
`CountryTaxNumberRules.php:69-76` uses `strtoupper($countryCode) === 'TN'`; the inline normalizer it replaced compared `$countryCode === 'TN'`. Measured divergence: 4/132 input pairs, all with a lowercase/mixed-case country code. Currently unreachable to an outcome change (§2), but it becomes live the moment anyone makes the `TAX_NUMBER_PATTERNS` lookup at `FiscalPayloadConstraintValidator.php:3060` case-insensitive. Add a one-line comment there, or uppercase the country once at the top of `assertTaxNumberForCountry()` so both steps share one convention.

### F-6 [MINOR] Docblock evidence is self-undercutting, and the seller-side entry hole is undisclosed
`CountryTaxNumberRules.php:82-86` cites `CoffeeShopSeeder` storing `1234567A` as evidence of the stored TN convention. That value **does not match** the converged rule (`CountryTaxNumberRules::matches('TN','1234567A') === false`, executed), and it is seeded as the `tax_id` of a **TN** company and location (`CoffeeShopSeeder.php:283,331`). Separately, the class docblock at `:10-13` claims "every entry point that validates a tax number consumes this class", but company `tax_id` — the value that becomes `seller.tax_number` at seal time — has no format rule at all (`CreateCompanyRequest.php:28`, `UpdateCompanyRequest.php:45`: `['nullable','string','max:50']`). Both are pre-existing, neither is a regression; correct the cited evidence and add one sentence disclosing the seller-side gap so the next reader does not over-trust the "single source of truth" claim.

### F-7 [MINOR] The convergence unit test bypasses the new logic it is meant to protect
`tests/Unit/Shared/TunisianMatriculeConvergenceTest.php:269-285` reflectively invokes the private `validateVatNumber` on `newInstanceWithoutConstructor()`, so `prepareForValidation()`, `resolvedTaxCountryCode()` and the validation closure — the actually new, actually risky code — are never executed there. The HTTP coverage lives in `PartnerTunisianMatriculeTest`, which is good, but has the gap named in F-2. Add the null-country case there.

### F-8 [MINOR] Unscoped partner lookup + repeated queries in the FormRequest
`UpdatePartnerRequest.php:174-179` does `Partner::query()->whereKey($partnerId)->value('country_code')` with no company scoping, while the controller scopes by company (`PartnerController.php:263-265`). Under database-per-tenant there is no cross-tenant exposure, but in a multi-company tenant a partner id from another company would seed the validation country. Scope the lookup with `$this->companyContext`. Also `resolvedTaxCountryCode()` runs up to twice per request (`prepareForValidation()` + the closure) and `CompanyContext::getCompany()` issues a fresh `Company::find` each call (`CompanyContext.php:86-93`) — memoize the resolved code in a property.

---

## What to fix before merge
Fix F-1 (one-line path idiom + the `\n` assertion — the POS merge gate is red and the device guard runs zero assertions) and F-2 (grandfather unchanged `vat_number` on update, or restrict the fallback — proven 422 on untouched fields for every migration-wizard-imported partner), then re-gate; F-3–F-8 can ride along in the same round.

VERDICT: CHANGES-REQUIRED
