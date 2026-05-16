<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\DTOs\ParseResult;
use App\Modules\Fiscal\Application\Services\FiscalEventPayloadRegistry;
use App\Modules\Fiscal\Application\Services\StrictCanonicalParser;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
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
        return new StrictCanonicalParser(new FiscalEventPayloadRegistry);
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
        $this->assertSame('TND', $result->payload['currency']);
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
        $bytes = '{"event_type":"SALE_RECEIPT","sequence_number":1}';

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('missing_payload', $result->failureReason ?? '');
    }

    public function test_rejects_payload_not_object(): void
    {
        $bytes = '{"event_type":"SALE_RECEIPT","payload":"not an object"}';

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
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[1,2,3],"payment_lines":[],"subtotal":"0.000","tax_total":"0.000",'
            .'"total":"0.000","vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
        $this->assertStringContainsString('lines', $result->failureReason ?? '');
    }

    public function test_rejects_empty_object_item_in_sub_array_lines(): void
    {
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[{}],"payment_lines":[],"subtotal":"0.000","tax_total":"0.000",'
            .'"total":"0.000","vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
    }

    public function test_rejects_integer_monetary_field_in_sub_array_line(): void
    {
        // unit_price is an int (1) — must be a CurrencyScale::bcformat() string per §4.
        // Strict tokenizer doesn't catch this (1 is a valid integer); the sub-array
        // shape validator does.
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[{"product_id":"p-1","quantity":1,"unit_price":1,'
            .'"line_total":"5.000","vat_rate":"7"}],"payment_lines":[],'
            .'"subtotal":"5.000","tax_total":"0.000","total":"5.000",'
            .'"vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
        $this->assertStringContainsString('unit_price', $result->failureReason ?? '');
    }

    public function test_rejects_integer_monetary_field_in_payment_lines(): void
    {
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[{"payment_method_id":"pm-cash","amount":100,'
            .'"tendered":"100.000","change":"0.000"}],"subtotal":"0.000",'
            .'"tax_total":"0.000","total":"0.000","vat_breakdown":[],'
            .'"voucher_redemptions":[]}';
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
        // Empty sub-arrays-of-items are legitimate (no vouchers redeemed).
        $bytes = $this->validSaleReceiptEnvelope();
        // sanity: validSaleReceiptEnvelope has voucher_redemptions: []
        $this->assertStringContainsString('"voucher_redemptions":[]', $bytes);

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

    private function validSaleReceiptEnvelope(): string
    {
        return $this->envelope('SALE_RECEIPT', [
            'currency' => 'TND',
            'currency_scale' => 3,
            'discount_total' => '0.000',
            'lines' => [
                ['product_id' => 'p-1', 'quantity' => 1, 'unit_price' => '5.000', 'line_total' => '5.000', 'vat_rate' => '7'],
            ],
            'payment_lines' => [
                ['payment_method_id' => 'pm-cash', 'amount' => '5.350', 'tendered' => '6.000', 'change' => '0.650'],
            ],
            'subtotal' => '5.000',
            'tax_total' => '0.350',
            'total' => '5.350',
            'vat_breakdown' => [
                ['rate' => '7', 'base' => '5.000', 'amount' => '0.350'],
            ],
            'voucher_redemptions' => [],
        ]);
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
}
