<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Compliance\Services\Nf525\Nf525JetExportService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Byte-stable snapshot test for the NF525 JET XML export.
 *
 * Captured pre-H3 to gate the H3 refactor: before/after refactor must produce
 * byte-identical XML. The fixture lives under tests/Fixtures/Nf525/.
 *
 * Determinism strategy:
 * - All UUIDs assigned explicitly.
 * - Carbon::setTestNow() pins the export-date timestamp.
 * - Configured certification number pinned via config().
 * - All datetimes set with explicit Carbon literals.
 */
class Nf525ExportSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ID = '00000000-0000-4000-8000-000000000001';

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000002';

    private const LOCATION_ID = '00000000-0000-4000-8000-000000000003';

    private const TERMINAL_ID = '00000000-0000-4000-8000-000000000004';

    private const CASHIER_ID = '00000000-0000-4000-8000-000000000005';

    private const RECEIPT_ID = '00000000-0000-4000-8000-000000000010';

    private const RECEIPT_LINE_ID = '00000000-0000-4000-8000-000000000011';

    private const RECEIPT_VAT_ID = '00000000-0000-4000-8000-000000000012';

    private const FROZEN_NOW = '2026-04-28T12:00:00+00:00';

    private const POSTED_AT = '2026-04-15T10:30:00+00:00';

    public function test_jet_export_xml_is_byte_stable_against_fixture(): void
    {
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW));
        config()->set('compliance.nf525_certification_number', 'TEST-CERT-001');
        config()->set('app.version', '1.0.0-snapshot');

        $this->seedDeterministicFixture();

        /** @var Nf525JetExportService $service */
        $service = $this->app->make(Nf525JetExportService::class);

        $xml = $service->exportJet(
            self::COMPANY_ID,
            Carbon::parse('2026-04-01'),
            Carbon::parse('2026-04-30'),
        );

        $fixturePath = __DIR__.'/../../Fixtures/Nf525/jet_export_v1.xml';

        if (! file_exists($fixturePath)) {
            file_put_contents($fixturePath, $xml);
            $this->markTestIncomplete(
                'Snapshot fixture written at '.$fixturePath.'. Re-run to assert byte stability.'
            );
        }

        $expected = (string) file_get_contents($fixturePath);

        $this->assertSame(
            $expected,
            $xml,
            'NF525 JET XML diverged from fixture — byte stability gate violated. '
            .'If a deliberate change to the export shape was made, re-create the fixture.'
        );
    }

    private function seedDeterministicFixture(): void
    {
        $tenant = new Tenant;
        $tenant->id = self::TENANT_ID;
        $tenant->name = 'Snapshot Tenant';
        $tenant->slug = 'snapshot-tenant';
        $tenant->status = TenantStatus::Active;
        $tenant->plan = SubscriptionPlan::Professional;
        $tenant->save();

        $company = new Company;
        $company->id = self::COMPANY_ID;
        $company->tenant_id = self::TENANT_ID;
        $company->name = 'Snapshot Company SARL';
        $company->country_code = 'FR';
        $company->currency = 'EUR';
        $company->locale = 'fr_FR';
        $company->timezone = 'Europe/Paris';
        $company->save();

        $location = new Location;
        $location->id = self::LOCATION_ID;
        $location->company_id = self::COMPANY_ID;
        $location->name = 'Snapshot Store';
        $location->type = 'shop';
        $location->is_active = true;
        $location->save();

        $cashier = new User;
        $cashier->id = self::CASHIER_ID;
        $cashier->tenant_id = self::TENANT_ID;
        $cashier->name = 'Snapshot Cashier';
        $cashier->email = 'snapshot-cashier@example.com';
        $cashier->password = 'password123';
        $cashier->status = UserStatus::Active;
        $cashier->save();

        $terminal = new Terminal;
        $terminal->id = self::TERMINAL_ID;
        $terminal->tenant_id = self::TENANT_ID;
        $terminal->company_id = self::COMPANY_ID;
        $terminal->location_id = self::LOCATION_ID;
        $terminal->type = TerminalType::Physical;
        $terminal->code = 'POS01';
        $terminal->name = 'Snapshot Terminal 1';
        $terminal->genesis_seed = 'deadbeef'.str_repeat('0', 56);
        $terminal->current_sequence = 1;
        $terminal->current_year = 2026;
        $terminal->is_active = true;
        $terminal->last_hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $terminal->save();

        $receipt = new Receipt;
        $receipt->id = self::RECEIPT_ID;
        $receipt->tenant_id = self::TENANT_ID;
        $receipt->company_id = self::COMPANY_ID;
        $receipt->location_id = self::LOCATION_ID;
        $receipt->terminal_id = self::TERMINAL_ID;
        $receipt->receipt_number = 'POS01-2026-00000001';
        $receipt->receipt_type = ReceiptType::Sale;
        $receipt->chain_sequence = 1;
        $receipt->receipt_year = 2026;
        $receipt->fiscal_hash = str_repeat('a', 64);
        $receipt->previous_hash = null;
        $receipt->vat_breakdown_hash = str_repeat('b', 64);
        $receipt->payment_methods_hash = str_repeat('c', 64);
        $receipt->posted_at = Carbon::parse(self::POSTED_AT);
        $receipt->cashier_id = self::CASHIER_ID;
        $receipt->cashier_name = 'Snapshot Cashier';
        $receipt->subtotal = '100.000';
        $receipt->tax_amount = '20.000';
        $receipt->discount_amount = '0.000';
        $receipt->total = '120.000';
        $receipt->currency = 'EUR';
        $receipt->customer_name = 'Snapshot Customer';
        $receipt->is_voided = false;
        $receipt->is_training = false;
        $receipt->save();

        $line = new ReceiptLine;
        $line->id = self::RECEIPT_LINE_ID;
        $line->receipt_id = self::RECEIPT_ID;
        $line->line_number = 1;
        $line->product_code = 'PROD001';
        $line->product_name = 'Snapshot Product';
        $line->quantity = '2';
        $line->unit = 'pc';
        $line->unit_price = '50.000';
        $line->line_total = '100.000';
        $line->tax_rate = '20.000';
        $line->tax_amount = '20.000';
        $line->discount_amount = '0.000';
        $line->save();

        $vat = new ReceiptVatDetail;
        $vat->id = self::RECEIPT_VAT_ID;
        $vat->receipt_id = self::RECEIPT_ID;
        $vat->tax_rate = '20.000';
        $vat->net_amount = '100.000';
        $vat->vat_amount = '20.000';
        $vat->gross_amount = '120.000';
        $vat->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
