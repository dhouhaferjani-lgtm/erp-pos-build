import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useAuthStore } from '@/stores/authStore';
import { useBootstrapStore } from '@/stores/bootstrapStore';

/**
 * T2.4 Day 2 — surfaces a bootstrap state machine failure with Retry /
 * Use cached data / Sign out affordances. Replaces the indefinite spinner
 * branches in `App.tsx::AppRouter` for the four orchestrated phases
 * (`authenticating`, `fetching-companies`, `fetching-terminal`,
 * `checking-pins`).
 *
 * The error label is already coerced to the SAFE_ERROR_NAMES allowlist by
 * the store, so we render it verbatim without further sanitization (the
 * T0.1 banner-opacity contract is satisfied at the store boundary).
 */
export function BootstrapErrorScreen() {
  const { t } = useTranslation('common');
  const error = useBootstrapStore((s) => s.error);
  const phase = useBootstrapStore((s) => s.phase);
  const retry = useBootstrapStore((s) => s.retry);
  const skipWithCache = useBootstrapStore((s) => s.skipWithCache);
  const logout = useAuthStore((s) => s.logout);

  const [isBusy, setIsBusy] = useState(false);
  const [detailsOpen, setDetailsOpen] = useState(false);

  // Defensive guard — should never render with no error, but if the
  // bootstrap store transitions out of `error` while this screen is
  // mounted we want a clean fallback rather than a null-dereference.
  if (error === null) {
    return null;
  }

  async function handleRetry() {
    setIsBusy(true);
    try {
      await retry();
    } finally {
      setIsBusy(false);
    }
  }

  async function handleSkip() {
    setIsBusy(true);
    try {
      await skipWithCache();
    } finally {
      setIsBusy(false);
    }
  }

  const phaseLabel = t(`auth.bootstrap.phase.${error.phase}`);
  const messageKey =
    error.phase === 'fetching-companies'
      ? 'auth.bootstrap.noCompaniesMessage'
      : 'auth.bootstrap.message';

  return (
    <div
      className="flex h-screen items-center justify-center bg-gray-50"
      data-testid="bootstrap-error-screen"
    >
      <div className="w-full max-w-md rounded-card bg-white p-8 shadow-md text-center">
        <h2 className="text-xl font-bold text-gray-900">
          {t('auth.bootstrap.title')}
        </h2>
        <p className="mt-2 text-sm text-gray-500">
          {t(messageKey, { phase: phaseLabel })}
        </p>

        <div className="mt-4 rounded-sm bg-red-50 p-3 text-xs text-red-700">
          <div className="font-mono font-medium" data-testid="bootstrap-error-label">
            {error.errorName}
          </div>
          <div className="mt-1">{t('auth.bootstrap.errorHint')}</div>
        </div>

        <button
          type="button"
          data-testid="bootstrap-retry"
          disabled={isBusy}
          onClick={() => void handleRetry()}
          className="mt-6 w-full rounded-ctl bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
        >
          {isBusy ? t('auth.bootstrap.retrying') : t('auth.bootstrap.retry')}
        </button>

        {error.recoverable && (
          <button
            type="button"
            data-testid="bootstrap-skip-with-cache"
            disabled={isBusy}
            onClick={() => void handleSkip()}
            className="mt-3 w-full rounded-ctl border border-blue-600 bg-white px-4 py-2 text-sm font-medium text-blue-600 hover:bg-blue-50 disabled:opacity-50"
          >
            {t('auth.bootstrap.useCachedData')}
          </button>
        )}

        <button
          type="button"
          data-testid="bootstrap-signout"
          // Codex PR #108 r5 P1 — disable while retry/skipWithCache is
          // in flight. logout() resets bootstrap state (sets phase='ready',
          // lastSuccessfulPhase=null, running=false), but an already-
          // running runFromPhase loop would otherwise complete a phase
          // and `setState({ lastSuccessfulPhase: phase })` after the
          // reset, polluting the cleared session state. Disabling the
          // button while busy makes this race impossible from the UI;
          // the other logout entry points (auth-init internal 401, sync
          // scheduler 401) cannot fire concurrently with a retry from
          // this screen because bootstrap is in `error` state (sync
          // scheduler only runs after the operator gate, i.e. phase ===
          // 'ready' and beyond).
          disabled={isBusy}
          onClick={() => logout()}
          className="mt-3 w-full text-sm text-gray-500 hover:text-gray-700 underline disabled:opacity-50"
        >
          {t('auth.bootstrap.signOut')}
        </button>

        <button
          type="button"
          data-testid="bootstrap-details-toggle"
          onClick={() => setDetailsOpen((v) => !v)}
          className="mt-4 text-xs text-gray-400 hover:text-gray-600 underline"
        >
          {detailsOpen ? t('auth.bootstrap.hideDetails') : t('auth.bootstrap.viewDetails')}
        </button>

        {detailsOpen && (
          <dl
            data-testid="bootstrap-details"
            className="mt-2 rounded-sm bg-gray-50 p-2 text-left text-xs text-gray-600 font-mono"
          >
            <div className="flex justify-between">
              {/* eslint-disable-next-line local/no-untranslated-literal -- developer debug label in technical error panel; not end-user copy */}
              <dt>phase</dt>
              <dd>{error.phase}</dd>
            </div>
            <div className="flex justify-between">
              <dt>storePhase</dt>
              <dd>{phase}</dd>
            </div>
            <div className="flex justify-between">
              <dt>errorName</dt>
              <dd>{error.errorName}</dd>
            </div>
            <div className="flex justify-between">
              <dt>retryCount</dt>
              <dd>{error.retryCount}</dd>
            </div>
          </dl>
        )}
      </div>
    </div>
  );
}
