<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Install the treasury-approved Option A inventory variance accounts into
 * existing tenant charts. Resolution is purpose-first and code-second: a
 * valid legacy purpose holder is authoritative, while an unpurposed 6586/7586
 * row may be promoted. Each definition has its own connection-bound
 * transaction/savepoint so one PostgreSQL error cannot leave the surrounding
 * tenants:migrate transaction in SQLSTATE 25P02.
 *
 * Invoke through tenants:run. Its child exit status is swallowed, so the last
 * console line and warning log are the stable deploy gate token.
 */
final class BackfillInventoryShrinkagePurposesCommand extends Command
{
    protected $signature = 'accounting:backfill-inventory-shrinkage-purposes
                            {--dry-run : Report changes without writing accounts}';

    protected $description = 'Backfill approved inventory shrinkage (6586) and gain (7586) purpose accounts.';

    public const SUMMARY_TOKEN_PREFIX = 'INVENTORY-SHRINKAGE-PURPOSE BACKFILL FAILURES:';

    public function __construct(private readonly DatabaseManager $database)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('accounts')) {
            $this->error('Tenant accounting tables are unavailable; run this command through tenants:run.');

            $token = self::SUMMARY_TOKEN_PREFIX.' 1';
            Log::warning($token);
            $this->line($token);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $promoted = 0;
        $satisfied = 0;
        $failures = 0;

        $companies = $this->database->table('companies')
            ->whereNull('deleted_at')
            ->select(['id', 'tenant_id', 'country_code'])
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            foreach ($this->definitions((string) $company->country_code) as $definition) {
                try {
                    $outcome = $this->database->connection()->transaction(
                        fn (): string => $this->applyDefinition(
                            (string) $company->id,
                            (string) $company->tenant_id,
                            $definition,
                            $dryRun,
                        ),
                    );
                    match ($outcome) {
                        'created' => $created++,
                        'promoted' => $promoted++,
                        default => $satisfied++,
                    };
                } catch (Throwable $exception) {
                    $failures++;
                    $this->error($exception->getMessage());
                }
            }
        }

        $this->info(sprintf(
            '%sInventory variance purpose backfill: %d created; %d promoted; %d already satisfied; %d failed.',
            $dryRun ? '[DRY-RUN] ' : '',
            $created,
            $promoted,
            $satisfied,
            $failures,
        ));

        $token = self::SUMMARY_TOKEN_PREFIX.' '.$failures;
        Log::warning($token);
        $this->line($token);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{code: string, name: string, type: string, parent_code: string, purpose: string}  $definition
     * @return 'created'|'promoted'|'satisfied'
     */
    private function applyDefinition(string $companyId, string $tenantId, array $definition, bool $dryRun): string
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

    /** @return list<array{code: string, name: string, type: string, parent_code: string, purpose: string}> */
    private function definitions(string $countryCode): array
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
}
