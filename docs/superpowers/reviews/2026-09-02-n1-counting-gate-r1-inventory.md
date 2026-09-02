# N-1 (API) — adversarial gate r1 — inventory-costing-reviewer

- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n-inventory-mobile`
- Branch `test/N-inventory-mobile`, base `dev` `3615cab8f`
- Scope reviewed: `git diff dev..HEAD -- apps/api` = commits `6e47bde17` (A-1) and `a143f4bad` (A-3 + A-11). `a43f70f04` is web-only and out of scope.

## VERDICT: MERGE-WITH-CONDITIONS

The three API changes do what the commits claim; I verified each against the code and empirically. Nothing in the diff moves stock, touches WAC, or changes a movement sign. But the **batch draft path — the actual offline-mobile path this lane exists to harden — got the weaker half of the A-3 fix**, and the new hard 422 introduces a sync poison-pill and strands pre-existing drafts. Conditions C1–C3 below before merge.

## Verification runs

```
./vendor/bin/phpunit tests/Feature/Inventory/SubmitCountQuantityScaleTest.php \
  tests/Feature/Inventory/ActivateDraftCountingTest.php \
  tests/Feature/Inventory/BlindCountingTest.php \
  tests/Feature/Inventory/CountingSubmitCountRaceTest.php
=> OK (30 tests, 103 assertions)

# adjacent regression sweep (not requested, run anyway)
./vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php \
  tests/Feature/Inventory/ReconciliationTest.php tests/Feature/Inventory/CountTimestampSkewTest.php
=> OK (59 tests, 256 assertions; 7 pre-existing PHPUnit deprecations)

./vendor/bin/phpstan analyse <5 changed app/ files> --memory-limit=1G  => [OK] No errors
./vendor/bin/pint --test <7 changed files>                             => {"result":"pass"}
```

`phpstan.neon:7` sets `paths: - app/` only, so the one PHPStan error I found in the new test (MINOR-1) does not fail CI.

## Claim-by-claim verification

### A-1 — scale-4 ceiling on `SubmitCountRequest::quantity` — VERIFIED

- `apps/api/app/Modules/Inventory/Presentation/Requests/SubmitCountRequest.php:39` — `['bail','required','numeric','regex:/^-?\d+(\.\d{1,4})?$/','min:0']`, byte-identical to the sibling `ManualOverrideRequest.php:36`. `messages()` at :55-60 adds the regex message.
- Regex allows a leading minus, `min:0` refuses it — matches the sibling. Verified empirically against a live validator: `'-5'` and `-5` both fail with *"must be at least 0"*, not a format error.
- Shapes that now pass / fail (measured, not assumed):
  `'12.5' PASS · 12.5 PASS · 6 PASS · 6.75 PASS · '12' PASS · '12.0000' PASS · '0'/0 PASS · 1000.0 PASS · '0.0001' PASS`
  `'1e3' FAIL(format) · '1E3' FAIL · '12.99999' FAIL · '0.00001' FAIL · '.5' FAIL · '5.' FAIL · '+5' FAIL · 1e21 FAIL(format) · ''/null FAIL(required)`
  So a JSON int/float payload (`6`, `6.5`) still passes — Laravel's Regex rule accepts non-strings via `is_numeric` and casts to string.
- `quantity()` at :102-108 still returns a `numeric-string` at scale 4 (`bcadd($raw,'0',4)`), and it is now unreachable by any value bcmath cannot parse (every E-notation form fails the regex first, and `bail` stops before `min`).
- **No other caller can break.** `SubmitCountRequest` has exactly one consumer — `CountingItemController.php:85` behind `app/Modules/Inventory/Presentation/routes.php:295`. There is no batch count-submission endpoint and no second writer of `count_N_qty` (only `InventoryCountingService::submitCount` :730 → `InventoryCountingItem::submitCount` :274). The web client has **no** submit-count caller at all (`apps/web/src/features/inventory-counting/api/countingApi.ts` only exposes `manualOverride` at :100 and `setOpeningCost` at :113) — this endpoint is mobile-only, so there is no shipped consumer to break.

### A-3 — `product_location` requires `scope_filters.location_id` — VERIFIED, with a gap

- (a) `InventoryCountingController.php:979-986` — bare-string 422 guard, inside the existing `Product|ProductLocation` branch, before the `count_1_user_id` check. Refused activation leaves status Draft (test asserts it).
- (b) `CreateDraftCountingRequest.php:74-79` — `required_if:scope_type,product_location` + `nullable` + `string` + `ScopedExists::company('locations', $company->id)`; `ScopedExists::company` (`app/Shared/Presentation/Validation/ScopedExists.php:47-54`) is `Rule::exists('locations','id')->where('company_id',$companyId)` — company boundary confirmed.
- (c) `InventoryCountingController.php:1047` — `'drafts.*.scopeFilters.location_id' => 'required_if:drafts.*.scopeType,product_location|nullable|string'`.
  **Wildcard binding proven empirically** (live Laravel validator, not from memory):
  `idx0 pl missing loc -> FAIL [drafts.0.scopeFilters.location_id]`; `idx1 of 2 missing (idx0 ok) -> FAIL [drafts.1...]`; `idx0 location-scope + idx1 pl missing -> FAIL [drafts.1...] only`; `scopeFilters key absent entirely -> FAIL`; `location_id "" -> FAIL`; `location_id null -> FAIL` (the trailing `nullable` does **not** neutralise the implicit `required_if`); `location scope with no loc -> PASS`.
- The defect narrative holds. `InventoryCountingService.php:525-527` applies the location filter only `if (isset($filters['location_id']))`, and `resolveIncludesZeroStock` (:202-204) returns false for `product_location`, so `getStockLevelsForScope` is genuinely the path taken. `CountingBlockService.php:158` — `ProductLocation => ($filters['location_id'] ?? null) === $locationId` — a null matches no location, so a location-less product_location count never blocks sales anywhere. `InventoryCountingService.php:1400` also emits `InventoryCountingCompleted(locationId: '')` for such a counting.
- Other scopes unaffected: `product` / `location` / `category` / `full_inventory` / `zone` branches of `getStockLevelsForScope` (:530-556) untouched; the only service change in this commit is a comment block (:516-520). `UpdateDraftCountingRequest.php:39-54` never touches `scope_filters`, so an already-set location cannot be removed by an update.

### A-11 — `last_modified_at` cast + ISO-8601 — VERIFIED

- Column type: `database/migrations/tenant/2025_12_16_120000_add_mobile_initiation_to_inventory_countings.php:28` — `timestampTz('last_modified_at')->nullable()`. Same type as `activated_at`/`finalized_at` (`2025_12_02_070000_create_inventory_countings_table.php:55-56`) which have carried a `datetime` cast all along — so the cast is precedent-consistent, not novel.
- Cast added at `InventoryCounting.php:114`; docblock `@property Carbon|null` at :44.
- Emission: `InventoryCountingController.php:735` — `?->toIso8601String()`. All **seven** writers now assign `now()`: :699, :798, :857, :925, :1080, :1269, :1381 (grep-confirmed; count matches the claim).
- Nothing else reads it as a raw string: repo-wide grep finds only `:723` (`orderBy`, unaffected), the model, and the two new tests. **No in-repo consumer exists** — `apps/web/src/features/inventory-counting/types.ts:94` declares `last_modified_at: string | null` but nothing renders or parses it, and there is no `apps/mobile` in this repository (`apps/` = `api`, `pos`, `web`). So the "silent contract break" is forward-looking, not observed. See MINOR-6 for the device-side condition that follows.

### Tests — real data-meaning, one weak spot

Good (assert stored values / generated rows, not status codes):
- `SubmitCountQuantityScaleTest.php:150-153` and `:166-170` assert `count_1_qty` is still **null** after the 422 — i.e. "refused, not truncated to 12.9999", the actual requirement.
- `ActivateDraftCountingTest.php:287-296` asserts status stayed Draft **and** `items()->count() === 0`.
- `:336-341` asserts every generated item's `location_id` equals the scoped warehouse, with a real second location seeded and stocked at `:301-321` (satisfies the second-**location** limb of convention 09).
- `:404-408` asserts `InventoryCounting::count() === 0` after the batch 422 — data meaning, not just the code.
- Both would genuinely have been red before the fix (`'1e3'` → bcadd ValueError → 500 ≠ 422; `'12.99999'` → stored `12.9999` ≠ null).

Weak spots are MINOR-2/3/4 below. No vacuously-passing test, no mock of the thing under test, `RefreshDatabase` + real models + `RolesAndPermissionsSeeder` throughout.

### House rules

Rule 19: no float touches quantity — `bcadd` only, no `(float)`, no `number_format`; the ceiling regex matches the mandated `…{1,4}` for quantity. Rule 13: `CreateDraftCountingRequest` constructor-injects `CompanyContext` as `private readonly` (:23-27); no `app()` added. Rule 3/9: `declare(strict_types=1)` everywhere, `CountingScopeType`/`CountingStatus` enums used in the new guards, no `mixed` introduced. Rule 22: see IMPORTANT-3 (second-company limb missing). No schema change, so no `TenantOnlyUniqueOnCatalogueTablesRatchetTest` exposure. No new noun → no glossary entry owed.

---

## Findings

### BLOCKER
None.

### IMPORTANT

**IMPORTANT-1 — the batch (offline-sync) path accepts an unvalidated, cross-company `location_id`; the sibling path does not.**
`app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:1047` — `'required_if:drafts.*.scopeType,product_location|nullable|string'`, with **no** `ScopedExists::company('locations', …)`, while the single-draft twin `app/Modules/Inventory/Presentation/Requests/CreateDraftCountingRequest.php:74-79` does scope it. `$companyId` is already in hand at `:1027`.
Failure scenario: a device that went offline before a location was deleted (or with a stale/foreign UUID, or literally `"undefined"`) syncs a `product_location` draft. It passes validation, persists verbatim at `:1074`, sails through the new `empty()` guard at `:982`, and `getStockLevelsForScope` (`InventoryCountingService.php:525-527`) filters `forCompany($companyId)->where('location_id', <foreign/garbage>)` → **zero** stock rows → the counting activates with **zero items**, assignments stamped `total_items = 0`, no error anywhere. That is the same silent-wrong outcome A-3 claims to close, just reshaped. (No cross-company data leak — `forCompany()` at `:509` holds — which is why this is IMPORTANT and not a BLOCKER.)
Fix: `'drafts.*.scopeFilters.location_id' => ['required_if:drafts.*.scopeType,product_location','nullable','string', ScopedExists::company('locations', $companyId)]`.

**IMPORTANT-2 — the new rule turns one malformed offline draft into a permanent poison pill for the whole batch.**
`InventoryCountingController.php:1032-1050`: `$request->validate()` runs before the loop, so a single bad draft 422s all 50 — yet this endpoint's own response contract advertises a **per-row** error channel (`errors[]` built at `:1091-1094`, returned at `:1099-1100`), and before this diff every draft in a batch was persisted regardless.
Failure scenario: a device holds one legacy/hand-built `product_location` draft with no `location_id`. Every sync attempt now 422s the entire payload; the other 49 drafts never reach the server, the client retries the identical payload forever, and the operator sees no per-draft explanation. The brief itself says mobile "treats a thrown batch as retryable per draft" — that retry never converges.
This is a behavioural regression against the endpoint's advertised shape, so it needs a ruling, not silence: either move the location check into the per-draft loop and emit it into `errors[]` (keeping the 201 + partial success shape), or document in the mobile brief that a 422'd batch must be split and the offending draft quarantined locally. Do not ship the mobile client against the current shape without that decision recorded.

**IMPORTANT-3 — no second-company test for the new company-scoped rule (convention 09, second-of-everything).**
`CreateDraftCountingRequest.php:78` adds `ScopedExists::company('locations', …)` and **nothing tests it.** The isolation suite covers the neighbouring rules only — `tests/Feature/Inventory/InventoryTenantIsolationTest.php:305-317` (cross-tenant `product_ids`) and `:319+` (cross-tenant counter user) — but no case sends company B's location id to company A's draft endpoint. The lane touches a catalogue entity (`locations`), and the second-**location** limb is covered (`ActivateDraftCountingTest.php:301-321`) while the second-**company** limb is not.
Fix: add to `InventoryTenantIsolationTest` a `product_location` draft from company A carrying company B's location id, asserting 422 on `scope_filters.location_id`.

**IMPORTANT-4 — zone drafts keep the identical hole that was just closed for `product_location`, and nothing refuses a zero-item activation.**
`InventoryCountingController.php:987-994` — the zone branch checks `zone_ids` only. `CreateDraftCountingRequest` has no zone rule at all. But `CreateCountingRequest.php:138-141` has always required `scope_filters.location_id` for zone, and `InventoryCountingService::zoneItemSeeds` returns `[]` when `location_id` is null/empty (`:326-330`).
Failure scenario: a zone draft created via `POST /countings/drafts` with `zone_ids` but no `location_id` activates cleanly into a counting with **zero items** — activation never checks the generated item count (`InventoryCountingService.php:651` calls `generateCountingItems`, `:659` transitions, with no count assertion in between). The operator gets a live "count" with nothing to count and a `COUNTING_ACTIVATED` event recording `items_count: 0` (`:670-678`).
Fix (either or both): mirror the location requirement for zone scope in `activateDraft` + `CreateDraftCountingRequest`, and/or refuse activation when `generateCountingItems` produced zero items.

**IMPORTANT-5 — `drafts.*.scopeType` has no enum rule; an unknown value 500s and rolls back the entire batch.**
`InventoryCountingController.php:1036` — `'drafts.*.scopeType' => 'required|string'`. At `:1067` the value is assigned to an enum-cast attribute (`InventoryCounting.php:101`). **Verified empirically:** `$counting->scope_type = 'productLocation'` throws `ValueError: "productLocation" is not a valid backing value for enum CountingScopeType` — and `ValueError extends Error`, so the `catch (\Exception $e)` at `:1090` does **not** catch it. Result: uncaught throwable → 500, and because the loop runs inside `\DB::transaction()` at `:1053`, every already-persisted draft in the batch is rolled back.
Pre-existing, but it is the same endpoint, the same offline-sync failure mode this lane exists to harden, and a one-line fix: `'drafts.*.scopeType' => ['required', Rule::enum(CountingScopeType::class)]`.

**IMPORTANT-6 — pre-existing location-less `product_location` drafts become permanently un-activatable with no repair path.**
The new guard at `:982` refuses activation, but **no endpoint can set `scope_filters.location_id` on an existing draft**: `scope_filters` is written wholesale only at create time (`:693`, `:1074`); `addProduct` (`:785-797`), `removeProduct` (`:851-856`) and `batchAddProducts` (`:1145`, `:1268`) mutate only `product_ids`; and `UpdateDraftCountingRequest.php:39-54` has no `scope_filters` field.
Failure scenario: any such row already in a tenant DB yields "A location must be selected before activation" forever; the operator's only exit is cancel/delete and re-create. Blast radius cannot be verified from here (no staging DB access) and may well be zero if no mobile build has shipped — but that must be *checked*, not assumed.
Fix: either a one-shot backfill/census of `inventory_countings WHERE scope_type='product_location' AND scope_filters->>'location_id' IS NULL AND status='draft'`, or allow `scope_filters.location_id` in `UpdateDraftCountingRequest` (company-scoped) so the draft can be repaired.

### MINOR

**MINOR-1 — new test assigns a string to the now-Carbon property.**
`tests/Feature/Inventory/ActivateDraftCountingTest.php:456` — `$draft->last_modified_at = now()->toDateTimeString();`. PHPStan level 8 reports `assign.propertyType` ("does not accept string") when the file is analysed. CI is safe today only because `phpstan.neon:7` limits `paths` to `app/`. Use `now()` — which is exactly what all seven production writers now do, so the test would also exercise the real shape.

**MINOR-2 — a test docblock overclaims its coverage.**
`ActivateDraftCountingTest.php:344-348` says the guard leaves "`product` …, `location` and `zone`" unaffected, but `test_activate_draft_leaves_other_scopes_unaffected` (`:362-370`) only runs the helper's default scope — `CountingScopeType::Product` (`:496`). `location` and `zone` are never exercised. Either add the cases or narrow the docblock.

**MINOR-3 — tautological expectation in the happy-path scale test.**
`tests/Feature/Inventory/SubmitCountQuantityScaleTest.php:181-186` computes the expected value with `bcadd((string) $quantity, '0', 4)` — the same call the production `quantity()` makes (`SubmitCountRequest.php:107`). If the normalisation were wrong, the expectation would be wrong identically. Hardcode `'12.5000' / '12.0000' / '12.0000' / '6.7500'`.

**MINOR-4 — the same test file asserts nothing about the 4-dp boundary itself.** `'0.0001'` (the last accepted value) and `'0.00001'` (the first rejected one) are not exercised; the file jumps from `12.5` to `12.99999`. One extra pair would pin the ceiling exactly.

**MINOR-5 — the scale ceiling is exact only for STRING payloads.** For a JSON *number* the regex sees PHP's `(string)` rendering at `precision=14`. Measured: `0.30000000000000004` → `"0.3"` → **accepted**, stored `0.3000`. Harmless here (it rounds toward the intended value, and every E-notation rendering — `1e21`, `0.00001`-as-double — fails the regex, so bcadd can still never 500), but it means the mobile client must send `quantity` as a **string** per rule 19 for the ceiling to be the real ceiling. Worth one line in the mobile brief.

**MINOR-6 — device-side consequence of the ISO-8601 switch (rule 20).** `last_modified_at` now carries a `T` separator. If the mobile client stores it in a SQLite TEXT column and compares lexicographically, `' ' < 'T'` silently excludes rows — the exact 2026-06-12 POS-reports trap. Route it through the device equivalent of `apps/pos/src/lib/db/sqliteTime.ts` `toSqliteUtc()`. Cannot verify from this repo (no `apps/mobile` here).

**MINOR-7 — cosmetics.** `SubmitCountRequest.php:10` imports `Illuminate\Validation\Rule` and never uses it (pre-existing). The new 422 strings at `:984` and `SubmitCountRequest.php:58` are hardcoded English, consistent with their siblings.

**MINOR-8 — two validation surfaces for one concept (convention 11).** Draft creation is validated by a FormRequest (`CreateDraftCountingRequest`) *and* by inline `$request->validate()` rules in the controller (`:1032-1050`), and this diff had to patch both — divergently (IMPORTANT-1). Extracting a `BatchCreateDraftCountingsRequest` that reuses the same rule fragments would make the next such fix single-surface.

### Not a finding (checked, clean)
- No stock movement, WAC arithmetic, batch/FEFO or lock-order code is touched by this diff.
- `getStockLevelsForScope` change is comment-only (`InventoryCountingService.php:516-520`).
- No scale-resolver call added, so no queue/console `getScale()`-with-no-args exposure.
- No new unique key / migration → no second-of-everything ratchet exposure.
- The SQLite-only suite does not mask anything here: no aggregate/`SUM` logic in the diff; the one PG-shaped concern (timestampTz round-trip) is already exercised in production by the sibling `activated_at`/`finalized_at` casts.

## Conditions before merge
1. **C1 (IMPORTANT-1)** — company-scope `drafts.*.scopeFilters.location_id` with `ScopedExists::company('locations', $companyId)`.
2. **C2 (IMPORTANT-2)** — rule on whole-batch-422 vs per-row `errors[]`, and record it in the mobile brief before the client is written.
3. **C3 (IMPORTANT-3)** — add the cross-company location test to `InventoryTenantIsolationTest`.
4. **C4** — IMPORTANT-4/5/6 may land in this lane or a tracked follow-up, but IMPORTANT-6 needs a *census* answer (are there location-less product_location drafts in any tenant DB?) before promotion, not after.
