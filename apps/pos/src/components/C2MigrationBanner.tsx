import { useEffect } from 'react';
import { AlertCircle, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useC2MigrationBannerStore } from '@/stores/c2MigrationBannerStore';

/**
 * Cashier-facing banner shown when the C2 bare-cart-line migration dumped
 * one or more held transactions. Subscribes to a Zustand store mirror of
 * the persisted `StorageKeys.C2_MIGRATION_BANNER` flag so a DEFERRED
 * migration retry that lands AFTER the banner has mounted still surfaces
 * the notification (Codex PR #118 round-5 P2).
 */
export function C2MigrationBanner() {
  const { t } = useTranslation('common');
  const visible = useC2MigrationBannerStore((s) => s.visible);
  const hydrate = useC2MigrationBannerStore((s) => s.hydrate);
  const dismiss = useC2MigrationBannerStore((s) => s.dismiss);

  useEffect(() => {
    void hydrate();
  }, [hydrate]);

  if (!visible) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      data-testid="c2-migration-banner"
      className="flex shrink-0 items-center justify-center gap-3 border-b border-rose-300 bg-rose-50 px-4 py-2 text-rose-950"
    >
      <AlertCircle className="h-5 w-5 shrink-0" aria-hidden />
      <span className="text-sm font-medium">{t('c2Migration.banner')}</span>
      <button
        type="button"
        onClick={() => void dismiss()}
        className="inline-flex h-7 items-center gap-1 rounded border border-rose-300 bg-white px-2 text-sm font-semibold text-rose-950 hover:bg-rose-100 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-1"
      >
        <X className="h-4 w-4" aria-hidden />
        {t('c2Migration.dismiss')}
      </button>
    </div>
  );
}
