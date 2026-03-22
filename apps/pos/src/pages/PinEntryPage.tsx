import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PinPad } from '@/components/PinPad';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useCartStore } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useProductStore } from '@/stores/productStore';
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
  const [showSignOut, setShowSignOut] = useState(false);

  function handleSignOut() {
    useCartStore.getState().clearCart();
    usePaymentStore.getState().reset();
    useProductStore.getState().reset();
    useOperatorStore.getState().clearOperator();
    useAuthStore.getState().logout();
  }

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

        <button
          onClick={() => setShowSignOut(true)}
          className="mt-6 w-full text-center text-sm text-gray-400 hover:text-gray-600"
        >
          {t('settings.signOutFromPin')}
        </button>
      </div>

      {/* Sign Out Confirmation */}
      {showSignOut && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="mx-4 w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">
              {t('settings.signOutTerminal')}
            </h3>
            <p className="mt-2 text-sm text-gray-600">
              {t('settings.signOutConfirmMessage')}
            </p>
            <div className="mt-6 flex gap-3">
              <button
                onClick={() => setShowSignOut(false)}
                className="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('settings.cancel')}
              </button>
              <button
                onClick={handleSignOut}
                className="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700"
              >
                {t('settings.signOut')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
