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
