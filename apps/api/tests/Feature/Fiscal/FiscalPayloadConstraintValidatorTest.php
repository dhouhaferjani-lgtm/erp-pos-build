<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
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

/**
 * Tests for `FiscalPayloadConstraintValidator::validateSaleReceiptPayload`
 * under the Pass 2A.PHP.1 27-key Candidate C-v3 contract (synthesis v5 §6).
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

    public function test_baseline_27_key_payload_is_accepted(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload));

        // No throw == accepted.
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

    public function test_account_charge_rejects_amount_balance_mismatch(): void
    {
        $payload = $this->canonicalAccountChargePayload();
        $payload['local_balance_snapshot']['charge_amount'] = '120.000';

        $this->expectAccountChargeException('/payload_account_charge_amount_mismatch/', $payload);
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

    public function test_account_charge_accepts_discount_present_and_absent_variants(): void
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
            'TN' => '1234567ABC000',
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

    public function test_payload_with_extra_28th_key_is_rejected_with_extra_field_prefix(): void
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
        self::assertNull($error, 'F-15 key set must be exactly 27');

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

    public function test_all_golden_fixtures_f1_to_f14_pass_validator(): void
    {
        $fixtures = GoldenFixtureBuilder::all();
        self::assertCount(14, $fixtures, 'Builder must produce exactly F-1 through F-14.');

        foreach ($fixtures as $slug => $payload) {
            try {
                $keysetError = $this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload);
                self::assertNull($keysetError, "Fixture {$slug} key-set rejected: {$keysetError}");
                $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);
            } catch (RuntimeException $e) {
                self::fail("Fixture {$slug} rejected by validator: {$e->getMessage()}");
            }
        }
        $this->addToAssertionCount(1);
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

        return array_replace_recursive($payload, $overrides);
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
}
