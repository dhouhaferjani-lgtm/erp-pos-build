<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Seeder;

/**
 * Read-only preview of the normal legacy provisioning baseline: the frozen
 * country seeder followed by the approved inventory-variance installer.
 *
 * The collaborator owns and always rolls back its nested transaction, so neither
 * direct calls nor calls inside a committing outer transaction can persist repairs.
 */
final class LegacyExistingChartRepairPreviewer
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly InventoryVarianceAccountProvisioner $inventoryVarianceAccounts,
    ) {}

    /**
     * @return array{int, int, int} [created, promoted, reparented]
     *
     * @throws \RuntimeException When a purpose/code collision or missing approved parent makes repair unsafe
     */
    public function preview(Company $company): array
    {
        $connection = $this->database->connection();
        $connection->beginTransaction();

        try {
            $before = $this->snapshot((string) $company->id);
            $seeder = $this->seederForCountry($company->country_code);
            /** @var TunisiaChartOfAccountsSeeder|FranceChartOfAccountsSeeder|GenericChartOfAccountsSeeder $seeder */
            $seeder->run($company->id, $company->tenant_id);
            $this->inventoryVarianceAccounts->provisionCompany(
                $company->id,
                $company->tenant_id,
                $company->country_code,
            );

            return $this->diff($before, $this->snapshot((string) $company->id));
        } finally {
            $connection->rollBack();
        }
    }

    /** @return array<string, array{is_system: bool, parent_id: string|null}> */
    private function snapshot(string $companyId): array
    {
        $rows = [];
        foreach (Account::query()->where('company_id', $companyId)->get(['id', 'is_system', 'parent_id']) as $account) {
            $rows[(string) $account->id] = [
                'is_system' => (bool) $account->is_system,
                'parent_id' => $account->parent_id === null ? null : (string) $account->parent_id,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{is_system: bool, parent_id: string|null}>  $before
     * @param  array<string, array{is_system: bool, parent_id: string|null}>  $after
     * @return array{int, int, int}
     */
    private function diff(array $before, array $after): array
    {
        $created = 0;
        $promoted = 0;
        $reparented = 0;

        foreach ($after as $id => $row) {
            if (! array_key_exists($id, $before)) {
                $created++;

                continue;
            }
            if (! $before[$id]['is_system'] && $row['is_system']) {
                $promoted++;
            }
            if ($before[$id]['parent_id'] !== $row['parent_id']) {
                $reparented++;
            }
        }

        return [$created, $promoted, $reparented];
    }

    /** @return TunisiaChartOfAccountsSeeder|FranceChartOfAccountsSeeder|GenericChartOfAccountsSeeder */
    private function seederForCountry(string $countryCode): Seeder
    {
        return match (strtoupper($countryCode)) {
            'TN' => new TunisiaChartOfAccountsSeeder,
            'FR' => new FranceChartOfAccountsSeeder,
            default => new GenericChartOfAccountsSeeder,
        };
    }
}
