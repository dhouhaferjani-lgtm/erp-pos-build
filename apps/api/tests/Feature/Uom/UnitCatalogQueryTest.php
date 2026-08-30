<?php

declare(strict_types=1);

namespace Tests\Feature\Uom;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Shared\Contracts\UnitCatalogQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UnitCatalogQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_interface_returns_the_visible_active_vocabulary_in_deterministic_order(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $otherTenant = Tenant::factory()->create();
        $category = UnitCategory::factory()->create();

        $tenantUnit = Unit::factory()->tenant($tenant->id)->for($category, 'category')->create([
            'code' => 'KG',
            'name' => 'Tenant kilogram',
            'symbol' => 'KG',
            'decimal_places' => 3,
        ]);
        $systemGram = Unit::factory()->for($category, 'category')->create([
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'decimal_places' => 2,
        ]);
        $systemKilogram = Unit::factory()->for($category, 'category')->create([
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'decimal_places' => 4,
        ]);
        Unit::factory()->tenant($otherTenant->id)->for($category, 'category')->create(['code' => 'hidden']);
        Unit::factory()->tenant($tenant->id)->inactive()->for($category, 'category')->create(['code' => 'inactive']);

        $catalog = app(UnitCatalogQueryInterface::class);
        $entries = $catalog->visibleUnits($company->id);

        $this->assertSame(['KG', 'g', 'kg'], array_map(
            static fn ($entry): string => $entry->code,
            $entries,
        ));
        $this->assertSame($tenantUnit->id, $entries[0]->id);
        $this->assertSame('tenant', $entries[0]->tier);
        $this->assertSame($systemGram->id, $entries[1]->id);
        $this->assertSame('system', $entries[1]->tier);
        $this->assertSame(2, $entries[1]->decimalPlaces);
        $this->assertSame($systemKilogram->id, $entries[2]->id);
        $this->assertSame(count($entries), $catalog->visibleActiveUnitCount($company->id));
    }
}
