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
              title={action.label}
              aria-label={action.label}
              leftIcon={<Icon className="h-4 w-4 shrink-0" />}
              // `@container` turns this button's OWN rendered width into a
              // container-query context (Tailwind v4 native, no plugin) —
              // the label span below queries THAT, not the viewport. Icon
              // stays icon-forward and always visible; the label only
              // renders once the button itself has room, so this degrades
              // to a clean icon-only tappable button at narrow cart widths
              // instead of the unreadable "Rem…/Sus…/Rap…" hard-truncation
              // the owner flagged. The full label is never lost — it's
              // always on `title` + `aria-label` above, so the action stays
              // discoverable (tooltip) and announced (a11y) icon-only.
              className="@container relative min-w-0 flex-1"
            >
              {/*
               * Hidden by default; becomes a label once the button's own
               * container width clears the threshold where the longest
               * label ("Suspendre"/"Rappeler") comfortably fits alongside
               * the icon + padding. `truncate`/`min-w-0` still guard the
               * shown state: text-overflow:ellipsis must target only this
               * label box, not the whole flex row (icon + label + badge
               * siblings) — that combo hard-clips or shrinks the wrong
               * sibling instead of ellipsizing the label.
               */}
              <span className="hidden min-w-0 truncate @[9rem]:inline">{action.label}</span>
              {action.count > 0 && (
                // A count is neither selection, stock, nor money — off accent
                // (Strategy A whole-branch review fix A): a neutral surface
                // pill, same shape/size as before.
                <span className="ml-1.5 inline-flex h-5 min-w-[20px] shrink-0 items-center justify-center rounded-pill bg-surface-sunken px-1.5 text-xs font-semibold tabular-nums text-ink">
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
