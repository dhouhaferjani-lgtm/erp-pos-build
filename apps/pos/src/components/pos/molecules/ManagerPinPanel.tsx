import { useState, useMemo, useCallback, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { CurrencyNumpad } from '../atoms/CurrencyNumpad';

interface ManagerPinPanelProps {
  authorizedManagers: Array<{ id: string; name: string }>;
  excludeUserId: string;
  onVerify: (userId: string, pin: string) => Promise<{ valid: boolean }>;
  onSuccess: (managerUserId: string, managerName: string) => void;
  throttle: { until: string | null; failedAttempts: number };
  onThrottleUpdate: (next: { until: string | null; failedAttempts: number }) => void;
  disabled?: boolean;
}

const MAX_FAILED_ATTEMPTS = 3;
const THROTTLE_DURATION_MS = 30 * 1000;

export function ManagerPinPanel({
  authorizedManagers,
  excludeUserId,
  onVerify,
  onSuccess,
  throttle,
  onThrottleUpdate,
  disabled = false,
}: ManagerPinPanelProps) {
  const { t } = useTranslation('pos');

  const eligibleManagers = useMemo(
    () => authorizedManagers.filter((m) => m.id !== excludeUserId),
    [authorizedManagers, excludeUserId],
  );

  const [selectedManagerId, setSelectedManagerId] = useState<string>(
    eligibleManagers[0]?.id ?? '',
  );
  const [pin, setPin] = useState('');
  const [verifying, setVerifying] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [now, setNow] = useState(Date.now());

  // Tick every second to update the countdown display
  useEffect(() => {
    if (!throttle.until) return;
    const interval = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(interval);
  }, [throttle.until]);

  const throttleUntilMs = throttle.until !== null ? new Date(throttle.until).getTime() : 0;
  const isThrottled = throttle.until !== null && throttleUntilMs > now;
  const remainingSeconds = isThrottled ? Math.ceil((throttleUntilMs - now) / 1000) : 0;

  const handleVerify = useCallback(async () => {
    if (verifying || disabled || isThrottled) return;

    if (!selectedManagerId) {
      setError(
        t('cash_count.manager_pin.no_manager_selected', {
          defaultValue: 'Please select a manager.',
        }),
      );
      return;
    }

    if (pin.length < 4) {
      setError(
        t('cash_count.manager_pin.pin_too_short', {
          defaultValue: 'PIN must be at least 4 digits.',
        }),
      );
      return;
    }

    setVerifying(true);
    setError(null);
    try {
      const result = await onVerify(selectedManagerId, pin);
      if (result.valid) {
        const managerName =
          eligibleManagers.find((m) => m.id === selectedManagerId)?.name ?? '';
        onThrottleUpdate({ until: null, failedAttempts: 0 });
        onSuccess(selectedManagerId, managerName);
        setPin('');
      } else {
        const newAttempts = throttle.failedAttempts + 1;
        if (newAttempts >= MAX_FAILED_ATTEMPTS) {
          const until = new Date(Date.now() + THROTTLE_DURATION_MS).toISOString();
          onThrottleUpdate({ until, failedAttempts: 0 });
          setError(
            t('cash_count.manager_pin.throttled', {
              defaultValue: 'Too many failed attempts. Please wait.',
            }),
          );
        } else {
          onThrottleUpdate({ until: null, failedAttempts: newAttempts });
          setError(
            t('cash_count.manager_pin.invalid', { defaultValue: 'Invalid PIN.' }),
          );
        }
        setPin('');
      }
    } catch {
      setError(
        t('cash_count.manager_pin.error', {
          defaultValue: 'Verification failed.',
        }),
      );
    } finally {
      setVerifying(false);
    }
  }, [
    selectedManagerId,
    pin,
    verifying,
    disabled,
    isThrottled,
    eligibleManagers,
    throttle.failedAttempts,
    onVerify,
    onSuccess,
    onThrottleUpdate,
    t,
  ]);

  return (
    <div className="space-y-3" data-testid="manager-pin-panel">
      <div>
        <label htmlFor="manager-select" className="text-sm font-medium">
          {t('cash_count.manager_pin.manager_label', { defaultValue: 'Manager' })}
        </label>
        <select
          id="manager-select"
          value={selectedManagerId}
          onChange={(e) => setSelectedManagerId(e.target.value)}
          disabled={disabled || isThrottled || eligibleManagers.length === 0}
          data-testid="manager-pin-select"
          className="mt-1 block w-full rounded-md border border-border-strong p-2"
        >
          {eligibleManagers.length === 0 ? (
            <option value="">
              {t('cash_count.manager_pin.no_managers', {
                defaultValue: 'No authorized managers',
              })}
            </option>
          ) : (
            eligibleManagers.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))
          )}
        </select>
      </div>

      <div>
        <label className="text-sm font-medium">
          {t('cash_count.manager_pin.pin_label', { defaultValue: 'PIN' })}
        </label>
        <div
          className="mt-1 rounded-md border border-border-strong p-2 font-mono text-2xl tracking-widest"
          data-testid="manager-pin-display"
        >
          {pin.length > 0 ? '•'.repeat(pin.length) : ' '}
        </div>
      </div>

      {/* currencyCode="JPY" → scale 0 → dot button disabled → digit-only numpad */}
      <CurrencyNumpad
        value={pin}
        onChange={setPin}
        currencyCode="JPY"
        disabled={disabled || isThrottled || verifying}
        aria-label={t('cash_count.manager_pin.numpad_label', {
          defaultValue: 'PIN keypad',
        })}
      />

      {error !== null && (
        <p data-testid="manager-pin-error" className="text-sm text-danger">
          {error}
        </p>
      )}

      {isThrottled && (
        <p data-testid="manager-pin-countdown" className="text-sm text-warning-strong">
          {t('cash_count.manager_pin.wait', {
            defaultValue: 'Please wait {{seconds}}s before retry.',
            seconds: remainingSeconds,
          })}
        </p>
      )}

      <button
        type="button"
        onClick={handleVerify}
        disabled={disabled || isThrottled || verifying || pin.length < 4 || !selectedManagerId}
        data-testid="manager-pin-verify"
        className="w-full rounded-md bg-action py-2 text-ink-inverse disabled:bg-surface-sunken"
      >
        {verifying
          ? t('cash_count.manager_pin.verifying', { defaultValue: 'Verifying…' })
          : t('cash_count.manager_pin.verify', { defaultValue: 'Verify' })}
      </button>
    </div>
  );
}
