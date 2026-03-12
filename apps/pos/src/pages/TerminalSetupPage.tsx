import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { apiGet, getErrorMessage } from '@/lib/api';
import { getDeviceId } from '@/lib/device';
import { useTerminalActivation } from '@/hooks/useTerminalActivation';
import { useTerminalStore, type Location, type Terminal } from '@/stores/terminalStore';

type Tab = 'claim' | 'request';

export function TerminalSetupPage() {
  const pendingTerminalId = useTerminalStore((s) => s.pendingTerminalId);

  // If there's a persisted pending terminal, go straight to the pending screen
  if (pendingTerminalId) {
    return <PendingActivationPage terminalId={pendingTerminalId} />;
  }

  return <SetupTabs />;
}

function PendingActivationPage({ terminalId }: { terminalId: string }) {
  const { t } = useTranslation('pos');
  const { checkTerminalStatus } = useTerminalStore();
  const [checking, setChecking] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { wsConnected } = useTerminalActivation(terminalId);

  async function handleCheckStatus() {
    setError(null);
    setChecking(true);
    try {
      const terminal = await checkTerminalStatus(terminalId);
      if (!terminal.is_active) {
        setError(t('terminal.stillPending'));
      }
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setChecking(false);
    }
  }

  return (
    <div className="flex h-screen items-center justify-center bg-gray-50">
      <div className="w-full max-w-lg rounded-lg bg-white p-8 shadow-md">
        <div className="mb-6 text-center">
          <h2 className="text-xl font-bold text-gray-900">{t('terminal.setup')}</h2>
          <p className="mt-1 text-sm text-gray-500">
            {t('terminal.connectDevice')}
          </p>
        </div>

        {error && (
          <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{error}</div>
        )}

        <div className="space-y-4 text-center">
          <div className="rounded-md bg-amber-50 p-4">
            <div className="text-sm font-medium text-amber-800">{t('terminal.pendingActivation')}</div>
            <p className="mt-1 text-sm text-amber-700">
              {t('terminal.pendingMessage')}
            </p>
          </div>

          {wsConnected ? (
            <div className="flex items-center justify-center gap-2 text-sm text-green-700">
              <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-green-500" />
              {t('terminal.listeningActivation')}
            </div>
          ) : (
            <>
              <p className="text-xs text-gray-500">
                {t('terminal.autoChecking')}
              </p>
              <button
                onClick={() => void handleCheckStatus()}
                disabled={checking}
                className="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                {checking ? t('terminal.checking') : t('terminal.checkStatus')}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

function SetupTabs() {
  const { t } = useTranslation('pos');
  const [activeTab, setActiveTab] = useState<Tab>('claim');
  const [error, setError] = useState<string | null>(null);

  return (
    <div className="flex h-screen items-center justify-center bg-gray-50">
      <div className="w-full max-w-lg rounded-lg bg-white p-8 shadow-md">
        <div className="mb-6 text-center">
          <h2 className="text-xl font-bold text-gray-900">{t('terminal.setup')}</h2>
          <p className="mt-1 text-sm text-gray-500">
            {t('terminal.connectDevice')}
          </p>
        </div>

        <div className="mb-6 flex rounded-md border border-gray-200">
          <button
            onClick={() => { setActiveTab('claim'); setError(null); }}
            className={`flex-1 rounded-l-md px-4 py-2 text-sm font-medium transition-colors ${
              activeTab === 'claim'
                ? 'bg-blue-600 text-white'
                : 'bg-white text-gray-700 hover:bg-gray-50'
            }`}
          >
            {t('terminal.availableTerminals')}
          </button>
          <button
            onClick={() => { setActiveTab('request'); setError(null); }}
            className={`flex-1 rounded-r-md px-4 py-2 text-sm font-medium transition-colors ${
              activeTab === 'request'
                ? 'bg-blue-600 text-white'
                : 'bg-white text-gray-700 hover:bg-gray-50'
            }`}
          >
            {t('terminal.requestNew')}
          </button>
        </div>

        {error && (
          <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{error}</div>
        )}

        {activeTab === 'claim' ? (
          <ClaimTab onError={setError} />
        ) : (
          <RequestTab onError={setError} />
        )}
      </div>
    </div>
  );
}

function ClaimTab({ onError }: { onError: (msg: string | null) => void }) {
  const { t } = useTranslation('pos');
  const { fetchAvailable, claimTerminal, isLoading } = useTerminalStore();
  const [terminals, setTerminals] = useState<Terminal[]>([]);
  const [fetching, setFetching] = useState(true);

  useEffect(() => {
    async function load() {
      try {
        const data = await fetchAvailable();
        setTerminals(data);
      } catch (err) {
        onError(getErrorMessage(err));
      } finally {
        setFetching(false);
      }
    }
    void load();
  }, [fetchAvailable, onError]);

  async function handleClaim(terminalId: string) {
    onError(null);
    try {
      await claimTerminal(terminalId, getDeviceId());
    } catch (err) {
      onError(getErrorMessage(err));
    }
  }

  if (fetching) {
    return <div className="py-8 text-center text-sm text-gray-500">{t('terminal.loadingTerminals')}</div>;
  }

  if (terminals.length === 0) {
    return (
      <div className="rounded-md bg-yellow-50 p-4 text-sm text-yellow-700">
        {t('terminal.noUnclaimed')}
      </div>
    );
  }

  return (
    <div className="space-y-3">
      {terminals.map((terminal) => (
        <div
          key={terminal.id}
          className="flex items-center justify-between rounded-md border border-gray-200 p-4"
        >
          <div>
            <div className="font-medium text-gray-900">{terminal.name}</div>
            <div className="text-sm text-gray-500">
              {terminal.code} &middot; {terminal.location.name}
            </div>
          </div>
          <button
            onClick={() => void handleClaim(terminal.id)}
            disabled={isLoading}
            className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {isLoading ? t('terminal.claiming') : t('terminal.claim')}
          </button>
        </div>
      ))}
    </div>
  );
}

function RequestTab({ onError }: { onError: (msg: string | null) => void }) {
  const { t } = useTranslation('pos');
  const { requestTerminal, checkTerminalStatus, isLoading } = useTerminalStore();
  const pendingTerminalId = useTerminalStore((s) => s.pendingTerminalId);
  const [locations, setLocations] = useState<Location[]>([]);
  const [selectedLocationId, setSelectedLocationId] = useState<string | null>(null);
  const [terminalName, setTerminalName] = useState('');
  const [fetchingLocations, setFetchingLocations] = useState(true);
  const [pendingTerminal, setPendingTerminal] = useState<Terminal | null>(null);
  const [checking, setChecking] = useState(false);

  const { wsConnected } = useTerminalActivation(pendingTerminal?.id ?? pendingTerminalId);

  useEffect(() => {
    async function load() {
      try {
        const data = await apiGet<Location[]>('/locations');
        setLocations(data);
        if (data.length === 1 && data[0]) {
          setSelectedLocationId(data[0].id);
        }
      } catch (err) {
        onError(getErrorMessage(err));
      } finally {
        setFetchingLocations(false);
      }
    }
    void load();
  }, [onError]);

  async function handleRequest() {
    if (!selectedLocationId || !terminalName.trim()) return;
    onError(null);

    try {
      const terminal = await requestTerminal(selectedLocationId, terminalName.trim(), getDeviceId());
      setPendingTerminal(terminal);
    } catch (err) {
      onError(getErrorMessage(err));
    }
  }

  const activePendingId = pendingTerminal?.id ?? pendingTerminalId;

  async function handleCheckStatus() {
    if (!activePendingId) return;
    onError(null);
    setChecking(true);

    try {
      const terminal = await checkTerminalStatus(activePendingId);
      if (!terminal.is_active) {
        onError(t('terminal.stillPending'));
      }
    } catch (err) {
      onError(getErrorMessage(err));
    } finally {
      setChecking(false);
    }
  }

  if (pendingTerminal || pendingTerminalId) {
    return (
      <div className="space-y-4 text-center">
        <div className="rounded-md bg-amber-50 p-4">
          <div className="text-sm font-medium text-amber-800">{t('terminal.pendingActivation')}</div>
          <p className="mt-1 text-sm text-amber-700">
            {pendingTerminal ? (
              <span
                dangerouslySetInnerHTML={{
                  __html: t('terminal.terminalRequested', {
                    name: pendingTerminal.name,
                    code: pendingTerminal.code,
                    interpolation: { escapeValue: false },
                  }),
                }}
              />
            ) : (
              <>{t('terminal.awaitingActivation')}</>
            )}
            {' '}{t('terminal.adminActivate')}
          </p>
        </div>

        {wsConnected ? (
          <div className="flex items-center justify-center gap-2 text-sm text-green-700">
            <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-green-500" />
            {t('terminal.listeningActivation')}
          </div>
        ) : (
          <>
            <p className="text-xs text-gray-500">
              {t('terminal.autoChecking')}
            </p>
            <button
              onClick={() => void handleCheckStatus()}
              disabled={checking}
              className="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {checking ? t('terminal.checking') : t('terminal.checkStatus')}
            </button>
          </>
        )}
      </div>
    );
  }

  if (fetchingLocations) {
    return <div className="py-8 text-center text-sm text-gray-500">{t('terminal.loadingLocations')}</div>;
  }

  return (
    <div className="space-y-4">
      <div>
        <label htmlFor="location" className="block text-sm font-medium text-gray-700">
          {t('terminal.location')}
        </label>
        <select
          id="location"
          value={selectedLocationId ?? ''}
          onChange={(e) => setSelectedLocationId(e.target.value || null)}
          className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
        >
          <option value="">{t('terminal.selectLocation')}</option>
          {locations.map((loc) => (
            <option key={loc.id} value={loc.id}>
              {loc.name} ({loc.code})
            </option>
          ))}
        </select>
      </div>

      <div>
        <label htmlFor="terminalName" className="block text-sm font-medium text-gray-700">
          {t('terminal.terminalName')}
        </label>
        <input
          id="terminalName"
          type="text"
          value={terminalName}
          onChange={(e) => setTerminalName(e.target.value)}
          placeholder={t('terminal.terminalNamePlaceholder')}
          className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
        />
      </div>

      <button
        onClick={() => void handleRequest()}
        disabled={isLoading || !selectedLocationId || !terminalName.trim()}
        className="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
      >
        {isLoading ? t('terminal.requesting') : t('terminal.requestTerminal')}
      </button>
    </div>
  );
}
