<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenant\Application\DTOs\DayOneInvariantResult;
use App\Modules\Tenant\Application\Services\DayOneCensus;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

final class DayOneCensusCommand extends Command
{
    protected $signature = 'tenant:census-day-one
        {--company= : Optional company UUID}
        {--fail-on-drift : Exit 1 when any invariant fails (default: report only, exit 0)}';

    protected $description = 'Read-only day-one tenant census. Exit 0 = report complete/clean, 1 = drift when --fail-on-drift is set.';

    public function __construct(
        private readonly DayOneCensus $census,
        private readonly Tenancy $tenancy,
        private readonly DatabaseManager $database,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $companyOption = $this->option('company');
        $companyId = is_string($companyOption) && $companyOption !== '' ? $companyOption : null;
        if ($companyId !== null && ! Str::isUuid($companyId)) {
            $this->error('DAY-ONE CENSUS: --company must be a valid UUID.');

            return self::INVALID;
        }

        $results = $this->census->inspect($companyId);

        if ($results === []) {
            $this->error($companyId === null
                ? 'DAY-ONE CENSUS '.$this->tenantId().' -: NO-COMPANY'
                : "DAY-ONE CENSUS: company {$companyId} does not exist on the current tenant connection.");

            return self::FAILURE;
        }

        $this->table(
            ['invariant', 'company', 'location', 'expected', 'actual', 'status'],
            array_map(
                static fn (DayOneInvariantResult $result): array => [
                    $result->key,
                    $result->companyId,
                    $result->locationId ?? '-',
                    $result->expected,
                    $result->actual,
                    $result->passed ? 'OK' : 'FAIL',
                ],
                $results,
            ),
        );

        $tenantId = $this->tenantId($results[0]->companyId);
        $byCompany = [];
        foreach ($results as $result) {
            $byCompany[$result->companyId][] = $result;
        }

        $driftCount = 0;
        foreach ($byCompany as $resultCompanyId => $companyResults) {
            $companyDrift = count(array_filter(
                $companyResults,
                static fn (DayOneInvariantResult $result): bool => ! $result->passed,
            ));
            $driftCount += $companyDrift;
            $verdict = $companyDrift === 0 ? 'CLEAN' : "DRIFT({$companyDrift})";
            $this->line("DAY-ONE CENSUS {$tenantId} {$resultCompanyId}: {$verdict}");
        }

        // tenants:run forwards VALUE_NONE options as the string '1'. Treat the
        // option truthily; a strict `=== true` would silently ignore staging.
        if ((bool) $this->option('fail-on-drift') && $driftCount > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function tenantId(?string $companyId = null): string
    {
        $boundTenant = $this->tenancy->tenant;
        if ($boundTenant instanceof TenantContract) {
            return (string) $boundTenant->getTenantKey();
        }

        $tenantId = $companyId === null
            ? null
            : $this->database->table('companies')->where('id', $companyId)->value('tenant_id');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : '(unbound)';
    }
}
