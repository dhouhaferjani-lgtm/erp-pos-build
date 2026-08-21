<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Console\Commands\BackfillChartPurposesCommand;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Feature\CountryDefaults\CertifiedFixtureDeltaTest;
use Tests\Feature\CountryDefaults\TemplatePublishGateTest;
use Tests\TestCase;

/**
 * Per-country SEEDED-chart completeness, keyed to ProvisioningRequiredPurposesV1.
 *
 * ## Scope boundary: provisioning, NOT live-tenant validation
 *
 * This gates the chart a NEW TENANT is provisioned with. It is **not** live-tenant
 * validation. Nothing here widens {@see SystemAccountPurpose::requiredPurposes()}
 * or {@see ChartOfAccountsService::validateCompanyAccounts()} — those are what
 * ALREADY-LIVE tenants are measured against, and tightening them needs a
 * backfill/seed story ({@see BackfillChartPurposesCommand})
 * plus an owner gate routed through the country-defaults authority (P3 dispatch
 * F-4). A defect found here is a SEEDER bug, fixed in the chart definition —
 * never by making existing tenants fail validation.
 *
 * ## Which provisioning path this covers, and why it is the uncovered one
 *
 * {@see ChartOfAccountsService::seedForCompany()} has two arms:
 *
 *  - the TEMPLATE arm (`country_defaults.provisioning_enabled` = true), already
 *    gated against this same manifest by
 *    {@see CertifiedFixtureDeltaTest} (REQUIRED-set
 *    diff per certified template) and, for account TYPE, by
 *    {@see TemplatePublishGateTest} via
 *    `TemplatePublishingService`;
 *  - the LEGACY FROZEN-SEEDER arm (the config default, `false`), which is what
 *    this class covers and which had neither guarantee.
 *
 * The two existing seeder-side tests key off something other than the manifest:
 * {@see ChartOfAccountsPurposeParityTest} keys off `SystemAccountPurpose::cases()`
 * minus a per-country exemption list, and
 * {@see ChartOfAccountsServiceTest::test_every_country_seeder_satisfies_all_required_system_purposes()}
 * keys off `requiredPurposes()` — which the country-defaults lane ruled (D-6) is
 * NOT the operational manifest and must not be used for conformance. Neither
 * asserts that the mapped account's TYPE matches
 * {@see SystemAccountPurpose::expectedAccountType()}. A type-mismatched mapping
 * resolves without error and then posts the leg to the wrong side of the balance
 * sheet, so that dimension is the substantive addition here.
 *
 * ## Consuming, never redefining
 *
 * {@see ProvisioningRequiredPurposesV1} is the authority: a complete partition of
 * every `SystemAccountPurpose` into REQUIRED / SCOPE_REQUIRED / CONDITIONAL /
 * SOFT with per-entry call site and evidence. Only REQUIRED is enforced here.
 * SCOPE_REQUIRED (`SalesStampDutyPayable` — a Tunisian obligation with no PCG
 * counterpart), CONDITIONAL (gated behind a documented DOMAIN_PRECHECK_4XX) and
 * SOFT (no registered throwing call site) are out of scope by the manifest's own
 * classification, not by a local opinion.
 */
final class SeededChartManifestRequiredPurposeCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every country code that reaches a distinct seeded chart.
     *
     * Enumerated from the provisioning dispatch itself
     * ({@see ChartOfAccountsService::getSeederForCountry()}), a three-arm match:
     * `'TN' => TunisiaChartOfAccountsSeeder`, `'FR' => FranceChartOfAccountsSeeder`,
     * `default => GenericChartOfAccountsSeeder`. There is no MA/DZ/UK/IT chart —
     * `getSupportedCountries()` returns `['TN', 'FR']` and every other country
     * receives the generic international chart. 'XX' is therefore not a fourth
     * chart: it is an unassigned code that exercises the `default` arm.
     */
    private const COUNTRY_CODES = ['TN', 'FR', 'XX'];

    private ChartOfAccountsService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the legacy seeder arm explicitly rather than inheriting the config
        // default: this class is specifically the guard for that arm, and it must
        // not silently start testing the template arm if the default flips.
        config(['country_defaults.provisioning_enabled' => false]);

        $this->service = app(ChartOfAccountsService::class);
        $this->tenant = Tenant::create([
            'name' => 'Seeded Chart Completeness Tenant',
            'slug' => 'seeded-chart-completeness-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    /**
     * The manifest's REQUIRED partition, read straight from the authority.
     *
     * `assertConforms()` runs first so a manifest whose partition has drifted
     * fails HERE with the manifest's own message, rather than silently shrinking
     * the set this test enforces.
     *
     * @return list<SystemAccountPurpose>
     */
    private function manifestRequiredPurposes(): array
    {
        $entries = ProvisioningRequiredPurposesV1::entries();
        ProvisioningRequiredPurposesV1::assertConforms($entries);

        $required = [];
        foreach ($entries as $entry) {
            // The classification constants are private to the manifest; the
            // published shape of entries() is the string. Reading the string is
            // the documented consumption path, not a workaround.
            if ($entry['classification'] === 'REQUIRED') {
                $required[] = $entry['purpose'];
            }
        }

        return $required;
    }

    private function seedChart(string $countryCode): Company
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Completeness Co '.uniqid(),
            'country_code' => $countryCode,
            'currency' => $countryCode === 'TN' ? 'TND' : 'EUR',
            'locale' => $countryCode === 'TN' ? 'fr_TN' : 'en_US',
            'timezone' => $countryCode === 'TN' ? 'Africa/Tunis' : 'UTC',
        ]);

        $this->service->seedForCompany($company);

        return $company;
    }

    /**
     * The single assertion body, shared with the tamper cases so those cases
     * prove THIS logic discriminates rather than a parallel copy of it.
     *
     * @param  list<SystemAccountPurpose>  $required
     */
    private function assertChartCoversRequiredPurposes(Company $company, array $required): void
    {
        $countryCode = $company->country_code;

        foreach ($required as $purpose) {
            $account = Account::findByPurpose($company->id, $purpose);

            $this->assertNotNull($account, sprintf(
                'Country %s: seeded chart does not map REQUIRED purpose %s. '
                .'ProvisioningRequiredPurposesV1 classifies it REQUIRED, so a live path '
                .'resolves-or-fails on it and this chart cannot post that entry.',
                $countryCode,
                $purpose->value,
            ));

            $this->assertSame($purpose->expectedAccountType(), $account->type, sprintf(
                'Country %s: REQUIRED purpose %s is mapped to account %s of type %s, '
                .'but expectedAccountType() is %s. A type-mismatched mapping resolves '
                .'without error and then posts the leg to the wrong side.',
                $countryCode,
                $purpose->value,
                $account->code,
                $account->type->value,
                $purpose->expectedAccountType()->value,
            ));
        }
    }

    public function test_every_seeded_country_chart_maps_every_manifest_required_purpose_with_the_expected_account_type(): void
    {
        $required = $this->manifestRequiredPurposes();

        $this->assertNotEmpty($required, 'The manifest yielded no REQUIRED purposes to enforce.');

        foreach (self::COUNTRY_CODES as $countryCode) {
            $this->assertChartCoversRequiredPurposes($this->seedChart($countryCode), $required);
        }
    }

    /**
     * Tamper case (the red-first artifact for a ratchet, per the dispatch's
     * working rules): a fixture chart with one REQUIRED purpose mapping removed
     * must fail, and the failure must NAME the country and the purpose so an
     * operator can act without re-deriving the manifest.
     */
    public function test_a_chart_missing_one_required_purpose_fails_and_names_the_country_and_the_purpose(): void
    {
        $required = $this->manifestRequiredPurposes();
        $company = $this->seedChart('FR');

        $tampered = SystemAccountPurpose::CostOfGoodsSold;

        Account::forCompany($company->id)
            ->where('system_purpose', $tampered->value)
            ->update(['system_purpose' => null]);

        try {
            $this->assertChartCoversRequiredPurposes($company, $required);
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('Country FR', $failure->getMessage());
            $this->assertStringContainsString($tampered->value, $failure->getMessage());

            return;
        }

        $this->fail(sprintf(
            'Removing the %s mapping from the FR chart did not fail the completeness gate — '
            .'the gate does not discriminate.',
            $tampered->value,
        ));
    }

    /**
     * Second tamper axis: existence alone is not the gate. A purpose still
     * mapped, but onto an account of the wrong TYPE, must also fail and name
     * both types. This is the dimension no existing seeder-side test can see.
     */
    public function test_a_required_purpose_mapped_to_the_wrong_account_type_fails_and_names_both_types(): void
    {
        $required = $this->manifestRequiredPurposes();
        $company = $this->seedChart('TN');

        $tampered = SystemAccountPurpose::CustomerReceivable;

        // Move the mapping onto a revenue account: still resolvable, still
        // non-null, and silently wrong. UNIQUE(company_id, system_purpose)
        // forces the vacate-then-reassign order.
        $revenueAccountId = Account::forCompany($company->id)
            ->where('system_purpose', SystemAccountPurpose::ProductRevenue->value)
            ->value('id');

        $this->assertNotNull($revenueAccountId, 'TN chart must map ProductRevenue for this tamper case to be meaningful.');

        Account::forCompany($company->id)
            ->where('system_purpose', $tampered->value)
            ->update(['system_purpose' => null]);
        Account::forCompany($company->id)
            ->where('id', $revenueAccountId)
            ->update(['system_purpose' => $tampered->value]);

        try {
            $this->assertChartCoversRequiredPurposes($company, $required);
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('Country TN', $failure->getMessage());
            $this->assertStringContainsString($tampered->value, $failure->getMessage());
            $this->assertStringContainsString('expectedAccountType', $failure->getMessage());

            return;
        }

        $this->fail('A type-mismatched REQUIRED purpose mapping did not fail the completeness gate.');
    }
}
