import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import { Tag, Pause, ClipboardList, RotateCcw } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface QuickActionsProps {
  onDiscount: () => void;
  onHold: () => void;
  onRecall: () => void;
  /** Opens the Returns / Exchange receipt-locator screen. */
  onReturns: () => void;
  hasItems: boolean;
  hasDiscount?: boolean;
}

export function QuickActions({
  onDiscount,
  onHold,
  onRecall,
  onReturns,
  hasItems,
  hasDiscount,
}: QuickActionsProps) {
  const { t } = useTranslation('pos');

  // Two role groups, separated by a divider:
  //  · "modify this sale" (Discount, Hold) — item-dependent
  //  · "start / retrieve a sale" (Recall, Returns) — always available
  const actions = [
    {
      label: t('quickActions.discount'),
      icon: Tag,
      onClick: onDiscount,
      disabled: !hasItems,
      showBadge: hasDiscount,
      group: 0,
    },
    {
      label: t('quickActions.hold'),
      icon: Pause,
      onClick: onHold,
      disabled: !hasItems,
      showBadge: false,
      group: 0,
    },
    {
      label: t('quickActions.recall'),
      icon: ClipboardList,
      onClick: onRecall,
      disabled: false,
      showBadge: false,
      group: 1,
    },
    {
      label: t('receiptLocator.entryButton'),
      icon: RotateCcw,
      onClick: onReturns,
      disabled: false,
      showBadge: false,
      group: 1,
    },
  ];

  return (
    <div className="flex items-stretch gap-1.5 overflow-x-auto py-1">
      {actions.map((action, i) => {
        const Icon = action.icon;
        const groupBreak = i > 0 && action.group !== actions[i - 1]?.group;
        return (
          <Fragment key={action.label}>
            {groupBreak && (
              <span
                className="my-1 w-px shrink-0 self-stretch bg-border-subtle"
                aria-hidden="true"
              />
            )}
            <button
              onClick={action.onClick}
              disabled={action.disabled}
              className={cn(
                'relative flex min-h-[36px] flex-1 items-center justify-center gap-1.5 rounded-md bg-surface-sunken px-2 py-1 text-xs font-medium text-ink-muted transition-colors hover:bg-surface-canvas hover:text-ink',
                action.disabled &&
                  'cursor-not-allowed bg-surface-sunken text-ink-faint hover:bg-surface-sunken hover:text-ink-faint',
              )}
            >
              <Icon className="h-4 w-4" />
              {action.label}
              {action.showBadge && (
                <span className="absolute -top-1 -right-1 h-2.5 w-2.5 rounded-full bg-danger" />
              )}
            </button>
          </Fragment>
        );
      })}
    </div>
  );
}
