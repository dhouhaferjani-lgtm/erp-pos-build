/**
 * Shared RFC 8785 / JSON Canonicalization Scheme (JCS) structural core.
 *
 * Two callers depend on the same structural rules — sorted object keys,
 * positional array order, integer-only numbers, and a recursive encoder
 * dispatched by `typeof`:
 *
 *   1. `lib/fiscal/v3/canonicalJson.ts` — receipt-V3 hash payloads. Has live
 *      production data; output must remain byte-identical to before the
 *      refactor. Uses an identity string normalizer (raw `JSON.stringify`).
 *
 *   2. `lib/fiscal/FiscalEventCanonicalEncoder.ts` — Phase 1 fiscal-event
 *      canonical encoder. Strips U+2028 / U+2029 and applies NFC at the
 *      producer (SoT v3 §4).
 *
 * The structural logic was previously duplicated; the only divergence
 * between the two callers is the per-string normalization step. This module
 * extracts the shared structural encoder and exposes a `normalize`
 * parameter to handle that divergence cleanly.
 *
 * Closes Task 5 deferred P2 — "extract a shared JCS core instead of
 * duplicating the canonicalization".
 */

export type StringNormalizer = (input: string) => string;

/** No-op string normalizer — passes the input through unchanged. */
export const identityNormalizer: StringNormalizer = (input) => input;

export class CanonicalEncodingError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'CanonicalEncodingError';
  }
}

/**
 * Encode any JCS-supported value (null / boolean / integer / string /
 * array / object) using the given string normalizer.
 *
 * Numbers MUST be integers — decimal monetary values are pre-formatted as
 * strings by the producer. Non-integer numbers throw
 * `CanonicalEncodingError`.
 */
export function encodeCanonicalValue(value: unknown, normalize: StringNormalizer): string {
  if (value === null) {
    return 'null';
  }
  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }
  if (typeof value === 'number') {
    if (!Number.isInteger(value)) {
      throw new CanonicalEncodingError(
        `Non-integer number ${value} is not allowed in canonical JSON. ` +
          'Decimal monetary values must be pre-formatted as strings.',
      );
    }
    return String(value);
  }
  if (typeof value === 'string') {
    return encodeCanonicalString(value, normalize);
  }
  if (Array.isArray(value)) {
    return encodeCanonicalList(value, normalize);
  }
  if (typeof value === 'object') {
    return encodeCanonicalObject(value as Record<string, unknown>, normalize);
  }
  throw new CanonicalEncodingError(`Unsupported value type: ${typeof value}`);
}

/**
 * Encode an object with sorted keys.
 *
 * Use this for every top-level object payload. Callers that need to encode
 * a top-level *array* must use `encodeCanonicalList` so an empty array does
 * not collapse to `{}`.
 */
export function encodeCanonicalObject(
  value: Record<string, unknown>,
  normalize: StringNormalizer,
): string {
  const keys = Object.keys(value).sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
  const parts = keys.map(
    (key) => encodeCanonicalString(key, normalize) + ':' + encodeCanonicalValue(value[key], normalize),
  );
  return '{' + parts.join(',') + '}';
}

/** Encode an array preserving positional order. */
export function encodeCanonicalList(value: unknown[], normalize: StringNormalizer): string {
  return '[' + value.map((v) => encodeCanonicalValue(v, normalize)).join(',') + ']';
}

/**
 * Encode a single string after applying the caller-supplied normalizer.
 *
 * JS's `JSON.stringify` emits RFC-8259-compliant escapes with raw UTF-8 for
 * codepoints U+0080+ by default — matching PHP's
 * `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
 *
 * **Known PHP-vs-JS divergence on U+2028 / U+2029:** PHP with
 * `JSON_UNESCAPED_UNICODE` emits these line-separator codepoints raw, while
 * `JSON.stringify` escapes them as ` ` / ` `. The fiscal-event
 * encoder defends against this by stripping the codepoints at the producer
 * via its normalizer (SoT v3 §4). The v3 receipt-hash encoder accepts the
 * divergence because the v3 input fields (UUIDs, receipt numbers, voucher
 * codes, override_reason) are not expected to contain these characters in
 * practice.
 */
function encodeCanonicalString(value: string, normalize: StringNormalizer): string {
  return JSON.stringify(normalize(value));
}
