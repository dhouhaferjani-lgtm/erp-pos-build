# Lane G-4 duplicate-policy gate r8 — browser defects

Review target: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g4-duplicates`, branch `feat/g4-duplicate-policy-merge`, dirty fix-round-6 overlay on `f878a248c`. Source was reviewed read-only. This register is the only intentional workspace write. The running servers on `:8011` and `:5174` were not touched. PostgreSQL legs ran serially with `DB_DATABASE=autoerp_test_g2 DB_CENTRAL_DATABASE=autoerp_test_g2 DB_CONNECTION=pgsql`; no `apps/api/autoerp_test_*` file was created.

## Findings

| ID | severity | file:line | finding | change |
|---|---|---|---|---|
| G4-R8-01 | **HIGH** | `apps/api/app/Modules/Import/Services/ProductPriceResolver.php:42-58`; `apps/api/app/Modules/Import/Services/ImportService.php:887-893`; `apps/api/app/Modules/Product/Application/Services/ProductService.php:123-124,192-210`; `apps/api/app/Modules/Import/Services/CoalescingAttributeMerger.php:43-52` | FIX 1 still loses a valid existing sale price for sparse cost/margin updates. The resolver receives only the sparse incoming row. For `{purchase_price: 9.000}` it returns `sale_price=null`; for `{margin: 25}` it cannot see the existing `purchase_price=8.000`, emits no candidate, and also returns `null`. `ProductService` nevertheless places `sale_price=null` in the incoming attributes, and `governingCellWasProvided()` treats either `purchase_price` or `margin` as sufficient authority, so the merger overwrites the stored `sale_price=11.900` with `null`. The read-only probe against the actual classes printed `purchase_only:resolved=NULL,sale=NULL,purchase='9.000'` and `margin_only:resolved=NULL,sale=NULL,purchase='8.000'`. The governing set is also not the exact resolver read-set: it includes `margin_percent`, which is absent from `ImportType::Products` and is never read by `ProductPriceResolver`. The current coalescing tests inject a precomputed non-null sale price and therefore cannot catch this execute-path loss. | Add failing execute-path override regressions for purchase-price-only and margin-only updates (existing product/cost/sale price), plus the tax-only preservation case. Resolve price from the effective inputs needed by the selected candidate: a margin-only row must be able to use the stored purchase price, while purchase-price-only must not manufacture or clear a sale candidate. Gate the derived `sale_price` write on a successful resolver result rather than on any member of an over-broad union, and remove unsupported `margin_percent` unless it becomes a real normalized import field read by the resolver. |

## Per-fix status

| fix | status | evidence |
|---|---|---|
| FIX 1 — governing cells and HAR-200 execute path | **CHANGES** | HT-only and TTC-only are handled: the resolver produces a value and the merger writes it while a blank purchase-price cell remains absent from `_provided`. Tax-rate-only is also safe for the stored TTC sale price: the probe printed `tax_only:resolved=NULL,sale='11.900',purchase='8.000'`. `tax_rate`/`default_tax_configuration_id` are governed by `tax_rate` and `category_name`; `category_id` by `category_name`; brand fields by `brand`; and `unit_id` by `unit`, matching their derivations. There is no persisted imported margin field or `cost_price` merger arm; `purchase_price` is the direct cost input. The new Feature method genuinely creates an existing HAR-200 product, posts create then `/execute` with `duplicate_policy=override`, and asserts `sale_price=4.462`, `purchase_price=2.000`, `updated_at` after the backdated value, exact `_provided`, and outcome `imported` (`ProductsImportPipelineTest.php:215-266`). It passes on SQLite and PostgreSQL. G4-R8-01 remains open for purchase-only and margin-only sparse rows. |
| FIX 2 — completion result tiles | **PASS** | Three tiles and exact testids are present (`ImportWizardPage.tsx:1293-1320`). Imported is `imported_count ?? successful_rows`, skipped is `skipped_count ?? skipped_rows`, and failed is `execution_error_count ?? failed_rows` (`:357-362`); skipped is no longer added to failed. `ImportController::formatJob()` exposes outcome-derived `skipped_rows` (`ImportController.php:942-955`), and the local `ImportJob` wire interface includes it (`apps/web/src/features/import/types.ts:49-67`). The read-only transformer comparison produced 547 types and matched `packages/shared/types/generated.d.ts` byte-for-byte. The skip-only Vitest drives execute success and asserts `0 / 5 / 0` through all three testids plus the neutral `noChanges` message (`ImportWizardPage.duplicates.test.tsx:191-246`). `noChanges` and `skipped` exist in en/fr/ar; all three JSON files parse. The Arabic copy contains no directional markup/punctuation hazard, the grid is direction-neutral, and every changed color uses `colorTokens` rather than hardcoded Tailwind colors. |
| FIX 3 — no-location in-file census | **PASS** | `placementKey()` now mirrors `ProductResolver::resolveInput()`: supplied SKU, then barcode, then `mb_strtolower(trim(name))` only when both identifiers are blank (`DuplicateCensusService.php:321-347`; `ProductResolver.php:63-137`). The added test pins two blank-SKU/blank-barcode normalized Fresh Bread rows as one `in_file` plus one `new` (`DuplicateCensusTest.php:105-121`). A read-only call of the actual private key method printed `blank_name_equal=yes` and `same_name_distinct_sku=yes`; therefore same-name rows carrying `SKU-A` and `SKU-B` remain distinct `new/new`. There is no direct committed regression method for the latter combination, but the reviewed behavior itself is correct. |

## Fresh outputs

| command / leg | result |
|---|---|
| SQLite, six requested paths serially: `CoalescingMergeTest`, `DuplicateCensusTest`, `ProductIdentityResolutionTest`, `ImportOutcomeAtomicityTest`, `ProductsRoundTripTest`, filtered HAR-200 Feature method | **PASS — 32 tests, 218 assertions** |
| PostgreSQL, same six requested paths serially with the required G2 database prefix | **PASS — 32 tests, 218 assertions** |
| `./vendor/bin/pint --test` on the five touched PHP files | **PASS — `{"result":"pass"}`** |
| PHPStan on the five touched PHP files | **PASS — 0 errors** |
| `php tools/feature-lane-manifest-check.php` | **PASS — 1,497 Feature classes / 74 groups; parked execution-gate ceiling exactly 1,235 classes**. No Feature class was added, so no arithmetic or CI allowlist change is due. |
| Read-only in-memory equivalent of `CACHE_STORE=array php artisan typescript:transform --dry` | **PASS — 547 types; generated and committed SHA-256 both `d600ba2cd36ca68924506bf7a1b0acfd6fd805a88d23c8d6ec9d439e874cd86b`** |
| `cd apps/web && pnpm vitest run src/features/import` | **PASS — 12 files, 68 tests**; existing React `act()`/test-console warnings remain. |
| `cd apps/web && pnpm typecheck` | **PASS** |
| ESLint on the two touched TSX files | **PASS — 0 errors, 20 warnings** |
| `node tools/audit-tanstack-keys.mjs` | **PASS — 0 new, 0 stale, 0 acknowledged** |
| en/fr/ar `jq empty` validation and hardcoded-color grep on the changed page | **PASS** |
| React Doctor diff scan | **INCONCLUSIVE** — `react-doctor@0.9.12` produced no scan output through three bounded 30-second windows and was terminated with exit 130. No success is claimed from this optional scan; the required Vitest/typecheck/ESLint checks above completed independently. |
| `git diff --check f878a248c` | **PASS** |
| Artifact/status audit | **PASS** — no `apps/api/autoerp_test_*`; exactly ten tracked dirty files in the three fixes/tests/i18n overlay. The instructed `e2e-local/`, local Vite config, and local test-results paths remain ignored and untouched. |

## Regression sweep

`git diff f878a248c` contains exactly ten expected files: two import services, three Feature test files, the wizard page and its duplicate-policy test, and en/fr/ar import locales. The delta is 226 insertions and 11 deletions. There is no source change outside the three browser fixes, their tests, and their i18n. `TASKS.md` is not present in the supplied worktree; `CLAUDE.md`, import documentation, i18n guidance, and frontend type conventions were reviewed.

## VERDICT: CHANGES

FIX 2 and FIX 3 pass. FIX 1 closes the observed HT/TTC browser defect and its HAR-200 regression, but G4-R8-01 is a remaining data-loss path: purchase-price-only and margin-only override imports can clear an existing sale price. Do not merge until that root cause is fixed and execute-path regressions cover purchase-only, margin-only-with-existing-cost, and tax-only-with-no-price-cell behavior on SQLite and PostgreSQL.
