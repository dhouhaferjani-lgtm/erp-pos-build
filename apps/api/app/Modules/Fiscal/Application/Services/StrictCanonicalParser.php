<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Application\DTOs\ParseResult;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\FiscalEventTypeNotImplemented;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Server-side strict parser for verified `canonical_bytes` (spec v7 §7.6).
 *
 * Reads the device's authoritative bytes verbatim and derives the
 * structured `payload` JSONB the OutboxIngestor stores. Rejects every
 * anomaly the upstream hash check cannot detect:
 *
 *  - **invalid UTF-8** — `mb_check_encoding`
 *  - **non-canonical whitespace** — spec §4 is RFC 8785/JCS; canonical
 *    bytes have no insignificant whitespace, so any byte-level whitespace
 *    outside string values is rejected (BLOCKER F3 round-2)
 *  - **duplicate keys at any depth** — PHP's `json_decode` silently
 *    overwrites; this parser uses a hand-rolled tokenizer that detects
 *    duplicates at every object level
 *  - **out-of-grammar numbers** — fractional, exponent, leading-zero,
 *    `+`-signed, `-0` (non-canonical), or overflow. §4 grammar is
 *    integer-only; money is `CurrencyScale::bcformat()` strings
 *  - **unescaped control bytes in strings** — RFC 8259
 *  - **U+2028 / U+2029 line/paragraph separators** — §4 says producer
 *    strips them; canonical bytes never contain them
 *  - **14-field envelope shape** — spec §4 enumerates the canonical
 *    keys; the parser requires exactly those keys with the correct types
 *    + canonical regexes for hash / ISO 8601 / date fields (BLOCKER F1
 *    round-2; carry-forward handoff §4.3)
 *  - **payload extras** — spec §7.6 "event-type schema violations";
 *    DTO::fromArray only checks required keys, so the parser enforces an
 *    exact key set per event type (BLOCKER F2 round-2)
 *  - **event-type schema violations** — via per-event-type DTO
 *    `fromArray()` (Task 14)
 *  - **sub-array per-item shape** — `lines[i]`, `vat_breakdown[i]`,
 *    `payment_lines[i]`, `voucher_redemptions[i]`, `terminals[i]` must
 *    each be a non-empty associative object; the container itself must
 *    be a JSON list (`array_is_list`); named monetary fields must be
 *    scale-aware `CurrencyScale::bcformat()` strings (BLOCKER F4 + P1 F5
 *    round-2)
 *  - **payload hash-field format** — `last_good_hash`,
 *    `new_genesis_reference`, `snapshot_hash`, `last_good_anchor.hash`,
 *    `offending_record_reference.observed_previous_hash`,
 *    `prior_snapshot_link` must each match `^[0-9a-f]{64}$` when present
 *    (P1 F6 / Opus P1-1 round-2; analogue of Task 15 P1-1 on the
 *    server boundary)
 *
 * Never throws on malformed input — returns a failed `ParseResult` so
 * the verbatim canonical bytes can be quarantined with a structured
 * `integrity_exception_reason` (§8).
 *
 * **Non-reentrant.** The tokenizer holds cursor state on the instance;
 * the public `parse()` resets it on entry. Single-threaded PHP makes
 * this safe within one request — do not call from concurrent fibers.
 */
final class StrictCanonicalParser
{
    /** 64-char lowercase hex — spec §4 hash format. */
    private const LOWER_HEX_64 = '/^[0-9a-f]{64}$/D';

    /** ISO 8601 UTC, second precision — spec §4 timestamp format. */
    private const ISO_8601_SECONDS_UTC = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D';

    /** ISO 8601 date (YYYY-MM-DD) — spec §4 business_date format. */
    private const ISO_8601_DATE = '/^\d{4}-\d{2}-\d{2}$/D';

    /** The 14 canonical envelope keys per spec §4 (alphabetical). */
    private const ENVELOPE_KEYS = [
        'business_date',
        'company_id',
        'event_time_device',
        'event_type',
        'event_version',
        'operator_id',
        'payload',
        'previous_hash',
        'reference_document_id',
        'reference_event_id',
        'sequence_number',
        'signature_version',
        'tenant_id',
        'terminal_id',
    ];

    /**
     * Expected payload key set per event type. The DTO `fromArray()` only
     * validates that required keys are present; the parser additionally
     * rejects any extra keys (BLOCKER F2 round-2).
     *
     * @var array<value-of<FiscalEventType>, list<string>>
     */
    private const PAYLOAD_KEYS = [
        'SALE_RECEIPT' => [
            'currency', 'currency_scale', 'discount_total', 'lines',
            'payment_lines', 'subtotal', 'tax_total', 'total',
            'vat_breakdown', 'voucher_redemptions',
        ],
        'CHAIN_BREAK_DETECTED' => [
            'last_good_hash', 'last_good_sequence',
            'offending_record_reference', 'reason',
        ],
        'CHAIN_RESTART' => [
            'last_good_anchor', 'new_genesis_reference',
            'operator_authorization_evidence', 'provenance_link',
        ],
        'TERMINAL_REGISTRY_SNAPSHOT' => [
            'prior_snapshot_link', 'snapshot_hash', 'terminals',
        ],
    ];

    private string $bytes = '';

    private int $pos = 0;

    private int $len = 0;

    public function __construct(
        private readonly FiscalEventPayloadRegistry $registry,
    ) {}

    public function parse(string $canonicalBytes, FiscalEventType $type): ParseResult
    {
        if ($canonicalBytes === '') {
            return ParseResult::failure('empty_bytes:zero-length canonical_bytes');
        }
        if (! mb_check_encoding($canonicalBytes, 'UTF-8')) {
            return ParseResult::failure('invalid_utf8:bytes are not valid UTF-8');
        }

        $this->bytes = $canonicalBytes;
        $this->pos = 0;
        $this->len = strlen($canonicalBytes);

        try {
            $envelope = $this->parseValue();
            if ($this->pos !== $this->len) {
                return ParseResult::failure('trailing_bytes:offset='.$this->pos);
            }
        } catch (RuntimeException $e) {
            return ParseResult::failure($e->getMessage());
        }

        if (! is_array($envelope) || (count($envelope) > 0 && array_is_list($envelope))) {
            return ParseResult::failure('envelope_not_object:got='.(is_array($envelope) ? 'list' : get_debug_type($envelope)));
        }
        /** @var array<string, mixed> $envelope */

        // Event-type assertion first so unimplemented types are rejected with
        // a precise prefix before we drown the caller in envelope-shape errors.
        $envelopeType = $envelope['event_type'] ?? null;
        if ($envelopeType !== $type->value) {
            return ParseResult::failure(sprintf(
                'event_type_mismatch:expected=%s,envelope=%s',
                $type->value,
                is_string($envelopeType) ? $envelopeType : get_debug_type($envelopeType),
            ));
        }

        try {
            $dtoClass = $this->registry->dtoClassFor($type);
            $expectedVersion = $this->registry->eventVersionFor($type);
        } catch (FiscalEventTypeNotImplemented) {
            return ParseResult::failure('event_type_unimplemented:'.$type->value);
        }

        $envelopeError = $this->validateEnvelopeShape($envelope, $expectedVersion);
        if ($envelopeError !== null) {
            return ParseResult::failure($envelopeError);
        }

        /** @var array<string, mixed> $payload */
        $payload = $envelope['payload'];

        try {
            $dtoClass::fromArray($payload);
        } catch (Throwable $e) {
            return ParseResult::failure('schema_violation:'.$e->getMessage());
        }

        $extrasError = $this->validatePayloadKeySet($type, $payload);
        if ($extrasError !== null) {
            return ParseResult::failure($extrasError);
        }

        try {
            $this->validatePerEventConstraints($type, $payload);
        } catch (RuntimeException $e) {
            return ParseResult::failure('sub_array_shape:'.$e->getMessage());
        }

        return ParseResult::ok($payload);
    }

    // -----------------------------------------------------------------
    // Strict JSON tokenizer — whitespace forbidden (BLOCKER F3 round-2)
    //
    // Every tokenizer method below is **impure** — it mutates the cursor
    // state held on the instance. `@phpstan-impure` tells PHPStan to
    // re-read instance state across these calls instead of caching the
    // pre-call values (otherwise `if ($this->pos !== $this->len)` after
    // a parseValue() reads as "always true" because PHPStan thinks
    // `$this->pos` is still 0).
    // -----------------------------------------------------------------

    /**
     * @return array<int|string, mixed>|string|int|bool|null
     *
     * @phpstan-impure
     */
    private function parseValue(): array|string|int|bool|null
    {
        if ($this->pos >= $this->len) {
            throw new RuntimeException('unexpected_end:expected value at offset '.$this->pos);
        }
        $c = $this->bytes[$this->pos];

        return match (true) {
            $c === '"' => $this->parseString(),
            $c === '{' => $this->parseObject(),
            $c === '[' => $this->parseArray(),
            $c === 't', $c === 'f' => $this->parseBool(),
            $c === 'n' => $this->parseNull(),
            $c === '-' || ($c >= '0' && $c <= '9') => $this->parseNumber(),
            default => throw new RuntimeException('unexpected_character:byte=0x'.bin2hex($c).' offset='.$this->pos),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseObject(): array
    {
        $this->expect('{');
        /** @var array<string, mixed> $result */
        $result = [];
        if ($this->peek() === '}') {
            $this->pos++;

            return $result;
        }
        while (true) {
            if ($this->peek() !== '"') {
                throw new RuntimeException('unexpected_character:expected object key string at offset '.$this->pos);
            }
            $key = $this->parseString();
            if (array_key_exists($key, $result)) {
                throw new RuntimeException('duplicate_key:'.$key);
            }
            $this->expect(':');
            $result[$key] = $this->parseValue();
            $c = $this->peek();
            if ($c === ',') {
                $this->pos++;

                continue;
            }
            if ($c === '}') {
                $this->pos++;

                return $result;
            }
            throw new RuntimeException("unexpected_character:expected ',' or '}' at offset ".$this->pos);
        }
    }

    /**
     * @return list<mixed>
     */
    private function parseArray(): array
    {
        $this->expect('[');
        /** @var list<mixed> $result */
        $result = [];
        if ($this->peek() === ']') {
            $this->pos++;

            return $result;
        }
        while (true) {
            $result[] = $this->parseValue();
            $c = $this->peek();
            if ($c === ',') {
                $this->pos++;

                continue;
            }
            if ($c === ']') {
                $this->pos++;

                return $result;
            }
            throw new RuntimeException("unexpected_character:expected ',' or ']' at offset ".$this->pos);
        }
    }

    private function parseString(): string
    {
        $this->expect('"');
        $out = '';
        while ($this->pos < $this->len) {
            $c = $this->bytes[$this->pos];
            if ($c === '"') {
                $this->pos++;

                // P2 F7 round-2 — reject U+2028 LINE SEPARATOR and U+2029
                // PARAGRAPH SEPARATOR. Spec §4 says the producer strips
                // these; canonical bytes must never contain them.
                if (str_contains($out, "\xE2\x80\xA8") || str_contains($out, "\xE2\x80\xA9")) {
                    throw new RuntimeException('invalid_unicode:U+2028 / U+2029 forbidden in canonical strings');
                }

                return $out;
            }
            if ($c === '\\') {
                $this->pos++;
                if ($this->pos >= $this->len) {
                    throw new RuntimeException('unterminated_string:trailing backslash');
                }
                $esc = $this->bytes[$this->pos];
                switch ($esc) {
                    case '"': $out .= '"';
                        break;
                    case '\\': $out .= '\\';
                        break;
                    case '/': $out .= '/';
                        break;
                    case 'b': $out .= "\x08";
                        break;
                    case 'f': $out .= "\x0C";
                        break;
                    case 'n': $out .= "\n";
                        break;
                    case 'r': $out .= "\r";
                        break;
                    case 't': $out .= "\t";
                        break;
                    case 'u':
                        $out .= $this->parseUnicodeEscape();
                        break;
                    default:
                        throw new RuntimeException('unexpected_character:bad escape \\'.$esc.' at offset '.$this->pos);
                }
                $this->pos++;

                continue;
            }
            // RFC 8259 — control chars (< 0x20) must be escaped, never literal.
            if (ord($c) < 0x20) {
                throw new RuntimeException('unexpected_character:unescaped control byte=0x'.bin2hex($c).' offset='.$this->pos);
            }
            // UTF-8 multibyte validity was guaranteed by mb_check_encoding upfront,
            // so we can append the raw byte directly.
            $out .= $c;
            $this->pos++;
        }
        throw new RuntimeException('unterminated_string:string runs to end of input');
    }

    /**
     * Consumes 4 hex digits (already past the `\u`) plus optionally a
     * second `\uXXXX` for a surrogate pair, and returns the UTF-8 bytes
     * for the resolved codepoint. The trailing `$this->pos++` in the
     * caller advances past the final hex digit.
     */
    private function parseUnicodeEscape(): string
    {
        $hex = substr($this->bytes, $this->pos + 1, 4);
        if (strlen($hex) !== 4 || ctype_xdigit($hex) === false) {
            throw new RuntimeException('invalid_unicode_escape:\\u'.$hex.' at offset '.$this->pos);
        }
        $codepoint = (int) hexdec($hex);
        $this->pos += 4;

        if ($codepoint >= 0xDC00 && $codepoint <= 0xDFFF) {
            throw new RuntimeException('invalid_unicode_escape:lone low surrogate at offset '.$this->pos);
        }

        if ($codepoint >= 0xD800 && $codepoint <= 0xDBFF) {
            // High surrogate — require a paired `\uXXXX` low surrogate next.
            if ($this->pos + 6 >= $this->len
                || $this->bytes[$this->pos + 1] !== '\\'
                || $this->bytes[$this->pos + 2] !== 'u') {
                throw new RuntimeException('invalid_unicode_escape:lone high surrogate at offset '.$this->pos);
            }
            $hex2 = substr($this->bytes, $this->pos + 3, 4);
            if (strlen($hex2) !== 4 || ctype_xdigit($hex2) === false) {
                throw new RuntimeException('invalid_unicode_escape:bad surrogate pair at offset '.$this->pos);
            }
            $low = (int) hexdec($hex2);
            if ($low < 0xDC00 || $low > 0xDFFF) {
                throw new RuntimeException('invalid_unicode_escape:invalid low surrogate at offset '.$this->pos);
            }
            $codepoint = 0x10000 + (($codepoint - 0xD800) << 10) + ($low - 0xDC00);
            $this->pos += 6;
        }

        if ($codepoint === 0x2028 || $codepoint === 0x2029) {
            throw new RuntimeException('invalid_unicode:U+2028 / U+2029 forbidden in canonical strings');
        }

        return mb_chr($codepoint, 'UTF-8');
    }

    private function parseNumber(): int
    {
        $start = $this->pos;
        if ($this->bytes[$this->pos] === '-') {
            $this->pos++;
            if ($this->pos >= $this->len) {
                throw new RuntimeException('out_of_grammar_number:bare minus at offset '.$start);
            }
        }
        $first = $this->bytes[$this->pos];
        if ($first === '0') {
            $this->pos++;
            $next = $this->peek();
            if ($next !== null && $next >= '0' && $next <= '9') {
                throw new RuntimeException('out_of_grammar_number:leading zero at offset '.$start);
            }
        } elseif ($first >= '1' && $first <= '9') {
            $this->pos++;
            while ($this->pos < $this->len) {
                $d = $this->bytes[$this->pos];
                if ($d < '0' || $d > '9') {
                    break;
                }
                $this->pos++;
            }
        } else {
            throw new RuntimeException('out_of_grammar_number:invalid leading character at offset '.$start);
        }

        $next = $this->peek();
        if ($next === '.' || $next === 'e' || $next === 'E') {
            throw new RuntimeException('out_of_grammar_number:fractional or exponent forbidden at offset '.$this->pos);
        }

        $token = substr($this->bytes, $start, $this->pos - $start);
        $value = (int) $token;

        // Round-trip stringify rejects: (a) integer overflow (saturation),
        // (b) `-0` (non-canonical — JCS normalizes to `0`). Both cases are
        // out-of-grammar per spec §4. (Opus P3-2 round-2 dropped the `-0`
        // carve-out.)
        if ((string) $value !== $token) {
            throw new RuntimeException('out_of_grammar_number:non-canonical or overflow at offset '.$start);
        }

        return $value;
    }

    private function parseBool(): bool
    {
        if (substr($this->bytes, $this->pos, 4) === 'true') {
            $this->pos += 4;

            return true;
        }
        if (substr($this->bytes, $this->pos, 5) === 'false') {
            $this->pos += 5;

            return false;
        }
        throw new RuntimeException('unexpected_character:expected boolean at offset '.$this->pos);
    }

    private function parseNull(): null
    {
        if (substr($this->bytes, $this->pos, 4) === 'null') {
            $this->pos += 4;

            return null;
        }
        throw new RuntimeException('unexpected_character:expected null at offset '.$this->pos);
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw new RuntimeException("unexpected_character:expected '$char' at offset ".$this->pos);
        }
        $this->pos++;
    }

    private function peek(): ?string
    {
        return $this->pos < $this->len ? $this->bytes[$this->pos] : null;
    }

    // -----------------------------------------------------------------
    // Envelope shape validation (BLOCKER F1 round-2 / handoff §4.3)
    //
    // Asserts the §4 fourteen-field canonical object shape: exactly the
    // expected key set, each with the documented type + canonical regex
    // format. Runs AFTER tokenization + event-type assertion so the
    // failure prefix is meaningful (event_type_mismatch /
    // event_type_unimplemented surface first).
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function validateEnvelopeShape(array $envelope, int $expectedEventVersion): ?string
    {
        $actual = array_keys($envelope);
        $missing = array_diff(self::ENVELOPE_KEYS, $actual);
        if (count($missing) > 0) {
            return 'envelope_field_missing:'.implode(',', $missing);
        }
        $extras = array_diff($actual, self::ENVELOPE_KEYS);
        if (count($extras) > 0) {
            return 'envelope_extra_field:'.implode(',', $extras);
        }

        // event_version: positive int matching the registry. The registry
        // is the source of truth; a mismatched version means either a
        // device drift or an unknown event flavor.
        if (! is_int($envelope['event_version']) || $envelope['event_version'] < 1) {
            return 'envelope_event_version_invalid:got='.var_export($envelope['event_version'], true);
        }
        if ($envelope['event_version'] !== $expectedEventVersion) {
            return sprintf(
                'envelope_event_version_mismatch:expected=%d,envelope=%d',
                $expectedEventVersion,
                $envelope['event_version'],
            );
        }

        // sequence_number: positive int (>= 1 — the genesis is `1`).
        if (! is_int($envelope['sequence_number']) || $envelope['sequence_number'] < 1) {
            return 'envelope_sequence_number_invalid:got='.var_export($envelope['sequence_number'], true);
        }

        // previous_hash: 64-char lowercase hex.
        if (! is_string($envelope['previous_hash']) || preg_match(self::LOWER_HEX_64, $envelope['previous_hash']) !== 1) {
            return 'envelope_previous_hash_invalid:must be 64-char lowercase hex';
        }

        // event_time_device: ISO 8601 UTC second-precision.
        if (! is_string($envelope['event_time_device']) || preg_match(self::ISO_8601_SECONDS_UTC, $envelope['event_time_device']) !== 1) {
            return 'envelope_event_time_device_invalid:must be ISO 8601 UTC seconds';
        }

        // business_date: ISO 8601 date.
        if (! is_string($envelope['business_date']) || preg_match(self::ISO_8601_DATE, $envelope['business_date']) !== 1) {
            return 'envelope_business_date_invalid:must be ISO 8601 date';
        }

        // Non-empty string IDs.
        foreach (['tenant_id', 'company_id', 'terminal_id', 'operator_id', 'signature_version'] as $field) {
            if (! is_string($envelope[$field]) || $envelope[$field] === '') {
                return 'envelope_'.$field.'_invalid:must be non-empty string';
            }
        }

        // Optional string-or-null.
        foreach (['reference_document_id', 'reference_event_id'] as $field) {
            $v = $envelope[$field];
            if ($v !== null && (! is_string($v) || $v === '')) {
                return 'envelope_'.$field.'_invalid:must be non-empty string or null';
            }
        }

        // payload: must be an object (non-list array), not a scalar or list.
        $payload = $envelope['payload'];
        if (! is_array($payload)) {
            return 'payload_not_object:got='.get_debug_type($payload);
        }
        if (count($payload) > 0 && array_is_list($payload)) {
            return 'payload_not_object:got=list';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePayloadKeySet(FiscalEventType $type, array $payload): ?string
    {
        $expected = self::PAYLOAD_KEYS[$type->value] ?? null;
        if ($expected === null) {
            // Defense — already rejected at registry lookup, but keeps the
            // method total over the enum.
            return 'event_type_unimplemented:'.$type->value;
        }

        $extras = array_diff(array_keys($payload), $expected);
        if (count($extras) > 0) {
            return 'payload_extra_field:'.implode(',', $extras);
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Per-event constraints — sub-array shape (Task 14 P2-2 carry-forward),
    // money format (BLOCKER F4 round-2), hash format (P1 F6 / Opus P1-1).
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePerEventConstraints(FiscalEventType $type, array $payload): void
    {
        match ($type) {
            FiscalEventType::SALE_RECEIPT => $this->validateSaleReceiptPayload($payload),
            FiscalEventType::CHAIN_BREAK_DETECTED => $this->validateChainBreakDetectedPayload($payload),
            FiscalEventType::CHAIN_RESTART => $this->validateChainRestartPayload($payload),
            FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT => $this->validateTerminalRegistrySnapshotPayload($payload),
            // Opus P2-3 round-2 — explicit throw so a future Phase 1 event
            // type cannot land without a corresponding parser clause.
            default => throw new LogicException(
                'StrictCanonicalParser missing per-event clause for FiscalEventType::'.$type->name
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateSaleReceiptPayload(array $payload): void
    {
        $scale = $payload['currency_scale'];
        if (! is_int($scale) || $scale < 0 || $scale > 8) {
            throw new RuntimeException('currency_scale must be a non-negative int <= 8; got '.var_export($scale, true));
        }
        $moneyRegex = $this->moneyRegex($scale);

        // Top-level monetary fields.
        foreach (['subtotal', 'discount_total', 'tax_total', 'total'] as $field) {
            $this->assertMoneyString($payload, $field, $moneyRegex, $scale);
        }

        // Sub-array containers.
        $this->validateListOfAssoc($payload, 'lines', $moneyRegex, $scale, ['unit_price', 'line_total']);
        $this->validateListOfAssoc($payload, 'vat_breakdown', $moneyRegex, $scale, ['base', 'amount']);
        $this->validateListOfAssoc($payload, 'payment_lines', $moneyRegex, $scale, ['amount', 'tendered', 'change']);
        $this->validateListOfAssoc($payload, 'voucher_redemptions', $moneyRegex, $scale, ['amount']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateChainBreakDetectedPayload(array $payload): void
    {
        // `last_good_hash` is 64-char lowercase hex per spec §9.
        $this->assertHashField($payload, 'last_good_hash');

        // `offending_record_reference` is a non-empty assoc object; if it
        // carries an `observed_previous_hash`, that's a hash.
        $this->validateNonEmptyAssoc($payload, 'offending_record_reference');
        /** @var array<string, mixed> $ref */
        $ref = $payload['offending_record_reference'];
        if (array_key_exists('observed_previous_hash', $ref)) {
            $this->assertHashField($ref, 'observed_previous_hash', 'offending_record_reference.observed_previous_hash');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateChainRestartPayload(array $payload): void
    {
        $this->assertHashField($payload, 'new_genesis_reference');
        $this->validateNonEmptyAssoc($payload, 'last_good_anchor');
        $this->validateNonEmptyAssoc($payload, 'operator_authorization_evidence');
        $this->validateNonEmptyAssoc($payload, 'provenance_link');

        /** @var array<string, mixed> $anchor */
        $anchor = $payload['last_good_anchor'];
        if (array_key_exists('hash', $anchor)) {
            $this->assertHashField($anchor, 'hash', 'last_good_anchor.hash');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateTerminalRegistrySnapshotPayload(array $payload): void
    {
        $this->assertHashField($payload, 'snapshot_hash');
        // `prior_snapshot_link` is nullable; when present and non-null it's
        // the prior snapshot's hash per spec §11 ("(carries a hash; links
        // to the prior snapshot)").
        if (($payload['prior_snapshot_link'] ?? null) !== null) {
            $this->assertHashField($payload, 'prior_snapshot_link');
        }

        $this->validateListOfAssoc($payload, 'terminals', $this->moneyRegex(0), 0, []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $monetaryStringFields  field names that, when present, must match `$moneyRegex`
     */
    private function validateListOfAssoc(array $payload, string $key, string $moneyRegex, int $moneyScale, array $monetaryStringFields): void
    {
        $items = $payload[$key] ?? null;
        if (! is_array($items)) {
            // Defense — DTO::fromArray() already throws on non-array.
            throw new RuntimeException("$key must be an array; got ".get_debug_type($items));
        }
        // P1 F5 round-2 — the container MUST be a JSON list, not an object.
        // `array_is_list([])` returns true so this also accepts empty lists.
        if (! array_is_list($items)) {
            throw new RuntimeException("$key must be a JSON list, not an object");
        }
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new RuntimeException("{$key}[{$index}] must be an object; got ".get_debug_type($item));
            }
            if (count($item) === 0) {
                throw new RuntimeException("{$key}[{$index}] must be a non-empty object; got empty");
            }
            if (array_is_list($item)) {
                throw new RuntimeException("{$key}[{$index}] must be an object, not a list");
            }
            // After the is_array + count > 0 + !array_is_list checks above,
            // $item is a non-empty associative array — safe to pass to
            // assertMoneyString which expects `array<string, mixed>`.
            foreach ($monetaryStringFields as $field) {
                if (array_key_exists($field, $item)) {
                    $this->assertMoneyString($item, $field, $moneyRegex, $moneyScale, "{$key}[{$index}].{$field}");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateNonEmptyAssoc(array $payload, string $key): void
    {
        $value = $payload[$key] ?? null;
        if (! is_array($value)) {
            throw new RuntimeException("$key must be an object; got ".get_debug_type($value));
        }
        if (count($value) === 0) {
            throw new RuntimeException("$key must be a non-empty object; got empty");
        }
        if (array_is_list($value)) {
            throw new RuntimeException("$key must be an object, not a list");
        }
    }

    /**
     * @param  array<string, mixed>  $bag  source object (top-level payload or nested object)
     */
    private function assertMoneyString(array $bag, string $field, string $regex, int $scale, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_string($value)) {
            throw new RuntimeException(sprintf(
                '%s must be a CurrencyScale::bcformat() string for scale=%d; got %s',
                $label,
                $scale,
                get_debug_type($value),
            ));
        }
        if (preg_match($regex, $value) !== 1) {
            throw new RuntimeException(sprintf(
                '%s must match bcformat(scale=%d); got %s',
                $label,
                $scale,
                var_export($value, true),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function assertHashField(array $bag, string $field, ?string $reportAs = null): void
    {
        $label = $reportAs ?? $field;
        $value = $bag[$field] ?? null;
        if (! is_string($value) || preg_match(self::LOWER_HEX_64, $value) !== 1) {
            throw new RuntimeException(sprintf(
                'invalid_hash_format:%s must be 64-char lowercase hex; got %s',
                $label,
                var_export($value, true),
            ));
        }
    }

    /**
     * Scale-aware regex matching `CurrencyScale::bcformat()` output:
     *
     *   - scale 0 (e.g. JPY)  → `0`, `-1`, `123`
     *   - scale 2 (e.g. EUR)  → `0.00`, `-1.50`, `123.45`
     *   - scale 3 (e.g. TND)  → `0.000`, `-1.500`, `123.456`
     *
     * Integer part is `0` or `[1-9]\d*` (no leading zeros); fraction is
     * exactly `$scale` digits when `$scale > 0`.
     */
    private function moneyRegex(int $scale): string
    {
        if ($scale === 0) {
            return '/^-?(0|[1-9]\d*)$/D';
        }

        return '/^-?(0|[1-9]\d*)\.\d{'.$scale.'}$/D';
    }
}
