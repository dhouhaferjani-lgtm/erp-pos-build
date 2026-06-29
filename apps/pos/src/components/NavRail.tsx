import type { ReactNode } from 'react';
import { Sun, Moon } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * NavRail — the 88px vertical destination rail (Caisse / Clients / Rapports /
 * Caisse-Shift). Sits on the side OPPOSITE the cart (cart left ⇒ rail right).
 * Active item uses the accent tint + accent fg (accent = "selected/highlight").
 * Theme sun/moon toggle pinned at the bottom. Presentational — AppShell wires
 * routing + theme.
 */
export interface NavRailItem<T extends string> {
  id: T;
  label: ReactNode;
  icon: ReactNode;
}

export interface NavRailProps<T extends string> {
  items: NavRailItem<T>[];
  active: T;
  onSelect: (id: T) => void;
  /** Brand mark at the top (logo/wordmark glyph). */
  brand?: ReactNode;
  theme: 'light' | 'dark';
  onToggleTheme: () => void;
  themeToggleLabel?: string;
  /** Accessible name for the nav landmark (pass a translated string). */
  ariaLabel?: string;
  className?: string;
}

export function NavRail<T extends string>({
  items,
  active,
  onSelect,
  brand,
  theme,
  onToggleTheme,
  themeToggleLabel = 'Thème',
  ariaLabel,
  className,
}: NavRailProps<T>) {
  return (
    <nav
      aria-label={ariaLabel}
      className={cn(
        'flex h-full w-[88px] shrink-0 flex-col items-center gap-1 border-r border-border-subtle bg-surface-raised py-3',
        className,
      )}
    >
      {brand && <div className="mb-2 flex h-10 w-10 items-center justify-center">{brand}</div>}

      <div className="flex flex-1 flex-col items-center gap-1">
        {items.map((item) => {
          const isActive = item.id === active;
          return (
            <button
              key={item.id}
              type="button"
              aria-current={isActive ? 'page' : undefined}
              onClick={() => onSelect(item.id)}
              className={cn(
                // 72px tall touch target, icon over label
                'flex h-[72px] w-[72px] flex-col items-center justify-center gap-1 rounded-ctl text-center transition-colors',
                isActive
                  ? 'bg-accent-tint text-accent-strong'
                  : 'text-ink-muted hover:bg-surface-sunken hover:text-ink',
              )}
            >
              <span className="flex h-6 w-6 items-center justify-center" aria-hidden>
                {item.icon}
              </span>
              <span className="text-xs font-medium leading-tight">{item.label}</span>
            </button>
          );
        })}
      </div>

      <button
        type="button"
        onClick={onToggleTheme}
        aria-label={themeToggleLabel}
        title={themeToggleLabel}
        className="flex h-12 w-12 items-center justify-center rounded-full text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
      >
        {theme === 'dark' ? <Sun className="h-5 w-5" /> : <Moon className="h-5 w-5" />}
      </button>
    </nav>
  );
}
