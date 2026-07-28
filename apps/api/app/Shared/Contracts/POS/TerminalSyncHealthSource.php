<?php

declare(strict_types=1);

namespace App\Shared\Contracts\POS;

interface TerminalSyncHealthSource
{
    /**
     * @param  list<string>  $locationIds
     * @return list<array{id: string, code: string, name: string, location_id: string}>
     */
    public function physicalAtLocations(string $companyId, array $locationIds): array;

    /** @return array{id: string, code: string, name: string, location_id: string}|null */
    public function physicalForDevice(
        string $companyId,
        string $terminalId,
        string $hardwareIdentifier,
    ): ?array;
}
