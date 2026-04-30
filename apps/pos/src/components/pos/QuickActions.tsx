import { useTranslation } from 'react-i18next';
import { Tag, Pause, ClipboardList, RotateCcw } from 'lucide-react';
import { cn } from '@/lib/utils';

interface QuickActionsProps {
  onDiscount: () => void;
  onHold: () => void;
  onRecall: () => void;
  /** Opens the Returns / Exchange receipt-locator screen. */
  onReturns: () => void;
  hasItems: boolean;
}

export function QuickActions({
  onDiscount,
  onHold,
  onRecall,
  onReturns,
  hasItems,
}: QuickActionsProps) {
  const { t } = useTranslation('pos');

  const actions = [
    {
      label: t('quickActions.discount'),
      icon: Tag,
      onClick: onDiscount,
      disabled: !hasItems,
    },
    {
      label: t('quickActions.hold'),
      icon: Pause,
      onClick: onHold,
      disabled: !hasItems,
    },
    {
      label: t('quickActions.recall'),
      icon: ClipboardList,
      onClick: onRecall,
      disabled: false,
    },
    {
      label: t('receiptLocator.entryButton'),
      icon: RotateCcw,
      onClick: onReturns,
      disabled: false,
    },
  ];

  return (
    <div className="flex gap-2 overflow-x-auto px-4 py-2">
      {actions.map((action) => {
        const Icon = action.icon;
        return (
          <button
            key={action.label}
            onClick={action.onClick}
            disabled={action.disabled}
            className={cn(
              'flex min-h-[44px] flex-1 items-center justify-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100',
              action.disabled && 'cursor-not-allowed opacity-40',
            )}
          >
            <Icon className="h-4 w-4" />
            {action.label}
          </button>
        );
      })}
    </div>
  );
}
