# Lane G-4 duplicate-policy gate r9 — sparse price rows

Review target: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g4-duplicates`, branch `feat/g4-duplicate-policy-merge`, dirty fix-round-7 overlay on `f878a248c`. Source was reviewed read-only. This register is the only intentional workspace write. The running servers on `:8011` and `:5174` were not touched. PostgreSQL legs ran serially with `DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_g2 DB_CENTRAL_DATABASE=autoerp_test_g2`; no `apps/api/autoerp_test_*` file was created.

## Findings

| ID | severity | file:line | finding | change |
|---|---|---|---|---|
| G4-R8-01 | **HIGH — FIXED** | `apps/api/app/Modules/Import/Services/ImportService.php:859-861,891-901,1027-1065`; `apps/api/app/Modules/Import/Services/CoalescingAttributeMerger.php:21-22,43-56`; `apps/api/app/Modules/Product/Application/Services/ProductService.php:65-82`; `apps/api/tests/Feature/Import/ProductsImportPipelineTest.php:215-319,1526-1577` | The r8 sparse-update data-loss path is closed. Existing cost/tax inputs are read through the Shared product contract and coalesced only into resolver inputs. A derived `sale_price` enters row data only when `ProductPriceResolver` returns a non-null candidate. The merger additionally refuses to overwrite an existing TTC sale price with a null derived value. Purchase-only and tax-only therefore preserve stored TTC; margin-only derives from stored purchase price at the effective stored tax rate. `margin_percent` is absent from current Import code/tests. Full execute-path regressions pass on SQLite and PostgreSQL. | None. |

No new finding ID was opened.

## R8-01 adversarial probe

The probe instantiated the actual `ProductPriceResolver` and `CoalescingAttributeMerger`, and invoked the actual private `ImportService::effectivePriceInputs()` / `effectivePriceTaxRate()` helpers read-only. Every case started from an existing product with `purchase_price=8.000`, `sale_price=11.900`, and `tax_rate=19.00`.

```text
purchase_only:resolved=NULL,sale='11.900',purchase='9.000',tax=19.00,effective_purchase='9.000'
margin_only:resolved='11.900',sale='11.900',purchase='8.000',tax=19.00,effective_purchase='8.000'
tax_only:resolved=NULL,sale='11.900',purchase='8.000',tax=7,effective_purchase='8.000'
ht_only:resolved='11.900',sale='11.900',purchase='8.000',tax=19.00,effective_purchase='8.000'
ttc_only:resolved='12.500',sale='12.500',purchase='8.000',tax=19.00,effective_purchase='8.000'
all_blank:resolved=NULL,sale='11.900',purchase='8.000',tax=19.00,effective_purchase='8.000'
margin_zero_purchase:resolved='0.000',sale='0.000',purchase='0.000',tax=19.00,effective_purchase='0.000'
ttc_empty_string:resolved=NULL,sale='11.900',purchase='8.000',tax=19.00,effective_purchase='8.000'
tax_change_margin_only:resolved='10.700',sale='10.700',purchase='8.000',tax=7,effective_purchase='8.000'
```

The extra combinations do not reveal another sparse clear/corruption path:

- An empty TTC cell is normalized as not provided and behaves like a missing key, preserving both stored prices.
- Tax plus margin uses the incoming tax and stored purchase price, producing `10.700`; purchase remains unchanged.
- Explicit `purchase_price=0.000` plus explicit margin produces `0.000`. Both governing inputs are non-blank, and the current Products rules explicitly accept zero (`ImportType.php:206-210`), so this is the selected formula output rather than loss caused by a missing sparse input.

## Derived-field governing census

| derived target | governing source cells | result |
|---|---|---|
| `sale_price` | `sale_price`, `sale_price_incl_tax`, `sale_price_excl_tax`, `purchase_price`, `margin` | Exact `ProductPriceResolver::resolve()` row read-set. The separately supplied effective tax rate affects HT/margin candidates only; tax-only creates no candidate and preserves canonical stored TTC. Unsupported `margin_percent` was removed. |
| `tax_rate`, `default_tax_configuration_id` | `tax_rate`, `category_name` | Matches the explicit file rate or category/company tax derivation path. |
| `category_id` | `category_name` | Matches category resolution. |
| `brand_id`, `brand_source` | `brand` | Matches brand resolution. |
| `unit_id` | `unit` | Matches unit resolution. |

`purchase_price` is a direct imported attribute, not a derived merger target. `margin` is an import-only input to the sale-price resolver and is not persisted as a separate product field. All other merger fields use their direct source cell.

The original HAR-200 end-to-end method genuinely creates an existing product, backdates `updated_at`, posts `/api/v1/imports` with `duplicate_policy=override` and `price_authority=ht`, then posts `/execute`. It asserts `sale_price=4.462`, unchanged `purchase_price=2.000`, an advanced `updated_at`, exact `_provided`, and row outcome `imported` (`ProductsImportPipelineTest.php:215-265`).

## Per-fix status

| fix | status | evidence |
|---|---|---|
| FIX 1 — sparse derived prices / G4-R8-01 | **PASS — FIXED** | Candidate write is non-null-gated; effective input coalescing supplies stored cost/tax without recycling stored TTC; purchase-only, margin-only, tax-only, HT-only, TTC-only and all-blank paths pass through the real HTTP execute flow on both databases. `CoalescingMergeTest` also pins null-derived merge preservation. Full `ProductsRoundTripTest` and `ProductsImportPipelineTest` pass on both databases, covering unchanged create-path behavior. |
| FIX 2 — completion result tiles | **PASS — unchanged** | Imported/skipped/failed counts remain separate at `ImportWizardPage.tsx:357-362,1293-1320`; exact testids are present; failed excludes skipped; skip-only uses `wizard.complete.noChanges`. `ImportController::formatJob()` exposes outcome-derived `skipped_rows` (`ImportController.php:942-955`) and the local wire type includes it (`apps/web/src/features/import/types.ts:49-67`). The 0/5/0 Vitest remains green. New keys exist in en/fr/ar, all JSON parses, Arabic copy is direction-neutral, and changed UI colors use `colorTokens`. |
| FIX 3 — name-only in-file census | **PASS — unchanged** | `DuplicateCensusService::placementKey()` mirrors `ProductResolver` identity order: trimmed supplied SKU, then trimmed barcode, then lowercased/trimmed name only when both are blank (`DuplicateCensusService.php:321-345`; `ProductResolver.php:63-137`). The actual key-method probe printed `blank_name_equal=yes` and `same_name_distinct_sku=yes`; two blank-key Fresh Bread rows census as `in_file=1`, while same-name `SKU-A` / `SKU-B` remain distinct `new/new`. |

## Fresh outputs

| command / leg | result |
|---|---|
| SQLite, six requested paths serially: `CoalescingMergeTest`, `DuplicateCensusTest`, `ProductIdentityResolutionTest`, `ImportOutcomeAtomicityTest`, `ProductsRoundTripTest`, full `ProductsImportPipelineTest` | **PASS — 65 tests, 509 assertions** |
| PostgreSQL, same six paths serially with the required G2 database prefix | **PASS — 65 tests, 509 assertions** |
| `./vendor/bin/pint --test --format=json` on all eight touched PHP source/test files | **PASS — `{"result":"pass"}`** |
| PHPStan on all eight touched PHP source/test files | **PASS — `[OK] No errors`** |
| `php tools/feature-lane-manifest-check.php` | **PASS — 1,497 Feature classes / 74 groups; parked execution-gate ceiling exactly 1,235 classes.** No class was added, so no arithmetic or CI allowlist change is due. |
| `CACHE_STORE=array php artisan typescript:transform --dry` | This installed transformer has no `--dry` option and exits with `The "--dry" option does not exist.` The prompt-permitted fallback below was used. |
| `git diff --exit-code f878a248c -- packages/shared/types` | **PASS — no generated-type drift in the lane diff.** No DTO or generated declaration changed. |
| `jq empty` on en/fr/ar import locales | **PASS** |
| Hardcoded Tailwind color scan on the changed page | **PASS — no matches** |
| `pnpm vitest run src/features/import` | **PASS — 12 files, 68 tests.** Existing React `act()`, expected test-console/routing, and Node local-storage warnings remain. |
| `pnpm typecheck` | **PASS — exit 0** |
| ESLint on the two touched TSX files | **PASS — 0 errors, 20 warnings** |
| `node tools/audit-tanstack-keys.mjs` | **PASS — 0 new, 0 stale, 0 acknowledged** |
| `git diff --check f878a248c` | **PASS** |
| Artifact scan | **PASS — no `apps/api/autoerp_test_*`** |

## Regression sweep

`git diff f878a248c` contains exactly 13 modified tracked files: the five backend service/contract files required for effective price resolution and the duplicate census, three backend Feature test files, the wizard page and its duplicate-policy test, and en/fr/ar import locales. The delta is 429 insertions and 12 deletions. There is no change beyond FIX 1/2/3, their tests, and i18n; `packages/shared/types` is unchanged. No Feature class was added.

## VERDICT: PASS

G4-R8-01 is fixed. No sparse input tested clears or corrupts an existing sale or purchase price unintentionally, FIX 2 and FIX 3 remain intact, all required serial SQLite/PostgreSQL and frontend/static gates pass, and the base-diff sweep is confined to the approved three-fix scope.
