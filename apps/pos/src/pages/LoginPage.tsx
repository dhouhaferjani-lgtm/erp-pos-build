import { useState, useEffect, useRef, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { useAuthStore, type Company, type Organization } from '@/stores/authStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { getErrorMessage } from '@/lib/api';
import { WifiOff } from 'lucide-react';

// T1.1 Step 1.5: how long to wait before showing the still-trying
// affordance. Lines up with T0.3's 10s default request timeout — by
// 8s the user gets a chance to cancel before the timeout would have
// fired anyway, AND covers captive-portal scenarios that respond
// after 8-15s with a redirect HTML page (within timeout but visually
// indistinguishable from a hang).
const STILL_TRYING_THRESHOLD_MS = 8000;

export function LoginPage() {
  const { t } = useTranslation('pos');
  const { login, isLoading, companies, setCompany, companyId } = useAuthStore();
  const isOnline = useConnectivityStore((s) => s.isOnline);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [showCompanySelect, setShowCompanySelect] = useState(false);
  const [showStillTrying, setShowStillTrying] = useState(false);
  const [organizations, setOrganizations] = useState<Organization[] | null>(null);
  const [pendingTenantId, setPendingTenantId] = useState<string | null>(null);

  // T1.1 Step 1.5: stored AbortController so the Cancel button can
  // abort the in-flight login(). A fresh controller is created on each
  // submit; the ref is null between attempts. AbortController is
  // single-use, so re-clicking Sign in after a cancel ALWAYS produces
  // a new instance — Codex preempt (e) cancel-twice / late-resolve.
  const abortControllerRef = useRef<AbortController | null>(null);

  // Dedicated controller for picker selections — the submit-path controller
  // has already settled by the time the picker renders (Codex r2 F-1).
  const pickAbortRef = useRef<AbortController | null>(null);

  // Show the still-trying affordance + Cancel button once isLoading has
  // been true for STILL_TRYING_THRESHOLD_MS.
  useEffect(() => {
    if (!isLoading) {
      setShowStillTrying(false);
      return;
    }
    const timer = setTimeout(() => {
      setShowStillTrying(true);
    }, STILL_TRYING_THRESHOLD_MS);
    return () => clearTimeout(timer);
  }, [isLoading]);

  async function handleLogin(e: FormEvent) {
    e.preventDefault();
    setError(null);

    // Always create a fresh controller on each submit — AbortController
    // is single-use, so a previously-aborted one cannot be reused.
    const controller = new AbortController();
    abortControllerRef.current = controller;

    try {
      const outcome = await login(email, password, { signal: controller.signal });

      if (outcome.status === 'requires_org_selection') {
        setOrganizations(outcome.organizations);
        return;
      }

      // Authenticated — check if company selection is needed.
      const state = useAuthStore.getState();
      if (state.companies.length > 1 && !state.companyId) {
        setShowCompanySelect(true);
      }
    } catch (err) {
      // If the user cancelled, suppress the error message — they know
      // why nothing happened. Otherwise surface the typed message.
      if (controller.signal.aborted) {
        setError(null);
      } else {
        setError(getErrorMessage(err));
      }
    } finally {
      // Clear the ref so the next submit starts fresh.
      if (abortControllerRef.current === controller) {
        abortControllerRef.current = null;
      }
    }
  }

  function handleCancel() {
    abortControllerRef.current?.abort();
  }

  function handleCompanySelect(company: Company) {
    setCompany(company.id);
    setShowCompanySelect(false);
  }

  async function handleOrgSelect(org: Organization) {
    if (pendingTenantId) return; // ignore repeat / concurrent clicks
    setPendingTenantId(org.tenant_id);
    setError(null);
    const controller = new AbortController();
    pickAbortRef.current = controller;
    try {
      const outcome = await login(email, password, {
        signal: controller.signal,
        tenantId: org.tenant_id,
      });
      if (outcome.status === 'authenticated') {
        setOrganizations(null);
        const state = useAuthStore.getState();
        if (state.companies.length > 1 && !state.companyId) {
          setShowCompanySelect(true);
        }
      }
    } catch (err) {
      if (!controller.signal.aborted) setError(getErrorMessage(err));
    } finally {
      if (pickAbortRef.current === controller) pickAbortRef.current = null;
      setPendingTenantId(null);
    }
  }

  function handleCancelPick() {
    pickAbortRef.current?.abort();
  }

  if (organizations) {
    return (
      <div className="flex h-screen items-center justify-center bg-gray-50">
        <div className="w-full max-w-md rounded-lg bg-white p-8 shadow-md">
          <h2 className="mb-6 text-center text-xl font-bold text-gray-900">
            {t('auth.selectOrganization')}
          </h2>
          {error && (
            <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{error}</div>
          )}
          <div className="space-y-3" data-testid="org-picker">
            {organizations.map((org) => (
              <button
                key={org.tenant_id}
                type="button"
                disabled={pendingTenantId !== null}
                onClick={() => void handleOrgSelect(org)}
                className="w-full rounded-lg border border-gray-200 p-4 text-left transition hover:border-blue-300 hover:bg-blue-50 disabled:opacity-50"
              >
                <div className="font-medium text-gray-900">{org.name}</div>
                <div className="text-sm text-gray-500">{org.slug}</div>
              </button>
            ))}
          </div>
          {showStillTrying && pendingTenantId && (
            <button
              type="button"
              data-testid="org-pick-cancel"
              onClick={handleCancelPick}
              className="mt-4 w-full rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
              {t('auth.cancel')}
            </button>
          )}
        </div>
      </div>
    );
  }

  if (showCompanySelect && companies.length > 1 && !companyId) {
    return (
      <div className="flex h-screen items-center justify-center bg-gray-50">
        <div className="w-full max-w-md rounded-lg bg-white p-8 shadow-md">
          <h2 className="mb-6 text-center text-xl font-bold text-gray-900">
            {t('auth.selectCompany')}
          </h2>
          <div className="space-y-3">
            {companies.map((company) => (
              <button
                key={company.id}
                onClick={() => handleCompanySelect(company)}
                className="w-full rounded-lg border border-gray-200 p-4 text-left transition hover:border-blue-300 hover:bg-blue-50"
              >
                <div className="font-medium text-gray-900">{company.name}</div>
                <div className="text-sm text-gray-500">
                  {company.countryCode} &middot; {company.currency}
                </div>
              </button>
            ))}
          </div>
        </div>
      </div>
    );
  }

  if (!isOnline) {
    return (
      <div className="flex h-screen items-center justify-center bg-gray-50">
        <div className="w-full max-w-md rounded-lg bg-white p-8 shadow-md text-center">
          <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
            <WifiOff className="h-8 w-8 text-red-500" />
          </div>
          <h2 className="text-xl font-bold text-gray-900">{t('auth.noConnection')}</h2>
          <p className="mt-2 text-sm text-gray-500">{t('auth.noConnectionMessage')}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex h-screen items-center justify-center bg-gray-50">
      <div className="w-full max-w-md rounded-lg bg-white p-8 shadow-md">
        <div className="mb-8 text-center">
          <h1 className="text-2xl font-bold text-gray-900">{t('auth.title')}</h1>
          <p className="mt-1 text-sm text-gray-500">{t('auth.subtitle')}</p>
        </div>

        <form onSubmit={(e) => void handleLogin(e)} className="space-y-4">
          <div>
            <label htmlFor="email" className="block text-sm font-medium text-gray-700">
              {t('auth.email')}
            </label>
            <input
              id="email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
              placeholder="you@example.com"
              required
              autoFocus
            />
          </div>

          <div>
            <label htmlFor="password" className="block text-sm font-medium text-gray-700">
              {t('auth.password')}
            </label>
            <input
              id="password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
              placeholder="••••••••"
              required
              minLength={8}
            />
          </div>

          {error && (
            <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">{error}</div>
          )}

          <button
            type="submit"
            disabled={isLoading}
            className="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none disabled:opacity-50"
          >
            {isLoading ? t('auth.signingIn') : t('auth.signIn')}
          </button>

          {showStillTrying && (
            <>
              <p
                data-testid="login-still-trying"
                className="text-center text-xs text-gray-500"
              >
                {t('auth.stillTrying')}
              </p>
              <button
                type="button"
                data-testid="login-cancel"
                onClick={handleCancel}
                className="w-full rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('auth.cancel')}
              </button>
            </>
          )}
        </form>
      </div>
    </div>
  );
}
