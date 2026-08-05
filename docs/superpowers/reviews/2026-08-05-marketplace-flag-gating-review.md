# Adversarial review — marketplace kill-switch lane (`1d6798a8b`, `5f5e94108`, `11e567aa5`, `accb1962e`)

Reviewer: tenancy-authz-reviewer (adversarial, code-grounded). Date 2026-08-05.
Scope: `apps/api` only. Every claim below was verified against the files/line
numbers cited or by an executed probe; nothing is asserted from memory.

**VERDICT: spec ✅ (the P1 admin surface is genuinely closed) + quality
APPROVE-WITH-FIXES.** No merge blocker. Two Important items should land before or
immediately after merge (CI never runs the new guards; the kill-switch does not
cover the Cart→Marketplace application path), plus two documentation-accuracy
corrections that will otherwise mislead the follow-up work.

---

## 1. The critical claim — VERIFIED TRUE, and sufficient, with one qualification

**Claim:** a condition around `loadRoutesFrom()` is not a route gate, because
Spatie event-sourcing auto-discovery autoloads (and therefore executes) every
file under `app/`, including `Presentation/routes.php`.

Verified mechanically, not just by reading the comment:

- `apps/api/config/event-sourcing.php:21-23` — `auto_discover_projectors_and_reactors => [app()->path()]`;
  `:29` — `auto_discover_base_path => base_path()`.
- `apps/api/vendor/spatie/laravel-event-sourcing/src/EventSourcingServiceProvider.php:105-108`
  runs the discovery on boot; `.../src/Support/DiscoverEventHandlers.php:62-67`
  Finders **every** file, maps it to a PSR-4 name
  (`fullQualifiedClassNameFromFile()`, `:74-85` → `App\Modules\Marketplace\Presentation\routes`)
  and calls `is_subclass_of($class, EventHandler::class)`, which triggers the
  autoloader → Composer `include`s `app/Modules/Marketplace/Presentation/routes.php`.
- **Executed probe** (boot the console kernel, dump `get_included_files()`), flag
  false, provider not calling `loadRoutesFrom`:
  `/app/Modules/Marketplace/Presentation/routes.php` **is included**, and
  `marketplace.listings.index` count = 0 (early return works),
  `catalog-carts.index` = 1, total routes 1037. With `MARKETPLACE_ENABLED=true`:
  total 1049 (+12 = 11 marketplace + 1 cart checkout).

So the early `return` at `apps/api/app/Modules/Marketplace/Presentation/routes.php:36-38`
**is** the load-bearing guard in local/CI, and it is sufficient: it precedes every
`Route::` call in the file, so no boot path can register a route past it.

**Qualification the lane's docs get wrong (see finding I-3):** in the *production
image* the include never happens. `apps/api/Dockerfile:124` runs
`composer dump-autoload --optimize --classmap-authoritative`, and
`apps/api/vendor/composer/ClassLoader.php:442-450` returns `false` from
`findFile()` immediately when `classMapAuthoritative` is set — a routes file
declares no class, so it is not in the classmap and the PSR-4 fallback is
disabled. In production the *provider* condition
(`MarketplaceServiceProvider.php:74-76`) is the gate. Both guards are present, so
the fix is correct in both worlds; only the documentation is over-generalised.

### (a) Other boot paths
- **Octane:** not installed (`grep octane apps/api/composer.json` → no match). N/A.
- **`route:cache`:** production builds the route cache at **container start**, not
  image build — `apps/api/docker/entrypoint.sh:214` `config:cache`, `:219`
  `route:cache`, in that order, after env is present. No `config:cache`/`optimize`
  in the Dockerfile. So the flag is read from runtime env, and a false flag bakes
  route ABSENCE. Fail-safe direction is correct.
- **Duplicate registration when the flag is on:** with a non-authoritative
  autoloader the file executes twice (probe include + `loadRoutesFrom`'s
  `require`, `vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php:198-203`).
  Harmless: `RouteCollection` keys by verb+URI so duplicates collapse — verified
  by compiling the collection with the flag on (`refreshNameLookups()` +
  `compile()` → OK, no `LogicException` from
  `Illuminate/Routing/AbstractRouteCollection.php:257`). `route:cache` will not
  break when the flag is flipped on.

### (b) Same defect latent elsewhere? — NO
`MarketplaceServiceProvider.php:75` is the **only** nested/conditional
`loadRoutesFrom` in `app/` (`grep -rn "^            .*loadRoutesFrom" app/` → 1
hit). All 44 module `routes.php` files resolve to a provider `loadRoutesFrom`
target (script-resolved; the one apparent orphan, `Compliance`, is loaded via
`base_path()` at `app/Modules/Compliance/Providers/ComplianceServiceProvider.php:94`).
Nothing else depends on provider-conditional route loading for security.

### (c) Cache-flip scenarios
- flag false at container start → `route:cache` bakes absence → correct.
- flag flipped true without a container restart → routes stay absent (fail-closed).
- **The one hazard:** a container whose route cache was built while the flag was
  TRUE keeps serving marketplace routes even if the env is later set false, until
  `route:cache` is rebuilt. Runbook line owed (finding m-4).

---

## 2. Findings

### Important

**[I-1] The kill-switch does not cover the Cart → Marketplace application path.**
`apps/api/app/Modules/Cart/Presentation/routes.php:34-36` (`catalog-carts.items.store`,
`can:catalog_cart.create`) stays registered — correct for procurement — but
`apps/api/app/Modules/Cart/Presentation/Controllers/CatalogCartController.php:184`
still accepts `marketplace_listing_id`, and
`apps/api/app/Modules/Cart/Application/Services/CartService.php:86-101` branches on
`CartItemSource::Marketplace` to `MarketplaceListing::findOrFail()` +
`MarketplaceOrderService::reserveForCart()` (creating a stock reservation and
consuming the `marketplace.anti_abuse` counter, `CartService.php:147-148`).
So with the module "dark" an `admin`/`manager` can still drive the Marketplace
application layer and create marketplace reservations, as long as listing rows
exist in that tenant's DB. This contradicts the ticket's "de-registers the whole
Marketplace HTTP surface". *Not* a cross-tenant issue and it does not reopen the
P1 admin surface — hence Important, not Critical.
*Fix:* reject `source=marketplace` (422) in `CartService::addItem()` /
`CatalogCartController` when `config('marketplace.enabled')` is false, and add the
case to `MarketplaceFlagGatingTest`.

**[I-2] The new regression guards never run in CI.** `.github/workflows/ci.yml`
runs exactly: `--testsuite=Unit` (`:274`), two explicit `--filter=...` pgsql gates
(`:560`, `:657` — neither lists `MarketplaceFlag*`), and
`./vendor/bin/phpunit tests/Feature/Treasury|Accounting` + `tests/Unit/Treasury`
(`:777-783`). `ci.yml` is the only workflow that runs tests
(`.github/workflows/` = ci, react-doctor, smoke-test, sonarcloud). Therefore
`tests/Feature/Security/MarketplaceFlagGatingTest.php` and
`MarketplaceFlagEnabledTest.php` are **never executed in CI** — a future change
that re-registers the marketplace surface would not be caught. (`scripts/preflight.sh:75`
runs the full suite locally, so devs do get it.)
*Fix:* add a `./vendor/bin/phpunit tests/Feature/Security` step, or append the two
class names to the pgsql merge-gate `--filter` list.

**[I-3] The "a provider condition is NOT a gate — this applies to every module in
`app/Modules/`" gotcha is false for the production image.** Stated unqualified in
`docs/superpowers/tickets/2026-08-05-marketplace-admin-surface-redesign.md`
("Gotcha for whoever implements this") and in the audit amendment bullet
(`docs/superpowers/audits/2026-06-15-vertical-module-gating-audit/README.md`,
HIGH-A "Implementation gotcha for the eventual CI guard"). Contradicted by
`apps/api/Dockerfile:124` + `apps/api/vendor/composer/ClassLoader.php:442-450`
(classmap-authoritative short-circuits `findFile`, so the discovery probe cannot
include a class-less routes file). Whoever builds the CI guard on this note will
model production wrongly.
*Fix:* qualify — "true under a non-authoritative autoloader (local + CI); the
production image is built `--classmap-authoritative`, where the probe cannot
include the file and the provider condition *is* the gate. Guard both."

**[I-4] The exposure narrative is mode-dependent and, as written, internally
inconsistent.** The commit message and the ticket's Finding section assert "any
tenant admin could create/edit/suspend marketplace sellers **for any tenant**" and
"index() lists all sellers with no tenant predicate". Under the shipped topology
that is not reachable: `marketplace_sellers|listings|orders` are per-tenant
(`apps/api/database/migrations/tenant/2026_03_10_400000..400002`),
`MarketplaceSeller` pins no `$connection` and carries no global tenant scope
(`apps/api/app/Modules/Marketplace/Domain/Models/MarketplaceSeller.php:50-77`), so
every query runs inside the caller's own tenant database — the ticket proves this
itself two sections later ("Why the obvious fix does not work"). The fleet-wide
read/write claim IS true in legacy row-level mode
(`apps/api/config/tenancy_resolver.php:30` — `TENANCY_DB_PER_TENANT` defaults
**false**), which is why the finding is still real, and the residual risk under
db-per-tenant is different: `store()` accepts an arbitrary `tenant_id`/`company_id`
(`MarketplaceSellerController.php:42-50`), so a tenant admin can plant spoofed
attribution rows that would become fleet-visible the moment option 1(a) of the
ticket (promote the registry to central) is executed.
*Fix:* state the mode-dependence in the ticket, and carry the spoofed-attribution
residual into redesign question 1.

### Minor

**[m-1] Readiness register R-02 is stale against the shipped state.**
`docs/superpowers/audits/2026-08-05-production-v1-readiness.md:186` (authored at
HEAD, i.e. after this lane) still says `config/marketplace.php:6` "…and **nothing
reads it**" and gives the remediation as "fix-lane-in-flight — super-admin guard +
wire the flag". The guard swap was ruled out and ticketed; the flag is wired.
Update the row to the delivered disposition and drop the fleet-wide phrasing per I-4.

**[m-2] Env cleanup in the test trait is not exception-safe.**
`apps/api/tests/Traits/EnablesMarketplaceModule.php:31-37` — if
`parent::refreshApplication()` throws, `clearMarketplaceEnabledEnv()` never runs
and `MARKETPLACE_ENABLED=true` leaks to every later class in the process. Wrap in
`try/finally`. *(No leak on the happy path: I ran
`MarketplaceFlagEnabledTest` **before** `MarketplaceFlagGatingTest` in one process
— 12/12 pass, including `test_marketplace_is_disabled_by_default`.)*

**[m-3] Dead annotation.** `apps/api/tests/Traits/EnablesMarketplaceModule.php:22`
`@phpstan-ignore-next-line` — `apps/api/phpstan.neon:6-7` analyses `app/` only, so
the trait is never analysed (and `reportUnmatchedIgnoredErrors: false` at `:11`).

**[m-4] Operational gap.** `MARKETPLACE_ENABLED` appears in no env template
(`.env`, `.env.production.example`) or deploy config — repo-wide grep finds it only
in `config/marketplace.php:6`, the test trait and the new docs. Document it as
"leave unset/false", and note that flipping it requires a `route:cache` rebuild
(entrypoint restart) because `docker/entrypoint.sh:219` bakes the route collection.

**[m-5] No deny-path test in the enabled half.**
`MarketplaceFlagEnabledTest::test_enabled_routes_keep_their_permission_middleware`
asserts middleware *strings* only. Given the actual defect is "every tenant admin
holds `marketplace.admin`" (`apps/web/src/hooks/permissionsMap.generated.ts:128-131`
shows `marketplace.browse` seeded to all five roles, `marketplace.admin` to admin;
source `RolesAndPermissionsSeeder.php:382-392`), a 403-for-a-role-without-the-permission
test is what will pin the seeder change when the redesign lands.

**[m-6] Manually-invocable commands do not consult the flag** — intended and
documented (`MarketplaceServiceProvider.php:50-53`). Assessed blast radius:
`MarketplaceDeltaSyncCommand.php:58-78` fans out per tenant inside tenant context
and `SyncSellerListingsJob` rebinds it (`SyncSellerListingsJob.php:23-45`), writes
stay inside each tenant DB, nothing is published externally. Acceptable; a
warning log ("marketplace is disabled; running by operator request") would be kind.

---

## 3. Items verified clean (no action)

- **(d) Disabled-state tests assert absence, not just 404.**
  `MarketplaceFlagGatingTest.php` asserts `Route::has(...) === false` for all 11
  route names *and* 404s (a registered route would 401 unauthenticated, so the
  404s are not an auth-order artifact), *and* `catalog-carts.marketplace-checkout`
  absent, *and* the 9 other `catalog-carts.*` names still present, *and*
  `schedule:list` free of both commands, *and* both commands still in
  `Artisan::all()`.
- **(e) No trait leakage.** Verified by running Enabled→Gating in one process (see m-2).
- **(f) Schedule gating.** `MarketplaceServiceProvider.php:78-83` registers the
  commands unconditionally, `:85-87` returns before `callAfterResolving(Schedule)`
  — commands invocable, not scheduled. Matches the tests.
- **(g) Cart group intact.** Probe with flag false: `catalog-carts.index` present,
  `catalog-carts.marketplace-checkout` absent; `apps/api/app/Modules/Cart/Presentation/routes.php:10-49`
  untouched.
- **Residual surface with flag off = zero.** Probe enumerating every registered
  route whose URI/name/action mentions "marketplace" → none; Projectionist has no
  Marketplace handler.
- **(h) Audit-count correction is accurate.** `apps/api/app/Enums/ModuleName.php:16-39`
  = exactly **24** cases (the 25th `grep` hit is the doc comment at `:11`).
  `module:` gates in `app/Modules/**/routes.php` cover exactly
  `BatchExpiry, CompositeItems, Ecommerce, Inventory, Loyalty, Menu, Parapharmacy,
  Vehicle, Workshop` = **9**; 15 ungated, of which the 8 core + the 7 named
  vertical-exclusive = 15. ✅ `docs/architecture/vertical-module-gating.md:259-282`
  is a 22-row table with **zero** occurrences of `Merchandising`/`PurchaseBonus`
  (both are real vertical scoping: `config/verticals.php:47,137,167,340,357-360`). ✅
  `Marketplace`/`Procurement` are not enum cases, and
  `tests/Unit/Enums/ModuleNameTest.php:55-65` pins the enum to the union of
  `config/verticals.php`, so adding one is a product decision. ✅
- **(i) No test weakening.** All four diffs are additive apart from the two moved
  route-registration lines; no assertion or suite was removed.
- **No client impact.** Zero references to `api/v1/marketplace` or
  `marketplace-checkout` in `apps/web`, `apps/pos`, `apps/mobile`.
- **Gates green on the touched code:** `phpstan analyse` (level 8) on the three
  changed `app/` files → OK; `pint --test` on Marketplace + Cart routes + the new
  tests/trait → pass; `MarketplaceFlagGatingTest` + `MarketplaceFlagEnabledTest`
  12/12; `MarketplaceListingTest` + `MarketplaceOrderTest` +
  `Cart/MarketplaceCheckoutTest` 13/13; `PriceComparisonTest` green.

## 4. Environment note (NOT this lane)

The working tree is dirty with another session's uncommitted changes, including
`apps/api/app/Console/TenantScopedCommand.php` (+141 lines adding a
`tenantDatabaseExists()` skip). Under sqlite that probe skips every tenant, which
fails
`MarketplaceScheduledCommandsTest::test_for_each_tenant_enters_and_ends_tenant_context_in_db_per_tenant_mode`
(`$seen` empty at `:169`). The file is untouched by all four commits under review
(`git show HEAD:…/TenantScopedCommand.php` contains no `tenantDatabaseExists`), so
this is not a lane regression — but whoever owns that WIP must fix the test before
committing.

## 5. What to fix before merge

Add the two `tests/Feature/Security` classes to a CI job (I-2) and close the Cart
`addItem` marketplace path (I-1); correct the two documentation claims (I-3, I-4).
