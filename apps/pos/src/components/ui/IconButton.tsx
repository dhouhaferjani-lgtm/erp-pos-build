import { forwardRef } from 'react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { ButtonVariant } from './Button';

/**
 * IconButton — square, icon-only button. Same variant grammar as Button, but
 * a fixed square footprint with an enforced accessible name.
 *
 * Sizes are touch-first: md = 48px (the §6 touch floor — default), lg = 56px.
 * (sm = 36px is desktop-dense only; never use it for a touchscreen control.)
 */
export type IconButtonSize = 'sm' | 'md' | 'lg';

const VARIANT: Record<ButtonVariant, string> = {
  primary: 'bg-action text-ink-inverse hover:bg-action-hover active:bg-action-strong',
  confirm: 'bg-success text-ink-inverse hover:bg-success-hover active:bg-success-hover',
  secondary:
    'border border-border-subtle bg-surface-raised text-ink hover:bg-surface-sunken active:bg-surface-sunken',
  ghost: 'text-ink-muted hover:bg-surface-sunken hover:text-ink active:bg-surface-sunken',
  destructive:
    'text-danger-strong hover:bg-danger-surface active:bg-danger-surface',
};

const SIZE: Record<IconButtonSize, string> = {
  sm: 'h-9 w-9',
  // md is the touchscreen default — 48px, the §6 touch floor (was 44px).
  md: 'h-12 w-12',
  lg: 'h-14 w-14',
};

export interface IconButtonProps
  extends ButtonHTMLAttributes<HTMLButtonElement> {
  /** Required accessible name — icon-only buttons must be labelled. */
  'aria-label': string;
  icon: ReactNode;
  variant?: ButtonVariant;
  size?: IconButtonSize;
}

export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(
  function IconButton(
    { icon, variant = 'ghost', size = 'md', disabled, className, type = 'button', ...rest },
    ref,
  ) {
    return (
      <button
        ref={ref}
        type={type}
        disabled={disabled}
        className={cn(
          'inline-flex shrink-0 items-center justify-center rounded-xl transition-colors',
          'disabled:cursor-not-allowed disabled:bg-transparent disabled:text-ink-faint disabled:hover:bg-transparent',
          VARIANT[variant],
          SIZE[size],
          className,
        )}
        {...rest}
      >
        {icon}
      </button>
    );
  },
);
