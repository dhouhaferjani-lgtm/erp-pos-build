<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\KeyComponent;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProductKeyComponentData extends Data
{
    public function __construct(
        public KeyComponentData $key_component,
        public int $order,
    ) {}

    public static function fromPivot(KeyComponent $keyComponent, Pivot $pivot): self
    {
        return new self(
            key_component: KeyComponentData::fromModel($keyComponent),
            order: $pivot->order ?? 0,
        );
    }
}
