import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { FileText, FileCheck, Clock, Wallet, Receipt } from 'lucide-react';

interface ReportsMenuProps {
  isOpen: boolean;
  onClose: () => void;
  onXReport: () => void;
  onZReport: () => void;
  onTransactionHistory: () => void;
  onCashDrawerOps: () => void;
  onTodaySales: () => void;
}

export function ReportsMenu({
  isOpen,
  onClose,
  onXReport,
  onZReport,
  onTransactionHistory,
  onCashDrawerOps,
  onTodaySales,
}: ReportsMenuProps) {
  const { t } = useTranslation('pos');
  const menuRef = useRef<HTMLDivElement>(null);

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
    { label: t('reports.xReport'), icon: FileText, onClick: onXReport },
    { label: t('reports.zReport'), icon: FileCheck, onClick: onZReport },
    { label: t('reports.transactionHistory'), icon: Clock, onClick: onTransactionHistory },
    { label: t('reports.cashDrawer'), icon: Wallet, onClick: onCashDrawerOps },
    { label: t('reports.todaySales'), icon: Receipt, onClick: onTodaySales },
  ];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="absolute inset-0" onClick={onClose} />
      <div
        ref={menuRef}
        className="relative z-10 w-72 rounded-2xl bg-white py-2 shadow-2xl"
      >
        <h3 className="px-4 py-2 text-sm font-bold text-gray-900">
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
              className="flex w-full items-center gap-3 px-4 py-3 text-sm text-gray-700 transition-colors hover:bg-gray-50"
            >
              <Icon className="h-5 w-5 text-gray-400" />
              {item.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}
