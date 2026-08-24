<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use App\Shared\Contracts\ProductServiceInterface;
use App\Shared\Enums\CategoryResolutionOutcome;

/**
 * Outcome of resolving a free-text category name to a local `categories` row.
 *
 * Crosses the module boundary via {@see ProductServiceInterface},
 * so it carries the row id (bigint PK) rather than the Category model.
 */
final readonly class CategoryResolutionDTO
{
    public function __construct(
        public int $categoryId,
        /** The RESOLVED category's own name — which differs from the incoming
         *  value on a MatchedBySlug, and is what the operator needs to see. */
        public string $categoryName,
        public CategoryResolutionOutcome $outcome,
    ) {}
}
