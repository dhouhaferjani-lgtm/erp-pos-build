<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class BatchTraceabilityModuleBoundaryTest extends TestCase
{
    public function test_batch_expiry_traceability_imports_only_shared_contracts(): void
    {
        $paths = glob(dirname(__DIR__, 2).'/app/Modules/BatchExpiry/Presentation/Controllers/*.php');
        self::assertNotEmpty($paths);
        foreach ($paths as $path) {
            preg_match_all('/^use App\\\\Modules\\\\(?:Document|POS)\\\\[^;]+;/m', file_get_contents($path), $violations);
            self::assertSame([], $violations[0], $path.PHP_EOL.implode(PHP_EOL, $violations[0]));
        }
    }
}
