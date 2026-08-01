<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\ApprovalEvidenceUnresolvedException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §4.2 — projector-side seven-field
 * approval-evidence verification: present (resolves and matches), absent
 * (cited event does not exist), mismatched (cited events exist but a field
 * diverges) cases.
 */
final class PosCoreReceiptProjectionApprovalEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
            'fiscal_schema_version' => 3,
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Test Cashier']);
        $this->operatorId = $user->id;

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
    }

    public function test_a_refund_citing_a_present_and_matching_approval_reference_projects_cleanly(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->buildSaleEvent($product->id, sequenceNumber: 1, receiptUuid: '00000000-0000-4000-8000-000000000001');
        $this->project($sale);

        $approvalId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $approvalScope = 'void_or_return_override';
        $policyVersion = 'pos-void-policy-v1';
        $supervisorUserId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $targetReferenceId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

        $approvalEvent = $this->buildOperatorApprovalGrantedEvent(
            approvalId: $approvalId,
            approvalScope: $approvalScope,
            policyVersion: $policyVersion,
            supervisorUserId: $supervisorUserId,
            targetReferenceId: $targetReferenceId,
        );

        $overrideEvent = $this->buildOverrideEvent(
            approvalEventId: $approvalEvent->id,
            approvalId: $approvalId,
            approvalScope: $approvalScope,
            policyVersion: $policyVersion,
            supervisorUserId: $supervisorUserId,
            targetReferenceId: $targetReferenceId,
        );

        $refund = $this->buildRefundEvent(
            $product->id,
            originalSale: $sale,
            sequenceNumber: 2,
            receiptUuid: '00000000-0000-4000-8000-000000000002',
            approvalReferences: [[
                'approval_event_id' => $approvalEvent->id,
                'approval_id' => $approvalId,
                'approval_scope' => $approvalScope,
                'override_event_id' => $overrideEvent->id,
                'policy_version' => $policyVersion,
                'supervisor_user_id' => $supervisorUserId,
                'target_reference_id' => $targetReferenceId,
            ]],
        );

        $this->project($refund);

        $refundReceipt = Receipt::where('fiscal_event_id', $refund->id)->sole();
        self::assertSame($refund->id, $refundReceipt->fiscal_event_id);
    }

    public function test_a_refund_citing_an_absent_approval_event_throws(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->buildSaleEvent($product->id, sequenceNumber: 1, receiptUuid: '00000000-0000-4000-8000-000000000001');
        $this->project($sale);

        $refund = $this->buildRefundEvent(
            $product->id,
            originalSale: $sale,
            sequenceNumber: 2,
            receiptUuid: '00000000-0000-4000-8000-000000000002',
            approvalReferences: [[
                'approval_event_id' => (string) Str::uuid(), // does not exist
                'approval_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'approval_scope' => 'void_or_return_override',
                'override_event_id' => (string) Str::uuid(), // does not exist either
                'policy_version' => 'pos-void-policy-v1',
                'supervisor_user_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                'target_reference_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            ]],
        );

        $this->expectException(ApprovalEvidenceUnresolvedException::class);
        $this->expectExceptionMessageMatches('/does not resolve to a signed OPERATOR_APPROVAL_GRANTED event/');
        $this->project($refund);
    }

    public function test_a_refund_citing_a_resolvable_but_mismatched_approval_reference_throws(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenantId, 'company_id' => $this->companyId]);

        $sale = $this->buildSaleEvent($product->id, sequenceNumber: 1, receiptUuid: '00000000-0000-4000-8000-000000000001');
        $this->project($sale);

        $approvalId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $approvalScope = 'void_or_return_override';
        $policyVersion = 'pos-void-policy-v1';
        $supervisorUserId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $targetReferenceId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

        $approvalEvent = $this->buildOperatorApprovalGrantedEvent(
            approvalId: $approvalId,
            approvalScope: $approvalScope,
            policyVersion: $policyVersion,
            supervisorUserId: $supervisorUserId,
            targetReferenceId: $targetReferenceId,
        );

        $overrideEvent = $this->buildOverrideEvent(
            approvalEventId: $approvalEvent->id,
            approvalId: $approvalId,
            approvalScope: $approvalScope,
            policyVersion: $policyVersion,
            supervisorUserId: $supervisorUserId,
            targetReferenceId: $targetReferenceId,
        );

        // Both cited events resolve — but the refund's own reference row
        // claims a DIFFERENT supervisor_user_id than what either signed
        // event actually carries (a forged/corrupted evidence claim).
        $refund = $this->buildRefundEvent(
            $product->id,
            originalSale: $sale,
            sequenceNumber: 2,
            receiptUuid: '00000000-0000-4000-8000-000000000002',
            approvalReferences: [[
                'approval_event_id' => $approvalEvent->id,
                'approval_id' => $approvalId,
                'approval_scope' => $approvalScope,
                'override_event_id' => $overrideEvent->id,
                'policy_version' => $policyVersion,
                'supervisor_user_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', // mismatched
                'target_reference_id' => $targetReferenceId,
            ]],
        );

        $this->expectException(ApprovalEvidenceUnresolvedException::class);
        $this->expectExceptionMessageMatches('/supervisor_user_id mismatch/');
        $this->project($refund);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function project(FiscalEvent $event): void
    {
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);
    }

    private function buildOperatorApprovalGrantedEvent(
        string $approvalId,
        string $approvalScope,
        string $policyVersion,
        string $supervisorUserId,
        string $targetReferenceId,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $payload = [
            'approval_id' => $approvalId,
            'approval_scope' => $approvalScope,
            'cashier_user_id' => $this->operatorId,
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->toIso8601ZuluString('millisecond'),
            'policy_version' => $policyVersion,
            'reason_code' => 'manager_reason',
            'reason_text' => 'Approved',
            'regime_extensions' => null,
            'requested_at_device' => $eventTime->toIso8601ZuluString('millisecond'),
            'resolved_at_device' => $eventTime->toIso8601ZuluString('millisecond'),
            'supervisor_user_id' => $supervisorUserId,
            'supervisor_user_snapshot' => ['name' => 'Manager', 'roles' => ['manager']],
            'target' => ['target_reference_id' => $targetReferenceId],
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
            'training_flag' => false,
        ];

        return $this->buildAuditEvent(FiscalEventType::OPERATOR_APPROVAL_GRANTED, $payload, sequenceNumber: 90);
    }

    private function buildOverrideEvent(
        string $approvalEventId,
        string $approvalId,
        string $approvalScope,
        string $policyVersion,
        string $supervisorUserId,
        string $targetReferenceId,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $payload = [
            'approval_event_id' => $approvalEventId,
            'approval_id' => $approvalId,
            'approval_scope' => $approvalScope,
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->toIso8601ZuluString('millisecond'),
            'override_context' => [
                'target_event_type' => 'SALE_RECEIPT',
                'target_reference_id' => $targetReferenceId,
            ],
            'policy_version' => $policyVersion,
            'reason_code' => 'manager_reason',
            'reason_text' => 'Approved',
            'supervisor_user_id' => $supervisorUserId,
            'target' => [],
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
            'training_flag' => false,
        ];

        return $this->buildAuditEvent(FiscalEventType::OVERRIDE_VOID_OR_RETURN, $payload, sequenceNumber: 91);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildAuditEvent(FiscalEventType $eventType, array $payload, int $sequenceNumber): FiscalEvent
    {
        $eventTime = now()->utc();
        $canonicalBytes = json_encode($payload, JSON_THROW_ON_ERROR);
        $currentHash = hash('sha256', $canonicalBytes.(string) $sequenceNumber);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => $eventType,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $eventTime->copy()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
    }

    private function buildSaleEvent(string $productId, int $sequenceNumber, string $receiptUuid): FiscalEvent
    {
        return $this->buildSaleReceiptEvent(
            invoiceTypeCode: 'SALE',
            eventVersion: 3,
            productId: $productId,
            sequenceNumber: $sequenceNumber,
            receiptUuid: $receiptUuid,
            originalLineReferences: null,
            originalReceiptReference: null,
            approvalReferences: [],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $approvalReferences
     */
    private function buildRefundEvent(
        string $productId,
        FiscalEvent $originalSale,
        int $sequenceNumber,
        string $receiptUuid,
        array $approvalReferences,
    ): FiscalEvent {
        return $this->buildSaleReceiptEvent(
            invoiceTypeCode: 'REFUND',
            eventVersion: 4,
            productId: $productId,
            sequenceNumber: $sequenceNumber,
            receiptUuid: $receiptUuid,
            originalLineReferences: [[
                'disposition' => 'restock',
                'original_line_index' => 0,
                'product_id' => $productId,
                'quantity' => '5.000',
            ]],
            originalReceiptReference: [
                'fiscal_event_id' => $originalSale->id,
                'original_business_date' => $originalSale->business_date->toDateString(),
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => 'customer return',
            ],
            approvalReferences: $approvalReferences,
        );
    }

    /**
     * @param  list<array<string, mixed>>|null  $originalLineReferences
     * @param  array<string, mixed>|null  $originalReceiptReference
     * @param  list<array<string, mixed>>  $approvalReferences
     */
    private function buildSaleReceiptEvent(
        string $invoiceTypeCode,
        int $eventVersion,
        string $productId,
        int $sequenceNumber,
        string $receiptUuid,
        ?array $originalLineReferences,
        ?array $originalReceiptReference,
        array $approvalReferences,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();

        $quantity = '5.000';
        $unitPrice = '10.00';
        $lineTotal = bcmul($unitPrice, $quantity, 2);

        $lineItem = [
            'gtin' => null,
            'line_discount_amount' => '0.00',
            'line_discount_reason' => null,
            'line_subtotal' => $lineTotal,
            'line_vat' => '0.00',
            'name' => 'Approval Evidence Test Item',
            'non_collected_subtype' => null,
            'product_id' => $productId,
            'quantity' => $quantity,
            'sku' => 'SKU-APPROVAL',
            'tax_category_code' => 'Z',
            'unit_price' => $unitPrice,
            'variant_id' => null,
            'variant_name' => null,
            'variant_sku' => null,
            'vat_rate' => '0.00',
        ];

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => $approvalReferences,
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => [$lineItem],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => [[
                'amount' => $lineTotal,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => $receiptUuid,
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $lineTotal,
            'table_id' => null,
            'terminal_id' => $this->terminalId,
            'total' => $lineTotal,
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $lineTotal,
                'net_amount' => $lineTotal,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        if ($originalLineReferences !== null) {
            $payload['original_line_references'] = $originalLineReferences;
            $payload['refund_destination'] = 'cash';
            $payload['settlement_allocation'] = null;
        }

        $canonicalBytes = json_encode($payload, JSON_THROW_ON_ERROR);
        $currentHash = hash('sha256', $canonicalBytes.(string) $sequenceNumber);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => $eventVersion,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
    }
}
