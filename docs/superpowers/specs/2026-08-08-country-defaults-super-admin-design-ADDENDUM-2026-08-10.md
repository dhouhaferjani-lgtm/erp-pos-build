# Country Defaults in the Super Admin Panel — Phase A Reconciliation Addendum

This addendum is the delta record for the accepted Rev 14 design spec in
`docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md`. It does not amend the
accepted spec body. Where the repository changed after that spec was frozen, the reconciled facts
and executed decisions below govern Phase A.

## Reconciliation baseline

The freeze and reconciliation base is the current fetched `origin/dev`, unchanged from the
authoring baseline:

```text
7d85232cc54abd6a6b2135f476205ab434e71a66
```

The complete operational manifest has 41 entries: 27 REQUIRED, one SCOPE-REQUIRED, four
CONDITIONAL, and nine SOFT (`27 + 1 + 4 + 9 = 41`; accepted spec §4.2.1). Mechanical
reconciliation of its REQUIRED classification over each frozen seeder's complete
account-definition array produced:

The exact 27 REQUIRED purposes are `Bank`, `Cash`, `CustomerReceivable`, `Inventory`,
`SupplierPayable`, `VatCollected`, `VatDeductible`, `ProductRevenue`, `ServiceRevenue`,
`CostOfGoodsSold`, `GeneralExpense`, `OpeningBalanceEquity`, `PurchasePriceVarianceExpense`,
`PurchasePriceVarianceIncome`, `GoodsReceivedNotInvoiced`, `PurchaseStampDuty`, `SalesDiscount`,
`CustomerAdvance`, `SupplierAdvance`, `SalesReturnsClearing`, `VoucherLiability`,
`MarketingGoodwillExpense`, `PosTenderClearing`, `RoundingLossExpense`,
`PaymentToleranceExpense`, `PaymentToleranceIncome`, and `PurchaseExpenses`.

| Scope | Accounts | Missing REQUIRED | `SalesStampDutyPayable` | Superseded content deltas |
|---|---:|---|---|---|
| TN | 139 | `[]` | present, as required for the timbre scope (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:208`) | `[]` |
| FR | 144 | `[]` | absent, as required for a non-timbre scope | `[]` |
| Generic (`*`) | 61 | `[]` | absent, as required for a non-timbre scope | `[]` |

### Committed reconciliation evidence

Run the following exact command from this pinned worktree's root. Composer supplies framework
dependencies only: the enum, seeder contract, and all three seeder definitions are explicitly
required from the resolved worktree before reflection, and every reflected source path must remain
under that root.

```bash
php -r '
$root = realpath(getcwd());
if ($root === false) {
  throw new RuntimeException("Unable to resolve the worktree root.");
}
require $root . "/apps/api/vendor/autoload.php";
require_once $root . "/apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php";
require_once $root . "/apps/api/database/seeders/Contracts/ChartOfAccountsSeederContract.php";
require_once $root . "/apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php";
require_once $root . "/apps/api/database/seeders/FranceChartOfAccountsSeeder.php";
require_once $root . "/apps/api/database/seeders/GenericChartOfAccountsSeeder.php";
$sources = [
  "purpose_enum" => App\Modules\Accounting\Domain\Enums\SystemAccountPurpose::class,
  "seeder_contract" => Database\Seeders\Contracts\ChartOfAccountsSeederContract::class,
  "TN_seeder" => Database\Seeders\TunisiaChartOfAccountsSeeder::class,
  "FR_seeder" => Database\Seeders\FranceChartOfAccountsSeeder::class,
  "GENERIC_seeder" => Database\Seeders\GenericChartOfAccountsSeeder::class,
];
echo "source_root=" . $root . PHP_EOL;
foreach ($sources as $label => $class) {
  $sourceFile = (new ReflectionClass($class))->getFileName();
  if (!is_string($sourceFile) || !str_starts_with($sourceFile, $root . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException($label . " did not resolve from the worktree: " . var_export($sourceFile, true));
  }
  echo $label . "_file=" . $sourceFile . PHP_EOL;
}
$seeders = [
  "TN" => Database\Seeders\TunisiaChartOfAccountsSeeder::class,
  "FR" => Database\Seeders\FranceChartOfAccountsSeeder::class,
  "GENERIC" => Database\Seeders\GenericChartOfAccountsSeeder::class,
];
$required = [
  "bank", "cash", "customer_receivable", "inventory", "supplier_payable",
  "vat_collected", "vat_deductible", "product_revenue", "service_revenue",
  "cost_of_goods_sold", "general_expense", "opening_balance_equity",
  "purchase_price_variance_expense", "purchase_price_variance_income",
  "goods_received_not_invoiced", "purchase_stamp_duty", "sales_discount",
  "customer_advance", "supplier_advance", "sales_returns_clearing",
  "voucher_liability", "marketing_goodwill_expense", "pos_tender_clearing",
  "rounding_loss_expense", "payment_tolerance_expense", "payment_tolerance_income",
  "purchase_expenses",
];
$enumValues = array_map(
  static fn (App\Modules\Accounting\Domain\Enums\SystemAccountPurpose $purpose): string => $purpose->value,
  App\Modules\Accounting\Domain\Enums\SystemAccountPurpose::cases(),
);
$unknownRequired = array_values(array_diff($required, $enumValues));
if ($unknownRequired !== []) {
  throw new RuntimeException("Unknown REQUIRED purpose: " . json_encode($unknownRequired, JSON_THROW_ON_ERROR));
}
$oldDeltas = [
  "TN" => ["sales_discount"],
  "FR" => ["cost_of_goods_sold", "general_expense", "sales_discount", "customer_advance", "supplier_advance", "code:624"],
  "GENERIC" => [],
];
echo "base_sha=" . trim((string) shell_exec("git -C " . escapeshellarg($root) . " rev-parse origin/dev")) . PHP_EOL;
echo "enum_case_count=" . count($enumValues) . PHP_EOL;
echo "required_count=" . count($required) . PHP_EOL;
foreach ($seeders as $country => $class) {
  $method = new ReflectionMethod($class, "getAccountsDefinition");
  $method->setAccessible(true);
  $rows = $method->invoke(new $class());
  $purposes = array_values(array_filter(array_column($rows, "system_purpose"), static fn ($value): bool => is_string($value)));
  $codes = array_column($rows, "code");
  $missing = array_values(array_diff($required, $purposes));
  $stamp = in_array("sales_stamp_duty_payable", $purposes, true) ? "present" : "absent";
  $deltaMissing = [];
  foreach ($oldDeltas[$country] as $delta) {
    if (str_starts_with($delta, "code:")) {
      $code = substr($delta, 5);
      if (!in_array($code, $codes, true)) { $deltaMissing[] = $delta; }
    } elseif (!in_array($delta, $purposes, true)) {
      $deltaMissing[] = $delta;
    }
  }
  echo $country . " accounts=" . count($rows)
    . " missing_required=" . json_encode($missing, JSON_THROW_ON_ERROR)
    . " sales_stamp_duty_payable=" . $stamp
    . " old_delta_missing=" . json_encode($deltaMissing, JSON_THROW_ON_ERROR)
    . PHP_EOL;
}
'
```

Its verbatim output at the pinned worktree is:

```text
source_root=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a
purpose_enum_file=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a/apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php
seeder_contract_file=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a/apps/api/database/seeders/Contracts/ChartOfAccountsSeederContract.php
TN_seeder_file=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a/apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php
FR_seeder_file=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a/apps/api/database/seeders/FranceChartOfAccountsSeeder.php
GENERIC_seeder_file=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a/apps/api/database/seeders/GenericChartOfAccountsSeeder.php
base_sha=7d85232cc54abd6a6b2135f476205ab434e71a66
enum_case_count=41
required_count=27
TN accounts=139 missing_required=[] sales_stamp_duty_payable=present old_delta_missing=[]
FR accounts=144 missing_required=[] sales_stamp_duty_payable=absent old_delta_missing=[]
GENERIC accounts=61 missing_required=[] sales_stamp_duty_payable=absent old_delta_missing=[]
```

Zero missing REQUIRED purposes is not, by itself, the full publish or certification gate. The
scope-dependent stamp-purpose rule and every other publish invariant still apply. The old content
deltas are empty, but human authenticated HTTP publish remains mandatory and is the only path that
can set certification metadata, including `certified_by`, `published_at`, `content_hash`, and the
certified scope.

## Post-spec repository deltas

### D-1 — Previously missing baseline content is present

The accepted spec's former §5.4 gaps are all closed at the pinned base: TN no longer lacks
`SalesDiscount`; FR no longer lacks `CostOfGoodsSold`, `GeneralExpense`, `SalesDiscount`,
`CustomerAdvance`, `SupplierAdvance`, or code `624`. The missing-REQUIRED sets and superseded
content deltas are therefore empty for TN, FR, and Generic. M4 remains data-driven from the
operational manifest so a future manifest addition fails fixture verification until every scope is
resolved explicitly. Empty deltas do not waive authenticated human HTTP publication.

### D-2 — Compatibility-seeder freeze and replay semantics

All three compatibility seeders are frozen at `7d85232cc`. M1 adds their deprecation docblocks;
M5 adds the static consumer guard.

tenant migration `apps/api/database/migrations/tenant/2026_03_23_300000_fix_existing_tunisian_companies_tax_setup.php:44-53` instantiates the **live** `TunisiaChartOfAccountsSeeder` **only when a Tunisian company has zero accounts**. A not-yet-run migration therefore seeds the definition frozen at the *deployed source version*, not the bytes of its authoring date; already-run migrations do not rerun. It must remain the **only** production replay consumer of a frozen seeder.

### D-3 — One timbre authority

At the pinned base, `CountryTaxConfigurationRegistry` still owns the live timbre predicate:
its `MAP` stores `supports_stamp_duty` as `true` for TN and `false` for FR
(`apps/api/app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php:20-33`),
and `supportsStampDuty()` answers directly from that map
(`apps/api/app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php:48-50`).
This pinned-base Taxation predicate is the duplicate-authority drift against the accepted target.
M1 must remove it from the Taxation map and make Taxation delegate to the sole versioned
CountryDefaults authority by executing S-1 below; M0 does not claim that rewire is already complete.

### D-4 — Protected-code variants

The protected-code registry has three variants:

- TN instrument codes: `5312`, `4035`, `413`, `403`, `5313`, `5314`, `6275`, `43666`, `416`;
  demo-consumer codes: `613`, `615`, `616`, `624`, `626`, `6061`, `6064`.
- FR instrument codes: `5112`, `4035`, `413`, `403`, `5113`, `5114`, `627`, `44566`, `416`;
  demo-consumer codes: `613`, `615`, `616`, `624`, `626`, `6061`, `6064`.
- Wildcard/generic instrument codes: the same non-TN set as FR; demo-consumer codes: `6130`,
  `6170`, `6250`, `6256`.

The drift test must compare this registry with both `ExpenseCategorySeeder` maps as well as the
instrument consumers.

### D-5 — Expense-category failure boundary

A deliberate `null` mapping may resolve `GeneralExpense`. An absent mapped code and a wholly absent
COA must fail loudly.

### D-6 — Operational manifest independence

`SystemAccountPurpose` has 41 cases. Its `requiredPurposes()` helper returns 13 purposes and is not
the 41-entry operational manifest. Neither manifest conformance nor certification may derive the
27-entry REQUIRED classification from that helper.

### D-7 — Country-code mutability limitation

At the pinned base, `UpdateCompanySettingsRequest` authorizes callers with `settings.update` and
accepts `country_code` (`apps/api/app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php:15-17,41`).
`CompanySettingsController` writes that validated field
(`apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:103-154`).
Meanwhile, `tax_configurations` is keyed by `country_code` and has no `company_id`
(`apps/api/database/migrations/tenant/2025_12_30_100000_create_tax_configurations_table.php:13-38`).
Those facts are the unresolved repository drift that constrains Phase A's certification claim:

Phase A does not wait for, and does not implement, `country_code` immutability (a separate settings-guards lane owns it). Phase A's certification claims cover unconditional template-layer timbre invariants only. The tenant-side stamp capability check is a usability guard, never an authorization control, until that lane closes.

Owner-visible follow-up:
`docs/superpowers/tickets/2026-08-10-country-code-mutable-authz-authority.md:8-23,33-35`.

### D-8 — Existing-company boundary

Phase A does not mutate an existing company's accounts. No Phase A resolver, service, or test may
infer live-chart/template parity: existing charts can diverge from templates by construction after
the separate backfill lane creates or maps purposes.

## Executed design decisions

### S-1 — Timbre authority and dependency direction

There is exactly one production authority for the timbre predicate and capability version:

1. `apps/api/app/Shared/Contracts/CountryDefaults/CountryAccountingCapabilities.php` is an
   interface exposing `supportsStampDuty(string $countryCode): bool` and `version(): string`.
2. `apps/api/app/Modules/CountryDefaults/Application/Services/CountryAccountingCapabilitiesService.php`
   is the sole implementation and owns the `{TN}` predicate and version scalar together.
3. `apps/api/app/Modules/CountryDefaults/Providers/CountryDefaultsServiceProvider.php` binds the
   contract to that implementation.
4. `CountryTaxConfigurationRegistry` constructor-injects the shared contract,
   `supports_stamp_duty` is removed from its `MAP`, and `supportsStampDuty()` delegates to the
   contract. Only the seeder-class map remains in Taxation.

This direction preserves the shared-interface-only module boundary, prevents the central
certification kernel from depending on a tenant-tax application class, and prevents a duplicate
capability authority. A source/architecture test must fail if a second production predicate or
country set appears.

### S-2 — Module placement

The feature is a new module rooted at `apps/api/app/Modules/CountryDefaults/`, using
Domain/Application/Infrastructure/Presentation boundaries. It does not live in the monitoring-only
`apps/api/app/Modules/Admin/` slice or the legacy fleet/vertical controllers under
`apps/api/app/Http/Controllers/Api/Admin/`. Only central-authentication integration remains under
`apps/api/app/Http/`.

### S-3 — Minimal `admin/auth` edit

Login remains public and throttled. Only the nested logout/`me` group replaces `super_admin` with
the central-admin check while retaining `auth:sanctum-admin`; the full-admin group remains
byte-identical.

### S-4 — Two-release, default-off activation

Release 1 adds schema and idempotent draft import while readers remain on the compatibility
seeders. Release 2 is a deployment configuration change performed only after certification and
`country-defaults:verify` succeed. Both company-creation paths read
`COUNTRY_DEFAULTS_PROVISIONING_ENABLED` uncached at call time through
`apps/api/config/country_defaults.php`; it defaults to `false`, and rollback restores `false`.

### S-5 — Certification limitation

Phase A does not wait for, and does not implement, `country_code` immutability (a separate settings-guards lane owns it). Phase A's certification claims cover unconditional template-layer timbre invariants only. The tenant-side stamp capability check is a usability guard, never an authorization control, until that lane closes.

Owner-visible follow-up:
`docs/superpowers/tickets/2026-08-10-country-code-mutable-authz-authority.md:8-23,33-35`.

### S-6 — Effort envelope

The Phase A implementation envelope is 9–12 dev-days. Actual effort is reported in the session
report.
