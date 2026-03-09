import { useState } from 'react';
import { PinPad } from '@/components/PinPad';
import { useOperatorStore } from '@/stores/operatorStore';
import { getErrorMessage } from '@/lib/api';

const MAX_PIN_LENGTH = 6;

type Step = 'enter' | 'confirm';

export function PinSetupPage() {
  const setupPin = useOperatorStore((s) => s.setupPin);
  const [step, setStep] = useState<Step>('enter');
  const [firstPin, setFirstPin] = useState('');
  const [pin, setPin] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleEnterSubmit() {
    if (pin.length < 4) return;
    setFirstPin(pin);
    setPin('');
    setError(null);
    setStep('confirm');
  }

  async function handleConfirmSubmit() {
    if (pin.length < 4) return;

    if (pin !== firstPin) {
      setError('PINs do not match. Try again.');
      setPin('');
      setStep('enter');
      setFirstPin('');
      return;
    }

    setError(null);
    setSubmitting(true);

    try {
      await setupPin(pin);
    } catch (err) {
      setError(getErrorMessage(err));
      setPin('');
      setStep('enter');
      setFirstPin('');
    } finally {
      setSubmitting(false);
    }
  }

  function handleSubmit() {
    if (step === 'enter') {
      void handleEnterSubmit();
    } else {
      void handleConfirmSubmit();
    }
  }

  return (
    <div className="flex h-screen items-center justify-center bg-gray-50">
      <div className="w-full max-w-xs">
        <div className="mb-8 text-center">
          <h2 className="text-xl font-bold text-gray-900">
            {step === 'enter' ? 'Set Your PIN' : 'Confirm Your PIN'}
          </h2>
          <p className="mt-1 text-sm text-gray-500">
            {step === 'enter'
              ? 'Create a 4-6 digit PIN for quick sign-in'
              : 'Enter the same PIN again to confirm'}
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
          onSubmit={handleSubmit}
          disabled={submitting}
        />
      </div>
    </div>
  );
}
