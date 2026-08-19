<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Infrastructure\Import\InventoryVarianceCoaTemplateV2Importer;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Seeders\TemplateChartOfAccountsSeeder;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;

final class ChartOfAccountsParityTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    #[DataProvider('charts')]
    public function test_v2_is_v1_plus_the_approved_option_a_variance_rows(
        string $legacyKey,
        string $v2Key,
        string $expenseParent,
        string $gainParent,
        string $expenseName,
        string $gainName,
    ): void {
        $legacy = AdminTemplate::query()->where('bootstrap_key', $legacyKey)->firstOrFail();
        $v2 = AdminTemplate::query()->where('bootstrap_key', $v2Key)->firstOrFail();

        self::assertNull($v2->cloned_from_id);
        self::assertSame($legacy->accounts()->count() + 2, $v2->accounts()->count());

        $variance = $v2->accounts()->whereIn('system_purpose', [
            SystemAccountPurpose::InventoryShrinkageExpense->value,
            SystemAccountPurpose::InventoryGainIncome->value,
        ])->orderBy('code')->get()->map(static fn ($row): array => [
            'code' => $row->code,
            'name' => $row->name,
            'type' => $row->getRawOriginal('type'),
            'parent_code' => $row->parent_code,
            'system_purpose' => $row->getRawOriginal('system_purpose'),
            'is_system' => $row->is_system,
        ])->values()->all();

        self::assertSame([
            [
                'code' => '6586',
                'name' => $expenseName,
                'type' => 'expense',
                'parent_code' => $expenseParent,
                'system_purpose' => SystemAccountPurpose::InventoryShrinkageExpense->value,
                'is_system' => true,
            ],
            [
                'code' => '7586',
                'name' => $gainName,
                'type' => 'revenue',
                'parent_code' => $gainParent,
                'system_purpose' => SystemAccountPurpose::InventoryGainIncome->value,
                'is_system' => true,
            ],
        ], $variance);

        foreach ([
            SystemAccountPurpose::CostOfGoodsSold,
            SystemAccountPurpose::GeneralExpense,
            SystemAccountPurpose::CustomerAdvance,
        ] as $purpose) {
            self::assertSame(
                $legacy->accounts()->where('system_purpose', $purpose->value)->firstOrFail()->only(['code', 'name', 'type', 'parent_code', 'system_purpose', 'is_system']),
                $v2->accounts()->where('system_purpose', $purpose->value)->firstOrFail()->only(['code', 'name', 'type', 'parent_code', 'system_purpose', 'is_system']),
                "{$purpose->value} changed across the v1 to v2 template boundary.",
            );
        }

        self::assertFalse($legacy->accounts()->whereIn('system_purpose', [
            SystemAccountPurpose::InventoryShrinkageExpense->value,
            SystemAccountPurpose::InventoryGainIncome->value,
        ])->exists());
    }

    public function test_the_v2_template_can_be_published_and_provisions_a_posting_ready_chart(): void
    {
        $template = AdminTemplate::query()->where('bootstrap_key', 'coa.tn.default-v2')->firstOrFail();
        $actor = $this->m4Actor();
        $certificationDraft = app(TemplatePublishingService::class)->cloneToDraft(
            $template->id,
            'Treasury-approved Option A certification draft',
            $actor,
        );
        $published = app(TemplatePublishingService::class)->publish(
            $certificationDraft->id,
            'Treasury-approved Option A test certification',
            ['TN'],
            $actor,
        );
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        app(TemplateChartOfAccountsSeeder::class)->seed($published, $company);

        self::assertDatabaseHas('accounts', [
            'company_id' => $company->id,
            'code' => '6586',
            'system_purpose' => SystemAccountPurpose::InventoryShrinkageExpense->value,
        ]);
        self::assertDatabaseHas('accounts', [
            'company_id' => $company->id,
            'code' => '7586',
            'system_purpose' => SystemAccountPurpose::InventoryGainIncome->value,
        ]);
        self::assertTrue(app(GeneralLedgerService::class)->hasInventoryMovementAccounts($company->id, MovementReason::Damage));
        self::assertTrue(app(GeneralLedgerService::class)->hasInventoryMovementAccounts($company->id, MovementReason::CountCorrection));
    }

    public function test_the_v2_bootstrap_import_is_idempotent_and_asserts_existing_content(): void
    {
        self::assertSame([
            'coa.tn.default-v2' => 'verified_existing',
            'coa.fr.default-v2' => 'verified_existing',
            'coa.generic.default-v2' => 'verified_existing',
        ], app(InventoryVarianceCoaTemplateV2Importer::class)->importAll());

        $template = AdminTemplate::query()->where('bootstrap_key', 'coa.tn.default-v2')->firstOrFail();
        $template->accounts()->where('code', '6586')->update(['name' => 'tampered']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('coa.tn.default-v2 bootstrap assertion failed');
        app(InventoryVarianceCoaTemplateV2Importer::class)->importAll();
    }

    /** @return array<string, array{string, string, string, string, string, string}> */
    public static function charts(): array
    {
        return [
            'Tunisia' => ['coa.tn.legacy-v1', 'coa.tn.default-v2', '65', '75', "Écarts d'inventaire — manquants et pertes", "Écarts d'inventaire — excédents"],
            'France' => ['coa.fr.legacy-v1', 'coa.fr.default-v2', '65', '75', "Écarts d'inventaire — manquants et pertes", "Écarts d'inventaire — excédents"],
            'Generic' => ['coa.generic.legacy-v1', 'coa.generic.default-v2', '6000', '7000', 'Inventory Shrinkage Expense', 'Inventory Count Gain'],
        ];
    }
}
