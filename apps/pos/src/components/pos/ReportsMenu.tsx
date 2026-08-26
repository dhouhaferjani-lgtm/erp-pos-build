import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { FileText, Clock, Wallet, Receipt, FileArchive } from 'lucide-react';
import { useOperatorStore } from '@/stores/operatorStore';
import { hasManagerAccess } from '@/lib/auth/roles';
import type { ManagerSurface } from '@/lib/auth/roles';

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
  // B-13 (iv): the gate reads the PIN operator, never the login account.
  //
  // Gate r2 (R2-2): each entry is filtered on the SAME surface key its handler
  // enforces. Filtering the cash-drawer entry on `pos.view_reports` while
  // `Header.handleCashDrawerOps` enforced `pos.approve_cash_drawer_control`
  // was harmless on seeded roles but not on the tenant-created roles R1-4
  // exists for: one holding only the drawer permission never saw the entry it
  // was entitled to, and one holding only `pos.view_reports` saw it and got a
  // toast.
  const operator = useOperatorStore((s) => s.operator);

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

  const items: {
    label: string;
    icon: typeof FileText;
    onClick: () => void;
    surface: ManagerSurface | null;
  }[] = [
    { label: t('reports.xReport'), icon: FileText, onClick: onXReport, surface: 'reports' },
    { label: t('reports.transactionHistory'), icon: Clock, onClick: onTransactionHistory, surface: null },
    { label: t('reports.cashDrawer'), icon: Wallet, onClick: onCashDrawerOps, surface: 'cash_drawer' },
    { label: t('reports.todaySales'), icon: Receipt, onClick: onTodaySales, surface: null },
    { label: t('reports.zList.title'), icon: FileArchive, onClick: onZReportHistory, surface: 'reports' },
  ];
  const visibleItems = items.filter(
    (item) => item.surface === null || hasManagerAccess(operator, item.surface),
  );

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="absolute inset-0" onClick={onClose} />
      <div
        ref={menuRef}
        className="relative z-10 w-72 rounded-panel bg-surface-overlay py-2 shadow-2xl"
      >
        <h3 className="px-4 py-2 text-sm font-bold text-ink">
          {t('reports.title')}
        </h3>
        {visibleItems.map((item) => {
          const Icon = item.icon;
          return (
            <button
              key={item.label}
              onClick={() => {
                item.onClick();
                onClose();
              }}
              className="flex min-h-[48px] w-full items-center gap-3 px-4 py-3 text-sm text-ink transition-colors hover:bg-surface-sunken"
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
