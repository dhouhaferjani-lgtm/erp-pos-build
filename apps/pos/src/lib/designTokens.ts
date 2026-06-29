/**
 * Design Tokens for IziPOS (apps/pos)
 *
 * Composite component recipes built on the semantic color tokens defined in
 * `src/index.css` (@theme). These are the canonical, reusable building blocks
 * for the POS design language — use them instead of hand-assembling Tailwind
 * color classes.
 *
 * Color grammar (enforced):
 *  - action  (ocean blue) → interactive / primary actions / selected state
 *  - success (green)      → confirmed money & sync events ONLY
 *  - warning (amber)      → warnings, low stock
 *  - danger  (red)        → errors & destructive actions ONLY
 *  - ink / surface        → text & elevation; prices use `text-ink`, never accent
 *
 * Usage:
 * ```tsx
 * import { tokens } from '@/lib/designTokens';
 * <button className={tokens.button.primary}>…</button>
 * ```
 */

/** Primary interactive button (the one strong action per view). */
const buttonPrimary =
  'inline-flex items-center justify-center gap-2 rounded-xl bg-action px-4 font-semibold text-ink-inverse transition-colors ' +
  'hover:bg-action-hover active:bg-action-strong ' +
  'disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-ink-faint';

/** Confirm-money button (complete sale, take payment). */
const buttonConfirm =
  'inline-flex items-center justify-center gap-2 rounded-xl bg-success px-4 font-semibold text-ink-inverse transition-colors ' +
  'hover:bg-success-hover ' +
  'disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-ink-faint';

/** Secondary / neutral action. */
const buttonSecondary =
  'inline-flex items-center justify-center gap-2 rounded-xl border border-border-subtle bg-surface-raised px-4 font-medium text-ink transition-colors ' +
  'hover:bg-surface-sunken active:bg-surface-sunken ' +
  'disabled:cursor-not-allowed disabled:text-ink-faint';

/** Low-emphasis button — still carries a PERSISTENT filled surface so it reads
 * as tappable at rest on a touchscreen (no hover state). */
const buttonGhost =
  'inline-flex items-center justify-center gap-2 rounded-lg px-3 font-medium text-ink transition-colors ' +
  'bg-surface-sunken hover:bg-border-subtle active:bg-border-subtle ' +
  'disabled:cursor-not-allowed disabled:bg-transparent disabled:text-ink-faint';

/** Destructive action (irreversible). */
const buttonDestructive =
  'inline-flex items-center justify-center gap-2 rounded-xl bg-danger px-4 font-semibold text-ink-inverse transition-colors ' +
  'hover:opacity-90 ' +
  'disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-ink-faint';

export const tokens = {
  /** Buttons — pair with size/min-height utilities at the call site (e.g. `min-h-[56px]`). */
  button: {
    primary: buttonPrimary,
    confirm: buttonConfirm,
    secondary: buttonSecondary,
    ghost: buttonGhost,
    destructive: buttonDestructive,
  },

  /** Status pills / badges — surface + strong text + subtle border. */
  badge: {
    neutral:
      'inline-flex items-center gap-1 rounded-full border border-border-subtle bg-surface-sunken px-2 py-0.5 text-xs font-medium text-ink-muted',
    success:
      'inline-flex items-center gap-1 rounded-full border border-success-subtle bg-success-surface px-2 py-0.5 text-xs font-medium text-success-strong',
    warning:
      'inline-flex items-center gap-1 rounded-full border border-warning-subtle bg-warning-surface px-2 py-0.5 text-xs font-medium text-warning-strong',
    danger:
      'inline-flex items-center gap-1 rounded-full border border-danger-subtle bg-danger-surface px-2 py-0.5 text-xs font-medium text-danger-strong',
  },

  /** The ONE segmented (choose-one) control voice. */
  segmented: {
    root: 'inline-flex rounded-lg bg-surface-sunken p-1',
    item: 'flex min-h-12 items-center justify-center rounded-md px-3 text-sm font-medium transition-colors',
    itemActive: 'bg-surface-raised text-ink shadow-sm',
    itemInactive: 'text-ink-muted hover:text-ink',
  },

  /** Header session-status pill. */
  statusPill: {
    base: 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium',
    healthy: 'bg-success-surface text-success-strong',
    warning: 'bg-warning-surface text-warning-strong',
    danger: 'bg-danger-surface text-danger-strong',
    dot: 'inline-block h-1.5 w-1.5 rounded-full',
  },

  /**
   * Disabled-control look — communicate disabled by surface + ink + cursor,
   * never by opacity alone (a faded primary button reads as "is it enabled?").
   * Pair with a visible reason near the control.
   */
  disabledReason: 'cursor-not-allowed bg-surface-sunken text-ink-faint',

  /** Surface elevation helpers. */
  surface: {
    canvas: 'bg-surface-canvas',
    raised: 'bg-surface-raised shadow-sm',
    overlay: 'bg-surface-overlay shadow-2xl',
    sunken: 'bg-surface-sunken',
  },

  /** Monetary display — always tabular figures, ink color (data, not action). */
  money: 'tabular-nums text-ink',
} as const;

export type DesignTokens = typeof tokens;
