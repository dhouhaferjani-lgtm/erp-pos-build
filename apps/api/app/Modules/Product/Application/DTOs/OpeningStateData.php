<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class OpeningStateData extends Data
{
    public function __construct(
        public bool $has_active_opening,
        public bool $has_downstream_movements,
        public bool $can_enter_opening,
    ) {}

    public static function fromFlags(bool $hasActiveOpening, bool $hasDownstream): self
    {
        return new self($hasActiveOpening, $hasDownstream, ! $hasActiveOpening && ! $hasDownstream);
    }
}
