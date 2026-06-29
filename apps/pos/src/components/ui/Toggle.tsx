import { cn } from '@/lib/utils';

/**
 * Toggle — on/off switch (e.g. marketing-consent). Accent track when on.
 * Renders a real checkbox-role button; label is supplied by the caller.
 */
export interface ToggleProps {
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
  ariaLabel: string;
  className?: string;
}

export function Toggle({ checked, onChange, disabled, ariaLabel, className }: ToggleProps) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={ariaLabel}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={cn(
        'relative inline-flex h-7 w-12 shrink-0 items-center rounded-pill border transition-colors',
        checked ? 'border-accent bg-accent' : 'border-border-strong bg-surface-sunken',
        disabled && 'cursor-not-allowed opacity-50',
        className,
      )}
    >
      <span
        className={cn(
          'inline-block h-5 w-5 rounded-full bg-surface-raised shadow-sm transition-transform',
          checked ? 'translate-x-6' : 'translate-x-1',
        )}
      />
    </button>
  );
}
