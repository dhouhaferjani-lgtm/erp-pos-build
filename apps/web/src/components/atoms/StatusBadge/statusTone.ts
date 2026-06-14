import type { StatusTone } from './StatusBadge'

/**
 * Built-in status → tone mapping. Keys are lowercase; matched against the
 * lowercased input status.
 *
 * Kept in its own module (not StatusBadge.tsx) so the component file only
 * exports components — react-refresh requires that for Fast Refresh to work.
 */
const STATUS_TONE_MAP: Record<string, StatusTone> = {
  // success
  active: 'success',
  completed: 'success',
  paid: 'success',
  approved: 'success',
  success: 'success',
  // pending
  pending: 'pending',
  draft: 'pending',
  scheduled: 'pending',
  // danger
  cancelled: 'danger',
  void: 'danger',
  failed: 'danger',
  rejected: 'danger',
  error: 'danger',
  // warning
  warning: 'warning',
  low: 'warning',
  overdue: 'warning',
}

/**
 * Map a raw status string to a {@link StatusTone}.
 *
 * Lowercases `status` and looks it up against common ERP statuses. Unknown
 * statuses fall back to `neutral`. `overrides` (keyed by lowercase status) take
 * precedence over the built-in map, letting callers retire bespoke color maps
 * with `<StatusBadge tone={statusTone(s, overrides)}>{label}</StatusBadge>`.
 */
export function statusTone(
  status: string,
  overrides?: Record<string, StatusTone>,
): StatusTone {
  const key = status.toLowerCase()
  if (overrides) {
    const normalizedOverrides: Record<string, StatusTone> = {}
    for (const [k, v] of Object.entries(overrides)) {
      normalizedOverrides[k.toLowerCase()] = v
    }
    if (key in normalizedOverrides) {
      return normalizedOverrides[key]
    }
  }
  return STATUS_TONE_MAP[key] ?? 'neutral'
}
