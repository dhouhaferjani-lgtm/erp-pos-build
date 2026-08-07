<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Services;

use Illuminate\Contracts\Config\Repository;
use LogicException;

final class SupportAccessConfigurationValidator
{
    public function __construct(private readonly Repository $config) {}

    public function validate(): void
    {
        $maxWindowHours = $this->config->get('support_access.max_grant_window_hours');
        if (! is_int($maxWindowHours) || $maxWindowHours < 1) {
            throw new LogicException('support_access.max_grant_window_hours must be a positive integer.');
        }

        $this->stringList('write_guard.readonly_post_routes');
        $this->stringList('write_guard.hard_block_route_patterns');

        foreach ($this->stringList('write_guard.hard_block_path_patterns') as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new LogicException(
                    'support_access.write_guard.hard_block_path_patterns contains an invalid regular expression.',
                );
            }
        }
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        $value = $this->config->get('support_access.'.$key);
        if (! is_array($value)) {
            throw new LogicException("support_access.{$key} must be a list of non-empty strings.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new LogicException("support_access.{$key} must be a list of non-empty strings.");
            }
        }

        return array_values($value);
    }
}
