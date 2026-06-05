<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Repositories;

use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Repositories\AttributeValueRepository;
use Illuminate\Support\Collection;

final readonly class EloquentAttributeValueRepository implements AttributeValueRepository
{
    public function findById(string $id): ?ProductAttributeValue
    {
        return ProductAttributeValue::find($id);
    }

    /**
     * @return Collection<int, ProductAttributeValue>
     */
    public function listForAttribute(string $attributeId): Collection
    {
        return ProductAttributeValue::where('attribute_id', $attributeId)
            ->orderBy('display_order')
            ->get();
    }

    public function save(ProductAttributeValue $value): void
    {
        $value->save();
    }

    public function delete(string $id): void
    {
        $value = $this->findById($id);

        if ($value !== null) {
            $value->delete();
        }
    }
}
