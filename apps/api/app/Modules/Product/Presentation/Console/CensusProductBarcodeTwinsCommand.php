<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;

/**
 * @cross-tenant-by-design Runs only inside the tenant context bound by tenants:run; bare invocation fails before product access.
 */
final class CensusProductBarcodeTwinsCommand extends Command
{
    protected $signature = 'products:census-barcode-twins {--dry-run : Report live twins without changing any product}';

    protected $description = 'Report company-scoped live product barcode twins; this command never mutates product data.';

    public function __construct(private readonly Tenancy $tenancy)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->tenancy->initialized) {
            $this->error('No tenant context is bound. Run via: php artisan tenants:run products:census-barcode-twins');

            return self::FAILURE;
        }

        $groups = DB::table('products')
            ->select(['company_id', 'barcode'])
            ->selectRaw('COUNT(*) AS row_count')
            ->whereNotNull('barcode')
            ->whereNull('deleted_at')
            ->groupBy('company_id', 'barcode')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')
            ->orderBy('barcode')
            ->get();

        $this->info(sprintf('Barcode twin groups: %d (read-only%s).', $groups->count(), $this->option('dry-run') ? ' dry run' : ' census'));
        foreach ($groups as $group) {
            /** @var object{company_id: string, barcode: string, row_count: int|string} $group */
            $ids = DB::table('products')
                ->where('company_id', $group->company_id)
                ->where('barcode', $group->barcode)
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id')
                ->filter(static fn (mixed $id): bool => is_string($id))
                ->values()
                ->all();
            $this->line(sprintf(
                'company=%s barcode=%s ids=%s',
                $group->company_id,
                $group->barcode,
                implode(',', $ids),
            ));
        }

        return self::SUCCESS;
    }
}
