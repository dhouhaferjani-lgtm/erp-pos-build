import { ChevronRight, type LucideIcon } from 'lucide-react'
import { Link } from 'react-router-dom'
import { tokens, textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

export interface HubCardProps {
  /** react-router path the whole card links to */
  to: string
  /** Leading icon — rendered inside the single tokenized brand chip */
  icon: LucideIcon
  title: string
  description?: string
  className?: string
}

/**
 * A single navigation card for "hub" landing pages (Finance / Inventory / POS /
 * Marketing). Every card uses ONE consistent neutral/brand icon chip
 * (`${colorTokens.intent.primary.bgSubtle} ${colorTokens.intent.primary.text}`) — there is intentionally no per-card color, which
 * removes the off-theme "rainbow tile" look the old hub pages had.
 *
 * The whole card is the link.
 */
export function HubCard({
  to,
  icon: Icon,
  title,
  description,
  className,
}: HubCardProps) {
  return (
    <Link
      to={to}
      className={cn(
        tokens.card.base,
        tokens.card.hover,
        'flex items-center gap-4',
        className
      )}
    >
      <span
        data-testid="hub-card-icon-chip"
        className={cn(
          'flex shrink-0 items-center justify-center rounded-lg p-2.5',
          tokens.alert.info
        )}
      >
        <Icon className="h-5 w-5" />
      </span>

      <span className="min-w-0 flex-1">
        <span className={cn('block font-semibold', textColors.primary)}>
          {title}
        </span>
        {description ? (
          <span className={cn('mt-0.5 block text-sm', textColors.tertiary)}>
            {description}
          </span>
        ) : null}
      </span>

      <ChevronRight className={cn('h-5 w-5 shrink-0', textColors.disabled)} aria-hidden="true" />
    </Link>
  )
}

export interface HubGridProps {
  children: React.ReactNode
  className?: string
}

/**
 * Responsive grid wrapper for `HubCard`s — the standard hub layout
 * (1 / 2 / 3 columns).
 */
export function HubGrid({ children, className }: HubGridProps) {
  return (
    <div className={cn('grid gap-4 sm:grid-cols-2 lg:grid-cols-3', className)}>
      {children}
    </div>
  )
}
