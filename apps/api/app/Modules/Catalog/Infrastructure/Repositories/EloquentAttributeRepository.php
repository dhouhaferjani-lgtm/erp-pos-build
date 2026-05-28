<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Repositories;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use Illuminate\Support\Collection;

final readonly class EloquentAttributeRepository implements AttributeRepository
{
    public function findById(string $id): ?ProductAttribute
    {
        return ProductAttribute::find($id);
    }

    public function findByCode(string $code): ?ProductAttribute
    {
        return ProductAttribute::where('code', $code)->first();
    }

    /**
     * @return Collection<int, ProductAttribute>
     */
    public function listForTenant(bool $onlyVariantAxes = false): Collection
    {
        $query = ProductAttribute::query();

        if ($onlyVariantAxes) {
            $query->where('is_variant_axis', true);
        }

        return $query->orderBy('display_order')->get();
    }

    public function save(ProductAttribute $attribute): void
    {
        $attribute->save();
    }

    public function softDelete(string $id): void
    {
        $attribute = $this->findById($id);

        if ($attribute !== null) {
            $attribute->delete();
        }
    }
}
