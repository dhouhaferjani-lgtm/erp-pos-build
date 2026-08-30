<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

final readonly class TenantOnlyUniqueIndex
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $tableName,
        public string $indexName,
        public array $columns,
        public string $definition,
    ) {}

    public function key(): string
    {
        return implode('|', [
            $this->tableName,
            $this->indexName,
            implode(',', $this->columns),
        ]);
    }
}
