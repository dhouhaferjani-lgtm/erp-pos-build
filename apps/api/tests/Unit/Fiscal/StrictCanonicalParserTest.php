<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\DTOs\ParseResult;
use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Application\Services\StrictCanonicalParser;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use Tests\Helpers\Fiscal\GoldenFixtureBuilder;
use Tests\TestCase;

/**
 * Task 16 — `StrictCanonicalParser` (server PHP) — spec v7 §7.6.
 *
 * The parser is the server-side derivation of the structured `payload`
 * JSONB from already-hash-verified `canonical_bytes`. Per §7.6 it must
 * reject duplicate keys, out-of-grammar numbers, invalid Unicode, and
 * event-type schema violations. Per Task 14 round-2 carry-forward
 * (handoff §4.3) it also validates sub-array per-item shape that the
 * payload DTOs intentionally defer to the parser.
 */
final class StrictCanonicalParserTest extends TestCase
{
    private function parser(): StrictCanonicalParser
    {
        return new StrictCanonicalParser(
            new FiscalEventPayloadRegistry,
            new FiscalPayloadConstraintValidator,
        );
    }

    // -----------------------------------------------------------------
    // Happy path — each implemented event type
    // -----------------------------------------------------------------

    public function test_parses_valid_sale_receipt_envelope(): void
    {
        $bytes = $this->validSaleReceiptEnvelope();

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertNotNull($result->payload);
        // Pass 2A.PHP.2 — 28-key contract uses `currency_code` (not `currency`).
        $this->assertSame('TND', $result->payload['currency_code']);
        $this->assertSame(3, $result->payload['currency_scale']);
        $this->assertNull($result->failureReason);
    }

    public function test_parses_valid_chain_break_detected_envelope(): void
    {
        $bytes = $this->validChainBreakDetectedEnvelope();

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_BREAK_DETECTED);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertNotNull($result->payload);
        $this->assertSame('previous_hash_mismatch', $result->payload['reason']);
    }

    public function test_parses_valid_chain_restart_envelope(): void
    {
        $bytes = $this->validChainRestartEnvelope();

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_RESTART);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
    }

    public function test_parses_valid_terminal_registry_snapshot_envelope(): void
    {
        $bytes = $this->validTerminalRegistrySnapshotEnvelope();

        $result = $this->parser()->parse($bytes, FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
    }

    public function test_strict_parser_accepts_account_payment_canonical_envelope(): void
    {
        $bytes = $this->validAccountPaymentEnvelope();

        $result = $this->parser()->parse($bytes, FiscalEventType::ACCOUNT_PAYMENT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertNotNull($result->payload);
        $this->assertSame('ACCOUNT_PAYMENT', $result->payload['receipt_type_code']);
        $this->assertSame('synced', $result->payload['customer']['customer_sync_status']);
    }

    public function test_strict_parser_accepts_account_charge_canonical_envelope(): void
    {
        $bytes = $this->validAccountChargeEnvelope();

        $result = $this->parser()->parse($bytes, FiscalEventType::ACCOUNT_CHARGE);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertNotNull($result->payload);
        $this->assertSame('ACCOUNT_CHARGE', $result->payload['receipt_type_code']);
        $this->assertSame('b2c_charge_receipt', $result->payload['invoice_classification']);
        $this->assertSame('119.000', $result->payload['totals']['amount_charged_to_account']);
    }

    public function test_strict_parser_rejects_session_open_on_operational_chain_context(): void
    {
        $bytes = $this->envelope('SESSION_OPEN', $this->canonicalSessionOpenPayload());

        $result = $this->parser()->parse($bytes, FiscalEventType::SESSION_OPEN);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_chain_context_event_type_mismatch', $result->failureReason ?? '');
    }

    public function test_strict_parser_accepts_session_open_with_valid_shift_number(): void
    {
        $bytes = $this->envelope(
            'SESSION_OPEN',
            $this->canonicalSessionOpenPayload(),
            ['chain_context' => 'z_session'],
        );

        $result = $this->parser()->parse($bytes, FiscalEventType::SESSION_OPEN);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertNotNull($result->payload);
        $this->assertSame(7, $result->payload['shift_number']);
    }

    public function test_strict_parser_rejects_session_open_missing_shift_number(): void
    {
        $payload = $this->canonicalSessionOpenPayload();
        unset($payload['shift_number']);
        $bytes = $this->envelope('SESSION_OPEN', $payload, ['chain_context' => 'z_session']);

        $result = $this->parser()->parse($bytes, FiscalEventType::SESSION_OPEN);

        // Now that `shift_number` is in `SessionOpenPayload::PAYLOAD_KEYS`,
        // it is a REQUIRED canonical key. A payload omitting it is rejected
        // at the canonical-schema layer (`assertPresent` during DTO hydration)
        // with a missing-required-key violation before per-field validation.
        $this->assertFailed($result);
        $this->assertStringContainsString('missing required key', $result->failureReason ?? '');
        $this->assertStringContainsString('shift_number', $result->failureReason ?? '');
    }

    public function test_strict_parser_rejects_session_open_with_zero_shift_number(): void
    {
        $payload = $this->canonicalSessionOpenPayload();
        $payload['shift_number'] = 0;
        $bytes = $this->envelope('SESSION_OPEN', $payload, ['chain_context' => 'z_session']);

        $result = $this->parser()->parse($bytes, FiscalEventType::SESSION_OPEN);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_integer_format_mismatch', $result->failureReason ?? '');
        $this->assertStringContainsString('shift_number', $result->failureReason ?? '');
    }

    public function test_strict_parser_rejects_session_open_with_non_int_shift_number(): void
    {
        $payload = $this->canonicalSessionOpenPayload();
        $payload['shift_number'] = '7';
        $bytes = $this->envelope('SESSION_OPEN', $payload, ['chain_context' => 'z_session']);

        $result = $this->parser()->parse($bytes, FiscalEventType::SESSION_OPEN);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_integer_format_mismatch', $result->failureReason ?? '');
        $this->assertStringContainsString('shift_number', $result->failureReason ?? '');
    }

    public function test_strict_parser_accepts_z_session_cash_out_payload(): void
    {
        $bytes = $this->envelope(
            'CASH_OUT',
            $this->canonicalZCashDrawerMovementPayload('CASH_OUT'),
            ['chain_context' => 'z_session'],
        );

        $result = $this->parser()->parse($bytes, FiscalEventType::CASH_OUT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertNotNull($result->payload);
        $this->assertSame('CASH_OUT', $result->payload['movement_type']);
        $this->assertSame('session-1', $result->payload['reason_code']);
    }

    public function test_strict_parser_rejects_account_payment_extra_payload_key(): void
    {
        $payload = $this->canonicalAccountPaymentPayload();
        $payload['unexpected_extra'] = 'rogue';
        $bytes = $this->envelope('ACCOUNT_PAYMENT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::ACCOUNT_PAYMENT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_extra_field', $result->failureReason ?? '');
        $this->assertStringContainsString('unexpected_extra', $result->failureReason ?? '');
    }

    public function test_strict_parser_accepts_account_payment_populated_references_with_null_alias(): void
    {
        $payload = $this->canonicalAccountPaymentPayload();
        $payload['references'] = [
            'external_reference' => 'counter-payment-42',
            'related_sale_receipt_event_id' => '88888888-8888-4888-8888-888888888888',
            'server_customer_alias_id' => null,
        ];
        $bytes = $this->envelope('ACCOUNT_PAYMENT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::ACCOUNT_PAYMENT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertSame('counter-payment-42', $result->payload['references']['external_reference'] ?? null);
    }

    public function test_strict_parser_rejects_account_payment_non_null_server_customer_alias(): void
    {
        $payload = $this->canonicalAccountPaymentPayload();
        $payload['references'] = [
            'external_reference' => null,
            'related_sale_receipt_event_id' => null,
            'server_customer_alias_id' => '99999999-9999-4999-8999-999999999999',
        ];
        $bytes = $this->envelope('ACCOUNT_PAYMENT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::ACCOUNT_PAYMENT);

        $this->assertFailed($result);
        $this->assertStringContainsString('server_customer_alias_id', $result->failureReason ?? '');
    }

    // -----------------------------------------------------------------
    // Strict JSON: duplicate keys at every depth
    // -----------------------------------------------------------------

    public function test_rejects_duplicate_keys_at_envelope_level(): void
    {
        $bytes = '{"event_type":"SALE_RECEIPT","event_type":"X"}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('duplicate_key', $result->failureReason ?? '');
        $this->assertStringContainsString('event_type', $result->failureReason ?? '');
    }

    public function test_rejects_duplicate_keys_in_payload(): void
    {
        // Manually composed: payload object has `currency` twice.
        $payload = '{"currency":"TND","currency":"USD","currency_scale":3,'
            .'"discount_total":"0.000","lines":[],"payment_lines":[],'
            .'"subtotal":"0.000","tax_total":"0.000","total":"0.000",'
            .'"vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('duplicate_key', $result->failureReason ?? '');
        $this->assertStringContainsString('currency', $result->failureReason ?? '');
    }

    public function test_rejects_duplicate_keys_in_sub_array_item(): void
    {
        // lines[0] has `product_id` twice.
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[{"product_id":"p-1","product_id":"p-2","quantity":1,'
            .'"unit_price":"5.000","line_total":"5.000","vat_rate":"7"}],'
            .'"payment_lines":[],"subtotal":"5.000","tax_total":"0.000","total":"5.000",'
            .'"vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('duplicate_key', $result->failureReason ?? '');
        $this->assertStringContainsString('product_id', $result->failureReason ?? '');
    }

    // -----------------------------------------------------------------
    // Strict JSON: out-of-grammar numbers (only integers; money is string)
    // -----------------------------------------------------------------

    public function test_rejects_float_number(): void
    {
        $bytes = '{"event_type":"SALE_RECEIPT","sequence_number":1.5}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('out_of_grammar_number', $result->failureReason ?? '');
    }

    public function test_rejects_exponent_lowercase(): void
    {
        $bytes = '{"event_type":"SALE_RECEIPT","sequence_number":1e3}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('out_of_grammar_number', $result->failureReason ?? '');
    }

    public function test_rejects_exponent_uppercase(): void
    {
        $bytes = '{"event_type":"SALE_RECEIPT","sequence_number":1E3}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('out_of_grammar_number', $result->failureReason ?? '');
    }

    public function test_rejects_leading_zero_number(): void
    {
        // RFC 8259 forbids leading zeros; JCS canonical form is integer-only
        // with no leading-zero permission.
        $bytes = '{"event_type":"SALE_RECEIPT","sequence_number":0123}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('out_of_grammar_number', $result->failureReason ?? '');
    }

    public function test_rejects_plus_sign_number(): void
    {
        // RFC 8259 forbids '+' prefix on numbers.
        $bytes = '{"event_type":"SALE_RECEIPT","sequence_number":+1}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
    }

    // -----------------------------------------------------------------
    // Strict JSON: invalid UTF-8
    // -----------------------------------------------------------------

    public function test_rejects_invalid_utf8(): void
    {
        // \xC3\x28 is an invalid 2-byte UTF-8 sequence.
        $bytes = "{\"event_type\":\"\xC3\x28\"}";

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_utf8', $result->failureReason ?? '');
    }

    public function test_rejects_lone_continuation_byte(): void
    {
        // \x80 is a continuation byte that cannot start a sequence.
        $bytes = "{\"event_type\":\"\x80\"}";

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_utf8', $result->failureReason ?? '');
    }

    // -----------------------------------------------------------------
    // Event-type schema violations (payload DTO contract)
    // -----------------------------------------------------------------

    public function test_rejects_payload_missing_required_field(): void
    {
        // Payload is missing `subtotal` and other required keys; DTO::fromArray throws.
        $payload = '{"currency":"TND","currency_scale":3,"lines":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('schema_violation', $result->failureReason ?? '');
    }

    public function test_rejects_payload_wrong_field_type(): void
    {
        // currency_scale must be int; payload has a string.
        // Strict tokenizer accepts "3" as a string. DTO rejects.
        $payload = '{"currency":"TND","currency_scale":"3","discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"0.000","tax_total":"0.000",'
            .'"total":"0.000","vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('schema_violation', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_missing_payload_key(): void
    {
        // Round-2 (Codex F1): the envelope shape validator surfaces the
        // missing field by name. Multiple fields are missing here; the
        // failure reason lists them.
        $bytes = '{"event_type":"SALE_RECEIPT","sequence_number":1}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_field_missing', $result->failureReason ?? '');
        $this->assertStringContainsString('payload', $result->failureReason ?? '');
    }

    public function test_rejects_payload_not_object(): void
    {
        // Use the full 14-field envelope but with payload as a string.
        // Envelope shape validator's payload-type check fires before any
        // other anomaly.
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', '"not an object"');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_not_object', $result->failureReason ?? '');
    }

    public function test_rejects_event_type_mismatch(): void
    {
        // Caller asserts CHAIN_RESTART, envelope says SALE_RECEIPT.
        $bytes = $this->validSaleReceiptEnvelope();

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_RESTART);

        $this->assertFailed($result);
        $this->assertStringContainsString('event_type_mismatch', $result->failureReason ?? '');
        $this->assertStringContainsString('CHAIN_RESTART', $result->failureReason ?? '');
        $this->assertStringContainsString('SALE_RECEIPT', $result->failureReason ?? '');
    }

    public function test_rejects_unimplemented_event_type(): void
    {
        // COMPANY_DAY_CLOSURE_MANIFEST is reserved schema-only — registry throws.
        $bytes = '{"event_type":"COMPANY_DAY_CLOSURE_MANIFEST","payload":{}}';

        $result = $this->parser()->parse($bytes, FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST);

        $this->assertFailed($result);
        $this->assertStringContainsString('event_type_unimplemented', $result->failureReason ?? '');
        $this->assertStringContainsString('COMPANY_DAY_CLOSURE_MANIFEST', $result->failureReason ?? '');
    }

    public function test_rejects_missing_event_type(): void
    {
        $bytes = '{"payload":{}}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('event_type_mismatch', $result->failureReason ?? '');
    }

    // -----------------------------------------------------------------
    // Sub-array per-item shape (Task 14 P2-2 carry-forward).
    // The payload DTOs accept `array<string,mixed>` for sub-array slots
    // — the parser is the boundary that enforces per-item shape.
    // -----------------------------------------------------------------

    public function test_rejects_scalar_item_in_sub_array_lines(): void
    {
        // Pass 2A.PHP.2 — `line_items` is the 28-key list container.
        $payload = $this->canonicalSaleReceiptPayloadJson(['line_items' => [1, 2, 3]]);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
        $this->assertStringContainsString('line_items', $result->failureReason ?? '');
    }

    public function test_rejects_empty_object_item_in_sub_array_lines(): void
    {
        $payload = $this->canonicalSaleReceiptPayloadJson(['line_items' => [new \stdClass]]);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
    }

    public function test_rejects_integer_monetary_field_in_sub_array_line(): void
    {
        // unit_price as int (1) — must be a bcformat string per §4.
        $payload = $this->canonicalSaleReceiptPayloadJson([
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '5.000',
                'line_vat' => '0.000',
                'name' => 'Item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-1',
                'quantity' => '1.000',
                'sku' => 'SKU-X',
                'tax_category_code' => '',
                'unit_price' => 1, // integer — invalid per spec
                'vat_rate' => '7.00',
            ]],
        ]);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
        $this->assertStringContainsString('unit_price', $result->failureReason ?? '');
    }

    public function test_rejects_integer_monetary_field_in_payment_lines(): void
    {
        // amount as int (100) — must be a bcformat string per §4.
        $payload = $this->canonicalSaleReceiptPayloadJson([
            'payments' => [[
                'amount' => 100, // integer — invalid per spec
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
        ]);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
        $this->assertStringContainsString('amount', $result->failureReason ?? '');
    }

    public function test_rejects_scalar_item_in_terminals(): void
    {
        $payload = '{"terminals":[1,2],"snapshot_hash":"'.str_repeat('a', 64).'",'
            .'"prior_snapshot_link":null}';
        $bytes = $this->envelopeWithRawPayload('TERMINAL_REGISTRY_SNAPSHOT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
        $this->assertStringContainsString('terminals', $result->failureReason ?? '');
    }

    public function test_rejects_empty_object_for_chain_restart_provenance_link(): void
    {
        // provenance_link must be a non-empty associative object.
        $payload = '{"new_genesis_reference":"'.str_repeat('a', 64).'",'
            .'"last_good_anchor":{"sequence_number":1,"hash":"'.str_repeat('b', 64).'"},'
            .'"operator_authorization_evidence":{"user_id":"u-1"},'
            .'"provenance_link":[]}';
        $bytes = $this->envelopeWithRawPayload('CHAIN_RESTART', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_RESTART);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
        $this->assertStringContainsString('provenance_link', $result->failureReason ?? '');
    }

    public function test_accepts_empty_voucher_redemptions(): void
    {
        // Pass 2A.PHP.2 — the 28-key contract renames `voucher_redemptions`
        // to `vouchers_redeemed`; empty array is still the canonical
        // representation of "no vouchers redeemed".
        $bytes = $this->validSaleReceiptEnvelope();
        $this->assertStringContainsString('"vouchers_redeemed":[]', $bytes);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'expected ok; reason: '.($result->failureReason ?? '(none)'));
    }

    // -----------------------------------------------------------------
    // Misc strictness
    // -----------------------------------------------------------------

    public function test_rejects_trailing_bytes(): void
    {
        $bytes = $this->validSaleReceiptEnvelope().'{}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('trailing_bytes', $result->failureReason ?? '');
    }

    public function test_rejects_unterminated_string(): void
    {
        $bytes = '{"event_type":"SALE_RECEIPT';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
    }

    public function test_rejects_unbalanced_object(): void
    {
        $bytes = '{"event_type":"SALE_RECEIPT"';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
    }

    public function test_rejects_empty_bytes(): void
    {
        $result = $this->parser()->parse('', FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
    }

    public function test_returned_payload_does_not_include_envelope_fields(): void
    {
        // The parser returns only the `payload` sub-object — never the envelope.
        $bytes = $this->validSaleReceiptEnvelope();

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok);
        $this->assertNotNull($result->payload);
        $this->assertArrayNotHasKey('event_type', $result->payload);
        $this->assertArrayNotHasKey('sequence_number', $result->payload);
        $this->assertArrayNotHasKey('previous_hash', $result->payload);
    }

    public function test_failure_reason_is_machine_readable_kebab_or_snake_case_prefix(): void
    {
        // Every failure surface uses a single machine-readable prefix so the
        // ingestor can route on `prefix === 'duplicate_key'` etc. The prefix
        // is a stable identifier; the trailer is free-form context.
        $bytes = '{"a":1,"a":2}';
        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $prefix = strstr($result->failureReason ?? '', ':', true);
        $this->assertNotFalse($prefix);
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]+$/', (string) $prefix);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function assertFailed(ParseResult $result): void
    {
        $this->assertFalse($result->ok);
        $this->assertNull($result->payload);
        $this->assertNotNull($result->failureReason);
        $this->assertNotSame('', $result->failureReason);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $envelopeOverrides
     */
    private function envelope(string $eventType, array $payload, array $envelopeOverrides = []): string
    {
        $fields = array_merge([
            'business_date' => '2026-05-16',
            'chain_context' => 'operational',
            'company_id' => 'co-1',
            'event_time_device' => '2026-05-16T12:00:00Z',
            'event_type' => $eventType,
            'event_version' => 1,
            'operator_id' => 'op-1',
            'payload' => $payload,
            'previous_hash' => str_repeat('0', 64),
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => 'tn-1',
            'terminal_id' => 'tm-1',
        ], $envelopeOverrides);
        ksort($fields);

        // json_encode preserves PHP array insertion order; ksort gives us
        // canonical lexicographic key order at this level. Sub-objects in
        // the payload are written by the helpers below in their own order;
        // the parser does NOT enforce sort order (that's the producer's
        // contract upstream), so this is sufficient.
        return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function envelopeWithRawPayload(string $eventType, string $rawPayloadJson): string
    {
        // For negative tests that need exact control of the payload bytes
        // (duplicate keys, etc. — things json_encode can't produce).
        // We wrap the literal payload JSON into a 14-field envelope by
        // first emitting a placeholder envelope and replacing the payload
        // value with the raw JSON.
        $placeholder = $this->envelope($eventType, ['__placeholder__' => 1]);
        $needle = '"payload":'.json_encode(['__placeholder__' => 1]);
        $this->assertStringContainsString($needle, $placeholder);

        return str_replace($needle, '"payload":'.$rawPayloadJson, $placeholder);
    }

    /**
     * Build a full 14-field envelope and replace one envelope-level field's
     * value with a raw JSON literal. **Scalars only** (string, int, null,
     * bool) — the regex's stop-class is `[,}]`, so the helper does not
     * cope with object/list values. Use `envelopeWithRawPayload` to swap
     * in a composite payload value.
     */
    private function envelopeWithRawValue(string $eventType, string $key, string $rawJsonValue): string
    {
        $base = $this->validEnvelopeFor($eventType);
        // Each envelope field has a distinct "key":value substring because
        // json_encode emits keys in insertion (ksort'd) order; we replace
        // the FIRST occurrence which is the envelope-level one (sub-array
        // items with the same key name come later).
        $regex = '/"'.preg_quote($key, '/').'":[^,}]+(?=[,}])/';
        $count = 0;
        $out = preg_replace($regex, '"'.$key.'":'.$rawJsonValue, $base, 1, $count);
        $this->assertSame(1, $count, "could not find envelope key '{$key}' to replace");

        return (string) $out;
    }

    private function validEnvelopeFor(string $eventType): string
    {
        return match ($eventType) {
            'SALE_RECEIPT' => $this->validSaleReceiptEnvelope(),
            'CHAIN_BREAK_DETECTED' => $this->validChainBreakDetectedEnvelope(),
            'CHAIN_RESTART' => $this->validChainRestartEnvelope(),
            'TERMINAL_REGISTRY_SNAPSHOT' => $this->validTerminalRegistrySnapshotEnvelope(),
            'ACCOUNT_PAYMENT' => $this->validAccountPaymentEnvelope(),
            'ACCOUNT_CHARGE' => $this->validAccountChargeEnvelope(),
            default => throw new \LogicException('unsupported event type for helper: '.$eventType),
        };
    }

    private function validSaleReceiptEnvelope(): string
    {
        // Pass 2A.PHP.2 — emit the 28-key Candidate C-v3 SALE_RECEIPT shape
        // per synthesis v5 §3. Constant UUIDs + TND currency_scale=3 mirror
        // the GoldenFixtureBuilder convention but stay independent so this
        // unit test can run without the Fixture helper.
        return $this->envelope('SALE_RECEIPT', $this->canonicalSaleReceiptPayload());
    }

    private function validAccountPaymentEnvelope(): string
    {
        return $this->envelope('ACCOUNT_PAYMENT', $this->canonicalAccountPaymentPayload());
    }

    private function validAccountChargeEnvelope(): string
    {
        return $this->envelope('ACCOUNT_CHARGE', $this->canonicalAccountChargePayload());
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalSessionOpenPayload(bool $trainingFlag = false): array
    {
        return [
            'business_date' => '2026-05-16',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'opened_at_device' => '2026-05-16T08:00:00.000Z',
            'opening_float_amount' => '100.000',
            'operator_id' => '11111111-1111-4111-8111-111111111111',
            'operator_name' => 'Default Cashier',
            'session_id' => '77777777-7777-4777-8777-777777777777',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'shift_number' => 7,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'terminal_label' => 'T01',
            'training_flag' => $trainingFlag,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalZCashDrawerMovementPayload(string $movementType, bool $trainingFlag = false): array
    {
        return [
            'amount' => '25.000',
            'approval' => null,
            'business_date' => '2026-05-16',
            'cash_drawer_operation_id' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-05-16T08:15:00.000Z',
            'movement_id' => '88888888-8888-4888-8888-888888888888',
            'movement_type' => $movementType,
            'operator_id' => '11111111-1111-4111-8111-111111111111',
            'operator_name' => 'Default Cashier',
            'reason_code' => 'session-1',
            'reason_text' => null,
            'session_id' => '77777777-7777-4777-8777-777777777777',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'training_flag' => $trainingFlag,
        ];
    }

    /**
     * Build a 28-key SALE_RECEIPT payload JSON string with the given
     * overrides applied at the top level. Used by negative tests that need
     * to inject a malformed sub-array shape while keeping the rest of the
     * payload valid against the 28-key contract.
     *
     * @param  array<string, mixed>  $overrides
     */
    /**
     * SaleReceiptV2 (M4) payload: the V1 shape with the variant identity
     * keys on each line — here a variant sale of the default item.
     *
     * @return array<string, mixed>
     */
    private function canonicalSaleReceiptV2Payload(): array
    {
        $payload = $this->canonicalSaleReceiptPayload();
        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        $lines[0]['variant_id'] = '44444444-4444-4444-8444-444444444444';
        $lines[0]['variant_name'] = 'Default item — Red / L';
        $lines[0]['variant_sku'] = 'SKU-DEFAULT-RED-L';
        ksort($lines[0]);
        $payload['line_items'] = $lines;

        return $payload;
    }

    /**
     * SaleReceiptV3 (cash rounding, spec §4.4): the V2 shape plus the two
     * signed rounding siblings, with a REAL non-zero adjustment.
     *
     * exact_total 5.353 (net 5.003 + vat 0.350) → D 0.050 → rounded 5.350,
     * adjustment -0.003. `total` is an exact multiple of D and the folded
     * identity subtotal + vat == (total − adj) + discount holds.
     *
     * @return array<string, mixed>
     */
    private function canonicalSaleReceiptV3Payload(): array
    {
        $payload = $this->canonicalSaleReceiptV2Payload();
        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        $lines[0]['line_subtotal'] = '5.003';
        $lines[0]['unit_price'] = '5.003';
        $payload['line_items'] = $lines;

        $payload['subtotal'] = '5.003';
        $payload['vat_breakdown'] = [[
            'gross_amount' => '5.353',
            'net_amount' => '5.003',
            'rate' => '7.00',
            'tax_category_code' => '',
            'vat_amount' => '0.350',
        ]];
        $payload['cash_rounding_adjustment'] = '-0.003';
        $payload['cash_rounding_denomination'] = '0.050';
        ksort($payload);

        return $payload;
    }

    private function canonicalSaleReceiptPayloadJson(array $overrides = []): string
    {
        $payload = array_replace($this->canonicalSaleReceiptPayload(), $overrides);

        return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Canonical 28-key SALE_RECEIPT payload used by the parser unit tests.
     * TND (currency_scale=3) to preserve the pre-PHP.2 fixture's currency
     * assertion in `test_parses_valid_sale_receipt_envelope`.
     *
     * @return array<string, mixed>
     */
    private function canonicalSaleReceiptPayload(): array
    {
        return [
            'approval_references' => [],
            'business_date' => '2026-05-20',
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '5.000',
                'line_vat' => '0.350',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                'unit_price' => '5.000',
                'vat_rate' => '7.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '5.350',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '5.000',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '5.350',
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '5.350',
                'net_amount' => '5.000',
                'rate' => '7.00',
                'tax_category_code' => '',
                'vat_amount' => '0.350',
            ]],
            'vat_total' => '0.350',
            'vouchers_redeemed' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalAccountChargePayload(): array
    {
        return [
            'account_charge_uuid' => '66666666-6666-4666-8666-666666666666',
            'business_date' => '2026-05-21',
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'charge_terms' => ['due_date' => '2026-06-20', 'payment_terms_days' => 30, 'terms_label' => 'Net 30'],
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
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalAccountPaymentPayload(): array
    {
        return [
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
    }

    private function validChainBreakDetectedEnvelope(): string
    {
        return $this->envelope('CHAIN_BREAK_DETECTED', [
            'last_good_hash' => str_repeat('a', 64),
            'last_good_sequence' => 42,
            'offending_record_reference' => ['observed_previous_hash' => str_repeat('b', 64), 'sequence_number' => 43, 'terminal_id' => 'tm-1'],
            'reason' => 'previous_hash_mismatch',
        ]);
    }

    private function validChainRestartEnvelope(): string
    {
        return $this->envelope('CHAIN_RESTART', [
            'last_good_anchor' => ['hash' => str_repeat('c', 64), 'sequence_number' => 42],
            'new_genesis_reference' => str_repeat('d', 64),
            'operator_authorization_evidence' => ['reason_code' => 'CHAIN_RESTART', 'role' => 'manager', 'user_id' => 'u-1'],
            'provenance_link' => ['chain_break_event_id' => 'evt-abc'],
        ]);
    }

    private function validTerminalRegistrySnapshotEnvelope(): string
    {
        return $this->envelope('TERMINAL_REGISTRY_SNAPSHOT', [
            'prior_snapshot_link' => null,
            'snapshot_hash' => str_repeat('e', 64),
            'terminals' => [
                ['is_active' => true, 'terminal_code' => 'T01', 'terminal_id' => 'tm-1'],
                ['is_active' => false, 'terminal_code' => 'T02', 'terminal_id' => 'tm-2'],
            ],
        ]);
    }

    // =================================================================
    // Round-2 — Codex BLOCKER + P1 + P2 closures, Opus P2-1/P2-2/P2-3/P3-1.
    // =================================================================

    // ---- BLOCKER F1: 14-field envelope shape (Codex round-2) --------

    public function test_rejects_envelope_with_missing_business_date(): void
    {
        // Strip the business_date key from a valid envelope.
        $base = $this->validSaleReceiptEnvelope();
        $bytes = (string) preg_replace('/"business_date":"[^"]*",/', '', $base, 1);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_field_missing', $result->failureReason ?? '');
        $this->assertStringContainsString('business_date', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_with_extra_field(): void
    {
        // Append a fifteenth key to the envelope.
        $base = $this->validSaleReceiptEnvelope();
        $bytes = str_replace('"terminal_id":"tm-1"', '"terminal_id":"tm-1","unexpected_field":"x"', $base);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_extra_field', $result->failureReason ?? '');
        $this->assertStringContainsString('unexpected_field', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_with_string_event_version(): void
    {
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'event_version', '"1"');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_event_version_invalid', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_with_event_version_mismatch_to_registry(): void
    {
        // SALE_RECEIPT supports event_version {1, 2, 3} since the cash-rounding
        // v3 contract (spec §4.4); an unknown version 4 must reject.
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'event_version', '4');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_event_version_mismatch', $result->failureReason ?? '');
    }

    // ─── SaleReceiptV2 (M4) — event_version=2 variant line fidelity ─────────

    public function test_parses_valid_sale_receipt_v2_envelope_with_variant_line(): void
    {
        $bytes = $this->envelope(
            'SALE_RECEIPT',
            $this->canonicalSaleReceiptV2Payload(),
            ['event_version' => 2],
        );

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        /** @var array<string, mixed> $payload */
        $payload = $result->payload;
        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        $this->assertSame('44444444-4444-4444-8444-444444444444', $lines[0]['variant_id']);
        $this->assertSame('SKU-DEFAULT-RED-L', $lines[0]['variant_sku']);
        $this->assertSame('Default item — Red / L', $lines[0]['variant_name']);
    }

    public function test_parses_v2_envelope_with_null_variant_fields_on_non_variant_line(): void
    {
        $payload = $this->canonicalSaleReceiptV2Payload();
        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        $lines[0]['variant_id'] = null;
        $lines[0]['variant_name'] = null;
        $lines[0]['variant_sku'] = null;
        $payload['line_items'] = $lines;

        $bytes = $this->envelope('SALE_RECEIPT', $payload, ['event_version' => 2]);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
    }

    public function test_rejects_v2_sale_receipt_with_v1_shaped_line_items(): void
    {
        // A v2 envelope must carry the full V2 line shape — the variant keys
        // are required (explicit null for non-variant lines), never absent.
        $bytes = $this->envelope(
            'SALE_RECEIPT',
            $this->canonicalSaleReceiptPayload(),
            ['event_version' => 2],
        );

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_line_item_missing_keys', $result->failureReason ?? '');
        $this->assertStringContainsString('variant_id', $result->failureReason ?? '');
    }

    public function test_rejects_v1_sale_receipt_with_variant_keys(): void
    {
        // V1 events are immutable forever — variant keys on a version-1
        // envelope are foreign keys to that shape and must reject.
        $bytes = $this->envelope('SALE_RECEIPT', $this->canonicalSaleReceiptV2Payload());

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_line_item_extra_keys', $result->failureReason ?? '');
    }

    public function test_rejects_v2_variant_identity_without_variant_id(): void
    {
        $payload = $this->canonicalSaleReceiptV2Payload();
        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        $lines[0]['variant_id'] = null;
        $lines[0]['variant_name'] = null;
        $payload['line_items'] = $lines;

        $bytes = $this->envelope('SALE_RECEIPT', $payload, ['event_version' => 2]);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_line_item_variant_orphan', $result->failureReason ?? '');
    }

    public function test_rejects_v2_variant_id_with_invalid_uuid_format(): void
    {
        $payload = $this->canonicalSaleReceiptV2Payload();
        /** @var list<array<string, mixed>> $lines */
        $lines = $payload['line_items'];
        $lines[0]['variant_id'] = 'NOT-A-UUID';
        $payload['line_items'] = $lines;

        $bytes = $this->envelope('SALE_RECEIPT', $payload, ['event_version' => 2]);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_line_item_variant_id_invalid', $result->failureReason ?? '');
    }

    // ─── SaleReceiptV3 — event_version=3 cash-rounding key set ──────────────

    public function test_accepts_v3_sale_receipt_envelope_with_cash_rounding_keys(): void
    {
        // The version threading at StrictCanonicalParser's validatePayloadKeySet
        // call site is what admits these two keys; without it the whole
        // envelope quarantines as payload_extra_field.
        $bytes = $this->envelope(
            'SALE_RECEIPT',
            $this->canonicalSaleReceiptV3Payload(),
            ['event_version' => 3],
        );

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        /** @var array<string, mixed> $payload */
        $payload = $result->payload;
        $this->assertCount(30, $payload);
        $this->assertSame('-0.003', $payload['cash_rounding_adjustment']);
        $this->assertSame('0.050', $payload['cash_rounding_denomination']);
        $this->assertSame('5.350', $payload['total']);
    }

    public function test_rejects_v2_sale_receipt_carrying_the_cash_rounding_keys(): void
    {
        // Negative twin: the SAME payload on a version-2 envelope. v1/v2
        // events are immutable forever — the rounding keys are foreign to
        // that shape and must reject at the key-set gate.
        $bytes = $this->envelope(
            'SALE_RECEIPT',
            $this->canonicalSaleReceiptV3Payload(),
            ['event_version' => 2],
        );

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_extra_field', $result->failureReason ?? '');
        $this->assertStringContainsString('cash_rounding_adjustment', $result->failureReason ?? '');
        $this->assertStringContainsString('cash_rounding_denomination', $result->failureReason ?? '');
    }

    public function test_rejects_v3_sale_receipt_missing_the_cash_rounding_keys(): void
    {
        // A v2-shaped payload on a version-3 envelope: the two siblings are
        // REQUIRED-always on v3 (canonical zero when no rounding applied).
        $bytes = $this->envelope(
            'SALE_RECEIPT',
            $this->canonicalSaleReceiptV2Payload(),
            ['event_version' => 3],
        );

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_missing_required', $result->failureReason ?? '');
        $this->assertStringContainsString('cash_rounding_denomination', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_with_zero_sequence_number(): void
    {
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'sequence_number', '0');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_sequence_number_invalid', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_previous_hash_not_64_lowercase_hex(): void
    {
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'previous_hash', '"NOT_HEX"');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_previous_hash_invalid', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_previous_hash_uppercase_hex(): void
    {
        // Spec §4 mandates lowercase hex.
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'previous_hash', '"'.str_repeat('A', 64).'"');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_previous_hash_invalid', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_event_time_device_without_z_suffix(): void
    {
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'event_time_device', '"2026-05-16T12:00:00"');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_event_time_device_invalid', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_event_time_device_with_fractional_seconds(): void
    {
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'event_time_device', '"2026-05-16T12:00:00.123Z"');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_event_time_device_invalid', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_business_date_with_time_component(): void
    {
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'business_date', '"2026-05-16T00:00:00Z"');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_business_date_invalid', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_empty_tenant_id(): void
    {
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'tenant_id', '""');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_tenant_id_invalid', $result->failureReason ?? '');
    }

    public function test_rejects_envelope_reference_document_id_as_integer(): void
    {
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'reference_document_id', '123');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_reference_document_id_invalid', $result->failureReason ?? '');
    }

    public function test_accepts_envelope_with_null_reference_document_id(): void
    {
        // The default envelope already sets reference_document_id to null —
        // this test pins that the validator accepts the canonical nullable.
        $bytes = $this->validSaleReceiptEnvelope();
        $this->assertStringContainsString('"reference_document_id":null', $bytes);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
    }

    public function test_accepts_envelope_with_non_null_reference_document_id(): void
    {
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'reference_document_id', '"doc-42"');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
    }

    public function test_rejects_envelope_payload_as_list(): void
    {
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', '[1,2,3]');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_not_object', $result->failureReason ?? '');
        $this->assertStringContainsString('list', $result->failureReason ?? '');
    }

    // ---- BLOCKER F2: payload extras (Codex round-2) -----------------

    public function test_rejects_payload_extra_field_sale_receipt(): void
    {
        $payload = $this->canonicalSaleReceiptPayloadJson(['extra_untyped' => 'X']);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_extra_field', $result->failureReason ?? '');
        $this->assertStringContainsString('extra_untyped', $result->failureReason ?? '');
    }

    public function test_rejects_payload_extra_field_chain_break(): void
    {
        $payload = '{"last_good_hash":"'.str_repeat('a', 64).'","last_good_sequence":1,'
            .'"offending_record_reference":{"observed_previous_hash":"'.str_repeat('b', 64).'"},'
            .'"reason":"r","unexpected":"x"}';
        $bytes = $this->envelopeWithRawPayload('CHAIN_BREAK_DETECTED', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_BREAK_DETECTED);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_extra_field', $result->failureReason ?? '');
    }

    public function test_rejects_payload_extra_field_chain_restart(): void
    {
        $payload = '{"last_good_anchor":{"hash":"'.str_repeat('c', 64).'","sequence_number":1},'
            .'"new_genesis_reference":"'.str_repeat('d', 64).'",'
            .'"operator_authorization_evidence":{"user_id":"u-1"},'
            .'"provenance_link":{"chain_break_event_id":"evt-x"},'
            .'"sneaky":"x"}';
        $bytes = $this->envelopeWithRawPayload('CHAIN_RESTART', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_RESTART);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_extra_field', $result->failureReason ?? '');
    }

    public function test_rejects_payload_extra_field_terminal_registry_snapshot(): void
    {
        $payload = '{"prior_snapshot_link":null,"snapshot_hash":"'.str_repeat('e', 64).'",'
            .'"terminals":[{"terminal_id":"tm-1","is_active":true}],"unexpected":"x"}';
        $bytes = $this->envelopeWithRawPayload('TERMINAL_REGISTRY_SNAPSHOT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT);

        $this->assertFailed($result);
        $this->assertStringContainsString('payload_extra_field', $result->failureReason ?? '');
    }

    // ---- BLOCKER F3: whitespace rejected (Codex round-2) ------------

    public function test_rejects_whitespace_around_envelope_keys(): void
    {
        // Spec §4 mandates RFC 8785/JCS canonical bytes — no whitespace.
        $bytes = '{ "event_type":"SALE_RECEIPT","payload":{}}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('unexpected_character', $result->failureReason ?? '');
    }

    public function test_rejects_whitespace_after_colon(): void
    {
        $bytes = '{"event_type": "SALE_RECEIPT"}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('unexpected_character', $result->failureReason ?? '');
    }

    public function test_rejects_newline_between_fields(): void
    {
        $bytes = "{\"event_type\":\"SALE_RECEIPT\",\n\"payload\":{}}";

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('unexpected_character', $result->failureReason ?? '');
    }

    // ---- BLOCKER F4: scale-aware money format (Codex round-2) -------

    public function test_rejects_top_level_total_not_in_bcformat(): void
    {
        $payload = $this->canonicalSaleReceiptPayloadJson(['total' => 'not-money']);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
        $this->assertStringContainsString('total', $result->failureReason ?? '');
    }

    public function test_rejects_money_with_wrong_fraction_length(): void
    {
        // currency_scale=3 but money has 2 decimals (and update arithmetic
        // so the total-arithmetic check doesn't fire first).
        $payload = $this->canonicalSaleReceiptPayloadJson([
            'subtotal' => '5.00', // <-- scale-2 string in a scale-3 payload
            // Re-equilibrate so the partition rule check passes the scale
            // gate before — the per-field scale invariant fires first.
        ]);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        // The validator emits `payload_money_scale_mismatch` for scale drift;
        // the parser wraps it as `sub_array_shape:payload_money_scale_mismatch:...`.
        $this->assertStringContainsString('scale_mismatch', $result->failureReason ?? '');
    }

    public function test_accepts_scale_0_money(): void
    {
        // JPY-style 0-decimal currency. Re-emit a fully valid scale-0 payload
        // (we can't just override `currency_scale` because every money field
        // must match the scale at the boundary).
        $payload = $this->canonicalSaleReceiptPayloadJson([
            'currency_code' => 'JPY',
            'currency_scale' => 0,
            'subtotal' => '5',
            'vat_total' => '0',
            'total' => '5',
            'transaction_discount_amount' => '0',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0',
                'line_discount_reason' => null,
                'line_subtotal' => '5',
                'line_vat' => '0',
                'name' => 'Item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-1',
                'quantity' => '1.000',
                'sku' => 'SKU-J',
                'tax_category_code' => '',
                'unit_price' => '5',
                'vat_rate' => '0.00',
            ]],
            'payments' => [[
                'amount' => '5',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'vat_breakdown' => [[
                'gross_amount' => '5',
                'net_amount' => '5',
                'rate' => '0.00',
                'tax_category_code' => '',
                'vat_amount' => '0',
            ]],
        ]);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
    }

    public function test_accepts_negative_money(): void
    {
        // Pass 2A.PHP.2 — synthesis v5 §6 says EVERY money field is NON-NEGATIVE.
        // Refunds are modeled via invoice_type_code='REFUND' + non-null
        // original_receipt_reference. Negative payload money is now REJECTED
        // (the test contract changes: the OLD payload allowed negatives, the
        // 28-key payload does not). Keep the test as a regression guard for
        // the new contract — assert that a negative subtotal is rejected.
        $payload = $this->canonicalSaleReceiptPayloadJson([
            'subtotal' => '-5.000',
            'vat_total' => '-0.350',
            'total' => '-5.350',
        ]);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        // Negative money is now rejected per the 28-key contract.
        $this->assertFailed($result);
        $this->assertStringContainsString('money', $result->failureReason ?? '');
    }

    public function test_rejects_money_with_leading_plus(): void
    {
        $payload = $this->canonicalSaleReceiptPayloadJson(['subtotal' => '+5.000']);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        // The validator's money regex doesn't allow leading + — surfaces
        // as `payload_money_scale_mismatch` or generic shape error.
        $this->assertStringContainsString('subtotal', $result->failureReason ?? '');
    }

    // ---- P1 F5: list-vs-assoc on containers (Codex round-2) ---------

    public function test_rejects_assoc_object_for_lines_container(): void
    {
        // line_items must be a JSON list, not an object.
        // We construct the payload mostly from helpers then patch line_items
        // to be an object via direct JSON manipulation.
        $payload = $this->canonicalSaleReceiptPayloadJson();
        // Replace the line_items array literal with an object literal.
        $newLines = '{"k":{"gtin":null,"line_discount_amount":"0.000","line_discount_reason":null,"line_subtotal":"5.000","line_vat":"0.350","name":"X","non_collected_subtype":null,"product_id":"p1","quantity":"1.000","sku":"X","tax_category_code":"","unit_price":"5.000","vat_rate":"7.00"}}';
        $payload = (string) preg_replace('/"line_items":\[[^\]]*\]/', '"line_items":'.$newLines, $payload, 1);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('JSON list', $result->failureReason ?? '');
    }

    // ---- P1 F6 / Opus P1-1: hex hash format on payload fields -------

    public function test_rejects_chain_break_last_good_hash_not_64_lowercase_hex(): void
    {
        $payload = '{"last_good_hash":"NOT_HEX","last_good_sequence":1,'
            .'"offending_record_reference":{"observed_previous_hash":"'.str_repeat('b', 64).'"},'
            .'"reason":"r"}';
        $bytes = $this->envelopeWithRawPayload('CHAIN_BREAK_DETECTED', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_BREAK_DETECTED);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_hash_format', $result->failureReason ?? '');
        $this->assertStringContainsString('last_good_hash', $result->failureReason ?? '');
    }

    public function test_rejects_chain_break_observed_previous_hash_uppercase(): void
    {
        $payload = '{"last_good_hash":"'.str_repeat('a', 64).'","last_good_sequence":1,'
            .'"offending_record_reference":{"observed_previous_hash":"'.str_repeat('B', 64).'"},'
            .'"reason":"r"}';
        $bytes = $this->envelopeWithRawPayload('CHAIN_BREAK_DETECTED', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_BREAK_DETECTED);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_hash_format', $result->failureReason ?? '');
        $this->assertStringContainsString('observed_previous_hash', $result->failureReason ?? '');
    }

    public function test_rejects_chain_restart_new_genesis_reference_short(): void
    {
        $payload = '{"last_good_anchor":{"hash":"'.str_repeat('c', 64).'","sequence_number":1},'
            .'"new_genesis_reference":"abc",'
            .'"operator_authorization_evidence":{"user_id":"u-1"},'
            .'"provenance_link":{"chain_break_event_id":"evt-x"}}';
        $bytes = $this->envelopeWithRawPayload('CHAIN_RESTART', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_RESTART);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_hash_format', $result->failureReason ?? '');
        $this->assertStringContainsString('new_genesis_reference', $result->failureReason ?? '');
    }

    public function test_rejects_chain_restart_last_good_anchor_hash_bad(): void
    {
        $payload = '{"last_good_anchor":{"hash":"INVALID","sequence_number":1},'
            .'"new_genesis_reference":"'.str_repeat('d', 64).'",'
            .'"operator_authorization_evidence":{"user_id":"u-1"},'
            .'"provenance_link":{"chain_break_event_id":"evt-x"}}';
        $bytes = $this->envelopeWithRawPayload('CHAIN_RESTART', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_RESTART);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_hash_format', $result->failureReason ?? '');
        $this->assertStringContainsString('last_good_anchor.hash', $result->failureReason ?? '');
    }

    public function test_rejects_terminal_registry_snapshot_hash_bad(): void
    {
        $payload = '{"prior_snapshot_link":null,"snapshot_hash":"bad",'
            .'"terminals":[{"terminal_id":"tm-1","is_active":true}]}';
        $bytes = $this->envelopeWithRawPayload('TERMINAL_REGISTRY_SNAPSHOT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_hash_format', $result->failureReason ?? '');
        $this->assertStringContainsString('snapshot_hash', $result->failureReason ?? '');
    }

    public function test_rejects_terminal_registry_prior_snapshot_link_bad_when_non_null(): void
    {
        $payload = '{"prior_snapshot_link":"NOT_HEX","snapshot_hash":"'.str_repeat('e', 64).'",'
            .'"terminals":[{"terminal_id":"tm-1","is_active":true}]}';
        $bytes = $this->envelopeWithRawPayload('TERMINAL_REGISTRY_SNAPSHOT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_hash_format', $result->failureReason ?? '');
        $this->assertStringContainsString('prior_snapshot_link', $result->failureReason ?? '');
    }

    // ---- P2 F7: U+2028 / U+2029 rejection (Codex round-2) -----------

    public function test_rejects_u2028_line_separator_in_string_raw_bytes(): void
    {
        // Raw UTF-8 bytes E2 80 A8 inside a payload string.
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"0.000","tax_total":"0.000",'
            ."\"total\":\"0.000\",\"vat_breakdown\":[],\"voucher_redemptions\":[\"\xE2\x80\xA8\"]}";
        // The above is structurally wrong (voucher_redemptions has scalar items)
        // — but the U+2028 byte triggers the canonical-string rejection BEFORE
        // sub-array shape sees the scalars, so the strict-Unicode check is the
        // active failure.
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_unicode', $result->failureReason ?? '');
    }

    public function test_rejects_u2029_paragraph_separator_via_unicode_escape(): void
    {
        // (U+2029) is a paragraph separator — JCS canonical form strips these.
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"\u2029","tax_total":"0.000",'
            .'"total":"0.000","vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_unicode', $result->failureReason ?? '');
    }

    // ---- Opus P2-1: surrogate-pair branch coverage ------------------

    public function test_parses_basic_unicode_escape(): void
    {
        // é == é. Round-trip via vouchers_redeemed[0].voucher_code per 28-key contract.
        $payload = $this->canonicalSaleReceiptPayloadJson([
            'vouchers_redeemed' => [['redeemed_amount' => '0.000', 'voucher_code' => 'café']],
        ]);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertSame('café', $this->voucherCodeAt($result, 0));
    }

    public function test_parses_valid_surrogate_pair_grinning_face(): void
    {
        // 😀 == U+1F600 (😀).
        $payload = $this->canonicalSaleReceiptPayloadJson([
            'vouchers_redeemed' => [['redeemed_amount' => '0.000', 'voucher_code' => '😀']],
        ]);
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertSame("\xF0\x9F\x98\x80", $this->voucherCodeAt($result, 0));
    }

    /**
     * Helper to drill into payload.vouchers_redeemed[$index].voucher_code
     * with proper narrowing — PHPStan can't see through chained array
     * access on `array<string, mixed>|null`.
     */
    private function voucherCodeAt(ParseResult $result, int $index): mixed
    {
        $payload = $result->payload;
        $this->assertIsArray($payload);
        $vouchers = $payload['vouchers_redeemed'] ?? null;
        $this->assertIsArray($vouchers);
        $this->assertArrayHasKey($index, $vouchers);
        $item = $vouchers[$index];
        $this->assertIsArray($item);

        return $item['voucher_code'] ?? null;
    }

    public function test_rejects_lone_high_surrogate(): void
    {
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"0.000","tax_total":"0.000",'
            .'"total":"0.000","vat_breakdown":[],"voucher_redemptions":[{"code":"\ud83d","amount":"0.000"}]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_unicode_escape', $result->failureReason ?? '');
        $this->assertStringContainsString('high surrogate', $result->failureReason ?? '');
    }

    public function test_rejects_lone_low_surrogate(): void
    {
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"0.000","tax_total":"0.000",'
            .'"total":"0.000","vat_breakdown":[],"voucher_redemptions":[{"code":"\ude00","amount":"0.000"}]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_unicode_escape', $result->failureReason ?? '');
        $this->assertStringContainsString('low surrogate', $result->failureReason ?? '');
    }

    public function test_rejects_high_surrogate_followed_by_non_low_surrogate(): void
    {
        // \ud83d followed by A (a BMP codepoint, not a low surrogate).
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"0.000","tax_total":"0.000",'
            .'"total":"0.000","vat_breakdown":[],"voucher_redemptions":[{"code":"\ud83dA","amount":"0.000"}]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('invalid_unicode_escape', $result->failureReason ?? '');
    }

    // ---- Opus P2-2: per-non-SALE_RECEIPT-type coverage --------------

    public function test_rejects_chain_break_payload_missing_required_field(): void
    {
        $payload = '{"reason":"r"}'; // missing last_good_hash, etc.
        $bytes = $this->envelopeWithRawPayload('CHAIN_BREAK_DETECTED', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_BREAK_DETECTED);

        $this->assertFailed($result);
        $this->assertStringContainsString('schema_violation', $result->failureReason ?? '');
    }

    public function test_rejects_chain_restart_payload_missing_required_field(): void
    {
        $payload = '{"new_genesis_reference":"'.str_repeat('d', 64).'"}';
        $bytes = $this->envelopeWithRawPayload('CHAIN_RESTART', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_RESTART);

        $this->assertFailed($result);
        $this->assertStringContainsString('schema_violation', $result->failureReason ?? '');
    }

    public function test_rejects_terminal_snapshot_payload_missing_required_field(): void
    {
        $payload = '{"snapshot_hash":"'.str_repeat('e', 64).'"}';
        $bytes = $this->envelopeWithRawPayload('TERMINAL_REGISTRY_SNAPSHOT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT);

        $this->assertFailed($result);
        $this->assertStringContainsString('schema_violation', $result->failureReason ?? '');
    }

    public function test_returned_payload_carries_chain_restart_typed_fields(): void
    {
        $bytes = $this->validChainRestartEnvelope();

        $result = $this->parser()->parse($bytes, FiscalEventType::CHAIN_RESTART);

        $this->assertTrue($result->ok);
        $this->assertNotNull($result->payload);
        $this->assertSame(str_repeat('d', 64), $result->payload['new_genesis_reference']);
        $this->assertSame(str_repeat('c', 64), $result->payload['last_good_anchor']['hash']);
        $this->assertSame('evt-abc', $result->payload['provenance_link']['chain_break_event_id']);
    }

    public function test_returned_payload_carries_terminal_snapshot_typed_fields(): void
    {
        $bytes = $this->validTerminalRegistrySnapshotEnvelope();

        $result = $this->parser()->parse($bytes, FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT);

        $this->assertTrue($result->ok);
        $this->assertNotNull($result->payload);
        $this->assertSame(str_repeat('e', 64), $result->payload['snapshot_hash']);
        $this->assertNull($result->payload['prior_snapshot_link']);
        $this->assertCount(2, $result->payload['terminals']);
    }

    // ---- Opus P3-1: parser reuse across calls -----------------------

    public function test_parser_reuses_safely_across_calls(): void
    {
        $parser = $this->parser();

        $bad = $parser->parse('{"event_type":"SALE_RECEIPT","sequence_number":1.5}', FiscalEventType::SALE_RECEIPT);
        $this->assertFalse($bad->ok);

        $good = $parser->parse($this->validSaleReceiptEnvelope(), FiscalEventType::SALE_RECEIPT);
        $this->assertTrue($good->ok, 'unexpected failure on reuse: '.($good->failureReason ?? '(none)'));

        $bad2 = $parser->parse('{"event_type":"SALE_RECEIPT","event_type":"X"}', FiscalEventType::SALE_RECEIPT);
        $this->assertFalse($bad2->ok);
        $this->assertStringContainsString('duplicate_key', $bad2->failureReason ?? '');
    }

    // ---- Opus P3-2: `-0` rejected ------------------------------------

    public function test_rejects_negative_zero_integer(): void
    {
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'sequence_number', '-0');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('out_of_grammar_number', $result->failureReason ?? '');
    }

    // =================================================================
    // Pass 2A.PHP.1 — new forensic prefixes from synthesis v5 §6.E.
    //
    // These exercise the parser → FiscalPayloadConstraintValidator
    // pipeline via the new 28-key Candidate C-v3 payload (built by
    // GoldenFixtureBuilder F-01-baseline-eur) so the failure prefixes
    // surface through the parser's `sub_array_shape:` wrap.
    // =================================================================

    public function test_pass_2a_rejects_payload_partition_duplicate_forensic_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Duplicate the single breakdown row.
        $payload['vat_breakdown'][] = $payload['vat_breakdown'][0];
        $bytes = $this->envelope('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape:payload_partition_duplicate', $result->failureReason ?? '');
    }

    public function test_pass_2a_rejects_payload_partition_mismatch_forensic_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Add an extra breakdown row with no matching line.
        $payload['vat_breakdown'][] = [
            'gross_amount' => '0.00', 'net_amount' => '0.00', 'rate' => '0.00',
            'tax_category_code' => 'Z', 'vat_amount' => '0.00',
        ];
        $bytes = $this->envelope('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape:payload_partition_mismatch', $result->failureReason ?? '');
    }

    public function test_pass_2a_rejects_payload_money_scale_mismatch_forensic_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // currency_scale=2 but emit unit_price at scale 3.
        $payload['line_items'][0]['unit_price'] = '10.000';
        $bytes = $this->envelope('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape:payload_money_scale_mismatch', $result->failureReason ?? '');
    }

    public function test_pass_2a_rejects_payload_total_arithmetic_mismatch_forensic_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // Bump total without rebalancing.
        $payload['total'] = '15.00';
        $bytes = $this->envelope('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape:payload_total_arithmetic_mismatch', $result->failureReason ?? '');
    }

    public function test_pass_2a_rejects_payload_discount_reason_mismatch_forensic_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // zero discount + non-null reason → reject.
        $payload['transaction_discount_amount'] = '0.00';
        $payload['transaction_discount_reason'] = 'seasonal';
        $bytes = $this->envelope('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape:payload_discount_reason_mismatch', $result->failureReason ?? '');
    }

    public function test_pass_2a_rejects_payload_invoice_type_invalid_forensic_prefix(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        // SALE invoice with original_receipt_reference populated → reject.
        $payload['original_receipt_reference'] = [
            'fiscal_event_id' => '00000000-0000-4000-8000-000000000001',
            'original_business_date' => '2026-05-19',
            'original_receipt_uuid' => '00000000-0000-4000-8000-000000000002',
            'refund_reason' => 'should not be here on SALE',
        ];
        $bytes = $this->envelope('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape:payload_invoice_type_invalid', $result->failureReason ?? '');
    }

    public function test_pass_2a_accepts_28_key_baseline_through_parser_end_to_end(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $bytes = $this->envelope('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertNotNull($result->payload);
        $this->assertSame('EUR', $result->payload['currency_code']);
        $this->assertSame(2, $result->payload['currency_scale']);
        $this->assertSame('SALE', $result->payload['invoice_type_code']);
    }
}
