import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { getErrorMessage } from '@/lib/api';
import { useTerminalStore } from '@/stores/terminalStore';
import { useCartStore } from '@/stores/cartStore';

interface CloseShiftModalProps {
  isOpen: boolean;
  onClose: () => void;
  shift: {
    shift_number: number;
    opening_cash: string;
  };
}

export function CloseShiftModal({ isOpen, onClose, shift }: CloseShiftModalProps) {
  const { t } = useTranslation(['pos', 'common']);
  const closeShift = useTerminalStore((s) => s.closeShift);

  const [actualCash, setActualCash] = useState('');
  const [closing, setClosing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleCloseShift() {
    setError(null);
    setClosing(true);
    try {
      await closeShift(actualCash);
      useCartStore.getState().clearCart();
      onClose();
      setActualCash('');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setClosing(false);
    }
  }

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
        <h3 className="text-lg font-bold text-gray-900">{t('pos:header.closeShift')}</h3>
        <p className="mt-1 text-sm text-gray-500">
          {t('pos:header.shiftOpening', { number: shift.shift_number, amount: shift.opening_cash })}
        </p>

        <div className="mt-4">
          <label htmlFor="actualCash" className="block text-sm font-medium text-gray-700">
            {t('pos:header.actualCash')}
          </label>
          <input
            id="actualCash"
            type="number"
            step="0.01"
            min="0"
            value={actualCash}
            onChange={(e) => setActualCash(e.target.value)}
            className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
            autoFocus
          />
        </div>

        {error && (
          <div className="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700">{error}</div>
        )}

        <div className="mt-4 flex gap-3">
          <button
            onClick={onClose}
            className="flex-1 rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            {t('common:cancel')}
          </button>
          <button
            onClick={() => void handleCloseShift()}
            disabled={closing || !actualCash}
            className="flex-1 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
          >
            {closing ? t('pos:header.closing') : t('pos:header.closeShift')}
          </button>
        </div>
      </div>
    </div>
  );
}
