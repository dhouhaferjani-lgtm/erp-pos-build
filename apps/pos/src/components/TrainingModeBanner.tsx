import { useTranslation } from 'react-i18next';
import { useTerminalStore } from '@/stores/terminalStore';
import { GraduationCap } from 'lucide-react';

/**
 * T2.5 — sticky banner that surfaces the existing backend Training
 * Mode in the POS UI. Renders only when the active terminal has
 * `is_training_mode === true`. Replaces the prior "build demo mode
 * from scratch" plan (the backend already does the right thing —
 * this is just the visible surface).
 *
 * The cashier cannot toggle training mode from here — the toggle
 * stays in the back-office. The banner exists so the cashier never
 * confuses training and production at a glance: training receipts
 * skip the fiscal hash chain and don't pollute Z reports, but they
 * DO persist as DB rows, so the visible distinction is the only
 * safeguard against an honest mistake (e.g. processing a real cash
 * sale on a training terminal).
 */
export function TrainingModeBanner() {
  const { t } = useTranslation('common');
  const isTrainingMode = useTerminalStore((s) => s.terminal?.is_training_mode === true);

  if (!isTrainingMode) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      data-testid="training-mode-banner"
      className="flex shrink-0 items-center justify-center gap-3 border-b-2 border-amber-500 bg-amber-100 px-4 py-2 text-amber-900"
    >
      <GraduationCap className="h-5 w-5 shrink-0" aria-hidden />
      <span className="text-sm font-semibold uppercase tracking-wide">
        {t('trainingMode.banner')}
      </span>
      <span className="text-sm">{t('trainingMode.subtitle')}</span>
    </div>
  );
}
