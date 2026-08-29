<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPANY_CREATED_AT_INDEX = 'import_jobs_company_id_created_at_index';

    private const COMPANY_INDEX = 'import_jobs_company_id_index';

    private const SOURCE_HASH_INDEX = 'import_jobs_source_hash_index';

    /** @var array<string, string> */
    private const TARGET_TABLES = [
        'products' => 'products',
        'stock_levels' => 'products',
        'product_images' => 'products',
        'parties' => 'partners',
        'partners' => 'partners',
        'composite_items' => 'composite_items',
        'opening_balances' => 'journal_entries',
    ];

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->info('import-job-company-skipped', ['reason' => 'unsupported-driver', 'driver' => $driver]);

            return;
        }

        if (! Schema::connection($connection->getName())->hasTable('import_jobs')) {
            $this->info('import-job-company-skipped', ['reason' => 'import-jobs-table-absent']);

            return;
        }

        if (! Schema::connection($connection->getName())->hasColumn('import_jobs', 'company_id')) {
            Schema::connection($connection->getName())->table('import_jobs', function (Blueprint $table): void {
                $table->uuid('company_id')->nullable();
            });
        }

        if (! Schema::connection($connection->getName())->hasColumn('import_jobs', 'source_hash')) {
            Schema::connection($connection->getName())->table('import_jobs', function (Blueprint $table): void {
                $table->char('source_hash', 64)->nullable();
            });
        }

        $this->ensureIndexes($connection);
        $this->backfill($connection);
    }

    /**
     * Forward-only by design: dropping these columns would destroy attribution
     * and hashes written after deployment, while NULL cannot distinguish a
     * migration abstention from an intentionally unattributed job.
     */
    public function down(): void
    {
        Log::info('import-job-company-forward-only', [
            'reason' => 'dropping columns would destroy fleet-written attribution; null is indistinguishable from abstention',
        ]);
    }

    private function ensureIndexes(Connection $connection): void
    {
        $schema = Schema::connection($connection->getName());

        if (! $schema->hasIndex('import_jobs', self::COMPANY_INDEX)) {
            $schema->table('import_jobs', function (Blueprint $table): void {
                $table->index('company_id', self::COMPANY_INDEX);
            });
        }

        if (! $schema->hasIndex('import_jobs', self::COMPANY_CREATED_AT_INDEX)) {
            $schema->table('import_jobs', function (Blueprint $table): void {
                $table->index(['company_id', 'created_at'], self::COMPANY_CREATED_AT_INDEX);
            });
        }

        if (! $schema->hasIndex('import_jobs', self::SOURCE_HASH_INDEX)) {
            $schema->table('import_jobs', function (Blueprint $table): void {
                $table->index('source_hash', self::SOURCE_HASH_INDEX);
            });
        }
    }

    private function backfill(Connection $connection): void
    {
        $attributed = 0;
        $none = 0;
        /** @var array<string, array<string, int>> $ambiguous */
        $ambiguous = [];

        /** @var object{id: string, type: string} $job */
        foreach ($connection->table('import_jobs')->whereNull('company_id')->get(['id', 'type']) as $job) {
            $counts = $this->evidenceCounts($connection, $job->id, $job->type);

            if (count($counts) === 1) {
                $companyId = array_key_first($counts);
                $connection->table('import_jobs')
                    ->where('id', $job->id)
                    ->whereNull('company_id')
                    ->update(['company_id' => $companyId]);
                $attributed++;

                continue;
            }

            if (count($counts) > 1) {
                $ambiguous[$job->id] = $counts;

                continue;
            }

            $none++;
        }

        $this->info('import-job-company-attributed', ['jobs' => $attributed]);
        $this->warning('import-job-company-ambiguous', ['jobs' => count($ambiguous), 'per_job' => $ambiguous]);
        $this->info('import-job-company-none', ['jobs' => $none]);
    }

    /**
     * @return array<string, int>
     */
    private function evidenceCounts(Connection $connection, string $jobId, string $type): array
    {
        $targetTable = self::TARGET_TABLES[$type] ?? null;
        if ($targetTable === null) {
            return [];
        }

        $schema = Schema::connection($connection->getName());
        if (! $schema->hasTable('import_rows')
            || ! $schema->hasTable($targetTable)
            || ! $schema->hasColumn($targetTable, 'company_id')) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = [];
        /** @var object{company_id: string, evidence_count: int|string} $row */
        foreach ($connection->table('import_rows as rows')
            ->join($targetTable.' as target', 'target.id', '=', 'rows.imported_entity_id')
            ->where('rows.import_job_id', $jobId)
            ->whereNotNull('rows.imported_entity_id')
            ->whereNotNull('target.company_id')
            ->groupBy('target.company_id')
            ->selectRaw('target.company_id, COUNT(*) AS evidence_count')
            ->get() as $row) {
            $counts[$row->company_id] = (int) $row->evidence_count;
        }

        ksort($counts);

        return $counts;
    }

    /** @param array<string, int|string> $context */
    private function info(string $message, array $context): void
    {
        echo $message.' '.json_encode($context, JSON_THROW_ON_ERROR).PHP_EOL;
        Log::info($message, $context);
    }

    /** @param array{jobs: int, per_job: array<string, array<string, int>>} $context */
    private function warning(string $message, array $context): void
    {
        echo $message.' '.json_encode($context, JSON_THROW_ON_ERROR).PHP_EOL;
        Log::warning($message, $context);
    }
};
