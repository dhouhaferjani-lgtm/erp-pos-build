<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Services;

use App\Modules\Channel\Application\Contracts\ChannelAdapter;
use Illuminate\Support\Collection;
use RuntimeException;

final class AdapterRegistry
{
    /**
     * @var array<string, ChannelAdapter>
     */
    private array $adapters = [];

    public function register(string $adapterType, ChannelAdapter $adapter): void
    {
        if ($adapter->adapterType() !== $adapterType) {
            throw new RuntimeException(sprintf(
                'Adapter registry mismatch: requested %s but adapter reports %s.',
                $adapterType,
                $adapter->adapterType(),
            ));
        }

        $this->adapters[$adapterType] = $adapter;
    }

    public function resolve(string $adapterType): ChannelAdapter
    {
        if (! isset($this->adapters[$adapterType])) {
            throw new RuntimeException(sprintf(
                'No adapter registered for type %s. Concrete adapters ship in a follow-up sprint.',
                $adapterType,
            ));
        }

        return $this->adapters[$adapterType];
    }

    public function has(string $adapterType): bool
    {
        return isset($this->adapters[$adapterType]);
    }

    /**
     * @return Collection<int, string>
     */
    public function listRegistered(): Collection
    {
        return collect(array_keys($this->adapters))->values();
    }
}
