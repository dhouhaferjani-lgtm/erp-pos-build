import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { FileText, Clock, Wallet, Receipt, FileArchive } from 'lucide-react';
import { useOperatorStore } from '@/stores/operatorStore';
import { isManagerRole } from '@/lib/auth/roles';

interface ReportsMenuProps {
  isOpen: boolean;
  onClose: () => void;
  onXReport: () => void;
  onTransactionHistory: () => void;
  onCashDrawerOps: () => void;
  onTodaySales: () => void;
  onZReportHistory: () => void;
}

export function ReportsMenu({
  isOpen,
  onClose,
  onXReport,
  onTransactionHistory,
  onCashDrawerOps,
  onTodaySales,
  onZReportHistory,
}: ReportsMenuProps) {
  const { t } = useTranslation('pos');
  const menuRef = useRef<HTMLDivElement>(null);
  // Owner access constraint (2026-06-28, point 1): the fiscal/cash reports
  // (X-report, cash-drawer ops, Z-report history) are manager-only; the
  // transaction history + today's sales views stay open to every operator.
  const operator = useOperatorStore((s) => s.operator);
  const isManager = isManagerRole(operator?.roles);

  useEffect(() => {
    if (!isOpen) return;

    const handleClickOutside = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        onClose();
      }
    };

    const handleEscape = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };

    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('keydown', handleEscape);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleEscape);
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const items = [
    { label: t('reports.xReport'), icon: FileText, onClick: onXReport, managerOnly: true },
    { label: t('reports.transactionHistory'), icon: Clock, onClick: onTransactionHistory, managerOnly: false },
    { label: t('reports.cashDrawer'), icon: Wallet, onClick: onCashDrawerOps, managerOnly: true },
    { label: t('reports.todaySales'), icon: Receipt, onClick: onTodaySales, managerOnly: false },
    { label: t('reports.zList.title'), icon: FileArchive, onClick: onZReportHistory, managerOnly: true },
  ].filter((item) => isManager || !item.managerOnly);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="absolute inset-0" onClick={onClose} />
      <div
        ref={menuRef}
        className="relative z-10 w-72 rounded-2xl bg-surface-overlay py-2 shadow-2xl"
      >
        <h3 className="px-4 py-2 text-sm font-bold text-ink">
          {t('reports.title')}
        </h3>
        {items.map((item) => {
          const Icon = item.icon;
          return (
            <button
              key={item.label}
              onClick={() => {
                item.onClick();
                onClose();
              }}
              className="flex w-full items-center gap-3 px-4 py-3 text-sm text-ink transition-colors hover:bg-surface-sunken"
            >
              <Icon className="h-5 w-5 text-ink-faint" />
              {item.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}
