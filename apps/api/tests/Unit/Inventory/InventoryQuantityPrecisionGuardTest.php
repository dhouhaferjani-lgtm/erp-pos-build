<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Architecture guard against quantity-precision drift in the Inventory module.
 *
 * Inventory quantities are stored at decimal(15,4) and must be computed at
 * scale 4 end to end. The historical bug was a scale-2 chokepoint
 * (StockAdjustmentService::SCALE = 2 + decimal:2 casts) that silently truncated
 * 4-decimal quantities. These assertions fail loudly if that regresses.
 *
 * Pure file scan — no database, no framework boot.
 */
class InventoryQuantityPrecisionGuardTest extends TestCase
{
    private const MODULE_PATH = __DIR__.'/../../../app/Modules/Inventory';

    /**
     * bcmath sites that legitimately pass a literal `2` as the scale argument
     * and are NOT quantity math. Keyed by the trimmed source line so the
     * allowlist re-evaluates if the line changes.
     *
     * - Tax-rate comparison: tax rates are a separate precision domain (handled
     *   by their own columns) and are explicitly out of scope for the quantity
     *   precision rule.
     *
     * @var list<string>
     */
    private const LITERAL_SCALE_2_ALLOWLIST = [
        '&& bccomp($taxRate, $lineTaxRate, 2) === 0) {',
    ];

    /**
     * Every Inventory model quantity property and the scale it must cast to.
     * Must match the decimal(15,4) storage scale.
     *
     * @var array<string, list<string>>
     */
    private const MODEL_QUANTITY_CASTS = [
        'Domain/StockLevel.php' => ['quantity', 'reserved', 'min_quantity', 'max_quantity'],
        'Domain/StockMovement.php' => ['quantity', 'quantity_before', 'quantity_after'],
        'Domain/StockReservation.php' => ['quantity'],
        'Domain/InventoryCountingItem.php' => [
            'theoretical_qty', 'count_1_qty', 'count_2_qty', 'count_3_qty', 'final_qty',
        ],
    ];

    public function test_no_scale_constant_equals_two_in_inventory_module(): void
    {
        $offenders = [];

        foreach ($this->inventoryPhpFiles() as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/const\s+\w*SCALE\w*\s*=\s*2\s*;/i', $contents) === 1) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A SCALE constant set to 2 was found in the Inventory module. Quantity math must use scale 4.\n"
            .implode("\n", $offenders),
        );
    }

    public function test_no_quantity_bcmath_uses_literal_scale_two(): void
    {
        $bcPattern = '/\bbc(?:add|sub|mul|div|comp|mod|pow)\s*\(/';
        $offenders = [];

        foreach ($this->inventoryPhpFiles() as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            foreach ($lines as $index => $line) {
                if (preg_match($bcPattern, $line) !== 1) {
                    continue;
                }
                // bcmath call whose last argument before the closing paren is a literal 2.
                if (preg_match('/,\s*2\s*\)/', $line) !== 1) {
                    continue;
                }
                if (in_array(trim($line), self::LITERAL_SCALE_2_ALLOWLIST, true)) {
                    continue;
                }
                $offenders[] = $this->relative($file).':'.($index + 1).' — '.trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'bcmath call(s) in the Inventory module pass a literal scale of 2. Quantity math must use scale 4 '
            ."(or a named scale constant/helper). If this is a non-quantity scale, add it to the allowlist with justification.\n"
            .implode("\n", $offenders),
        );
    }

    public function test_inventory_model_quantity_casts_use_scale_four(): void
    {
        foreach (self::MODEL_QUANTITY_CASTS as $relativePath => $properties) {
            $file = self::MODULE_PATH.'/'.$relativePath;
            $this->assertFileExists($file, "Expected Inventory model {$relativePath} to exist.");
            $contents = (string) file_get_contents($file);

            foreach ($properties as $property) {
                $this->assertMatchesRegularExpression(
                    "/'".preg_quote($property, '/')."'\s*=>\s*'decimal:4'/",
                    $contents,
                    "{$relativePath}: quantity property '{$property}' must cast to decimal:4 to match the "
                    .'decimal(15,4) storage scale.',
                );
                $this->assertDoesNotMatchRegularExpression(
                    "/'".preg_quote($property, '/')."'\s*=>\s*'decimal:[0-3]'/",
                    $contents,
                    "{$relativePath}: quantity property '{$property}' must not cast below decimal:4.",
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function inventoryPhpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::MODULE_PATH, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $absolute): string
    {
        $marker = 'app/Modules/Inventory/';
        $pos = strpos($absolute, $marker);

        return $pos === false ? $absolute : substr($absolute, $pos);
    }
}
