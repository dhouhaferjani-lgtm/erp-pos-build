import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { fetchToleranceReceiptsForShift, type ToleranceReceiptRow } from '@/api/toleranceApi';

interface ToleranceDrillDownProps {
  shiftId: string;
  totalAmount: string;
  writeoffCount: number;
  currencyCode: string;
}

export function ToleranceDrillDown({
  shiftId,
  totalAmount,
  writeoffCount,
  currencyCode,
}: ToleranceDrillDownProps) {
  const { t } = useTranslation('pos');
  const [open, setOpen] = useState(false);
  const [rows, setRows] = useState<ToleranceReceiptRow[] | null>(null);
  const [loading, setLoading] = useState(false);

  const toggle = async () => {
    if (!open && rows === null) {
      setLoading(true);
      try {
        const data = await fetchToleranceReceiptsForShift(shiftId);
        setRows(data);
      } finally {
        setLoading(false);
      }
    }
    setOpen((prev) => !prev);
  };

  return (
    <div
      className="rounded-sm border border-warning-subtle bg-warning-surface p-3 text-sm"
      data-testid="tolerance-drill"
    >
      <button
        type="button"
        onClick={() => void toggle()}
        data-testid="tolerance-drill-toggle"
        className="flex w-full items-center justify-between gap-2"
      >
        <span className="flex items-center gap-1">
          {open ? (
            <ChevronDown className="h-4 w-4" />
          ) : (
            <ChevronRight className="h-4 w-4" />
          )}
          {t('cash_count.tolerance.row_label', {
            defaultValue: 'Tolerance write-offs',
          })}
        </span>
        <span className="font-semibold">
          {totalAmount} {currencyCode} ({writeoffCount})
        </span>
      </button>

      {open && (
        <div className="mt-2 space-y-1">
          {loading && (
            <p className="text-xs">
              {t('common.loading', { defaultValue: 'Loading…' })}
            </p>
          )}
          {!loading && rows !== null && rows.length === 0 && (
            <p className="text-xs">
              {t('cash_count.tolerance.empty', {
                defaultValue: 'No write-offs recorded.',
              })}
            </p>
          )}
          {!loading && rows !== null && rows.length > 0 && (
            <table className="w-full text-xs">
              <thead>
                <tr>
                  <th className="text-left">
                    {t('cash_count.tolerance.col_receipt', {
                      defaultValue: 'Receipt',
                    })}
                  </th>
                  <th className="text-left">
                    {t('cash_count.tolerance.col_cashier', {
                      defaultValue: 'Cashier',
                    })}
                  </th>
                  <th className="text-left">
                    {t('cash_count.tolerance.col_time', {
                      defaultValue: 'Time',
                    })}
                  </th>
                  <th className="text-right">
                    {t('cash_count.tolerance.col_writeoff', {
                      defaultValue: 'Write-off',
                    })}
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.receiptId}>
                    <td>{r.receiptNumber}</td>
                    <td>{r.cashierName}</td>
                    <td>{new Date(r.occurredAt).toLocaleTimeString()}</td>
                    <td className="text-right">{r.writeoffAmount}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </div>
  );
}
