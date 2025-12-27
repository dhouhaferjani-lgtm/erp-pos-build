<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Compliance\Domain\FraudAlert;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingEvent;
use Illuminate\Support\Facades\DB;

/**
 * Service for creating inventory counting operations triggered by fraud detection.
 *
 * When fraud patterns are detected (e.g., high draft abandonment rate),
 * the system can automatically create counting operations to verify
 * stock levels for flagged products.
 *
 * This helps detect potential theft or inventory discrepancies.
 */
final class FraudTriggeredCountingService
{
    public function __construct(
        private readonly InventoryCountingService $countingService,
    ) {}

    /**
     * Create inventory counting operation from fraud alert.
     *
     * Extracts flagged products from alert and creates a counting operation
     * scoped to those specific products across all locations.
     *
     * @param  User|null  $assignedUser  User to perform the count (if null, must be assigned later)
     */
    public function createCountingFromAlert(
        FraudAlert $alert,
        ?User $assignedUser = null
    ): InventoryCounting {
        return DB::transaction(function () use ($alert, $assignedUser): InventoryCounting {
            // Extract product IDs from flagged products
            $productIds = [];
            if ($alert->flagged_products !== null) {
                foreach ($alert->flagged_products as $product) {
                    $productIds[] = $product['product_id'];
                }
            }

            if (empty($productIds)) {
                throw new \InvalidArgumentException('Fraud alert has no flagged products to count');
            }

            // Get a system user for creating the counting (fraud detection is system-initiated)
            /** @var User $systemUser */
            $systemUser = User::where('email', 'system@autoerp.local')->first()
                ?? User::first(); // Fallback to first user if system user doesn't exist

            // Create counting operation
            $counting = $this->countingService->create(
                data: [
                    'scope_type' => CountingScopeType::Product,
                    'scope_filters' => [
                        'product_ids' => $productIds,
                    ],
                    'execution_mode' => 'parallel',
                    'requires_count_2' => true, // Double counting for fraud cases
                    'requires_count_3' => false,
                    'allow_unexpected_items' => false,
                    'count_1_user_id' => $assignedUser?->id,
                    'count_2_user_id' => null,
                    'count_3_user_id' => null,
                    'instructions' => $this->generateInstructions($alert),
                ],
                createdBy: $systemUser,
                companyId: $alert->company_id
            );

            // Record fraud alert linkage in counting event
            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => 'fraud_alert_triggered',
                'event_data' => [
                    'fraud_alert_id' => $alert->id,
                    'alert_type' => $alert->alert_type,
                    'severity' => $alert->severity,
                    'flagged_user_id' => $alert->user_id,
                    'product_count' => count($productIds),
                ],
                'user_id' => $systemUser->id,
            ]);

            return $counting;
        });
    }

    /**
     * Generate counting instructions based on fraud alert details.
     */
    private function generateInstructions(FraudAlert $alert): string
    {
        $instructions = "⚠️ FRAUD DETECTION: Automatic counting operation\n\n";
        $instructions .= "Alert Type: {$alert->alert_type}\n";
        $instructions .= "Severity: {$alert->severity}\n";
        $instructions .= "Reason: {$alert->description}\n\n";

        if ($alert->flagged_products !== null && count($alert->flagged_products) > 0) {
            $instructions .= "Flagged Products:\n";
            foreach ($alert->flagged_products as $product) {
                $name = $product['product_name'];
                $count = $product['count'];
                $instructions .= "- {$name} (appeared {$count} times in abandoned drafts)\n";
            }
        }

        $instructions .= "\nPlease verify stock levels carefully and report any discrepancies immediately.";

        return $instructions;
    }
}
