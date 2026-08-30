<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Application\Services\StrictCanonicalParser;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountPaymentView;
use App\Modules\Fiscal\Domain\DTOs\Canonical\BuyerDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\LineItemDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\OriginalReceiptReferenceDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\SellerDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\VatBreakdownDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\VoucherRedemptionDTO;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use RuntimeException;
use Tests\Helpers\Fiscal\GoldenFixtureBuilder;
use Tests\Helpers\Fiscal\LargeReceiptFixtureGenerator;
use Tests\TestCase;
use ValueError;

/**
 * Tests for `FiscalPayloadConstraintValidator::validateSaleReceiptPayload`
 * under the Pass 2A.PHP.1 28-key Candidate C-v3 contract (synthesis v5 §6).
 *
 * Covers the 12 negative cases from §6.E + positive invariants from spec
 * v7 §11.2 (PAYLOAD_KEYS equality / partition rule / scale invariant /
 * total arithmetic / discount-reason consistency) + extras + missing-key
 * + nested-shape malformed tests.
 *
 * **Tests\TestCase, not RefreshDatabase** — the validator is shape-only;
 * the in-memory FiscalEvent instances used by the CanonicalPayloadReader
 * tests bind Spatie EventSubscriber via the container, so the Laravel
 * app must be booted. No DB hit; no migrations needed.
 */
final class FiscalPayloadConstraintValidatorTest extends TestCase
{
    private FiscalPayloadConstraintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FiscalPayloadConstraintValidator;
    }

    // =================================================================
    // PAYLOAD_KEYS set equality (synthesis v5 §6.A + spec v7 §11.2)
    // =================================================================

    public function test_baseline_28_key_payload_is_accepted(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload));

        // No throw == accepted.
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_sale_receipt_with_non_zero_transaction_discount_is_accepted(): void
    {
        // Regression for the aggregate-identity gap: a transaction-level discount
        // makes total = subtotalGross − discount, so the identity must add the
        // discount back to total (subtotal + vat_total == total + discount). The
        // baseline is subtotal 10.00 + vat 2.00 == 12.00; a 2.00 transaction
        // discount yields total 10.00, and 10.00 + 2.00 == 12.00.
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['total'] = '10.00';
        $payload['transaction_discount_amount'] = '2.00';
        $payload['transaction_discount_reason'] = 'Loyalty reward';

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload));

        // No throw == accepted (validateSaleReceiptAggregateConsistency passes).
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_account_payment_payload_is_accepted(): void
    {
        $payload = $this->canonicalAccountPaymentPayload();

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_PAYMENT, $payload));
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);

        $this->addToAssertionCount(1);
    }

    public function test_account_payment_pending_customer_stale_card_payload_is_accepted(): void
    {
        $payload = $this->canonicalAccountPaymentPayload([
            'customer' => [
                'address' => ['city' => 'Sfax', 'country_code' => 'TN', 'postal_code' => '3000', 'street' => '22 rue Client'],
                'customer_category' => null,
                'customer_id' => '66666666-6666-4666-8666-666666666666',
                'customer_sync_status' => 'pending_create',
                'email' => 'client@example.test',
                'name' => 'Pending Client',
                'phone' => null,
                'tax_number' => '7654321BM000',
            ],
            'payment' => [
                'amount' => '50.000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => 'CARD-REF-1',
                'instrument_type' => 'card',
                'method_code' => 'CARD',
                'repository_id' => '77777777-7777-4777-8777-777777777777',
            ],
            'staleness' => [
                'balance_snapshot_stale' => true,
                'customer_snapshot_stale' => true,
                'mirror_last_synced_at' => '2026-05-20T08:00:00.000Z',
                'staleness_reason' => 'older_than_threshold',
            ],
        ]);

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_PAYMENT, $payload));
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);

        $this->addToAssertionCount(1);
    }

    public function test_account_payment_foreign_currency_payload_is_accepted(): void
    {
        $payload = $this->canonicalAccountPaymentPayload([
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'credit_balance_before' => '0.00',
                'net_balance_before' => '300.00',
                'payment_amount' => '100.00',
                'projected_credit_balance_after' => '0.00',
                'projected_net_balance_after' => '200.00',
                'projected_receivable_balance_after' => '200.00',
                'receivable_balance_before' => '300.00',
            ],
            'payment' => [
                'amount' => '100.00',
                'foreign_currency_amount' => '330.000',
                'foreign_currency_code' => 'TND',
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
                'repository_id' => null,
            ],
        ]);

        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_phase_four_operator_approval_payload_rejects_malformed_timestamp(): void
    {
        $payload = $this->phaseFourApprovalPayload([
            'resolved_at_device' => '2026-05-22T10:00:00Z',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payload_datetime_format_mismatch:field=resolved_at_device');

        $this->validator->validatePerEventConstraints(FiscalEventType::OPERATOR_APPROVAL_GRANTED, $payload);
    }

    public function test_phase_four_override_payload_rejects_unknown_scope(): void
    {
        $payload = $this->phaseFourOverridePayload([
            'approval_scope' => 'cash_drawer_control',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payload_field_invalid:approval_scope');

        $this->validator->validatePerEventConstraints(FiscalEventType::OVERRIDE_DISCOUNT_LIMIT, $payload);
    }

    public function test_account_payment_populated_references_payload_is_accepted_when_alias_is_null(): void
    {
        $payload = $this->canonicalAccountPaymentPayload([
            'references' => [
                'external_reference' => 'counter-payment-42',
                'related_sale_receipt_event_id' => '88888888-8888-4888-8888-888888888888',
                'server_customer_alias_id' => null,
            ],
        ]);

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_PAYMENT, $payload));
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);

        $this->addToAssertionCount(1);
    }

    public function test_account_payment_rejects_missing_customer_block(): void
    {
        $payload = $this->canonicalAccountPaymentPayload();
        unset($payload['customer']);

        $error = $this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_PAYMENT, $payload);

        self::assertSame('payload_missing_required:customer', $error);
    }

    public function test_account_payment_rejects_invalid_customer_sync_status(): void
    {
        $payload = $this->canonicalAccountPaymentPayload();
        $payload['customer']['customer_sync_status'] = 'server_only';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/customer_sync_status/');
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);
    }

    public function test_account_payment_rejects_zero_amount_outside_training(): void
    {
        $payload = $this->canonicalAccountPaymentPayload();
        $payload['payment']['amount'] = '0.000';
        $payload['local_balance_snapshot']['payment_amount'] = '0.000';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payment_amount_zero/');
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);
    }

    public function test_account_payment_rejects_stale_flag_without_reason(): void
    {
        $payload = $this->canonicalAccountPaymentPayload();
        $payload['staleness']['balance_snapshot_stale'] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/staleness_reason/');
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);
    }

    public function test_account_payment_rejects_foreign_currency_amount_without_code(): void
    {
        $payload = $this->canonicalAccountPaymentPayload();
        $payload['payment']['foreign_currency_amount'] = '20.00';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/foreign_currency/');
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);
    }

    public function test_account_payment_rejects_seller_tax_number_under_universal_validator(): void
    {
        $payload = $this->canonicalAccountPaymentPayload();
        $payload['seller']['tax_number'] = 'TN#BAD';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/seller\\.tax_number/');
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);
    }

    public function test_account_charge_validator_accepts_discounted_b2b_buyer_payload(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'customer' => [
                'customer_category' => 'business',
                'tax_number' => '7654321BM000',
            ],
            'invoice_classification' => 'b2b_facture_draft_requested',
            'buyer' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => 'Rue Buyer'],
                'codice_fiscale' => null,
                'contact_id' => 'contact-1',
                'customer_id' => '55555555-5555-4555-8555-555555555555',
                'name' => 'Business Buyer',
                'tax_number' => '7654321BM000',
            ],
        ]);
        $payload['line_items'][0]['unit_price'] = '110.000';
        $payload['line_items'][0]['line_discount_amount'] = '10.000';
        $payload['line_items'][0]['line_discount_reason'] = 'customer discount';

        $this->assertAccountChargeAccepted($payload);
    }

    public function test_account_charge_rejects_extra_top_level_key(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        $payload['unexpected'] = true;

        self::assertSame(
            'payload_extra_field:unexpected',
            $this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_CHARGE, $payload),
        );
    }

    public function test_account_charge_rejects_missing_required_top_level_key(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        unset($payload['customer']);

        self::assertSame(
            'payload_missing_required:customer',
            $this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_CHARGE, $payload),
        );
    }

    public function test_account_charge_rejects_missing_nested_customer_key(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        unset($payload['customer']['account_identifier']);

        $this->expectAccountChargeException('/payload_object_missing_keys:customer:account_identifier/', $payload);
    }

    public function test_account_charge_rejects_malformed_money_field(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        $payload['totals']['total'] = '119.00';

        $this->expectAccountChargeException('/payload_money_scale_mismatch:field=totals\\.total/', $payload);
    }

    public function test_account_charge_rejects_malformed_vat_partition(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        $payload['vat_breakdown'][0]['net_amount'] = '99.000';
        $payload['vat_breakdown'][0]['gross_amount'] = '118.000';

        $this->expectAccountChargeException('/payload_partition_net_mismatch/', $payload);
    }

    public function test_account_charge_rejects_payments_key(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        $payload['payments'] = [];

        self::assertSame(
            'payload_account_charge_payments_forbidden:payments is not valid on ACCOUNT_CHARGE',
            $this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_CHARGE, $payload),
        );
    }

    public function test_account_charge_rejects_nested_payments_key(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'regime_extensions' => [
                'payments' => [],
            ],
        ]);

        $this->expectAccountChargeException('/payload_account_charge_payments_forbidden/', $payload);
    }

    public function test_account_charge_rejects_missing_product_id(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        unset($payload['line_items'][0]['product_id']);

        $this->expectAccountChargeException('/payload_line_item_missing_keys:line_items\\[0\\]:product_id/', $payload);
    }

    public function test_account_charge_rejects_invalid_buyer_codice_fiscale(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'buyer' => [
                'address' => ['city' => 'Rome', 'country_code' => 'IT', 'postal_code' => '00100', 'street' => 'Via Test'],
                'codice_fiscale' => 'BAD-CF',
                'contact_id' => 'contact-1',
                'customer_id' => '55555555-5555-4555-8555-555555555555',
                'name' => 'Italian Buyer',
                'tax_number' => null,
            ],
        ]);

        $this->expectAccountChargeException('/buyer\\.codice_fiscale/', $payload);
    }

    public function test_account_charge_rejects_limit_exceeded_production_event(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        $payload['credit_decision']['limit_exceeded'] = true;

        $this->expectAccountChargeException('/payload_account_charge_credit_decision_invalid/', $payload);
    }

    public function test_account_charge_accepts_production_credit_limit_override_evidence(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'credit_decision' => [
                'credit_available_after' => '0.000',
                'decision' => 'approved_with_override',
                'limit_exceeded' => true,
                'override_evidence' => [
                    'approval_event_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    'approval_scope' => 'credit_limit_override',
                    'override_event_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                    'policy_version' => 'phase3-default-v1',
                    'target_account_status' => 'active',
                    'target_amount' => '119.000',
                    'target_customer_id' => '55555555-5555-4555-8555-555555555555',
                ],
            ],
        ]);

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_CHARGE, $payload));
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_CHARGE, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_account_charge_rejects_amount_balance_mismatch(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        $payload['local_balance_snapshot']['charge_amount'] = '120.000';

        $this->expectAccountChargeException('/payload_account_charge_amount_mismatch/', $payload);
    }

    public function test_account_charge_accepts_fully_credit_offset_projected_net(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'credit_decision' => [
                'credit_available_after' => '500.000',
                'credit_available_before' => '500.000',
            ],
            'line_items' => [[
                'line_subtotal' => '50.000',
                'line_vat' => '0.000',
                'unit_price' => '50.000',
                'vat_rate' => '0.00',
            ]],
            'local_balance_snapshot' => [
                'charge_amount' => '50.000',
                'credit_balance_before' => '100.000',
                'net_balance_before' => '0.000',
                'projected_credit_balance_after' => '100.000',
                'projected_net_balance_after' => '0.000',
                'projected_receivable_balance_after' => '50.000',
                'receivable_balance_before' => '0.000',
            ],
            'totals' => [
                'amount_charged_to_account' => '50.000',
                'grand_total_before_charge' => '50.000',
                'subtotal' => '50.000',
                'total' => '50.000',
                'vat_total' => '0.000',
            ],
            'vat_breakdown' => [[
                'gross_amount' => '50.000',
                'net_amount' => '50.000',
                'rate' => '0.00',
                'vat_amount' => '0.000',
            ]],
        ]);

        $this->assertAccountChargeAccepted($payload);
    }

    public function test_account_charge_rejects_grand_total_before_charge_mismatch(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        $payload['totals']['grand_total_before_charge'] = '120.000';

        $this->expectAccountChargeException('/payload_account_charge_amount_mismatch:grand_total_before_charge/', $payload);
    }

    public function test_account_charge_rejects_invalid_invoice_classification_for_non_business_customer(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'customer' => ['customer_category' => 'retail'],
            'invoice_classification' => 'b2b_facture_draft_requested',
        ]);

        $this->expectAccountChargeException('/payload_account_charge_invoice_classification_mismatch/', $payload);
    }

    public function test_account_charge_accepts_synced_and_pending_customer_variants(): void
    {
        $this->assertAccountChargeAccepted($this->canonicalAccountChargePayload());

        $payload = $this->canonicalAccountChargePayload([
            'customer' => [
                'customer_id' => '66666666-6666-4666-8666-666666666666',
                'customer_sync_status' => 'pending_create',
                'customer_category' => null,
            ],
        ]);
        $this->assertAccountChargeAccepted($payload);
    }

    public function test_account_charge_accepts_stale_and_fresh_mirror_variants(): void
    {
        $this->assertAccountChargeAccepted($this->canonicalAccountChargePayload());

        $payload = $this->canonicalAccountChargePayload([
            'staleness' => [
                'balance_snapshot_stale' => true,
                'customer_snapshot_stale' => true,
                'mirror_last_synced_at' => '2026-05-20T08:00:00.000Z',
                'staleness_reason' => 'older_than_threshold',
            ],
            'credit_decision' => [
                'mirror_stale_at_authoring' => true,
                'stale_policy_action' => 'warn',
                'warnings' => ['balance_snapshot_stale'],
            ],
        ]);
        $this->assertAccountChargeAccepted($payload);
    }

    public function test_account_charge_accepts_credit_limit_present_and_absent_variants(): void
    {
        $this->assertAccountChargeAccepted($this->canonicalAccountChargePayload());

        $payload = $this->canonicalAccountChargePayload([
            'credit_decision' => [
                'credit_available_after' => null,
                'credit_available_before' => null,
                'credit_limit' => null,
            ],
        ]);
        $this->assertAccountChargeAccepted($payload);
    }

    public function test_account_charge_accepts_nullable_charge_terms(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'charge_terms' => [
                'due_date' => null,
                'payment_terms_days' => null,
                'terms_label' => null,
            ],
        ]);

        $this->assertAccountChargeAccepted($payload);
    }

    public function test_account_charge_rejects_missing_due_date_when_payment_terms_present(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'charge_terms' => [
                'due_date' => null,
                'payment_terms_days' => 30,
                'terms_label' => 'Net 30',
            ],
        ]);

        $this->expectAccountChargeException('/payload_account_charge_terms_invalid/', $payload);
    }

    public function test_account_charge_rejects_unsorted_credit_decision_warnings(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'credit_decision' => [
                'warnings' => ['mirror_stale', 'balance_stale'],
            ],
        ]);

        $this->expectAccountChargeException('/payload_account_charge_credit_decision_invalid:warnings/', $payload);
    }

    public function test_account_charge_rejects_display_text_credit_decision_warning(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'credit_decision' => [
                'warnings' => ['balance snapshot stale'],
            ],
        ]);

        $this->expectAccountChargeException('/payload_account_charge_credit_decision_invalid:warnings\\[0\\] must be a stable lower_snake_case code/', $payload);
    }

    /**
     * D-1 gate r2 finding 2 — RE-PINNED back to "accepts", at THIS layer.
     *
     * r1 refused a discounted ACCOUNT_CHARGE here, in the pure payload
     * validator. That was wrong twice: `VerifyEventChainCommand` re-runs this
     * validator over STORED events, so every historical discounted credit sale
     * would have started reporting as a payload-constraint failure; and it bound
     * un-upgraded terminals, quarantining real credit sales at ingest the moment
     * the server deployed — inverting the deploy order.
     *
     * The refusal moved to `SaleReceiptForwardVersionGate`, on the same
     * per-chain v5 watermark the sales arm uses, so it binds only chains that
     * have proven they author the post-remise base. The PAYLOAD CONTRACT is
     * unchanged and must stay that way: these bytes were valid when they were
     * signed and must re-validate forever (rule 8).
     */
    public function test_account_charge_still_accepts_a_transaction_discount_at_the_payload_contract(): void
    {
        $this->assertAccountChargeAccepted($this->canonicalAccountChargePayload());

        $payload = $this->canonicalAccountChargePayload([
            'transaction_discount_amount' => '5.000',
            'transaction_discount_reason' => 'manager discount',
            'totals' => [
                'amount_charged_to_account' => '114.000',
                'grand_total_before_charge' => '114.000',
                'total' => '114.000',
            ],
            'local_balance_snapshot' => [
                'charge_amount' => '114.000',
                'projected_net_balance_after' => '414.000',
                'projected_receivable_balance_after' => '414.000',
            ],
            'credit_decision' => [
                'credit_available_after' => '86.000',
            ],
        ]);

        // Accepted at the payload contract — the cutover lives on the ingest
        // path, not in the immutable bytes.
        $this->assertAccountChargeAccepted($payload);
    }

    public function test_account_charge_accepts_nullable_and_populated_buyer_and_references(): void
    {
        $this->assertAccountChargeAccepted($this->canonicalAccountChargePayload());

        $payload = $this->canonicalAccountChargePayload([
            'buyer' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => 'Rue Buyer'],
                'codice_fiscale' => null,
                'contact_id' => 'contact-1',
                'customer_id' => '55555555-5555-4555-8555-555555555555',
                'name' => 'Mariam Ben Ali',
                'tax_number' => '1234567AM000',
            ],
            'references' => [
                'external_reference' => 'charge-ref-1',
                'related_sale_receipt_event_id' => null,
                'server_customer_alias_id' => null,
            ],
        ]);
        $this->assertAccountChargeAccepted($payload);
    }

    public function test_account_charge_rejects_reserved_related_sale_receipt_reference(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'references' => [
                'external_reference' => null,
                'related_sale_receipt_event_id' => '88888888-8888-4888-8888-888888888888',
                'server_customer_alias_id' => null,
            ],
        ]);

        $this->expectAccountChargeException('/payload_account_charge_reference_forbidden:references\\.related_sale_receipt_event_id/', $payload);
    }

    public function test_account_charge_rejects_reserved_server_customer_alias_reference(): void
    {
        $payload = $this->canonicalAccountChargePayload([
            'references' => [
                'external_reference' => null,
                'related_sale_receipt_event_id' => null,
                'server_customer_alias_id' => 'alias-1',
            ],
        ]);

        $this->expectAccountChargeException('/payload_account_charge_reference_forbidden:references\\.server_customer_alias_id/', $payload);
    }

    public function test_account_charge_accepts_nullable_non_collected_subtype(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        $payload['line_items'][0]['non_collected_subtype'] = 'servizi';
        $this->assertAccountChargeAccepted($payload);

        $payload['line_items'][0]['non_collected_subtype'] = null;
        $this->assertAccountChargeAccepted($payload);
    }

    public function test_account_charge_accepts_training_limit_exceeded_but_rejects_production_limit_exceeded(): void
    {
        $training = $this->canonicalAccountChargePayload([
            'training_flag' => true,
            'credit_decision' => [
                'limit_exceeded' => true,
                'warnings' => ['training_over_limit'],
            ],
        ]);
        $this->assertAccountChargeAccepted($training);

        $production = $this->canonicalAccountChargePayload([
            'credit_decision' => [
                'limit_exceeded' => true,
            ],
        ]);
        $this->expectAccountChargeException('/payload_account_charge_credit_decision_invalid/', $production);
    }

    public function test_phase_1_5_2_accepts_per_country_seller_tax_numbers(): void
    {
        $fixtures = [
            'FR' => '12345678901234',
            'TN' => '1234567/A/M/000',
            'SA' => '312345678901203',
            'DE' => 'DE123456789',
            'IT' => '12345678901',
        ];

        foreach ($fixtures as $country => $taxNumber) {
            $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
            $payload['seller']['tax_jurisdiction_country_code'] = $country;
            $payload['seller']['address']['country_code'] = $country;
            $payload['seller']['tax_number'] = $taxNumber;

            try {
                $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
            } catch (RuntimeException $e) {
                self::fail("{$country} seller tax number should pass Phase 1.5.2 validation: {$e->getMessage()}");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_phase_1_5_2_rejects_country_specific_seller_tax_number_mismatches(): void
    {
        $fixtures = [
            'FR' => 'FR12345678901',
            // Was `1234567ABC000` (7 digits + 3 letters + 3 digits). That shape
            // is now the CANONICAL 13-character matricule fiscale and is
            // accepted on purpose — the TN pattern was widened from
            // `[A-Z]{2}` to `[A-Z]{2,3}` by the TN convergence lane (research
            // spec 2026-08-23 §3.3). It stopped being a negative fixture the
            // moment the 3-letter arm became legal, so it is replaced by a
            // letter-count violation that is invalid under BOTH the old and
            // the new pattern, which is what this test is actually asserting:
            // a value wrong for its country fails with the country-specific
            // message.
            'TN' => '1234567ABCD000',
            'SA' => '212345678901203',
            'DE' => '123456789',
            'IT' => 'IT123456789',
        ];

        foreach ($fixtures as $country => $taxNumber) {
            $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
            $payload['seller']['tax_jurisdiction_country_code'] = $country;
            $payload['seller']['address']['country_code'] = $country;
            $payload['seller']['tax_number'] = $taxNumber;

            try {
                $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
                self::fail("{$country} seller tax number {$taxNumber} should fail Phase 1.5.2 validation.");
            } catch (RuntimeException $e) {
                self::assertSame(
                    "payload_tax_number_format_mismatch:field=seller.tax_number:country={$country}:value={$taxNumber}",
                    $e->getMessage()
                );
            }
        }
    }

    public function test_phase_1_5_2_unknown_country_falls_back_to_universal_tax_number_pattern(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['seller']['tax_jurisdiction_country_code'] = 'XX';
        $payload['seller']['address']['country_code'] = 'XX';
        $payload['seller']['tax_number'] = 'AB-1234/XY';

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_phase_1_5_2_accepts_fr_buyer_tva_intracommunautaire(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];
        $payload['buyer']['tax_number'] = 'FR12345678901';

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_phase_1_5_2_rejects_invalid_it_buyer_codice_fiscale(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];
        $payload['buyer']['address']['country_code'] = 'IT';
        $payload['buyer']['tax_number'] = null;
        $payload['buyer']['codice_fiscale'] = 'RSSMRA80A01H50';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'payload_buyer_codice_fiscale_format_mismatch:field=buyer.codice_fiscale:value=RSSMRA80A01H50'
        );
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_phase_1_5_2_accepts_it_buyer_codice_fiscale_with_tax_number_preferred(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];
        $payload['buyer']['address']['country_code'] = 'IT';
        $payload['buyer']['tax_number'] = '12345678901';
        $payload['buyer']['codice_fiscale'] = 'RSSMRA80A01H501U';

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_account_payment_rejects_server_customer_alias_on_original_event(): void
    {
        $payload = $this->canonicalAccountPaymentPayload([
            'references' => [
                'external_reference' => null,
                'related_sale_receipt_event_id' => null,
                'server_customer_alias_id' => '99999999-9999-4999-8999-999999999999',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/server_customer_alias_id/');
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);
    }

    public function test_payload_with_extra_29th_key_is_rejected_with_extra_field_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['unknown_extra_field'] = 'rogue';

        $error = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload);

        self::assertNotNull($error);
        self::assertStringStartsWith('payload_extra_field:', $error);
        self::assertStringContainsString('unknown_extra_field', $error);
    }

    public function test_payload_missing_required_key_is_rejected_with_missing_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        unset($payload['seller']);

        $error = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload);

        self::assertNotNull($error);
        self::assertStringStartsWith('payload_missing_required:', $error);
        self::assertStringContainsString('seller', $error);
    }

    public function test_legacy_10_key_payload_is_rejected_as_missing_required(): void
    {
        // Reject the OLD shape per synthesis v5 §8.A — every Pass 2A.PHP.2
        // consumer test that still hard-codes this shape gets per-method
        // markTestSkipped in Pass 2A.PHP.1; Pass 2A.PHP.2 migrates them.
        $legacy = [
            'currency' => 'EUR',
            'currency_scale' => 2,
            'discount_total' => '0.00',
            'lines' => [],
            'payment_lines' => [],
            'subtotal' => '0.00',
            'tax_total' => '0.00',
            'total' => '0.00',
            'vat_breakdown' => [],
            'voucher_redemptions' => [],
        ];

        $error = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $legacy);

        self::assertNotNull($error);
        self::assertStringStartsWith('payload_missing_required:', $error);
    }

    // =================================================================
    // §6.E negative cases — 12 numbered scenarios
    // =================================================================

    /** §6.E.1 — duplicate partition row */
    public function test_negative_1_duplicate_partition_row_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Two breakdown rows at the same (rate, category).
        $payload['vat_breakdown'][] = $payload['vat_breakdown'][0];

        $this->expectExceptionMessageMatches('/^payload_partition_duplicate:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.2 — missing partition row */
    public function test_negative_2_missing_partition_row_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Drop the single breakdown row but keep the line.
        $payload['vat_breakdown'] = [
            [
                'gross_amount' => '0.00', 'net_amount' => '0.00', 'rate' => '0.00',
                'tax_category_code' => 'Z', 'vat_amount' => '0.00',
            ],
        ];

        $this->expectExceptionMessageMatches('/^payload_partition_mismatch:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.3 — extra partition row */
    public function test_negative_3_extra_partition_row_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['vat_breakdown'][] = [
            'gross_amount' => '0.00', 'net_amount' => '0.00', 'rate' => '0.00',
            'tax_category_code' => 'O', 'vat_amount' => '0.00',
        ];

        $this->expectExceptionMessageMatches('/^payload_partition_mismatch:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.4 — one-cent drift between line sum and breakdown net.
     *
     * The breakdown row stays internally consistent (net + vat == gross)
     * to dodge the per-row arithmetic check; the drift is between the
     * line_subtotal sum and the breakdown net.
     */
    public function test_negative_4_one_cent_drift_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // line sums to 10.00 / 2.00; bump breakdown net+gross by 0.01 each.
        $payload['vat_breakdown'][0]['net_amount'] = '10.01';
        $payload['vat_breakdown'][0]['gross_amount'] = '12.01';
        // Keep total arithmetic balanced: subtotal=10.01, vat_total=2.00, total=12.01.
        $payload['subtotal'] = '10.01';
        $payload['total'] = '12.01';
        $payload['payments'][0]['amount'] = '12.01';

        $this->expectExceptionMessageMatches('/^payload_partition_net_mismatch:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.5 — mixed 0% categories with distinct tax_category_code (must succeed). */
    public function test_negative_5_mixed_zero_percent_categories_are_accepted_when_partitioned_correctly(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-06-multi-vat-rate-eur'];
        // F-06 already has multiple 0%-* rows + matched lines → must pass.
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    /** §6.E.6 — wrong scale on unit_price */
    public function test_negative_6_wrong_money_scale_is_rejected_with_scale_mismatch_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // currency_scale = 2 but unit_price emitted at scale 3.
        $payload['line_items'][0]['unit_price'] = '10.000';

        $this->expectExceptionMessageMatches('/^payload_money_scale_mismatch:field=line_items\[0\]\.unit_price/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.7 — total arithmetic mismatch */
    public function test_negative_7_total_arithmetic_mismatch_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Bump total without bumping subtotal/vat_total.
        $payload['total'] = '15.00';
        // Re-balance partition so partition-mismatch doesn't fire first
        // (we want total_arithmetic to fire). subtotal+vat_total=10+2=12
        // != total+discount=15+0=15 → reject.

        $this->expectExceptionMessageMatches('/^payload_total_arithmetic_mismatch:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.8 — negative transaction_discount (regex rejects leading minus) */
    public function test_negative_8_negative_transaction_discount_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['transaction_discount_amount'] = '-5.00';

        $this->expectExceptionMessageMatches('/^payload_money_scale_mismatch:field=transaction_discount_amount/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.9 — discount-reason consistency at scale=2 zero, reason set (reject) */
    public function test_negative_9_discount_zero_at_scale_2_with_reason_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['transaction_discount_amount'] = '0.00';
        $payload['transaction_discount_reason'] = 'seasonal';

        $this->expectExceptionMessageMatches('/^payload_discount_reason_mismatch:amount=0\.00:reason_present=true/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.10 — discount-reason consistency at scale=2 zero, reason null (pass) */
    public function test_negative_10_discount_zero_at_scale_2_with_null_reason_passes(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['transaction_discount_amount'] = '0.00';
        $payload['transaction_discount_reason'] = null;

        // No throw expected.
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    /** §6.E.11 — discount-reason consistency non-zero with null reason (reject) */
    public function test_negative_11_discount_non_zero_with_null_reason_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Rebalance: discount=5.00, total=7.00 → 10+2 == 7+5 ✓
        $payload['transaction_discount_amount'] = '5.00';
        $payload['transaction_discount_reason'] = null;
        $payload['total'] = '7.00';

        $this->expectExceptionMessageMatches('/^payload_discount_reason_mismatch:amount=5\.00:reason_present=false/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** §6.E.12 — discount-reason consistency at scale=0 zero TND, null reason (pass) */
    public function test_negative_12_discount_zero_at_scale_0_tnd_with_null_reason_passes(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Convert to a scale-0 TND-style currency.
        $payload['currency_code'] = 'JPY';
        $payload['currency_scale'] = 0;
        $payload['transaction_discount_amount'] = '0';
        $payload['transaction_discount_reason'] = null;
        $payload['subtotal'] = '10';
        $payload['vat_total'] = '2';
        $payload['total'] = '12';
        $payload['line_items'] = [
            array_replace($payload['line_items'][0], [
                'unit_price' => '10',
                'line_subtotal' => '10',
                'line_vat' => '2',
                'line_discount_amount' => '0',
            ]),
        ];
        $payload['vat_breakdown'] = [
            [
                'gross_amount' => '12',
                'net_amount' => '10',
                'rate' => '20.00',
                'tax_category_code' => '',
                'vat_amount' => '2',
            ],
        ];
        $payload['payments'] = [
            array_replace($payload['payments'][0], ['amount' => '12']),
        ];

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    // =================================================================
    // Aggregate-consistency invariant (NF525 VAT-declaration integrity)
    //
    // NF525 secures the ticket AGGREGATES — subtotal / vat_total / total /
    // vat_breakdown — and requires line detail to be CONSERVED inalterably
    // (the hash already does that). It does NOT mandate per-line arithmetic
    // re-validation. The server stores canonical_bytes/current_hash verbatim
    // and verifies by re-hashing; it does NOT recompute prices from unit_price
    // (which is the cart's TAX-INCLUSIVE figure). It only verifies the device's
    // own aggregates are internally consistent:
    //   1. subtotal + vat_total == total
    //   2. Σ vat_breakdown[].net_amount == subtotal
    //   3. Σ vat_breakdown[].vat_amount == vat_total
    //   4. per group: gross_amount == net_amount + vat_amount
    // A violation routes through the SAME RuntimeException → quarantine path
    // (event stored, NOT projected) without touching the hash.
    // =================================================================

    public function test_aggregate_consistent_multi_quantity_with_discount_passes(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // 20% VAT inclusive; net subtotal 13.00, vat 2.60, total 15.60.
        $payload['line_items'] = [
            array_replace($payload['line_items'][0], [
                'unit_price' => '5.00',
                'quantity' => '3.000',
                'line_discount_amount' => '2.00',
                'line_discount_reason' => 'loyalty',
                'line_subtotal' => '13.00',
                'line_vat' => '2.60',
                'vat_rate' => '20.00',
                'tax_category_code' => '',
            ]),
        ];
        $payload['vat_breakdown'] = [
            ['gross_amount' => '15.60', 'net_amount' => '13.00', 'rate' => '20.00', 'tax_category_code' => '', 'vat_amount' => '2.60'],
        ];
        $payload['subtotal'] = '13.00';
        $payload['vat_total'] = '2.60';
        $payload['total'] = '15.60';
        $payload['transaction_discount_amount'] = '0.00';
        $payload['transaction_discount_reason'] = null;
        $payload['payments'] = [array_replace($payload['payments'][0], ['amount' => '15.60'])];

        // No throw == accepted.
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    /**
     * CRITICAL regression guard for the prior per-line BLOCKER.
     *
     * Real device output (`buildSaleReceiptPayload`) writes the cart's
     * TAX-INCLUSIVE unit_price verbatim into `unit_price`, while `line_subtotal`
     * is the NET figure (line_total − line_vat). The removed per-line check
     * asserted `line_subtotal == round(unit_price × qty) − discount`, comparing
     * a NET field against a GROSS product — it FALSE-POSITIVED and quarantined
     * every valid taxed receipt. This test feeds exactly that device shape
     * (gross unit_price 12.00, net subtotal 10.00, vat 2.00) and confirms the
     * validator now ACCEPTS it (the false-positive is gone). Aggregates are
     * internally consistent: 10.00 + 2.00 == 12.00.
     */
    public function test_device_shaped_taxed_receipt_with_inclusive_unit_price_passes(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['line_items'] = [
            array_replace($payload['line_items'][0], [
                // Cart tax-INCLUSIVE unit price (12.00 gross), NOT the net 10.00.
                'unit_price' => '12.00',
                'quantity' => '1.000',
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '10.00', // NET = line_total(12.00) − line_vat(2.00)
                'line_vat' => '2.00',
                'vat_rate' => '20.00',
                'tax_category_code' => '',
            ]),
        ];
        $payload['vat_breakdown'] = [
            ['gross_amount' => '12.00', 'net_amount' => '10.00', 'rate' => '20.00', 'tax_category_code' => '', 'vat_amount' => '2.00'],
        ];
        $payload['subtotal'] = '10.00';
        $payload['vat_total'] = '2.00';
        $payload['total'] = '12.00';
        $payload['transaction_discount_amount'] = '0.00';
        $payload['transaction_discount_reason'] = null;
        $payload['payments'] = [array_replace($payload['payments'][0], ['amount' => '12.00'])];

        // No throw == accepted. The old per-line check would have quarantined
        // this (net 10.00 != round(gross 12.00 × 1) − 0 == 12.00).
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_aggregate_subtotal_plus_vat_not_equal_total_is_quarantined(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // subtotal 10.00 + vat_total 2.00 == 12.00 but total claims 13.00 (and
        // transaction_discount_amount is 0.00). The aggregate identity
        // subtotal + vat_total == total + discount is violated. This is the SAME
        // identity enforced by the §6.D `payload_total_arithmetic_mismatch`
        // step, which fires first; the redundant aggregate-block check #1 backs
        // it up. Either way the event is quarantined.
        $payload['total'] = '13.00';
        $payload['payments'] = [array_replace($payload['payments'][0], ['amount' => '13.00'])];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^payload_total_arithmetic_mismatch:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_aggregate_vat_breakdown_net_sum_not_equal_subtotal_is_quarantined(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Tamper the declared subtotal so the vat_breakdown net sum (10.00) no
        // longer matches it, while keeping subtotal + vat_total == total so the
        // first aggregate check passes and check #2 is what fires.
        // subtotal 9.00 + vat_total 2.00 != total 12.00 would trip check #1 first,
        // so also move total to 11.00; the breakdown net sum (10.00) != 9.00.
        $payload['subtotal'] = '9.00';
        $payload['total'] = '11.00';
        $payload['payments'] = [array_replace($payload['payments'][0], ['amount' => '11.00'])];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^payload_aggregate_consistency:vat_breakdown_net_sum_ne_subtotal:expected=9\.00:got=10\.00/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_aggregate_vat_breakdown_vat_sum_not_equal_vat_total_is_quarantined(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Keep subtotal + vat_total == total (10.00 + 3.00 == 13.00) and the
        // partition net sum == subtotal (10.00), but the vat_breakdown vat sum
        // (2.00) != vat_total (3.00) so check #3 fires.
        $payload['vat_total'] = '3.00';
        $payload['total'] = '13.00';
        $payload['payments'] = [array_replace($payload['payments'][0], ['amount' => '13.00'])];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^payload_aggregate_consistency:vat_breakdown_vat_sum_ne_vat_total:expected=3\.00:got=2\.00/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_aggregate_consistent_at_scale_3_tnd_passes(): void
    {
        // Scale-3 (TND millimes), tax-inclusive: net 2.750, vat 0.000, total 2.750.
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['currency_code'] = 'TND';
        $payload['currency_scale'] = 3;
        $payload['line_items'] = [
            array_replace($payload['line_items'][0], [
                'unit_price' => '1.500',
                'quantity' => '2.000',
                'line_discount_amount' => '0.250',
                'line_discount_reason' => 'promo',
                'line_subtotal' => '2.750',
                'line_vat' => '0.000',
                'vat_rate' => '0.00',
                'tax_category_code' => 'Z',
            ]),
        ];
        $payload['vat_breakdown'] = [
            ['gross_amount' => '2.750', 'net_amount' => '2.750', 'rate' => '0.00', 'tax_category_code' => 'Z', 'vat_amount' => '0.000'],
        ];
        $payload['subtotal'] = '2.750';
        $payload['vat_total'] = '0.000';
        $payload['total'] = '2.750';
        $payload['transaction_discount_amount'] = '0.000';
        $payload['transaction_discount_reason'] = null;
        $payload['payments'] = [array_replace($payload['payments'][0], ['amount' => '2.750'])];

        // No throw == accepted.
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    // =================================================================
    // Positive invariants from spec v7 §11.2
    // =================================================================

    public function test_invariant_partition_rule_holds_when_lines_correctly_aggregate(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-05-multi-line-mixed-categories-eur'];
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_invariant_scale_invariant_holds_at_currency_scale_2(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_invariant_total_arithmetic_holds_with_zero_discount(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $this->addToAssertionCount(1);
    }

    // =================================================================
    // Nested-shape malformed tests (per-event)
    // =================================================================

    public function test_malformed_seller_missing_tax_number_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        unset($payload['seller']['tax_number']);

        $this->expectExceptionMessageMatches('/^payload_seller_missing_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_seller_with_extra_key_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['seller']['extra_field'] = 'rogue';

        $this->expectExceptionMessageMatches('/^payload_seller_extra_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_buyer_with_extra_key_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];
        $payload['buyer']['extra_field'] = 'rogue';

        $this->expectExceptionMessageMatches('/^payload_buyer_extra_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_v5_buyer_accepts_uuid_customer_id(): void
    {
        $payload = $this->canonicalV5Payload();
        $payload['buyer'] = [
            'address' => null,
            'codice_fiscale' => null,
            'contact_id' => null,
            'customer_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'name' => 'Acme SARL',
            'tax_number' => '1234567AM000',
        ];

        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 5,
        );
        $this->addToAssertionCount(1);
    }

    public function test_v5_buyer_accepts_null_customer_id(): void
    {
        $payload = $this->canonicalV5Payload();
        $payload['buyer'] = [
            'address' => null,
            'codice_fiscale' => null,
            'contact_id' => null,
            'customer_id' => null,
            'name' => 'Pending Acme SARL',
            'tax_number' => null,
        ];

        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 5,
        );
        $this->addToAssertionCount(1);
    }

    public function test_v5_buyer_rejects_pending_non_uuid_customer_id(): void
    {
        $payload = $this->canonicalV5Payload();
        $payload['buyer'] = [
            'address' => null,
            'codice_fiscale' => null,
            'contact_id' => null,
            'customer_id' => 'pending-customer-7',
            'name' => 'Pending Acme SARL',
            'tax_number' => null,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^payload_buyer_invalid:customer_id must be UUID or null/');
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 5,
        );
    }

    public function test_v5_buyer_rejects_uppercase_uuid_customer_id(): void
    {
        $payload = $this->canonicalV5Payload();
        $payload['buyer'] = [
            'address' => null,
            'codice_fiscale' => null,
            'contact_id' => null,
            'customer_id' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA',
            'name' => 'Acme SARL',
            'tax_number' => null,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^payload_buyer_invalid:customer_id must be UUID or null/');
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 5,
        );
    }

    public function test_v5_buyer_requires_non_empty_name(): void
    {
        $payload = $this->canonicalV5Payload();
        $payload['buyer'] = [
            'address' => null,
            'codice_fiscale' => null,
            'contact_id' => null,
            'customer_id' => null,
            'name' => null,
            'tax_number' => null,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^payload_buyer_name_invalid:/');
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 5,
        );
    }

    public function test_stale_f07_file_remains_byte_pinned_with_documented_27_key_drift(): void
    {
        $path = __DIR__.'/../../Fixtures/Fiscal/sale-receipt-golden/v4/F-07-b2b-buyer-eur/payload.json';
        $bytes = file_get_contents($path);

        self::assertIsString($bytes);
        self::assertSame('96e325eedc1b5466e3cd0a0c7b74b203b0110617b46459a47ed4236579e46142', hash('sha256', $bytes));
        /** @var array<string, mixed> $payload */
        $payload = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(27, $payload);
        self::assertArrayNotHasKey('approval_references', $payload);
        self::assertSame('cust-007', $payload['buyer']['customer_id']);
    }

    public function test_legacy_f07_builder_semantics_accept_non_uuid_buyer_at_v1(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];

        self::assertCount(28, $payload);
        self::assertSame([], $payload['approval_references']);
        self::assertSame('cust-007', $payload['buyer']['customer_id']);
        self::assertNull($this->validator->validatePayloadKeySet(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 1,
        ));

        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 1,
        );
        $this->addToAssertionCount(1);
    }

    public function test_v1_sale_receipt_requires_approval_references_key(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];
        unset($payload['approval_references']);

        self::assertSame(
            'payload_missing_required:approval_references',
            $this->validator->validatePayloadKeySet(
                FiscalEventType::SALE_RECEIPT,
                $payload,
                eventVersion: 1,
            ),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^payload_approval_references_invalid:/');
        $this->validator->validatePerEventConstraints(
            FiscalEventType::SALE_RECEIPT,
            $payload,
            eventVersion: 1,
        );
    }

    public function test_v5_sale_receipt_requires_approval_references_key(): void
    {
        $payload = $this->canonicalV5Payload();
        unset($payload['approval_references']);

        self::assertSame(
            'payload_missing_required:approval_references',
            $this->validator->validatePayloadKeySet(
                FiscalEventType::SALE_RECEIPT,
                $payload,
                eventVersion: 5,
            ),
        );
    }

    public function test_malformed_line_item_missing_sku_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        unset($payload['line_items'][0]['sku']);

        $this->expectExceptionMessageMatches('/^payload_line_item_missing_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_line_item_with_extra_key_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['line_items'][0]['extra_field'] = 'rogue';

        $this->expectExceptionMessageMatches('/^payload_line_item_extra_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_payment_missing_method_code_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        unset($payload['payments'][0]['method_code']);

        $this->expectExceptionMessageMatches('/^payload_payment_missing_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_payment_foreign_currency_pair_half_null_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-03-split-foreign-currency-eur-usd'];
        $payload['payments'][1]['foreign_currency_code'] = null;

        $this->expectExceptionMessageMatches('/^payload_payment_foreign_currency_pair_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_vat_breakdown_missing_rate_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        unset($payload['vat_breakdown'][0]['rate']);

        $this->expectExceptionMessageMatches('/^payload_vat_breakdown_missing_keys:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_original_receipt_reference_present_on_sale_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['original_receipt_reference'] = [
            'fiscal_event_id' => '00000000-0000-4000-8000-000000000001',
            'original_business_date' => '2026-05-19',
            'original_receipt_uuid' => '00000000-0000-4000-8000-000000000002',
            'refund_reason' => 'should not be here',
        ];

        $this->expectExceptionMessageMatches('/^payload_invoice_type_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_refund_without_original_receipt_reference_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-09-refund-eur'];
        $payload['original_receipt_reference'] = null;

        $this->expectExceptionMessageMatches('/^payload_invoice_type_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_invoice_type_code_invalid_enum_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['invoice_type_code'] = 'BOGUS';

        $this->expectExceptionMessageMatches('/^payload_field_invalid:invoice_type_code/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_consumption_mode_invalid_enum_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['consumption_mode'] = 'drive-through';

        $this->expectExceptionMessageMatches('/^payload_field_invalid:consumption_mode/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_country_code_lowercase_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['seller']['tax_jurisdiction_country_code'] = 'fr';

        $this->expectExceptionMessageMatches('/^payload_seller_tax_jurisdiction_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_seller_tax_number_with_control_byte_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['seller']['tax_number'] = "1234\x01567";

        $this->expectExceptionMessageMatches('/^payload_tax_number_invalid:seller\.tax_number/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_seller_tax_number_with_trailing_whitespace_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['seller']['tax_number'] = '12345678901234 ';

        $this->expectExceptionMessageMatches('/^payload_tax_number_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_line_items_list_empty_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['line_items'] = [];

        $this->expectExceptionMessage('payload_line_items_empty');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_payments_list_empty_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['payments'] = [];

        $this->expectExceptionMessage('payload_payments_empty');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_malformed_vat_breakdown_list_empty_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['vat_breakdown'] = [];

        $this->expectExceptionMessage('payload_vat_breakdown_empty');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    // =================================================================
    // F-15 large-receipt acceptance — synthesis v5 §10
    // =================================================================

    public function test_f15_large_receipt_50_lines_10_payments_8_breakdown_is_accepted_under_100ms(): void
    {
        $gen = LargeReceiptFixtureGenerator::generate();
        $payload = $gen['payload'];

        // Sanity — generator produces 50 lines + 10 payments + 8 breakdown rows.
        self::assertCount(50, $payload['line_items']);
        self::assertCount(10, $payload['payments']);
        self::assertCount(8, $payload['vat_breakdown']);

        $start = hrtime(true);
        $error = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload);
        self::assertNull($error, 'F-15 key set must be exactly 28');

        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(100, $durationMs, "F-15 validator took {$durationMs}ms; expected < 100ms");
    }

    public function test_f15_large_receipt_matches_committed_fixture_bytes(): void
    {
        $gen = LargeReceiptFixtureGenerator::generate();
        $generatedBytes = GoldenFixtureBuilder::jcsCanonicalEncode($gen['payload']);
        $committedPath = __DIR__.'/../../Fixtures/Fiscal/sale-receipt-golden/v4/F-15-large/payload.json';

        self::assertFileExists($committedPath);
        $committedBytes = file_get_contents($committedPath);
        self::assertSame($committedBytes, $generatedBytes, 'Committed F-15 payload.json must match generator output byte-for-byte (deterministic re-generation invariant).');
    }

    /**
     * Pass 2A.PHP.2 — close Opus P3 #2 determinism deferral.
     *
     * Constructing the F-15 fixture via the generator helper TWICE must
     * produce byte-identical canonical output. Locks the property that
     * the generator depends only on its inputs (no clock / RNG / global
     * state) — a future regression that injected `Str::uuid()` or
     * `microtime()` into the generator would be caught here.
     */
    public function test_f15_large_receipt_generator_is_deterministic_via_double_construction(): void
    {
        $gen1 = LargeReceiptFixtureGenerator::generate();
        $gen2 = LargeReceiptFixtureGenerator::generate();

        $bytes1 = GoldenFixtureBuilder::jcsCanonicalEncode($gen1['payload']);
        $bytes2 = GoldenFixtureBuilder::jcsCanonicalEncode($gen2['payload']);

        self::assertSame(
            $bytes1,
            $bytes2,
            'F-15 generator must be deterministic: two constructions in the same process must emit byte-identical JCS canonical bytes (Opus P3 #2 closure).'
        );
    }

    // =================================================================
    // Defensive — invalid currency_scale at the boundary
    // =================================================================

    public function test_invalid_currency_scale_negative_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['currency_scale'] = -1;

        $this->expectExceptionMessageMatches('/^payload_currency_scale_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_invalid_currency_code_lowercase_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['currency_code'] = 'eur';

        $this->expectExceptionMessageMatches('/^payload_currency_code_invalid:/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    public function test_invalid_training_flag_not_bool_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['training_flag'] = 'false';

        $this->expectExceptionMessageMatches('/^payload_field_invalid:training_flag/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    // =================================================================
    // R2 N-01 — currency_scale restricted to {0, 2, 3} allowlist
    // (synthesis v3 §3 line 49-50 + spec v7 §11.2 line 577)
    // =================================================================

    /** R2 N-01: scale=8 (e.g. crypto-precision) MUST be rejected at the boundary. */
    public function test_negative_currency_scale_8_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['currency_scale'] = 8;

        $this->expectExceptionMessageMatches('/^payload_currency_scale_unsupported:value=8:allowed=0,2,3/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    // =================================================================
    // R2 N-02 — identity UUID + event_time_device ISO 8601 ms + tz
    // (synthesis v3 §3 lines 46-95 + spec v7 §11.2 line 571)
    // =================================================================

    /** R2 N-02: non-UUID receipt_uuid rejected with payload_uuid_format_mismatch. */
    public function test_negative_non_uuid_receipt_uuid_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['receipt_uuid'] = 'not-a-uuid';

        $this->expectExceptionMessageMatches('/^payload_uuid_format_mismatch:field=receipt_uuid:value=not-a-uuid/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** R2 N-02: UUID must be lowercase hex; uppercase is rejected. */
    public function test_negative_uppercase_uuid_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['receipt_uuid'] = '01234567-89AB-CDEF-0123-456789ABCDEF';

        $this->expectExceptionMessageMatches('/^payload_uuid_format_mismatch:field=receipt_uuid/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** R2 N-02: non-UUID cashier_id rejected. */
    public function test_negative_non_uuid_cashier_id_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['cashier_id'] = 'human-readable-id';

        $this->expectExceptionMessageMatches('/^payload_uuid_format_mismatch:field=cashier_id:value=human-readable-id/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** R2 N-02: event_time_device without milliseconds is rejected. */
    public function test_negative_malformed_event_time_device_no_milliseconds_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['event_time_device'] = '2026-05-20T14:30:00Z';

        $this->expectExceptionMessageMatches('/^payload_datetime_format_mismatch:field=event_time_device/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** R2 N-02: event_time_device without timezone offset or Z is rejected. */
    public function test_negative_malformed_event_time_device_no_timezone_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['event_time_device'] = '2026-05-20T14:30:00.000';

        $this->expectExceptionMessageMatches('/^payload_datetime_format_mismatch:field=event_time_device/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** R2 N-02: original_receipt_reference.fiscal_event_id must be UUID. */
    public function test_negative_original_receipt_reference_non_uuid_fiscal_event_id_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-09-refund-eur'];
        $payload['original_receipt_reference']['fiscal_event_id'] = 'not-uuid-here';

        $this->expectExceptionMessageMatches('/^payload_uuid_format_mismatch:field=original_receipt_reference\.fiscal_event_id/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    // =================================================================
    // R2 N-03 — TRAINING-flag coupling invariant
    // (migration 2026_05_20_120000_*.php:22-32 denormalization contract)
    // =================================================================

    /** R2 N-03: invoice_type_code='TRAINING' with training_flag=false is rejected. */
    public function test_negative_training_invoice_type_with_false_training_flag_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-11-training-flag-eur'];
        // F-11 baseline pairs TRAINING+true; break the coupling.
        $payload['training_flag'] = false;

        $this->expectExceptionMessageMatches('/^payload_training_flag_mismatch:invoice_type_code=TRAINING:training_flag=false/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    /** R2 N-03: invoice_type_code='SALE' with training_flag=true is rejected. */
    public function test_negative_sale_invoice_type_with_true_training_flag_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // F-01 baseline pairs SALE+false; break the coupling.
        $payload['training_flag'] = true;

        $this->expectExceptionMessageMatches('/^payload_training_flag_mismatch:invoice_type_code=SALE:training_flag=true/');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
    }

    // =================================================================
    // CanonicalPayloadReader integration — typed DTO round-trip
    // =================================================================

    public function test_canonical_payload_reader_builds_typed_view_from_baseline_payload(): void
    {
        // The reader operates on a FiscalEvent with an already-verified
        // payload; here we exercise the static SaleReceiptPayload+sub-DTO
        // construction path without a DB-backed FiscalEvent. That path is
        // the same code the reader's foreach loops invoke.
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];

        $dto = SaleReceiptPayload::fromArray($payload);
        $seller = SellerDTO::fromArray($dto->seller);
        $buyer = $dto->buyer === null ? null : BuyerDTO::fromArray($dto->buyer);

        self::assertSame('SALE', $dto->invoiceTypeCode);
        self::assertSame('12345678901234', $seller->taxNumber);
        self::assertSame('FR', $seller->taxJurisdictionCountryCode);
        self::assertSame('Paris', $seller->address->city);
        self::assertNotNull($buyer);
        self::assertSame('FR12345678901', $buyer->taxNumber);
        self::assertNotNull($buyer->address);
        self::assertSame('FR', $buyer->address->countryCode);

        // Line items + payments + vat_breakdown DTO construction.
        foreach ($dto->lineItems as $row) {
            $line = LineItemDTO::fromArray($row);
            self::assertNotSame('', $line->productId);
        }
        foreach ($dto->payments as $row) {
            $p = PaymentDTO::fromArray($row);
            self::assertNotSame('', $p->methodCode);
        }
        foreach ($dto->vatBreakdown as $row) {
            $v = VatBreakdownDTO::fromArray($row);
            self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $v->netAmount);
        }
    }

    public function test_canonical_payload_reader_builds_original_receipt_reference_dto_on_refund(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-09-refund-eur'];
        $dto = SaleReceiptPayload::fromArray($payload);
        self::assertNotNull($dto->originalReceiptReference);
        $ref = OriginalReceiptReferenceDTO::fromArray($dto->originalReceiptReference);
        self::assertSame('REFUND', $dto->invoiceTypeCode);
        self::assertSame('customer return', $ref->refundReason);
    }

    public function test_canonical_payload_reader_builds_voucher_redemption_dtos(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-04-voucher-redemption-eur'];
        $dto = SaleReceiptPayload::fromArray($payload);
        self::assertCount(1, $dto->vouchersRedeemed);
        $v = VoucherRedemptionDTO::fromArray($dto->vouchersRedeemed[0]);
        self::assertSame('GC-ABC-123', $v->voucherCode);
        self::assertSame('24.00', $v->redeemedAmount);
    }

    public function test_canonical_payload_reader_for_sale_receipt_assembles_view_over_fiscal_event(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-07-b2b-buyer-eur'];

        // Build an in-memory FiscalEvent (no DB) with the payload casted.
        $event = new FiscalEvent;
        $event->id = '11111111-1111-4111-8111-111111111111';
        $event->event_type = FiscalEventType::SALE_RECEIPT;
        $event->payload = $payload;

        $reader = new CanonicalPayloadReader;
        $view = $reader->forSaleReceipt($event);

        self::assertSame('SALE', $view->payload->invoiceTypeCode);
        self::assertSame('Default Seller S.A.', $view->seller()->name);
        self::assertNotNull($view->buyer());
        self::assertSame('Acme B2B SARL', $view->buyer()->name);
        self::assertCount(1, $view->lineItems());
        self::assertCount(1, $view->payments());
        self::assertCount(1, $view->vatBreakdown());
        self::assertNull($view->originalReceiptReference());
    }

    public function test_canonical_payload_reader_returns_account_payment_view(): void
    {
        $event = new FiscalEvent;
        $event->id = '44444444-4444-4444-8444-444444444444';
        $event->event_type = FiscalEventType::ACCOUNT_PAYMENT;
        $event->payload = $this->canonicalAccountPaymentPayload();

        $reader = new CanonicalPayloadReader;
        $view = $reader->forAccountPayment($event);

        self::assertInstanceOf(AccountPaymentView::class, $view);
        self::assertSame('ACCOUNT_PAYMENT', $view->payload->receiptTypeCode);
        self::assertSame('Mariam Ben Ali', $view->customer->name);
        self::assertSame('100.000', $view->payment->amount);
        self::assertFalse($view->staleness->balanceSnapshotStale);
    }

    public function test_canonical_payload_reader_rejects_wrong_event_type(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $event = new FiscalEvent;
        $event->id = '22222222-2222-4222-8222-222222222222';
        $event->event_type = FiscalEventType::CHAIN_BREAK_DETECTED;
        $event->payload = $payload;

        $reader = new CanonicalPayloadReader;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/CanonicalPayloadReader::forSaleReceipt called with event_type=/');
        $reader->forSaleReceipt($event);
    }

    public function test_canonical_payload_reader_rejects_null_payload(): void
    {
        $event = new FiscalEvent;
        $event->id = '33333333-3333-4333-8333-333333333333';
        $event->event_type = FiscalEventType::SALE_RECEIPT;
        $event->payload = null;

        $reader = new CanonicalPayloadReader;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/CanonicalPayloadReader::forSaleReceipt called on fiscal_event_id=/');
        $reader->forSaleReceipt($event);
    }

    // =================================================================
    // All golden fixtures F-1..F-14 are accepted (round-trip guard)
    // =================================================================

    public function test_all_golden_fixtures_f1_to_f16_pass_validator(): void
    {
        $fixtures = GoldenFixtureBuilder::all();
        self::assertCount(15, $fixtures, 'Builder must produce exactly F-1 through F-14 plus F-16 (F-15 is generated separately by LargeReceiptFixtureGenerator).');

        foreach ($fixtures as $slug => $payload) {
            // F-16 is the only event_version=4 fixture in this builder;
            // every other fixture is validated at the default v1 key set,
            // matching this test's pre-existing behavior for F-1..F-14.
            $eventVersion = str_starts_with($slug, 'F-16') ? 4 : 1;
            try {
                $keysetError = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: $eventVersion);
                self::assertNull($keysetError, "Fixture {$slug} key-set rejected: {$keysetError}");
                $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: $eventVersion);
            } catch (RuntimeException $e) {
                self::fail("Fixture {$slug} rejected by validator: {$e->getMessage()}");
            }
        }
        $this->addToAssertionCount(1);
    }

    // =================================================================
    // v4 refund/void chain integration — spec
    // 2026-07-31-v3-refund-chain-integration.md §3, §17
    // =================================================================

    /**
     * review round-2 MINOR — renamed from the previous (miscounted)
     * "...is_exactly_32_keys" name: SALE_RECEIPT_PAYLOAD_KEYS_V4 actually
     * has 33 entries (the v3 30-key contract plus the three v4-only keys
     * `original_line_references`/`refund_destination`/`settlement_allocation`
     * — `shift_id` was already present pre-v4, bringing the v3 count to
     * 30, not 29). The old body also never actually asserted a key COUNT
     * or the exact key SET at all — only that validation passed — so the
     * name's claim was untested either way; both are asserted directly
     * now.
     */
    public function test_f16_v4_refund_key_set_is_exactly_33_keys(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];

        self::assertCount(33, FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V4);
        self::assertCount(33, $payload);
        self::assertEqualsCanonicalizing(FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V4, array_keys($payload));

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4));
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
        $this->addToAssertionCount(1);
    }

    public function test_v4_key_set_rejects_v3_payload_as_missing_required(): void
    {
        // A v3-shaped payload (no original_line_references/refund_destination/
        // settlement_allocation) validated AS v4 must fail missing-required,
        // not silently pass — the v4 key set is a strict superset.
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['cash_rounding_adjustment'] = '0.00';
        $payload['cash_rounding_denomination'] = '0.00';

        $error = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
        self::assertNotNull($error);
        self::assertStringStartsWith('payload_missing_required:', $error);
        self::assertStringContainsString('original_line_references', $error);
        self::assertStringContainsString('refund_destination', $error);
        self::assertStringContainsString('settlement_allocation', $error);
    }

    public function test_v4_key_set_rejects_v4_extra_field_on_v3_payload(): void
    {
        // The v4-only keys must be REJECTED as extras when validated against
        // the v3 key set — v1/v2/v3 events must keep rejecting them forever.
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];

        $error = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 3);
        self::assertNotNull($error);
        self::assertStringStartsWith('payload_extra_field:', $error);
    }

    public function test_v4_void_invoice_type_is_rejected(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['invoice_type_code'] = 'VOID';

        $this->expectExceptionMessage('payload_void_authoring_prohibited');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_sale_invoice_type_is_rejected(): void
    {
        // event_version=4 never legitimately resolves for anything but
        // REFUND (spec §2's resolution table) — a SALE at v4 is a
        // structural anomaly, not merely an unauthored combination.
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['invoice_type_code'] = 'SALE';

        $this->expectExceptionMessage('payload_invoice_type_invalid');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_refund_transaction_discount_must_be_zero(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['transaction_discount_amount'] = '1.00';
        $payload['transaction_discount_reason'] = 'whole-receipt discount on the original';
        // Keep the total-arithmetic identity satisfied so the discount-zero
        // check (not an unrelated arithmetic failure) is what actually fires.
        $payload['total'] = '11.00';

        $this->expectExceptionMessage('payload_v4_refund_transaction_discount_must_be_zero');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_refund_destination_rejects_non_cash_literal(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['refund_destination'] = 'store_voucher';

        $this->expectExceptionMessage('payload_field_invalid:refund_destination');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_settlement_allocation_must_be_null(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['settlement_allocation'] = ['some' => 'value'];

        $this->expectExceptionMessage('payload_settlement_allocation_not_null');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_original_line_references_length_must_match_line_items(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['original_line_references'] = [];

        $this->expectExceptionMessage('payload_original_line_references_length_mismatch');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_original_line_reference_product_id_must_match_line_item(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['original_line_references'][0]['product_id'] = 'some-other-product';

        $this->expectExceptionMessage('payload_original_line_reference_product_id_mismatch');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_original_line_reference_quantity_must_match_line_item(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['original_line_references'][0]['quantity'] = '2.000';

        $this->expectExceptionMessage('payload_original_line_reference_quantity_mismatch');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_original_line_reference_disposition_must_be_valid_enum(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['original_line_references'][0]['disposition'] = 'destroyed';

        $this->expectExceptionMessage('payload_field_invalid:original_line_references[0].disposition');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_payments_must_be_exactly_one_row(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['payments'][] = $payload['payments'][0];
        // Keep the arithmetic identity satisfied for the second leg so the
        // single-cash-leg check (not an unrelated aggregate mismatch) fires.
        $payload['payments'][0]['amount'] = '6.00';
        $payload['payments'][1]['amount'] = '6.00';

        $this->expectExceptionMessage('payload_v4_refund_payments_not_single_leg');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_payments_must_be_cash(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['payments'][0]['method_code'] = 'CARD';
        $payload['payments'][0]['instrument_type'] = 'visa';
        $payload['payments'][0]['instrument_serial'] = '4242';

        $this->expectExceptionMessage('payload_v4_refund_payment_not_cash');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_payments_instrument_type_must_be_null(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $payload['payments'][0]['instrument_type'] = 'cash_drawer';

        $this->expectExceptionMessage('payload_v4_refund_payment_instrument_type_must_be_null');
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload, eventVersion: 4);
    }

    public function test_v4_canonical_payload_reader_parses_original_line_references(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];

        $event = new FiscalEvent;
        $event->id = '44444444-4444-4444-8444-444444444444';
        $event->event_type = FiscalEventType::SALE_RECEIPT;
        $event->payload = $payload;

        $reader = new CanonicalPayloadReader;
        $view = $reader->forSaleReceipt($event);

        self::assertNotNull($view->originalLineReferences());
        self::assertCount(1, $view->originalLineReferences());
        self::assertSame('restock', $view->originalLineReferences()[0]->disposition);
        self::assertSame(0, $view->originalLineReferences()[0]->originalLineIndex);
        self::assertSame('prod-default', $view->originalLineReferences()[0]->productId);
        self::assertSame('1.000', $view->originalLineReferences()[0]->quantity);
    }

    public function test_v1_v2_v3_canonical_payload_reader_original_line_references_is_null(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];

        $event = new FiscalEvent;
        $event->id = '55555555-5555-4555-8555-555555555555';
        $event->event_type = FiscalEventType::SALE_RECEIPT;
        $event->payload = $payload;

        $reader = new CanonicalPayloadReader;
        $view = $reader->forSaleReceipt($event);

        self::assertNull($view->originalLineReferences());
    }

    public function test_f16_matches_committed_fixture_bytes(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $generatedBytes = GoldenFixtureBuilder::jcsCanonicalEncode($payload);
        $committedPath = __DIR__.'/../../Fixtures/Fiscal/sale-receipt-golden/v4/F-16-refund-v4-cash-eur/payload.json';

        self::assertFileExists($committedPath);
        $committedBytes = file_get_contents($committedPath);
        self::assertSame($committedBytes, $generatedBytes, 'Committed F-16 payload.json must match generator output byte-for-byte.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function canonicalAccountChargePayload(array $overrides = []): array
    {
        $payload = [
            'account_charge_uuid' => '66666666-6666-4666-8666-666666666666',
            'business_date' => '2026-05-21',
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'charge_terms' => [
                'due_date' => '2026-06-20',
                'payment_terms_days' => 30,
                'terms_label' => 'Net 30',
            ],
            'credit_decision' => [
                'credit_available_after' => '81.000',
                'credit_available_before' => '200.000',
                'credit_limit' => '500.000',
                'decision' => 'approved',
                'limit_exceeded' => false,
                'mirror_stale_at_authoring' => false,
                'override_evidence' => null,
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
                'customer_id' => '55555555-5555-4555-8555-555555555555',
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
                'unit_price' => '119.000',
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

        return array_replace_recursive($payload, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalV5Payload(): array
    {
        $path = __DIR__.'/../../Fixtures/Fiscal/sale-receipt-v5-golden.json';
        $fixtureBytes = file_get_contents($path);
        self::assertIsString($fixtureBytes);

        /** @var array{expected_canonical_string: string} $fixture */
        $fixture = json_decode($fixtureBytes, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($fixture['expected_canonical_string'], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertAccountChargeAccepted(array $payload): void
    {
        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_CHARGE, $payload));
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_CHARGE, $payload);
        $this->addToAssertionCount(1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function phaseFourApprovalPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'approval_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'approval_scope' => 'discount_limit_override',
            'cashier_user_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'company_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'event_time_device' => '2026-05-22T10:00:00.000Z',
            'policy_version' => 'pos-discount-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => 'Approved',
            'regime_extensions' => null,
            'requested_at_device' => '2026-05-22T09:59:58.000Z',
            'resolved_at_device' => '2026-05-22T10:00:00.000Z',
            'supervisor_user_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'supervisor_user_snapshot' => ['name' => 'Manager', 'roles' => ['manager']],
            'target' => ['target_reference_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'],
            'tenant_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'terminal_id' => '99999999-9999-4999-8999-999999999999',
            'training_flag' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function phaseFourOverridePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'approval_event_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'approval_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'approval_scope' => 'discount_limit_override',
            'company_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'event_time_device' => '2026-05-22T10:00:00.000Z',
            'override_context' => [
                'target_event_type' => 'DISCOUNT_LIMIT_OVERRIDE',
                'target_reference_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            ],
            'policy_version' => 'pos-discount-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => 'Approved',
            'supervisor_user_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'target' => ['discount_value' => '20'],
            'tenant_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'terminal_id' => '99999999-9999-4999-8999-999999999999',
            'training_flag' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function expectAccountChargeException(string $pattern, array $payload): void
    {
        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_CHARGE, $payload));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches($pattern);
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_CHARGE, $payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function canonicalAccountPaymentPayload(array $overrides = []): array
    {
        $payload = [
            'account_payment_uuid' => '44444444-4444-4444-8444-444444444444',
            'business_date' => '2026-05-21',
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'address' => null,
                'customer_category' => 'retail',
                'customer_id' => '55555555-5555-4555-8555-555555555555',
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Mariam Ben Ali',
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => '2026-05-21T10:15:30.000Z',
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'payment_amount' => '100.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '200.000',
                'projected_receivable_balance_after' => '200.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'payment' => [
                'amount' => '100.000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
                'repository_id' => null,
            ],
            'receipt_type_code' => 'ACCOUNT_PAYMENT',
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
            'training_flag' => false,
            'treasury_allocation_policy' => 'FIFO',
        ];

        return array_replace_recursive($payload, $overrides);
    }
    // =================================================================
    // LEDGER C-6 item 4 (finding F-4) — the Z-family vat_breakdown tripwire
    //
    // `validateZReportFamilyPayload` checked six scalars and never looked at
    // `vat_breakdown` at all, which is the only reason the Z family escaped the
    // invariant `validateSaleReceiptAggregateConsistency` has enforced on
    // SALE_RECEIPT since v1 — and therefore the reason the C-2 (per-rate
    // gross-as-net) and C-6 (headline gross-as-net) defects reached signed bytes
    // undetected. These tests pin the extension.
    //
    // WHAT IS ASSERTED, and why not more (the honest boundary):
    //
    //   (a) per group `gross_amount == net_amount + vat_amount`, EXACT, always.
    //   (b) on a REFUND-FREE shift only: `Σ net_amount == net_sales` and
    //       `Σ vat_amount == tax_amount`, EXACT.
    //
    // (b) is gated on `refunds_totals.count == 0` because the device's
    // `vat_breakdown` is SIGNED — refunds are SUBTRACTED from it
    // (`zReportService.ts` refund branch) — while `receipt_totals`/`sales_totals`
    // are SALE-ONLY by design (refunds live in `refunds_totals`). The two
    // therefore cannot reconcile once a refund exists, and no field in the Z
    // payload carries the refund's VAT split with which to bridge them. Asserting
    // it unconditionally would quarantine every valid shift that took a return.
    //
    // NOT asserted: `net_sales + tax_amount == gross_sales`. `gross_sales` is
    // Σ receipt `total` — POST transaction-discount and POST cash-rounding —
    // while the other two are PRE both. The canonical receipt has the identical
    // wedge and closes it with `transaction_discount_amount` +
    // `cash_rounding_adjustment` (see validateSaleReceiptAggregateConsistency
    // identity 1); the Z payload carries neither field, so the identity is not
    // available and is not claimed.
    //
    // NOT caught, deliberately: a FULLY-LEGACY payload, where the breakdown AND
    // the headline are both gross-as-net. Legacy `net_amount` was Σ line_total
    // and legacy `net_sales` was Σ receipt `subtotal` — the same number — so
    // (a) and (b) both hold on it. That is not an oversight but the requirement:
    // this validator runs over STORED HISTORY, not only at ingest (see the
    // execution-context note on the production method), so a check that rejected
    // the pre-fix corpus would fail `fiscal:verify-chain` on every sealed Z
    // authored before the C-2/C-6 device build. What IS caught is any payload
    // where the two disagree — which is exactly every partial state: the
    // post-C-2/pre-C-6 build, the reverse, and any future single-consumer
    // regression of one of the three aggregation sites.
    // =================================================================

    public function test_z_report_family_accepts_a_correctly_decomposed_refund_free_payload(): void
    {
        foreach (self::zFamilyTypes() as $type) {
            $this->validator->validatePerEventConstraints($type, self::zFamilyPayload($type));
            $this->addToAssertionCount(1);
        }
    }

    public function test_z_report_family_rejects_a_gross_as_net_vat_breakdown(): void
    {
        // THE tripwire. The pre-C-2 decomposition: `net_amount` carries the GROSS
        // line total and `gross_amount` is net + vat on top of it, against a
        // correctly-derived headline. Every component is individually plausible;
        // only the cross-check catches it.
        foreach (self::zFamilyTypes() as $type) {
            $payload = self::zFamilyPayload($type, [
                'vat_breakdown' => [
                    ['tax_rate' => 20, 'net_amount' => '120.00', 'vat_amount' => '20.00', 'gross_amount' => '140.00'],
                ],
            ]);

            try {
                $this->validator->validatePerEventConstraints($type, $payload);
                self::fail('expected a gross-as-net vat_breakdown to be rejected for '.$type->value);
            } catch (RuntimeException $e) {
                self::assertStringContainsString(
                    'payload_aggregate_consistency:vat_breakdown_net_sum_ne_net_sales',
                    $e->getMessage(),
                );
            }
        }
    }

    public function test_z_report_family_rejects_a_gross_as_net_headline_against_a_correct_breakdown(): void
    {
        // The C-6 defect itself, as it would have arrived post-C-2: correct
        // per-rate rows, `net_sales` still carrying the gross.
        foreach (self::zFamilyTypes() as $type) {
            $payload = self::zFamilyPayload($type);
            $payload[self::zFamilyTotalsKey($type)]['net_sales'] = '120.00';

            try {
                $this->validator->validatePerEventConstraints($type, $payload);
                self::fail('expected a gross-as-net headline to be rejected for '.$type->value);
            } catch (RuntimeException $e) {
                self::assertStringContainsString(
                    'payload_aggregate_consistency:vat_breakdown_net_sum_ne_net_sales',
                    $e->getMessage(),
                );
            }
        }
    }

    public function test_z_report_family_rejects_a_group_whose_gross_is_not_net_plus_vat(): void
    {
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, [
            'vat_breakdown' => [
                ['tax_rate' => 20, 'net_amount' => '100.00', 'vat_amount' => '20.00', 'gross_amount' => '119.00'],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_aggregate_consistency:group_gross_ne_net_plus_vat/');
        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
    }

    public function test_z_report_family_rejects_a_vat_sum_that_disagrees_with_the_headline_tax(): void
    {
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT);
        $payload['receipt_totals']['tax_amount'] = '19.00';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_aggregate_consistency:vat_breakdown_vat_sum_ne_tax_amount/');
        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
    }

    public function test_z_report_family_skips_the_sum_identities_once_the_shift_took_a_refund(): void
    {
        // The device SUBTRACTS a refund from `vat_breakdown` while leaving the
        // sale-only headline alone, so the sums legitimately stop reconciling.
        // The per-group identity still holds and is still enforced (next test).
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, [
            'refunds_totals' => ['amount' => '12.00', 'count' => 1],
            'vat_breakdown' => [
                ['tax_rate' => 20, 'net_amount' => '90.00', 'vat_amount' => '18.00', 'gross_amount' => '108.00'],
            ],
        ]);

        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_z_report_family_still_enforces_the_group_identity_on_a_refund_bearing_shift(): void
    {
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, [
            'refunds_totals' => ['amount' => '12.00', 'count' => 1],
            'vat_breakdown' => [
                ['tax_rate' => 20, 'net_amount' => '90.00', 'vat_amount' => '18.00', 'gross_amount' => '110.00'],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_aggregate_consistency:group_gross_ne_net_plus_vat/');
        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
    }

    public function test_z_report_family_accepts_a_signed_negative_bucket(): void
    {
        // A refund-only shift leaves every bucket negative. The Z-family money
        // guard must therefore accept a leading minus — the breakdown is SIGNED,
        // unlike a SALE_RECEIPT's, whose `moneyRegex()` is unsigned — and the
        // group identity still has to hold on the negative values.
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, [
            'refunds_totals' => ['amount' => '12.00', 'count' => 1],
            'vat_breakdown' => [
                ['tax_rate' => 20, 'net_amount' => '-10.00', 'vat_amount' => '-2.00', 'gross_amount' => '-12.00'],
            ],
        ]);

        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_z_report_family_accepts_an_empty_shift(): void
    {
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, [
            'vat_breakdown' => [],
            'receipt_totals' => ['count' => 0, 'gross_sales' => '0.00', 'net_sales' => '0.00', 'tax_amount' => '0.00'],
        ]);

        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_z_report_family_rejects_a_vat_breakdown_that_is_not_a_list_of_objects(): void
    {
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, ['vat_breakdown' => ['not-an-object']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_object_invalid:vat_breakdown\.0/');
        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
    }

    public function test_z_report_family_accepts_a_legacy_fully_gross_as_net_payload(): void
    {
        // Documented boundary, asserted rather than left to the docblock: the
        // pre-fix sealed corpus (breakdown AND headline both gross-as-net) still
        // parses, because this validator also runs over STORED HISTORY
        // (`VerifyEventChainCommand:544` re-parses `canonical_bytes`,
        // `QuarantineBestEffortParseController:91` re-parses stored events) and a
        // check that rejected it would break chain verification on every Z sealed
        // before the C-2/C-6 device build.
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, [
            'vat_breakdown' => [
                ['tax_rate' => 20, 'net_amount' => '120.00', 'vat_amount' => '20.00', 'gross_amount' => '140.00'],
            ],
            'receipt_totals' => ['count' => 1, 'gross_sales' => '120.00', 'net_sales' => '120.00', 'tax_amount' => '20.00'],
        ]);

        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
        $this->addToAssertionCount(1);
    }

    // ── Fix round (gate CHANGES-REQUIRED) ────────────────────────────────────

    public function test_z_report_family_accepts_a_one_ulp_group_rounding_residue(): void
    {
        // Gate finding P2-1. `net_amount`, `vat_amount` and `gross_amount` are
        // three INDEPENDENTLY half-up-rounded accumulators, so a line below the
        // currency scale makes round(g−v) + round(v) land one ulp away from
        // round(g). 10.01 + 2.01 = 12.02 against a gross of 12.01 is a
        // legitimately-signed shape, and an exact rule 1 rejected it — which,
        // through VerifyEventChainCommand, would have meant rejecting sealed
        // history. One ulp of slack, and no more.
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, [
            'vat_breakdown' => [
                ['tax_rate' => 20, 'net_amount' => '10.01', 'vat_amount' => '2.01', 'gross_amount' => '12.01'],
            ],
            'receipt_totals' => ['count' => 1, 'gross_sales' => '12.01', 'net_sales' => '10.01', 'tax_amount' => '2.01'],
        ]);

        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_z_report_family_still_rejects_a_two_ulp_group_deviation(): void
    {
        // The slack is exactly one ulp: two ulp is not rounding, it is an error.
        // Headline kept consistent with the sums so this isolates rule 1.
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, [
            'vat_breakdown' => [
                ['tax_rate' => 20, 'net_amount' => '10.01', 'vat_amount' => '2.01', 'gross_amount' => '12.00'],
            ],
            'receipt_totals' => ['count' => 1, 'gross_sales' => '12.00', 'net_sales' => '10.01', 'tax_amount' => '2.01'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_aggregate_consistency:group_gross_ne_net_plus_vat/');
        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
    }

    public function test_z_report_family_rejects_money_bcmath_cannot_parse_without_fataling(): void
    {
        // Gate finding P2-3. `is_numeric('1e2')` is TRUE, so the numeric-string
        // check passed the value straight into bcmath, which raises a
        // ValueError — NOT a RuntimeException. SALE_RECEIPT is protected by
        // `assertMoneyString` running before any arithmetic; the Z family had no
        // equivalent, so a corrupted stored Z FATALED `fiscal:verify-chain`
        // instead of being recorded as a parse failure.
        foreach (['1e2', '+12.00', '12.', '.5', 'NaN', '0x1A', '12.000000'] as $poison) {
            $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, [
                'vat_breakdown' => [
                    ['tax_rate' => 20, 'net_amount' => $poison, 'vat_amount' => '20.00', 'gross_amount' => '120.00'],
                ],
            ]);

            try {
                $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
                self::fail('expected money "'.$poison.'" to be rejected');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('payload_money_scale_mismatch', $e->getMessage());
            }
        }
    }

    public function test_z_report_family_rejects_a_malformed_headline_money_string(): void
    {
        $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT);
        $payload['receipt_totals']['net_sales'] = '1e2';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_money_scale_mismatch:field=receipt_totals\.net_sales/');
        $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
    }

    public function test_strict_parser_records_a_parse_failure_instead_of_fataling_on_unparseable_z_money(): void
    {
        // Gate finding P2-3, second layer — the OUTCOME that matters: a stored Z
        // whose money bcmath cannot parse must be RECORDED as a parse failure,
        // never crash the caller. `VerifyEventChainCommand:544` re-parses every
        // row's canonical_bytes, so before this a single corrupted Z fataled
        // `fiscal:verify-chain` for the whole terminal.
        //
        // Driven end to end through the REAL parser with a REAL X_REPORT
        // envelope (the smallest Z-family key set). The validator class is
        // `final`, so no test double can inject the ValueError directly; the
        // parser's ValueError→RuntimeException clause is instead proven
        // load-bearing by revert-replay — remove the validator's money guard and
        // this test still passes, with `payload_value_error` in the reason,
        // ONLY because of that clause (evidence in the commit message).
        $parser = new StrictCanonicalParser(
            new FiscalEventPayloadRegistry,
            new FiscalPayloadConstraintValidator,
        );

        $result = $parser->parse(
            self::xReportCanonicalBytes(['net_amount' => '1e2']),
            FiscalEventType::X_REPORT,
        );

        self::assertFalse($result->ok, 'a poisoned Z-family money value must not parse');
        self::assertNotNull($result->failureReason);
        self::assertStringContainsString('payload_money_scale_mismatch', $result->failureReason);
    }

    public function test_strict_parser_accepts_a_well_formed_x_report_envelope(): void
    {
        // Guard the guard: if the envelope fixture above ever stopped reaching
        // `validatePerEventConstraints` at all, the failure assertion would pass
        // vacuously on an envelope-shape error instead of the money guard.
        $parser = new StrictCanonicalParser(
            new FiscalEventPayloadRegistry,
            new FiscalPayloadConstraintValidator,
        );

        $result = $parser->parse(self::xReportCanonicalBytes(), FiscalEventType::X_REPORT);

        self::assertTrue($result->ok, 'fixture drift: '.($result->failureReason ?? ''));
    }

    public function test_z_report_family_rejects_a_non_integer_refund_count_instead_of_skipping(): void
    {
        // Gate finding P3-1: the refund gate failed OPEN. A JSON `"0"` is not an
        // int, so `! is_int(...)` returned early and BOTH sum identities were
        // silently skipped — the exact shape that would let a gross-as-net Z
        // through the tripwire that exists to catch it.
        foreach (['0', 0.0, null, true] as $badCount) {
            $payload = self::zFamilyPayload(FiscalEventType::Z_REPORT, [
                'refunds_totals' => ['amount' => '0.00', 'count' => $badCount],
            ]);

            try {
                $this->validator->validatePerEventConstraints(FiscalEventType::Z_REPORT, $payload);
                self::fail('expected refunds_totals.count '.var_export($badCount, true).' to be rejected');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('payload_field_invalid:refunds_totals.count', $e->getMessage());
            }
        }
    }

    /**
     * A complete, well-formed X_REPORT envelope — the smallest Z-family key set
     * (19 keys) — as canonical bytes.
     *
     * @param  array<string, mixed>  $vatRowOverrides
     */
    private static function xReportCanonicalBytes(array $vatRowOverrides = []): string
    {
        $payload = [
            'business_date' => '2026-08-21',
            'cash_drawer_totals' => [],
            'generated_at_device' => '2026-08-21T18:00:00.000Z',
            'operational_event_range' => [],
            'operator_id' => '33333333-3333-4333-8333-333333333333',
            'operator_name' => 'Alice',
            'payment_method_totals' => [],
            'period_end' => '2026-08-21T18:00:00.000Z',
            'period_start' => '2026-08-21T08:00:00.000Z',
            'receipt_count' => 1,
            'refunds_totals' => ['amount' => '0.00', 'count' => 0],
            'sales_totals' => ['gross_sales' => '120.00', 'net_sales' => '100.00', 'tax_amount' => '20.00'],
            'session_id' => '11111111-1111-4111-8111-111111111111',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'terminal_id' => '44444444-4444-4444-8444-444444444444',
            'training_flag' => false,
            'vat_breakdown' => [
                array_replace(
                    ['tax_rate' => 20, 'net_amount' => '100.00', 'vat_amount' => '20.00', 'gross_amount' => '120.00'],
                    $vatRowOverrides,
                ),
            ],
            'voids_totals' => ['count' => 0],
            'x_report_uuid' => '55555555-5555-4555-8555-555555555555',
        ];

        $envelope = [
            'business_date' => '2026-08-21',
            'chain_context' => 'z_session',
            'company_id' => '66666666-6666-4666-8666-666666666666',
            // ISO 8601 UTC to SECONDS in the envelope (milliseconds are the
            // PAYLOAD's `generated_at_device` contract, not the envelope's).
            'event_time_device' => '2026-08-21T18:00:00Z',
            'event_type' => 'X_REPORT',
            'event_version' => 1,
            'operator_id' => '33333333-3333-4333-8333-333333333333',
            'payload' => $payload,
            'previous_hash' => str_repeat('0', 64),
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => '77777777-7777-4777-8777-777777777777',
            'terminal_id' => '44444444-4444-4444-8444-444444444444',
        ];

        return (string) json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<FiscalEventType> */
    private static function zFamilyTypes(): array
    {
        return [FiscalEventType::Z_REPORT, FiscalEventType::X_REPORT, FiscalEventType::SESSION_CLOSE];
    }

    /**
     * Z_REPORT nests its headline under `receipt_totals`; X_REPORT and
     * SESSION_CLOSE use `sales_totals` (`zSessionAuthoring.ts:384/:427/:482`).
     */
    private static function zFamilyTotalsKey(FiscalEventType $type): string
    {
        return $type === FiscalEventType::Z_REPORT ? 'receipt_totals' : 'sales_totals';
    }

    /**
     * A minimal, CORRECT Z-family payload: 120.00 gross at 20 % ⇒ net 100.00,
     * VAT 20.00. Only the fields `validateZReportFamilyPayload` reads are
     * present — the key-set contract is a separate call with its own tests.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function zFamilyPayload(FiscalEventType $type, array $overrides = []): array
    {
        $payload = [
            'session_id' => '11111111-1111-4111-8111-111111111111',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'operator_id' => '33333333-3333-4333-8333-333333333333',
            'terminal_id' => '44444444-4444-4444-8444-444444444444',
            'business_date' => '2026-08-21',
            'training_flag' => false,
            'refunds_totals' => ['amount' => '0.00', 'count' => 0],
            'vat_breakdown' => [
                ['tax_rate' => 20, 'net_amount' => '100.00', 'vat_amount' => '20.00', 'gross_amount' => '120.00'],
            ],
        ];
        $payload[self::zFamilyTotalsKey($type)] = [
            'count' => 1,
            'gross_sales' => '120.00',
            'net_sales' => '100.00',
            'tax_amount' => '20.00',
        ];

        return array_replace($payload, $overrides);
    }
}
