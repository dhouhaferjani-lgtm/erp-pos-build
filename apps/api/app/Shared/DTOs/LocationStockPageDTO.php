<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

/**
 * One page of the location stock feed (LocationStockReader::read()).
 *
 * Stock rows are paginated; the incoming set is NOT — it is the complete
 * set for the location on page 1 and an empty list on every later page.
 */
final readonly class LocationStockPageDTO
{
    /**
     * @param  list<LocationStockRowDTO>  $stock
     * @param  list<LocationIncomingRowDTO>  $incoming  Complete set on page 1, [] on later pages
     */
    public function __construct(
        public array $stock,
        public array $incoming,
        public int $page,
        public int $lastPage,
        public int $total,
    ) {}
}
