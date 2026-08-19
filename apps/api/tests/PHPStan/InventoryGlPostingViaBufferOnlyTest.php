<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\PHPStan\Rules\InventoryGlPostingViaBufferOnly;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<InventoryGlPostingViaBufferOnly> */
final class InventoryGlPostingViaBufferOnlyTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new InventoryGlPostingViaBufferOnly;
    }

    public function test_flags_a_writer_that_calls_the_posting_service_directly(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/DirectInventoryGlPostingCall.php'],
            [[
                'InventoryGlPostingService::postFor* may only be called from '
                .'App\\Modules\\Inventory\\Application\\Services\\InventoryGlPostingBuffer::flushIfOutermost(). Writers must enqueue.',
                15,
            ]],
        );
    }

    public function test_accepts_a_writer_that_only_enqueues(): void
    {
        $this->analyse([__DIR__.'/Fixtures/EnqueueOnlyInventoryGlWriter.php'], []);
    }
}
