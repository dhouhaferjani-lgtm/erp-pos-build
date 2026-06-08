<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\Commands\AddAttributeValueCommand;
use App\Modules\Catalog\Application\Commands\CreateAttributeCommand;
use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Events\ProductAttributeCreated;
use App\Modules\Catalog\Domain\Events\ProductAttributeValueAdded;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use App\Modules\Catalog\Domain\Repositories\AttributeValueRepository;
use Illuminate\Support\Collection;

final class AttributeService
{
    public function __construct(
        private readonly AttributeRepository $attributeRepo,
        private readonly AttributeValueRepository $attributeValueRepo,
    ) {}

    /**
     * Create and persist a new product attribute, then emit ProductAttributeCreated.
     */
    public function create(CreateAttributeCommand $command): ProductAttribute
    {
        $attribute = new ProductAttribute;
        $attribute->tenant_id = $command->tenantId;
        $attribute->code = $command->code;
        $attribute->name = $command->name;
        $attribute->data_type = $command->dataType;
        $attribute->is_variant_axis = $command->isVariantAxis;
        $attribute->display_order = $command->displayOrder;
        $attribute->is_active = true;

        $this->attributeRepo->save($attribute);

        event(new ProductAttributeCreated(
            attributeId: $attribute->id,
            tenantId: $attribute->tenant_id,
            code: $attribute->code,
            name: $attribute->name,
            dataType: $attribute->data_type->value,
            isVariantAxis: $attribute->is_variant_axis,
            createdAt: now()->toIso8601String(),
        ));

        return $attribute;
    }

    /**
     * Add a value to an existing attribute, then emit ProductAttributeValueAdded.
     */
    public function addValue(AddAttributeValueCommand $command): ProductAttributeValue
    {
        $value = new ProductAttributeValue;
        $value->tenant_id = $command->tenantId;
        $value->attribute_id = $command->attributeId;
        $value->code = $command->code;
        $value->label = $command->label;
        $value->hex_color = $command->hexColor;
        $value->image_url = $command->imageUrl;
        $value->display_order = $command->displayOrder;

        $this->attributeValueRepo->save($value);

        event(new ProductAttributeValueAdded(
            attributeValueId: $value->id,
            attributeId: $value->attribute_id,
            tenantId: $value->tenant_id,
            code: $value->code,
            label: $value->label,
            createdAt: now()->toIso8601String(),
        ));

        return $value;
    }

    /**
     * List all attributes visible to the current tenant (implicit via DB-per-tenant context).
     *
     * @return Collection<int, ProductAttribute>
     */
    public function listForTenant(): Collection
    {
        return $this->attributeRepo->listForTenant();
    }
}
