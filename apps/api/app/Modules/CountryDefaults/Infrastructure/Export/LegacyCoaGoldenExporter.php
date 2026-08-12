<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Infrastructure\Export;

use App\Modules\CountryDefaults\Application\Services\CanonicalCoaSerializer;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class LegacyCoaGoldenExporter
{
    private const SCRATCH_CONNECTION = 'country_defaults_legacy_scratch';

    public function __construct(
        private readonly CanonicalCoaSerializer $serializer,
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function export(string $country): string
    {
        return $this->exportLegacyDefinitions($country);
    }

    public function exportLegacyDefinitions(string $country): string
    {
        $normalized = strtolower(trim($country));
        $seeder = match ($normalized) {
            'tn' => new TunisiaChartOfAccountsSeeder,
            'fr' => new FranceChartOfAccountsSeeder,
            'generic', '*' => new GenericChartOfAccountsSeeder,
            default => throw new InvalidArgumentException("Unknown legacy COA fixture {$country}."),
        };

        $originalDefault = $this->database->getDefaultConnection();
        $this->configureScratchConnection();

        try {
            $this->database->setDefaultConnection(self::SCRATCH_CONNECTION);
            $connection = $this->database->connection(self::SCRATCH_CONNECTION);
            $this->createScratchAccountsTable($connection->getDriverName());
            $this->runSeeder($seeder);

            $rows = $connection->table('accounts as account')
                ->leftJoin('accounts as parent', 'parent.id', '=', 'account.parent_id')
                ->orderBy('account.scratch_order')
                ->get([
                    'account.code',
                    'account.name',
                    'account.type',
                    'parent.code as parent_code',
                    'account.system_purpose',
                    'account.is_system',
                    'account.scratch_order as sort_order',
                ])
                ->map(static fn (object $row): array => [
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'type' => (string) $row->type,
                    'parent_code' => $row->parent_code === null ? null : (string) $row->parent_code,
                    'system_purpose' => $row->system_purpose === null ? null : (string) $row->system_purpose,
                    'is_system' => (bool) $row->is_system,
                ])
                ->all();

            return $this->serializer->serialize(
                $this->serializer->withLegacyInsertionOrder(array_values($rows)),
            );
        } finally {
            $this->database->setDefaultConnection($originalDefault);
            $this->database->purge(self::SCRATCH_CONNECTION);
            $this->config->offsetUnset('database.connections.'.self::SCRATCH_CONNECTION);
        }
    }

    public function exportPersistedTemplate(AdminTemplate $template): string
    {
        $rows = $template->accounts()
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (AdminTemplateAccount $row): array => [
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'parent_code' => $row->parent_code,
                'system_purpose' => $row->system_purpose,
                'is_system' => $row->is_system,
                'sort_order' => $row->sort_order,
            ])
            ->all();

        return $this->serializer->serialize(array_values($rows));
    }

    private function configureScratchConnection(): void
    {
        $central = $this->database->connection((string) $this->config->get('tenancy.database.central_connection', 'central'));
        $connection = $central->getConfig();
        if (($connection['driver'] ?? null) === 'sqlite') {
            $connection['database'] = ':memory:';
            $connection['foreign_key_constraints'] = true;
        }
        $connection['name'] = self::SCRATCH_CONNECTION;
        $this->config->set('database.connections.'.self::SCRATCH_CONNECTION, $connection);
        $this->database->purge(self::SCRATCH_CONNECTION);
    }

    private function createScratchAccountsTable(string $driver): void
    {
        $connection = $this->database->connection(self::SCRATCH_CONNECTION);
        if ($driver === 'pgsql') {
            $connection->statement(<<<'SQL'
                CREATE TEMPORARY TABLE accounts (
                    scratch_order BIGSERIAL PRIMARY KEY,
                    id UUID NOT NULL UNIQUE,
                    tenant_id UUID NULL,
                    company_id UUID NOT NULL,
                    parent_id UUID NULL,
                    code VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    type VARCHAR(32) NOT NULL,
                    system_purpose VARCHAR(255) NULL,
                    is_active BOOLEAN NOT NULL,
                    is_system BOOLEAN NOT NULL,
                    balance NUMERIC(19, 4) NOT NULL,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL
                ) ON COMMIT PRESERVE ROWS
            SQL);

            return;
        }
        if ($driver !== 'sqlite') {
            throw new RuntimeException("Legacy COA scratch export does not support {$driver}.");
        }

        $connection->statement(<<<'SQL'
            CREATE TABLE accounts (
                scratch_order INTEGER PRIMARY KEY AUTOINCREMENT,
                id TEXT NOT NULL UNIQUE,
                tenant_id TEXT NULL,
                company_id TEXT NOT NULL,
                parent_id TEXT NULL,
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                system_purpose TEXT NULL,
                is_active INTEGER NOT NULL,
                is_system INTEGER NOT NULL,
                balance NUMERIC NOT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )
        SQL);
    }

    private function runSeeder(
        TunisiaChartOfAccountsSeeder|FranceChartOfAccountsSeeder|GenericChartOfAccountsSeeder $seeder,
    ): void {
        try {
            $seeder->run(Str::uuid()->toString(), Str::uuid()->toString());
        } catch (Throwable $exception) {
            throw new RuntimeException('Frozen legacy COA seeder failed in its isolated scratch database.', previous: $exception);
        }
    }
}
