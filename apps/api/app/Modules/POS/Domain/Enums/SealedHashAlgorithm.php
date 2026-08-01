<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

/**
 * `pos_receipts.sealed_hash_algorithm` discriminator — v3-refund-chain-
 * integration spec §6. Names which hash pipeline actually sealed a given
 * row, so the verifier (`ReceiptHashService`, `Nf525DataProvider`) can pick
 * the matching re-verification arm instead of inferring it from
 * `fiscal_schema_version` alone.
 *
 * Deliberately NOT an Eloquent cast on `Receipt::casts()` (spec §6.4: "a
 * plain nullable string, needing no cast entry") — this enum is the
 * write-side source of truth for the literal values, avoiding magic
 * strings (rule 9) without adding a cast the spec explicitly says the
 * column doesn't need.
 */
enum SealedHashAlgorithm: string
{
    /** v2 schema — `ReceiptHashService::calculateHash()`'s legacy pipe-separated format. */
    case LegacyPipeV1 = 'legacy_pipe_v1';

    /** v3 schema — `V3ReceiptHashComputer`'s canonical-JSON SHA-256 format. */
    case CanonicalJsonV3 = 'canonical_json_v3';
}
