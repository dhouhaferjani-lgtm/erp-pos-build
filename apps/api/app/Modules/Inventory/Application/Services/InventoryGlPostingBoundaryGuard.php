<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use Illuminate\Support\Facades\DB;

/**
 * Test-only terminating assertion for D-28 Guard 4.
 *
 * Production has the after-commit alarm; tests additionally fail at every
 * request/job boundary so an unregistered composite root cannot hide behind a
 * scenario-local assertion.
 */
final class InventoryGlPostingBoundaryGuard
{
    public function __construct(private readonly InventoryGlPostingBuffer $buffer) {}

    public function assertEmpty(string $boundary): void
    {
        // RefreshDatabase wraps most legacy feature tests in a transaction.
        // Those tests cannot expose production root-commit semantics: an app
        // root is depth two and must defer to the test wrapper. A boundary at
        // depth zero is therefore the universal discriminator for tests that
        // exercised a real application root; discard wrapper-deferred state.
        if (DB::transactionLevel() !== 0) {
            $this->buffer->reset();

            return;
        }

        if ($this->buffer->isEmpty()) {
            return;
        }

        $this->buffer->reset();

        throw new \LogicException("Inventory GL posting buffer leaked at {$boundary} boundary.");
    }
}
