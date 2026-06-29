import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { ShoppingCart, Users } from 'lucide-react';
import { NavRail } from '../NavRail';

const items = [
  { id: 'caisse', label: 'Caisse', icon: <ShoppingCart /> },
  { id: 'clients', label: 'Clients', icon: <Users /> },
] as const;

describe('NavRail', () => {
  it('marks the active destination with aria-current and fires onSelect', () => {
    const onSelect = vi.fn();
    const { getByRole } = render(
      <NavRail
        items={items as never}
        active="caisse"
        onSelect={onSelect}
        theme="light"
        onToggleTheme={vi.fn()}
      />,
    );
    expect(getByRole('button', { name: /Caisse/ }).getAttribute('aria-current')).toBe('page');
    fireEvent.click(getByRole('button', { name: /Clients/ }));
    expect(onSelect).toHaveBeenCalledWith('clients');
  });

  it('toggles theme', () => {
    const onToggleTheme = vi.fn();
    const { getByLabelText } = render(
      <NavRail
        items={items as never}
        active="caisse"
        onSelect={vi.fn()}
        theme="dark"
        onToggleTheme={onToggleTheme}
        themeToggleLabel="Thème"
      />,
    );
    fireEvent.click(getByLabelText('Thème'));
    expect(onToggleTheme).toHaveBeenCalledOnce();
  });
});
