<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Architecture guard for the ProductCostLock serialization seam.
 *
 * The WAC serialization foundation relies on a single advisory-lock seam
 * (ProductCostLock::acquire) wrapping every code path that either recomputes
 * the weighted-average cost or creates a new stock_level row. Pure decrement
 * paths intentionally stay OUTSIDE the seam: they only mutate an already
 * existing row and serialize against an in-flight recompute via the row locks
 * they take (lockForUpdate/firstOrFail). This test pins both halves of that
 * contract so a future edit cannot silently drop the seam from a recompute
 * path or wrap a pure decrement in a redundant (deadlock-prone) lock.
 */
final class InventoryCostLockCoverageTest extends TestCase
{
    /**
     * Methods that recompute the WAC or can create a stock_level row.
     * Each MUST take the ProductCostLock seam.
     *
     * @return array<int, array{string, string}>
     */
    public static function mustLockProvider(): array
    {
        return [
            ['app/Modules/Inventory/Application/Services/WeightedAverageCostService.php', 'public function recordPurchase('],
            ['app/Modules/Inventory/Application/Services/WeightedAverageCostService.php', 'public function recordReturn('],
            ['app/Modules/Inventory/Application/Services/WeightedAverageCostService.php', 'public function recordCostAdjustment('],
            ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function receive('],
            ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function adjust('],
            ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function adjustByDelta('],
            ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function transfer('],
            ['app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php', 'public function post('],
            ['app/Modules/Inventory/Application/Services/InventoryService.php', 'public function upsertStockLevel('],
        ];
    }

    /**
     * Pure decrement methods that operate only on an existing row.
     * Each MUST NOT take the ProductCostLock seam.
     *
     * @return array<int, array{string, string}>
     */
    public static function mustNotLockProvider(): array
    {
        return [
            ['app/Modules/Inventory/Application/Services/WeightedAverageCostService.php', 'public function recordSale('],
            ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function issue('],
        ];
    }

    #[Test]
    #[DataProvider('mustLockProvider')]
    public function test_recompute_or_creating_methods_take_the_seam_lock(string $path, string $sig): void
    {
        $body = $this->extractMethodBody(base_path($path), $sig);

        $this->assertStringContainsString(
            'costLock->acquire',
            $body,
            "{$sig} in {$path} recomputes the weighted-average cost or can create a "
            .'stock_level row, so it MUST acquire the ProductCostLock serialization '
            .'seam (costLock->acquire). Without it, a concurrent recompute can read a '
            .'stale cost or race to create a phantom row.'
        );
    }

    #[Test]
    #[DataProvider('mustNotLockProvider')]
    public function test_pure_decrement_methods_stay_lock_free(string $path, string $sig): void
    {
        $body = $this->extractMethodBody(base_path($path), $sig);

        $this->assertStringNotContainsString(
            'costLock->acquire',
            $body,
            "{$sig} in {$path} is a pure decrement that only mutates an existing "
            .'stock_level row. It MUST NOT acquire the ProductCostLock seam: it already '
            .'serializes against an in-flight recompute via the existing row locks it '
            .'takes, and wrapping it in the seam adds a redundant, deadlock-prone lock.'
        );
    }

    /**
     * Multi-product seam callers: each opens ONE transaction and drives a loop
     * that calls a per-product advisory-locking seam (recordPurchase /
     * recordReturn / recordCostAdjustment / receive, or a per-row
     * costLock->acquire). The deadlock defense is to acquire ALL the products'
     * advisory locks UP-FRONT in ONE sorted ProductCostLock::acquire BEFORE the
     * loop (ProductCostLock sorts internally), so the nested per-iteration
     * acquires are re-entrant on already-held xact locks. A per-iteration single
     * acquire instead accumulates per-product locks in row/line order (unsorted)
     * within the one transaction, AB-BA deadlocking two concurrent runs over
     * overlapping products in different orders.
     *
     * @return array<int, array{string, string}>
     */
    public static function multiProductSeamCallerProvider(): array
    {
        return [
            ['app/Modules/Inventory/Application/Services/StockTransferService.php', 'public function complete('],
            ['app/Modules/Inventory/Application/Services/StockTransferService.php', 'public function cancel('],
            ['app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php', 'public function post('],
            // The DELEGATE receiveGoods() hands off to. Pinned HERE, not just as a
            // string match in the delegating test, so its acquire-BEFORE-the-seam-loop
            // ordering — the actual deadlock defence — is asserted (gate N-3).
            ['app/Modules/Inventory/Application/Services/GoodsReceiptService.php', 'public function post('],
            ['app/Modules/Document/Domain/Services/ReturnNoteService.php', 'public function confirm('],
            ['app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php', 'public function post('],
        ];
    }

    /**
     * DELEGATING callers: a method that opens the transaction but hands the whole
     * seam loop to another method in the same class. The up-front sorted acquire
     * is held by the DELEGATE, so asserting `costLock->acquire(` in the caller's
     * own body is the wrong assertion — it was failing here for that reason
     * (gate code-review I-4), and a knowingly-red architecture test is how a
     * guard stops being trusted.
     *
     * What must hold instead: the caller delegates to a method that IS itself
     * pinned by multiProductSeamCallerProvider — GoodsReceiptService::post( is a
     * row there, so the delegate's acquire-before-the-loop ordering is asserted
     * rather than merely assumed.
     *
     * @return array<int, array{string, string, string}>
     */
    public static function delegatingSeamCallerProvider(): array
    {
        return [
            [
                'app/Modules/Inventory/Application/Services/GoodsReceiptService.php',
                'public function receiveGoods(',
                'this->post(',
            ],
        ];
    }

    #[Test]
    #[DataProvider('delegatingSeamCallerProvider')]
    public function test_delegating_seam_callers_hand_off_to_a_pinned_locker(
        string $path,
        string $sig,
        string $delegate,
    ): void {
        $body = $this->extractMethodBody(base_path($path), $sig);

        $this->assertStringContainsString(
            $delegate,
            $body,
            "{$sig} in {$path} takes NO advisory lock of its own, so it must delegate the seam loop to "
            ."a method that does ({$delegate}). If this ever stops delegating, move the row back to "
            .'multiProductSeamCallerProvider — it would then need its own up-front sorted acquire.'
        );

        $this->assertStringNotContainsString(
            'costLock->acquire(',
            $body,
            "{$sig} in {$path} is registered as a DELEGATING caller but now acquires the seam itself. "
            .'Move it to multiProductSeamCallerProvider so the acquire-before-the-loop ordering is pinned.'
        );

        // And the delegate it hands off to must itself be a pinned locker.
        $post = $this->extractMethodBody(base_path($path), 'public function post(');
        $this->assertStringContainsString(
            'costLock->acquire(',
            $post,
            "The delegate of {$sig} must hold the up-front advisory lock."
        );
    }

    #[Test]
    #[DataProvider('multiProductSeamCallerProvider')]
    public function test_multi_product_seam_callers_acquire_locks_up_front(string $path, string $sig): void
    {
        $body = $this->extractMethodBody(base_path($path), $sig);

        $acquirePos = strpos($body, 'costLock->acquire(');

        $this->assertNotFalse(
            $acquirePos,
            "{$sig} in {$path} must acquire all product advisory locks UP-FRONT (sorted) "
            .'before iterating — a per-iteration acquire accumulates unsorted locks and '
            .'deadlocks (see WAC foundation review r2/r3/r4).'
        );

        // Target the acquire-before-the-SEAM-LOOP ordering, using the LAST
        // foreach as the seam loop's position. Two of these methods legitimately
        // run a benign foreach BEFORE the acquire — a validation / id-collection
        // pre-pass that builds the product-id list to lock (e.g. postBatch's
        // unique-id gather). That pre-pass takes no locks, so it cannot
        // accumulate the unsorted advisory locks the deadlock requires; only the
        // SEAM loop (which calls the per-product locking seam) must run AFTER the
        // up-front acquire. The seam loop is always the LAST foreach: it lives
        // inside the acquire closure (cancel, postBatch) or in a helper the
        // closure calls (complete, receiveGoods, confirm — no foreach in this
        // body at all). So the acquire must precede the LAST foreach when one
        // exists in this body.
        $lastForeachPos = strrpos($body, 'foreach');

        if ($lastForeachPos !== false) {
            $this->assertLessThan(
                $lastForeachPos,
                $acquirePos,
                "{$sig} in {$path} must acquire all product advisory locks UP-FRONT (sorted) "
                .'before iterating — a per-iteration acquire accumulates unsorted locks and '
                .'deadlocks (see WAC foundation review r2/r3/r4).'
            );
        }
    }

    /**
     * DPA V7 / T2 (inventory gate I-2) — close the PREFIX hole.
     *
     * extractMethodBody() locates a method with a bare `strpos($source,
     * $signature)`, and `'public function adjust'` is a PREFIX of
     * `'public function adjustByDelta'`. If the new method were ever declared
     * ABOVE adjust(), the `adjust` row would extract the WRONG body, both bodies
     * contain `costLock->acquire`, the assertion would pass — and the pin on
     * adjust() would be silently gone.
     *
     * Every provider row now carries a trailing `(` so the paren disambiguates.
     * This test is the belt to that braces: the two extracted bodies must not be
     * the same string, which is only possible if one signature matched the other.
     */
    #[Test]
    public function test_adjust_and_adjust_by_delta_extract_distinct_bodies(): void
    {
        $path = base_path('app/Modules/Inventory/Domain/Services/StockAdjustmentService.php');

        $adjust = $this->extractMethodBody($path, 'public function adjust(');
        $adjustByDelta = $this->extractMethodBody($path, 'public function adjustByDelta(');

        $this->assertNotSame(
            $adjust,
            $adjustByDelta,
            "'public function adjust' is a PREFIX of 'public function adjustByDelta': if the two "
            .'extracted bodies are identical, one signature matched the other and the pin on the '
            .'shorter name is silently gone. Keep the trailing "(" on every provider row.'
        );

        // Both must be real bodies, not one truncated by a bad match.
        $this->assertStringContainsString('costLock->acquire(', $adjust);
        $this->assertStringContainsString('costLock->acquire(', $adjustByDelta);
        // Only the delta form carries the staleness / availability guards.
        $this->assertStringContainsString('StockMovedSinceAuthoringException', $adjustByDelta);
        $this->assertStringNotContainsString('StockMovedSinceAuthoringException', $adjust);
    }

    #[Test]
    public function test_lock_free_methods_operate_only_on_existing_rows(): void
    {
        // recordSale loads the EXISTING stock_level row with lockForUpdate()->firstOrFail();
        // firstOrFail throws rather than creating a row, so it can never produce the
        // phantom row that a pure row-lock strategy would miss.
        $recordSale = $this->extractMethodBody(
            base_path('app/Modules/Inventory/Application/Services/WeightedAverageCostService.php'),
            'public function recordSale'
        );

        $this->assertStringContainsString(
            'firstOrFail',
            $recordSale,
            'recordSale must load the EXISTING stock_level row via firstOrFail(): being '
            .'lock-free is only safe because it mutates an already-existing row and cannot '
            .'create the phantom rows that pure row-locking would miss, so it serializes '
            .'against an in-flight recompute through the row locks it takes.'
        );

        // issue loads the EXISTING row via lockStockLevel(), which itself uses
        // firstOrFail() — same existing-row-only guarantee.
        $issue = $this->extractMethodBody(
            base_path('app/Modules/Inventory/Domain/Services/StockAdjustmentService.php'),
            'public function issue'
        );

        $this->assertStringContainsString(
            'lockStockLevel',
            $issue,
            'issue must load the EXISTING stock_level row via lockStockLevel(): being '
            .'lock-free is only safe because it mutates an already-existing row and cannot '
            .'create the phantom rows that pure row-locking would miss, so it serializes '
            .'against an in-flight recompute through the row locks it takes.'
        );
    }

    /**
     * Balanced-brace method-body extractor.
     *
     * Locates $signature in the file, finds the first '{' at/after it, then
     * walks characters tracking brace depth until it returns to 0, returning
     * the method body substring (inclusive of the outer braces). The target
     * bodies contain no '{' or '}' inside string literals or comments, so a
     * naive depth counter is balanced and accurate for them.
     */
    private function extractMethodBody(string $absPath, string $signature): string
    {
        $source = file_get_contents($absPath);

        $this->assertNotFalse($source, "Unable to read {$absPath}");

        $sigPos = strpos($source, $signature);
        $this->assertNotFalse($sigPos, "Signature '{$signature}' not found in {$absPath}");

        $openPos = strpos($source, '{', $sigPos);
        $this->assertNotFalse($openPos, "Opening brace for '{$signature}' not found in {$absPath}");

        $depth = 0;
        $length = strlen($source);

        for ($i = $openPos; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $openPos, $i - $openPos + 1);
                }
            }
        }

        $this->fail("Unbalanced braces while extracting body of '{$signature}' in {$absPath}");
    }
}
