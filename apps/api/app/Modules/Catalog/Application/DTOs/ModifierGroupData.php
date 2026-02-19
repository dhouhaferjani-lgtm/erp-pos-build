<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Domain\Enums\SelectionType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ModifierGroupData extends Data
{
    /**
     * @param  array<int, ModifierData>|null  $modifiers
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public SelectionType $selection_type,
        public int $min_selections,
        public int $max_selections,
        public bool $is_required,
        public bool $is_active,
        public int $display_order,
        public ?array $modifiers,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(ModifierGroup $group): self
    {
        return new self(
            id: $group->id,
            code: $group->code,
            name: $group->name,
            selection_type: $group->selection_type,
            min_selections: $group->min_selections,
            max_selections: $group->max_selections,
            is_required: $group->is_required,
            is_active: $group->is_active,
            display_order: $group->display_order,
            modifiers: $group->relationLoaded('modifiers')
                ? $group->modifiers->map(fn ($m) => ModifierData::fromModel($m))->all()
                : null,
            created_at: $group->created_at?->toIso8601String() ?? '',
            updated_at: $group->updated_at?->toIso8601String(),
        );
    }
}
