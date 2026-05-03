<?php

declare(strict_types=1);

namespace App\Application\Sweep\Domain;

use App\Application\Sweep\InventoryService;
use InvalidArgumentException;

/**
 * Immutable typed wrapper around the parsed sweep-inventory YAML.
 *
 * Mutations always go through {@see InventoryService::mutate()}
 * which exposes a callable mutator that receives THIS document and must
 * return a NEW one. Direct mutation of the internal array is prevented by the
 * `with*()` builders that always return a fresh instance.
 *
 * The document is intentionally kept thin (a typed array view) — it does not
 * understand the schema beyond the locator helpers below. JSON Schema
 * validation is the InventoryService's responsibility.
 */
final class InventoryDocument
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function schemaVersion(): string
    {
        /** @var array<string, mixed> $metadata */
        $metadata = $this->data['metadata'];

        return (string) ($metadata['schema_version'] ?? '');
    }

    public function yamlSha256(): string
    {
        /** @var array<string, mixed> $metadata */
        $metadata = $this->data['metadata'];

        return (string) ($metadata['yaml_sha256'] ?? '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function clusters(): array
    {
        /** @var list<array<string, mixed>> $clusters */
        $clusters = $this->data['clusters'] ?? [];

        return $clusters;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function callsites(): array
    {
        /** @var list<array<string, mixed>> $callsites */
        $callsites = $this->data['callsites'] ?? [];

        return $callsites;
    }

    /**
     * Returns a fresh document where the callsite identified by $callsiteId
     * has been transformed by $mutator. The mutator receives the callsite's
     * raw associative array and must return the updated array (it MUST keep
     * the `id` field; modifying `id` is rejected).
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    public function withCallsiteUpdate(string $callsiteId, callable $mutator): self
    {
        $found = false;
        $next = $this->data;
        /** @var list<array<string, mixed>> $callsites */
        $callsites = $next['callsites'] ?? [];
        foreach ($callsites as $index => $callsite) {
            if (($callsite['id'] ?? null) !== $callsiteId) {
                continue;
            }
            $found = true;
            $updated = $mutator($callsite);
            if (($updated['id'] ?? null) !== $callsiteId) {
                throw new InvalidArgumentException(
                    "Mutator changed callsite id for {$callsiteId} (found '".(string) ($updated['id'] ?? '')."'); ".
                    'callsite ids are immutable.',
                );
            }
            $callsites[$index] = $updated;
        }

        if (! $found) {
            throw new InvalidArgumentException("Callsite id not found in document: {$callsiteId}");
        }
        $next['callsites'] = $callsites;

        return new self($next);
    }

    /**
     * Returns a fresh document with the entire callsites list replaced.
     * Used by SweepInventoryGenerateCommand when merging scanner output.
     *
     * @param  list<array<string, mixed>>  $callsites
     */
    public function withCallsites(array $callsites): self
    {
        $next = $this->data;
        $next['callsites'] = $callsites;

        return new self($next);
    }

    /**
     * Returns a fresh document with `metadata.yaml_sha256` set. The service
     * uses this internally; consumers should not call it directly.
     */
    public function withYamlSha256(string $sha256): self
    {
        $next = $this->data;
        /** @var array<string, mixed> $metadata */
        $metadata = $next['metadata'];
        $metadata['yaml_sha256'] = $sha256;
        $next['metadata'] = $metadata;

        return new self($next);
    }

    /**
     * Returns a fresh document with `metadata.generated_at` set. Useful for
     * regenerate runs.
     */
    public function withGeneratedAt(string $isoTimestamp): self
    {
        $next = $this->data;
        /** @var array<string, mixed> $metadata */
        $metadata = $next['metadata'];
        $metadata['generated_at'] = $isoTimestamp;
        $next['metadata'] = $metadata;

        return new self($next);
    }
}
