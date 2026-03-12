import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from './Modal';
import { cn } from '@/lib/utils';

type DiscountType = 'percentage' | 'fixed';
type TabType = 'lineItem' | 'transaction';

interface DiscountModalProps {
  isOpen: boolean;
  onClose: () => void;
  onApplyLineDiscount: (data: {
    type: DiscountType;
    value: string;
    reason: string;
  }) => void;
  onApplyTransactionDiscount: (data: {
    amount: string;
    reason: string;
  }) => void;
  maxDiscountPercent: number;
  requiresReason: boolean;
}

const NUMPAD_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0', 'C'];

export function DiscountModal({
  isOpen,
  onClose,
  onApplyLineDiscount,
  onApplyTransactionDiscount,
  maxDiscountPercent,
  requiresReason,
}: DiscountModalProps) {
  const { t } = useTranslation('pos');
  const [tab, setTab] = useState<TabType>('lineItem');
  const [discountType, setDiscountType] = useState<DiscountType>('percentage');
  const [value, setValue] = useState('');
  const [reason, setReason] = useState('');

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

  const numericValue = parseFloat(value) || 0;
  const percentageExceeded =
    discountType === 'percentage' && numericValue > maxDiscountPercent;
  const isValid =
    numericValue > 0 && !percentageExceeded && (!requiresReason || reason.trim().length > 0);

  const handleApply = useCallback(() => {
    if (!isValid) return;

    if (tab === 'lineItem') {
      onApplyLineDiscount({
        type: discountType,
        value,
        reason: reason.trim(),
      });
    } else {
      onApplyTransactionDiscount({
        amount: value,
        reason: reason.trim(),
      });
    }

    setValue('');
    setReason('');
    onClose();
  }, [
    isValid,
    tab,
    discountType,
    value,
    reason,
    onApplyLineDiscount,
    onApplyTransactionDiscount,
    onClose,
  ]);

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('discount.title')} size="md">
      <div className="space-y-4">
        {/* Tabs */}
        <div className="flex rounded-lg bg-gray-100 p-1">
          <button
            onClick={() => setTab('lineItem')}
            className={cn(
              'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
              tab === 'lineItem'
                ? 'bg-white text-gray-900 shadow-sm'
                : 'text-gray-500 hover:text-gray-700',
            )}
          >
            {t('discount.lineItem')}
          </button>
          <button
            onClick={() => setTab('transaction')}
            className={cn(
              'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
              tab === 'transaction'
                ? 'bg-white text-gray-900 shadow-sm'
                : 'text-gray-500 hover:text-gray-700',
            )}
          >
            {t('discount.transaction')}
          </button>
        </div>

        {/* Discount type toggle (only for line-item) */}
        {tab === 'lineItem' && (
          <div className="flex rounded-lg bg-gray-100 p-1">
            <button
              onClick={() => setDiscountType('percentage')}
              className={cn(
                'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                discountType === 'percentage'
                  ? 'bg-white text-gray-900 shadow-sm'
                  : 'text-gray-500 hover:text-gray-700',
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
                  : 'text-gray-500 hover:text-gray-700',
              )}
            >
              {t('discount.fixed')}
            </button>
          </div>
        )}

        {/* Value display */}
        <div className="rounded-xl bg-gray-50 px-4 py-3 text-center text-3xl font-bold text-gray-900">
          {value || '0'}
          {tab === 'lineItem' && discountType === 'percentage' ? '%' : ''}
        </div>

        {/* Max exceeded warning */}
        {percentageExceeded && (
          <p className="text-center text-sm text-red-600">
            {t('discount.maxExceeded', { max: maxDiscountPercent })}
          </p>
        )}

        {/* Numpad */}
        <div className="grid grid-cols-3 gap-2">
          {NUMPAD_KEYS.map((key) => (
            <button
              key={key}
              onClick={() => handleNumpadPress(key)}
              className={cn(
                'flex h-14 items-center justify-center rounded-xl text-lg font-semibold transition-colors',
                key === 'C'
                  ? 'bg-red-50 text-red-600 hover:bg-red-100'
                  : 'bg-gray-50 text-gray-900 hover:bg-gray-100 active:bg-gray-200',
              )}
            >
              {key}
            </button>
          ))}
        </div>

        {/* Reason */}
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">
            {t('discount.reason')}
            {requiresReason && <span className="text-red-500"> *</span>}
          </label>
          <input
            type="text"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          />
        </div>

        {/* Apply */}
        <button
          onClick={handleApply}
          disabled={!isValid}
          className="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-blue-600 px-6 py-3 text-base font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {t('discount.apply')}
        </button>
      </div>
    </Modal>
  );
}
