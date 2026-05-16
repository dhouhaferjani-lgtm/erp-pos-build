<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Application\DTOs\ParseResult;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\FiscalEventTypeNotImplemented;
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
 *  - **duplicate keys at any depth** — PHP's `json_decode` silently
 *    overwrites; this parser uses a hand-rolled tokenizer that detects
 *    duplicates at every object level
 *  - **out-of-grammar numbers** — fractional, exponent, leading-zero,
 *    `+`-signed forms. §4 value grammar is integer-only; money is
 *    `CurrencyScale::bcformat()` strings
 *  - **unescaped control bytes in strings** — RFC 8259
 *  - **event-type schema violations** — via per-event-type DTO
 *    `fromArray()` (Task 14)
 *  - **sub-array per-item shape** — `lines[i]`, `vat_breakdown[i]`,
 *    `payment_lines[i]`, `voucher_redemptions[i]`, `terminals[i]` must
 *    each be a non-empty associative object; named monetary fields
 *    (when present) must be `CurrencyScale::bcformat()` strings, never
 *    int/float. Sub-array shape validation is the carry-forward from
 *    Task 14 P2-2 (handoff §4.3)
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
            $this->skipWhitespace();
            if ($this->pos !== $this->len) {
                return ParseResult::failure('trailing_bytes:offset='.$this->pos);
            }
        } catch (RuntimeException $e) {
            return ParseResult::failure($e->getMessage());
        }

        if (! is_array($envelope)) {
            return ParseResult::failure('envelope_not_object:got='.get_debug_type($envelope));
        }

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
        } catch (FiscalEventTypeNotImplemented) {
            return ParseResult::failure('event_type_unimplemented:'.$type->value);
        }

        if (! array_key_exists('payload', $envelope)) {
            return ParseResult::failure('missing_payload:envelope has no payload key');
        }
        $payload = $envelope['payload'];
        if (! is_array($payload)) {
            return ParseResult::failure('payload_not_object:got='.get_debug_type($payload));
        }

        try {
            /** @var array<string, mixed> $payload */
            $dtoClass::fromArray($payload);
        } catch (Throwable $e) {
            return ParseResult::failure('schema_violation:'.$e->getMessage());
        }

        try {
            $this->validateSubArrays($type, $payload);
        } catch (RuntimeException $e) {
            return ParseResult::failure('sub_array_shape:'.$e->getMessage());
        }

        return ParseResult::ok($payload);
    }

    // -----------------------------------------------------------------
    // Strict JSON tokenizer
    // -----------------------------------------------------------------

    /**
     * @return array<int|string, mixed>|string|int|bool|null
     */
    private function parseValue(): array|string|int|bool|null
    {
        $this->skipWhitespace();
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
        $this->skipWhitespace();
        /** @var array<string, mixed> $result */
        $result = [];
        if ($this->peek() === '}') {
            $this->pos++;

            return $result;
        }
        while (true) {
            $this->skipWhitespace();
            if ($this->peek() !== '"') {
                throw new RuntimeException('unexpected_character:expected object key string at offset '.$this->pos);
            }
            $key = $this->parseString();
            if (array_key_exists($key, $result)) {
                throw new RuntimeException('duplicate_key:'.$key);
            }
            $this->skipWhitespace();
            $this->expect(':');
            $result[$key] = $this->parseValue();
            $this->skipWhitespace();
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
        $this->skipWhitespace();
        /** @var list<mixed> $result */
        $result = [];
        if ($this->peek() === ']') {
            $this->pos++;

            return $result;
        }
        while (true) {
            $result[] = $this->parseValue();
            $this->skipWhitespace();
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

        // Surrogate ranges + `> 0x10FFFF` are rejected above; mb_chr cannot
        // fail on the remaining range, so the stubbed `string` return is
        // accurate here.
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

        // Belt-and-braces overflow detection: PHP_INT_MAX is 9223372036854775807
        // on 64-bit; (int) silently saturates / wraps on overflow. Catch via
        // round-trip stringification; `-0` is the one accepted divergence.
        if ((string) $value !== $token && $token !== '-0') {
            throw new RuntimeException('out_of_grammar_number:integer overflow at offset '.$start);
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

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->bytes[$this->pos];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->pos++;
            } else {
                break;
            }
        }
    }

    // -----------------------------------------------------------------
    // Per-event-type sub-array shape (Task 14 P2-2 carry-forward).
    //
    // The payload DTOs accept `array<string, mixed>` for sub-array slots
    // because the DTOs' job is the top-level shape; per-item shape is
    // the parser's job. This is where we enforce that each item is a
    // non-empty associative object and that named monetary fields are
    // CurrencyScale::bcformat() strings (never int / float).
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateSubArrays(FiscalEventType $type, array $payload): void
    {
        match ($type) {
            FiscalEventType::SALE_RECEIPT => $this->validateSaleReceiptSubArrays($payload),
            FiscalEventType::CHAIN_BREAK_DETECTED => $this->validateNonEmptyAssoc($payload, 'offending_record_reference'),
            FiscalEventType::CHAIN_RESTART => $this->validateChainRestartSubArrays($payload),
            FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT => $this->validateListOfAssoc($payload, 'terminals', []),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateSaleReceiptSubArrays(array $payload): void
    {
        $this->validateListOfAssoc($payload, 'lines', ['unit_price', 'line_total']);
        $this->validateListOfAssoc($payload, 'vat_breakdown', ['base', 'amount']);
        $this->validateListOfAssoc($payload, 'payment_lines', ['amount', 'tendered', 'change']);
        $this->validateListOfAssoc($payload, 'voucher_redemptions', ['amount']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateChainRestartSubArrays(array $payload): void
    {
        $this->validateNonEmptyAssoc($payload, 'last_good_anchor');
        $this->validateNonEmptyAssoc($payload, 'operator_authorization_evidence');
        $this->validateNonEmptyAssoc($payload, 'provenance_link');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $monetaryStringFields  field names that, when present, must be string (bcformat)
     */
    private function validateListOfAssoc(array $payload, string $key, array $monetaryStringFields): void
    {
        $items = $payload[$key] ?? null;
        if (! is_array($items)) {
            // Defense — DTO::fromArray() already throws on non-array, but the
            // parser stays self-contained.
            throw new RuntimeException("$key must be an array; got ".get_debug_type($items));
        }
        // Empty list is acceptable (e.g. a receipt with no voucher redemptions).
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
            foreach ($monetaryStringFields as $field) {
                if (array_key_exists($field, $item) && ! is_string($item[$field])) {
                    throw new RuntimeException(sprintf(
                        '%s[%d].%s must be a CurrencyScale::bcformat() string; got %s',
                        $key,
                        $index,
                        $field,
                        get_debug_type($item[$field]),
                    ));
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
}
