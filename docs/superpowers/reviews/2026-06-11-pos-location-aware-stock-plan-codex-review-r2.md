# Confirmation Plan Review r2: POS Location-Aware Stock

**Date:** 2026-06-11  
**Reviewer:** Codex confirmation review, round 2  
**Plan:** `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md`  
**r1 review:** `docs/superpowers/reviews/2026-06-11-pos-location-aware-stock-plan-codex-review.md`

## 1. Applied-Fix Verification

- **CONFIRMED — Migration object shape (not string list).** Plan Task 8 uses an object with `version`, `name`, `sql`, and `async run(db)`: `version: 50`, `name: 'create_location_stock'`, `sql: ''`, `async run(db) { ... }` in `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1029-1033`. It also states: "The real migration-object shape is `{version, name, sql, async run(db)}`" at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1053`.

- **CONFIRMED — `@/lib/decimal` path with explicit scale 4.** Plan Task 10 imports from the expected path and calls with the quantity scale: `import { bcsub, bccomp } from '@/lib/decimal'; ... ALWAYS pass 4 explicitly for quantities` at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1380`, `const QTY_DP = 4;` at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1390`, and `bcsub(server, input.pendingSaleQty, QTY_DP)` / `bcsub(afterPending, input.cartQty, QTY_DP)` at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1397-1398`.

- **CONFIRMED — JSON-blob pending aggregate.** Plan Task 10 states: "`offline_receipts.lines` is a JSON TEXT blob ... so: `SELECT lines FROM offline_receipts WHERE synced = 0` ... then `JSON.parse` each and `bcsum` the matching (product, variant) quantities at scale 4 in TypeScript" at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1403`.

- **CONFIRMED — Mandatory `api.get` (not `apiGet`) for pagination meta.** Plan Task 9 includes a mandatory note: "`apiGet` strips `meta`, so pagination would silently stop after page 1. Use the raw `api.get` form and `return response.data` ... implement with `api.get` and read `meta.pagination.last_page`" at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1226`. Open issue below: the nearby code snippet still shows `apiGet`.

- **CONFIRMED — Console-command backfill replacing in-migration `tenant()`.** Task 1 migration now explicitly says: "NO in-migration backfill" and "Existing-tenant backfill = the console command below" at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:177-181`. Step 8a adds `BackfillPosStockPolicyCommand.php`, signature `pos:stock-policy-backfill {--dry-run}`, and walks the central tenant directory at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:203-213`.

- **CONFIRMED — `pos.operate_terminal` gate + spec wording for `terminal_id`.** Task 5 says `terminal_id` is "client-supplied + company-scoped + permission-gated" and "LOCATION is never client-supplied" at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:775-779`; the controller snippet calls `Gate::authorize('pos.operate_terminal')` at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:780`; it validates `terminal_id` and scopes the terminal by company at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:784-793`; and it passes `locationId: (string) $terminal->location_id` to the reader at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:802-807`.

- **CONFIRMED — PHP+TS fixture pairing.** Task 13 says: "Any fixture change is a cross-language PAIR ... the PHP counterpart under `apps/api/tests/` fiscal fixtures must change in the same commit" at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1648`. Task 14 also says to "add one location-identity SALE_RECEIPT fixture case to the cross-language fixture set" at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1664`.

- **CONFIRMED — Accumulator/as_of notes.** Task 9 declares accumulators outside the page loop and explains why at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1261-1265`, pushes all pages before writing at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1267-1288`, and says: "Persist cursor/table only after ALL pages succeed" at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1297`. Task 5 says "`as_of` is captured BEFORE the read..." and clarifies "advisory-only overlap handling under READ COMMITTED" at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:852`.

## 2. Rejection Re-adjudication

### a. P1-1: `Terminal::company()` allegedly missing

**Finding restated:** r1 said `Terminal` has no direct `company()` relation and plan snippets using `$terminal->company` would fail.

**Code evidence:** `apps/api/app/Modules/POS/Domain/Terminal.php:143-149` contains:

```php
/**
 * @return BelongsTo<Company, $this>
 */
public function company(): BelongsTo
{
    return $this->belongsTo(Company::class);
}
```

**Verdict:** r1 WRONG.

### b. P1-2: `QuantityScale::round(string, int, string)` allegedly missing

**Finding restated:** r1 said `QuantityScale::round()` does not exist and the plan should use `bcformat()`.

**Code evidence:** `apps/api/app/Shared/Domain/QuantityScale.php:23-34` documents and declares:

```php
/**
 * Round a numeric string to $decimalPlaces using bcmath (no float).
 *
 * @param  string  $value
 * @param  int  $decimalPlaces
 * @param  string  $method
 */
public static function round(string $value, int $decimalPlaces, string $method): string
```

**Verdict:** r1 WRONG.

### c. P1-6: Task 1 allegedly adds NOT NULL `location_id`

**Finding restated:** r1 said Task 1 had a `location_id` / `NOT NULL` migration risk.

**Plan evidence:** Task 1 is `PosStockPolicy enum + companies.pos_stock_policy column + backfill` at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:23`. Its migration only adds `companies.pos_stock_policy` at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:171-174`:

```php
$table->string('pos_stock_policy', 10)
    ->default(PosStockPolicy::Block->value)
    ->after('compliance_profile');
```

Search evidence: `rg -n "location_id|NOT NULL|not null|stock_levels|Task 1" docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md` shows no `location_id` / `stock_levels` / NOT NULL item inside Task 1; `location_id` appears later in other tasks, and SQLite `TEXT NOT NULL` appears in Task 8 at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1036-1042`, not Task 1.

**Verdict:** r1 WRONG.

## 3. Blocker Adjudication

**What the code shows:** There is no server-side terminal-session mechanism in the checked POS route/auth flow. POS routes are mounted under `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` in `apps/api/app/Modules/POS/routes.php:33`; there is no terminal-session middleware in that group. Middleware aliases in `apps/api/bootstrap/app.php:45-51` are `super_admin`, `validate.location.access`, `module`, `scheduling.captcha`, and `cross_tenant`; no terminal-session alias exists. The API group appends tenancy/security/locale/company context middleware at `apps/api/bootstrap/app.php:72-77`, again with no terminal resolver.

`ShiftController` resolves terminal identity from request data plus company context, not from a server-side terminal session. `apps/api/app/Modules/POS/Presentation/Controllers/ShiftController.php:46-52`:

```php
public function open(OpenShiftRequest $request): JsonResponse
{
    Gate::authorize('pos.manage_shifts');

    $terminal = Terminal::byCode($request->validated('terminal_code'))
        ->where('company_id', $this->companyContext->getCompanyId())
        ->firstOrFail();
```

`OpenShiftRequest` validates `terminal_code` from the request and scopes existence by tenant/company at `apps/api/app/Modules/POS/Presentation/Requests/OpenShiftRequest.php:41-46`:

```php
'terminal_code' => [
    'required', 'string', 'max:50',
    ScopedExists::tenantAndCompany('pos_terminals', $tenantId, $companyId, 'code'),
],
```

Other POS auth/terminal surfaces follow the same pattern. `PosAuthController::pinData()` accepts optional request `terminal_id` and scopes it by tenant/company/active/non-virtual terminal at `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:133-145`. `TerminalController::claim()` gates `pos.operate_terminal`, then loads the request `terminal_id` through `Terminal::forCompany(...)` at `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:317-325`. `DiscountController::getPermissions()` reads `X-Terminal-Code` or `terminal_code` from the request, then resolves it with `Terminal::forCompany($companyId)->byCode(...)` at `apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php:53-75`.

Search evidence: `rg -n "TerminalSession|terminal_session|TerminalMiddleware|ResolveTerminal|currentTerminal|current_terminal|terminalContext|terminal context" apps/api/app apps/api/routes apps/api/bootstrap apps/api/config` found no terminal-session middleware or auth-context terminal resolver. The only `terminal context` hits were comments in fiscal QR token services, not request middleware.

**Adjudication:** The plan's downgrade is correct against the current codebase. r1's requested "resolve from authenticated terminal session" depends on a mechanism that is not present. The established boundary is authenticated user + company context + POS permission + company-scoped terminal identifier. The plan preserves that boundary and derives `location_id` from the resolved terminal, not from a client-supplied location.

## 4. Remaining Open Issues

- **Minor edit:** Task 9 still includes a `stockApi` code block importing and returning `apiGet` at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1197-1223`, immediately followed by the mandatory correction to use raw `api.get` at `docs/superpowers/plans/2026-06-11-pos-location-aware-stock.md:1226`. This is internally contradictory enough to trip an executor who copies the code block. Replace the code block with the raw `api.get` implementation so the snippet and mandatory note match.

- **No r1 carry-over requiring request-changes found.** All eight named applied fixes are present in the plan text; the three rejected r1 findings are wrong against the current source; and the blocker downgrade matches the current POS auth/terminal flow.

## 5. Verdict + confidence %

**Verdict: APPROVE-WITH-MINOR-EDITS**  
**Confidence: 89%**

The plan is executable after the Task 9 snippet is made consistent with its mandatory `api.get` instruction. The r1 blocker should remain downgraded unless a new terminal-session middleware/auth-context resolver is introduced before implementation.
