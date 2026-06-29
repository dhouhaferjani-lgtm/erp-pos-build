import type { ReactNode } from 'react';
import { ArrowLeft } from 'lucide-react';

/**
 * PageHeader — the single, consistent sub-page header (back on the LEFT, title,
 * optional right-aligned actions). Full-width, sticky-friendly, tokenised. Use
 * on every routed sub-page (Settings, Ventes du jour, Z-report list, …) so the
 * back affordance and layout never drift between pages.
 */
export interface PageHeaderProps {
  title: string;
  /** When provided, renders a 48px left-aligned back button. */
  onBack?: () => void;
  /** Accessible label for the back button (translated by the caller). */
  backLabel?: string;
  /** Right-aligned actions (e.g. a Save button). */
  actions?: ReactNode;
}

export function PageHeader({ title, onBack, backLabel, actions }: PageHeaderProps) {
  return (
    <div className="flex shrink-0 items-center gap-3 border-b border-border-subtle bg-surface-raised px-4 py-3">
      {onBack && (
        <button
          type="button"
          onClick={onBack}
          aria-label={backLabel}
          className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-ink-muted hover:bg-surface-sunken hover:text-ink"
        >
          <ArrowLeft className="h-5 w-5" />
        </button>
      )}
      <h1 className="truncate font-display text-xl font-bold text-ink">{title}</h1>
      {actions && <div className="ml-auto flex shrink-0 items-center gap-2">{actions}</div>}
    </div>
  );
}
