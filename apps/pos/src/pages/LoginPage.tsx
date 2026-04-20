import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { useAuthStore, type Company } from '@/stores/authStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { getErrorMessage } from '@/lib/api';
import { WifiOff } from 'lucide-react';

export function LoginPage() {
  const { t } = useTranslation('pos');
  const { login, isLoading, companies, setCompany, companyId } = useAuthStore();
  const isOnline = useConnectivityStore((s) => s.isOnline);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [showCompanySelect, setShowCompanySelect] = useState(false);

  async function handleLogin(e: FormEvent) {
    e.preventDefault();
    setError(null);

    try {
      await login(email, password);

      // Check if company selection is needed
      const state = useAuthStore.getState();
      if (state.companies.length > 1 && !state.companyId) {
        setShowCompanySelect(true);
      }
    } catch (err) {
      setError(getErrorMessage(err));
    }
  }

  function handleCompanySelect(company: Company) {
    setCompany(company.id);
    setShowCompanySelect(false);
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
        </form>
      </div>
    </div>
  );
}
