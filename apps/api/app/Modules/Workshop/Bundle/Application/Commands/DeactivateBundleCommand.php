<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Commands;

final readonly class DeactivateBundleCommand
{
    public function __construct(
        public string $bundle_id,
    ) {}
}
