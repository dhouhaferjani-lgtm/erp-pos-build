<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\DTOs;

/**
 * Outcome of `StrictCanonicalParser::parse()`.
 *
 * The parser never throws on malformed canonical bytes — it returns a
 * failed `ParseResult` so the `OutboxIngestor` (spec v7 §7.2) can record
 * the failure as `payload = NULL, payload_parse_status = 'failed',
 * integrity_status = 'quarantined', integrity_exception_class =
 * 'canonical_parse_failure'` with the verbatim canonical bytes intact
 * (§8). Exceptions would hide the bytes; a typed result keeps both.
 *
 * `failureReason` is a machine-readable identifier with the format
 * `<snake_case_prefix>:<context>`, e.g. `duplicate_key:event_type`,
 * `out_of_grammar_number:fractional or exponent forbidden at offset 42`.
 * Stable prefixes let `OutboxIngestor` route on the class while the
 * trailer carries forensic context.
 */
final readonly class ParseResult
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $envelope
     */
    private function __construct(
        public bool $ok,
        public ?array $payload,
        public ?string $failureReason,
        public ?array $envelope,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $envelope
     */
    public static function ok(array $payload, array $envelope): self
    {
        return new self(true, $payload, null, $envelope);
    }

    public static function failure(string $reason): self
    {
        return new self(false, null, $reason, null);
    }
}
