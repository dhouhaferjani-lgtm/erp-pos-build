/**
 * RFC 8785 / JSON Canonicalization Scheme (JCS) encoder for receipt-V3 hash
 * payloads.
 *
 * Produces byte-identical output for byte-identical input across TypeScript
 * and PHP implementations. The receipt-V3 chain has live production data,
 * so the output of these functions is contract-frozen — do not change
 * unless you're prepared to re-hash every existing chain head.
 *
 * The structural rules (sorted keys, positional arrays, integer-only
 * numbers, JSON.stringify-based string escaping) are shared with the
 * Phase 1 fiscal-event canonical encoder via `../canonicalCore.ts`. This
 * module supplies the receipt-V3-specific entry points + the identity
 * string normalizer (no NFC, no U+2028/U+2029 stripping — preserving the
 * pre-Phase-1 behavior).
 *
 * Top-level entry points are `canonicalJson` for objects and
 * `canonicalJsonList` for arrays. We separate them because an empty array
 * `[]` must not be silently serialised as `{}` — type-forcing the caller
 * to declare the top-level shape prevents that ambiguity. This mirrors the
 * PHP `encode()` / `encodeList()` split in `CanonicalJsonEncoder.php`.
 */

import {
  encodeCanonicalList,
  encodeCanonicalObject,
  identityNormalizer,
} from '../canonicalCore';

/**
 * Encode an object as RFC 8785 canonical JSON.
 *
 * Use this for every top-level object payload. Do NOT use it for a top-level
 * list — use `canonicalJsonList` instead.
 */
export function canonicalJson(value: Record<string, unknown>): string {
  return encodeCanonicalObject(value, identityNormalizer);
}

/**
 * Encode a list (array) as RFC 8785 canonical JSON.
 *
 * Use this for sub-lists such as payments, vat_breakdown, and
 * voucher_ledger_entries. Keeping it separate from `canonicalJson` prevents
 * an empty list from being serialised as `{}` rather than `[]`.
 */
export function canonicalJsonList(value: unknown[]): string {
  return encodeCanonicalList(value, identityNormalizer);
}
