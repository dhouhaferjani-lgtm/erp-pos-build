<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportWarningCode;
use App\Modules\Import\Domain\Exceptions\CodedImportRowException;
use App\Modules\Import\Services\UnitResolver;
use App\Modules\Product\Application\Services\ProductUnitBackfillService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Shared\Contracts\UnitCatalogQueryInterface;
use App\Shared\DTOs\UnitCatalogEntryData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UnitResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private UnitCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->category = UnitCategory::factory()->create(['name' => 'Weight']);
    }

    public function test_matching_is_trimmed_then_exact_and_case_sensitive(): void
    {
        $kg = $this->unit('kg', null);
        $upperKg = $this->unit('KG', $this->tenant->id);
        $resolver = app(UnitResolver::class);

        $this->assertSame($kg->id, $resolver->resolve($this->company->id, 'kg', false)->unitId);
        $this->assertSame($kg->id, $resolver->resolve($this->company->id, ' kg ', false)->unitId);
        $this->assertSame($upperKg->id, $resolver->resolve($this->company->id, 'KG', false)->unitId);

        foreach (['Kg', 'kgs', 'Kilogrammes'] as $unknown) {
            try {
                $resolver->resolve($this->company->id, $unknown, false);
                $this->fail("{$unknown} must not resolve.");
            } catch (CodedImportRowException $exception) {
                $this->assertSame(ImportErrorCode::UnitUnknown, $exception->errorCode);
                if (! array_key_exists('supplied', $exception->detail)
                    || ! array_key_exists('accepted', $exception->detail)) {
                    $this->fail('Unknown-unit detail must carry supplied and accepted.');
                }
                $this->assertSame($unknown, $exception->detail['supplied']);
                $this->assertSame(['KG', 'kg'], $exception->detail['accepted']);
            }
        }
    }

    public function test_duplicate_exact_codes_in_one_tier_are_ambiguous(): void
    {
        $first = $this->unit('kg', null, 'Kilogram A');
        $second = $this->unit('kg', null, 'Kilogram B');

        try {
            app(UnitResolver::class)->resolve($this->company->id, 'kg', false);
            $this->fail('An exact duplicate code must not be picked arbitrarily.');
        } catch (CodedImportRowException $exception) {
            $this->assertSame(ImportErrorCode::UnitAmbiguous, $exception->errorCode);
            if (! array_key_exists('candidates', $exception->detail)) {
                $this->fail('Ambiguous-unit detail must carry candidates.');
            }
            $candidates = $exception->detail['candidates'];
            $this->assertSame([$first->id, $second->id], array_column($candidates, 'id'));
            $this->assertSame(['system', 'system'], array_column($candidates, 'tier'));
        }
    }

    public function test_blank_update_preserves_while_blank_create_defaults_to_visible_pc(): void
    {
        $pc = $this->unit('pc', null, 'Piece');
        $resolver = app(UnitResolver::class);

        $update = $resolver->resolve($this->company->id, ' ', true);
        $this->assertNull($update->unitId);
        $this->assertNull($update->unitCode);
        $this->assertNull($update->warning);

        $create = $resolver->resolve($this->company->id, '', false);
        $this->assertSame($pc->id, $create->unitId);
        $this->assertSame('pc', $create->unitCode);
        $this->assertSame(ImportWarningCode::UnitDefaulted, $create->warning);
    }

    public function test_blank_create_refuses_when_default_pc_is_not_visible(): void
    {
        $this->unit('kg', null);

        $this->expectExceptionObject(new CodedImportRowException(
            ImportErrorCode::UnitDefaultMissing,
            'Default unit pc is not visible.',
            ['supplied' => 'pc', 'accepted' => ['kg']],
        ));

        app(UnitResolver::class)->resolve($this->company->id, '', false);
    }

    public function test_tenant_added_code_is_read_live(): void
    {
        $this->unit('pc', null);
        $sachet = $this->unit('sachet', $this->tenant->id, 'Sachet');

        $resolved = app(UnitResolver::class)->resolve($this->company->id, 'sachet', false);

        $this->assertSame($sachet->id, $resolved->unitId);
        $this->assertSame('sachet', $resolved->unitCode);
    }

    public function test_company_tier_wins_over_tenant_and_system_code_collisions(): void
    {
        $resolver = new UnitResolver($this->catalog([
            $this->entry('system', 'system-id'),
            $this->entry('tenant', 'tenant-id'),
            $this->entry('company', 'company-id'),
        ]));

        $this->assertSame('company-id', $resolver->resolve($this->company->id, 'pc', false)->unitId);
    }

    public function test_tenant_tier_wins_over_ambiguous_system_code_collisions(): void
    {
        $resolver = new UnitResolver($this->catalog([
            $this->entry('system', 'system-a'),
            $this->entry('system', 'system-b'),
            $this->entry('tenant', 'tenant-id'),
        ]));

        $this->assertSame('tenant-id', $resolver->resolve($this->company->id, 'pc', false)->unitId);
    }

    public function test_ambiguity_is_reported_only_within_the_winning_tier_and_includes_category(): void
    {
        $resolver = new UnitResolver($this->catalog([
            $this->entry('system', 'system-id'),
            $this->entry('company', 'company-a', 'Quantity'),
            $this->entry('company', 'company-b', 'Packaging'),
        ]));

        try {
            $resolver->resolve($this->company->id, 'pc', false);
            $this->fail('Two company-tier matches must be ambiguous.');
        } catch (CodedImportRowException $exception) {
            $this->assertSame(ImportErrorCode::UnitAmbiguous, $exception->errorCode);
            if (! array_key_exists('candidates', $exception->detail)) {
                $this->fail('Ambiguous-unit detail must carry candidates.');
            }
            $candidates = $exception->detail['candidates'];
            $this->assertSame(['company-a', 'company-b'], array_column($candidates, 'id'));
            $this->assertSame(['Quantity', 'Packaging'], array_column($candidates, 'category'));
            $this->assertSame(['company', 'company'], array_column($candidates, 'tier'));
        }
    }

    public function test_backfill_uses_the_same_winning_tier_rule_as_import_resolution(): void
    {
        $system = $this->unit('pc', null, 'System piece');
        $tenant = $this->unit('pc', $this->tenant->id, 'Tenant piece');
        // The current schema has no company_id yet; the catalog tier is the
        // compatibility boundary. Use another persisted FK target for the fake
        // company-tier candidate without violating the legacy tenant/code key.
        $company = $this->unit('company-pc', $this->tenant->id, 'Company piece');
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => null,
            'unit' => 'pc',
        ]);
        $service = new ProductUnitBackfillService($this->catalog([
            $this->entry('system', $system->id),
            $this->entry('tenant', $tenant->id),
            $this->entry('company', $company->id),
        ]));

        $result = $service->backfill();

        $this->assertSame(1, $result->mapped);
        $this->assertSame($company->id, $product->refresh()->unit_id);
    }

    public function test_backfill_maps_unique_codes_and_counts_ambiguous_and_unknown_rows(): void
    {
        $pc = $this->unit('pc', null, 'Piece');
        $this->unit('kg', null, 'Kilogram A');
        $this->unit('kg', null, 'Kilogram B');
        $mapped = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => null,
            'unit' => ' pc ',
        ]);
        $ambiguous = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => null,
            'unit' => 'kg',
        ]);
        $unknown = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => null,
            'unit' => 'case',
        ]);

        $result = app(ProductUnitBackfillService::class)->backfill();

        $this->assertSame(1, $result->mapped);
        $this->assertSame(1, $result->ambiguous);
        $this->assertSame(1, $result->unknown);
        $this->assertSame($pc->id, $mapped->refresh()->unit_id);
        $this->assertSame('pc', $mapped->unit);
        $this->assertNull($ambiguous->refresh()->unit_id);
        $this->assertNull($unknown->refresh()->unit_id);
    }

    public function test_backfill_visits_every_row_exactly_once_across_multiple_company_pages(): void
    {
        $pc = $this->unit('pc', null, 'Piece');
        $firstCompany = Company::factory()->for($this->tenant)->create([
            'id' => '10000000-0000-4000-8000-000000000001',
        ]);
        $secondCompany = Company::factory()->for($this->tenant)->create([
            'id' => '20000000-0000-4000-8000-000000000002',
        ]);

        Product::factory()->count(501)->sequence(
            fn ($sequence): array => [
                'id' => sprintf('f0000000-0000-4000-8000-%012d', $sequence->index + 1),
                'sku' => sprintf('BACKFILL-A-%04d', $sequence->index + 1),
            ],
        )->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $firstCompany->id,
            'unit_id' => null,
            'unit' => 'pc',
        ]);
        Product::factory()->count(500)->sequence(
            fn ($sequence): array => [
                'id' => sprintf('00000000-0000-4000-8000-%012d', $sequence->index + 1),
                'sku' => sprintf('BACKFILL-B-%04d', $sequence->index + 1),
            ],
        )->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $secondCompany->id,
            'unit_id' => null,
            'unit' => 'pc',
        ]);

        $result = app(ProductUnitBackfillService::class)->backfill();

        $this->assertSame(1001, $result->mapped);
        $this->assertSame(0, $result->ambiguous);
        $this->assertSame(0, $result->unknown);
        $this->assertSame(0, $result->missingCompany);
        $this->assertSame(1001, $result->mapped + $result->ambiguous + $result->unknown + $result->missingCompany);
        $this->assertSame(
            1001,
            Product::query()
                ->whereIn('company_id', [$firstCompany->id, $secondCompany->id])
                ->where('unit_id', $pc->id)
                ->count(),
        );
    }

    private function unit(string $code, ?string $tenantId, string $name = 'Kilogram'): Unit
    {
        $factory = Unit::factory()->for($this->category, 'category');
        if ($tenantId !== null) {
            $factory = $factory->tenant($tenantId);
        }

        return $factory->create([
            'code' => $code,
            'name' => $name,
            'symbol' => $code,
        ]);
    }

    /** @param list<UnitCatalogEntryData> $entries */
    private function catalog(array $entries): UnitCatalogQueryInterface
    {
        return new class($entries) implements UnitCatalogQueryInterface
        {
            /** @param list<UnitCatalogEntryData> $entries */
            public function __construct(private readonly array $entries) {}

            public function visibleUnits(string $companyId): array
            {
                return $this->entries;
            }

            public function visibleActiveUnitCount(string $companyId): int
            {
                return count($this->entries);
            }
        };
    }

    /** @param 'system'|'tenant'|'company' $tier */
    private function entry(string $tier, string $id, string $category = 'Quantity'): UnitCatalogEntryData
    {
        return new UnitCatalogEntryData($id, 'pc', 'Piece', 'pc', 0, $tier, $category);
    }
}
