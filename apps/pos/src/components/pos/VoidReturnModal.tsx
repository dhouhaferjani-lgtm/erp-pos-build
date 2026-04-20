import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from './Modal';
import { Search, AlertTriangle, WifiOff } from 'lucide-react';
import { apiGet, apiPost, getErrorMessage } from '@/lib/api';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useAuthStore } from '@/stores/authStore';
import { getDatabase } from '@/lib/db';
import { queryAll } from '@/lib/db';
import { useCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';

interface ReceiptLine {
  id: string;
  product_name: string;
  quantity: number;
  unit_price: string;
  line_total: string;
}

interface ReceiptDetail {
  id: string;
  receipt_number: string;
  total: string;
  status: string;
  lines: ReceiptLine[];
}

interface VoidReturnModalProps {
  isOpen: boolean;
  onClose: () => void;
}

type ActionTab = 'void' | 'return';

export function VoidReturnModal({ isOpen, onClose }: VoidReturnModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const isOnline = useConnectivityStore((s) => s.isOnline);
  const [searchQuery, setSearchQuery] = useState('');
  const [receipt, setReceipt] = useState<ReceiptDetail | null>(null);
  const [isLocalReceipt, setIsLocalReceipt] = useState(false);
  const [isSearching, setIsSearching] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  const [actionTab, setActionTab] = useState<ActionTab>('void');
  const [selectedLines, setSelectedLines] = useState<Set<string>>(new Set());
  const [reason, setReason] = useState('');

  const handleSearch = useCallback(async () => {
    if (!searchQuery.trim()) return;
    setIsSearching(true);
    setError(null);
    setReceipt(null);
    setSuccessMsg(null);
    setIsLocalReceipt(false);

    // Try API first (if online)
    if (isOnline) {
      try {
        const result = await apiGet<ReceiptDetail>('/pos/receipts/lookup', {
          receipt_number: searchQuery.trim(),
        });
        setReceipt(result);
        setIsSearching(false);
        return;
      } catch {
        // API failed — fall through to local search
      }
    }

    // Search local unsynced receipts
    try {
      const { companyId } = useAuthStore.getState();
      const db = await getDatabase(companyId ?? '');
      const localReceipts = await queryAll<OfflineReceipt>(
        db,
        "SELECT * FROM offline_receipts WHERE receipt_number LIKE $1 AND status IN ('pending', 'failed') AND voided = 0 LIMIT 1",
        [`%${searchQuery.trim()}%`],
      );

      if (localReceipts.length > 0) {
        const r = localReceipts[0]!;
        interface LineJson { name: string; quantity: number; unit_price: string; line_total: string }
        const lines = JSON.parse(r.lines) as LineJson[];
        setReceipt({
          id: r.id,
          receipt_number: r.receipt_number,
          total: r.total,
          status: 'pending',
          lines: lines.map((l, i) => ({
            id: `local-${String(i)}`,
            product_name: l.name,
            quantity: l.quantity,
            unit_price: l.unit_price,
            line_total: l.line_total,
          })),
        });
        setIsLocalReceipt(true);
      } else {
        setError(isOnline ? t('voidReturn.receiptNotFound') : t('voidReturn.offlineNoReceipt'));
      }
    } catch (err) {
      setError(t('voidReturn.receiptNotFound'));
      console.error('Receipt lookup failed:', getErrorMessage(err));
    } finally {
      setIsSearching(false);
    }
  }, [searchQuery, t, isOnline]);

  const toggleLine = useCallback((lineId: string) => {
    setSelectedLines((prev) => {
      const next = new Set(prev);
      if (next.has(lineId)) {
        next.delete(lineId);
      } else {
        next.add(lineId);
      }
      return next;
    });
  }, []);

  const handleVoid = useCallback(async () => {
    if (!receipt) return;
    setIsProcessing(true);
    setError(null);
    try {
      if (isLocalReceipt) {
        // Void a local unsynced receipt — mark as voided in SQLite
        const { companyId } = useAuthStore.getState();
        const db = await getDatabase(companyId ?? '');
        const { execute } = await import('@/lib/db');
        await execute(
          db,
          'UPDATE offline_receipts SET voided = 1, void_reason = $1 WHERE id = $2',
          [reason.trim(), receipt.id],
        );
        setSuccessMsg(t('voidReturn.confirmVoid'));
      } else {
        // Void a server receipt — requires API
        await apiPost<unknown>(`/pos/receipts/${receipt.id}/void`, {
          reason: reason.trim(),
        });
        setSuccessMsg(t('voidReturn.confirmVoid'));
      }
      setReceipt(null);
      setReason('');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setIsProcessing(false);
    }
  }, [receipt, reason, t, isLocalReceipt]);

  const handleReturn = useCallback(async () => {
    if (!receipt || selectedLines.size === 0) return;
    setIsProcessing(true);
    setError(null);
    try {
      const lines = receipt.lines
        .filter((l) => selectedLines.has(l.id))
        .map((l) => ({ line_id: l.id, quantity: l.quantity }));

      await apiPost<unknown>(`/pos/receipts/${receipt.id}/return`, {
        lines,
        reason: reason.trim(),
      });
      setSuccessMsg(t('voidReturn.confirmReturn'));
      setReceipt(null);
      setSelectedLines(new Set());
      setReason('');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setIsProcessing(false);
    }
  }, [receipt, selectedLines, reason, t]);

  const handleClose = useCallback(() => {
    setSearchQuery('');
    setReceipt(null);
    setError(null);
    setSuccessMsg(null);
    setSelectedLines(new Set());
    setReason('');
    onClose();
  }, [onClose]);

  return (
    <Modal isOpen={isOpen} onClose={handleClose} title={t('voidReturn.title')} size="xl">
      <div className="space-y-4">
        {/* Search */}
        <div className="flex gap-2">
          <div className="relative flex-1">
            <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') void handleSearch();
              }}
              placeholder={t('voidReturn.searchReceipt')}
              className="w-full rounded-lg border border-gray-300 py-2.5 pr-3 pl-10 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
            />
          </div>
          <button
            onClick={() => void handleSearch()}
            disabled={isSearching}
            className="rounded-lg bg-gray-100 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50"
          >
            <Search className="h-4 w-4" />
          </button>
        </div>

        {/* Error / Success */}
        {error && (
          <div className="flex items-center gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">
            <AlertTriangle className="h-4 w-4" />
            {error}
          </div>
        )}
        {successMsg && (
          <div className="rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {successMsg}
          </div>
        )}

        {/* Receipt detail */}
        {receipt && (
          <>
            {/* Local receipt indicator */}
            {isLocalReceipt && (
              <div className="rounded-md bg-amber-50 p-3 text-sm text-amber-700">
                {t('voidReturn.localReceipt')}
              </div>
            )}

            {/* Tabs — hide return tab for local receipts (returns require server) */}
            <div className="flex rounded-lg bg-gray-100 p-1">
              <button
                onClick={() => setActionTab('void')}
                className={cn(
                  'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                  actionTab === 'void'
                    ? 'bg-white text-gray-900 shadow-sm'
                    : 'text-gray-500 hover:text-gray-700',
                )}
              >
                {t('voidReturn.void')}
              </button>
              {!isLocalReceipt && (
                <button
                  onClick={() => setActionTab('return')}
                  className={cn(
                    'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    actionTab === 'return'
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-500 hover:text-gray-700',
                  )}
                >
                  {t('voidReturn.return')}
                </button>
              )}
            </div>

            {/* Receipt info */}
            <div className="rounded-xl bg-gray-50 p-4">
              <p className="text-sm font-medium text-gray-600">
                #{receipt.receipt_number}
              </p>
              <p className="text-lg font-bold text-gray-900">{format(receipt.total)}</p>
            </div>

            {/* Lines (for return) */}
            {actionTab === 'return' && (
              <div className="space-y-2">
                <p className="text-sm font-medium text-gray-700">
                  {t('voidReturn.selectLines')}
                </p>
                <div className="max-h-[30vh] space-y-2 overflow-y-auto">
                {receipt.lines.map((line) => (
                  <label
                    key={line.id}
                    className={cn(
                      'flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors',
                      selectedLines.has(line.id)
                        ? 'border-blue-500 bg-blue-50'
                        : 'border-gray-200 hover:bg-gray-50',
                    )}
                  >
                    <input
                      type="checkbox"
                      checked={selectedLines.has(line.id)}
                      onChange={() => toggleLine(line.id)}
                      className="h-4 w-4 rounded border-gray-300 text-blue-600"
                    />
                    <div className="flex-1 min-w-0">
                      <p className="truncate text-sm font-medium text-gray-900">
                        {line.product_name}
                      </p>
                      <p className="text-xs text-gray-500">
                        {line.quantity} x {format(line.unit_price)}
                      </p>
                    </div>
                    <span className="text-sm font-semibold text-gray-900">
                      {format(line.line_total)}
                    </span>
                  </label>
                ))}
                </div>
              </div>
            )}

            {/* Reason */}
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">
                {t('voidReturn.reason')}
              </label>
              <input
                type="text"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
              />
            </div>

            {/* Action button */}
            {actionTab === 'void' ? (
              <button
                onClick={() => void handleVoid()}
                disabled={isProcessing}
                className="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-red-600 px-6 py-3 text-base font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {t('voidReturn.confirmVoid')}
              </button>
            ) : (
              <button
                onClick={() => void handleReturn()}
                disabled={isProcessing || selectedLines.size === 0}
                className="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-amber-600 px-6 py-3 text-base font-semibold text-white transition-colors hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {t('voidReturn.confirmReturn')}
              </button>
            )}
          </>
        )}
      </div>
    </Modal>
  );
}
