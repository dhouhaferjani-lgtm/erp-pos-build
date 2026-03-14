<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Commands;

use App\Modules\Compliance\Services\Nf525\Nf525JetExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Artisan command to export NF525 JET XML for a company.
 *
 * Usage:
 *   php artisan nf525:export-jet --company=uuid --from=2026-01-01 --to=2026-03-31
 *   php artisan nf525:export-jet --company=uuid --from=2026-01-01 --to=2026-03-31 --output=export.xml
 */
final class ExportNf525JetCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nf525:export-jet
        {--company= : Company UUID (required)}
        {--from= : Start date (YYYY-MM-DD, required)}
        {--to= : End date (YYYY-MM-DD, required)}
        {--output=jet.xml : Output file path}';

    /**
     * @var string
     */
    protected $description = 'Export NF525 JET (Journal des Evenements Techniques) XML for fiscal audit';

    public function __construct(
        private readonly Nf525JetExportService $exportService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string|null $companyId */
        $companyId = $this->option('company');
        /** @var string|null $fromStr */
        $fromStr = $this->option('from');
        /** @var string|null $toStr */
        $toStr = $this->option('to');
        /** @var string $outputPath */
        $outputPath = $this->option('output');

        if ($companyId === null || $companyId === '') {
            $this->error('The --company option is required.');

            return self::FAILURE;
        }

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
        } catch (\Exception $e) {
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
