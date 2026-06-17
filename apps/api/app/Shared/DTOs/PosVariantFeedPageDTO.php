<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class PosVariantFeedPageDTO
{
    /**
     * @param  list<PosVariantData>  $variants
     * @param  list<string>  $deletedIds
     */
    public function __construct(
        public array $variants,
        public array $deletedIds,
        public int $page,
        public int $lastPage,
        public int $total,
    ) {}
}
