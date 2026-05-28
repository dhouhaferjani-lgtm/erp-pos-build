<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use Database\Factories\Catalog\ProductAttributeFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttributeRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private AttributeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(AttributeRepository::class);
    }

    public function test_repository_saves_and_finds_by_id(): void
    {
        $tenantId = (string) Str::uuid();

        $attribute = new ProductAttribute([
            'tenant_id' => $tenantId,
            'code' => 'colour',
            'name' => 'Colour',
            'data_type' => AttributeDataType::Color,
            'is_variant_axis' => true,
            'display_order' => 1,
            'is_active' => true,
        ]);

        $this->repository->save($attribute);

        $found = $this->repository->findById($attribute->id);

        $this->assertNotNull($found);
        $this->assertSame($attribute->id, $found->id);
        $this->assertSame('colour', $found->code);
        $this->assertSame(AttributeDataType::Color, $found->data_type);
    }

    public function test_lists_only_variant_axes_when_flag_set(): void
    {
        $tenantId = (string) Str::uuid();

        ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'size',
            'is_variant_axis' => true,
        ]);

        ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'material',
            'is_variant_axis' => false,
        ]);

        $variantAxes = $this->repository->listForTenant(onlyVariantAxes: true);

        $this->assertCount(1, $variantAxes);
        $this->assertSame('size', $variantAxes->first()->code);
    }

    public function test_tenant_code_unique(): void
    {
        $this->expectException(QueryException::class);

        $tenantId = (string) Str::uuid();

        ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'dup-code',
        ]);

        ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'dup-code',
        ]);
    }
}
