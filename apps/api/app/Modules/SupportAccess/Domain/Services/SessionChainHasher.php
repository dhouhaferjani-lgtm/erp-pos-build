<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Services;

use JsonException;

final class SessionChainHasher
{
    /** @param array<string, mixed> $event */
    public function hash(array $event): string
    {
        $canonical = [
            'version' => $event['version'] ?? 1,
            'event_id' => $event['event_id'] ?? null,
            'session_id' => $event['session_id'] ?? null,
            'sequence' => $event['sequence'] ?? null,
            'previous_hash' => $event['previous_hash'] ?? null,
            'event_type' => $event['event_type'] ?? null,
            'outcome' => $event['outcome'] ?? null,
            'operator_id' => $event['operator_id'] ?? null,
            'subject_user_id' => $event['subject_user_id'] ?? null,
            'tenant_id' => $event['tenant_id'] ?? null,
            'request_id' => $event['request_id'] ?? null,
            'http_method' => $event['http_method'] ?? null,
            'path' => $event['path'] ?? null,
            'details' => $event['details'] ?? [],
            'occurred_at' => $event['occurred_at'] ?? null,
        ];
        $this->sortRecursively($canonical);

        try {
            $serialized = json_encode(
                $canonical,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new JsonException('Impersonation audit event is not canonically serializable.', previous: $exception);
        }

        return hash('sha256', $serialized);
    }

    /** @param array<mixed> $value */
    private function sortRecursively(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
    }
}
