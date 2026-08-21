I reviewed the full range, verified every load-bearing claim against code, and ran the milestone's own artifacts locally.

## Verification performed (independent, not from the brief/handback)

- `./vendor/bin/phpunit tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php` → **OK (3 tests, 200 assertions)** — matches the handback exactly; non-vacuous.
- `php tools/feature-lane-manifest-check.php` → **EXIT=0**, coverage debt **1131** unchanged.
- `./vendor/bin/phpunit tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php` → **8 tests, 5 errors**, all `DeliveryRequiredBeforeInvoiceException` from `DocumentPostingService.php:649` — reproduced the flagged lane red.
- Manifest partition: `ProvisioningRequiredPurposesV1::assertConforms()` pins **28/1/4/10** (`ProvisioningRequiredPurposesV1.php:249-254`) — the brief's "27/1/4/9" is stale; keying to the landed authority is correct (M2-D2 accepted).
- CI wiring is real: `ci.yml:1111-1112` runs `./vendor/bin/phpunit tests/Feature/Accounting`; job `if:` at `ci.yml:1012` includes `github.base_ref == 'dev'`; `DB_CONNECTION: pgsql` at `ci.yml:1048`; `treasury-spine-pgsql` is in `all-checks-pass` `needs` (`ci.yml:1443`).

---

## Register

**1 — P2 · CONFIRMED · `apps/api/tests/feature-lane-manifest.json:43-44`**
`Accounting.classes` was set to `82` with a note asserting "82 on disk after enforcement-P3 M2 added SeededChartManifestRequiredPurposeCompletenessTest (81 at the 2026-08-21 accepted tip)". The real count at HEAD is **83**. `git diff --name-status 0ca7bbb09..HEAD -- tests/Feature/Accounting` shows **two** additions — `ChokepointUnbalancedGuardTest.php` (M1, `git cat-file -e 0ca7bbb09:…` → not at base) and `SeededChartManifestRequiredPurposeCompletenessTest.php` (M2). Base was 81; `find tests/Feature/Accounting -name '*Test.php' | wc -l` at HEAD is 83, and the checker's enumerator is exactly `str_ends_with($file->getFilename(), 'Test.php')` (`tools/feature-lane-manifest-check.php:122-133`).
*Failure scenario:* no CI break today — the ceiling is enforced only for deferred/excluded groups (`:336-352`) — but M2 edited this field *specifically for documentation truth* and made it false. If the F-2 CI-budget decision ever moves `Accounting` to `deferred` (the reason string on `CountryDefaults` names that exact decision), the ceiling lands one below reality and the very next unrelated class trips `COVERAGE DEBT GREW`. M3's whole-package gate would inherit the false count.

**2 — P2 · CONFIRMED · `docs/handoff/reviews/enforcement-p3/M2-reconciliation.md:198-217` (mirrored in `enforcement-p3.progress.yaml` M2 record, "DELIVERABLE C")**
The red-first mutation evidence does not show what it claims. §4 states the mutant run "confirm[ed] the assertion is what catches a type mismatch". The pasted output shows the failure message naming **`product_revenue`**, not a type mismatch — because tamper 2 *vacates* the ProductRevenue mapping when it re-points that account at `customer_receivable` (`SeededChartManifestRequiredPurposeCompletenessTest.php:251-256`). With the type assertion removed, the loop passes `customer_receivable` (still mapped) and dies at the 8th REQUIRED entry on a **missing mapping**.
Arithmetic confirms it exactly: mutant = 85 (main: 3×28 + `assertNotEmpty`) + 12 (tamper 1, fails at COGS, the 10th entry) + 11 (tamper 2, fails at `product_revenue`, the 8th) = **108**, the number pasted. Green = 169 + 21 + 10 = **200**, reproduced locally.
*Failure scenario:* the register presents a mutant that died of a collateral fixture side-effect as proof that the type assertion is load-bearing. A later reader (or M3) takes the red-first artifact at face value. **The underlying invariant does hold** — unmutated tamper 2 asserts the message contains `expectedAccountType`, a string only the type assertion emits, and the class is green — so the fix is to restate the evidence, not to re-do the guard.

**3 — P3 · CONFIRMED · `SeededChartManifestRequiredPurposeCompletenessTest.php:240-263`**
Tamper case 2 is silently coupled to the manifest's *entry order*. It passes only because `CustomerReceivable` is the 3rd REQUIRED entry and `ProductRevenue` the 8th. `assertConforms()` pins counts and uniqueness but **not order** (`ProvisioningRequiredPurposesV1.php:213-256`), so a legal reorder that puts `ProductRevenue` first makes the failure message name `product_revenue`, and the `customer_receivable` / `expectedAccountType` assertions go red for a reason unrelated to the guard. Re-pointing the purpose onto a revenue account that carries **no** `system_purpose` would remove the coupling and also fix finding 2's evidence.

**4 — P3 · CONFIRMED · `M2-reconciliation.md:362-375`**
The pre-existing-red proof is weaker than its wording. Moving the M2 file out and re-running `InvoiceAndCreditNoteGLIntegrationTest` proves "not M2's", not "pre-existing at base" — the branch also carries M1's `GeneralLedgerService` / exception-split changes. I checked the attribution independently and it holds (M1's `SalesOrderToInvoiceConverter` edit is comment-only; nothing in the range touches `DocumentPostingService.php:649` or the delivery-before-invoice rule), so the conclusion is right and the claim should just say what was actually demonstrated. The substantive flag — landing M2 does not make `treasury-spine-pgsql` green — is correct and correctly left to the parent's red-gate reconciliation (rule 4).

**5 — P3 · CONFIRMED · scope asymmetry between the two provisioning arms**
The template arm enforces the scope-required purpose for timbre countries (`TemplatePublishingService` scope block, `…:309-317`), but M2's legacy-arm gate enforces REQUIRED only — so a future edit dropping `SalesStampDutyPayable` from the TN chart is caught on the template arm and not on the legacy one, even though `GeneralLedgerService.php:295` resolves it through the throwing `getAccountByPurpose()` for TN. **Not a live gap** (`TunisiaChartOfAccountsSeeder.php:210` maps it) and **not a scope violation** — the brief scopes deliverable 2 to REQUIRED — but it is the one dimension the legacy gate structurally cannot see, and it belongs in the report's §3 boundary discussion.

---

## Bypasses attempted that FAILED (guard held)

- **Vacuous-guard bypass:** an empty REQUIRED set cannot green the class — `assertNotEmpty($required)` (`:190`), `assertConforms()`'s 28/1/4/10 pin, and both tamper cases falling through to `$this->fail()` all close it.
- **Fourth-chart bypass:** only three legacy chart seeders exist; `CountryDefaultsChartOfAccountsSeeder` delegates to `seedForCompany()`; `LegacyExistingChartRepairPreviewer.php:102-104` mirrors the same three-arm match. Enumeration is complete, not assumed.
- **Ratchet-loosening bypass:** `debt_ceiling` untouched at 1131; laned groups carry no enforced ceiling; checker and `FeatureLaneManifestCheckerTest` both green.
- **Fictional-lane bypass:** the lane is real *and* runs the whole directory on PR→dev, and is in `all-checks-pass`.
- **Missed-local-list bypass:** the 8 reconciled rows are exactly the `hasAccountForPurpose` caller set. `TreasuryAccountChargeBridge.php:217-250` is an expected-lines map, not a resolve-or-fail list, and all four purposes it names are manifest-REQUIRED — no divergence.
- **Forbidden-edit bypass:** `ProvisioningRequiredPurposesV1`, `SystemAccountPurpose::requiredPurposes()`, `ChartOfAccountsService::validateCompanyAccounts()`, the three frozen seeders, `ci.yml`, `scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml` and `.claude/agents/*` are all absent from `git diff --name-only 0ca7bbb09..HEAD`.

**Lenses.** *treasury* — applied: the two `TreasuryReceiptBridge` lists (`:445-461`, `:528-544`) and `recordTolerancePurposeMissingAlertOrFail`'s fail-closed rethrow are characterised accurately, and D-1 correctly identifies a gate shape `allowedGateKinds()` cannot express. *fiscal-pos* — applied: no hash-chain, sealed-bytes or projection-write change in M2; D-1's projection-swallow class is reported, not unilaterally changed. *Rule 19* — no money or quantity arithmetic is introduced; N/A. F-4 discipline holds: `requiredPurposes()` and `validateCompanyAccounts()` are byte-unchanged and D-2 is reported, not closed.

All five findings are documentation-truth or brittleness; the guard itself is sound, non-vacuous, correctly manifest-keyed, and genuinely CI-gated. Findings 1 and 2 are one-line and one-paragraph corrections respectively.

VERDICT: CHANGES-REQUIRED
