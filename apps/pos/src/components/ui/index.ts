/**
 * Atomic UI layer for IziPOS.
 *
 * These are the canonical, reusable building blocks. Every button, badge,
 * status pill, and choose-one control in the app should be one of these so
 * that (a) elements of the same type look identical and (b) a change to a
 * token or an atom propagates everywhere automatically.
 *
 * See docs/design-language.md.
 */
export { Button } from './Button';
export type { ButtonProps, ButtonVariant, ButtonSize } from './Button';

export { IconButton } from './IconButton';
export type { IconButtonProps, IconButtonSize } from './IconButton';

export { Badge } from './Badge';
export type { BadgeProps, BadgeTone } from './Badge';

export { StatusPill } from './StatusPill';
export type { StatusPillProps, StatusTone } from './StatusPill';

export { SegmentedControl } from './SegmentedControl';
export type { SegmentedControlProps, SegmentedOption } from './SegmentedControl';
export { StockBadge } from './StockBadge';
export type { StockBadgeProps, StockStatus } from './StockBadge';
export { ProductThumb, initialsFromName, tintForCategory } from './ProductThumb';
export type { ProductThumbProps, CategoryTint } from './ProductThumb';
