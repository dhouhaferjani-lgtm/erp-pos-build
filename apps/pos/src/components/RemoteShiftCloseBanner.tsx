import { useTranslation } from 'react-i18next';
import { useTerminalStore } from '@/stores/terminalStore';
import { AlertTriangle } from 'lucide-react';

/**
 * Offline-first shifts Phase 6.2 — advisory banner surfaced when the background
 * reconcile (syncService.runFullSync → shiftReconcile) detects that this
 * device's open shift was CLOSED on the server (a web-admin recovery close)
 * while local SQLite still has it OPEN.
 *
 * Advisory only: it never auto-closes the local shift (a sale may be mid-flight;
 * Decision 4 disallows web force-close by default). The cashier is told to
 * review and reconcile. The detection is audited + idempotent server-side; this
 * is just the visible surface. Renders null when there is no conflict (no DOM,
 * no layout impact), mirroring TrainingModeBanner.
 */
export function RemoteShiftCloseBanner() {
  const { t } = useTranslation('common');
  const conflict = useTerminalStore((s) => s.remoteShiftCloseConflict);

  if (!conflict) return null;

  return (
    <div
      role="alert"
      aria-live="assertive"
      data-testid="remote-shift-close-banner"
      className="flex shrink-0 flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b-2 border-red-500 bg-red-100 px-4 py-2 text-center text-red-900"
    >
      <AlertTriangle className="h-5 w-5 shrink-0" aria-hidden />
      <span className="text-sm font-semibold uppercase tracking-wide">
        {t('remoteShiftClose.banner', { shiftNumber: conflict.shiftNumber })}
      </span>
      <span className="text-sm">{t('remoteShiftClose.subtitle')}</span>
    </div>
  );
}
