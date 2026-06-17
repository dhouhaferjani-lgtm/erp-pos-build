<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Shared\Domain\Exceptions\MatrixGenerationLimitException;
use Tests\TestCase;

class MatrixGenerationLimitExceptionTest extends TestCase
{
    public function test_exceeded_builds_message_with_counts(): void
    {
        $e = MatrixGenerationLimitException::exceeded(201, 200);

        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertStringContainsString('201', $e->getMessage());
        $this->assertStringContainsString('200', $e->getMessage());
    }
}
