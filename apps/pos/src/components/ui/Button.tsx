import { forwardRef } from 'react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Button — the single source of truth for every text button in the POS.
 *
 * Atomic-design rule: any clickable "button" must be this component. Styling
 * lives ONLY here, built on the semantic color tokens (src/index.css @theme),
 * so changing a token or a variant map updates every button everywhere.
 *
 * Variants encode ROLE (the color grammar), not ad-hoc looks:
 *  - primary     → the one main action (ocean blue)
 *  - confirm     → confirmed-money action: Charge / Pay (green)
 *  - secondary   → neutral secondary actions (Client, Discount, Hold, …)
 *  - ghost       → low-emphasis / icon actions
 *  - destructive → irreversible (red)
 *
 * Sizes encode TOUCH targets (this is a counter touchscreen):
 *  - sm = 40px (dense desktop only)   md = 48px (default touch)   lg = 64px (primary CTA)
 *
 * Labels never clip: the base class carries `whitespace-nowrap` so a button
 * never silently wraps/clips its own text (e.g. "Rappeler" -> "Rapp"). Sizing
 * is padding-driven, not a fixed width, so `min-w-0` on a flex/grid parent
 * still works. Opt into ellipsis truncation (rather than nowrap overflow)
 * with the `truncate` prop when the parent needs to clip long labels.
 *
 * All 8 states are covered: default · hover · focus-visible (global ring) ·
 * active · disabled · loading · plus error/success are expressed via variant.
 */
export type ButtonVariant =
  | 'primary'
  | 'confirm'
  | 'secondary'
  | 'ghost'
  | 'destructive';
export type ButtonSize = 'sm' | 'md' | 'lg';

const VARIANT: Record<ButtonVariant, string> = {
  primary:
    'bg-action text-ink-inverse hover:bg-action-hover active:bg-action-strong',
  confirm: 'bg-success text-ink-inverse hover:bg-success-hover active:bg-success-hover',
  secondary:
    'border border-border-subtle bg-surface-raised text-ink hover:bg-surface-sunken active:bg-surface-sunken',
  // Persistent filled surface so the control reads as tappable at rest on a
  // touchscreen (no hover) — NN/g signifiers + kiosk UX.
  ghost: 'bg-surface-sunken text-ink hover:bg-border-subtle active:bg-border-subtle',
  destructive: 'bg-danger text-ink-inverse hover:opacity-90 active:opacity-80',
};

// Ergonomic touch scale 40/48/64 — padding (not a fixed width) governs
// horizontal room so a `min-w-0` flex parent can still shrink the button.
const SIZE: Record<ButtonSize, string> = {
  sm: 'min-h-[40px] gap-1.5 px-3 text-sm',
  md: 'min-h-[48px] gap-2 px-4 text-sm',
  lg: 'min-h-[64px] gap-2 px-6 text-lg',
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** Show a spinner and disable the button. */
  loading?: boolean;
  /** Icon rendered before the label. */
  leftIcon?: ReactNode;
  /** Icon rendered after the label. */
  rightIcon?: ReactNode;
  /** Stretch to the full width of the parent. */
  fullWidth?: boolean;
  /** Ellipsis-truncate the label instead of letting it overflow (still never wraps). */
  truncate?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'secondary',
    size = 'md',
    loading = false,
    leftIcon,
    rightIcon,
    fullWidth = false,
    truncate = false,
    disabled,
    className,
    children,
    type = 'button',
    ...rest
  },
  ref,
) {
  const isDisabled = disabled || loading;
  return (
    <button
      ref={ref}
      type={type}
      disabled={isDisabled}
      aria-busy={loading || undefined}
      className={cn(
        'inline-flex select-none items-center justify-center whitespace-nowrap rounded-xl font-semibold transition-colors',
        // Disabled look is communicated by surface + ink + cursor — never
        // opacity alone (a faded primary reads as "is this on?").
        'disabled:cursor-not-allowed disabled:border-transparent disabled:bg-surface-sunken disabled:text-ink-faint disabled:hover:bg-surface-sunken',
        VARIANT[variant],
        SIZE[size],
        fullWidth && 'w-full',
        truncate && 'truncate',
        className,
      )}
      {...rest}
    >
      {loading ? (
        <Loader2 className="h-4 w-4 shrink-0 animate-spin" aria-hidden="true" />
      ) : (
        leftIcon
      )}
      {children}
      {!loading && rightIcon}
    </button>
  );
});
