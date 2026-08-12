<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\POS\Application\Services\ReportGenerationService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use Tests\TestCase;

final class ZReportServerAuthoringChokepointTest extends TestCase
{
    public function test_takings_only_expected_per_method_surface_is_deprecated_and_has_no_shipped_client(): void
    {
        $method = new ReflectionMethod(ReportGenerationService::class, 'buildExpectedPerMethod');
        $docblock = $method->getDocComment();

        $this->assertIsString($docblock);
        $this->assertStringContainsString('@deprecated', $docblock);
        $this->assertStringContainsString('takings-only', $docblock);
        $this->assertStringContainsString('Production is whole-drawer via the device', $docblock);
        $this->assertStringContainsString('The defect is a missing join, not a wrong doctrine', $docblock);
        $this->assertStringContainsString('this annotation stops a fifth', $docblock);

        $repoRoot = dirname(base_path(), 2);
        $webClientPath = $repoRoot.'/apps/web/src/features/pos/api/shiftApi.ts';
        $deviceClientPath = $repoRoot.'/apps/pos/src/api/reportApi.ts';

        if (! is_file($webClientPath) || ! is_file($deviceClientPath)) {
            $this->markTestSkipped('Shipped-client inventory requires the monorepo web and device apps.');
        }

        $webClient = $this->read($webClientPath);
        $deviceClient = $this->read($deviceClientPath);

        $this->assertMatchesRegularExpression(
            '/export interface ZReportData\s*\{\s*terminal_id: string\s*\}/s',
            $webClient,
        );
        $this->assertMatchesRegularExpression(
            '/@deprecated[\s\S]*?generateZReportServer\(terminalId: string\)[\s\S]*?\{ terminal_id: terminalId \}/',
            $deviceClient,
        );
    }

    public function test_z_report_generation_call_sites_are_known_and_cutover_guarded(): void
    {
        $this->assertSame([
            'app/Modules/POS/Application/Services/ReportGenerationService.php:public function generateZReport(',
            'app/Modules/POS/Presentation/Controllers/ReportController.php:$zReport = $this->reportGenerationService->generateZReport(',
            'app/Modules/POS/Presentation/Controllers/ReportController.php:public function generateZReport(GenerateZReportRequest $request): JsonResponse',
        ], $this->callSites('generateZReport('));

        $this->assertSame([
            'app/Modules/POS/Application/Services/ReportGenerationService.php:public function generateXReport(',
            'app/Modules/POS/Presentation/Controllers/ReportController.php:$xReport = $this->reportGenerationService->generateXReport(',
            'app/Modules/POS/Presentation/Controllers/ReportController.php:public function generateXReport(Request $request): JsonResponse',
        ], $this->callSites('generateXReport('));

        $this->assertSame([
            'app/Modules/POS/Application/Services/ReportGenerationService.php:$this->grandtotalService->createGrandtotalEvent(',
            'app/Modules/POS/Domain/Services/GrandtotalService.php:public function createGrandtotalEvent(',
        ], $this->callSites('createGrandtotalEvent('));

        $service = $this->read(base_path('app/Modules/POS/Application/Services/ReportGenerationService.php'));
        $this->assertStringContainsString("assertServerReportAuthoringAllowed(\$terminal, 'X_REPORT')", $service);
        $this->assertStringContainsString("assertServerReportAuthoringAllowed(\$terminal, 'Z_REPORT')", $service);
        $this->assertStringContainsString('fiscal_schema_version ?? 2) >= 3', $service);

        $controller = $this->read(base_path('app/Modules/POS/Presentation/Controllers/ReportController.php'));
        $this->assertStringContainsString('Z_SESSION_DEVICE_AUTHORITY_REQUIRED', $controller);

        $syncController = $this->read(base_path('app/Modules/POS/Presentation/Controllers/ZReportSyncController.php'));
        $this->assertStringContainsString('Z_SESSION_DEVICE_AUTHORITY_REQUIRED', $syncController);
        $this->assertStringContainsString('Legacy Z-report sync is retired for cutover terminal', $syncController);
    }

    public function test_legacy_report_routes_remain_inventory_pinned(): void
    {
        $routes = $this->read(base_path('app/Modules/POS/routes.php'));

        $this->assertStringContainsString("Route::post('/pos/reports/z/sync', [ZReportSyncController::class, 'sync']);", $routes);
        $this->assertStringContainsString("Route::post('/pos/reports/x', [ReportController::class, 'generateXReport']);", $routes);
        $this->assertStringContainsString("Route::post('/pos/reports/z', [ReportController::class, 'generateZReport']);", $routes);
        $this->assertStringContainsString("Route::get('/pos/reports/z', [ReportController::class, 'listZReports']);", $routes);
    }

    /**
     * @return list<string>
     */
    private function callSites(string $needle): array
    {
        $sites = [];
        $root = base_path('app');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($contents);

            foreach ($contents as $line) {
                if (! str_contains((string) $line, $needle)) {
                    continue;
                }

                $sites[] = str_replace($root.'/', 'app/', $file->getPathname()).':'.trim((string) $line);
            }
        }

        sort($sites, SORT_STRING);

        return $sites;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        $this->assertIsString($contents, sprintf('Expected to read %s', $path));

        return $contents;
    }
}
