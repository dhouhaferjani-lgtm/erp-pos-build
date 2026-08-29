# Gate r1 — Lane G-7 "Import coverage baseline" (test-only)

- **Reviewer:** imports-reviewer (adversarial, code-grounded)
- **Date:** 2026-08-29
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g7-coverage`
- **Branch:** `feat/g7-import-coverage-baseline` · base `a33b01354` · tree DIRTY (uncommitted, as instructed)
- **Brief:** `docs/sessions/session-G-imports-hardening-2026-08-29/briefs/LANE-G7-coverage-baseline-BRIEF.md`
- **Spec:** `docs/superpowers/specs/2026-08-29-imports-hardening-design.md` §9.2 G-7, §7.3a, §5.4, §12.1
- **Audit input:** `docs/sessions/session-G-imports-hardening-2026-08-29/audit/E-templates-locale-and-type-coverage.md` PART 2
- **Implementer summary:** `/private/tmp/claude-501/.../scratchpad/lane-g7-summary.md`

## VERDICT: **CHANGES** (3 MAJOR, 6 MINOR, 5 NOTE — 0 BLOCKER, 0 production files touched)

The lane is substantially correct: five real HTTP round-trip classes, persisted-domain assertions,
`bccomp`-only money/quantity, EU+US fixture pairs per numeric type, every gate green, zero production
files in the diff. It is **not** yet the safety net it claims to be: the two red-by-design self-arming
guards are keyed to literals the spec contradicts (M-1), the composite class runs with the module
DISABLED so G-3b will spuriously redden six tests (M-2), and none of the five classes is selected by
any live CI lane (M-3).

---

## 1. Verification commands actually run (from `.worktrees/g7-coverage/apps/api`)

| Command | Result | Claimed in summary |
|---|---|---|
| `./vendor/bin/phpunit tests/Feature/Import/RoundTrip` | **OK** — `Tests: 19, Assertions: 178, Skipped: 4` (exit 0, 20–24 s) | 19/178/4 ✅ match |
| `./vendor/bin/phpunit … RoundTrip --testdox` | 4 skips are exactly the 4 named RED-BY-DESIGN tests (`Over precision money is refused by a regex ceiling`, `Upload/Template/Execute requires composite items module entitlement`) | ✅ match |
| `./vendor/bin/phpstan analyse tests/Feature/Import/RoundTrip` | **`[OK] No errors`** — `phpstan.neon:8 level: 8`, 5 files | ✅ match |
| `./vendor/bin/pint --test tests/Feature/Import/RoundTrip` | **`{"result":"pass"}`** | ✅ match |
| `php tools/feature-lane-manifest-check.php` | **PASS** — "1461 Feature classes in 74 groups … every `--filter` entry anchored"; warns "70 group(s) / **1202** class(es) … PARKED" and "a NEW class in any of these groups is selected by nothing" | ✅ pass, and the warning corroborates M-3 |
| `./vendor/bin/phpunit ProductsImportPipelineTest PartiesImportBalancesTest OpeningBalancesImportBatchTest ProductPlacementImportTest` | **OK** — `Tests: 47, Assertions: 413, PHPUnit Deprecations: 1` (pre-existing) | ✅ match |
| `git status --short` | `M apps/api/tests/feature-lane-manifest.json` + `?? apps/api/tests/Feature/Import/RoundTrip/` — **zero production files, zero existing test files touched** | ✅ Task 4 structurally satisfied |

**Manifest arithmetic verified correct:** `groups.Import.classes` 18 → **23** (5 new classes) and
`gated_ceiling` 1197 → **1202** (`apps/api/tests/feature-lane-manifest.json`); note prepended in the
precedent wording style ("DELIBERATE RAISE 18 -> 23 (2026-08-29, lane G-7 …)"), naming all five
classes and the `composite_items`-had-zero-coverage rationale.

## 2. What was verified GOOD (no change required)

- **Real route pair per class.** Every class's private `runImport()` does `POST /api/v1/imports`
  → `assertCreated()` → `POST /api/v1/imports/{id}/execute` → `assertOk()` + `assertJsonPath('data.failed_rows', 0)`:
  `ProductsRoundTripTest.php:246-254`, `PartiesRoundTripTest.php:189-197`,
  `OpeningBalancesRoundTripTest.php:197-205`, `CompositeItemsRoundTripTest.php:389-397`.
  `ProductImagesZipRoundTripTest.php:108-127` is HTTP-only by design.
- **Persisted rows, not responses.** `Product`/`StockLevel`/`StockMovement` (`ProductsRoundTripTest.php:134-152`),
  `LocationNode`/`ProductPlacement` (`:173-189`), `Partner`/`Document`/`OpeningBalanceBatch`
  (`PartiesRoundTripTest.php:113-140`), `JournalEntry` + lines (`OpeningBalancesRoundTripTest.php:146-160`),
  `composite_items` (`CompositeItemsRoundTripTest.php:137-152`).
- **Parties field coverage is complete and target names are correct.** `PartiesRoundTripTest.php:114-123`
  asserts `name`, `type`, `code`, `email`, `phone`, `vat_number`, `street_address`, `city`,
  `postal_code`, `country` — matching `PartiesRowMapper.php:18-27` exactly (`tax_id`→`vat_number`,
  `address_line1`→`street_address`, `address_city`→`city`, `address_postal_code`→`postal_code`,
  `address_country`→`country`). This closes audit-E gap (b).
- **Rule 19 assertions.** Every money/quantity comparison is `bccomp` at an explicit scale — no
  `assertEquals` on a float anywhere in the 1231 new lines. Quantity at scale 4
  (`ProductsRoundTripTest.php:145,152`), money at scale 3 (`:138-139`, `PartiesRoundTripTest.php:129`,
  `OpeningBalancesRoundTripTest.php:157-160`, `CompositeItemsRoundTripTest.php:143,145`), percent at
  scale 2 (`:147`).
- **EU/US fixtures where claimed.** `;`+decimal-comma vs `,`+decimal-dot pairs exist for products
  (`ProductsRoundTripTest.php:198-213`), parties (`PartiesRoundTripTest.php:148-169`), opening_balances
  (`OpeningBalancesRoundTripTest.php:168-177`), composite_items (`CompositeItemsRoundTripTest.php:297-314`).
  **No thousands separators anywhere** — the brief's hard constraint is respected, so this lane does
  not pre-empt G-8's `1,234` tiebreak decision.
- **The rule-12 entitlement gap IS pinned by a live test.** `CompositeItemsRoundTripTest.php:276-290`
  asserts `hasModule('CompositeItems') === false` via the real `CompanyConfigService`, then uploads AND
  executes AND asserts the persisted `composite_items` row. Confirmed against `config/verticals.php:137`
  (`CompositeItems` is only a *compatible extra* for retail, never a default module) and
  `ImportServiceProvider.php:67` (import group carries `can:imports.manage` but **no** `module:` middleware,
  unlike `Catalog/Presentation/routes.php:33`). This is exactly the pin G-3b must flip. ✅
- **Deviation "ZIP returns 202 not 201" is CORRECT — the brief was wrong.** `ImportController.php:791`
  returns `202` on the `product_images` branch; `ProductImagesZipRoundTripTest.php:113` truthfully
  asserts 202. Do not "fix" this back.
- **Deviation "countries normalise to TN" is real behaviour, not a masked defect.**
  `PartnerService.php:127` documents converting human-readable names ("Tunisie", "United Kingdom") to
  ISO codes; the company under test is `FR`, so `TN` cannot be a company-country fallback.
- **Fixture hygiene.** All five classes use `RefreshDatabase`, real `Tenant`/`Company`/`User`/
  `UserCompanyMembership`/`Location` models, `RolesAndPermissionsSeeder`, and `uniqid()`-suffixed
  tenant slugs; all FKs are model-generated UUIDs (no hand-written IDs). `app()` appears only in
  `setUp` for `PermissionRegistrar`/`CompanyContext`/`ChartOfAccountsService`, matching the
  `ProductsImportPipelineTest` precedent the brief pointed at. `OpeningBalancesRoundTripTest.php:52`
  pins `Carbon::setTestNow` and `:121-125` clears it in `tearDown` — no global-state leak. Tests are
  order-independent (each builds its own tenant).
- **Media-test pointer is machine-checked**, not a comment: `ProductImagesZipRoundTripTest.php:28,33-36`
  imports the real class for the `@see`, so a rename breaks PHPStan. Reciprocal pointer correctly
  deferred (Task 4 forbids editing existing tests).

---

## 3. Findings register

| ID | Severity | file:line | Finding | Required change |
|---|---|---|---|---|
| **M-1** | **MAJOR** | `apps/api/tests/Feature/Import/RoundTrip/CompositeItemsRoundTripTest.php:189-192` | The G-8 red-by-design guard is `in_array('regex:/^-?\d+(\.\d{1,3})?$/', $basePriceRules, true)` — an exact-literal match on the **signed** form. Spec §7.3a prescribes the **unsigned** form for prices (`…design.md:1895` writes `add money /^\d+(\.\d{1,3})?$/` verbatim for `sale_price`; `:1900` gives composite `base_price` "money `{1,3}`"; the signedness sentence below the table reads "prices and quantities unsigned (they already carry `min:0`)"). Repo precedent confirms it: `ImportType.php:194-195` uses `'regex:/^\d+(\.\d{1,3})?$/'` (no `-?`) for `sale_price_incl_tax`/`sale_price_excl_tax`; only `debit`/`credit` (`:230-231`) and `margin` (`:197`) carry `-?`. **When G-8 lands the spec-prescribed rule the guard evaluates false and this test stays silently SKIPPED forever** — the acceptance contract this lane exists to hand G-8 never arms. Same class of brittleness applies to the reflection guards (`:217,:237,:252`), though those are spec-anchored (`…design.md:1686,2145,2544` name `ImportType::requiredModule(): ?string`). | Replace the exact-literal check with a shape-agnostic predicate, e.g. skip only while `array_filter($basePriceRules, fn ($r) => is_string($r) && str_starts_with($r, 'regex:')) === []`. Do the same for `manual_cost` and `tax_rate` (see M-4). Keep the skip text naming G-8. |
| **M-2** | **MAJOR** | `apps/api/tests/Feature/Import/RoundTrip/CompositeItemsRoundTripTest.php:59-61` | `setUp` creates the tenant with `'enabled_extras' => []`, and `config/verticals.php:137` lists `CompositeItems` only as a *compatible extra* for retail — so **the entire class runs with the module DISABLED**. That is correct for the one bypass-pin test (`:276`), but it means that the moment G-3b adds the entitlement, **six further tests plus one extra data set go red for a reason that has nothing to do with what they assert**: `:108` (validation), `:128` ×2 data sets (EU/US round trip), `:155` (category), `:173` (template), `:204` (today-precision). A Wave-0 net that fires six false alarms in Wave 1 invites G-3b's implementer to bulk-edit the net. | Enable the module in `setUp` (`'enabled_extras' => ['CompositeItems']`, or call `setCompositeItemsModuleEnabled(true)` at the end of `setUp`) and have **only** `test_today_disabled_tenant_can_upload_and_execute_composite_items` disable it explicitly before its upload. Then exactly one test flips when G-3b lands. |
| **M-3** | **MAJOR** | `apps/api/tests/feature-lane-manifest.json` (Import group) vs `.github/workflows/ci.yml:1082` | The five new classes land in group `Import`, whose lane `feature-lane-data-console/Import` is PARKED behind `vars.SELF_HOSTED_RUNNER_READY`. None of `ProductsRoundTripTest`, `PartiesRoundTripTest`, `OpeningBalancesRoundTripTest`, `CompositeItemsRoundTripTest`, `ProductImagesZipRoundTripTest` was added to the `backend-test-pgsql --filter` allowlist at `ci.yml:1082` — so **all 19 tests execute in no CI lane at all**. The manifest checker says so out loud ("a NEW class in any of these groups is selected by nothing"), and the group's own note records the precedent: the previous raise added `SpreadsheetParserDateCellTest` to that allowlist precisely "because the feature lane is PARKED, so that filter is the only live gate that can run them" (`PartiesImportBalancesTest` and `ResultWorkbookTest` are already in it). A safety net that CI never pulls is not a safety net for the eleven lanes that follow. | Add the five class names to the `--filter` alternation at `ci.yml:1082` (they are PG-safe — see N-3) and say so in the manifest note, mirroring the `SpreadsheetParserDateCellTest` sentence. If the owner rules the CI budget forbids it, record that refusal explicitly in the note so the following lanes know the net is dark. |
| **m-1** | MINOR | summary lines 7-8 vs `CompositeItemsRoundTripTest.php:190,217,237,252` | The brief (line 40-41) specified the red-by-design tests **begin** with an unconditional `$this->markTestSkipped(...)`. The implementer shipped conditional self-arming guards instead — a better design, but the summary does not disclose it, so a reader cannot see that the arming condition is a guessed literal (M-1) / a guessed method name. | Add one line to the summary stating the guard mechanism and the exact literal / method name each guard waits for, so G-8 and G-3b know what they must produce for the net to arm. |
| **m-2** | MINOR | `CompositeItemsRoundTripTest.php:191` | The skip message claims the contract covers "`base_price`/`manual_cost`/`tax_rate`", but the guard inspects only `base_price` and the assertion body (`:194-201`) exercises only `base_price`. `manual_cost` (money `{1,3}`, spec `:1901`) and `tax_rate` (**percent** `{1,2}`, spec `:1902` — percent is NOT currency-scaled) get no acceptance contract. | Either extend the test (or add two siblings) to cover `manual_cost` at 4 decimals and `tax_rate` at 3 decimals, or narrow the skip message to `base_price` and record the other two as an explicit G-8-owned gap. |
| **m-3** | MINOR | `PartiesRoundTripTest.php:125-135`, `numberConventionProvider:148-169` | The HTTP round trip covers **one** of the four opening-balance sign quadrants: customer → AR → `DocumentType::Invoice`, positive amount. No supplier→AP row, no negative→`credit_note` row, and no `type = both` with `opening_balance_customer`/`opening_balance_supplier` (`PartiesRowMapper.php:37-43`). Sign→direction is where this module's worst bug class lives. Mitigated but not covered: `PartiesImportBalancesTest.php:100-102,124,138` exercises customer+, customer−, supplier+ at *service* level, and `tests/Unit/Import/PartiesRowMapperTest.php:114-116` covers `both` at unit level — none through the parse→HTTP seam this lane exists to prove. | Add one extra fixture row per convention: a `supplier` with a negative `opening_balance` (asserting `DocumentType::CreditNote`, `OpeningBatchType::ApOpenItems`, non-negative stored magnitude per `PartiesRowMapper.php:102,111`), and one `both` row using the two explicit columns. |
| **m-4** | MINOR | `PartiesRoundTripTest.php:125`, `OpeningBalancesRoundTripTest.php:146` | Both use `firstOrFail()` with no cardinality assertion, so a **double-post** regression (two `documents`, two `journal_entries` for one row) passes silently — the single most damaging failure mode of any re-runnable import. No test in the lane re-executes a job either. | Add `assertSame(1, Document::query()->where(…)->count())` and the same for `JournalEntry`. (The products class already does this correctly at `ProductsRoundTripTest.php:148,189`.) A re-run/idempotency case is G-Session's dedicated lane, so a pointer in the summary suffices. |
| **m-5** | MINOR | `ProductsRoundTripTest.php:217-227`, `CompositeItemsRoundTripTest.php:360-370` | `numericString(string\|int\|float\|null $value): string` accepts a `float` and returns `(string) $value`. If a money accessor ever regresses to returning a float, the helper **launders it into a passing `bccomp`** — the assertion can no longer detect the rule-19 violation it exists to guard. | Narrow to `assertIsString($value)` (fail on int/float) before the cast, so a float return is a test failure rather than a silent pass. |
| **m-6** | MINOR | `PartiesRoundTripTest.php:137-140`, `OpeningBalancesRoundTripTest.php:141-144` | `assertEqualsCanonicalizing` canonicalises (sorts) both sides, so it is a weak comparison for a 2-key associative array: it would pass with the values attached to the wrong keys. | Use `assertSame(['import_job_id' => $jobId, 'source' => 'unified-import'], $batch->import_file_reference)`. |
| **N-1** | NOTE | `ProductsRoundTripTest.php:201,209` | The products fixtures pin `tax_rate` to `0` (the implementer's disclosed deviation) so the stored `sale_price` equals the supplied `sale_price_excl_tax`. Acceptable for the EU/US byte-identity goal, but it means the round trip never exercises `ProductPriceResolver`'s HT→TTC conversion at a real rate (the company defaults to `19.00`, `:70`), and the `sale_price` column — one of §7.3a's un-ceilinged columns — is never used in any fixture. Not a defect; a disclosed coverage boundary. | Optional: add a third data set with `tax_rate = 19` asserting the resolved TTC via `bccomp`, or record the gap as G-8/G-4-owned. |
| **N-2** | NOTE | `apps/api/phpunit.xml:51` (`QUEUE_CONNECTION=sync`) + every `setUp`'s `app(CompanyContext::class)->setCompanyId(...)` | With `sync`, `POST …/execute` runs `ProcessImportJob` **in-process with `CompanyContext` bound** — a context the real worker does not have (CLAUDE.md rule 20; spec §7.4 "Tests clear `CompanyContext` **only** for the genuinely queued paths"). The existing `OpeningBalancesImportBatchTest.php:411` already has a no-context worker case, so the hole is not new and closing it is not this lane's job. | Record in the summary that the round-trip classes do not exercise the no-CompanyContext worker reality, so G-3b/G-8 do not mistake this net for coverage of that path. |
| **N-3** | NOTE | all five classes | PG-vs-SQLite risk reviewed and found **low**: no aggregates, no `MAX(uuid)`, no raw SQL, no date-string comparisons. `composite_items.base_price`/`manual_cost` are `decimal(15,4)` (`…create_composite_items_table.php:21`, `…add_manual_cost_to_composite_items_table.php:14`), so the `10.1234` pin at `CompositeItemsRoundTripTest.php:212` survives PG's scale enforcement; `tax_rate` is `string(10)` (`:23`); every money/qty comparison is scale-explicit `bccomp`. Not PG-verified by this gate (no PG available in-worktree). | No class needs to be PG-only. This supports adding all five to the `--filter` allowlist (M-3). |
| **N-4** | NOTE | spec `…design.md:2093-2094` vs brief line 41 | Spec §9.2 G-7 states G-7 "**must not** claim entitlement behaviour before G-3b — the composite-items entitlement assertions are G-3b's"; the brief overrode this and ordered the three red-by-design entitlement tests. As shipped this is compliant **in substance** — the three tests are skipped and assert nothing, while the live test (`:276`) pins the *absence* of the gate rather than claiming its presence. | Record the brief-vs-spec divergence in the summary/LEDGER so the spec's G-7 paragraph is not later read as violated. |
| **N-5** | NOTE | `ProductImagesZipRoundTripTest.php:136-137` | `makeZipWithImage()` calls `imagecreatetruecolor`/`imagejpeg`, so the class hard-requires `ext-gd`. Precedent exists (`ProductImageImportServiceMediaTest` does the same), and cleanup is correctly deferred via `beforeApplicationDestroyed` (`:145-158`). | None; note the extension dependency if M-3's allowlist addition goes ahead. |
| **N-6** | NOTE | `apps/api/tests/Feature/Import/RoundTrip/` vs spec `…design.md:2085` | Spec §9.2/§12.1 names a single `ImportTypeHttpRoundTripTest.php` + `tests/Fixtures/Import/`; the lane ships a `RoundTrip/` directory with inlined CSV. Disclosed in the summary, consistent with the brief, and better for the manifest (all five still land in group `Import` via `groupOf()`'s first-segment rule). | Accepted. |

---

## 4. Brief conformance

| Brief item | Status |
|---|---|
| Task 1 — 5 classes, real route pair, persisted rows | ✅ |
| Task 1 #1 — products: sku/name/type, `bccomp` prices, `StockLevel`, one `MovementType::Opening` movement, + placement sub-path | ✅ (`ProductsRoundTripTest.php:134-152`, `:155-190`) |
| Task 1 #2 — parties: every mapped column + AR/AP document + batch, `bccomp` | ⚠️ fields ✅ complete; only the AR/invoice quadrant (m-3) |
| Task 1 #3 — opening_balances: `accounts.manage`, `JournalEntry` + lines `bccomp`, batch status | ✅ (`OpeningBalancesRoundTripTest.php:84,139,146-160`) |
| Task 1 #4 — composite_items | ⚠️ see M-1, M-2, m-2 |
| Task 1 #5 — product_images ZIP, `Queue::fake()`, job + dispatch; do not move the media test | ✅ (`ProductImagesZipRoundTripTest.php:99,113-127`); 202 correction is right (N/A→§2) |
| Manifest ceiling 18→23, gated 1197→1202, note prepended in precedent style, checker green | ✅ arithmetic and format verified |
| Task 2.1 validation / 2.2 execution + enums + category / 2.3 template | ✅ (`:108-122`, `:128-153`, `:155-171`, `:173-185`) |
| Task 2.4 rule-19 red-by-design + today-companion | ⚠️ companion ✅ (`:204-213`); guard broken (M-1) |
| Task 2.5 entitlement red-by-design + today-bypass pin | ✅ bypass pin is correct and load-bearing (`:276-290`); ⚠️ M-2 |
| Task 3 — EU + US fixture per numeric type, no thousands separators, `bccomp` identity | ✅ all four types |
| Task 4 — existing tests untouched and green | ✅ 47/413/1 deprecation; `git status` proves untouched |
| Test-only, zero production files | ✅ |
| Summary file ≤20 lines with red-by-design list + table | ✅ (20 lines) — but see m-1 |

## 5. One-line fix list before merge

Fix **M-1** (make the G-8 guard match any `regex:` rule, not the signed literal the spec contradicts),
**M-2** (enable `CompositeItems` in `setUp`; disable it only inside the bypass-pin test), and **M-3**
(add the five classes to the `ci.yml:1082` pgsql `--filter` allowlist, or record the owner's refusal
in the manifest note); the six MINORs are cheap and should ride along.

## Gate r2 (Codex)

- **Reviewer:** Codex, standing in for `imports-reviewer` (adversarial re-check)
- **Date:** 2026-08-29
- **Worktree reviewed:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g7-coverage`
- **Scope:** read-only review of the dirty test-only lane; the only write was this register append

### r1 finding re-check

- **M-1 — fixed.** `CompositeItemsRoundTripTest.php:198` now reads:
  `static fn (string $rule): bool => str_contains($rule, '(\.\d{1,3})?$'),`
  The signed rule `regex:/^-?\d+(\.\d{1,3})?$/` and unsigned rule
  `regex:/^\d+(\.\d{1,3})?$/` both contain the exact searched fragment
  `(\.\d{1,3})?$`; therefore either G-8 form self-arms the acceptance test.
- **M-2 — fixed.** `setUp()` enables the module with
  `'enabled_extras' => [self::COMPOSITE_ITEMS_MODULE]` (`:63`). Exact call-site count for
  `setCompositeItemsModuleEnabled(false)` is **4**: the upload, template, and execute
  RED-BY-DESIGN tests (`:231`, `:253`, `:274`) plus the live current-behaviour bypass pin (`:291`).
  No ordinary round-trip/validation/template-companion test disables the module.
- **M-3 — fixed.** `.github/workflows/ci.yml:1089` contains all five classes exactly once in the
  PostgreSQL filter. The extracted expression has **164 alternatives / 164 unique**. A `php -r`
  `preg_match` check returned `1` for each new class and `0` for both
  `NotProductsRoundTripTest` and `ProductsRoundTripTestSuffix`, proving the namespace-prefix and
  `::` suffix boundaries reject near-matches. Ruby/Psych parsed the workflow: **YAML syntax PASS**.
- **Applied MINORs preserve or strengthen assertions.** m-1 discloses the self-arming mechanism;
  m-2 narrows the skip text to the actually pinned `base_price` contract and explicitly records
  `manual_cost`/`tax_rate` as G-8-owned gaps; m-4 adds exact cardinality assertions before
  `firstOrFail()` for `Document` and `JournalEntry`; m-5 adds `assertIsString()` to the Products
  persisted-decimal helper; m-6 replaces canonicalizing equality with key-bound `assertEquals`,
  which rejects swapped values while remaining safe for PostgreSQL JSONB key order. The unapplied
  m-3 fixture expansion remains disclosed and does not weaken an existing assertion.
- **CompositeItem defect is documented, not silently masked by a newly weakened test.** Production
  `CompositeItem::casts()` (`CompositeItem.php:103-113`) casts `manual_cost` but omits
  `base_price` and `tax_rate`, contradicting the string docblock and causing SQLite float hydration.
  The unchanged Composite helper is preceded by an explicit defect/filed-for-G-8/G-4 explanation
  (`CompositeItemsRoundTripTest.php:374-386`); its existing value comparisons remain intact. No
  strict assertion was added and then relaxed to manufacture a green gate.

### Fresh command evidence

| Command | Exit | Output |
|---|---:|---|
| `./vendor/bin/phpunit tests/Feature/Import/RoundTrip` | 0 | **19 tests, 186 assertions, 4 skipped** |
| `./vendor/bin/phpstan analyse tests/Feature/Import/RoundTrip` | 0 | **5/5 files; [OK] No errors** |
| `./vendor/bin/pint --test tests/Feature/Import/RoundTrip` | 0 | **`{"result":"pass"}`** |
| `php tools/feature-lane-manifest-check.php` | 0 | **OK — 1461 Feature classes / 74 groups; every filter anchored and uniquely matched against 1861 test classes** (expected parked warning: 70 groups / 1202 classes; coverage debt: 1 group / 1 class) |
| `php artisan test -c phpunit-pgsql.xml --filter='/\\(ProductsRoundTripTest\|PartiesRoundTripTest\|OpeningBalancesRoundTripTest\|CompositeItemsRoundTripTest\|ProductImagesZipRoundTripTest)::/'` | 0 | **15 passed, 4 skipped, 186 assertions** (41.88 s); unrelated pre-existing PHPUnit doc-comment metadata warnings occurred during discovery |
| `php -r` extracted-filter `preg_match` check | 0 | **5/5 target classes matched; 2/2 near-matches rejected; 164/164 alternatives unique** |
| Ruby/Psych parse of `.github/workflows/ci.yml` | 0 | **YAML syntax PASS** |

Final `git status --short` in the reviewed worktree contains **only**:

```text
 M .github/workflows/ci.yml
 M apps/api/tests/feature-lane-manifest.json
?? apps/api/tests/Feature/Import/RoundTrip/
```

## VERDICT: **PASS**

All three r1 MAJOR findings are closed, the applied MINOR changes do not weaken the coverage net,
the pre-existing CompositeItem casting defect is explicitly carried rather than concealed, and all
authorized SQLite, PostgreSQL, static-analysis, formatting, manifest, regex, and YAML checks pass.
