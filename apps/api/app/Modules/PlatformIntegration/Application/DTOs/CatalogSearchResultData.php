<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CatalogSearchResultData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $articles
     * @param  array<string, mixed>|null  $pagination
     */
    public function __construct(
        public array $articles,
        public ?array $pagination,
    ) {}
}
