import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { getErrorMessage } from '@/lib/api';

export function Header() {
  const logout = useAuthStore((s) => s.logout);
  const terminal = useTerminalStore((s) => s.terminal);
  const shift = useTerminalStore((s) => s.shift);
  const closeShift = useTerminalStore((s) => s.closeShift);

  const operator = useOperatorStore((s) => s.operator);
  const lockScreen = useOperatorStore((s) => s.lock);
  const clearOperator = useOperatorStore((s) => s.clearOperator);

  const [showCloseShift, setShowCloseShift] = useState(false);
  const [actualCash, setActualCash] = useState('');
  const [closing, setClosing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleCloseShift() {
    setError(null);
    setClosing(true);
    try {
      await closeShift(actualCash);
      setShowCloseShift(false);
      setActualCash('');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setClosing(false);
    }
  }

  return (
    <>
      <header className="flex h-12 items-center justify-between border-b border-gray-200 bg-white px-4">
        <div className="flex items-center gap-3">
          <h1 className="text-lg font-bold text-gray-900">IziPOS</h1>
          {terminal && (
            <span className="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
              {terminal.name}
            </span>
          )}
        </div>

        <div className="flex items-center gap-3">
          {shift ? (
            <button
              onClick={() => setShowCloseShift(true)}
              className="rounded bg-green-50 px-2 py-0.5 text-sm text-green-700 hover:bg-green-100"
            >
              Shift #{shift.shift_number}
            </button>
          ) : (
            <span className="text-sm text-gray-500">No shift open</span>
          )}

          {operator && (
            <span className="text-sm font-medium text-gray-700">{operator.name}</span>
          )}

          <button
            onClick={clearOperator}
            className="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-50"
            title="Switch operator"
          >
            Switch
          </button>

          <button
            onClick={lockScreen}
            className="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-50"
            title="Lock screen"
          >
            Lock
          </button>

          <button
            onClick={logout}
            className="text-sm text-gray-400 hover:text-gray-600"
          >
            Logout
          </button>
        </div>
      </header>

      {/* Close Shift Modal */}
      {showCloseShift && shift && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">Close Shift</h3>
            <p className="mt-1 text-sm text-gray-500">
              Shift #{shift.shift_number} &middot; Opening: {shift.opening_cash}
            </p>

            <div className="mt-4">
              <label htmlFor="actualCash" className="block text-sm font-medium text-gray-700">
                Actual Cash in Drawer
              </label>
              <input
                id="actualCash"
                type="number"
                step="0.01"
                min="0"
                value={actualCash}
                onChange={(e) => setActualCash(e.target.value)}
                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                autoFocus
              />
            </div>

            {error && (
              <div className="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700">{error}</div>
            )}

            <div className="mt-4 flex gap-3">
              <button
                onClick={() => setShowCloseShift(false)}
                className="flex-1 rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={() => void handleCloseShift()}
                disabled={closing || !actualCash}
                className="flex-1 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
              >
                {closing ? 'Closing...' : 'Close Shift'}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
