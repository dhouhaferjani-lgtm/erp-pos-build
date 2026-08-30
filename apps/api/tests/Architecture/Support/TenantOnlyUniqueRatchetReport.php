<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

final readonly class TenantOnlyUniqueRatchetReport
{
    /**
     * @param  list<string>  $growth
     * @param  list<string>  $stale
     */
    public function __construct(
        public array $growth,
        public array $stale,
    ) {}

    /** @return list<string> */
    public function violations(): array
    {
        return [...$this->growth, ...$this->stale];
    }

    public function message(): string
    {
        $message = '';
        if ($this->growth !== []) {
            $message .= "\nTENANT-ONLY UNIQUE GROWTH:\n  ".implode("\n  ", $this->growth)."\n";
        }
        if ($this->stale !== []) {
            $message .= "\nSTALE TENANT-ONLY UNIQUE BASELINE:\n  ".implode("\n  ", $this->stale)."\n";
        }

        return $message;
    }
}
