<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Queries;

final readonly class FindBundleQuery
{
    public function __construct(
        public string $bundle_id,
    ) {}
}
