import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PinPad } from '@/components/PinPad';
import { useOperatorStore } from '@/stores/operatorStore';
import { getErrorMessage } from '@/lib/api';

const MAX_PIN_LENGTH = 6;

interface PinEntryPageProps {
  isLocked?: boolean;
}

export function PinEntryPage({ isLocked }: PinEntryPageProps) {
  const { t } = useTranslation('pos');
  const verifyPin = useOperatorStore((s) => s.verifyPin);
  const [pin, setPin] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [verifying, setVerifying] = useState(false);

  async function handleSubmit() {
    if (pin.length < 4) return;
    setError(null);
    setVerifying(true);

    try {
      await verifyPin(pin);
    } catch (err) {
      setError(getErrorMessage(err));
      setPin('');
    } finally {
      setVerifying(false);
    }
  }

  return (
    <div className="flex h-screen items-center justify-center bg-gray-50">
      <div className="w-full max-w-xs">
        <div className="mb-8 text-center">
          <h2 className="text-xl font-bold text-gray-900">
            {isLocked ? t('pin.screenLocked') : t('pin.enterPin')}
          </h2>
          <p className="mt-1 text-sm text-gray-500">
            {isLocked ? t('pin.unlockMessage') : t('pin.enterMessage')}
          </p>
        </div>

        <div className="mb-6 flex justify-center gap-3">
          {Array.from({ length: MAX_PIN_LENGTH }).map((_, i) => (
            <div
              key={i}
              className={`h-4 w-4 rounded-full transition-colors ${
                i < pin.length ? 'bg-blue-600' : 'bg-gray-300'
              }`}
            />
          ))}
        </div>

        {error && (
          <div className="mb-4 rounded-md bg-red-50 p-3 text-center text-sm text-red-700">
            {error}
          </div>
        )}

        <PinPad
          value={pin}
          maxLength={MAX_PIN_LENGTH}
          onChange={setPin}
          onSubmit={() => void handleSubmit()}
          disabled={verifying}
        />
      </div>
    </div>
  );
}
