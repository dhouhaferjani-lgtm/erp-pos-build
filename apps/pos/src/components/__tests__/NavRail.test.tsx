import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { ShoppingCart, Users, Wallet } from 'lucide-react';
import { NavRail } from '../NavRail';
import frPos from '@/locales/fr/pos.json';

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

  it('renders distinct labels for the caisse and shift nav destinations (no duplicate "Caisse")', () => {
    // Regression guard for the fr/pos.json duplication where both nav.caisse
    // and nav.shift resolved to "Caisse", producing two identical rail
    // entries. Assert against the real locale strings (not component-local
    // fixtures) so a re-introduced duplication in the JSON fails this test.
    expect(frPos.nav.caisse).toBeTruthy();
    expect(frPos.nav.shift).toBeTruthy();
    expect(frPos.nav.shift).not.toBe(frPos.nav.caisse);

    const railItems = [
      { id: 'caisse', label: frPos.nav.caisse, icon: <ShoppingCart /> },
      { id: 'shift', label: frPos.nav.shift, icon: <Wallet /> },
    ] as const;
    const { getByText } = render(
      <NavRail
        items={railItems as never}
        active="caisse"
        onSelect={vi.fn()}
        theme="light"
        onToggleTheme={vi.fn()}
      />,
    );
    // Both resolved labels must actually appear, and as distinct nodes.
    expect(getByText(frPos.nav.caisse)).toBeInTheDocument();
    expect(getByText(frPos.nav.shift)).toBeInTheDocument();
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
