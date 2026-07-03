<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Modules\Import\Services\PartiesRowMapper;
use PHPUnit\Framework\TestCase;

final class PartiesRowMapperTest extends TestCase
{
    private PartiesRowMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new PartiesRowMapper;
    }

    public function test_partner_data_maps_party_fields_to_partner_payload(): void
    {
        $payload = $this->mapper->toPartnerData([
            'name' => 'Acme Corp',
            'type' => 'customer',
            'code' => 'CUST-001',
            'email' => 'acme@example.com',
            'phone' => '+123456789',
            'tax_id' => 'FR12345678901',
            'address_line1' => '123 Main Street',
            'address_city' => 'Paris',
            'address_postal_code' => '75001',
            'address_country' => 'FR',
        ]);

        $this->assertSame('Acme Corp', $payload['name']);
        $this->assertSame('customer', $payload['type']);
        $this->assertSame('CUST-001', $payload['code']);
        $this->assertSame('FR12345678901', $payload['vat_number']);
        $this->assertSame('123 Main Street', $payload['street_address']);
        $this->assertSame('Paris', $payload['city']);
        $this->assertSame('75001', $payload['postal_code']);
        $this->assertSame('FR', $payload['country']);
    }

    public function test_balance_payloads_map_all_sign_quadrants_to_document_types_and_magnitudes(): void
    {
        $customerPositive = $this->mapper->toBalancePayloads([
            'type' => 'customer',
            'opening_balance' => '100',
            'balance_date' => '2026-01-31',
            'reference' => 'LEG-CUST',
        ], '2026-01-01', 'CUST-001');

        $customerNegative = $this->mapper->toBalancePayloads([
            'type' => 'customer',
            'opening_balance' => '-100',
        ], '2026-01-01', 'CUST-002');

        $supplierPositive = $this->mapper->toBalancePayloads([
            'type' => 'supplier',
            'opening_balance' => '100',
        ], '2026-01-01', 'SUP-001');

        $supplierNegative = $this->mapper->toBalancePayloads([
            'type' => 'supplier',
            'opening_balance' => '-100',
        ], '2026-01-01', 'SUP-002');

        $customerPositiveAr = $customerPositive['ar'];
        $this->assertIsArray($customerPositiveAr);
        $this->assertSame('invoice', $customerPositiveAr['document_type']);
        $this->assertSame('100.000', $customerPositiveAr['total']);
        $this->assertSame('100.000', $customerPositiveAr['open_amount']);
        $this->assertSame('2026-01-31', $customerPositiveAr['document_date']);
        $this->assertSame('2026-01-31', $customerPositiveAr['due_date']);
        $this->assertSame('LEG-CUST', $customerPositiveAr['external_invoice_number']);
        $this->assertNull($customerPositive['ap']);

        $customerNegativeAr = $customerNegative['ar'];
        $this->assertIsArray($customerNegativeAr);
        $this->assertSame('credit_note', $customerNegativeAr['document_type']);
        $this->assertSame('100.000', $customerNegativeAr['total']);
        $this->assertSame('2026-01-01', $customerNegativeAr['document_date']);

        $supplierPositiveAp = $supplierPositive['ap'];
        $this->assertIsArray($supplierPositiveAp);
        $this->assertSame('invoice', $supplierPositiveAp['document_type']);
        $this->assertSame('100.000', $supplierPositiveAp['total']);
        $this->assertNull($supplierPositive['ar']);

        $supplierNegativeAp = $supplierNegative['ap'];
        $this->assertIsArray($supplierNegativeAp);
        $this->assertSame('credit_note', $supplierNegativeAp['document_type']);
        $this->assertSame('100.000', $supplierNegativeAp['total']);
    }

    public function test_zero_and_empty_balances_do_not_create_payloads(): void
    {
        foreach (['0', '0.000', '', null] as $value) {
            $payloads = $this->mapper->toBalancePayloads([
                'type' => 'customer',
                'opening_balance' => $value,
            ], '2026-01-01', 'CUST-001');

            $this->assertNull($payloads['ar']);
            $this->assertNull($payloads['ap']);
        }
    }

    public function test_both_uses_side_specific_balance_columns(): void
    {
        $payloads = $this->mapper->toBalancePayloads([
            'type' => 'both',
            'opening_balance_customer' => '70',
            'opening_balance_supplier' => '-40',
        ], '2026-01-01', 'BOTH-001');

        $ar = $payloads['ar'];
        $ap = $payloads['ap'];
        $this->assertIsArray($ar);
        $this->assertIsArray($ap);
        $this->assertSame('invoice', $ar['document_type']);
        $this->assertSame('70.000', $ar['total']);
        $this->assertSame('credit_note', $ap['document_type']);
        $this->assertSame('40.000', $ap['total']);
    }

    public function test_extra_validation_errors_reject_ambiguous_and_misused_balance_columns(): void
    {
        $this->assertSame([
            "For partners of type 'both', use opening_balance_customer / opening_balance_supplier instead of opening_balance.",
        ], $this->mapper->extraValidationErrors([
            'type' => 'both',
            'opening_balance' => '100',
        ]));

        $this->assertSame([
            'Customer rows must not use opening_balance_supplier.',
        ], $this->mapper->extraValidationErrors([
            'type' => 'customer',
            'opening_balance_supplier' => '100',
        ]));

        $this->assertSame([
            'Supplier rows must not use opening_balance_customer.',
        ], $this->mapper->extraValidationErrors([
            'type' => 'supplier',
            'opening_balance_customer' => '100',
        ]));
    }
}
