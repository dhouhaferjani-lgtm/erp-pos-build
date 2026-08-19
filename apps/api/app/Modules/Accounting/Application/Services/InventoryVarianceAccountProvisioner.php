<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Purpose-first installer shared by new-company provisioning and the tenant
 * backfill command. The frozen legacy seeders remain unchanged; their normal
 * ChartOfAccountsService path adds the approved accounts in the same atomic
 * transaction immediately after seeding.
 */
final class InventoryVarianceAccountProvisioner
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function provisionCompany(string $companyId, string $tenantId, string $countryCode): void
    {
        foreach ($this->definitions($countryCode) as $definition) {
            $this->applyDefinition($companyId, $tenantId, $definition, false);
        }
    }

    /**
     * @param  array{code: string, name: string, type: string, parent_code: string, purpose: string}  $definition
     * @return 'created'|'promoted'|'satisfied'
     */
    public function applyDefinition(string $companyId, string $tenantId, array $definition, bool $dryRun): string
    {
        $accounts = $this->database->table('accounts')->where('company_id', $companyId);
        $holder = (clone $accounts)->where('system_purpose', $definition['purpose'])->first();
        if ($holder !== null) {
            $this->assertUsable($companyId, $holder, $definition);

            return 'satisfied';
        }

        $existing = (clone $accounts)->where('code', $definition['code'])->first();
        if ($existing !== null) {
            if ($existing->system_purpose !== null) {
                throw new RuntimeException(sprintf(
                    'Company %s account %s already carries system_purpose %s; refusing to repurpose it.',
                    $companyId,
                    $definition['code'],
                    (string) $existing->system_purpose,
                ));
            }
            $this->assertUsable($companyId, $existing, $definition);
            if (! $dryRun) {
                $this->database->table('accounts')->where('id', $existing->id)->update([
                    'system_purpose' => $definition['purpose'],
                    'is_system' => true,
                    'updated_at' => now(),
                ]);
            }

            return 'promoted';
        }

        $parentId = (clone $accounts)->where('code', $definition['parent_code'])->value('id');
        if (! is_string($parentId)) {
            throw new RuntimeException(sprintf(
                'Company %s is missing parent account %s; inventory variance account %s was skipped.',
                $companyId,
                $definition['parent_code'],
                $definition['code'],
            ));
        }
        if (! $dryRun) {
            $now = now();
            $this->database->table('accounts')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'parent_id' => $parentId,
                'code' => $definition['code'],
                'name' => $definition['name'],
                'type' => $definition['type'],
                'system_purpose' => $definition['purpose'],
                'is_active' => true,
                'is_system' => true,
                'balance' => '0.000',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return 'created';
    }

    /** @return list<array{code: string, name: string, type: string, parent_code: string, purpose: string}> */
    public function definitions(string $countryCode): array
    {
        $frenchPlan = in_array(strtoupper($countryCode), ['TN', 'FR'], true);

        return [
            [
                'code' => '6586',
                'name' => $frenchPlan ? "Écarts d'inventaire — manquants et pertes" : 'Inventory Shrinkage Expense',
                'type' => 'expense',
                'parent_code' => $frenchPlan ? '65' : '6000',
                'purpose' => SystemAccountPurpose::InventoryShrinkageExpense->value,
            ],
            [
                'code' => '7586',
                'name' => $frenchPlan ? "Écarts d'inventaire — excédents" : 'Inventory Count Gain',
                'type' => 'revenue',
                'parent_code' => $frenchPlan ? '75' : '7000',
                'purpose' => SystemAccountPurpose::InventoryGainIncome->value,
            ],
        ];
    }

    /** @param array{type: string, purpose: string} $definition */
    private function assertUsable(string $companyId, \stdClass $account, array $definition): void
    {
        if ((string) $account->type !== $definition['type']) {
            throw new RuntimeException(sprintf(
                'Company %s account %s has wrong type %s; expected %s.',
                $companyId,
                (string) $account->code,
                (string) $account->type,
                $definition['type'],
            ));
        }
        if (! (bool) $account->is_active) {
            throw new RuntimeException(sprintf('Company %s account %s is inactive.', $companyId, (string) $account->code));
        }
    }
}
