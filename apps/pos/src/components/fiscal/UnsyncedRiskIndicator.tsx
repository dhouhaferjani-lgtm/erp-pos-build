/**
 * `UnsyncedRiskIndicator` — operator-visible §12 conservation surface.
 *
 * Spec v7 §12: "an operator-visible unsynced-risk indicator". This component
 * renders the level returned by `OffDeviceDurabilityService.unsyncedRisk()`
 * as a colored badge so the operator can see at a glance whether the
 * terminal's authoring is racing ahead of its off-device durability path.
 *
 * The component takes the service as a prop so callers wire it via
 * constructor injection at the container layer (rule 13) — there is no
 * module-scope singleton here. The risk level is polled on an interval
 * the caller controls (default 30s) so the indicator stays current without
 * the component owning a clock.
 */

import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import type {
  OffDeviceDurabilityService,
  UnsyncedRiskLevel,
} from '@/lib/fiscal/OffDeviceDurabilityService';

interface UnsyncedRiskIndicatorProps {
  service: OffDeviceDurabilityService;
  /** Poll interval (ms). Defaults to 30s. Pass 0 to disable polling (test). */
  pollIntervalMs?: number;
}

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

export function UnsyncedRiskIndicator({
  service,
  pollIntervalMs = 30_000,
}: UnsyncedRiskIndicatorProps) {
  const { t } = useTranslation('fiscal');
  const [level, setLevel] = useState<UnsyncedRiskLevel | null>(null);

  useEffect(() => {
    let cancelled = false;

    const tick = async () => {
      try {
        const next = await service.unsyncedRisk();
        if (!cancelled) setLevel(next);
      } catch {
        // The service is a read-only SQLite query surface in Pass 1; if it
        // throws we surface nothing rather than crashing the UI. The error
        // path belongs to the operational logging layer the caller wires up.
        if (!cancelled) setLevel(null);
      }
    };

    void tick();

    if (pollIntervalMs > 0) {
      const handle = setInterval(() => {
        void tick();
      }, pollIntervalMs);
      return () => {
        cancelled = true;
        clearInterval(handle);
      };
    }

    return () => {
      cancelled = true;
    };
  }, [service, pollIntervalMs]);

  if (level === null) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      data-risk-level={level}
      className={`border rounded-md px-3 py-2 text-sm font-medium flex items-center gap-2 ${riskClasses[level]}`}
    >
      <span aria-hidden="true" className="inline-block w-2 h-2 rounded-full bg-current" />
      <span>{t(`unsyncedRisk.label.${level}`)}</span>
      <span className="text-xs opacity-80">{t(`unsyncedRisk.detail.${level}`)}</span>
    </div>
  );
}
