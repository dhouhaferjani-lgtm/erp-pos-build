<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Precision\PercentScaleDriftScanner;
use Illuminate\Console\Command;

final class ScanPercentScaleDrift extends Command
{
    protected $signature = 'precision:scan-percent-scale-drift {--tenant=* : Limit to tenant ids}';

    protected $description = 'Read-only fleet scan for percent values that cannot be narrowed safely to scale 2.';

    public function handle(PercentScaleDriftScanner $scanner): int
    {
        /** @var list<string> $tenantIds */
        $tenantIds = $this->option('tenant');
        $query = Tenant::query();

        if ($tenantIds !== []) {
            $query->whereIn('id', $tenantIds);
        }

        $hasFindings = false;

        /** @var Tenant $tenant */
        foreach ($query->cursor() as $tenant) {
            /** @var list<array{table: string, column: string, count: int}> $findings */
            $findings = $tenant->run(fn (): array => $scanner->scanCurrentConnection());

            foreach ($findings as $finding) {
                $hasFindings = true;
                $this->line(sprintf(
                    '%s %s.%s %d',
                    $tenant->id,
                    $finding['table'],
                    $finding['column'],
                    $finding['count'],
                ));
            }
        }

        if ($hasFindings) {
            $this->error('Unsafe percent scale drift found. Resolve or explicitly normalize before narrowing columns.');

            return self::FAILURE;
        }

        $this->info('No unsafe percent scale drift found.');

        return self::SUCCESS;
    }
}
