<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Application\DTOs\FiscalEventEnvelope;
use App\Modules\Fiscal\Application\DTOs\ParseResult;
use App\Modules\Fiscal\Domain\DTOs\ZCashDrawerMovementPayload;
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
        'chain_context',
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

    private const OPERATIONAL_CHAIN_EVENT_TYPES = [
        'SALE_RECEIPT',
        'ACCOUNT_PAYMENT',
        'ACCOUNT_CHARGE',
        'ACCOUNT_STATUS_CHANGED',
        'CHAIN_BREAK_DETECTED',
        'CHAIN_RESTART',
        'TERMINAL_REGISTRY_SNAPSHOT',
        'OPERATOR_APPROVAL_GRANTED',
        'OVERRIDE_CREDIT_LIMIT',
        'OVERRIDE_ACCOUNT_STATUS',
        'OVERRIDE_DISCOUNT_LIMIT',
        'OVERRIDE_TENDER_TOLERANCE',
        'OVERRIDE_VOID_OR_RETURN',
        'CASH_OUT',
        'SAFE_DROP',
    ];

    private const Z_SESSION_CHAIN_EVENT_TYPES = [
        'SESSION_OPEN',
        'OPENING_FLOAT',
        'CASH_IN',
        'CASH_OUT',
        'SAFE_DROP',
        'CASH_CORRECTION',
        'SESSION_CLOSE',
        'X_REPORT',
        'Z_REPORT',
    ];

    private string $bytes = '';

    private int $pos = 0;

    private int $len = 0;

    /**
     * Round-2 (Task 24 Opus F2 / Codex T24-P1): the per-event constraint
     * validation surface was extracted into
     * `FiscalPayloadConstraintValidator` so the parser AND
     * `ParseFailureResolutionService` share ONE source of truth on
     * what a trusted payload looks like. Without the extraction, the
     * resolver only ran the DTO's top-level type checks — a real
     * correctness gap.
     *
     * Round-3 (Codex T24-P1): the validator is a REQUIRED constructor
     * parameter — no nullable fallback, no `new` in the constructor body.
     * CLAUDE.md rule 13 ("Constructor injection only — never use `app()`")
     * applies equivalently to `new` in the constructor body for
     * dependencies that should be container-resolved. Test call sites
     * must construct the validator explicitly (it is a pure-function
     * class with no dependencies of its own, so `new FiscalPayloadConstraintValidator`
     * in test code is trivial).
     */
    public function __construct(
        private readonly FiscalEventPayloadRegistry $registry,
        private readonly FiscalPayloadConstraintValidator $constraintValidator,
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
            $supportedVersions = $this->registry->supportedVersionsFor($type);
        } catch (FiscalEventTypeNotImplemented) {
            return ParseResult::failure('event_type_unimplemented:'.$type->value);
        }

        $envelopeError = $this->validateEnvelopeShape($envelope, $supportedVersions);
        if ($envelopeError !== null) {
            return ParseResult::failure($envelopeError);
        }

        /** @var int $eventVersion validated against the registry set above */
        $eventVersion = $envelope['event_version'];

        /** @var array<string, mixed> $payload */
        $payload = $envelope['payload'];
        $chainContext = $envelope['chain_context'];
        if (! is_string($chainContext)) {
            return ParseResult::failure('envelope_chain_context_invalid:got='.get_debug_type($chainContext));
        }

        $contextError = $this->validateChainContext($type, $chainContext, $payload);
        if ($contextError !== null) {
            return ParseResult::failure($contextError);
        }

        try {
            $effectiveDtoClass = $this->dtoClassForEnvelope($type, $chainContext, $dtoClass);
            $effectiveDtoClass::fromArray($payload);
        } catch (Throwable $e) {
            return ParseResult::failure('schema_violation:'.$e->getMessage());
        }

        $extrasError = $this->constraintValidator->validatePayloadKeySet($type, $payload, $chainContext, $eventVersion);
        if ($extrasError !== null) {
            return ParseResult::failure($extrasError);
        }

        try {
            $this->constraintValidator->validatePerEventConstraints($type, $payload, $chainContext, $eventVersion);
        } catch (RuntimeException $e) {
            return ParseResult::failure('sub_array_shape:'.$e->getMessage());
        }

        return ParseResult::ok($payload, $envelope);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateChainContext(
        FiscalEventType $type,
        string $chainContext,
        array $payload,
    ): ?string {
        $isZSessionContext = in_array($chainContext, ['z_session', 'training_z_session'], true);
        $allowedTypes = $isZSessionContext
            ? self::Z_SESSION_CHAIN_EVENT_TYPES
            : self::OPERATIONAL_CHAIN_EVENT_TYPES;

        if (! in_array($type->value, $allowedTypes, true)) {
            return sprintf(
                'envelope_chain_context_event_type_mismatch:event_type=%s,chain_context=%s',
                $type->value,
                $chainContext,
            );
        }

        $trainingFlag = $payload['training_flag'] ?? null;
        if (! is_bool($trainingFlag)) {
            return null;
        }

        $isTrainingContext = in_array($chainContext, ['training_operational', 'training_z_session'], true);
        if ($trainingFlag && ! $isTrainingContext) {
            return 'envelope_chain_context_training_mismatch:training_flag=true requires training context; got '.$chainContext;
        }
        if (! $trainingFlag && $isTrainingContext) {
            return 'envelope_chain_context_training_mismatch:training_flag=false requires production context; got '.$chainContext;
        }

        return null;
    }

    /**
     * @param  class-string  $registryDtoClass
     * @return class-string
     */
    private function dtoClassForEnvelope(
        FiscalEventType $type,
        string $chainContext,
        string $registryDtoClass,
    ): string {
        if (
            in_array($type, [FiscalEventType::CASH_OUT, FiscalEventType::SAFE_DROP], true)
            && in_array($chainContext, ['z_session', 'training_z_session'], true)
        ) {
            return ZCashDrawerMovementPayload::class;
        }

        return $registryDtoClass;
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
    /**
     * @param  array<string, mixed>  $envelope
     * @param  list<int>  $supportedEventVersions
     */
    private function validateEnvelopeShape(array $envelope, array $supportedEventVersions): ?string
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

        // event_version: positive int in the registry's supported set. The
        // registry is the source of truth; an unsupported version means
        // either a device drift or an unknown event flavor. Historical
        // versions stay supported forever (Events are Immutable Forever).
        if (! is_int($envelope['event_version']) || $envelope['event_version'] < 1) {
            return 'envelope_event_version_invalid:got='.var_export($envelope['event_version'], true);
        }
        if (! in_array($envelope['event_version'], $supportedEventVersions, true)) {
            return sprintf(
                'envelope_event_version_mismatch:expected=%s,envelope=%d',
                implode('|', $supportedEventVersions),
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

        if (! is_string($envelope['chain_context']) || ! in_array($envelope['chain_context'], FiscalEventEnvelope::CHAIN_CONTEXTS, true)) {
            return 'envelope_chain_context_invalid:got='.var_export($envelope['chain_context'], true);
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

    // -----------------------------------------------------------------
    // Per-event constraints + payload key set validation moved into
    // `FiscalPayloadConstraintValidator` (round-2 Task 24 Opus F2 /
    // Codex T24-P1). The validator is constructor-injected so the
    // resolver shares the same constraint surface — see this class's
    // constructor docblock.
    // -----------------------------------------------------------------
}
