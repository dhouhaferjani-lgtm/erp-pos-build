import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import { Tag, Pause, ClipboardList } from 'lucide-react';
import { Button } from '@/components/ui';

export interface QuickActionsProps {
  onDiscount: () => void;
  onHold: () => void;
  onRecall: () => void;
  hasItems: boolean;
  hasDiscount?: boolean;
  /** Number of parked sales — shown as a count badge on Recall (mock §5.1 "Reprendre + count"). */
  recallCount?: number;
}

export function QuickActions({
  onDiscount,
  onHold,
  onRecall,
  hasItems,
  hasDiscount,
  recallCount = 0,
}: QuickActionsProps) {
  const { t } = useTranslation('pos');

  // The three sale quick actions (mock §5.1). Returns/Exchange is a SEPARATE
  // flow (§5.4) surfaced as a compact icon in the cart toolbar, not a co-equal
  // labelled action here. Two role groups, separated by a divider:
  //  · "modify this sale" (Discount, Hold) — item-dependent
  //  · "retrieve a sale" (Recall) — always available, shows parked count
  const actions = [
    {
      label: t('quickActions.discount'),
      icon: Tag,
      onClick: onDiscount,
      disabled: !hasItems,
      showBadge: hasDiscount,
      count: 0,
      group: 0,
    },
    {
      label: t('quickActions.hold'),
      icon: Pause,
      onClick: onHold,
      disabled: !hasItems,
      showBadge: false,
      count: 0,
      group: 0,
    },
    {
      label: t('quickActions.recall'),
      icon: ClipboardList,
      onClick: onRecall,
      disabled: false,
      showBadge: false,
      count: recallCount,
      group: 1,
    },
  ];

  return (
    <div className="flex items-stretch gap-1.5 py-1">
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
            <Button
              variant="secondary"
              size="md"
              onClick={action.onClick}
              disabled={action.disabled}
              leftIcon={<Icon className="h-4 w-4 shrink-0" />}
              className="relative min-w-0 flex-1"
            >
              {/*
               * The ellipsis must target ONLY the label text box, not the
               * whole flex row (icon + label + badge siblings) — text-overflow:
               * ellipsis on a flex container with element children is
               * unreliable (can hard-clip with no ellipsis, or shrink the
               * icon/badge instead of the label). min-w-0 lets this span
               * shrink below its content size inside the flex-1 button.
               */}
              <span className="min-w-0 truncate">{action.label}</span>
              {action.count > 0 && (
                <span className="ml-1.5 inline-flex h-5 min-w-[20px] shrink-0 items-center justify-center rounded-pill bg-accent-tint px-1.5 text-xs font-semibold tabular-nums text-accent-strong">
                  {action.count}
                </span>
              )}
              {action.showBadge && (
                <span className="absolute -top-1 -right-1 h-2.5 w-2.5 rounded-full bg-danger" />
              )}
            </Button>
          </Fragment>
        );
      })}
    </div>
  );
}
