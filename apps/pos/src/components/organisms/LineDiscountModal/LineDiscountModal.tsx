import { useState, useCallback, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowLeft } from 'lucide-react';
import { apiPost } from '@/lib/api';
import { cn } from '@/lib/utils';
import type { Operator } from '@/stores/operatorStore';

type DiscountType = 'percentage' | 'fixed';

export interface LineDiscountModalProps {
  isOpen: boolean;
  onClose: () => void;
  onApply: (data: {
    type: DiscountType;
    value: string;
    reason: string;
  }) => void;
  itemName: string;
  canDiscount: boolean;
  maxDiscountPercent: number;
}

const NUMPAD_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0', 'C'];
const PIN_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'C'];

export function LineDiscountModal({
  isOpen,
  onClose,
  onApply,
  itemName,
  canDiscount,
  maxDiscountPercent,
}: LineDiscountModalProps) {
  const { t } = useTranslation('pos');
  const [discountType, setDiscountType] = useState<DiscountType>('percentage');
  const [value, setValue] = useState('');
  const [reason, setReason] = useState('');

  // Manager approval state
  const [needsApproval, setNeedsApproval] = useState(false);
  const [managerPin, setManagerPin] = useState('');
  const [managerError, setManagerError] = useState<string | null>(null);
  const [verifyingPin, setVerifyingPin] = useState(false);

  // Reset state when modal opens/closes
  useEffect(() => {
    if (isOpen) {
      setValue('');
      setReason('');
      setDiscountType('percentage');
      setNeedsApproval(false);
      setManagerPin('');
      setManagerError(null);
    }
  }, [isOpen]);

  // Escape closes the view, but not while the manager PIN entry is active
  useEffect(() => {
    if (!isOpen || needsApproval) return;
    const handleEscape = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handleEscape);
    return () => window.removeEventListener('keydown', handleEscape);
  }, [isOpen, needsApproval, onClose]);

  const handleNumpadPress = useCallback((key: string) => {
    if (key === 'C') {
      setValue('');
      return;
    }
    setValue((prev) => {
      if (key === '.' && prev.includes('.')) return prev;
      if (prev === '0' && key !== '.') return key;
      return prev + key;
    });
  }, []);

  const handlePinPress = useCallback((key: string) => {
    if (key === '') return;
    if (key === 'C') {
      setManagerPin('');
      setManagerError(null);
      return;
    }
    setManagerPin((prev) => {
      if (prev.length >= 6) return prev;
      return prev + key;
    });
  }, []);

  const numericValue = parseFloat(value) || 0;
  const percentageExceeded =
    discountType === 'percentage' && numericValue > maxDiscountPercent;
  const needsManagerOverride = !canDiscount || percentageExceeded;
  const isValid = numericValue > 0;

  const handleApply = useCallback(() => {
    if (!isValid) return;

    if (needsManagerOverride) {
      setNeedsApproval(true);
      setManagerPin('');
      setManagerError(null);
      return;
    }

    onApply({
      type: discountType,
      value,
      reason: reason.trim(),
    });

    setValue('');
    setReason('');
    onClose();
  }, [isValid, needsManagerOverride, discountType, value, reason, onApply, onClose]);

  const handleManagerPinSubmit = useCallback(async () => {
    if (managerPin.length < 4) return;
    setManagerError(null);
    setVerifyingPin(true);
    try {
      const manager = await apiPost<Operator>('/pos/auth/verify-pin', { pin: managerPin });

      if (!manager.can_discount) {
        setManagerError(t('discount.managerDenied'));
        return;
      }

      const managerMax = manager.max_discount_percent ?? 100;
      if (discountType === 'percentage' && parseFloat(value) > managerMax) {
        setManagerError(t('discount.managerDenied'));
        return;
      }

      onApply({
        type: discountType,
        value,
        reason: reason.trim(),
      });
      setValue('');
      setReason('');
      setManagerPin('');
      setNeedsApproval(false);
      onClose();
    } catch {
      setManagerError(t('discount.invalidPin'));
    } finally {
      setVerifyingPin(false);
    }
  }, [managerPin, discountType, value, reason, onApply, onClose, t]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-gray-50 text-gray-900">
      {/* Header */}
      <div className="flex shrink-0 items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
        <button
          onClick={onClose}
          className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-500 hover:bg-gray-100 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('discount.cancel')}
        </button>
        <span className="text-lg font-bold text-gray-900">
          {t('cart.itemDiscount')}
        </span>
        <div className="w-20" />
      </div>

      <div className="flex min-h-0 flex-1 gap-4 p-4">
        {/* Left: Toggle + Value + Numpad OR Manager PIN */}
        <div className="flex flex-[2] flex-col">
          {needsApproval ? (
            <>
              {/* Manager approval mode */}
              <div className="mb-3 text-center">
                <h3 className="text-lg font-bold text-gray-900">
                  {t('discount.managerApproval')}
                </h3>
                <p className="mt-1 text-sm text-gray-500">
                  {t('discount.enterManagerPin')}
                </p>
              </div>

              {/* PIN display */}
              <div className="mb-3 rounded-xl bg-gray-50 px-4 py-4 text-center text-4xl font-bold text-gray-900">
                {'•'.repeat(managerPin.length) || '\u00A0'}
              </div>

              {/* PIN numpad */}
              <div className="grid flex-1 grid-cols-3 gap-2">
                {PIN_KEYS.map((key, idx) => (
                  <button
                    key={`pin-${String(idx)}`}
                    onClick={() => handlePinPress(key)}
                    disabled={key === ''}
                    className={cn(
                      'flex items-center justify-center rounded-xl text-xl font-semibold transition-colors',
                      key === ''
                        ? 'invisible'
                        : key === 'C'
                          ? 'bg-red-50 text-red-700 hover:bg-red-100'
                          : 'bg-gray-50 text-gray-900 hover:bg-gray-100 active:bg-gray-200',
                    )}
                  >
                    {key}
                  </button>
                ))}
              </div>
            </>
          ) : (
            <>
              {/* Normal discount entry mode */}
              {/* Discount type toggle */}
              <div className="mb-3 flex rounded-lg bg-gray-100 p-1">
                <button
                  onClick={() => setDiscountType('percentage')}
                  className={cn(
                    'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    discountType === 'percentage'
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-700 hover:text-gray-900',
                  )}
                >
                  {t('discount.percentage')}
                </button>
                <button
                  onClick={() => setDiscountType('fixed')}
                  className={cn(
                    'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    discountType === 'fixed'
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-700 hover:text-gray-900',
                  )}
                >
                  {t('discount.fixed')}
                </button>
              </div>

              {/* Value display */}
              <div className="mb-3 rounded-xl bg-gray-50 px-4 py-4 text-center text-4xl font-bold text-gray-900">
                {value || '0'}
                {discountType === 'percentage' ? '%' : ''}
              </div>

              {/* Numpad */}
              <div className="grid flex-1 grid-cols-3 gap-2">
                {NUMPAD_KEYS.map((key) => (
                  <button
                    key={key}
                    onClick={() => handleNumpadPress(key)}
                    className={cn(
                      'flex items-center justify-center rounded-xl text-xl font-semibold transition-colors',
                      key === 'C'
                        ? 'bg-red-50 text-red-700 hover:bg-red-100'
                        : 'bg-gray-50 text-gray-900 hover:bg-gray-100 active:bg-gray-200',
                    )}
                  >
                    {key}
                  </button>
                ))}
              </div>
            </>
          )}
        </div>

        {/* Right: Item name + Summary + Reason + Apply/Authorize */}
        <div className="flex flex-[3] flex-col">
          {/* Item name — always visible */}
          <div className="mb-3 rounded-lg bg-gray-50 px-3 py-2 text-center text-sm font-medium text-gray-700">
            {itemName}
          </div>

          {needsApproval ? (
            <>
              {/* Show the discount that will be applied */}
              <div className="mb-3 rounded-lg bg-blue-50 border border-blue-200 p-3 text-center text-sm text-blue-700">
                {discountType === 'percentage'
                  ? `${value}% ${t('cart.itemDiscount').toLowerCase()}`
                  : `${value} ${t('cart.itemDiscount').toLowerCase()}`}
              </div>

              {/* Manager error */}
              {managerError && (
                <div className="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-center text-sm text-red-700">
                  {managerError}
                </div>
              )}

              {/* Spacer */}
              <div className="flex-1" />

              {/* Authorize button */}
              <button
                onClick={() => void handleManagerPinSubmit()}
                disabled={managerPin.length < 4 || verifyingPin}
                className="mb-2 flex min-h-[56px] w-full items-center justify-center rounded-xl bg-blue-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {verifyingPin ? t('discount.verifyingPin') : t('discount.authorize')}
              </button>

              {/* Back button */}
              <button
                onClick={() => {
                  setNeedsApproval(false);
                  setManagerPin('');
                  setManagerError(null);
                }}
                className="flex min-h-[44px] w-full items-center justify-center rounded-xl border border-gray-300 px-6 py-3 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
              >
                {t('cashPayment.back')}
              </button>
            </>
          ) : (
            <>
              {/* Max exceeded warning — shown as info since manager can override */}
              {needsManagerOverride && numericValue > 0 && (
                <div className="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-center text-sm text-amber-700">
                  {!canDiscount
                    ? t('discount.managerApproval')
                    : t('discount.maxExceeded', { max: maxDiscountPercent })}
                </div>
              )}

              {/* Spacer to push content toward center */}
              <div className="flex-1" />

              {/* Reason */}
              <div className="mb-4">
                <label className="mb-1 block text-sm font-medium text-gray-700">
                  {t('discount.reason')}
                </label>
                <input
                  type="text"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>

              {/* Apply button */}
              <button
                onClick={handleApply}
                disabled={!isValid}
                className="flex min-h-[56px] w-full items-center justify-center rounded-xl bg-blue-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {t('cart.applyDiscount')}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
