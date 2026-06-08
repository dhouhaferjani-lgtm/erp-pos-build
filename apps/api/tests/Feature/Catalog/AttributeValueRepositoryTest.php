<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use App\Modules\Catalog\Domain\Repositories\AttributeValueRepository;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributeValueRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private AttributeValueRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(AttributeValueRepository::class);
    }

    public function test_value_belongs_to_attribute(): void
    {
        $attr = ProductAttributeFactory::new()->create([
            'data_type' => AttributeDataType::Color,
        ]);

        $val = ProductAttributeValueFactory::new()->create([
            'attribute_id' => $attr->id,
            'tenant_id' => $attr->tenant_id,
            'code' => 'noir',
            'label' => 'Noir',
            'hex_color' => '#000000',
        ]);

        $this->assertSame($attr->id, $val->attribute_id);
        $this->assertSame('#000000', $val->hex_color);

        $found = $this->repository->findById($val->id);
        $this->assertNotNull($found);
        $this->assertSame('noir', $found->code);
        $this->assertSame($attr->id, $found->attribute_id);
    }

    public function test_value_unique_code_per_attribute(): void
    {
        $this->expectException(QueryException::class);

        $attr = ProductAttributeFactory::new()->create();

        ProductAttributeValueFactory::new()->create([
            'attribute_id' => $attr->id,
            'tenant_id' => $attr->tenant_id,
            'code' => 'dup',
        ]);

        ProductAttributeValueFactory::new()->create([
            'attribute_id' => $attr->id,
            'tenant_id' => $attr->tenant_id,
            'code' => 'dup',
        ]);
    }

    public function test_invalid_hex_color_rejected_by_db(): void
    {
        $this->expectException(QueryException::class);

        ProductAttributeValueFactory::new()->create([
            'hex_color' => 'invalid',
        ]);
    }

    public function test_list_for_attribute_returns_ordered_values(): void
    {
        $attr = ProductAttributeFactory::new()->create();

        ProductAttributeValueFactory::new()->create([
            'attribute_id' => $attr->id,
            'tenant_id' => $attr->tenant_id,
            'code' => 'b',
            'display_order' => 2,
        ]);

        ProductAttributeValueFactory::new()->create([
            'attribute_id' => $attr->id,
            'tenant_id' => $attr->tenant_id,
            'code' => 'a',
            'display_order' => 1,
        ]);

        $values = $this->repository->listForAttribute($attr->id);

        $this->assertCount(2, $values);
        $this->assertSame('a', $values->first()->code);
        $this->assertSame('b', $values->last()->code);
    }

    public function test_delete_removes_value(): void
    {
        $val = ProductAttributeValueFactory::new()->create();

        $this->repository->delete($val->id);

        $this->assertNull($this->repository->findById($val->id));
    }
}
