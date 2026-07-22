<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Modules\Company\Domain\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Multi-Location §3 backfill. This is deliberately a command, not a
 * migration: repository assignment is an explicit owner action and must be
 * complete before historical NULLs are attributed.
 *
 * Only NULL location_id values are filled. Documents are never touched.
 */
final class BackfillLocationAttributionCommand extends Command
{
    protected $signature = 'treasury:backfill-location-attribution {--company= : Company id (required)}';

    protected $description = 'Backfill payment and instrument locations from repository and POS terminal attribution.';

    public function handle(): int
    {
        $companyId = $this->option('company');
        if (! is_string($companyId) || $companyId === '') {
            $this->error('The --company option is required.');

            return self::FAILURE;
        }

        $company = Company::query()->find($companyId);
        if (! $company instanceof Company) {
            $this->error("Company {$companyId} not found.");

            return self::FAILURE;
        }

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

        return self::SUCCESS;
    }
}
