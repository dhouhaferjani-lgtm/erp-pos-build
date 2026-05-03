<?php

declare(strict_types=1);

namespace App\Application\Sweep;

use App\Application\Sweep\Domain\InventoryDocument;
use App\Application\Sweep\Domain\MutationContext;
use App\Application\Sweep\Domain\MutationResult;
use App\Application\Sweep\Exceptions\InventorySchemaViolationException;
use App\Application\Sweep\Exceptions\OptimisticLockException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Atomic mutator for the tenant-isolation sweep inventory YAML.
 *
 * Master plan reference: 2026-05-02-tenant-isolation-master-plan.md
 *   Section 4 — schema v2 + optimistic concurrency + history chain.
 *   Section 5 — scanners + artisan commands consume this service.
 *
 * Lifecycle of {@see self::mutate()}:
 *   1. Acquire flock LOCK_EX on the inventory file.
 *   2. Re-read; verify the on-disk yaml's metadata.yaml_sha256 matches the
 *      hash we recompute from the file content (with yaml_sha256 zeroed) AND
 *      matches the hash we exposed via the most recent load(). Mismatch →
 *      OptimisticLockException.
 *   3. Pass the parsed InventoryDocument to the caller's mutator.
 *   4. Stamp every newly-appended history event with previous_yaml_sha256 +
 *      new_yaml_sha256 + (if missing) the commit / actor / command from
 *      MutationContext.
 *   5. Validate the post-mutation document against the JSON Schema. Failure →
 *      InventorySchemaViolationException — file on disk untouched.
 *   6. Compute the new yaml_sha256 (serialize with the field zeroed → hash →
 *      set the field → re-serialize for write).
 *   7. Tempfile + fsync + atomic rename.
 *   8. Release lock.
 *
 * The optimistic-lock check guards against external hand-edits between two
 * load() calls within the same process AND against another writer who racily
 * grabbed the file before we did. CI hand-edit detection is a separate concern
 * (see SweepInventoryVerifyHistoryCommand in Phase 2).
 */
final class InventoryService
{
    private const HASH_ZERO = '0000000000000000000000000000000000000000000000000000000000000000';

    private const SCHEMA_REGISTRY_URI = 'urn:tenant-isolation-sweep-inventory-v2';

    private const YAML_DUMP_INDENT = 2;

    private const YAML_DUMP_INLINE_LEVEL = 8;

    public function __construct(
        private readonly string $inventoryAbsolutePath,
        private readonly string $schemaAbsolutePath,
    ) {}

    public function load(): InventoryDocument
    {
        $contents = $this->readFileOrFail($this->inventoryAbsolutePath);

        return new InventoryDocument($this->parseYaml($contents));
    }

    /**
     * @param  callable(InventoryDocument): InventoryDocument  $mutator
     */
    public function mutate(callable $mutator, MutationContext $context): MutationResult
    {
        $handle = fopen($this->inventoryAbsolutePath, 'cb+');
        if ($handle === false) {
            throw new RuntimeException("Cannot open inventory file: {$this->inventoryAbsolutePath}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Failed to acquire exclusive lock on inventory file.');
            }

            // Re-read inside the lock so we have a coherent baseline regardless of
            // whatever caller did with their previous load() result.
            $beforeContents = stream_get_contents($handle);
            if ($beforeContents === false) {
                throw new RuntimeException('Failed to read inventory file inside lock.');
            }
            $beforeArray = $this->parseYaml($beforeContents);
            /** @var array<string, mixed> $beforeMetadata */
            $beforeMetadata = $beforeArray['metadata'];
            $storedHash = (string) ($beforeMetadata['yaml_sha256'] ?? '');
            $recomputedHash = $this->computeHashOfArray($beforeArray);

            if ($storedHash !== $recomputedHash) {
                throw new OptimisticLockException(
                    "Inventory file's stored yaml_sha256 ({$storedHash}) does not match its content hash ({$recomputedHash}). ".
                    'Another writer or hand-edit modified the file outside the service. Re-load and retry.',
                );
            }

            $beforeDoc = new InventoryDocument($beforeArray);
            $afterDoc = $mutator($beforeDoc);
            if (! $afterDoc instanceof InventoryDocument) { /* @phpstan-ignore-line */
                throw new RuntimeException('Mutator must return an InventoryDocument.');
            }

            // Defensive re-check: a concurrent writer (or the mutator itself
            // misbehaving) could have edited the on-disk file between our
            // initial read and now. flock() is advisory on POSIX so a
            // co-operating second writer could still slip through. Hash the
            // current on-disk content and compare to the baseline.
            $postMutatorContents = (string) file_get_contents($this->inventoryAbsolutePath);
            if (hash('sha256', $postMutatorContents) !== hash('sha256', $beforeContents)) {
                throw new OptimisticLockException(
                    'Inventory file changed on disk while the mutator was running. '.
                    'Aborting to avoid clobbering concurrent edits. Re-load and retry.',
                );
            }

            // Count caller-appended events across all callsites and stamp them.
            $stampingResult = $this->stampNewHistoryEvents(
                beforeDoc: $beforeDoc,
                afterDoc: $afterDoc,
                context: $context,
            );
            $stampedDoc = $stampingResult['document'];
            $eventsAppended = $stampingResult['count'];

            // Chain hashes do not affect the canonical content hash (they're
            // zeroed via canonicalForHashing). So we compute the new hash
            // ONCE on the stamped document, then fill chain hashes on the
            // newly-appended events with previous=storedHash, new=finalHash.
            $finalHash = $this->computeHashOfDocument($stampedDoc);
            $stampedDoc = $this->fillChainHashesOnNewEvents(
                beforeDoc: $beforeDoc,
                afterDoc: $stampedDoc,
                previousHash: $storedHash,
                newHash: $finalHash,
            );
            $stampedDoc = $stampedDoc->withYamlSha256($finalHash);

            // Schema-validate BEFORE write.
            $this->validateOrFail($stampedDoc);

            // Short-circuit: if nothing changed (no caller events, hash same),
            // skip the write entirely. Useful for no-op verify runs.
            if ($finalHash === $storedHash && $eventsAppended === 0) {
                return new MutationResult(
                    previousYamlSha256: $storedHash,
                    newYamlSha256: $finalHash,
                    eventsAppended: 0,
                );
            }

            $this->atomicWrite($stampedDoc);

            return new MutationResult(
                previousYamlSha256: $storedHash,
                newYamlSha256: $finalHash,
                eventsAppended: $eventsAppended,
            );
        } finally {
            // flock release happens on close; release explicitly first so we
            // surface any release error before fclose swallows it.
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Stamp newly-appended history events with the actor/action/command/commit
     * from MutationContext when those fields are null on the event.
     *
     * @return array{document: InventoryDocument, count: int}
     */
    private function stampNewHistoryEvents(
        InventoryDocument $beforeDoc,
        InventoryDocument $afterDoc,
        MutationContext $context,
    ): array {
        $beforeCounts = $this->callsiteHistoryCounts($beforeDoc);
        $afterCallsites = $afterDoc->callsites();
        $count = 0;

        foreach ($afterCallsites as $index => $callsite) {
            $id = (string) ($callsite['id'] ?? '');
            $beforeLen = $beforeCounts[$id] ?? 0;
            /** @var list<array<string, mixed>> $history */
            $history = $callsite['history'] ?? [];
            $afterLen = count($history);
            if ($afterLen <= $beforeLen) {
                continue;
            }

            for ($i = $beforeLen; $i < $afterLen; $i++) {
                /** @var array<string, mixed> $event */
                $event = $history[$i];
                if (($event['actor'] ?? null) === null) {
                    $event['actor'] = $context->actor;
                }
                if (($event['action'] ?? null) === null) {
                    $event['action'] = $context->action;
                }
                if (($event['command'] ?? null) === null) {
                    $event['command'] = $context->command;
                }
                if (($event['commit'] ?? null) === null) {
                    $event['commit'] = $context->gitCommit;
                }
                $history[$i] = $event;
                $count++;
            }
            $callsite['history'] = $history;
            $afterCallsites[$index] = $callsite;
        }

        return [
            'document' => $afterDoc->withCallsites($afterCallsites),
            'count' => $count,
        ];
    }

    private function fillChainHashesOnNewEvents(
        InventoryDocument $beforeDoc,
        InventoryDocument $afterDoc,
        string $previousHash,
        string $newHash,
    ): InventoryDocument {
        $beforeCounts = $this->callsiteHistoryCounts($beforeDoc);
        $afterCallsites = $afterDoc->callsites();

        foreach ($afterCallsites as $index => $callsite) {
            $id = (string) ($callsite['id'] ?? '');
            $beforeLen = $beforeCounts[$id] ?? 0;
            /** @var list<array<string, mixed>> $history */
            $history = $callsite['history'] ?? [];
            $afterLen = count($history);
            if ($afterLen <= $beforeLen) {
                continue;
            }
            for ($i = $beforeLen; $i < $afterLen; $i++) {
                /** @var array<string, mixed> $event */
                $event = $history[$i];
                $event['previous_yaml_sha256'] = $previousHash;
                $event['new_yaml_sha256'] = $newHash;
                $history[$i] = $event;
            }
            $callsite['history'] = $history;
            $afterCallsites[$index] = $callsite;
        }

        return $afterDoc->withCallsites($afterCallsites);
    }

    /**
     * @return array<string, int>
     */
    private function callsiteHistoryCounts(InventoryDocument $doc): array
    {
        $out = [];
        foreach ($doc->callsites() as $callsite) {
            $id = (string) ($callsite['id'] ?? '');
            /** @var list<array<string, mixed>> $history */
            $history = $callsite['history'] ?? [];
            $out[$id] = count($history);
        }

        return $out;
    }

    private function validateOrFail(InventoryDocument $doc): void
    {
        $schemaContents = $this->readFileOrFail($this->schemaAbsolutePath);

        $validator = new Validator;
        $resolver = $validator->resolver();
        if ($resolver !== null) {
            $resolver->registerRaw($schemaContents, self::SCHEMA_REGISTRY_URI);
        }

        // Opis validator wants object-shaped input.
        $rawObject = json_decode((string) json_encode($doc->toArray()), false, 512, JSON_THROW_ON_ERROR);
        $result = $validator->validate($rawObject, self::SCHEMA_REGISTRY_URI);

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        $messages = [];
        if ($error !== null) {
            $formatter = new ErrorFormatter;
            foreach ($formatter->formatFlat($error) as $message) {
                $messages[] = (string) $message;
            }
        }
        throw new InventorySchemaViolationException(
            'Inventory schema validation failed: '.implode('; ', $messages),
        );
    }

    private function atomicWrite(InventoryDocument $doc): void
    {
        $serialized = $this->dumpYaml($doc->toArray());
        $dir = dirname($this->inventoryAbsolutePath);
        $temp = tempnam($dir, '.inventory-');
        if ($temp === false) {
            throw new RuntimeException("Failed to create tempfile in {$dir}");
        }
        try {
            $bytes = file_put_contents($temp, $serialized);
            if ($bytes === false) {
                throw new RuntimeException("Failed to write tempfile {$temp}");
            }
            $tempHandle = fopen($temp, 'rb+');
            if ($tempHandle !== false) {
                // Best-effort fsync; some filesystems don't support it but
                // the rename below is still atomic on POSIX.
                if (function_exists('fsync')) {
                    @fsync($tempHandle);
                }
                fclose($tempHandle);
            }
            if (! rename($temp, $this->inventoryAbsolutePath)) {
                throw new RuntimeException("Failed to atomically rename tempfile to {$this->inventoryAbsolutePath}");
            }
        } catch (Throwable $t) {
            if (is_file($temp)) {
                @unlink($temp);
            }
            throw $t;
        }
    }

    private function readFileOrFail(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseYaml(string $contents): array
    {
        $parsed = Yaml::parse($contents);
        if (! is_array($parsed)) {
            throw new RuntimeException('Inventory YAML must parse to an associative array.');
        }
        /** @var array<string, mixed> $parsed */

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function dumpYaml(array $document): string
    {
        return Yaml::dump(
            $document,
            self::YAML_DUMP_INLINE_LEVEL,
            self::YAML_DUMP_INDENT,
            Yaml::DUMP_OBJECT_AS_MAP,
        );
    }

    private function computeHashOfDocument(InventoryDocument $doc): string
    {
        return $this->computeHashOfArray($doc->toArray());
    }

    /**
     * Canonical hash: zero-out the self-referential fields (metadata.yaml_sha256
     * and every history event's previous_yaml_sha256 / new_yaml_sha256), dump
     * YAML with stable settings, sha256 the resulting bytes. The chain hash
     * fields are excluded because they reference the document hash itself —
     * including them would make hash computation a fixed-point problem with
     * no closed-form solution. The verify-history artisan command (Phase 2)
     * separately walks the chain semantically to detect tampering.
     *
     * The same algorithm is used at write-time (to fill metadata.yaml_sha256)
     * and at load-time (to verify the on-disk hash matches the content).
     *
     * @param  array<string, mixed>  $document
     */
    private function computeHashOfArray(array $document): string
    {
        return hash('sha256', $this->dumpYaml($this->canonicalForHashing($document)));
    }

    /**
     * Returns the document with self-referential fields zeroed for hashing.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function canonicalForHashing(array $document): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = $document['metadata'];
        $metadata['yaml_sha256'] = self::HASH_ZERO;
        $document['metadata'] = $metadata;

        /** @var list<array<string, mixed>> $callsites */
        $callsites = $document['callsites'] ?? [];
        foreach ($callsites as $cIdx => $callsite) {
            /** @var list<array<string, mixed>> $history */
            $history = $callsite['history'] ?? [];
            foreach ($history as $hIdx => $event) {
                if (array_key_exists('previous_yaml_sha256', $event)) {
                    $event['previous_yaml_sha256'] = null;
                }
                if (array_key_exists('new_yaml_sha256', $event)) {
                    $event['new_yaml_sha256'] = null;
                }
                $history[$hIdx] = $event;
            }
            $callsite['history'] = $history;
            $callsites[$cIdx] = $callsite;
        }
        $document['callsites'] = $callsites;

        return $document;
    }
}
