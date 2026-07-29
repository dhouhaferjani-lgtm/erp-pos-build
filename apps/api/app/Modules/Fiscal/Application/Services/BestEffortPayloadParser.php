<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Application\DTOs\BestEffortParseResult;
use App\Modules\Fiscal\Application\DTOs\ParseDefect;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use JsonException;
use RuntimeException;
use Throwable;

final class BestEffortPayloadParser
{
    public function __construct(
        private readonly StrictCanonicalParser $strictParser,
        private readonly FiscalEventPayloadRegistry $registry,
        private readonly FiscalPayloadConstraintValidator $constraintValidator,
    ) {}

    public function parse(string $canonicalBytes, FiscalEventType $eventType): BestEffortParseResult
    {
        $strict = $this->strictParser->parse($canonicalBytes, $eventType);
        if ($strict->ok && $strict->payload !== null) {
            return new BestEffortParseResult($strict->payload, []);
        }

        $defects = [];
        if ($strict->failureReason !== null) {
            $defects[] = new ParseDefect(
                path: 'canonical_bytes',
                code: $this->reasonCode($strict->failureReason),
                message: $strict->failureReason,
            );
        }

        try {
            $decoded = json_decode($canonicalBytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $defects[] = new ParseDefect('canonical_bytes', 'invalid_json', $e->getMessage());

            return new BestEffortParseResult([], $this->uniqueDefects($defects));
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $defects[] = new ParseDefect('canonical_bytes', 'envelope_not_object', 'Canonical bytes did not decode to an object envelope.');

            return new BestEffortParseResult([], $this->uniqueDefects($defects));
        }

        /** @var array<string, mixed> $envelope */
        $envelope = $decoded;
        $payload = $envelope['payload'] ?? null;
        if (! is_array($payload) || array_is_list($payload)) {
            $defects[] = new ParseDefect('payload', 'payload_not_object', 'Canonical envelope payload is missing or is not an object.');

            return new BestEffortParseResult([], $this->uniqueDefects($defects));
        }

        /** @var array<string, mixed> $payload */
        // Validate against the envelope's OWN event_version (M4 Codex P2-1)
        // so a v2 SALE_RECEIPT in the repair path is not mis-flagged with
        // v1 line-item defects, and a v3 payload is not mis-flagged as
        // carrying extra fields. Unparseable versions fall back to 1.
        $eventVersion = is_int($envelope['event_version'] ?? null) ? $envelope['event_version'] : 1;
        $parsed = $this->expectedPayloadFields($eventType, $payload, $eventVersion);
        $defects = array_merge($defects, $this->keySetDefects($eventType, $payload, $eventVersion));
        $defects = array_merge($defects, $this->schemaDefects($eventType, $payload, $eventVersion));

        return new BestEffortParseResult($parsed, $this->uniqueDefects($defects));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function expectedPayloadFields(FiscalEventType $eventType, array $payload, int $eventVersion): array
    {
        $expected = $this->constraintValidator->payloadKeysFor($eventType, $eventVersion) ?? [];
        $parsed = [];
        foreach ($expected as $key) {
            if (array_key_exists($key, $payload)) {
                $parsed[$key] = $payload[$key];
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ParseDefect>
     */
    private function keySetDefects(FiscalEventType $eventType, array $payload, int $eventVersion): array
    {
        $expected = $this->constraintValidator->payloadKeysFor($eventType, $eventVersion);
        if ($expected === null) {
            return [new ParseDefect('event_type', 'event_type_unimplemented', 'No payload contract is registered for '.$eventType->value.'.')];
        }

        $defects = [];
        foreach (array_diff($expected, array_keys($payload)) as $missing) {
            $defects[] = new ParseDefect('payload.'.$missing, 'payload_missing_required', 'Required payload field is missing.');
        }
        foreach (array_diff(array_keys($payload), $expected) as $extra) {
            $defects[] = new ParseDefect('payload.'.$extra, 'payload_extra_field', 'Payload field is not part of the canonical contract.');
        }

        return $defects;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ParseDefect>
     */
    private function schemaDefects(FiscalEventType $eventType, array $payload, int $eventVersion = 1): array
    {
        $defects = [];

        try {
            $dtoClass = $this->registry->dtoClassFor($eventType);
            $dtoClass::fromArray($payload);
        } catch (Throwable $e) {
            $defects[] = new ParseDefect(
                $this->inferPayloadPath($e->getMessage(), $eventType, $eventVersion),
                'schema_violation',
                $e->getMessage(),
            );
        }

        try {
            $this->constraintValidator->validatePerEventConstraints($eventType, $payload, 'operational', $eventVersion);
        } catch (RuntimeException $e) {
            $defects[] = new ParseDefect(
                $this->inferPayloadPath($e->getMessage(), $eventType, $eventVersion),
                $this->reasonCode($e->getMessage()),
                $e->getMessage(),
            );
        }

        return $defects;
    }

    private function reasonCode(string $reason): string
    {
        $prefix = strstr($reason, ':', true);

        return $prefix === false || $prefix === '' ? 'parse_failure' : $prefix;
    }

    private function inferPayloadPath(string $message, FiscalEventType $eventType, int $eventVersion = 1): string
    {
        $expected = $this->constraintValidator->payloadKeysFor($eventType, $eventVersion) ?? [];
        foreach ($expected as $key) {
            if (str_contains($message, $key)) {
                return 'payload.'.$key;
            }
        }

        if (preg_match('/(?:field|key) "([^"]+)"/', $message, $matches) === 1) {
            return 'payload.'.$matches[1];
        }

        return 'payload';
    }

    /**
     * @param  list<ParseDefect>  $defects
     * @return list<ParseDefect>
     */
    private function uniqueDefects(array $defects): array
    {
        $seen = [];
        $unique = [];
        foreach ($defects as $defect) {
            $key = $defect->path.'|'.$defect->code.'|'.$defect->message;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $defect;
        }

        return $unique;
    }
}
