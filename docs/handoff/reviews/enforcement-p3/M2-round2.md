# enforcement-P3 · p3-M2 · round-2 adversarial merge gate

**Range reviewed:** `0ca7bbb09..HEAD` (M2 = `d7c9d187a` guard/wiring, `c131b0c79` record, `79a330e00` round-1 fixes). **Lenses:** `treasury`, `fiscal-pos`. **Amending authority:** none. **Read-only:** no file was modified, staged, or committed; `git status --porcelain` is empty at exit.

## Independent verification performed (not taken from the brief or the handback)

| Check | Result |
|---|---|
| `phpunit tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php` (sqlite) | **OK (3 tests, 200 assertions)** |
| Same class on **real PostgreSQL** (`DB_CONNECTION=pgsql`, scratch `p3m2_test`) — the lane's actual engine, `ci.yml:1048` | **OK (3 tests, 200 assertions)** — the round-1 register did not verify this arm; I did |
| `php tools/feature-lane-manifest-check.php` | **EXIT 0**, coverage debt **1131** unchanged, `debt_ceiling` untouched |
| `phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php` | **OK (46 tests, 123 assertions)** |
| Class count (round-1 F1) | base `0ca7bbb09` = **81** (`git ls-tree`), HEAD = **83** (`find`), exactly two additions in range (`ChokepointUnbalancedGuardTest` M1 + M2's own). Manifest now says **83** — true |
| Manifest partition, executed | `entries()` → REQUIRED **28**, `assertConforms()` pins 28/1/4/10 (`ProvisioningRequiredPurposesV1.php:249-254`). `cost_of_goods_sold` is REQUIRED **#10**, `customer_receivable` **#3** |
| D-2's arithmetic, executed | `requiredPurposes()` = 14, `array_diff` both directions → **0** outside the manifest REQUIRED set, **14** missing from it, and the 14 names match the report's list exactly |
| Mutant-v2 claim (127 assertions) | reproduced arithmetically from the code: 85 (main, type assertion deleted) + 12 (tamper 1 dies at the 10th REQUIRED) + 30 (tamper 1... tamper 2 completes 28 `assertNotNull` + 1 guard + own `fail()`) = **127**, and the pasted `:274` matches HEAD's `:284` minus the 10-line assertion block. Self-consistent |
| Forbidden-edit sweep | `git diff --name-only 0ca7bbb09..HEAD` contains **no** `ProvisioningRequiredPurposesV1`, `SystemAccountPurpose`, `ChartOfAccounts*`, any `*Seeder`, `ci.yml`, `adversarial-review*`, control manifest, or `.claude/**` |
| Lane reality | `ci.yml:1112` runs `./vendor/bin/phpunit tests/Feature/Accounting`; job `if:` (`:1012`) includes `github.base_ref == 'dev'`; `treasury-spine-pgsql` ∈ `all-checks-pass needs` (`:1443`) |

Round-1's five findings are all genuinely closed, and I re-derived each rather than accepting the fix note: F1 (count 83, arithmetic above); F2 (the 108-assertion mutant is withdrawn in `M2-reconciliation.md:222-236` and in the YAML record, not defended); F3 (`…CompletenessTest.php:254-272` now re-points onto a Revenue account with `whereNull('system_purpose')`, guarded by `assertNotNull` — the chart's only defect is the type mismatch, so entry order no longer matters); F4 (the claim is now split into "not M2's" vs "base attribution by range inspection", and the inspection holds — `SalesOrderToInvoiceConverter.php:590-608` is comment-only and nothing in range touches `DocumentPostingService.php:649`); F5 (asymmetry note at `M2-reconciliation.md:163-180`, verified against `TemplatePublishingService.php:309-317`, `GeneralLedgerService.php:293-296`, `TunisiaChartOfAccountsSeeder.php:209-210`).

## Register

**1 — P3 · `apps/api/tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php:86` · CONFIRMED**
`COUNTRY_CODES = ['TN','FR','XX']` is a hand-copied snapshot of `ChartOfAccountsService::getSeederForCountry()`'s three-arm match (`:172-178`), and nothing binds the two. *Failure scenario:* a fourth arm (`'MA' => MoroccoChartOfAccountsSeeder`) is added; the completeness gate silently never seeds MA, ships green, and a Moroccan tenant is provisioned with an unmapped REQUIRED purpose — the exact class of silence this guard exists to end. One line closes it (`assertSame(['TN','FR'], $this->service->getSupportedCountries())`, or reflect over the match arms). **Mitigating and why this is P3, not P2:** the enumeration is complete *today* (verified: `getSupportedCountries()` returns `['TN','FR']`, `default` is the only other arm), the brief only requires "enumerate, don't assume", and the immediate sibling `ChartOfAccountsPurposeParityTest.php:131` hardcodes the identical trio — so this is house convention, not a deviation introduced by M2.

**2 — P3 · `docs/handoff/reviews/enforcement-p3/M2-reconciliation.md:56-58` · CONFIRMED**
The enumeration claim is broader than what the table delivers: it says the rows come from "grepping `hasAccountForPurpose` callers **and `SystemAccountPurpose` array literals across `app/`**". The `hasAccountForPurpose` half is exactly complete — I re-ran the grep and the caller set is precisely the 8 reconciled sites, with no ninth. The array-literal half is not: `app/Console/Commands/BackfillChartPurposesCommand.php:323-363` carries a six-purpose array literal (`CostOfGoodsSold`, `GeneralExpense`, `SupplierAdvance`, `CustomerAdvance`, `UninvoicedRevenue`, `SalesDiscount`) and is absent from the table, even though rows 7 and 8 list its two sibling backfill commands. *Failure scenario:* a reader treats the table as the closed set it claims to be and skips a site on a later pass. **No substantive consequence** — I classified the six myself: five are manifest-REQUIRED and `uninvoiced_revenue` is SOFT (a backfill creating an account is not a throwing resolution site), so the "zero misclassifications" result is unchanged. Fix is one row or one narrowed sentence.

**Not findings, recorded so the parent does not have to re-derive them:**
- The wired lane is **red at base for unrelated reasons** (5 `DeliveryRequiredBeforeInvoiceException` errors from the DN / document-per-action rule). M2 flags this correctly, attributes it correctly, and rule 4 forbids fixing it here. It belongs to the parent's red-gate reconciliation, and "wired" must not be read as "green-gating today".
- `on.push.branches` is `[main]` only (`ci.yml:3-5`), so the guard executes on PR→dev, PR→main, push→main and `workflow_dispatch` — not on a direct push to `dev`. That is the repo-wide CI posture and matches P2's own `runs_on_pr_dev` definition of a real lane; it is not an M2 defect.
- M2 touches no `app/` code, so the "phpstan L8 clean" line is true but carries no weight — `phpstan.neon:6-7` analyses `app/` only.

## Bypasses attempted that FAILED (the guard held)

1. **Empty-REQUIRED-set vacuity** — tried both routes: `assertConforms()`'s 28/1/4/10 pin throws on a shrunk partition, and if the `'REQUIRED'` string literal at `:129` ever stops matching the manifest's constant, the filter yields `[]` and the main test dies on `assertNotEmpty` (`:191`) *while both tamper cases fall through to their own `$this->fail()`*. Fail-closed on every arm.
2. **Grow-the-manifest bypass** — adding a 29th REQUIRED purpose with no chart mapping cannot ship green: `assertConforms()` throws on the count before the loop runs.
3. **Drift onto the already-covered arm** — `config(['country_defaults.provisioning_enabled' => false])` in `setUp():99` pins the legacy arm; I confirmed `seedForCompany()` (`ChartOfAccountsService.php:42-70`) branches on exactly that flag, so a config-default flip cannot silently retarget the class at the template arm.
4. **"The template arm already covers it, so this is redundant"** — refuted: `TemplatePublishingService::validateAccountRows` enforces REQUIRED (`:303-307`) and type (`:285`) for the *template* arm only; the legacy frozen seeders had neither guarantee, and neither `ChartOfAccountsPurposeParityTest` nor `ChartOfAccountsServiceTest` asserts `accounts.type` against `expectedAccountType()`. The type dimension is a genuine addition.
5. **Ratchet-loosening** — `debt_ceiling` untouched at 1131, checker exit 0, laned groups carry no enforced ceiling (`tools/feature-lane-manifest-check.php:337-352`), so the 81→83 edit is documentation truth and could not have been used to buy slack.
6. **Missed-local-list** — re-ran the `hasAccountForPurpose` sweep independently; the caller set is exactly the 8 rows. The one gap I found is documentation-only (finding 2).
7. **Tamper-2 uniqueness rationale** — checked the claim rather than trusting it: `accounts_company_purpose_unique` is a real `unique(['company_id','system_purpose'])` (`database/migrations/tenant/2025_12_06_002513_…:57`), so the vacate-then-reassign reasoning and the round-1 F3 diagnosis are both correct.
8. **Forbidden-edit smuggling** — no manifest, `requiredPurposes()`, `validateCompanyAccounts()`, frozen-seeder, `ci.yml`, harness or control-manifest byte was touched anywhere in the range.

## Lens application

**treasury** — applied. Rows 1–2 of the reconciliation (`TreasuryReceiptBridge.php:446-461`, `:528-544`) are characterised accurately against the code, and D-1's "third gate shape" is real: `recordTolerancePurposeMissingAlertOrFail` (`:585-600`) rethrows *only* when the alert write fails, so on a missing REQUIRED purpose the bridge persists an alert, **skips the GL entry, and the projection completes successfully**. Reporting it rather than unilaterally changing it is what the brief mandates (divergences are reported findings), and it correctly points at M1's R-11 cluster instead of ruling piecemeal. No partial-write or GL-atomicity regression: M2 writes no production code.

**fiscal-pos** — applied. No hash-chain, sealed-bytes, device-event or projection-write change in M2; the projection-swallow class it surfaces is reported, not touched.

**Rule 19** — N/A, and verified so: no money or quantity arithmetic, no scale resolution, no `bcmath` call is introduced anywhere in M2's diff. **Migrations / named queues / i18n** — none introduced; no user-facing strings. **F-4 discipline** — holds: `requiredPurposes()` and `validateCompanyAccounts()` are byte-unchanged and the 14-purpose brownfield gap is reported as an owner gate, not closed.

## Assessment

The guard is non-vacuous, correctly keyed to the landed manifest (never to `requiredPurposes()`, per D-6), fail-closed on manifest drift, genuinely CI-gated on a lane that is real and in `all-checks-pass`, and verified green on the engine the lane actually uses. Both round-2 findings are P3 documentation/brittleness notes with no consequence for correctness today, and neither is a merge blocker under the milestone's own acceptance criteria. They are worth folding into M3's whole-package pass rather than spending a fix round.

VERDICT: ACCEPT
