<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class BackfillPayableInstrumentAccountsCommand extends Command
{
    protected $signature = 'treasury:backfill-payable-instrument-accounts
                            {--dry-run : Report changes without writing accounts}';

    protected $description = 'Backfill the payable check and effect liability accounts for every company.';

    public function __construct(private readonly DatabaseManager $database)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('accounts')) {
            $this->error(
                'Tenant treasury tables are unavailable. Run this command inside each tenant context (for example via tenants:run).',
            );

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $promoted = 0;
        $invalid = 0;

        $companies = $this->database->table('companies')
            ->select(['id', 'tenant_id', 'country_code'])
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $companyId = (string) $company->id;
            $parentCode = strtoupper((string) $company->country_code) === 'TN'
                || strtoupper((string) $company->country_code) === 'FR'
                    ? '40'
                    : '4000';
            $parentId = $this->database->table('accounts')
                ->where('company_id', $companyId)
                ->where('code', $parentCode)
                ->value('id');

            if (! is_string($parentId)) {
                $this->error(sprintf(
                    'Company %s is missing supplier parent account %s; payable instrument accounts were skipped.',
                    $companyId,
                    $parentCode,
                ));
                $invalid++;

                continue;
            }

            foreach ($this->definitions((string) $company->country_code) as $definition) {
                $existing = $this->database->table('accounts')
                    ->where('company_id', $companyId)
                    ->where('code', $definition['code'])
                    ->first();

                if ($existing !== null) {
                    if ((string) $existing->type !== 'liability') {
                        $this->error(sprintf(
                            'Company %s account %s has wrong type %s; expected liability. Account was skipped.',
                            $companyId,
                            $definition['code'],
                            (string) $existing->type,
                        ));
                        $invalid++;

                        continue;
                    }

                    if (! (bool) $existing->is_active) {
                        $this->error(sprintf(
                            'Company %s account %s is inactive; activate it before using payable instruments. Account was skipped.',
                            $companyId,
                            $definition['code'],
                        ));
                        $invalid++;

                        continue;
                    }

                    if (! (bool) $existing->is_system) {
                        if ($dryRun) {
                            $this->line(sprintf(
                                '[DRY-RUN] Company %s: would promote account %s to system-managed.',
                                $companyId,
                                $definition['code'],
                            ));
                        } else {
                            $this->database->table('accounts')
                                ->where('id', $existing->id)
                                ->update(['is_system' => true, 'updated_at' => now()]);
                        }
                        $promoted++;
                    }

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '[DRY-RUN] Company %s: would create liability account %s (%s).',
                        $companyId,
                        $definition['code'],
                        $definition['name'],
                    ));
                    $created++;

                    continue;
                }

                $now = now();
                $this->database->table('accounts')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => (string) $company->tenant_id,
                    'company_id' => $companyId,
                    'parent_id' => $parentId,
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'type' => 'liability',
                    'system_purpose' => null,
                    'is_active' => true,
                    'is_system' => true,
                    'balance' => '0.000',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info(sprintf(
            '%sPayable instrument account backfill: %d account(s) %s; %d promoted; %d invalid account(s).',
            $prefix,
            $created,
            $dryRun ? 'would be created' : 'created',
            $promoted,
            $invalid,
        ));

        return $invalid === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function definitions(string $countryCode): array
    {
        if (in_array(strtoupper($countryCode), ['TN', 'FR'], true)) {
            return [
                ['code' => '403', 'name' => 'Fournisseurs - Effets à payer'],
                ['code' => '4035', 'name' => 'Fournisseurs - Chèques à payer'],
            ];
        }

        return [
            ['code' => '403', 'name' => 'Supplier Effects Payable'],
            ['code' => '4035', 'name' => 'Supplier Checks Payable'],
        ];
    }
}
