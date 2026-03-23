import { useTranslation } from 'react-i18next';
import { Tag, Pause, ClipboardList } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface QuickActionsProps {
  onDiscount: () => void;
  onHold: () => void;
  onRecall: () => void;
  hasItems: boolean;
  hasDiscount?: boolean;
}

export function QuickActions({
  onDiscount,
  onHold,
  onRecall,
  hasItems,
  hasDiscount,
}: QuickActionsProps) {
  const { t } = useTranslation('pos');

  const actions = [
    {
      label: t('quickActions.discount'),
      icon: Tag,
      onClick: onDiscount,
      disabled: !hasItems,
      showBadge: hasDiscount,
    },
    {
      label: t('quickActions.hold'),
      icon: Pause,
      onClick: onHold,
      disabled: !hasItems,
      showBadge: false,
    },
    {
      label: t('quickActions.recall'),
      icon: ClipboardList,
      onClick: onRecall,
      disabled: false,
      showBadge: false,
    },
  ];

  return (
    <div className="flex gap-1.5 overflow-x-auto px-2 py-1">
      {actions.map((action) => {
        const Icon = action.icon;
        return (
          <button
            key={action.label}
            onClick={action.onClick}
            disabled={action.disabled}
            className={cn(
              'relative flex min-h-[36px] flex-1 items-center justify-center gap-1.5 rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-100',
              action.disabled && 'cursor-not-allowed opacity-50',
            )}
          >
            <Icon className="h-4 w-4" />
            {action.label}
            {action.showBadge && (
              <span className="absolute -top-1 -right-1 h-2.5 w-2.5 rounded-full bg-red-500" />
            )}
          </button>
        );
      })}
    </div>
  );
}
