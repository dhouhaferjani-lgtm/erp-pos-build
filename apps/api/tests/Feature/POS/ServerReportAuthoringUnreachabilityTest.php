<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\Exceptions\ServerFiscalAuthoringRetiredException;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Cash-rounding Phase 1 / Task 12 — pins the foreclosure that makes
 * `GrandtotalService`'s `netSales = gross − tax` formula safe under rounding
 * (spec §4.5).
 *
 * `GrandtotalService::calculatePeriodTotals` derives net sales by subtracting
 * tax from the collected gross. On a ROUNDED receipt the collected gross
 * already carries the signed rounding adjustment, so that formula would fold
 * the adjustment into net sales and silently distort the VAT base. It is safe
 * today only because it is UNREACHABLE for rounded receipts, and that rests on
 * two facts, pinned here:
 *
 *  1. Server-side Z/X authoring throws `ServerFiscalAuthoringRetiredException`
 *     for `fiscal_schema_version >= 3` terminals — exactly the terminals the
 *     device is allowed to round on.
 *  2. `ReportGenerationService` is the only production caller of
 *     `GrandtotalService`, so no other path can reach the formula.
 *
 * The PAIRED device-side pin — "the device signs a non-zero
 * `cash_rounding_adjustment` ONLY when its cached terminal
 * `fiscal_schema_version === 3`" — is an `apps/pos` (Plan B) test and is
 * deliberately NOT written here; this suite can only observe the server half
 * of the interlock.
 *
 * If either pin below fails, the rounding-safety argument is void and
 * `GrandtotalService` must be made rounding-aware BEFORE the offending change
 * ships.
 */
final class ServerReportAuthoringUnreachabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_z_report_is_retired_at_fiscal_schema_version_three(): void
    {
        [$terminal, $user] = $this->seedTerminalAtSchemaVersion(3);

        $this->expectException(ServerFiscalAuthoringRetiredException::class);
        $this->app->make(ReportGenerationService::class)->generateZReport($terminal, $user);
    }

    public function test_server_x_report_is_retired_at_fiscal_schema_version_three(): void
    {
        [$terminal, $user] = $this->seedTerminalAtSchemaVersion(3);

        $this->expectException(ServerFiscalAuthoringRetiredException::class);
        $this->app->make(ReportGenerationService::class)->generateXReport($terminal, $user);
    }

    /**
     * Control: at v2 the gate does NOT fire, so the two tests above are pinning
     * the version discriminator and not some unrelated blanket failure.
     */
    public function test_server_report_authoring_is_not_retired_below_version_three(): void
    {
        [$terminal, $user] = $this->seedTerminalAtSchemaVersion(2);

        // No open shift, so the v2 path fails on the SHIFT precondition rather
        // than the retirement gate — proving the gate itself let it through.
        $this->expectException(ShiftNotOpenException::class);
        $this->app->make(ReportGenerationService::class)->generateXReport($terminal, $user);
    }

    public function test_grandtotal_service_is_reachable_only_from_the_retired_report_path(): void
    {
        // Static pin: the only production caller of GrandtotalService is
        // ReportGenerationService, which is gated above. If a new caller
        // appears, the netSales = gross − tax formula must be revisited for
        // rounded receipts BEFORE that caller ships.
        //
        // The scan is TOKEN-based, not substring-based: PaymentToleranceQueryService
        // names GrandtotalService in a docblock (listing the receipt aggregators
        // that filter training receipts) without ever calling it, and a naive
        // str_contains would score that as a caller.
        $root = base_path('app');
        $matches = [];

        /** @var iterable<string, SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_ends_with($path, 'GrandtotalService.php')) {
                continue;
            }
            if ($this->referencesSymbolInCode((string) file_get_contents($path), 'GrandtotalService')) {
                $matches[] = str_replace($root.DIRECTORY_SEPARATOR, '', $path);
            }
        }

        sort($matches);
        $this->assertSame(
            ['Modules/POS/Application/Services/ReportGenerationService.php'],
            $matches,
            'A new GrandtotalService caller appeared; its netSales formula is rounding-unsafe.',
        );
    }

    /**
     * True when `$symbol` appears in an executable PHP token (identifier or
     * qualified name) rather than only inside a comment, docblock or string.
     */
    private function referencesSymbolInCode(string $contents, string $symbol): bool
    {
        foreach (token_get_all($contents) as $token) {
            if (! is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if (str_contains($token[1], $symbol)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: Terminal, 1: User}
     */
    private function seedTerminalAtSchemaVersion(int $version): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $terminal = Terminal::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'code' => 'POS01',
            'name' => 'Test Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => (int) date('Y'),
            'fiscal_schema_version' => $version,
            'is_active' => true,
            'max_discount_percent' => 20.0,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);

        return [$terminal, $user];
    }
}
