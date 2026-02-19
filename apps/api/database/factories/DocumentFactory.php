<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Document\Domain\Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Create tenant first if needed
        $tenant = Tenant::first();
        if (! $tenant) {
            $tenant = Tenant::factory()->create();
        }

        // Get or create company with tenant
        $company = Company::first();
        if (! $company) {
            $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        }

        // Get or create partner
        $partner = Partner::first();
        if (! $partner) {
            $partner = Partner::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);
        }

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-'.$this->faker->unique()->numberBetween(1000, 9999),
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
            'balance_due' => '120.00',
            'is_historical' => false,
        ];
    }

    /**
     * Indicate that the document is a quote.
     */
    public function quote(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentType::Quote,
            'document_number' => 'QUO-'.$this->faker->unique()->numberBetween(1000, 9999),
            'valid_until' => now()->addDays(30),
        ]);
    }

    /**
     * Indicate that the document is a sales order.
     */
    public function salesOrder(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentType::SalesOrder,
            'document_number' => 'SO-'.$this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    /**
     * Indicate that the document is posted.
     */
    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the document is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Test cancellation',
        ]);
    }

    /**
     * Set custom total and balance.
     */
    public function withTotal(string $total, ?string $balanceDue = null): static
    {
        $balanceDue = $balanceDue ?? $total;

        return $this->state(fn (array $attributes) => [
            'subtotal' => bcmul($total, '0.833333', 2), // Approximate subtotal (excluding 20% VAT)
            'tax_amount' => bcmul($total, '0.166667', 2), // Approximate VAT (20%)
            'total' => $total,
            'balance_due' => $balanceDue,
        ]);
    }
}
