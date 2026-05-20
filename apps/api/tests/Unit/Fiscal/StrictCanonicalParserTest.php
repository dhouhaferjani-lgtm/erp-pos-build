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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
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
            default => throw new \LogicException('unsupported event type for helper: '.$eventType),
        };
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
        // Phase 1 SALE_RECEIPT is event_version=1; sending v2 must reject.
        $bytes = $this->envelopeWithRawValue('SALE_RECEIPT', 'event_version', '2');

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('envelope_event_version_mismatch', $result->failureReason ?? '');
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        // The default envelope already sets reference_document_id to null —
        // this test pins that the validator accepts the canonical nullable.
        $bytes = $this->validSaleReceiptEnvelope();
        $this->assertStringContainsString('"reference_document_id":null', $bytes);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
    }

    public function test_accepts_envelope_with_non_null_reference_document_id(): void
    {
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"extra_untyped":"X","lines":[],"payment_lines":[],"subtotal":"0.000",'
            .'"tax_total":"0.000","total":"0.000","vat_breakdown":[],"voucher_redemptions":[]}';
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"0.000","tax_total":"0.000",'
            .'"total":"not-money","vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('sub_array_shape', $result->failureReason ?? '');
        $this->assertStringContainsString('total', $result->failureReason ?? '');
    }

    public function test_rejects_money_with_wrong_fraction_length(): void
    {
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        // currency_scale=3 but money has 2 decimals.
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"5.00","tax_total":"0.000",'
            .'"total":"5.00","vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('bcformat', $result->failureReason ?? '');
    }

    public function test_accepts_scale_0_money(): void
    {
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        // JPY-style 0-decimal currency: integer-string money.
        $payload = '{"currency":"JPY","currency_scale":0,"discount_total":"0",'
            .'"lines":[],"payment_lines":[],"subtotal":"0","tax_total":"0",'
            .'"total":"0","vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
    }

    public function test_accepts_negative_money(): void
    {
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        // Negative values (refund) are valid bcformat.
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"-5.000","tax_total":"-0.350",'
            .'"total":"-5.350","vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
    }

    public function test_rejects_money_with_leading_plus(): void
    {
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"+5.000","tax_total":"0.000",'
            .'"total":"5.000","vat_breakdown":[],"voucher_redemptions":[]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertFailed($result);
        $this->assertStringContainsString('bcformat', $result->failureReason ?? '');
    }

    // ---- P1 F5: list-vs-assoc on containers (Codex round-2) ---------

    public function test_rejects_assoc_object_for_lines_container(): void
    {
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        // lines must be a JSON list, not an object.
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":{"k":{"product_id":"p-1","quantity":1,"unit_price":"5.000","line_total":"5.000","vat_rate":"7"}},'
            .'"payment_lines":[],"subtotal":"5.000","tax_total":"0.000","total":"5.000",'
            .'"vat_breakdown":[],"voucher_redemptions":[]}';
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        // é == é. Round-trip via voucher_redemptions[0].code.
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"0.000","tax_total":"0.000",'
            .'"total":"0.000","vat_breakdown":[],"voucher_redemptions":[{"code":"café","amount":"0.000"}]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertSame('café', $this->voucherCodeAt($result, 0));
    }

    public function test_parses_valid_surrogate_pair_grinning_face(): void
    {
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
        // 😀 == U+1F600 (😀).
        $payload = '{"currency":"TND","currency_scale":3,"discount_total":"0.000",'
            .'"lines":[],"payment_lines":[],"subtotal":"0.000","tax_total":"0.000",'
            .'"total":"0.000","vat_breakdown":[],"voucher_redemptions":[{"code":"😀","amount":"0.000"}]}';
        $bytes = $this->envelopeWithRawPayload('SALE_RECEIPT', $payload);

        $result = $this->parser()->parse($bytes, FiscalEventType::SALE_RECEIPT);

        $this->assertTrue($result->ok, 'unexpected failure: '.($result->failureReason ?? '(none)'));
        $this->assertSame("\xF0\x9F\x98\x80", $this->voucherCodeAt($result, 0));
    }

    /**
     * Helper to drill into payload.voucher_redemptions[$index].code with
     * proper narrowing — PHPStan can't see through `$result->payload[...]`
     * chained array access on `array<string, mixed>|null`.
     */
    private function voucherCodeAt(ParseResult $result, int $index): mixed
    {
        $payload = $result->payload;
        $this->assertIsArray($payload);
        $vouchers = $payload['voucher_redemptions'] ?? null;
        $this->assertIsArray($vouchers);
        $this->assertArrayHasKey($index, $vouchers);
        $item = $vouchers[$index];
        $this->assertIsArray($item);

        return $item['code'] ?? null;
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
        $this->markTestSkipped(
            'Pass 2A.PHP.2 will migrate the validSaleReceiptEnvelope() / envelopeWithRawPayload() helpers '.
            'to emit the 27-key Candidate C-v3 canonical contract per synthesis v5 §3. '.
            'See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + task tracker entry "Pass 2A.PHP.2 — consumer migration".'
        );
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
    // pipeline via the new 27-key Candidate C-v3 payload (built by
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

    public function test_pass_2a_accepts_27_key_baseline_through_parser_end_to_end(): void
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
