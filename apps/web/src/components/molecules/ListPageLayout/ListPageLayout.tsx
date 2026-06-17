import { cn } from '@/lib/utils'
import { PageHeader } from '@/components/molecules/PageHeader'

export interface ListPageLayoutProps {
  /** Page title rendered as the page's single `<h1>` via {@link PageHeader}. */
  title: string
  /** Optional muted text rendered under the title. */
  subtitle?: string
  /** Right-aligned header action area (e.g. an "Add" button). */
  actions?: React.ReactNode
  /** Optional breadcrumb slot rendered above the title. */
  breadcrumb?: React.ReactNode
  /**
   * Optional filter bar slot (search, tabs, status filters) rendered in a
   * region above the list body. Omitted entirely when not provided.
   */
  filters?: React.ReactNode
  /** The list/table body — typically a `DataTable`. */
  children: React.ReactNode
  /**
   * Optional pagination slot rendered below the body. Omitted entirely when
   * not provided.
   */
  pagination?: React.ReactNode
  /** Additional classes applied to the outer wrapper. */
  className?: string
}

/**
 * Shared shell for list/index pages.
 *
 * Standardises the header + filter bar + table + pagination arrangement so
 * every list page shares the same vertical rhythm instead of reinventing the
 * spacing. Renders a {@link PageHeader} at the top, an optional filter bar, the
 * table body, then optional pagination.
 */
export function ListPageLayout({
  title,
  subtitle,
  actions,
  breadcrumb,
  filters,
  children,
  pagination,
  className,
}: ListPageLayoutProps) {
  return (
    <div className={cn('w-full', className)}>
      <PageHeader
        title={title}
        {...(subtitle !== undefined ? { subtitle } : {})}
        {...(actions !== undefined ? { actions } : {})}
        {...(breadcrumb !== undefined ? { breadcrumb } : {})}
      />

      {filters ? (
        <div className="mb-4 flex flex-wrap items-center gap-3">{filters}</div>
      ) : null}

      <div>{children}</div>

      {pagination ? <div className="mt-4">{pagination}</div> : null}
    </div>
  )
}
