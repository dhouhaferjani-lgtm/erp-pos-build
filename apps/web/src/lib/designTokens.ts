/**
 * Design Tokens for AutoERP
 *
 * Central source of truth for colors, spacing, typography, and other design values.
 * These tokens ensure consistency across the application and enable easy theming.
 *
 * Usage:
 * ```tsx
 * import { tokens } from '@/lib/designTokens'
 * <input className={tokens.input.base} />
 * ```
 */

/**
 * Color Palette
 * Semantic color names for primary, success, error, warning, and neutral colors
 */
export const colors = {
  // Primary brand colors
  primary: {
    50: 'bg-blue-50',
    100: 'bg-blue-100',
    500: 'bg-blue-500',
    600: 'bg-blue-600',
    700: 'bg-blue-700',
    800: 'bg-blue-800',
  },

  // Success states
  success: {
    50: 'bg-green-50',
    100: 'bg-green-100',
    600: 'bg-green-600',
    700: 'bg-green-700',
    800: 'bg-green-800',
  },

  // Error/danger states
  error: {
    50: 'bg-red-50',
    100: 'bg-red-100',
    600: 'bg-red-600',
    700: 'bg-red-700',
    800: 'bg-red-800',
  },

  // Warning states
  warning: {
    50: 'bg-yellow-50',
    100: 'bg-yellow-100',
    600: 'bg-yellow-600',
    700: 'bg-yellow-700',
    800: 'bg-yellow-800',
  },

  // Neutral grays
  neutral: {
    50: 'bg-gray-50',
    100: 'bg-gray-100',
    200: 'bg-gray-200',
    300: 'bg-gray-300',
    400: 'bg-gray-400',
    500: 'bg-gray-500',
    600: 'bg-gray-600',
    700: 'bg-gray-700',
    800: 'bg-gray-800',
    900: 'bg-gray-900',
  },

  // Special colors
  white: 'bg-white',
  transparent: 'bg-transparent',
  black: 'bg-black',

  // Hover states
  hover: {
    gray50: 'hover:bg-gray-50',
    gray100: 'hover:bg-gray-100',
  },
}

/**
 * Text color variants
 */
export const textColors = {
  primary: 'text-gray-900',
  secondary: 'text-gray-700',
  tertiary: 'text-gray-600',
  disabled: 'text-gray-400',
  inverse: 'text-white',
  error: 'text-red-700',
  success: 'text-green-700',
  warning: 'text-yellow-700',
  warningDark: 'text-yellow-600',
  brand: 'text-blue-600',
  hoverSecondary: 'hover:text-gray-600',
}

/**
 * Border color variants
 */
export const borderColors = {
  default: 'border-gray-300',
  light: 'border-gray-200',
  dark: 'border-gray-400',
  primary: 'border-blue-500',
  error: 'border-red-500',
  success: 'border-green-500',
  warning: 'border-yellow-500',
  hover: 'hover:border-gray-300',
  divideLight: 'divide-gray-100',
  divideDefault: 'divide-gray-200',
  leftError: 'border-l-red-500',
  leftWarning: 'border-l-yellow-500',
  leftPrimary: 'border-l-blue-500',
}

/**
 * Spacing scale (consistent with Tailwind)
 */
export const spacing = {
  xs: 'p-1',
  sm: 'p-2',
  md: 'p-4',
  lg: 'p-6',
  xl: 'p-8',
}

/**
 * Border radius scale
 */
export const borderRadius = {
  none: 'rounded-none',
  sm: 'rounded-sm',
  base: 'rounded',
  md: 'rounded-md',
  lg: 'rounded-lg',
  xl: 'rounded-xl',
  full: 'rounded-full',
}

/**
 * Shadow scale
 */
export const shadows = {
  none: 'shadow-none',
  sm: 'shadow-sm',
  base: 'shadow',
  md: 'shadow-md',
  lg: 'shadow-lg',
  xl: 'shadow-xl',
  '2xl': 'shadow-2xl',
}

/**
 * Typography scale
 */
export const typography = {
  fontSize: {
    xs: 'text-xs',
    sm: 'text-sm',
    base: 'text-base',
    lg: 'text-lg',
    xl: 'text-xl',
    '2xl': 'text-2xl',
  },
  fontWeight: {
    normal: 'font-normal',
    medium: 'font-medium',
    semibold: 'font-semibold',
    bold: 'font-bold',
  },
}

/**
 * Transition/Animation tokens
 */
export const transitions = {
  base: 'transition-colors',
  all: 'transition-all',
  fast: 'duration-150',
  normal: 'duration-200',
  slow: 'duration-300',
}

/**
 * Focus ring styles (for accessibility)
 */
export const focusRing = {
  default: 'focus:outline-none focus:ring-2 focus:ring-offset-2',
  primary: 'focus:ring-blue-500',
  error: 'focus:ring-red-500',
  success: 'focus:ring-green-500',
}

/**
 * Composed token sets for common UI elements
 */
export const tokens = {
  /**
   * Input field styles
   */
  input: {
    base: 'mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed',
    error: 'border-red-500 focus:border-red-500 focus:ring-red-500',
    success: 'border-green-500 focus:border-green-500 focus:ring-green-500',
  },

  /**
   * Select dropdown styles
   */
  select: {
    base: 'mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed',
    error: 'border-red-500 focus:border-red-500 focus:ring-red-500',
  },

  /**
   * Textarea styles
   */
  textarea: {
    base: 'mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed resize-y',
  },

  /**
   * Checkbox styles
   */
  checkbox: {
    base: 'h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500',
  },

  /**
   * Radio button styles
   */
  radio: {
    base: 'h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500',
  },

  /**
   * Label styles
   */
  label: {
    base: 'block text-sm font-medium text-gray-700',
    required: 'text-red-500',
  },

  /**
   * Helper text / description styles
   */
  helperText: {
    base: 'mt-1 text-xs text-gray-500',
    error: 'mt-1 text-xs text-red-600',
  },

  /**
   * Button styles (variants)
   */
  button: {
    base: 'inline-flex items-center justify-center rounded-md font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
    primary: 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500',
    secondary: 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 focus:ring-gray-500',
    danger: 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
    ghost: 'bg-transparent text-gray-600 hover:bg-gray-100 focus:ring-gray-500',
    sizes: {
      sm: 'px-3 py-1.5 text-sm',
      md: 'px-4 py-2 text-sm',
      lg: 'px-6 py-3 text-base',
    },
  },

  /**
   * Modal/Dialog styles
   */
  modal: {
    backdrop: 'fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50',
    container: 'relative mx-4 w-full max-w-lg rounded-xl bg-white p-6 shadow-xl',
    header: 'mb-6 flex items-center justify-between',
    title: 'text-xl font-semibold text-gray-900',
    closeButton: 'rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600',
    footer: 'mt-6 flex justify-end gap-3',
  },

  /**
   * Alert/notification styles
   */
  alert: {
    base: 'rounded-md p-3 text-sm',
    error: 'bg-red-50 text-red-700',
    success: 'bg-green-50 text-green-700',
    warning: 'bg-yellow-50 text-yellow-700',
    info: 'bg-blue-50 text-blue-700',
  },

  /**
   * Card styles
   */
  card: {
    base: 'rounded-lg border border-gray-200 bg-white p-6 shadow-sm',
    hover: 'hover:shadow-md transition-shadow',
    // Hover variants for interactive cards (selection UI, list rows).
    hoverPrimary: 'hover:border-blue-400 hover:bg-blue-50',
  },

  /**
   * Badge styles
   */
  badge: {
    base: 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
    gray: 'bg-gray-100 text-gray-800',
    blue: 'bg-blue-100 text-blue-800',
    green: 'bg-green-100 text-green-800',
    red: 'bg-red-100 text-red-800',
    yellow: 'bg-yellow-100 text-yellow-800',
    purple: 'bg-purple-100 text-purple-800',
  },

  /**
   * Status badge tokens for appointment lifecycle states.
   *
   * Each state maps to a distinct Tailwind palette chosen for semantic clarity
   * rather than brand consistency — status badges need more nuance than the
   * semantic palette (primary/success/error/warning) can express.
   */
  statusBadge: {
    base: 'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium',
    scheduled: 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-500/20',
    confirmed: 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-600/20',
    checkedIn: 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
    inProgress: 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-600/20',
    completed: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
    closed: 'bg-stone-100 text-stone-700 ring-1 ring-inset ring-stone-500/20',
    noShow: 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-600/20',
    cancelled: 'bg-zinc-100 text-zinc-600 ring-1 ring-inset ring-zinc-500/20',
  },

  /**
   * Utilization bar tokens — horizontal progress indicator for capacity load.
   *
   * - low    : 0-60%  — emerald (plenty of headroom)
   * - medium : 60-90% — amber   (healthy load)
   * - high   : >90%   — rose    (overbooked / crunched)
   * - track  : slate 100 — background rail
   */
  utilizationBar: {
    low: 'bg-emerald-500',
    medium: 'bg-amber-500',
    high: 'bg-rose-500',
    track: 'bg-slate-100',
  },

  /**
   * Segmented toggle button (e.g. day/week view switcher).
   * - `group`  : outer wrapper, contains the border + rounded shape.
   * - `active` : pressed/selected segment.
   * - `idle`   : unpressed segment.
   */
  toggleButton: {
    group: 'inline-flex overflow-hidden rounded-md border border-gray-300',
    active: 'bg-sky-600 text-white',
    idle: 'bg-white text-gray-700',
  },
}

/**
 * Export individual token categories for granular imports
 */
export default tokens
