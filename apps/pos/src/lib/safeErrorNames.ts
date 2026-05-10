/**
 * Allowlist of error class names safe to surface to the cashier UI.
 *
 * The T0.1 banner-opacity contract requires that we never render the raw
 * `error.message` to the cashier — it can carry captive-portal HTML,
 * backend stack fragments, or vendor SDK formatting. We instead surface
 * the class name (`error.name`), but only when it is in this allowlist.
 * Any other name is coerced to the generic `'Error'` label.
 *
 * The allowlist is intentionally small and curated by hand:
 *   - `ApiRequestError` / `FetchTimeoutError` / `BootstrapTimeoutError` /
 *     `BootstrapAbortedError` are our own classes, semantically meaningful
 *     to engineering when included in structured logs and to the cashier
 *     as a coarse indication of what failed.
 *   - `AbortError` is a well-known DOM type produced by AbortController.
 *   - `TypeError` is the universal "network unreachable" signal in
 *     fetch-based APIs (browsers throw it on network failures).
 *   - `Error` is the catchall — every coerced value lands here.
 *
 * `BootstrapAbortedError` overrides `name = 'AbortError'` so it lands in
 * the allowlist transparently; we list `'AbortError'` here rather than
 * the class name. `BootstrapTimeoutError` keeps its own class name so
 * engineering can distinguish a phase-level timeout from a fetch-level
 * timeout (`FetchTimeoutError`).
 */
export const SAFE_ERROR_NAMES: ReadonlySet<string> = new Set([
  'ApiRequestError',
  'FetchTimeoutError',
  'BootstrapTimeoutError',
  'AbortError',
  'TypeError',
  'Error',
]);

/**
 * Coerce an unknown caught value into a label safe for the cashier UI.
 * Returns the class name when it is in the allowlist; falls back to the
 * generic `'Error'` label otherwise.
 */
export function safeErrorName(error: unknown): string {
  if (error instanceof Error && SAFE_ERROR_NAMES.has(error.name)) {
    return error.name;
  }
  return 'Error';
}
