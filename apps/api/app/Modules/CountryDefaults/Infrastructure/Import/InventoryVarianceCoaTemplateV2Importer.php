<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Infrastructure\Import;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports the treasury-approved Option A chart versions without modifying the
 * fingerprint-locked legacy seeders or their immutable v1 bootstrap templates.
 */
final class InventoryVarianceCoaTemplateV2Importer
{
    private const DESCRIPTION = 'Legacy v1 chart plus treasury-approved Option A inventory variance purposes (6586/7586).';

    /** @return array<string, 'created'|'verified_existing'> */
    public function importAll(): array
    {
        $outcomes = [];
        foreach ($this->definitions() as $definition) {
            $outcomes[$definition['key']] = $this->importOne($this->centralConnection(), $definition);
        }

        return $outcomes;
    }

    public function removeUntouchedDrafts(): void
    {
        $connection = $this->centralConnection();
        $connection->transaction(function () use ($connection): void {
            $ids = [];
            foreach (array_reverse($this->definitions()) as $definition) {
                $template = AdminTemplate::query()->where('bootstrap_key', $definition['key'])->lockForUpdate()->first();
                if (! $template instanceof AdminTemplate) {
                    continue;
                }
                $source = AdminTemplate::query()->where('bootstrap_key', $definition['source_key'])->lockForUpdate()->firstOrFail();
                $this->assertExisting($template, $source, $definition);
                if ($template->assignments()->lockForUpdate()->exists()) {
                    throw new RuntimeException("{$definition['key']} is assigned; rollback refused.");
                }
                if (AdminTemplate::query()->where('cloned_from_id', $template->id)->lockForUpdate()->exists()) {
                    throw new RuntimeException("{$definition['key']} has clone provenance; rollback refused.");
                }
                $ids[] = $template->id;
            }
            if ($ids !== []) {
                $connection->table('admin_template_accounts')->whereIn('template_id', $ids)->delete();
                $connection->table('admin_templates')->whereIn('id', $ids)->delete();
            }
        });
    }

    /** @return list<array{key: string, source_key: string, name: string, expense_parent: string, gain_parent: string, expense_name: string, gain_name: string}> */
    private function definitions(): array
    {
        return [
            [
                'key' => 'coa.tn.default-v2',
                'source_key' => 'coa.tn.legacy-v1',
                'name' => 'PCN Tunisie default v2',
                'expense_parent' => '65',
                'gain_parent' => '75',
                'expense_name' => "Écarts d'inventaire — manquants et pertes",
                'gain_name' => "Écarts d'inventaire — excédents",
            ],
            [
                'key' => 'coa.fr.default-v2',
                'source_key' => 'coa.fr.legacy-v1',
                'name' => 'PCG France default v2',
                'expense_parent' => '65',
                'gain_parent' => '75',
                'expense_name' => "Écarts d'inventaire — manquants et pertes",
                'gain_name' => "Écarts d'inventaire — excédents",
            ],
            [
                'key' => 'coa.generic.default-v2',
                'source_key' => 'coa.generic.legacy-v1',
                'name' => 'Generic COA default v2',
                'expense_parent' => '6000',
                'gain_parent' => '7000',
                'expense_name' => 'Inventory Shrinkage Expense',
                'gain_name' => 'Inventory Count Gain',
            ],
        ];
    }

    /**
     * @param  array{key: string, source_key: string, name: string, expense_parent: string, gain_parent: string, expense_name: string, gain_name: string}  $definition
     * @return 'created'|'verified_existing'
     */
    private function importOne(ConnectionInterface $connection, array $definition): string
    {
        return $connection->transaction(function () use ($connection, $definition): string {
            $source = AdminTemplate::query()->where('bootstrap_key', $definition['source_key'])->lockForUpdate()->first();
            if (! $source instanceof AdminTemplate) {
                throw new RuntimeException("{$definition['key']} requires missing source {$definition['source_key']}.");
            }
            $existing = AdminTemplate::query()->where('bootstrap_key', $definition['key'])->lockForUpdate()->first();
            if ($existing instanceof AdminTemplate) {
                $this->assertExisting($existing, $source, $definition);

                return 'verified_existing';
            }

            $rows = $this->expectedRows($source, $definition);
            $templateId = Str::uuid()->toString();
            $now = now();
            $connection->table('admin_templates')->insert([
                'id' => $templateId,
                'domain' => TemplateDomain::ChartOfAccounts->value,
                'name' => $definition['name'],
                'description' => self::DESCRIPTION,
                'status' => TemplateStatus::Draft->value,
                'bootstrap_key' => $definition['key'],
                // Bootstrap provenance is carried by the immutable key and the
                // exact parity assertion below. Keep this null so the ordinary
                // UI clone graph does not make the frozen v1 migration appear
                // user-referenced during independent rollback diagnostics.
                'cloned_from_id' => null,
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

    /**
     * @param  array{expense_parent: string, gain_parent: string, expense_name: string, gain_name: string}  $definition
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int}>
     */
    private function expectedRows(AdminTemplate $source, array $definition): array
    {
        $rows = $this->rows($source);
        if ($rows === []) {
            throw new RuntimeException("{$source->bootstrap_key} cannot derive Option A from an empty chart.");
        }
        foreach ([SystemAccountPurpose::InventoryShrinkageExpense, SystemAccountPurpose::InventoryGainIncome] as $purpose) {
            if (in_array($purpose->value, array_column($rows, 'system_purpose'), true)) {
                throw new RuntimeException("{$source->bootstrap_key} unexpectedly already contains {$purpose->value}.");
            }
        }
        $codes = array_column($rows, 'code');
        foreach (['6586', '7586', $definition['expense_parent'], $definition['gain_parent']] as $requiredCode) {
            $present = in_array($requiredCode, $codes, true);
            if (in_array($requiredCode, ['6586', '7586'], true) ? $present : ! $present) {
                throw new RuntimeException("{$source->bootstrap_key} cannot derive Option A: code {$requiredCode} has an invalid presence state.");
            }
        }
        $nextOrder = max(array_column($rows, 'sort_order')) + 1;
        $rows[] = [
            'code' => '6586',
            'name' => $definition['expense_name'],
            'type' => 'expense',
            'parent_code' => $definition['expense_parent'],
            'system_purpose' => SystemAccountPurpose::InventoryShrinkageExpense->value,
            'is_system' => true,
            'sort_order' => $nextOrder,
        ];
        $rows[] = [
            'code' => '7586',
            'name' => $definition['gain_name'],
            'type' => 'revenue',
            'parent_code' => $definition['gain_parent'],
            'system_purpose' => SystemAccountPurpose::InventoryGainIncome->value,
            'is_system' => true,
            'sort_order' => $nextOrder + 1,
        ];

        return $rows;
    }

    /**
     * @param  array{key: string, name: string, expense_parent: string, gain_parent: string, expense_name: string, gain_name: string}  $definition
     */
    private function assertExisting(AdminTemplate $template, AdminTemplate $source, array $definition): void
    {
        $raw = $template->getRawOriginal();
        $reason = null;
        if ($raw['domain'] !== TemplateDomain::ChartOfAccounts->value) {
            $reason = 'wrong domain';
        } elseif ($raw['status'] !== TemplateStatus::Draft->value) {
            $reason = 'not an untouched draft';
        } elseif ($raw['name'] !== $definition['name'] || $raw['description'] !== self::DESCRIPTION) {
            $reason = 'metadata mismatch';
        } elseif ($raw['cloned_from_id'] !== null) {
            $reason = 'unexpected UI clone provenance';
        } elseif ($raw['content_hash'] !== null || $raw['published_at'] !== null || $raw['certified_by'] !== null || $raw['created_by'] !== null) {
            $reason = 'not an untouched draft';
        } elseif ($this->rows($template) !== $this->expectedRows($source, $definition)) {
            $reason = 'row content mismatch';
        }
        if ($reason !== null) {
            throw new RuntimeException("{$definition['key']} bootstrap assertion failed: {$reason}.");
        }
    }

    /** @return list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int}> */
    private function rows(AdminTemplate $template): array
    {
        return array_values($template->accounts()->orderBy('sort_order')->orderBy('code')->lockForUpdate()->get()->map(
            static fn (AdminTemplateAccount $row): array => [
                'code' => $row->code,
                'name' => $row->name,
                'type' => (string) $row->getRawOriginal('type'),
                'parent_code' => $row->parent_code,
                'system_purpose' => $row->getRawOriginal('system_purpose') === null
                    ? null
                    : (string) $row->getRawOriginal('system_purpose'),
                'is_system' => $row->is_system,
                'sort_order' => $row->sort_order,
            ],
        )->all());
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
                throw new RuntimeException('Inventory variance template contains an unresolved parent or cycle.');
            }
        }

        return $ordered;
    }

    private function centralConnection(): ConnectionInterface
    {
        return DB::connection((new AdminTemplate)->getConnectionName());
    }
}
