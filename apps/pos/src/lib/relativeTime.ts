/**
 * Relative-time formatting shared by the Header's two freshness displays:
 * the SyncButton's "Last sync" affordance and the Task 12 `StockFreshness`
 * "Stock as of" hint. They sit side by side and must read the same way
 * ('<1m' / 'Xm' / 'Xh').
 */
export function formatRelativeTime(timestamp: number | null): string | null {
  if (!timestamp) return null;
  const diffMs = Date.now() - timestamp;
  const diffMin = Math.floor(diffMs / 60_000);

  if (diffMin < 1) return '<1m';
  if (diffMin < 60) return `${diffMin}m`;
  return `${Math.floor(diffMin / 60)}h`;
}

/**
 * Whether `timestamp` is older than `maxAgeMs` (FU-10 staleness check).
 * Mirrors {@link formatRelativeTime}'s `Date.now()`-based, re-render-cadenced
 * model — keeping the impure `Date.now()` read out of component render bodies
 * (react-hooks/purity) just as the formatter does.
 */
export function isOlderThan(timestamp: number | null, maxAgeMs: number): boolean {
  if (!timestamp) return false;
  return Date.now() - timestamp > maxAgeMs;
}
