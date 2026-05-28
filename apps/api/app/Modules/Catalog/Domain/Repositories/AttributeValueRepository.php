<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Repositories;

use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use Illuminate\Support\Collection;

interface AttributeValueRepository
{
    public function findById(string $id): ?ProductAttributeValue;

    /**
     * Return all values for a given attribute, ordered by display_order.
     *
     * @return Collection<int, ProductAttributeValue>
     */
    public function listForAttribute(string $attributeId): Collection;

    public function save(ProductAttributeValue $value): void;

    public function delete(string $id): void;
}
