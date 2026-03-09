<?php

declare(strict_types=1);

namespace App\Modules\Contact\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ContactPartyData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public ?string $job_title,
        public ?string $department,
        public bool $is_primary,
    ) {}
}
