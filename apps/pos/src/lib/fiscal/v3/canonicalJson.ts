/**
 * RFC 8785 / JSON Canonicalization Scheme (JCS) encoder.
 *
 * Produces byte-identical output for byte-identical input across TypeScript and
 * PHP implementations. Used for v3 receipt-hash payloads.
 *
 * Top-level entry points are `canonicalJson` for objects and `canonicalJsonList`
 * for arrays. We separate them because an empty array `[]` must not be silently
 * serialised as `{}` — type-forcing the caller to declare the top-level shape
 * prevents that ambiguity. This mirrors the PHP `encode()` / `encodeList()`
 * split in `CanonicalJsonEncoder.php`.
 */

/**
 * Encode an object as RFC 8785 canonical JSON.
 *
 * Use this for every top-level object payload. Do NOT use it for a top-level
 * list — use `canonicalJsonList` instead.
 */
export function canonicalJson(value: Record<string, unknown>): string {
  return encodeObject(value);
}

/**
 * Encode a list (array) as RFC 8785 canonical JSON.
 *
 * Use this for sub-lists such as payments, vat_breakdown, and
 * voucher_ledger_entries. Keeping it separate from `canonicalJson` prevents
 * an empty list from being serialised as `{}` rather than `[]`.
 */
export function canonicalJsonList(value: unknown[]): string {
  return encodeArray(value);
}

function encodeValue(value: unknown): string {
  if (value === null) {
    return 'null';
  }
  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }
  if (typeof value === 'number') {
    if (!Number.isInteger(value)) {
      throw new Error(
        `Non-integer number ${value} is not allowed in v3 canonical JSON. ` +
          'Decimal monetary values must be pre-formatted as strings.',
      );
    }
    return String(value);
  }
  if (typeof value === 'string') {
    return encodeString(value);
  }
  if (Array.isArray(value)) {
    return encodeArray(value);
  }
  if (typeof value === 'object') {
    return encodeObject(value as Record<string, unknown>);
  }
  throw new Error(`Unsupported value type: ${typeof value}`);
}

function encodeObject(value: Record<string, unknown>): string {
  const keys = Object.keys(value).sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
  const parts = keys.map((key) => encodeString(key) + ':' + encodeValue(value[key]));
  return '{' + parts.join(',') + '}';
}

function encodeArray(value: unknown[]): string {
  return '[' + value.map((v) => encodeValue(v)).join(',') + ']';
}

/**
 * Encode a single string as RFC 8785 canonical JSON.
 *
 * JS's `JSON.stringify` produces RFC-8259-compliant escapes with raw UTF-8 for
 * codepoints U+0080+ by default — matching PHP's
 * `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
 *
 * **Known PHP-vs-JS divergence**: PHP with `JSON_UNESCAPED_UNICODE` emits
 * U+2028 (LINE SEPARATOR) and U+2029 (PARAGRAPH SEPARATOR) raw, but
 * `JSON.stringify` escapes them as ` ` / ` `. This divergence is
 * acceptable because v3 input fields (UUIDs, receipt numbers, voucher codes,
 * override_reason) are not expected to contain these characters in practice.
 */
function encodeString(value: string): string {
  return JSON.stringify(value);
}
