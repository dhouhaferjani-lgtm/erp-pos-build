import { useState, useMemo, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Banknote,
  CreditCard,
  FileText,
  Building2,
  Wallet,
  Trash2,
  AlertTriangle,
  ArrowLeft,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useCurrency } from '@/lib/currency';
import { NumPad } from '@/components/molecules/NumPad';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import type { AdvancedPaymentLine } from '@/stores/paymentStore';

const METHOD_ICONS: Record<string, typeof Banknote> = {
  CASH: Banknote,
  ESPECES: Banknote,
  CARD: CreditCard,
  CARTE: CreditCard,
  CB: CreditCard,
  CHECK: FileText,
  CHEQUE: FileText,
  TRANSFER: Building2,
  VIREMENT: Building2,
  MOBILE: Wallet,
};

function getMethodIcon(method: PaymentMethod) {
  const code = method.code?.toUpperCase() ?? '';
  return METHOD_ICONS[code] ?? Wallet;
}

function getCompatibleRepositoryTypes(
  method: PaymentMethod,
): PaymentRepository['type'][] {
  if (method.is_physical && !method.has_maturity) {
    return ['cash_register', 'safe'];
  }
  if (method.requires_third_party) {
    return ['bank_account', 'virtual'];
  }
  if (method.has_maturity) {
    return ['safe', 'bank_account'];
  }
  return ['cash_register', 'safe', 'bank_account', 'virtual'];
}

interface PaymentLineItem {
  id: string;
  methodId: string;
  methodName: string;
  amount: number;
  repositoryId: string;
  repositoryName: string;
  reference: string;
  cardLastFour: string;
}

export interface AdvancedPaymentsModalProps {
  isOpen: boolean;
  onClose: () => void;
  total: number;
  subtotal: number;
  taxAmount: number;
  itemCount: number;
  paymentMethods: PaymentMethod[];
  paymentRepositories: PaymentRepository[];
  onComplete: (payments: AdvancedPaymentLine[]) => Promise<void>;
  isProcessing: boolean;
  error: string | null;
}

export function AdvancedPaymentsModal({
  isOpen,
  onClose,
  total,
  subtotal: _subtotal,
  taxAmount: _taxAmount,
  itemCount: _itemCount,
  paymentMethods,
  paymentRepositories,
  onComplete,
  isProcessing,
  error,
}: AdvancedPaymentsModalProps) {
  const { t } = useTranslation('pos');
  const { format, decimals } = useCurrency();
  const [paymentLines, setPaymentLines] = useState<PaymentLineItem[]>([]);
  const [selectedMethodId, setSelectedMethodId] = useState<string | null>(null);
  const [amount, setAmount] = useState('');
  const [repositoryId, setRepositoryId] = useState('');
  const [reference, setReference] = useState('');
  const [cardLastFour, setCardLastFour] = useState('');
  const [validationError, setValidationError] = useState<string | null>(null);

  const activeMethods = useMemo(
    () => paymentMethods.filter((m) => m.is_active),
    [paymentMethods],
  );

  const selectedMethod = useMemo(
    () => activeMethods.find((m) => m.id === selectedMethodId) ?? null,
    [activeMethods, selectedMethodId],
  );

  const compatibleRepositories = useMemo(() => {
    if (!selectedMethod) return [];
    const types = getCompatibleRepositoryTypes(selectedMethod);
    return paymentRepositories.filter(
      (r) => r.is_active && types.includes(r.type),
    );
  }, [selectedMethod, paymentRepositories]);

  // Auto-select repository when only one compatible
  const effectiveRepositoryId = useMemo(() => {
    if (repositoryId) return repositoryId;
    if (compatibleRepositories.length === 1) return compatibleRepositories[0]!.id;
    return '';
  }, [repositoryId, compatibleRepositories]);

  const totalPaid = useMemo(
    () => paymentLines.reduce((sum, l) => sum + l.amount, 0),
    [paymentLines],
  );

  const remaining = Math.max(0, total - totalPaid);
  const overpayment = Math.max(0, totalPaid - total);
  const isFullyPaid = totalPaid >= total;

  const handleSelectMethod = useCallback(
    (methodId: string) => {
      setSelectedMethodId(methodId);
      setAmount(remaining > 0 ? remaining.toFixed(decimals) : '');
      setRepositoryId('');
      setReference('');
      setCardLastFour('');
      setValidationError(null);
    },
    [remaining, decimals],
  );

  const handlePayRemaining = useCallback(() => {
    if (remaining > 0) {
      setAmount(remaining.toFixed(decimals));
    }
  }, [remaining, decimals]);

  const handleAddPayment = useCallback(() => {
    if (!selectedMethod) {
      setValidationError(t('advancedPayments.methodRequired'));
      return;
    }

    const parsedAmount = parseFloat(amount);
    if (!amount || isNaN(parsedAmount) || parsedAmount <= 0) {
      setValidationError(t('advancedPayments.amountRequired'));
      return;
    }

    if (!effectiveRepositoryId) {
      setValidationError(t('advancedPayments.repositoryRequired'));
      return;
    }

    const repo = paymentRepositories.find((r) => r.id === effectiveRepositoryId);

    const line: PaymentLineItem = {
      id: crypto.randomUUID(),
      methodId: selectedMethod.id,
      methodName: selectedMethod.name,
      amount: parsedAmount,
      repositoryId: effectiveRepositoryId,
      repositoryName: repo?.name ?? '',
      reference,
      cardLastFour,
    };

    setPaymentLines((prev) => [...prev, line]);
    setSelectedMethodId(null);
    setAmount('');
    setRepositoryId('');
    setReference('');
    setCardLastFour('');
    setValidationError(null);
  }, [
    selectedMethod,
    amount,
    effectiveRepositoryId,
    paymentRepositories,
    reference,
    cardLastFour,
    t,
  ]);

  const handleRemoveLine = useCallback((lineId: string) => {
    setPaymentLines((prev) => prev.filter((l) => l.id !== lineId));
  }, []);

  const handleComplete = useCallback(async () => {
    if (!isFullyPaid) {
      setValidationError(t('advancedPayments.insufficientPayment'));
      return;
    }

    const payments: AdvancedPaymentLine[] = paymentLines.map((l) => ({
      payment_method_id: l.methodId,
      amount: l.amount,
      repository_id: l.repositoryId,
      ...(l.cardLastFour ? { card_last_four: l.cardLastFour } : {}),
      ...(l.reference ? { transaction_reference: l.reference } : {}),
    }));

    await onComplete(payments);
  }, [isFullyPaid, paymentLines, onComplete, t]);

  const handleClose = useCallback(() => {
    if (isProcessing) return;
    setPaymentLines([]);
    setSelectedMethodId(null);
    setAmount('');
    setRepositoryId('');
    setReference('');
    setCardLastFour('');
    setValidationError(null);
    onClose();
  }, [isProcessing, onClose]);

  const showReference = selectedMethod?.has_maturity || selectedMethod?.requires_third_party;
  const showCardLastFour = selectedMethod?.requires_third_party;

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-gray-50 text-gray-900">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
        <button
          onClick={handleClose}
          className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-500 hover:bg-gray-100 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('advancedPayments.back')}
        </button>
        <div className="flex items-center gap-2 text-lg font-bold">
          <Wallet className="h-5 w-5" />
          {t('advancedPayments.title')}
        </div>
        <div className="w-20" />
      </div>

      {/* Main 3-column layout */}
      <div className="flex min-h-0 flex-1 overflow-hidden">
        {/* LEFT COLUMN (30%): Method selection + config */}
        <div className="flex w-[30%] flex-col border-r border-gray-200 bg-white p-4">
          <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
            {t('advancedPayments.selectMethod')}
          </p>
          <div className="flex flex-col gap-2">
            {activeMethods.map((method) => {
              const Icon = getMethodIcon(method);
              const isSelected = selectedMethodId === method.id;
              return (
                <button
                  key={method.id}
                  onClick={() => handleSelectMethod(method.id)}
                  className={cn(
                    'flex min-h-[52px] items-center gap-3 rounded-xl border-2 px-4 py-3 text-left transition-colors',
                    isSelected
                      ? 'border-primary-500 bg-primary-50 text-primary-700'
                      : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50',
                  )}
                >
                  <Icon className="h-5 w-5 shrink-0" />
                  <span className="text-sm font-medium">{method.name}</span>
                </button>
              );
            })}
          </div>

          {/* Config fields — shown when method selected */}
          {selectedMethod && (
            <div className="mt-4 space-y-3 border-t border-gray-200 pt-4">
              {/* Repository dropdown */}
              {compatibleRepositories.length > 1 && (
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-500">
                    {t('advancedPayments.repository')}
                  </label>
                  <select
                    value={effectiveRepositoryId}
                    onChange={(e) => {
                      setRepositoryId(e.target.value);
                      setValidationError(null);
                    }}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                  >
                    <option value="">
                      {t('advancedPayments.selectRepository')}
                    </option>
                    {compatibleRepositories.map((r) => (
                      <option key={r.id} value={r.id}>
                        {r.name}
                      </option>
                    ))}
                  </select>
                </div>
              )}

              {/* Reference field */}
              {showReference && (
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-500">
                    {t('advancedPayments.reference')}
                  </label>
                  <input
                    type="text"
                    value={reference}
                    onChange={(e) => setReference(e.target.value)}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                  />
                </div>
              )}

              {/* Card last 4 */}
              {showCardLastFour && (
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-500">
                    {t('advancedPayments.cardLastFour')}
                  </label>
                  <input
                    type="text"
                    maxLength={4}
                    value={cardLastFour}
                    onChange={(e) =>
                      setCardLastFour(e.target.value.replace(/\D/g, ''))
                    }
                    className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                  />
                </div>
              )}
            </div>
          )}

          {/* Add Payment button — pinned to bottom */}
          {selectedMethod && (
            <button
              onClick={handleAddPayment}
              className="mt-auto rounded-xl bg-primary-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-primary-700 active:bg-primary-800"
            >
              {t('advancedPayments.addPayment')}
            </button>
          )}
        </div>

        {/* CENTER COLUMN (35%): Amount + NumPad */}
        <div className="flex w-[35%] flex-col bg-gray-50 p-4">
          {/* Amount display */}
          <div className="mb-3 text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-gray-500">
              {t('advancedPayments.amount')}
            </p>
            <p className="mt-1 text-3xl font-bold text-gray-900">
              {amount ? format(parseFloat(amount)) : format(0)}
            </p>
          </div>

          {/* Pay Remaining pill */}
          {remaining > 0 && (
            <div className="mb-3 text-center">
              <button
                onClick={handlePayRemaining}
                className="inline-flex rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-primary-700"
              >
                {t('advancedPayments.payRemaining')}: {format(remaining)}
              </button>
            </div>
          )}

          {/* NumPad */}
          <div className="flex-1">
            <NumPad
              value={amount}
              onChange={(val) => {
                setAmount(val);
                setValidationError(null);
              }}
            />
          </div>
        </div>

        {/* RIGHT COLUMN (35%): Balance + Payments list + Complete */}
        <div className="flex w-[35%] flex-col border-l border-gray-200 bg-white p-4">
          {/* Total due card */}
          <div className="mb-4 rounded-xl bg-primary-600 p-4 text-white">
            <p className="text-xs font-medium uppercase tracking-wider opacity-80">
              {t('advancedPayments.totalDue')}
            </p>
            <p className="mt-1 text-3xl font-bold">{format(total)}</p>
          </div>

          {/* Payment lines — scrollable */}
          <div className="mb-3 flex-1 overflow-y-auto">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
              {t('advancedPayments.addedPayments')}
            </p>
            {paymentLines.length === 0 ? (
              <p className="py-6 text-center text-sm text-gray-400">
                {t('advancedPayments.noPayments')}
              </p>
            ) : (
              <div className="space-y-2">
                {paymentLines.map((line) => (
                  <div
                    key={line.id}
                    className="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5"
                  >
                    <div>
                      <p className="text-sm font-medium text-gray-900">
                        {line.methodName}
                      </p>
                      <p className="text-xs text-gray-500">
                        {line.repositoryName}
                        {line.cardLastFour && ` · *${line.cardLastFour}`}
                        {line.reference && ` · ${line.reference}`}
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold text-gray-900">
                        {format(line.amount)}
                      </span>
                      <button
                        onClick={() => handleRemoveLine(line.id)}
                        className="rounded-lg p-1.5 text-red-500 hover:bg-red-50"
                        aria-label={t('advancedPayments.delete')}
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Footer — balance + error + complete */}
          <div className="border-t border-gray-200 pt-3">
            <div className="mb-2 space-y-1 text-sm">
              <div className="flex justify-between">
                <span className="text-gray-500">
                  {t('advancedPayments.totalPaid')}
                </span>
                <span className="font-medium text-gray-900">
                  {format(totalPaid)}
                </span>
              </div>
              {remaining > 0 && (
                <div className="flex justify-between text-orange-600">
                  <span>{t('advancedPayments.remaining')}</span>
                  <span className="font-medium">{format(remaining)}</span>
                </div>
              )}
              {overpayment > 0 && (
                <div className="flex justify-between text-green-600">
                  <span>{t('advancedPayments.changeDue')}</span>
                  <span className="font-medium">{format(overpayment)}</span>
                </div>
              )}
            </div>

            {(validationError || error) && (
              <div className="mb-2 flex items-center gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                <AlertTriangle className="h-4 w-4 shrink-0" />
                {validationError || error}
              </div>
            )}

            <button
              onClick={() => void handleComplete()}
              disabled={!isFullyPaid || isProcessing}
              className="w-full rounded-xl bg-green-600 px-6 py-3.5 text-lg font-semibold text-white transition-colors hover:bg-green-700 active:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isProcessing
                ? t('advancedPayments.processing')
                : t('advancedPayments.completeTransaction')}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
