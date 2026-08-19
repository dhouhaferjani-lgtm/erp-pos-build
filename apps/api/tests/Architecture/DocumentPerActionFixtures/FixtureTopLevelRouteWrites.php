<?php

declare(strict_types=1);

namespace Tests\Architecture\DocumentPerActionFixtures;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Inventory\Domain\StockLevel;
use Illuminate\Support\Facades\Route;

/**
 * TOP-LEVEL code — the `routes.php` file class (M1 gate round 4, finding 1).
 *
 * 50 files under `app/` carry top-level statements (46 `routes.php` plus the
 * POS route files). Their closure bodies live in no class and in no named
 * function, so before the top-level pass the scanner never opened them: a
 * fully-resolvable `JournalEntry::create([...])` inside an inline route closure
 * emitted NO site at all — not a violation, not `not_applicable`, nothing.
 *
 * Everything here is attributed to the synthetic scope `(top-level)`, so the
 * whole file body is ONE pairing scope — which is why the linked counterpart
 * lives in its own file (`FixtureTopLevelLinkedWrite.php`): a movement anywhere
 * in this file would credit every level write in it. That scope semantics is
 * stated in blind spot B.
 *
 * PARSED, never executed or registered.
 */
Route::middleware(['api'])->group(function (): void {
    Route::post('/fixture/unlinked-entry', function (): void {
        JournalEntry::create([
            'entry_number' => 'JE-TL-1',
            'description' => 'posted straight from a route closure',
        ]);
    });

    Route::post('/fixture/unlinked-level', function (): void {
        StockLevel::create([
            'product_id' => 'p-1',
            'location_id' => 'l-1',
            'quantity' => '3.0000',
        ]);
    });
});
