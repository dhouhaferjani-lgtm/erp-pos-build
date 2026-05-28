<?php

declare(strict_types=1);

use App\Modules\Taxation\Domain\Entities\StampDutyRule;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Migrate existing stamp_duty_rules to tax_configurations table
     */
    public function up(): void
    {
        // Get all active stamp duty rules
        $stampDutyRules = StampDutyRule::where('is_active', true)->get();

        foreach ($stampDutyRules as $rule) {
            // Check if already migrated (by checking for existing config with same code)
            // Keep code short to fit in varchar(20) column
            $typeShort = match ($rule->document_type->value) {
                'invoice' => 'INV',
                'credit_note' => 'CN',
                'receipt' => 'REC',
                default => substr($rule->document_type->value, 0, 3),
            };

            $fiscalShort = $rule->fiscal_category ? match ($rule->fiscal_category->value) {
                'TAX_INVOICE' => 'TI',
                'FISCAL_RECEIPT' => 'FR',
                default => substr($rule->fiscal_category->value, 0, 2),
            } : null;

            $code = "STAMP_{$rule->country_code}_{$typeShort}".($fiscalShort ? "_{$fiscalShort}" : '');
            // Example: "STAMP_TN_INV_TI" (15 chars)

            $exists = TaxConfiguration::where('code', $code)
                ->where('is_stamp_duty', true)
                ->exists();

            if ($exists) {
                continue; // Already migrated, skip
            }

            // Create tax configuration from stamp duty rule
            TaxConfiguration::create([
                'id' => Str::uuid()->toString(),
                'country_code' => $rule->country_code,
                'tax_type' => 'FIXED_AMOUNT',
                'name' => "Stamp Duty - {$rule->document_type->value}".($rule->fiscal_category ? " ({$rule->fiscal_category->value})" : ''),
                'code' => $code,
                'percentage_rate' => null,
                'fixed_amount' => $rule->stamp_amount,
                'applies_to' => 'DOCUMENT_TOTAL',
                'is_default' => false,
                'is_active' => $rule->is_active,
                'sequence_order' => 99, // Apply stamp duty last
                'stacks_on' => 'TOTAL_INCLUDING_PREVIOUS',
                'applicable_document_types' => json_encode([$rule->document_type->value]),
                'is_stamp_duty' => true,
                'metadata' => json_encode([
                    'fiscal_category' => $rule->fiscal_category?->value,
                    'effective_from' => $rule->effective_from?->format('Y-m-d'),
                    'effective_to' => $rule->effective_to?->format('Y-m-d'),
                    'migrated_from_stamp_duty_rule' => $rule->id,
                    'migrated_at' => now()->toIso8601String(),
                ]),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Remove migrated stamp duty configurations
     */
    public function down(): void
    {
        // Delete all tax configurations that were migrated from stamp_duty_rules
        TaxConfiguration::where('is_stamp_duty', true)
            ->whereNotNull('metadata')
            ->whereRaw("metadata->>'migrated_from_stamp_duty_rule' IS NOT NULL")
            ->delete();
    }
};
