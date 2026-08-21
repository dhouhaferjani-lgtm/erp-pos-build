<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O-27 / F-4 — live-tenant chart validation must measure the SAME set the
 * provisioning manifest classifies REQUIRED.
 *
 * Before this lane, {@see SystemAccountPurpose::requiredPurposes()} was a strict
 * 14-of-28 SUBSET of `ProvisioningRequiredPurposesV1`'s REQUIRED partition
 * (P3-M2 reconciliation finding D-2). A brownfield tenant missing any of the
 * other fourteen — `inventory` most sharply, resolved through
 * `findByPurposeOrFail` at GR/IR, supplier-invoice clearing, cost
 * capitalization, inventory movement and write-off — got `valid: true` from
 * {@see ChartOfAccountsService::validateCompanyAccounts()} and then hard-failed
 * the first time the corresponding path ran.
 */
final class LiveTenantChartValidationParityTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccountsService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // The legacy frozen-seeder arm is what provisions today and what
        // brownfield charts were built by; pin it rather than inherit the
        // config default so this class cannot silently drift onto the template
        // arm (same discipline as SeededChartManifestRequiredPurposeCompletenessTest).
        config(['country_defaults.provisioning_enabled' => false]);

        $this->service = app(ChartOfAccountsService::class);
        $this->tenant = Tenant::create([
            'name' => 'Live Chart Validation Tenant',
            'slug' => 'live-chart-validation-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function seedChart(string $countryCode): Company
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Live Chart Co '.uniqid(),
            'country_code' => $countryCode,
            'currency' => $countryCode === 'TN' ? 'TND' : 'EUR',
            'locale' => $countryCode === 'TN' ? 'fr_TN' : 'en_US',
            'timezone' => $countryCode === 'TN' ? 'Africa/Tunis' : 'UTC',
        ]);

        $this->service->seedForCompany($company);

        return $company;
    }

    /**
     * The O-27 defect, now closed.
     *
     * Written first against the pre-widening code, where it asserted the
     * OPPOSITE (`valid: true`) and passed — that green run is the defect's
     * evidence. Holing `inventory` out of a seeded FR chart must now be caught.
     */
    public function test_a_chart_missing_the_inventory_purpose_fails_live_tenant_validation(): void
    {
        $company = $this->seedChart('FR');

        Account::forCompany($company->id)
            ->where('system_purpose', SystemAccountPurpose::Inventory->value)
            ->update(['system_purpose' => null]);

        $result = $this->service->validateCompanyAccounts($company->id);

        $this->assertFalse(
            $result['valid'],
            'A chart with no `inventory` purpose cannot receive goods — validation must not report it healthy.',
        );
        $this->assertContains(SystemAccountPurpose::Inventory->value, $result['missing_purposes']);
    }

    /**
     * All fourteen, not just the one the narrative names. Each is holed out of
     * an otherwise complete seeded chart on its own, so a widening that
     * happened to cover only some of them fails here naming the survivor.
     */
    public function test_every_previously_unchecked_required_purpose_is_now_caught_one_at_a_time(): void
    {
        foreach (self::PREVIOUSLY_UNCHECKED as $purposeValue) {
            $purpose = SystemAccountPurpose::from($purposeValue);
            $company = $this->seedChart('FR');

            $this->assertTrue(
                $this->service->validateCompanyAccounts($company->id)['valid'],
                sprintf('The seeded FR chart must start healthy before holing %s out of it.', $purposeValue),
            );

            Account::forCompany($company->id)
                ->where('system_purpose', $purpose->value)
                ->update(['system_purpose' => null]);

            $result = $this->service->validateCompanyAccounts($company->id);

            $this->assertFalse($result['valid'], sprintf(
                'Purpose %s is manifest-REQUIRED but a chart missing it still reports healthy.',
                $purposeValue,
            ));
            $this->assertContains($purposeValue, $result['missing_purposes']);
        }
    }

    /**
     * The repair path, end to end: a holed brownfield chart is reported
     * unhealthy, the backfill fills it from the country chart's own account
     * row, and validation passes again.
     */
    public function test_the_backfill_repairs_a_holed_chart_so_validation_passes_again(): void
    {
        $company = $this->seedChart('FR');

        // A brownfield chart provisioned before `37` carried the purpose: the
        // account exists, the mapping does not. The backfill's PROMOTE branch
        // is what such a chart needs.
        Account::forCompany($company->id)
            ->where('system_purpose', SystemAccountPurpose::Inventory->value)
            ->update(['system_purpose' => null]);

        $this->assertFalse($this->service->validateCompanyAccounts($company->id)['valid']);

        $this->artisan('accounting:backfill-chart-purposes')->assertSuccessful();

        $repaired = $this->service->validateCompanyAccounts($company->id);

        $this->assertTrue($repaired['valid'], 'Backfill left: '.implode(', ', $repaired['missing_purposes']));

        $inventory = Account::findByPurpose($company->id, SystemAccountPurpose::Inventory);
        $this->assertNotNull($inventory);
        $this->assertSame('37', $inventory->code, 'The purpose must go back onto the FR chart\'s own inventory account.');
    }

    /**
     * The launch country, end to end, on the one purpose whose mapping is NOT
     * identical across the two French-plan charts.
     *
     * `pos_tender_clearing` lives on `5810` for both TN and FR, but TN has no
     * `58` (Virements internes) header and roots it directly on class `5`. A
     * backfill that copied the FR tuple wholesale would report TN's parent as
     * missing and repair nothing — so this asserts the created account's
     * parent_id is the class-`5` row by identity, not just that a row appeared.
     */
    public function test_the_backfill_repairs_a_tunisian_chart_and_roots_pos_tender_clearing_on_class_five(): void
    {
        $company = $this->seedChart('TN');

        $classFive = Account::forCompany($company->id)->where('code', '5')->first();
        $this->assertNotNull($classFive, 'The TN chart must carry the class-5 FINANCIERS header.');

        Account::forCompany($company->id)
            ->where('system_purpose', SystemAccountPurpose::PosTenderClearing->value)
            ->delete();

        $before = $this->service->validateCompanyAccounts($company->id);
        $this->assertFalse($before['valid']);
        $this->assertContains(SystemAccountPurpose::PosTenderClearing->value, $before['missing_purposes']);

        $this->artisan('accounting:backfill-chart-purposes')->assertSuccessful();

        $repaired = $this->service->validateCompanyAccounts($company->id);
        $this->assertTrue($repaired['valid'], 'Backfill left: '.implode(', ', $repaired['missing_purposes']));

        $posTenderClearing = Account::findByPurpose($company->id, SystemAccountPurpose::PosTenderClearing);
        $this->assertNotNull($posTenderClearing);
        $this->assertSame('5810', $posTenderClearing->code);
        $this->assertSame(
            $classFive->id,
            $posTenderClearing->parent_id,
            'TN roots 5810 on class 5, not on the French chart\'s 58 (Virements internes) header.',
        );
    }

    /**
     * The same repair for a chart whose account row is missing outright (the
     * CREATE branch), on the generic chart every non-TN/FR country receives.
     */
    public function test_the_backfill_repairs_a_generic_chart_whose_account_row_is_absent(): void
    {
        $company = $this->seedChart('XX');

        Account::forCompany($company->id)
            ->where('system_purpose', SystemAccountPurpose::VoucherLiability->value)
            ->delete();

        $this->assertFalse($this->service->validateCompanyAccounts($company->id)['valid']);

        $this->artisan('accounting:backfill-chart-purposes')->assertSuccessful();

        $repaired = $this->service->validateCompanyAccounts($company->id);
        $this->assertTrue($repaired['valid'], 'Backfill left: '.implode(', ', $repaired['missing_purposes']));

        $voucherLiability = Account::findByPurpose($company->id, SystemAccountPurpose::VoucherLiability);
        $this->assertNotNull($voucherLiability);
        $this->assertSame('4197', $voucherLiability->code);
    }

    /**
     * DRIFT PIN. `requiredPurposes()` is derived from the manifest at runtime,
     * so the two cannot diverge — this asserts that the derivation is actually
     * what is running, in BOTH directions, and that it is not vacuous.
     *
     * A future change that re-hardcodes the list fails here rather than
     * silently reopening D-2.
     */
    public function test_required_purposes_is_exactly_the_manifest_required_set(): void
    {
        $entries = ProvisioningRequiredPurposesV1::entries();
        ProvisioningRequiredPurposesV1::assertConforms($entries);

        $manifestRequired = [];
        foreach ($entries as $entry) {
            if ($entry['classification'] === 'REQUIRED') {
                $manifestRequired[] = $entry['purpose']->value;
            }
        }

        $checked = array_map(
            static fn (SystemAccountPurpose $purpose): string => $purpose->value,
            SystemAccountPurpose::requiredPurposes(),
        );

        sort($manifestRequired);
        sort($checked);

        $this->assertNotEmpty($checked, 'Live-tenant validation checks nothing at all.');
        $this->assertSame($manifestRequired, $checked, sprintf(
            'requiredPurposes() has drifted from ProvisioningRequiredPurposesV1. It measures LIVE tenants, so a '
            .'subset silently certifies charts that cannot post (P3-M2 finding D-2) and a superset fails healthy '
            .'ones. The manifest is the authority; do not re-declare the list here.',
        ));
    }

    public function test_the_manifest_required_set_is_the_full_twenty_eight(): void
    {
        $this->assertCount(28, SystemAccountPurpose::requiredPurposes());
    }

    /**
     * FAIL-OPEN GUARD. The set is now produced by the authority's own
     * `requiredPurposes()` accessor, which compares against the PRIVATE const
     * in the one scope that can see it. A consumer that instead filtered
     * `entries()` on the bare string `'REQUIRED'` would silently return `[]`
     * the day that const's VALUE changed — and an empty required set makes
     * `validateCompanyAccounts()` certify EVERY chart healthy, including one
     * that cannot post a single entry.
     *
     * This asserts the accessor agrees with an INDEPENDENT derivation and, more
     * importantly, that neither is empty — emptiness is the failure mode, and
     * two empty sets would compare equal.
     */
    public function test_the_authority_accessor_is_not_a_fail_open_empty_set(): void
    {
        $independent = [];
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] === 'REQUIRED') {
                $independent[] = $entry['purpose']->value;
            }
        }

        $viaAccessor = array_map(
            static fn (SystemAccountPurpose $purpose): string => $purpose->value,
            ProvisioningRequiredPurposesV1::requiredPurposes(),
        );

        sort($independent);
        sort($viaAccessor);

        $this->assertNotEmpty($viaAccessor, 'The authority accessor returned an EMPTY required set — fail-open.');
        $this->assertCount(28, $viaAccessor);
        $this->assertSame($independent, $viaAccessor);

        // And the enum genuinely delegates rather than keeping its own copy.
        $viaEnum = array_map(
            static fn (SystemAccountPurpose $purpose): string => $purpose->value,
            SystemAccountPurpose::requiredPurposes(),
        );
        sort($viaEnum);
        $this->assertSame($viaAccessor, $viaEnum);
    }

    /**
     * The fourteen manifest-REQUIRED purposes `requiredPurposes()` did NOT
     * check before this lane (P3-M2 reconciliation, finding D-2). Frozen as a
     * constant because the complement is empty once the widening lands, so the
     * list can no longer be recomputed from the code it describes.
     *
     * @var list<string>
     */
    private const PREVIOUSLY_UNCHECKED = [
        'goods_received_not_invoiced',
        'inventory',
        'marketing_goodwill_expense',
        'payment_tolerance_expense',
        'payment_tolerance_income',
        'pos_tender_clearing',
        'purchase_expenses',
        'purchase_price_variance_expense',
        'purchase_price_variance_income',
        'purchase_stamp_duty',
        'rounding_loss_expense',
        'sales_discount',
        'sales_returns_clearing',
        'voucher_liability',
    ];
}
