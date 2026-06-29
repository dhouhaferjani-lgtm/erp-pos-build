import { useEffect, useRef, type ReactNode } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useFocusTrap } from '@/hooks/useFocusTrap';

/**
 * Drawer — slide-in side panel shell (redesign §5 shells). Backdrop fade +
 * 240ms slide; focus-trapped; Escape / backdrop close. Tokenised, both themes.
 * Use for the Filtres drawer, product fiche, etc. The accessible close label is
 * caller-supplied (i18n) — atoms hold no hardcoded user-facing strings.
 */
export interface DrawerProps {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  /** Side the panel slides in from. Default 'end' (right in LTR). */
  side?: 'start' | 'end';
  /** Tailwind width class for the panel. Default a comfortable 420px cap. */
  widthClass?: string;
  /** Accessible label for the close button (translated by the caller). */
  closeLabel?: string;
  /** Optional footer pinned below the scrollable body. */
  footer?: ReactNode;
}

export function Drawer({
  isOpen,
  onClose,
  title,
  children,
  side = 'end',
  widthClass = 'w-[min(420px,92vw)]',
  closeLabel,
  footer,
}: DrawerProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  useFocusTrap({ isActive: isOpen, containerRef });

  useEffect(() => {
    if (!isOpen) return;
    const handleEscape = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handleEscape);
    return () => window.removeEventListener('keydown', handleEscape);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex">
      {/* Backdrop */}
      <div className="ez-fade-in absolute inset-0 bg-black/50" onClick={onClose} />

      {/* Panel */}
      <div
        ref={containerRef}
        role="dialog"
        aria-modal="true"
        className={cn(
          'relative z-10 flex h-full flex-col overflow-hidden bg-surface-overlay shadow-2xl',
          widthClass,
          side === 'end' ? 'ml-auto ez-slide-in-end' : 'mr-auto ez-slide-in-start',
        )}
      >
        {/* Header */}
        <div className="flex shrink-0 items-center justify-between border-b border-border-subtle px-5 py-4">
          <h2 className="text-lg font-bold text-ink">{title}</h2>
          <button
            type="button"
            onClick={onClose}
            data-testid="drawer-close-button"
            aria-label={closeLabel}
            className="flex h-12 w-12 items-center justify-center rounded-full text-ink-faint hover:bg-surface-sunken hover:text-ink"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Body */}
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">{children}</div>

        {/* Footer — pinned, never scrolls */}
        {footer && (
          <div className="shrink-0 border-t border-border-subtle px-5 py-4">{footer}</div>
        )}
      </div>
    </div>
  );
}
