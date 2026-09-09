<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class BatchTraceabilityModuleBoundaryTest extends TestCase
{
    public function test_batch_expiry_traceability_imports_only_shared_contracts(): void
    {
        $path = dirname(__DIR__, 2).'/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php';
        preg_match_all('/^use App\\\\Modules\\\\(?:Document|POS)\\\\[^;]+;/m', file_get_contents($path), $violations);
        self::assertSame([], $violations[0], implode(PHP_EOL, $violations[0]));
    }
}
