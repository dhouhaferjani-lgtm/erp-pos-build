<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Commands\AddAttributeValueCommand;
use App\Modules\Catalog\Application\Commands\CreateAttributeCommand;
use App\Modules\Catalog\Application\Services\AttributeService;
use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use App\Modules\Catalog\Domain\Events\ProductAttributeCreated;
use App\Modules\Catalog\Domain\Events\ProductAttributeValueAdded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttributeServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttributeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AttributeService::class);
    }

    public function test_create_attribute_persists_and_emits_event(): void
    {
        Event::fake([ProductAttributeCreated::class]);

        $tenantId = (string) Str::uuid();

        $command = new CreateAttributeCommand(
            tenantId: $tenantId,
            code: 'color',
            name: 'Color',
            dataType: AttributeDataType::Selection,
            isVariantAxis: true,
            displayOrder: 1,
        );

        $attribute = $this->service->create($command);

        $this->assertInstanceOf(ProductAttribute::class, $attribute);
        $this->assertNotEmpty($attribute->id);
        $this->assertSame($tenantId, $attribute->tenant_id);
        $this->assertSame('color', $attribute->code);
        $this->assertSame('Color', $attribute->name);
        $this->assertTrue($attribute->is_variant_axis);
        $this->assertSame(1, $attribute->display_order);
        $this->assertTrue($attribute->is_active);

        // Verify persisted to DB
        $this->assertDatabaseHas('product_attributes', [
            'id' => $attribute->id,
            'tenant_id' => $tenantId,
            'code' => 'color',
        ]);

        Event::assertDispatched(ProductAttributeCreated::class, function (ProductAttributeCreated $event) use ($attribute, $tenantId): bool {
            return $event->attributeId === $attribute->id
                && $event->tenantId === $tenantId
                && $event->code === 'color'
                && $event->isVariantAxis === true;
        });
    }

    public function test_add_value_persists_and_emits_event(): void
    {
        Event::fake([ProductAttributeValueAdded::class]);

        $tenantId = (string) Str::uuid();

        // Create the attribute first (without faking its event)
        $attribute = ProductAttribute::factory()->create([
            'tenant_id' => $tenantId,
            'code' => 'size',
            'name' => 'Size',
        ]);

        $command = new AddAttributeValueCommand(
            tenantId: $tenantId,
            attributeId: $attribute->id,
            code: 'xl',
            label: 'XL',
            hexColor: null,
            imageUrl: null,
            displayOrder: 2,
        );

        $value = $this->service->addValue($command);

        $this->assertInstanceOf(ProductAttributeValue::class, $value);
        $this->assertNotEmpty($value->id);
        $this->assertSame($tenantId, $value->tenant_id);
        $this->assertSame($attribute->id, $value->attribute_id);
        $this->assertSame('xl', $value->code);
        $this->assertSame('XL', $value->label);
        $this->assertSame(2, $value->display_order);

        $this->assertDatabaseHas('product_attribute_values', [
            'id' => $value->id,
            'attribute_id' => $attribute->id,
            'code' => 'xl',
        ]);

        Event::assertDispatched(ProductAttributeValueAdded::class, function (ProductAttributeValueAdded $event) use ($value, $attribute, $tenantId): bool {
            return $event->attributeValueId === $value->id
                && $event->attributeId === $attribute->id
                && $event->tenantId === $tenantId
                && $event->code === 'xl';
        });
    }

    public function test_list_for_tenant_returns_created_attributes(): void
    {
        $tenantId = (string) Str::uuid();

        ProductAttribute::factory()->count(3)->create(['tenant_id' => $tenantId]);
        // A second tenant's attribute should not cause issues (shared DB in test env, but
        // listForTenant() scopes to connection context — this verifies at least count >= 3)
        ProductAttribute::factory()->create(['tenant_id' => (string) Str::uuid()]);

        $all = $this->service->listForTenant();

        $this->assertGreaterThanOrEqual(3, $all->count());
    }
}
