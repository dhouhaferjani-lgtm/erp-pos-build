<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\Nf525\Nf525JetExportService;
use Illuminate\Support\Carbon;

/**
 * Artisan command to export NF525 JET XML for a company.
 *
 * Usage:
 *   php artisan nf525:export-jet --tenant=uuid --company=uuid --from=2026-01-01 --to=2026-03-31
 *   php artisan nf525:export-jet --tenant=uuid --company=uuid --from=... --to=... --output=export.xml
 *
 * Tenant-isolation: cat-(a-singleshot). Inherits `--tenant` + `--company`
 * validation from {@see TenantScopedCommand}. The base validates the company
 * belongs to the tenant via `ScopedExists::tenant('companies', $tenantId)`
 * and binds `CompanyContext` so the downstream export service runs with the
 * correct company context.
 */
final class ExportNf525JetCommand extends TenantScopedCommand
{
    /**
     * @var string
     */
    protected $signature = 'nf525:export-jet
        {--tenant= : Tenant UUID (required; parent of --company)}
        {--company= : Company UUID (required; must belong to --tenant)}
        {--from= : Start date (YYYY-MM-DD, required)}
        {--to= : End date (YYYY-MM-DD, required)}
        {--output=jet.xml : Output file path}';

    /**
     * @var string
     */
    protected $description = 'Export NF525 JET (Journal des Evenements Techniques) XML for fiscal audit';

    public function __construct(
        CompanyContext $companyContext,
        private readonly Nf525JetExportService $exportService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        if (($exit = $this->bindTenantAndCompanyFromOptions()) !== null) {
            return $exit;
        }

        $companyId = $this->companyContext->requireCompanyId();

        /** @var string|null $fromStr */
        $fromStr = $this->option('from');
        /** @var string|null $toStr */
        $toStr = $this->option('to');
        /** @var string $outputPath */
        $outputPath = $this->option('output');

        if ($fromStr === null || $fromStr === '') {
            $this->error('The --from option is required (format: YYYY-MM-DD).');

            return self::FAILURE;
        }

        if ($toStr === null || $toStr === '') {
            $this->error('The --to option is required (format: YYYY-MM-DD).');

            return self::FAILURE;
        }

        try {
            $from = Carbon::parse($fromStr);
            $to = Carbon::parse($toStr);
        } catch (\Exception) {
            $this->error('Invalid date format. Use YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($from->greaterThan($to)) {
            $this->error('Start date must be before or equal to end date.');

            return self::FAILURE;
        }

        $this->info("Exporting NF525 JET XML for company {$companyId}");
        $this->info("Period: {$from->toDateString()} to {$to->toDateString()}");
        $this->info("Output: {$outputPath}");

        try {
            $this->exportService->exportJetToFile($companyId, $from, $to, $outputPath);
            $this->info('Export completed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Export failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
