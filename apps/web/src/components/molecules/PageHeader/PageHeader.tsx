import { cn } from '@/lib/utils'
import { textColors } from '@/lib/designTokens'

export interface PageHeaderProps {
  /** Page title rendered as the page's single `<h1>`. */
  title: string
  /** Optional muted text rendered under the title. */
  subtitle?: string
  /** Right-aligned action area (buttons typically live here). */
  actions?: React.ReactNode
  /**
   * Optional slot rendered ABOVE the title. The app has a global Breadcrumb;
   * this is an optional per-page override.
   */
  breadcrumb?: React.ReactNode
  /** Additional classes applied to the wrapper. */
  className?: string
}

/**
 * Standard page title block used at the top of every feature page.
 *
 * Replaces the bespoke `text-2xl/3xl font-bold` titles each page rolls on its
 * own, giving every page a consistent header: optional breadcrumb on its own
 * line, then a row with the title + subtitle on the left and actions on the
 * right. The row wraps on small screens so actions drop below the title.
 */
export function PageHeader({
  title,
  subtitle,
  actions,
  breadcrumb,
  className,
}: PageHeaderProps) {
  return (
    <div className={cn('mb-6', className)}>
      {breadcrumb ? <div className="mb-2">{breadcrumb}</div> : null}

      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className={cn('text-2xl font-bold', textColors.primary)}>
            {title}
          </h1>
          {subtitle ? (
            <p className={cn('mt-1', textColors.tertiary)}>{subtitle}</p>
          ) : null}
        </div>

        {actions ? (
          <div className="flex shrink-0 items-center gap-2">{actions}</div>
        ) : null}
      </div>
    </div>
  )
}
