<?php

declare(strict_types=1);

use App\Modules\CountryDefaults\Application\Services\CanonicalCoaSerializer;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Export\LegacyCoaGoldenExporter;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $serializer = new CanonicalCoaSerializer;
        $manager = DB::getFacadeRoot();
        $config = Config::getFacadeRoot();
        if (! $manager instanceof DatabaseManager || ! $config instanceof ConfigRepository) {
            throw new RuntimeException('Country Defaults bootstrap dependencies are unavailable.');
        }
        $exporter = new LegacyCoaGoldenExporter($serializer, $manager, $config);

        foreach ($this->definitions() as $definition) {
            $expected = $exporter->export($definition['fixture']);
            $rows = $this->decodeRows($expected);
            $connection = DB::connection((new AdminTemplate)->getConnectionName());

            try {
                $this->importOne($connection, $definition, $expected, $rows, $serializer);
            } catch (UniqueConstraintViolationException) {
                // Another deploy may have won the unique bootstrap-key race. Its committed
                // result earns success only after the same byte-for-byte assertion.
                $connection->transaction(function () use ($definition, $expected, $rows, $serializer): void {
                    $existing = AdminTemplate::query()
                        ->where('bootstrap_key', $definition['key'])
                        ->lockForUpdate()
                        ->first();
                    if (! $existing instanceof AdminTemplate) {
                        throw new RuntimeException("{$definition['key']} bootstrap race ended without a keyed row.");
                    }
                    $this->assertExisting($existing, $expected, count($rows), $serializer);
                });
            }
        }
    }

    public function down(): void
    {
        $keys = array_column($this->definitions(), 'key');
        $connection = DB::connection((new AdminTemplate)->getConnectionName());
        $connection->transaction(static function () use ($keys, $connection): void {
            $ids = $connection->table('admin_templates')->whereIn('bootstrap_key', $keys)->pluck('id');
            $connection->table('admin_template_accounts')->whereIn('template_id', $ids)->delete();
            $connection->table('admin_templates')->whereIn('id', $ids)->delete();
        });
    }

    /** @return list<array{key: string, fixture: string, name: string}> */
    private function definitions(): array
    {
        return [
            ['key' => 'coa.tn.legacy-v1', 'fixture' => 'tn', 'name' => 'PCN Tunisie legacy v1'],
            ['key' => 'coa.fr.legacy-v1', 'fixture' => 'fr', 'name' => 'PCG France legacy v1'],
            ['key' => 'coa.generic.legacy-v1', 'fixture' => 'generic', 'name' => 'Generic COA legacy v1'],
        ];
    }

    /** @return list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int}> */
    private function decodeRows(string $canonical): array
    {
        $rows = [];
        foreach (explode("\n", $canonical) as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Legacy canonical row is not an object.');
            }
            /** @var array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int} $decoded */
            $rows[] = $decoded;
        }

        return $rows;
    }

    private function assertExisting(
        AdminTemplate $template,
        string $expected,
        int $expectedCount,
        CanonicalCoaSerializer $serializer,
    ): void {
        $actualRows = $template->accounts()->orderBy('sort_order')->lockForUpdate()->get()->map(static fn (AdminTemplateAccount $row): array => [
            'code' => $row->code,
            'name' => $row->name,
            'type' => $row->type,
            'parent_code' => $row->parent_code,
            'system_purpose' => $row->system_purpose,
            'is_system' => $row->is_system,
            'sort_order' => $row->sort_order,
        ])->all();
        $reason = null;
        if ($template->getRawOriginal('domain') !== TemplateDomain::ChartOfAccounts->value) {
            $reason = 'wrong chart-of-accounts domain';
        } elseif ($template->getRawOriginal('status') !== TemplateStatus::Draft->value) {
            $reason = 'expected draft bootstrap status';
        } elseif (count($actualRows) !== $expectedCount) {
            $reason = "row count mismatch ({$expectedCount} expected, ".count($actualRows).' actual)';
        } elseif (! hash_equals(hash('sha256', $expected), $serializer->hash(array_values($actualRows)))) {
            $reason = 'canonical hash mismatch';
        }
        if ($reason !== null) {
            throw new RuntimeException("{$template->bootstrap_key} bootstrap assertion failed: {$reason}.");
        }
    }

    /**
     * @param  array{key: string, fixture: string, name: string}  $definition
     * @param  list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int}>  $rows
     */
    private function importOne(
        ConnectionInterface $connection,
        array $definition,
        string $expected,
        array $rows,
        CanonicalCoaSerializer $serializer,
    ): void {
        $connection->transaction(function () use ($definition, $expected, $rows, $serializer): void {
            $existing = AdminTemplate::query()
                ->where('bootstrap_key', $definition['key'])
                ->lockForUpdate()
                ->first();
            if ($existing instanceof AdminTemplate) {
                $this->assertExisting($existing, $expected, count($rows), $serializer);

                return;
            }

            AdminTemplate::withinBootstrapImport(function () use ($definition, $rows): void {
                $template = AdminTemplate::query()->forceCreate([
                    'domain' => TemplateDomain::ChartOfAccounts,
                    'name' => $definition['name'],
                    'description' => 'Immutable frozen legacy chart imported for human certification.',
                    'status' => TemplateStatus::Draft,
                    'bootstrap_key' => $definition['key'],
                ]);
                foreach ($this->insertionOrder($rows) as $row) {
                    AdminTemplateAccount::query()->create(['template_id' => $template->id, ...$row]);
                }
            });
        }, 3);
    }

    /**
     * @param  list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int}>  $rows
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int}>
     */
    private function insertionOrder(array $rows): array
    {
        $pending = [];
        foreach ($rows as $row) {
            $pending[$row['code']] = $row;
        }
        $ordered = [];
        $inserted = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $code => $row) {
                if ($row['parent_code'] !== null && ! isset($inserted[$row['parent_code']])) {
                    continue;
                }
                $ordered[] = $row;
                $inserted[$code] = true;
                unset($pending[$code]);
                $progress = true;
            }
            if (! $progress) {
                throw new RuntimeException('Legacy bootstrap hierarchy contains an unresolved parent or cycle.');
            }
        }

        return $ordered;
    }
};
