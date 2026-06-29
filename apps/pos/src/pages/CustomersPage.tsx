import { useTranslation } from 'react-i18next';
import { Users } from 'lucide-react';

/**
 * CustomersPage — PLACEHOLDER for the nav-rail "Clients" destination.
 *
 * The full customer-management surface (browse / detail / history / edit /
 * store-credit / loyalty / skin-type) is owned by the parapharmacy + loyalty
 * build sessions (redesign tracker P8). This stub exists so the nav rail has a
 * valid route; build it out in place — do not create a parallel page.
 */
export function CustomersPage() {
  const { t } = useTranslation('pos');
  return (
    <div className="flex h-full flex-col items-center justify-center gap-3 bg-surface-canvas p-8 text-center">
      <span className="flex h-16 w-16 items-center justify-center rounded-full bg-surface-sunken text-ink-faint">
        <Users className="h-8 w-8" />
      </span>
      <h1 className="font-display text-xl font-bold text-ink-strong">{t('customers.pageTitle')}</h1>
      <p className="max-w-sm text-sm text-ink-muted">{t('customers.placeholder')}</p>
    </div>
  );
}
