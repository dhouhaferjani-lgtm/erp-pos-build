import { useState } from 'react';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { getErrorMessage } from '@/lib/api';

export function HomePage() {
  const { shift, terminal, openShift, isLoading } = useTerminalStore();
  const operator = useOperatorStore((s) => s.operator);
  const [openingCash, setOpeningCash] = useState('0.00');
  const [error, setError] = useState<string | null>(null);

  async function handleOpenShift() {
    setError(null);
    try {
      await openShift(openingCash, operator?.id);
    } catch (err) {
      setError(getErrorMessage(err));
    }
  }

  if (!shift) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="w-full max-w-sm text-center">
          <h2 className="text-xl font-bold text-gray-900">Open a Shift</h2>
          <p className="mt-1 text-sm text-gray-500">
            Terminal: {terminal?.name ?? 'Unknown'}
          </p>

          <div className="mt-6">
            <label htmlFor="openingCash" className="block text-sm font-medium text-gray-700">
              Opening Cash
            </label>
            <input
              id="openingCash"
              type="number"
              step="0.01"
              min="0"
              value={openingCash}
              onChange={(e) => setOpeningCash(e.target.value)}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-center text-lg focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
            />
          </div>

          {error && (
            <div className="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{error}</div>
          )}

          <button
            onClick={() => void handleOpenShift()}
            disabled={isLoading}
            className="mt-4 w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {isLoading ? 'Opening...' : 'Open Shift'}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="flex h-full">
      {/* Product grid placeholder */}
      <div className="flex flex-1 items-center justify-center border-r border-gray-200 bg-gray-50">
        <div className="text-center text-gray-400">
          <p className="text-lg font-medium">Menu</p>
          <p className="text-sm">Product grid will go here</p>
        </div>
      </div>

      {/* Cart placeholder */}
      <div className="flex w-80 flex-col bg-white">
        <div className="flex-1 p-4">
          <div className="text-center text-gray-400">
            <p className="text-lg font-medium">Cart</p>
            <p className="text-sm">Cart items will appear here</p>
          </div>
        </div>

        <div className="border-t border-gray-200 p-4">
          <div className="flex justify-between text-sm text-gray-600">
            <span>Shift #{shift.shift_number}</span>
            <span>Opening: {shift.opening_cash}</span>
          </div>
        </div>
      </div>
    </div>
  );
}
