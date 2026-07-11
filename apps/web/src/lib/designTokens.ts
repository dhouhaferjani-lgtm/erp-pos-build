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
    red50: 'hover:bg-red-50',
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
  hoverBrand: 'hover:text-blue-800',
  hoverSecondary: 'hover:text-gray-600',
  hoverPrimary: 'hover:text-gray-900',
  hoverError: 'hover:text-red-700',
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
 * Canonical semantic color utility vocabulary for feature sweeps.
 *
 * Suffix ladder:
 * - backgrounds: Subtle = 50, Soft = 100, SoftStrong = 200,
 *   SoftStronger = 300, base = 500, Strong = 600.
 * - borders: Subtle = 200, base = 300, Active = 400,
 *   Focus = 500, Strong = 600.
 * - text: Subtle = 500, base = 600, Strong = 700,
 *   Stronger = 800, Strongest = 900.
 *
 * Extend this table when a sweep needs an exact class; do not substitute a
 * neighboring shade to fit the existing vocabulary.
 */
export const semanticColorTokens = {
  surface: {
    base: 'bg-white',
    baseOnFocus: 'focus:bg-white',
    page: 'bg-gray-50',
    pageAlpha: 'bg-gray-50/50',
    muted: 'bg-gray-100',
    mutedWhenDisabled: 'disabled:bg-gray-100',
    subdued: 'bg-gray-200',
    disabled: 'bg-gray-300',
    disabledWhenDisabled: 'disabled:bg-gray-300',
    disabledStrongWhenDisabled: 'disabled:bg-gray-400',
    neutral: 'bg-gray-500',
    neutralStrong: 'bg-gray-600',
    inverseMuted: 'bg-gray-700',
    inverse: 'bg-gray-800',
    inverseStrong: 'bg-gray-900',
    darkInverseStrong: 'dark:bg-gray-900',
    overlay: 'bg-black/50',
    overlaySubtle: 'bg-black/30',
  },
  text: {
    inverseFaint: 'text-gray-100',
    primary: 'text-gray-900',
    strong: 'text-gray-800',
    secondary: 'text-gray-700',
    muted: 'text-gray-600',
    subtle: 'text-gray-500',
    disabled: 'text-gray-400',
    faint: 'text-gray-300',
    inverse: 'text-white',
    darkDisabled: 'dark:text-gray-400',
    darkInverse: 'dark:text-white',
  },
  border: {
    hairline: 'border-gray-100',
    subtle: 'border-gray-200',
    default: 'border-gray-300',
    strong: 'border-gray-400',
    inverse: 'border-gray-600',
    inverseStrong: 'border-gray-700',
    divider: 'divide-gray-200',
    dividerSubtle: 'divide-gray-100',
    dividerStrong: 'divide-gray-300',
    hover: 'hover:border-gray-300',
    hoverStrong: 'hover:border-gray-400',
  },
  focus: {
    primaryBorder: 'focus:border-blue-500',
    primaryRing: 'focus:ring-blue-500',
    dangerBorder: 'focus:border-red-500',
    dangerRing: 'focus:ring-red-500',
    neutralRing: 'focus:ring-gray-500',
  },
  placeholder: {
    muted: 'placeholder-gray-400',
    textMuted: 'placeholder:text-gray-400',
  },
  intent: {
    primary: {
      bgSubtle: 'bg-blue-50',
      bgSubtleAlphaLight: 'bg-blue-50/30',
      bgSubtleAlpha: 'bg-blue-50/50',
      bgSubtleAlphaStrong: 'bg-blue-50/60',
      bgSoft: 'bg-blue-100',
      bgSoftStrong: 'bg-blue-200',
      bgSoftStronger: 'bg-blue-300',
      bg: 'bg-blue-500',
      bgStrong: 'bg-blue-600',
      bgHover: 'hover:bg-blue-50',
      bgHoverSubtleAlphaMuted: 'hover:bg-blue-50/30',
      bgHoverSubtleAlpha: 'hover:bg-blue-50/50',
      bgHoverSoft: 'hover:bg-blue-100',
      bgStrongHover: 'hover:bg-blue-700',
      groupBgHover: 'group-hover:bg-blue-100',
      borderSubtle: 'border-blue-200',
      border: 'border-blue-300',
      borderActive: 'border-blue-400',
      borderFocus: 'border-blue-500',
      borderStrong: 'border-blue-600',
      borderHoverSubtle: 'hover:border-blue-200',
      borderHover: 'hover:border-blue-300',
      ring: 'ring-blue-500',
      ringSoft: 'ring-blue-200',
      ringSubtle: 'ring-blue-600/20',
      accent: 'accent-blue-600',
      fileBgSubtle: 'file:bg-blue-50',
      fileBgSoft: 'file:bg-blue-100',
      fileBgHoverSoft: 'hover:file:bg-blue-100',
      fileText: 'file:text-blue-700',
      textFaint: 'text-blue-400',
      text: 'text-blue-600',
      textSubtle: 'text-blue-500',
      textStrong: 'text-blue-700',
      textStronger: 'text-blue-800',
      textStrongest: 'text-blue-900',
      textHoverSubtle: 'hover:text-blue-500',
      textHover: 'hover:text-blue-600',
      textHoverStrong: 'hover:text-blue-700',
      textHoverStronger: 'hover:text-blue-800',
      textHoverStrongest: 'hover:text-blue-900',
      groupTextHoverSubtle: 'group-hover:text-blue-500',
      groupTextHover: 'group-hover:text-blue-600',
    },
    success: {
      bgSubtle: 'bg-green-50',
      bgSubtleAlpha: 'bg-green-50/50',
      bgSoft: 'bg-green-100',
      bg: 'bg-green-500',
      bgStrong: 'bg-green-600',
      bgHover: 'hover:bg-green-50',
      bgStrongHover: 'hover:bg-green-700',
      borderSubtle: 'border-green-200',
      border: 'border-green-300',
      borderFocus: 'border-green-500',
      ring: 'ring-green-600/20',
      textFaint: 'text-green-400',
      textSubtle: 'text-green-500',
      text: 'text-green-600',
      textStrong: 'text-green-700',
      textStronger: 'text-green-800',
      textStrongest: 'text-green-900',
      textHover: 'hover:text-green-600',
      textHoverStrong: 'hover:text-green-800',
      textHoverStrongest: 'hover:text-green-900',
    },
    warning: {
      bgSubtle: 'bg-yellow-50',
      bgSubtleAlpha: 'bg-yellow-50/50',
      bgSoft: 'bg-yellow-100',
      bgSoftStrong: 'bg-yellow-200',
      bg: 'bg-yellow-500',
      bgStrong: 'bg-yellow-600',
      bgStronger: 'bg-yellow-700',
      borderSubtle: 'border-yellow-200',
      borderFocus: 'border-yellow-500',
      fillSubtle: 'fill-yellow-400',
      textFaint: 'text-yellow-400',
      text: 'text-yellow-600',
      textStrong: 'text-yellow-700',
      textStronger: 'text-yellow-800',
      textStrongest: 'text-yellow-900',
      textHoverStrong: 'hover:text-yellow-800',
    },
    caution: {
      bgSubtle: 'bg-amber-50',
      bgSubtleAlpha: 'bg-amber-50/50',
      bgSoft: 'bg-amber-100',
      bgSoftStronger: 'bg-amber-300',
      bg: 'bg-amber-500',
      bgStrong: 'bg-amber-600',
      bgStronger: 'bg-amber-700',
      bgHover: 'hover:bg-amber-50',
      border: 'border-amber-300',
      borderSubtle: 'border-amber-200',
      ring: 'ring-amber-600/20',
      textFaint: 'text-amber-100',
      textSubtle: 'text-amber-500',
      text: 'text-amber-600',
      textStrong: 'text-amber-700',
      textStronger: 'text-amber-800',
      textStrongest: 'text-amber-900',
      textHoverStrong: 'hover:text-amber-800',
    },
    danger: {
      bgSubtle: 'bg-red-50',
      bgSubtleAlpha: 'bg-red-50/50',
      bgSoft: 'bg-red-100',
      bg: 'bg-red-500',
      bgStrong: 'bg-red-600',
      bgHover: 'hover:bg-red-50',
      bgStrongHover: 'hover:bg-red-700',
      borderSubtle: 'border-red-200',
      border: 'border-red-300',
      borderFocus: 'border-red-500',
      borderStrong: 'border-red-600',
      ring: 'ring-red-500',
      textFaint: 'text-red-400',
      textSubtle: 'text-red-500',
      text: 'text-red-600',
      textStrong: 'text-red-700',
      textStronger: 'text-red-800',
      textStrongest: 'text-red-900',
      textHover: 'hover:text-red-600',
      textHoverStrong: 'hover:text-red-700',
      bgInverse: 'bg-red-900',
    },
    accent: {
      bgSubtle: 'bg-purple-50',
      bgSoft: 'bg-purple-100',
      bgSoftStrong: 'bg-purple-200',
      bg: 'bg-purple-500',
      bgStrong: 'bg-purple-600',
      bgStrongHover: 'hover:bg-purple-700',
      borderSubtle: 'border-purple-200',
      text: 'text-purple-600',
      textStrong: 'text-purple-700',
      textStronger: 'text-purple-800',
      textStrongest: 'text-purple-900',
      textHoverStrong: 'hover:text-purple-800',
      textHoverStrongest: 'hover:text-purple-900',
    },
    notice: {
      bgSubtle: 'bg-orange-50',
      bgSoft: 'bg-orange-100',
      bgSoftStrong: 'bg-orange-200',
      bgHover: 'hover:bg-orange-50',
      bgStrong: 'bg-orange-600',
      bgStrongHover: 'hover:bg-orange-700',
      borderSubtle: 'border-orange-200',
      borderStrong: 'border-orange-600',
      ring: 'ring-orange-600/20',
      text: 'text-orange-600',
      textStrong: 'text-orange-700',
      textStronger: 'text-orange-800',
      textStrongest: 'text-orange-900',
    },
    available: {
      bgSubtle: 'bg-emerald-50',
      bgSubtleAlpha: 'bg-emerald-50/50',
      bgSubtleAlphaStrong: 'bg-emerald-50/60',
      bgSoft: 'bg-emerald-100',
      borderSubtle: 'border-emerald-200',
      border: 'border-emerald-300',
      borderActive: 'border-emerald-400',
      borderFocus: 'border-emerald-500',
      ring: 'ring-emerald-600/20',
      ringFocus: 'ring-emerald-500',
      textFaint: 'text-emerald-400',
      textSubtle: 'text-emerald-500',
      text: 'text-emerald-600',
      textStrong: 'text-emerald-700',
      textStronger: 'text-emerald-800',
      textHoverStrong: 'hover:text-emerald-700',
    },
    info: {
      bgSubtle: 'bg-sky-50',
      bgSoft: 'bg-sky-100',
      text: 'text-sky-600',
      textStrong: 'text-sky-700',
    },
    ledger: {
      bgSubtle: 'bg-slate-50',
      bgInverse: 'bg-slate-900',
      textFaint: 'text-slate-300',
      textDisabled: 'text-slate-400',
      textMuted: 'text-slate-500',
      bgSoft: 'bg-slate-100',
      text: 'text-slate-600',
    },
    verified: {
      bgSoft: 'bg-indigo-100',
      textStrong: 'text-indigo-700',
      textStronger: 'text-indigo-800',
    },
    neutral: {
      bgSubtle: 'bg-gray-50',
      bgSoft: 'bg-gray-100',
      bg: 'bg-gray-500',
      bgStrong: 'bg-gray-600',
      bgHover: 'hover:bg-gray-50',
      bgHoverSoft: 'hover:bg-gray-100',
      bgHoverStrong: 'hover:bg-gray-200',
      bgStrongHover: 'hover:bg-gray-700',
      borderSubtle: 'border-gray-200',
      border: 'border-gray-300',
      ringSoft: 'ring-gray-200',
      ring: 'ring-gray-300',
      ringSubtle: 'ring-gray-500/10',
      textSubtle: 'text-gray-500',
      textStrong: 'text-gray-700',
      textHoverSubtle: 'hover:text-gray-500',
      textHover: 'hover:text-gray-600',
      textHoverStrong: 'hover:text-gray-700',
      darkTextHoverStrong: 'dark:hover:text-gray-200',
      textHoverStrongest: 'hover:text-gray-900',
      groupTextHover: 'group-hover:text-gray-500',
    },
    promotion: {
      bgSubtle: 'bg-pink-50',
      bgSoft: 'bg-pink-100',
      text: 'text-pink-600',
      textStronger: 'text-pink-800',
    },
    fashion: {
      bgSubtle: 'bg-violet-50',
      text: 'text-violet-600',
    },
    glass: {
      bgSubtle: 'bg-cyan-50',
      text: 'text-cyan-600',
    },
  },
  variants: {
    activeBgGray100: 'active:bg-gray-100',
    bgAmber300Alpha20: 'bg-amber-300/20',
    bgAmber400: 'bg-amber-400',
    bgBlackAlpha40: 'bg-black/40',
    bgBlue600Alpha20: 'bg-blue-600/20',
    bgGray500Alpha75: 'bg-gray-500/75',
    bgGray800Alpha50: 'bg-gray-800/50',
    bgGreen500Alpha20: 'bg-green-500/20',
    bgNeutral100: 'bg-neutral-100',
    bgNeutral200: 'bg-neutral-200',
    bgOrange500: 'bg-orange-500',
    bgPrimary500: 'bg-primary-500',
    bgWhiteAlpha95: 'bg-white/95',
    darkBgGray700: 'dark:bg-gray-700',
    darkBgGray700Alpha50: 'dark:bg-gray-700/50',
    darkBgGray800: 'dark:bg-gray-800',
    darkBgGray800Alpha50: 'dark:bg-gray-800/50',
    darkBgGray900: 'dark:bg-gray-900',
    darkBgBlue900Alpha20: 'dark:bg-blue-900/20',
    darkBgGreen900Alpha20: 'dark:bg-green-900/20',
    darkBgOrange900Alpha20: 'dark:bg-orange-900/20',
    darkBgRed900Alpha20: 'dark:bg-red-900/20',
    darkBgYellow900Alpha20: 'dark:bg-yellow-900/20',
    darkBorderGray600: 'dark:border-gray-600',
    darkBorderGray700: 'dark:border-gray-700',
    darkDivideGray700: 'dark:divide-gray-700',
    darkDisabledBgGray900: 'dark:disabled:bg-gray-900',
    darkHoverBgGray600: 'dark:hover:bg-gray-600',
    darkHoverBgGray700: 'dark:hover:bg-gray-700',
    darkHoverBgGray800Alpha50: 'dark:hover:bg-gray-800/50',
    darkHoverBgRed900Alpha20: 'dark:hover:bg-red-900/20',
    darkHoverTextGray300: 'dark:hover:text-gray-300',
    darkHoverTextBlue300: 'dark:hover:text-blue-300',
    darkHoverTextRed400: 'dark:hover:text-red-400',
    darkTextBlue100: 'dark:text-blue-100',
    darkTextBlue300: 'dark:text-blue-300',
    darkTextBlue400: 'dark:text-blue-400',
    darkTextGray100: 'dark:text-gray-100',
    darkTextGray300: 'dark:text-gray-300',
    darkTextGray400: 'dark:text-gray-400',
    darkTextGreen400: 'dark:text-green-400',
    darkTextOrange400: 'dark:text-orange-400',
    darkTextPrimary400: 'dark:text-primary-400',
    darkTextRed400: 'dark:text-red-400',
    darkTextYellow400: 'dark:text-yellow-400',
    disabledBgBlue400: 'disabled:bg-blue-400',
    disabledBgGray100: 'disabled:bg-gray-100',
    disabledBgGray300: 'disabled:bg-gray-300',
    disabledBgGray50: 'disabled:bg-gray-50',
    disabledBgRed400: 'disabled:bg-red-400',
    disabledTextGray500: 'disabled:text-gray-500',
    focusBorderBlue500: 'focus:border-blue-500',
    focusBorderEmerald500: 'focus:border-emerald-500',
    focusBorderOrange500: 'focus:border-orange-500',
    focusBorderRed500: 'focus:border-red-500',
    focusRingAmber500: 'focus:ring-amber-500',
    focusRingBlue500: 'focus:ring-blue-500',
    focusRingEmerald500: 'focus:ring-emerald-500',
    focusRingOrange500: 'focus:ring-orange-500',
    focusRingRed500: 'focus:ring-red-500',
    focusVisibleRingBlue500: 'focus-visible:ring-blue-500',
    groupHoverTextBlue400: 'group-hover:text-blue-400',
    hoverBgAmber100: 'hover:bg-amber-100',
    hoverBgAmber700: 'hover:bg-amber-700',
    hoverBgBlue50: 'hover:bg-blue-50',
    hoverBgBlue50Alpha50: 'hover:bg-blue-50/50',
    hoverBgBlue700: 'hover:bg-blue-700',
    hoverBgGray100: 'hover:bg-gray-100',
    hoverBgGray200: 'hover:bg-gray-200',
    hoverBgGray300: 'hover:bg-gray-300',
    hoverBgGray50: 'hover:bg-gray-50',
    hoverBgGray800: 'hover:bg-gray-800',
    hoverBgGreen50: 'hover:bg-green-50',
    hoverBgGreen700: 'hover:bg-green-700',
    hoverBgNeutral50: 'hover:bg-neutral-50',
    hoverBgNeutral100: 'hover:bg-neutral-100',
    hoverBgOrange50: 'hover:bg-orange-50',
    hoverBgRed50: 'hover:bg-red-50',
    hoverBgRed100: 'hover:bg-red-100',
    hoverBgRed700: 'hover:bg-red-700',
    hoverBgYellow50: 'hover:bg-yellow-50',
    hoverBgYellow700: 'hover:bg-yellow-700',
    hoverBorderBlue300: 'hover:border-blue-300',
    hoverBorderBlue500: 'hover:border-blue-500',
    hoverBorderGray300: 'hover:border-gray-300',
    hoverBorderGray400: 'hover:border-gray-400',
    hoverTextAmber800: 'hover:text-amber-800',
    hoverTextAmber900: 'hover:text-amber-900',
    hoverTextBlue600: 'hover:text-blue-600',
    hoverTextBlue800: 'hover:text-blue-800',
    hoverTextBlue900: 'hover:text-blue-900',
    hoverTextEmerald600: 'hover:text-emerald-600',
    hoverTextGray600: 'hover:text-gray-600',
    hoverTextGray700: 'hover:text-gray-700',
    hoverTextGray800: 'hover:text-gray-800',
    hoverTextGray900: 'hover:text-gray-900',
    hoverTextGray200: 'hover:text-gray-200',
    hoverTextNeutral600: 'hover:text-neutral-600',
    hoverTextPrimary500: 'hover:text-primary-500',
    hoverTextRed500: 'hover:text-red-500',
    hoverTextRed600: 'hover:text-red-600',
    hoverTextRed700: 'hover:text-red-700',
    hoverTextRed800: 'hover:text-red-800',
    hoverTextWhite: 'hover:text-white',
    placeholderTextEmerald400: 'placeholder:text-emerald-400',
    placeholderTextGray400: 'placeholder:text-gray-400',
    borderGray800: 'border-gray-800',
    borderNeutral200: 'border-neutral-200',
    borderOrange300: 'border-orange-300',
    borderWhite: 'border-white',
    ringBlack: 'ring-black',
    ringBlue600: 'ring-blue-600',
    textBlue300: 'text-blue-300',
    textGray200: 'text-gray-200',
    textNeutral400: 'text-neutral-400',
    textNeutral500: 'text-neutral-500',
    textNeutral600: 'text-neutral-600',
    textNeutral900: 'text-neutral-900',
    textPrimary400: 'text-primary-400',
    textPrimary500: 'text-primary-500',
    textPrimary600: 'text-primary-600',
    bgSecondary50: 'bg-secondary-50',
    textSecondary500: 'text-secondary-500',
    textSecondary700: 'text-secondary-700',
    textYellow500: 'text-yellow-500',
  },
} as const

/**
 * @deprecated Pixel-preservation quarantine for the Wave 5 documents/admin
 * sweep. Do not add entries and do not import outside those directories; burn
 * this table down by replacing each alias with semantic tokens/components.
 */
export const colorClasses = {
  bgAmber100: 'bg-amber-100',
  bgAmber50: 'bg-amber-50',
  bgBlue100: 'bg-blue-100',
  bgBlue50: 'bg-blue-50',
  bgBlue500: 'bg-blue-500',
  bgBlue600: 'bg-blue-600',
  bgGray100: 'bg-gray-100',
  bgGray200: 'bg-gray-200',
  bgGray50: 'bg-gray-50',
  bgGray600: 'bg-gray-600',
  bgGray800: 'bg-gray-800',
  bgGray900: 'bg-gray-900',
  bgGreen100: 'bg-green-100',
  bgGreen50: 'bg-green-50',
  bgGreen500: 'bg-green-500',
  bgGreen600: 'bg-green-600',
  bgIndigo600: 'bg-indigo-600',
  bgOrange100: 'bg-orange-100',
  bgOrange50: 'bg-orange-50',
  bgOrange600: 'bg-orange-600',
  bgPurple100: 'bg-purple-100',
  bgPurple50: 'bg-purple-50',
  bgPurple600: 'bg-purple-600',
  bgRed100: 'bg-red-100',
  bgRed50: 'bg-red-50',
  bgRed500: 'bg-red-500',
  bgRed600: 'bg-red-600',
  bgRed90050: 'bg-red-900/50',
  bgTeal600: 'bg-teal-600',
  bgYellow100: 'bg-yellow-100',
  bgYellow50: 'bg-yellow-50',
  bgYellow500: 'bg-yellow-500',
  borderAmber200: 'border-amber-200',
  borderBlue200: 'border-blue-200',
  borderBlue500: 'border-blue-500',
  borderBlue600: 'border-blue-600',
  borderGray100: 'border-gray-100',
  borderGray200: 'border-gray-200',
  borderGray300: 'border-gray-300',
  borderGray600: 'border-gray-600',
  borderGray700: 'border-gray-700',
  borderGreen200: 'border-green-200',
  borderOrange200: 'border-orange-200',
  borderPurple200: 'border-purple-200',
  borderRed200: 'border-red-200',
  borderRed300: 'border-red-300',
  borderRed500: 'border-red-500',
  borderYellow200: 'border-yellow-200',
  disabledBgGray400: 'disabled:bg-gray-400',
  divideGray100: 'divide-gray-100',
  divideGray200: 'divide-gray-200',
  focusBorderBlue500: 'focus:border-blue-500',
  focusBorderRed500: 'focus:border-red-500',
  focusRingBlue500: 'focus:ring-blue-500',
  focusRingGray500: 'focus:ring-gray-500',
  focusRingRed500: 'focus:ring-red-500',
  hoverBgBlue50: 'hover:bg-blue-50',
  hoverBgBlue700: 'hover:bg-blue-700',
  hoverBgGray100: 'hover:bg-gray-100',
  hoverBgGray200: 'hover:bg-gray-200',
  hoverBgGray50: 'hover:bg-gray-50',
  hoverBgGray700: 'hover:bg-gray-700',
  hoverBgGreen100: 'hover:bg-green-100',
  hoverBgGreen700: 'hover:bg-green-700',
  hoverBgIndigo700: 'hover:bg-indigo-700',
  hoverBgOrange700: 'hover:bg-orange-700',
  hoverBgPurple100: 'hover:bg-purple-100',
  hoverBgPurple700: 'hover:bg-purple-700',
  hoverBgRed100: 'hover:bg-red-100',
  hoverBgRed50: 'hover:bg-red-50',
  hoverBgRed700: 'hover:bg-red-700',
  hoverBgTeal700: 'hover:bg-teal-700',
  hoverBorderGray300: 'hover:border-gray-300',
  hoverBorderGray400: 'hover:border-gray-400',
  hoverTextBlue700: 'hover:text-blue-700',
  hoverTextBlue800: 'hover:text-blue-800',
  hoverTextBlue900: 'hover:text-blue-900',
  hoverTextGray300: 'hover:text-gray-300',
  hoverTextGray600: 'hover:text-gray-600',
  hoverTextGray700: 'hover:text-gray-700',
  hoverTextGray900: 'hover:text-gray-900',
  hoverTextGreen800: 'hover:text-green-800',
  hoverTextGreen900: 'hover:text-green-900',
  hoverTextIndigo900: 'hover:text-indigo-900',
  hoverTextRed600: 'hover:text-red-600',
  hoverTextRed700: 'hover:text-red-700',
  hoverTextRed800: 'hover:text-red-800',
  hoverTextRed900: 'hover:text-red-900',
  hoverTextYellow800: 'hover:text-yellow-800',
  placeholderGray400: 'placeholder-gray-400',
  ringBlue500: 'ring-blue-500',
  ringGray300: 'ring-gray-300',
  textAmber600: 'text-amber-600',
  textAmber700: 'text-amber-700',
  textAmber800: 'text-amber-800',
  textAmber900: 'text-amber-900',
  textBlue500: 'text-blue-500',
  textBlue600: 'text-blue-600',
  textBlue700: 'text-blue-700',
  textBlue800: 'text-blue-800',
  textBlue900: 'text-blue-900',
  textGray300: 'text-gray-300',
  textGray400: 'text-gray-400',
  textGray500: 'text-gray-500',
  textGray600: 'text-gray-600',
  textGray700: 'text-gray-700',
  textGray800: 'text-gray-800',
  textGray900: 'text-gray-900',
  textGreen400: 'text-green-400',
  textGreen500: 'text-green-500',
  textGreen600: 'text-green-600',
  textGreen700: 'text-green-700',
  textGreen800: 'text-green-800',
  textIndigo600: 'text-indigo-600',
  textOrange600: 'text-orange-600',
  textOrange700: 'text-orange-700',
  textOrange800: 'text-orange-800',
  textPurple600: 'text-purple-600',
  textPurple700: 'text-purple-700',
  textPurple800: 'text-purple-800',
  textRed200: 'text-red-200',
  textRed400: 'text-red-400',
  textRed500: 'text-red-500',
  textRed600: 'text-red-600',
  textRed700: 'text-red-700',
  textRed800: 'text-red-800',
  textYellow600: 'text-yellow-600',
  textYellow700: 'text-yellow-700',
  textYellow800: 'text-yellow-800',
} as const

/**
 * Chart colors for ECharts and other canvas/SVG renderers.
 *
 * ECharts can't consume Tailwind/CSS-variable tokens directly, so it needs
 * resolved color strings. Rather than hardcode hex here (which would drift from
 * the theme and ignore the per-vertical `[data-product]` switch), these are
 * resolved AT RUNTIME from the `--chart-*` CSS custom properties defined in
 * `src/index.css`. That keeps the theme the single source of truth: re-theming
 * or switching vertical updates the charts automatically, with zero hardcoded
 * hex in app code.
 *
 * `chartColors.primary` reads `--chart-primary` from the document root on each
 * access. In non-DOM contexts (SSR/jsdom without the stylesheet) it returns ''
 * (ECharts falls back to its own default) — tests inject the vars explicitly.
 *
 * Guarded by `src/lib/chartColors.theme.test.ts`.
 */
export type ChartColorKey =
  | 'primary'
  | 'success'
  | 'warning'
  | 'danger'
  | 'neutral'
  | 'secondary'
  | 'cyan'
  | 'violet'

/** Read a `--chart-*` custom property off the document root. Empty string when no DOM. */
export function readChartColor(key: ChartColorKey): string {
  if (typeof document === 'undefined') return ''
  return getComputedStyle(document.documentElement)
    .getPropertyValue(`--chart-${key}`)
    .trim()
}

/**
 * Live, theme-derived chart palette. Each getter resolves the matching
 * `--chart-*` CSS variable at access time, so the palette always reflects the
 * active theme/vertical. Keys also serve as a categorical sequence for
 * multi-series charts (see `chartCategoricalKeys`).
 */
export const chartColors: Record<ChartColorKey, string> = {
  get primary() {
    return readChartColor('primary')
  },
  get success() {
    return readChartColor('success')
  },
  get warning() {
    return readChartColor('warning')
  },
  get danger() {
    return readChartColor('danger')
  },
  get neutral() {
    return readChartColor('neutral')
  },
  get secondary() {
    return readChartColor('secondary')
  },
  get cyan() {
    return readChartColor('cyan')
  },
  get violet() {
    return readChartColor('violet')
  },
}

/** Ordered categorical sequence for multi-series charts (pies/donuts). */
export const chartCategoricalKeys: readonly ChartColorKey[] = [
  'primary',
  'success',
  'warning',
  'cyan',
  'violet',
  'neutral',
]

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
    base: 'mt-1 block w-full rounded-[var(--radius-input)] border border-gray-300 px-3 py-2 shadow-[var(--elevation-input)] focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed',
    error: 'border-red-500 focus:border-red-500 focus:ring-red-500',
    success: 'border-green-500 focus:border-green-500 focus:ring-green-500',
  },

  /**
   * Select dropdown styles
   */
  select: {
    base: 'mt-1 block w-full rounded-[var(--radius-input)] border border-gray-300 px-3 py-2 shadow-[var(--elevation-input)] focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed',
    error: 'border-red-500 focus:border-red-500 focus:ring-red-500',
  },

  /**
   * Textarea styles
   */
  textarea: {
    base: 'mt-1 block w-full rounded-[var(--radius-input)] border border-gray-300 px-3 py-2 shadow-[var(--elevation-input)] focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed resize-y',
    error: 'border-red-500 focus:border-red-500 focus:ring-red-500',
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
   * Toggle (switch) styles
   *
   * Track: 44×25px, fully rounded. Uses CSS variables bridged from the active
   * theme so that IziPOS (copper) and Otospex (pink) each render correctly.
   * Falls back to green-600/gray-300 when no theme variable is present.
   *
   * Usage:
   * ```tsx
   * import { tokens } from '@/lib/designTokens'
   * // track
   * <span className={cn(tokens.toggle.track, checked && tokens.toggle.trackOn, !checked && tokens.toggle.trackOff)} />
   * // knob
   * <span className={tokens.toggle.knob} />
   * ```
   */
  toggle: {
    /** Outer track — always applied */
    track: 'relative inline-flex h-[25px] w-[44px] shrink-0 cursor-pointer items-center rounded-full transition-colors duration-200 ease-in-out focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-[var(--color-primary,theme(colors.green.600))]',
    /** Applied when checked=true */
    trackOn: 'bg-[var(--color-success,theme(colors.green.600))]',
    /** Applied when checked=false */
    trackOff: 'bg-[var(--color-neutral-300,theme(colors.gray.300))]',
    /** Applied when disabled */
    trackDisabled: 'opacity-50 cursor-not-allowed',
    /** Knob (white circle) */
    knob: 'pointer-events-none inline-block h-[19px] w-[19px] translate-x-[3px] rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 ease-in-out',
    /** Knob shifted right when checked */
    knobOn: 'translate-x-[22px]',
  },

  /**
   * Label styles
   */
  label: {
    base: 'block text-sm font-medium text-gray-700',
    required: 'text-red-500',
  },

  /**
   * Section heading styles.
   *
   * The ONE sanctioned treatment for in-card `<h2>`/`<h3>` section titles, so
   * forms stop varying between `font-bold`/`font-semibold`/`font-medium`
   * (see CANONICALIZATION-SPEC §"Headers"). Only the theme-bridged `gray`
   * palette is used, so no new off-theme color literals are introduced.
   */
  heading: {
    section: 'text-lg font-medium text-gray-900',
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
    base: 'inline-flex items-center justify-center rounded-[var(--radius-button)] font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
    primary: 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500',
    secondary: 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 focus:ring-gray-500',
    danger: 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
    dangerOutline: 'bg-white text-red-700 border border-red-300 hover:bg-red-50 focus:ring-red-500',
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
    base: 'rounded-[var(--radius-card)] border border-gray-200 bg-white p-6 shadow-[var(--elevation-card)]',
    hover: 'hover:shadow-md transition-shadow',
    // Hover variants for interactive cards (selection UI, list rows).
    hoverPrimary: 'hover:border-blue-400 hover:bg-blue-50',
  },

  /**
   * Badge styles
   */
  badge: {
    base: 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
    // Quiet outline chip for identity/context labels (e.g. document type) that
    // must not compete with semantic status pills sitting next to them.
    outline: 'border border-gray-300 bg-white text-gray-600',
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

  /**
   * Data-table row/header tokens. Matches the header/stripe/hover
   * treatment used by list pages across features.
   */
  table: {
    header: 'bg-gray-50',
    rowHover: 'hover:bg-gray-50',
    cellMonoBadge: 'inline-flex rounded-md bg-gray-100 px-2 py-1 text-sm font-mono font-medium',
  },

  /**
   * Designation override indicator dot — shown when a line's description
   * has been manually overridden from its original product name snapshot.
   */
  designationOverride: {
    dot: 'inline-block h-2 w-2 rounded-full bg-amber-400 shrink-0',
  },

  productHero: {
    band: 'bg-gray-950 text-white',
    imageSlot: 'bg-gray-900',
    imageIcon: 'text-gray-500',
    chip: 'bg-white/10 text-white',
    mutedChip: 'bg-white/10 text-white/80',
  },
}

/**
 * Export individual token categories for granular imports
 */
export default tokens
