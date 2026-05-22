<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Projections\DocumentAccountChargeFactureBridge;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class DocumentAccountChargeFactureBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $customerId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->companyId = $company->id;

        $this->operatorId = Str::uuid()->toString();

        $customer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'customer_category' => CustomerCategory::Business,
            'name' => 'Mariam Pharmacie SARL',
        ]);
        $this->customerId = $customer->id;
    }

    public function test_business_customer_account_charge_creates_invoice_draft_when_sales_module_active(): void
    {
        $event = $this->storeAccountChargeFiscalEvent($this->businessFacturePayload());

        $this->bridge()->apply($event);

        $document = Document::query()->with('lines')->firstOrFail();
        $this->assertSame($this->tenantId, $document->tenant_id);
        $this->assertSame($this->companyId, $document->company_id);
        $this->assertSame($this->customerId, $document->partner_id);
        $this->assertSame(DocumentType::Invoice, $document->type);
        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertSame(FiscalStatus::Draft, $document->fiscal_status);
        $this->assertSame('POS-ACCOUNT-CHARGE:'.$event->id, $document->reference);
        $this->assertNull($document->source_document_id);
        $this->assertSame('100.000', $document->subtotal);
        $this->assertSame('0.000', $document->discount_amount);
        $this->assertSame('19.000', $document->tax_amount);
        $this->assertSame('119.000', $document->total);
        $this->assertSame('119.000', $document->balance_due);
        $this->assertSame($event->id, $document->payload['fiscal_event_id'] ?? null);
        $this->assertSame('66666666-6666-4666-8666-666666666666', $document->payload['account_charge_uuid'] ?? null);

        $this->assertCount(1, $document->lines);
        $line = $document->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);
        $this->assertSame(1, $line->line_number);
        $this->assertSame('Default item', $line->description);
        $this->assertSame('1.0000', $line->quantity);
        $this->assertSame('100.000', $line->unit_price);
        $this->assertSame('0.000', $line->discount_amount);
        $this->assertSame('19.00', $line->tax_rate);
        $this->assertSame('119.000', $line->line_total);
    }

    public function test_individual_customer_account_charge_skips_document_bridge(): void
    {
        $event = $this->storeAccountChargeFiscalEvent($this->accountChargePayload([
            'customer' => [
                'customer_category' => 'individual',
            ],
            'invoice_classification' => 'b2c_charge_receipt',
        ]));

        $this->bridge()->apply($event);

        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, DocumentLine::query()->count());
    }

    public function test_document_bridge_is_idempotent_by_fiscal_event_id(): void
    {
        $event = $this->storeAccountChargeFiscalEvent($this->businessFacturePayload());
        $bridge = $this->bridge();

        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(1, Document::query()
            ->where('reference', 'POS-ACCOUNT-CHARGE:'.$event->id)
            ->count());
        $this->assertSame(1, DocumentLine::query()->count());
    }

    public function test_document_bridge_replay_fails_loud_when_existing_draft_line_does_not_match_payload(): void
    {
        $event = $this->storeAccountChargeFiscalEvent($this->businessFacturePayload());
        $bridge = $this->bridge();

        $bridge->apply($event);

        DocumentLine::query()->update(['description' => 'Tampered draft line']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pos_account_charge_draft_conflict:line_1_description');

        $bridge->apply($event);
    }

    public function test_document_bridge_replay_fails_loud_when_duplicate_same_reference_drafts_exist(): void
    {
        $event = $this->storeAccountChargeFiscalEvent($this->businessFacturePayload());
        $bridge = $this->bridge();

        $bridge->apply($event);

        $document = Document::query()->firstOrFail();
        Document::query()->create([
            'tenant_id' => $document->tenant_id,
            'company_id' => $document->company_id,
            'partner_id' => $document->partner_id,
            'type' => $document->type,
            'fiscal_category' => $document->fiscal_category,
            'fiscal_status' => $document->fiscal_status,
            'status' => $document->status,
            'document_number' => null,
            'document_date' => $document->document_date,
            'due_date' => $document->due_date,
            'currency' => $document->currency,
            'subtotal' => $document->subtotal,
            'discount_amount' => $document->discount_amount,
            'tax_amount' => $document->tax_amount,
            'total' => $document->total,
            'balance_due' => $document->balance_due,
            'source_document_id' => null,
            'reference' => $document->reference,
            'payload' => $document->payload,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pos_account_charge_draft_conflict:multiple_documents_for_event');

        $bridge->apply($event);
    }

    public function test_document_bridge_replay_fails_loud_when_existing_draft_header_does_not_match_contract(): void
    {
        $event = $this->storeAccountChargeFiscalEvent($this->businessFacturePayload());
        $bridge = $this->bridge();

        $bridge->apply($event);

        Document::query()->update(['fiscal_category' => FiscalCategory::NonFiscal]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pos_account_charge_draft_conflict:fiscal_category');

        $bridge->apply($event);
    }

    public function test_document_bridge_fails_loud_for_cross_company_partner(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $foreignCustomer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $otherCompany->id,
            'customer_category' => CustomerCategory::Business,
        ]);
        $event = $this->storeAccountChargeFiscalEvent($this->businessFacturePayload([
            'customer' => [
                'customer_id' => $foreignCustomer->id,
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('customer_not_found');

        try {
            $this->bridge()->apply($event);
        } finally {
            $this->assertSame(0, Document::query()->count());
        }
    }

    public function test_document_bridge_does_not_post_or_tax_invoice_author_in_pos(): void
    {
        $event = $this->storeAccountChargeFiscalEvent($this->businessFacturePayload());

        $this->bridge()->apply($event);

        $document = Document::query()->firstOrFail();
        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertSame(FiscalStatus::Draft, $document->fiscal_status);
        $this->assertNull($document->fiscal_hash);
        $this->assertNull($document->previous_hash);
        $this->assertNull($document->chain_sequence);
        $this->assertSame(1, FiscalEvent::query()->where('event_type', FiscalEventType::ACCOUNT_CHARGE)->count());
        $this->assertSame(0, FiscalEvent::query()->where('event_type', FiscalEventType::SALE_RECEIPT)->count());
    }

    public function test_document_bridge_contract_and_provider_registration(): void
    {
        $bridge = $this->bridge();

        $this->assertSame('document_account_charge_facture_bridge', $bridge->name());
        $this->assertTrue($bridge->handlesEventType(FiscalEventType::ACCOUNT_CHARGE));
        $this->assertFalse($bridge->handlesEventType(FiscalEventType::SALE_RECEIPT));
        $this->assertFalse($bridge->handlesEventType(FiscalEventType::ACCOUNT_PAYMENT));
        $this->assertSame('Sales', $bridge->requiresModule());
        $this->assertSame(170, $bridge->priority());

        $names = array_map(
            static fn (FiscalEventProjector $projector): string => $projector->name(),
            iterator_to_array($this->app->tagged(FiscalEventProjector::class), false),
        );

        $this->assertContains('document_account_charge_facture_bridge', $names);
    }

    private function bridge(): DocumentAccountChargeFactureBridge
    {
        return $this->app->make(DocumentAccountChargeFactureBridge::class);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function storeAccountChargeFiscalEvent(?array $payload = null): FiscalEvent
    {
        $payload ??= $this->accountChargePayload();
        $eventId = Str::uuid()->toString();

        return FiscalEvent::query()->create([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::ACCOUNT_CHARGE,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 9,
            'event_time_device' => '2026-05-21 10:15:30',
            'business_date' => '2026-05-21',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-05-21 10:15:31',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'account_charge',
            'source_event_id' => $payload['account_charge_uuid'] ?? null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $this->canonicalEncode($payload),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function businessFacturePayload(array $overrides = []): array
    {
        return $this->accountChargePayload($this->mergeRecursiveDistinct([
            'buyer' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '2 rue Buyer'],
                'name' => 'Mariam Pharmacie SARL',
                'tax_number' => '1234567BM000',
            ],
            'customer' => [
                'customer_category' => 'business',
                'customer_id' => $this->customerId,
                'name' => 'Mariam Pharmacie SARL',
                'tax_number' => '1234567BM000',
            ],
            'invoice_classification' => 'b2b_facture_draft_requested',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountChargePayload(array $overrides = []): array
    {
        $payload = [
            'account_charge_uuid' => '66666666-6666-4666-8666-666666666666',
            'business_date' => '2026-05-21',
            'buyer' => null,
            'cashier_id' => $this->operatorId,
            'cashier_name' => 'Default Cashier',
            'charge_terms' => ['due_date' => '2026-06-20', 'payment_terms_days' => 30, 'terms_label' => 'Net 30'],
            'credit_decision' => [
                'credit_available_after' => '81.000',
                'credit_available_before' => '200.000',
                'credit_limit' => '500.000',
                'decision' => 'approved',
                'limit_exceeded' => false,
                'mirror_stale_at_authoring' => false,
                'policy_version' => 'phase3-default-v1',
                'stale_policy_action' => 'allow',
                'warnings' => [],
            ],
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'account_identifier' => 'CUST-0001',
                'address' => null,
                'customer_category' => 'individual',
                'customer_id' => $this->customerId,
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Mariam Ben Ali',
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => '2026-05-21T10:15:30.000Z',
            'invoice_classification' => 'b2c_charge_receipt',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '100.000',
                'line_uuid' => '77777777-7777-4777-8777-777777777777',
                'line_vat' => '19.000',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                'unit_price' => '100.000',
                'vat_rate' => '19.00',
            ]],
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'charge_amount' => '119.000',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '419.000',
                'projected_receivable_balance_after' => '419.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'print_profile' => 'ACCOUNT_CHARGE_RECEIPT',
            'receipt_type_code' => 'ACCOUNT_CHARGE',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'staleness' => [
                'balance_snapshot_stale' => false,
                'customer_snapshot_stale' => false,
                'mirror_last_synced_at' => '2026-05-21T10:10:00.000Z',
                'staleness_reason' => null,
            ],
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'totals' => [
                'amount_charged_to_account' => '119.000',
                'grand_total_before_charge' => '119.000',
                'subtotal' => '100.000',
                'total' => '119.000',
                'vat_total' => '19.000',
            ],
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '119.000',
                'net_amount' => '100.000',
                'rate' => '19.00',
                'tax_category_code' => '',
                'vat_amount' => '19.000',
            ]],
        ];

        return $this->mergeRecursiveDistinct($payload, $overrides);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeRecursiveDistinct(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                /** @var array<string, mixed> $baseValue */
                $baseValue = $base[$key];
                /** @var array<string, mixed> $overrideValue */
                $overrideValue = $value;
                $base[$key] = $this->mergeRecursiveDistinct($baseValue, $overrideValue);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        return json_encode($this->sortRecursive($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortRecursive(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->sortRecursive($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        return $value;
    }
}
