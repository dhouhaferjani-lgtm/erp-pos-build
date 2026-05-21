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
 * Tailwind class map per risk level. Matches the sibling `ChainBreakAlert`
 * convention (no `designTokens` module in apps/pos — POS uses Tailwind classes
 * directly per the established atom-component pattern). Green / amber / red
 * are the standard fiscal-status palette across the POS UI.
 */
const riskClasses: Record<UnsyncedRiskLevel, string> = {
  normal:
    'bg-green-50 border-green-300 text-green-900',
  elevated:
    'bg-amber-50 border-amber-300 text-amber-900',
  escalated:
    'bg-red-50 border-red-300 text-red-900',
};

export function UnsyncedRiskIndicator() {
  const { t } = useTranslation('fiscal');
  const level = useDurabilityStore((s) => s.riskLevel);

  // Pre-first-poll: render nothing. Once the polling hook pushes the first
  // result, the indicator appears.
  if (level === null) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      data-risk-level={level}
      data-testid="unsynced-risk-indicator"
      className={`border rounded-md px-3 py-2 text-sm font-medium flex items-center gap-2 ${riskClasses[level]}`}
    >
      <span aria-hidden="true" className="inline-block w-2 h-2 rounded-full bg-current" />
      <span>{t(`unsyncedRisk.label.${level}`)}</span>
      <span className="text-xs opacity-80">{t(`unsyncedRisk.detail.${level}`)}</span>
    </div>
  );
}
