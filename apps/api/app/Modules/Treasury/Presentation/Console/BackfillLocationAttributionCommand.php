<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Multi-Location §3 backfill. This is deliberately a command, not a
 * migration: repository assignment is an explicit owner action and must be
 * complete before historical NULLs are attributed.
 *
 * Only NULL location_id values are filled. Documents are never touched.
 */
final class BackfillLocationAttributionCommand extends TenantScopedCommand
{
    protected $signature = 'treasury:backfill-location-attribution
        {--tenant= : Restrict the run to one tenant id}
        {--company= : Process one company id}
        {--all-companies : Process every company in the selected tenant scope}';

    protected $description = 'Backfill payment and instrument locations from repository and POS terminal attribution.';

    protected function executeCommand(): int
    {
        $companyId = $this->stringOption('company');
        $allCompanies = (bool) $this->option('all-companies');
        if (($companyId === null) === ! $allCompanies) {
            $this->error('Pass exactly one of --company=<uuid> or --all-companies.');

            return self::FAILURE;
        }

        $processed = 0;
        $failed = false;
        $exit = $this->forEachTenantFiltered(
            $this->stringOption('tenant'),
            function (Tenant $tenant) use ($allCompanies, $companyId, &$failed, &$processed): int {
                $companies = Company::query()
                    ->where('tenant_id', $tenant->id)
                    ->when(! $allCompanies, static fn ($query) => $query->whereKey($companyId))
                    ->orderBy('id')
                    ->get();

                foreach ($companies as $company) {
                    try {
                        $this->companyContext->setCompanyId($company->id);
                        $this->backfillCompany($company);
                        $processed++;
                    } catch (Throwable $exception) {
                        $failed = true;
                        $this->error(sprintf(
                            'Company %s failed: %s',
                            $company->id,
                            $exception->getMessage(),
                        ));
                    }
                }

                return self::SUCCESS;
            },
        );

        if ($processed === 0) {
            $this->error($companyId === null
                ? 'No companies were visited.'
                : "Company {$companyId} not found in the selected tenant scope.");

            return self::FAILURE;
        }

        return $exit === self::SUCCESS && ! $failed ? self::SUCCESS : self::FAILURE;
    }

    private function backfillCompany(Company $company): void
    {
        $repositoryLocations = DB::table('payment_repositories')
            ->where('company_id', $company->id)
            ->whereNotNull('location_id')
            ->pluck('location_id', 'id');

        $instrumentCount = 0;
        DB::table('payment_instruments')
            ->where('company_id', $company->id)
            ->whereNull('location_id')
            ->orderBy('id')
            ->eachById(function (object $instrument) use ($repositoryLocations, &$instrumentCount): void {
                $locationId = $repositoryLocations->get($instrument->repository_id);
                if (! is_string($locationId)) {
                    return;
                }

                $instrumentCount += DB::table('payment_instruments')
                    ->where('id', $instrument->id)
                    ->whereNull('location_id')
                    ->update(['location_id' => $locationId]);
            });

        $paymentCount = 0;
        DB::table('payments')
            ->where('company_id', $company->id)
            ->whereNull('location_id')
            ->orderBy('id')
            ->eachById(function (object $payment) use ($repositoryLocations, &$paymentCount): void {
                $locationId = $repositoryLocations->get($payment->repository_id);
                if (! is_string($locationId)) {
                    return;
                }

                $paymentCount += DB::table('payments')
                    ->where('id', $payment->id)
                    ->whereNull('location_id')
                    ->update(['location_id' => $locationId]);
            });

        // POS-originated payments use the terminal's origin location. This is
        // intentionally a second NULL-only pass so a repository attribution (or
        // a manual assignment) always wins.
        DB::table('payments')
            ->where('company_id', $company->id)
            ->whereNull('location_id')
            ->whereNotNull('fiscal_event_id')
            ->orderBy('id')
            ->eachById(function (object $payment) use ($company, &$paymentCount): void {
                $event = DB::table('fiscal_events')
                    ->where('id', $payment->fiscal_event_id)
                    ->where('company_id', $company->id)
                    ->first(['terminal_id']);
                if ($event === null || ! is_string($event->terminal_id)) {
                    return;
                }

                $locationId = DB::table('pos_terminals')
                    ->where('id', $event->terminal_id)
                    ->where('company_id', $company->id)
                    ->value('location_id');
                if (! is_string($locationId)) {
                    return;
                }

                $paymentCount += DB::table('payments')
                    ->where('id', $payment->id)
                    ->whereNull('location_id')
                    ->update(['location_id' => $locationId]);
            });

        $this->info("Backfilled {$paymentCount} payment(s) and {$instrumentCount} instrument(s) for company {$company->id}.");
    }
}
