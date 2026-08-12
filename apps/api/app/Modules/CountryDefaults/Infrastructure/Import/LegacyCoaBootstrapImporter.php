<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Infrastructure\Import;

use App\Modules\CountryDefaults\Application\Services\CanonicalCoaSerializer;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Export\LegacyCoaGoldenExporter;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class LegacyCoaBootstrapImporter
{
    private const DESCRIPTION = 'Immutable frozen legacy chart imported for human certification.';

    public function __construct(
        private readonly CanonicalCoaSerializer $serializer,
        private readonly LegacyCoaGoldenExporter $exporter,
    ) {}

    /** @return array<string, 'created'|'recovered_unique_conflict'|'verified_existing'> */
    public function importAll(): array
    {
        $outcomes = [];
        foreach ($this->definitions() as $definition) {
            $expected = $this->exporter->exportLegacyDefinitions($definition['fixture']);
            $rows = $this->decodeRows($expected);
            $connection = $this->centralConnection();

            try {
                $outcomes[$definition['key']] = $this->importOne(
                    $connection,
                    $definition,
                    $expected,
                    $rows,
                );
            } catch (UniqueConstraintViolationException) {
                $connection->transaction(function () use ($definition, $expected, $rows): void {
                    $existing = AdminTemplate::query()
                        ->where('bootstrap_key', $definition['key'])
                        ->lockForUpdate()
                        ->first();
                    if (! $existing instanceof AdminTemplate) {
                        throw new RuntimeException("{$definition['key']} bootstrap race ended without a keyed row.");
                    }
                    $this->assertExisting($existing, $expected, count($rows));
                });
                $outcomes[$definition['key']] = 'recovered_unique_conflict';
            }
        }

        return $outcomes;
    }

    public function removeUntouchedDrafts(): void
    {
        $definitions = $this->definitions();
        $connection = $this->centralConnection();
        $connection->transaction(function () use ($connection, $definitions): void {
            $keys = array_column($definitions, 'key');
            $templates = AdminTemplate::query()
                ->whereIn('bootstrap_key', $keys)
                ->orderBy('bootstrap_key')
                ->lockForUpdate()
                ->get()
                ->keyBy('bootstrap_key');

            $ids = [];
            foreach ($definitions as $definition) {
                $template = $templates->get($definition['key']);
                if (! $template instanceof AdminTemplate) {
                    continue;
                }
                $expected = $this->exporter->exportLegacyDefinitions($definition['fixture']);
                $rows = $this->decodeRows($expected);
                $this->assertRemovable($template, $definition, $expected, count($rows));
                $ids[] = $template->id;
            }

            if ($ids === []) {
                return;
            }
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

    private function assertExisting(AdminTemplate $template, string $expected, int $expectedCount): void
    {
        $actualRows = $template->accounts()->orderBy('sort_order')->lockForUpdate()->get()->map(
            static fn (AdminTemplateAccount $row): array => [
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'parent_code' => $row->parent_code,
                'system_purpose' => $row->system_purpose,
                'is_system' => $row->is_system,
                'sort_order' => $row->sort_order,
            ],
        )->all();
        $reason = null;
        if ($template->getRawOriginal('domain') !== TemplateDomain::ChartOfAccounts->value) {
            $reason = 'wrong chart-of-accounts domain';
        } elseif ($template->getRawOriginal('status') !== TemplateStatus::Draft->value) {
            $reason = 'expected draft bootstrap status';
        } elseif (count($actualRows) !== $expectedCount) {
            $reason = "row count mismatch ({$expectedCount} expected, ".count($actualRows).' actual)';
        } elseif (! hash_equals(hash('sha256', $expected), $this->serializer->hash(array_values($actualRows)))) {
            $reason = 'canonical hash mismatch';
        }
        if ($reason !== null) {
            throw new RuntimeException("{$template->bootstrap_key} bootstrap assertion failed: {$reason}.");
        }
    }

    /**
     * @param  array{key: string, fixture: string, name: string}  $definition
     * @param  list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int}>  $rows
     * @return 'created'|'verified_existing'
     */
    private function importOne(
        ConnectionInterface $connection,
        array $definition,
        string $expected,
        array $rows,
    ): string {
        return $connection->transaction(function () use ($connection, $definition, $expected, $rows): string {
            $existing = AdminTemplate::query()
                ->where('bootstrap_key', $definition['key'])
                ->lockForUpdate()
                ->first();
            if ($existing instanceof AdminTemplate) {
                $this->assertExisting($existing, $expected, count($rows));

                return 'verified_existing';
            }

            $templateId = Str::uuid()->toString();
            $now = now();
            $connection->table('admin_templates')->insert([
                'id' => $templateId,
                'domain' => TemplateDomain::ChartOfAccounts->value,
                'name' => $definition['name'],
                'description' => self::DESCRIPTION,
                'status' => TemplateStatus::Draft->value,
                'bootstrap_key' => $definition['key'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($this->insertionOrder($rows) as $row) {
                $connection->table('admin_template_accounts')->insert([
                    'id' => Str::uuid()->toString(),
                    'template_id' => $templateId,
                    ...$row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return 'created';
        }, 3);
    }

    /** @param array{key: string, fixture: string, name: string} $definition */
    private function assertRemovable(
        AdminTemplate $template,
        array $definition,
        string $expected,
        int $expectedCount,
    ): void {
        $raw = $template->getRawOriginal();
        $untouched = $raw['domain'] === TemplateDomain::ChartOfAccounts->value
            && $raw['status'] === TemplateStatus::Draft->value
            && $raw['name'] === $definition['name']
            && $raw['description'] === self::DESCRIPTION
            && $raw['cloned_from_id'] === null
            && $raw['content_hash'] === null
            && $raw['standard_ref'] === null
            && $raw['certified_country_codes'] === null
            && $raw['capability_registry_version'] === null
            && $raw['certified_by'] === null
            && $raw['published_at'] === null
            && $raw['created_by'] === null;
        if (! $untouched) {
            throw new RuntimeException("{$definition['key']} is not an untouched draft; rollback refused.");
        }

        if ($template->assignments()->lockForUpdate()->exists()) {
            throw new RuntimeException("{$definition['key']} is assigned; rollback refused.");
        }
        if (AdminTemplate::query()->where('cloned_from_id', $template->id)->lockForUpdate()->exists()) {
            throw new RuntimeException("{$definition['key']} has clone provenance; rollback refused.");
        }

        $this->assertExisting($template, $expected, $expectedCount);
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

    private function centralConnection(): ConnectionInterface
    {
        return DB::connection((new AdminTemplate)->getConnectionName());
    }
}
