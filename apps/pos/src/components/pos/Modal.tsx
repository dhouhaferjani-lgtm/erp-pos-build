import { useEffect, useRef, type ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { X } from 'lucide-react';
import { useFocusTrap } from '@/hooks/useFocusTrap';

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
  /**
   * When false the modal cannot be dismissed: the X is visibly dimmed
   * (disabled — kept in the layout so the header height never jumps) and
   * backdrop / Escape are inert. Use while an irreversible action is in
   * flight (e.g. a refund submit).
   */
  closable?: boolean;
}

export function Modal({ isOpen, onClose, title, children, footer, size = 'md', closable = true }: ModalProps) {
  const containerRef = useRef<HTMLDivElement>(null);

  // Focus trap: Tab cycles within the modal; close restores focus to
  // the element that opened it. Closes the T2.1 deferral that flagged
  // BarcodeChooserModal — the gap was pre-existing across all modals
  // using this base. Every consumer of <Modal> inherits the fix.
  useFocusTrap({ isActive: isOpen, containerRef });

  useEffect(() => {
    if (!isOpen || !closable) return;

    const handleEscape = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handleEscape);
    return () => window.removeEventListener('keydown', handleEscape);
  }, [isOpen, closable, onClose]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      {/* Backdrop */}
      <div
        className="absolute inset-0 bg-black/50"
        onClick={closable ? onClose : undefined}
      />

      {/* Content */}
      <div
        ref={containerRef}
        role="dialog"
        aria-modal="true"
        className={cn(
          'relative z-10 flex w-full flex-col rounded-2xl bg-surface-overlay shadow-2xl overflow-hidden',
          size === 'sm' && 'max-w-sm max-h-[45vh]',
          size === 'md' && 'max-w-md max-h-[80vh]',
          size === 'lg' && 'max-w-lg max-h-[70vh]',
          size === 'xl' && 'max-w-2xl max-h-[80vh]',
          size === 'full' && 'max-w-4xl max-h-[85vh]',
        )}
      >
        {/* Header */}
        <div className="flex shrink-0 items-center justify-between border-b border-border-subtle px-6 py-4">
          <h2 className="text-xl font-bold text-ink">{title}</h2>
          <button
            onClick={onClose}
            disabled={!closable}
            data-testid="modal-close-button"
            className="flex h-10 w-10 items-center justify-center rounded-full text-ink-faint hover:bg-surface-sunken hover:text-ink disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-ink-faint"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 min-h-0 overflow-y-auto px-6 py-4">{children}</div>

        {/* Footer — always visible, never scrolls */}
        {footer && (
          <div className="shrink-0 border-t border-border-subtle px-6 py-4">{footer}</div>
        )}
      </div>
    </div>
  );
}
