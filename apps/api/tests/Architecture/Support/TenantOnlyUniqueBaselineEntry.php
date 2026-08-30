<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

final readonly class TenantOnlyUniqueBaselineEntry
{
    public function __construct(
        public string $key,
        public ?string $waiver,
    ) {}
}
