import { useTranslation } from 'react-i18next';
import { Printer } from 'lucide-react';
import { Modal } from '@/components/pos/Modal';
import { Button } from '@/components/ui';
import { useCurrency } from '@/lib/currency';
import { formatQuantity } from '@/lib/quantity';
import type { ShiftReceipt, ShiftReceiptLine } from '@/api/reportApi';

function lineName(line: ShiftReceiptLine): string {
  return line.product?.name ?? line.product_name ?? '—';
}

export interface SaleDetailModalProps {
  receipt: ShiftReceipt | null;
  isOpen: boolean;
  onClose: () => void;
  /** Optional reprint action — kept available from the detail view. */
  onReprint?: (receiptId: string) => void;
  reprinting?: boolean;
}

/**
 * SaleDetailModal — read-only view of a past sale's line items + totals +
 * payments. Lets the cashier SEE a ticket without printing a DUPLICATA (viewing
 * on-screen is not a fiscal reprint). Scoped to whatever the sales-history list
 * already exposes (current shift).
 */
export function SaleDetailModal({ receipt, isOpen, onClose, onReprint, reprinting }: SaleDetailModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  if (!receipt) return null;

  const time = receipt.posted_at ?? receipt.created_at;
  const isReturn = receipt.receipt_type === 'return';

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      size="lg"
      title={`${t('reports.saleDetailTitle')} · ${receipt.receipt_number}`}
      footer={
        onReprint && (
          <div className="flex justify-end">
            <Button
              variant="secondary"
              leftIcon={<Printer className="h-4 w-4" />}
              loading={reprinting}
              onClick={() => onReprint(receipt.id)}
            >
              {t('reports.reprint')}
            </Button>
          </div>
        )
      }
    >
      <div className="space-y-4">
        {/* Meta */}
        <div className="flex items-center justify-between text-sm text-ink-muted">
          <span>{time ? new Date(time).toLocaleString() : '—'}</span>
          {receipt.is_voided ? (
            <span className="rounded-pill bg-danger-surface px-2 py-0.5 text-xs font-medium text-danger-strong">
              {t('reports.voided')}
            </span>
          ) : (
            <span className="rounded-pill bg-surface-sunken px-2 py-0.5 text-xs font-medium text-ink-muted">
              {isReturn ? t('reports.typeReturn') : t('reports.typeSale')}
            </span>
          )}
        </div>

        {/* Lines */}
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border-subtle text-left text-xs text-ink-muted">
              <th className="pb-2">{t('reports.items')}</th>
              <th className="pb-2 text-center">{t('reports.qty')}</th>
              <th className="pb-2 text-right">{t('reports.unitPrice')}</th>
              <th className="pb-2 text-right">{t('reports.lineTotal')}</th>
            </tr>
          </thead>
          <tbody>
            {receipt.lines.map((line) => (
              <tr key={line.id} className="border-b border-border-subtle/60">
                <td className="py-2 text-ink">{lineName(line)}</td>
                <td className="py-2 text-center font-mono tabular-nums text-ink">
                  {formatQuantity(String(line.quantity), line.quantity_decimals)}
                </td>
                <td className="py-2 text-right font-mono tabular-nums text-ink-muted">
                  {format(line.unit_price)}
                </td>
                <td className="py-2 text-right font-mono tabular-nums text-ink">
                  {format(line.line_total)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        {/* Totals */}
        <div className="ml-auto w-full max-w-xs space-y-1 text-sm">
          <div className="flex justify-between text-ink-muted">
            <span>{t('reports.subtotal')}</span>
            <span className="font-mono tabular-nums">{format(receipt.subtotal)}</span>
          </div>
          <div className="flex justify-between text-ink-muted">
            <span>{t('reports.taxLabel')}</span>
            <span className="font-mono tabular-nums">{format(receipt.tax_amount)}</span>
          </div>
          <div className="flex justify-between border-t border-border-subtle pt-1 text-base font-bold text-ink-strong">
            <span>{t('reports.total')}</span>
            <span className="font-mono tabular-nums">
              {isReturn && '−'}
              {format(receipt.total)}
            </span>
          </div>
        </div>

        {/* Payments */}
        {receipt.payments.length > 0 && (
          <div className="border-t border-border-subtle pt-3">
            <p className="mb-1 text-xs font-medium uppercase text-ink-faint">{t('reports.paymentLabel')}</p>
            <div className="flex flex-col gap-1">
              {receipt.payments.map((p) => (
                <div key={p.id} className="flex justify-between text-sm">
                  <span className="text-ink">
                    {p.payment_type}
                    {p.card_last_four ? ` ···· ${p.card_last_four}` : ''}
                    {p.transaction_reference ? ` · ${p.transaction_reference}` : ''}
                  </span>
                  <span className="font-mono tabular-nums text-ink">{format(p.amount)}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </Modal>
  );
}
