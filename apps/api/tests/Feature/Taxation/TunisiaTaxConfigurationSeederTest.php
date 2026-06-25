<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TunisiaTaxConfigurationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_stamp_is_active_but_receipt_stamp_is_gated_off(): void
    {
        (new CountriesSeeder)->run();
        (new TunisiaTaxConfigurationSeeder)->run();

        $invoiceStamp = TaxConfiguration::where('code', 'STAMP_TAX_INVOICE')->firstOrFail();
        $receiptStamp = TaxConfiguration::where('code', 'STAMP_FISCAL_RECEIPT')->firstOrFail();

        // The 1.000 TND invoice timbre applies to every Tunisian business.
        $this->assertTrue($invoiceStamp->is_active, 'Invoice timbre must be active for all TN businesses');

        // The 0.100 TND ticket timbre is legally limited to grandes surfaces /
        // DGE-DME stores / foreign-brand franchisees (Art. 117-10° / 135 bis).
        // It must be seeded OFF so ordinary retailers/parapharmacies do not
        // over-collect it; a qualifying tenant activates it via the tax UI.
        $this->assertFalse($receiptStamp->is_active, 'Receipt timbre must be gated off by default');
    }
}
