<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\POS\TerminalSyncHealthSource;

final class TerminalSyncHealthSourceService implements TerminalSyncHealthSource
{
    public function physicalAtLocations(string $companyId, array $locationIds): array
    {
        $terminals = Terminal::query()
            ->forCompany($companyId)
            ->physical()
            ->whereIn('location_id', $locationIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'location_id'])
            ->map(static fn (Terminal $terminal): array => [
                'id' => $terminal->id,
                'code' => $terminal->code,
                'name' => $terminal->name,
                'location_id' => $terminal->location_id,
            ])
            ->all();

        return array_values($terminals);
    }

    public function physicalForDevice(
        string $companyId,
        string $terminalId,
        string $hardwareIdentifier,
    ): ?array {
        $terminal = Terminal::query()
            ->forCompany($companyId)
            ->physical()
            ->where('hardware_identifier', $hardwareIdentifier)
            ->find($terminalId);

        if ($terminal === null) {
            return null;
        }

        return [
            'id' => $terminal->id,
            'code' => $terminal->code,
            'name' => $terminal->name,
            'location_id' => $terminal->location_id,
        ];
    }
}
