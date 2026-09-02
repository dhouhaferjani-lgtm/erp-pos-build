<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class ProductBarcodeUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = 'products_company_barcode_live_unique';

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_clean_tenant_has_the_live_company_barcode_partial_unique_on_both_supported_drivers(): void
    {
        $definition = $this->indexDefinition();

        $this->assertNotNull($definition);
        $normalized = strtolower((string) $definition);
        $this->assertStringContainsString('unique', $normalized);
        $this->assertStringContainsString('company_id', $normalized);
        $this->assertStringContainsString('barcode', $normalized);
        $this->assertStringContainsString('barcode is not null', str_replace(['"', '`'], '', $normalized));
        $this->assertStringContainsString('deleted_at is null', str_replace(['"', '`'], '', $normalized));
    }

    public function test_migration_keeps_oldest_twin_clears_losers_logs_once_and_is_idempotent(): void
    {
        $this->dropIndex();
        $oldest = $this->product('OLDEST', 'LEGACY-TWIN', now()->subDays(2));
        $middle = $this->product('MIDDLE', 'LEGACY-TWIN', now()->subDay());
        $newest = $this->product('NEWEST', 'LEGACY-TWIN', now());
        Log::spy();

        $migration = $this->barcodeMigration();
        $migration->up();

        $this->assertSame('LEGACY-TWIN', $oldest->refresh()->barcode);
        $this->assertNull($middle->refresh()->barcode);
        $this->assertNull($newest->refresh()->barcode);
        $this->assertNotNull($this->indexDefinition());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'barcode twins')
                && ($context['company_id'] ?? null) === $oldest->company_id
                && ($context['barcode'] ?? null) === 'LEGACY-TWIN'
                && ($context['kept_id'] ?? null) === $oldest->id
                && ($context['cleared_ids'] ?? null) === [$middle->id, $newest->id],
        );

        $migration->up();
        $this->assertSame(1, Product::query()->where('company_id', $this->company->id)->where('barcode', 'LEGACY-TWIN')->count());
        $this->assertNotNull($this->indexDefinition());
    }

    public function test_command_refuses_bare_invocation_before_querying_central_products(): void
    {
        $this->assertFalse(tenancy()->initialized);
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $buffer = new BufferedOutput;
            $exit = Artisan::call('products:census-barcode-twins', [], $buffer);
            $output = $buffer->fetch();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('tenants:run products:census-barcode-twins', $output);
        $this->assertSame([], array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'products'),
        )));
    }

    public function test_dry_run_command_reports_twins_without_clearing_them(): void
    {
        $this->dropIndex();
        $first = $this->product('DRY-1', 'DRY-TWIN', now()->subDay());
        $second = $this->product('DRY-2', 'DRY-TWIN', now());

        try {
            tenancy()->initialize($this->tenant);
            $buffer = new BufferedOutput;
            $exit = Artisan::call('products:census-barcode-twins', ['--dry-run' => true], $buffer);
            $output = $buffer->fetch();

            $this->assertSame(0, $exit, $output);
            $this->assertStringContainsString($this->company->id, $output);
            $this->assertStringContainsString('DRY-TWIN', $output);
            $this->assertStringContainsString($first->id, $output);
            $this->assertStringContainsString($second->id, $output);
            $this->assertSame('DRY-TWIN', $first->refresh()->barcode);
            $this->assertSame('DRY-TWIN', $second->refresh()->barcode);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $this->barcodeMigration()->up();
        }
    }

    private function product(string $sku, string $barcode, \DateTimeInterface $createdAt): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'barcode' => $barcode,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function barcodeMigration(): Migration
    {
        $paths = glob(database_path('migrations/tenant/*_enforce_company_scoped_product_barcodes.php'));
        $this->assertIsArray($paths);
        $this->assertCount(1, $paths, 'Exactly one K-11 barcode uniqueness migration must exist.');

        /** @var Migration $migration */
        $migration = require $paths[0];

        return $migration;
    }

    private function dropIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }

    private function indexDefinition(): ?string
    {
        if (DB::getDriverName() === 'pgsql') {
            $row = DB::selectOne('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?', [self::INDEX]);

            return is_string($row?->indexdef ?? null) ? $row->indexdef : null;
        }

        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?", [self::INDEX]);

        return is_string($row?->sql ?? null) ? $row->sql : null;
    }
}
