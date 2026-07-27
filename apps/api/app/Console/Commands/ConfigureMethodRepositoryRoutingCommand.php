<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;

final class ConfigureMethodRepositoryRoutingCommand extends Command
{
    protected $signature = 'treasury:configure-method-routing
                            {--card-to= : Repository code to use for each company CARD method}
                            {--dry-run : Report mapping changes without writing them}';

    protected $description = 'Configure company-scoped CARD payment-method repository routing.';

    public function __construct(private readonly DatabaseManager $database)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('companies')
            || ! Schema::hasTable('payment_methods')
            || ! Schema::hasTable('payment_repositories')
            || ! Schema::hasColumn('payment_methods', 'default_repository_id')) {
            $this->error(
                'Tenant Treasury routing tables are unavailable. Run tenant migrations and execute this command inside each tenant context.',
            );

            return self::FAILURE;
        }

        $repositoryCode = strtoupper(trim((string) $this->option('card-to')));
        if ($repositoryCode === '') {
            $this->error('The --card-to repository code is required.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $unchanged = 0;
        $invalid = 0;

        $companies = $this->database->table('companies')
            ->select(['id', 'tenant_id'])
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $companyId = (string) $company->id;
            $tenantId = (string) $company->tenant_id;
            $methods = $this->database->table('payment_methods')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereRaw('UPPER(code) = ?', ['CARD'])
                ->orderBy('id')
                ->get(['id', 'default_repository_id']);

            if ($methods->isEmpty()) {
                continue;
            }

            $repository = $this->database->table('payment_repositories')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereRaw('UPPER(code) = ?', [$repositoryCode])
                ->where('is_active', true)
                ->whereNotNull('gl_account_id')
                ->first(['id']);

            if ($repository === null) {
                $this->error(sprintf(
                    'Company %s has no active GL-linked repository with code %s; CARD routing was not changed.',
                    $companyId,
                    $repositoryCode,
                ));
                $invalid++;

                continue;
            }

            foreach ($methods as $method) {
                if ((string) ($method->default_repository_id ?? '') === (string) $repository->id) {
                    $unchanged++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '[DRY-RUN] Company %s: would route CARD method %s to repository %s (%s).',
                        $companyId,
                        (string) $method->id,
                        (string) $repository->id,
                        $repositoryCode,
                    ));
                } else {
                    $this->database->table('payment_methods')
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->where('id', (string) $method->id)
                        ->update([
                            'default_repository_id' => (string) $repository->id,
                            'updated_at' => now(),
                        ]);
                }
                $changed++;
            }
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info(sprintf(
            '%sCARD repository routing: %d mapping(s) %s; %d unchanged; %d invalid company configuration(s).',
            $prefix,
            $changed,
            $dryRun ? 'would be changed' : 'changed',
            $unchanged,
            $invalid,
        ));

        return $invalid === 0 ? self::SUCCESS : self::FAILURE;
    }
}
