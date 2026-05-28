<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Repositories;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use Illuminate\Support\Collection;

interface AttributeRepository
{
    public function findById(string $id): ?ProductAttribute;

    public function findByCode(string $code): ?ProductAttribute;

    /**
     * @return Collection<int, ProductAttribute>
     */
    public function listForTenant(bool $onlyVariantAxes = false): Collection;

    public function save(ProductAttribute $attribute): void;

    public function softDelete(string $id): void;
}
