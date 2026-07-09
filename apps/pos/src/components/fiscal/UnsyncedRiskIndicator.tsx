/**
 * `UnsyncedRiskIndicator` — operator-visible §12 conservation surface.
 *
 * Spec v7 §12: "an operator-visible unsynced-risk indicator". This component
 * renders the last polled `UnsyncedRiskLevel` as a colored badge so the
 * operator can see at a glance whether the terminal's authoring is racing
 * ahead of its off-device durability path.
 *
 * **Round-2 T32-B2: refactored to read from `durabilityStore`.**
 *
 * Round-1 took `OffDeviceDurabilityService` as a prop and polled it from
 * inside `useEffect`. That made the component impossible to mount in the
 * always-visible POS shell without threading the service through every
 * surface, and Codex flagged the resulting dead-path (the component was
 * never rendered by any caller). Round-2 splits responsibilities:
 *
 *   - This component reads the latest polled `riskLevel` from the
 *     `useDurabilityStore` Zustand store (same atom-style pattern as the
 *     sibling `ChainBreakAlert` reading from `useSyncStore`).
 *   - The polling loop lives in `useFiscalDurabilityPolling` — a hook the
 *     `AppShell` mounts once the company DB + service are available.
 *
 * That keeps `AppShell` as the single wiring site for the operator-visible
 * conservation surface and lets this component mount unconditionally.
 */

import { useTranslation } from 'react-i18next';

import type { UnsyncedRiskLevel } from '@/lib/fiscal/OffDeviceDurabilityService';
import { useDurabilityStore } from '@/stores/durabilityStore';

/**
 * Exception-only banner classes per *attention-needing* risk level.
 *
 * The sync/durability surface is now exception-only: a healthy terminal
 * (`normal`/`null`) renders nothing here — the healthy signal lives in the
 * Header status pill. Only `elevated` (warning) and `escalated` (danger)
 * surface a banner, tokenized via the semantic design tokens so they share
 * the app-wide color grammar (warning = amber, danger = red).
 */
const riskClasses: Record<Exclude<UnsyncedRiskLevel, 'normal'>, string> = {
  elevated:
    'bg-warning-surface border-warning-subtle text-warning-strong',
  escalated:
    'bg-danger-surface border-danger-subtle text-danger-strong',
};

export function UnsyncedRiskIndicator() {
  const { t } = useTranslation('fiscal');
  const level = useDurabilityStore((s) => s.riskLevel);

  // Exception-only: healthy (pre-first-poll `null` or polled `normal`) renders
  // nothing. The healthy "synced" signal lives in the Header status pill, so a
  // permanent full-width banner would only add alarm fatigue and waste space.
  // The banner appears only when the unsynced backlog needs attention.
  if (level === null || level === 'normal') return null;

  return (
    <div
      role="status"
      aria-live="polite"
      data-risk-level={level}
      data-testid="unsynced-risk-indicator"
      className={`mx-4 mt-2 border rounded-sm px-3 py-2 text-sm font-medium flex items-center gap-2 ${riskClasses[level]}`}
    >
      <span aria-hidden="true" className="inline-block w-2 h-2 rounded-full bg-current" />
      <span>{t(`unsyncedRisk.label.${level}`)}</span>
      <span className="text-xs opacity-80">{t(`unsyncedRisk.detail.${level}`)}</span>
    </div>
  );
}
