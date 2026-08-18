# M1 adversarial merge-gate review — round 1
**Wave:** country-defaults-phase-a · **Base:** `7d85232cc` · **Head:** `eb5fb6c02` (M1 impl `07868ba12` + fix `6df85c92e`)
**Lens:** treasury (COA/purpose content, protected codes, absorber/timbre semantics). Applied. No tenancy-authz, fiscal-pos or frontend surface exists in this milestone.

---

## Register

### P1-1 — The manifest's `evidence_citation` column is systematically wrong, and the conformance suite only checks that it is non-empty · CONFIRMED
`apps/api/app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php:34-67`

M1 task 3 requires each of the 41 entries to carry `(purpose, call site, classification, gate kind, **evidence citation**)`. Every citation anchored in `GeneralLedgerService.php` / `AccountingService.php` — roughly 25 of the 41 entries — points at unrelated code:

| entry | cited | what is actually there |
|---|---|---|
| `Bank`/`Cash` (:34-35) | `GeneralLedgerService.php:3950-3951` | the `compensation_class` match arm / `InvalidArgumentException` throw (real sites: 4215/4216, 4598/4599) |
| `CustomerReceivable` (:36) | `AccountingService.php:409` | `]);` — the lookup is at 412/414 |
| `ProductRevenue` (:41) | `AccountingService.php:414` | `SystemAccountPurpose::CustomerReceivable` — **a different purpose** |
| `VatCollected` (:39) | `AccountingService.php:427` | `);` — the lookup is at 424/426 |
| `Inventory` (:37) | `GLS:1721,1829,1986` | a comment, `$pendingDraftClearing`, an `InvalidArgumentException` |
| `SupplierPayable`/`VatDeductible`/`PurchaseStampDuty`/`PPV*`/`GRNI` (:38,40,46-49) | `GLS:1983-1989` | `}`, blank lines, the `receivedQty` numeric guard |
| `CostOfGoodsSold` (:43) | `GLS:1720` | blank line |
| `GeneralExpense` (:44) | `GLS:3924,3938` | a docblock line and a `string $fiscalEventId,` parameter (real site: 4189→4203) |
| `SalesDiscount` (:50) | `GLS:3807-3827` | docblock (real site: 4091) |
| voucher quartet (:53-57) | `GLS:2563-2566,2619-2648` | real sites are 2795-2838 |
| `SalesStampDutyPayable` (:62) | `GLS:291 inside if ($hasStampDuty)` | 291 is `PurchaseStampDuty`; the stamp-payable lookup is 292 |

Cross-file citations (`AccountingOpeningService.php:314`, `RefundCompensationService.php:184`, `PaymentAllocationService.php:307`, `VoucherIssuanceService.php:295`, `VoucherRedemptionService.php:184`) *are* accurate, and `registeredThrowingCallSites()` (`:90-190`, 100 entries) is accurate — I spot-verified `GLS:4091|SalesDiscount`, `GLS:2159|SupplierPayable`, `GLS:1890|CostOfGoodsSold`, `GLS:291/292`. So the defect is confined to, and pervasive within, the two GL files.

`ProvisioningRequiredPurposesV1ConformanceTest.php:75-76` and `assertConforms()` (`:214-216`) only assert `trim(...) !== ''`. The brief's named evidence obligation is therefore satisfied vacuously.

**Failure scenario:** a treasury reviewer at M4 (`CertifiedFixtureDeltaTest`) or M5 is asked "why must every certified chart carry `GeneralExpense`?" — the manifest sends them to a docblock and a function signature. Nothing in CI notices. The manifest is the *certification* artifact this whole lane is built to produce; it is currently unverified prose next to a verified ratchet.

---

### P1-2 — `FrozenSeederDocblockTest` shells out to `git show 7d85232cc:…` and runs inside the CI `--testsuite=Unit` lane, which checks out a depth-1 clone · CONFIRMED
`apps/api/tests/Unit/CountryDefaults/FrozenSeederDocblockTest.php:35-44`

The test runs `git -C <repo> show 7d85232cc:apps/api/database/seeders/…` and byte-compares. Verified chain:
- `.github/workflows/ci.yml:228` uses `actions/checkout@v5` with **no `fetch-depth` override anywhere in the file** (grepped) → default depth 1, so `7d85232cc` (9 commits back) is not in the object store.
- `.github/workflows/ci.yml:275` runs `php artisan test --testsuite=Unit`, and `apps/api/phpunit.xml:8-10` maps that suite to the whole `tests/Unit` directory — this file included.
- `git show` on a missing object exits non-zero with empty stdout; `shell_exec` returns `''`, `assertNotNull` passes, and `assertSame('', $currentWithoutMarker)` fails.

**Failure scenario:** the first CI run after merge reds three data-provider cases with a diff of the entire seeder against an empty string — a failure mode that reads like content drift but is a missing git object. It also breaks for anyone running the suite from a shallow clone or an export, and depends on `shell_exec` not being disabled. The freeze guard needs a repo-committed fingerprint (e.g. a pinned SHA-256 of each frozen file) rather than a live `git` call.

---

### P2-3 — `CanonicalCoaSerializer` requires a unique integer `sort_order` on every row; neither the `accounts` table nor the three frozen chart definitions has one · CONFIRMED
`apps/api/app/Modules/CountryDefaults/Application/Services/CanonicalCoaSerializer.php:32-39`

- `apps/api/database/migrations/tenant/2025_11_30_090000_create_accounts_table.php:13-24` — no `sort_order` column; no later tenant migration adds one.
- `TunisiaChartOfAccountsSeeder.php:129+` (and FR/Generic) — definitions are `code/name/type/parent_code/system_purpose/is_system` only; the insert at `:78-93` writes no ordering key, and every row shares the same `$now` `created_at`.

M1 task 6 only asked for "rows sorted ascending by `sort_order`"; the uniqueness throw at `:36-38` is an addition beyond it.

**Failure scenario:** M4's `LegacyCoaGoldenExporter` must push the legacy TN/FR/Generic charts through this serializer to produce `tn.legacy-v1.txt` et al. Reading live `accounts` rows throws `InvalidArgumentException('Canonical COA sort_order must be an integer.')` on row 1. The only escape is to synthesize `sort_order` from the definition-array index — which silently makes the synthesis rule part of the certified byte contract while M1 leaves it unpinned, so the golden proves ordering that nothing else reproduces. Pin the ordering source (or drop uniqueness and add a deterministic `code` tiebreaker) at M1, before the goldens exist.

---

### P2-4 — The mandated capability ⇄ tax-seeder drift test is an uncorrelated whole-file substring conjunction and can false-pass · CONFIRMED
`apps/api/tests/Unit/CountryDefaults/CapabilityAuthoritySingularityTest.php:129-133`

```php
$seedsActiveStampDuty = str_contains($source, "'is_stamp_duty' => true")
    && preg_match("/'is_active'\s*=>\s*true/", $source) === 1;
```
The two conditions are evaluated over the whole file, never per row. `TunisiaTaxConfigurationSeeder.php` already mixes them: `'is_active' => true` at :64 (a VAT band), `'is_active' => false` at :109 (a stamp definition), `'is_active' => $stamp['is_active']` at :139 with `'is_stamp_duty' => true` at :143.

**Failure scenario (false pass, the dangerous direction):** someone deactivates TN's remaining active stamp-duty rows (:94/:121 → `false`). No active stamp configuration is seeded, but `'is_stamp_duty' => true` and an unrelated active VAT row both still appear in the file, so the test still computes `true`, agrees with `supportsStampDuty('TN')`, and stays green — while TN companies are provisioned with no usable timbre configuration. The symmetric false-*fail* also exists: adding a deactivated stamp row to FR reds the gate for a correct chart. Correlate per row (parse the definition arrays) rather than per file.

---

### P2-5 — Nothing asserts the REQUIRED direction of manifest ⇄ ratchet; six REQUIRED purposes have no named registered site · CONFIRMED
`apps/api/tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php:187-198`

Only the SOFT direction is checked (`assertStringNotContainsString`). No test asserts that a REQUIRED/SCOPE_REQUIRED purpose appears in `registeredThrowingCallSites()`. `GeneralExpense`, `SalesReturnsClearing`, `VoucherLiability`, `MarketingGoodwillExpense`, `PosTenderClearing`, `RoundingLossExpense` appear in *no* registered entry — they are reached only through `DYNAMIC` sites (`GLS:2735-2736`, `GLS:4203`, `GLS:3851`), which is legitimate, but it means their REQUIRED classification rests **entirely** on the citations shown wrong in P1-1.

**Failure scenario:** a purpose is silently misclassified REQUIRED→SOFT (or a new purpose lands classified SOFT while a DYNAMIC site can reach it). `assertConforms` accepts it because SOFT only requires `call_site === 'NONE'`, the partition counts can be rebalanced in the same edit, and no cross-check fires. Every certified chart then legitimately omits an account that a live GL path resolves.

---

### P2-6 — The protected-code registry carries `requires_system` but no `is_active` expectation, while the canonical projection deliberately excludes `is_active` · CONFIRMED (omission; consequence lands in M2)
`apps/api/app/Modules/CountryDefaults/Domain/Registries/ProtectedAccountCodeRegistry.php:54-60` vs `InstrumentAccountResolver.php:24-29`

The registry's nine treasury literals match `InstrumentAccountResolver::accountCode()/accountType()` exactly for TN, non-TN and wildcard (verified line by line), and the demo codes match both `ExpenseCategorySeeder` maps (`:42-49`, `:56-64`). But the resolver's third predicate is `->where('is_active', true)` (`:28`) — and `CanonicalCoaSerializer` excludes `is_active` from the projection by design (M1 invariant), so two templates differing only in `is_active` on `5312` produce the **same** `content_hash` and the same certification.

**Failure scenario:** an editor publishes a certified TN template with `5312` present, `is_system = true`, `is_active = false`. The publish gate M2 builds from this registry has nothing to check against; the hash matches the certified fixture; provisioning creates the row inactive; the first customer cheque deposit throws `MissingInstrumentAccountException` on a freshly certified chart. Add a `requires_active` expectation to the registry entries now, while the gate that will consume it is still unwritten.

---

### P3-7 — `CertificationScope::allowsAssignment()` throws on malformed input instead of returning `false`
`apps/api/app/Modules/CountryDefaults/Domain/ValueObjects/CertificationScope.php:70-79,88-90`. M3 wires this behind `PUT /assignments/{countryCode}`; an unvalidated route parameter (`FRA`, `tn-1`) becomes an uncaught `InvalidArgumentException` → 500 rather than 422. `AssignTemplateRequest` must validate the ISO shape before the VO is constructed — worth stating in the M3 handoff now.

### P3-8 — `DemoTenantSeeder` uses a mutable typed property assigned in `run()` rather than constructor injection
`apps/api/database/seeders/DemoTenantSeeder.php:84,98-99,2074`. The same commit constructor-injects `ParapharmacySeeder` (`:107`) correctly. House rule: `private readonly` constructor injection. Any future entry point that reaches `provisionCompanyTax()` without going through `run()` hits an uninitialized typed property `Error`.

### P3-9 — Scope beyond M1 task 1, plus one silently weakened assertion
M1 task 1 prescribes only the `CountryTaxConfigurationRegistry` delegation. `CompanyTaxProvisioningService::provisionForCompany()` gained a `bool $failLoudOnMissingCountry` parameter and lost its constructor flag (`:25,29`), rippling into six seeders and four test files. The ripple is justified (the registry can no longer be `new`-ed in an initializer) and is fully consistent — I found no surviving `new ParapharmacySeeder` / `new DemoPharmacySeeder` / `new CompanyTaxProvisioningService`, and `DemoPharmacySeeder` declares no constructor so it inherits correctly. But it is a public-API change to a Taxation service that the milestone did not name; it belongs in the report's decisions section. Separately, `CompanyTaxProvisioningServiceTest::test_is_idempotent` (`:48`) dropped `failLoudOnMissingCountry: true` and now runs with the default `false`, quietly reducing that case's coverage.

### P3-10 — `ProvisioningRequiredPurposesV1` and `ProtectedAccountCodeRegistry` are wholly static
M2–M5 consumers will call them statically, so they cannot be injected or substituted in a test. Both are pure data today, so this is a convention note, not a defect.

### P3-11 — New hard `ext-intl` dependency is undeclared
`CanonicalCoaSerializer.php:143` uses `Normalizer::normalize()`. `apps/api/composer.json:8-32` has no `ext-intl`. Not a live break — `Dockerfile:73` installs `intl` and it is present locally — but the requirement should be declared now that a certification-critical class depends on it.

### P3-12 — The ratchet is line-keyed by design and will red on unrelated edits
`ProvisioningRequiredPurposesV1.php:82-84` makes this explicit. I checked the risk and found **no imminent break**: `origin/dev` is still exactly `7d85232cc`, and local `dev` (38 ahead) touches neither `GeneralLedgerService.php` nor `AccountingService.php` and adds no new purpose-resolution site. It will recur the first time another lane edits those files.

---

## Bypasses attempted that FAILED (no finding)

- **Golden hash self-reference.** Recomputed `sha256` of the two-line golden independently in Python: `b93206e4fe2e416c36152f70c6684fd4c95a9865cbf5f1e46b4b13a9e100eb36` — exact match. The NFC case is real (`cafe\u{301}s` → `cafés`), `is_active` is genuinely excluded, no trailing newline, field order matches spec §5.4.
- **Second timbre authority evading the semantic AST guard.** `InstrumentAccountResolver::accountCode()` does hold `strtoupper($countryCode) === 'TN'`, but it returns `string` (a chart-plan selector, not a capability predicate) and `CountryTaxConfigurationRegistry::supports()` uses `isset(MAP[…])` with an array-of-arrays, so neither is a competing fixed authority. Found no live evasion; the test's own `ShadowPolicy` fixture proves vocabulary-independent detection.
- **M1 timbre invariant violated in the frozen charts.** `SalesStampDutyPayable` is present only in `TunisiaChartOfAccountsSeeder.php:210` and absent from FR and Generic; `PurchaseStampDuty` is present in all three (TN:274, FR:332, Generic:183). Invariant holds.
- **N-D violation.** `TaxConfigurationController` is byte-unchanged, and `TaxConfigurationCapabilityDelegationTest` pins both the real responses and an injected-fake response.
- **Merge-time ratchet/singularity collision with `dev`.** Checked `dev`'s new `app/Shared/Domain/CountryDocumentDefaults.php` — static, returns arrays, no bool predicate — it will not trip the semantic scan.
- **Hexagonal/deptrac regression.** `CountryDefaults/Domain → Accounting/Domain` is same-layer; `deptrac.yaml:17-22,92-94` explicitly does not enforce cross-module coupling and allows `ModuleDomain → SharedContracts`. No new violation.
- **Rule 19.** No money or quantity value is touched anywhere in the milestone; no float, no `bcmath`, no scale resolver needed. Lens not engaged.
- **Migrations / horizon queues / tenant scoping / i18n.** None introduced (no DB, no HTTP, no user-facing strings). Correctly out of scope for M1.

---

**Blocking:** P1-1, P1-2. **Fix before merge:** P2-3 … P2-6.

VERDICT: CHANGES-REQUIRED
