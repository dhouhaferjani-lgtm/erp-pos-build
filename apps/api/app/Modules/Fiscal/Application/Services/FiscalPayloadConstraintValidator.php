<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use LogicException;
use RuntimeException;

/**
 * Per-event payload constraint validator — spec v7 §7.6.
 *
 * Extracted from `StrictCanonicalParser` round-2 (Task 24 Opus F2 +
 * Codex T24-P1 convergent finding) so the SAME constraint surface gates
 * BOTH the canonical-bytes parse path (`StrictCanonicalParser::parse()`)
 * AND the operator-supplied corrected payload path
 * (`ParseFailureResolutionService::resolve()`).
 *
 * Before the round-2 extraction, the resolver validated corrected
 * payloads only through `SaleReceiptPayload::fromArray()` top-level
 * type checks (string / int / array). That left a real correctness gap
 * documented by Opus F2: an operator's "corrected" payload could pass
 * DTO validation yet violate every §4 grammar invariant the original
 * `canonical_bytes` had to satisfy — extra payload keys, money strings
 * at the wrong currency scale, sub-arrays as objects instead of lists,
 * non-hex hash fields. Spec §7.6 calls the strict parser "the
 * authority on what constitutes a trusted payload" — the resolver
 * writes the payload column downstream projectors read, so the
 * validation surface MUST match the parser's.
 *
 * **Per-event clauses currently implemented (Phase 1):**
 *   - SALE_RECEIPT — money fields scale-aware, sub-array container is a
 *     list, sub-array items are non-empty assoc objects with monetary
 *     subfields validated against scale regex.
 *   - CHAIN_BREAK_DETECTED — `last_good_hash` 64-char lowercase hex;
 *     `offending_record_reference` non-empty assoc; if it carries
 *     `observed_previous_hash`, that's a hash.
 *   - CHAIN_RESTART — `new_genesis_reference` hash; three non-empty
 *     assoc structures; nested `last_good_anchor.hash` validated when
 *     present.
 *   - TERMINAL_REGISTRY_SNAPSHOT — `snapshot_hash` + optional
 *     `prior_snapshot_link` (hash when non-null); `terminals` list.
 *
 * **Default arm `throw new LogicException`** — adding a new
 * Phase 1 FiscalEventType without a matching clause here is a planning
 * defect, not a runtime data anomaly. Mirrors the parser's own
 * Opus P2-3 round-2 fix.
 *
 * **Throws** `RuntimeException` on constraint violation. The parser
 * wraps this as `sub_array_shape:<message>` per its existing
 * `ParseResult::failure()` discipline; the resolver wraps it as
 * `InvalidCorrectedPayloadException` so the caller sees a typed
 * boundary error.
 */
final class FiscalPayloadConstraintValidator
{
    /** 64-char lowercase hex — spec §4 hash format. */
    private const LOWER_HEX_64 = '/^[0-9a-f]{64}$/D';

    /**
     * Expected payload key set per event type. Round-2 mirror of
     * `StrictCanonicalParser::PAYLOAD_KEYS` — extracted here so the
     * resolver enforces the SAME extras-rejection rule.
     *
     * @var array<value-of<FiscalEventType>, list<string>>
     */
    public const PAYLOAD_KEYS = [
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

    /**
     * Validate the payload's key set against the per-event expected keys.
     * Returns a kebab/snake-case prefix-tagged failure reason string when
     * the payload carries extra keys; null when the key set is clean.
     *
     * This is the moral equivalent of
     * `StrictCanonicalParser::validatePayloadKeySet` — extracted so the
     * parser AND the resolver share one source of truth.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validatePayloadKeySet(FiscalEventType $type, array $payload): ?string
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

    /**
     * Validate the per-event-type constraints on the structured payload
     * (money format, sub-array shape, hash format). Throws
     * `RuntimeException` on violation — callers wrap the throw as their
     * boundary-appropriate error.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException on constraint violation
     * @throws LogicException when invoked with a Phase 1 event type that
     *                        has no matching per-event clause (planning defect)
     */
    public function validatePerEventConstraints(FiscalEventType $type, array $payload): void
    {
        match ($type) {
            FiscalEventType::SALE_RECEIPT => $this->validateSaleReceiptPayload($payload),
            FiscalEventType::CHAIN_BREAK_DETECTED => $this->validateChainBreakDetectedPayload($payload),
            FiscalEventType::CHAIN_RESTART => $this->validateChainRestartPayload($payload),
            FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT => $this->validateTerminalRegistrySnapshotPayload($payload),
            default => throw new LogicException(
                'FiscalPayloadConstraintValidator missing per-event clause for FiscalEventType::'.$type->name
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateSaleReceiptPayload(array $payload): void
    {
        $scale = $payload['currency_scale'] ?? null;
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
