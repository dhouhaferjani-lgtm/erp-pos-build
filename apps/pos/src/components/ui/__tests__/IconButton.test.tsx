import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { IconButton } from '../IconButton';

describe('IconButton atom', () => {
  it('renders with its enforced accessible name and fires onClick', () => {
    const onClick = vi.fn();
    render(
      <IconButton
        aria-label="Autres paiements"
        icon={<svg data-testid="icon" />}
        onClick={onClick}
      />,
    );
    const btn = screen.getByRole('button', { name: 'Autres paiements' });
    fireEvent.click(btn);
    expect(onClick).toHaveBeenCalledOnce();
  });

  it('disabled state keeps a VISIBLE sunken surface — mirrors Button disabled recipe, never transparent', () => {
    // F2 (adversarial review): `disabled:bg-transparent` made a disabled
    // IconButton nearly invisible on the navy payment footer (borderless
    // transparent square + faint glyph) while the adjacent disabled Button
    // kept a visible bg-surface-sunken chip — asymmetric pair. The disabled
    // recipe must match Button's: sunken surface + faint ink.
    render(
      <IconButton
        aria-label="Autres paiements"
        icon={<svg data-testid="icon" />}
        disabled
      />,
    );
    const btn = screen.getByRole('button', { name: 'Autres paiements' });
    expect(btn).toBeDisabled();
    expect(btn.className).toContain('disabled:bg-surface-sunken');
    expect(btn.className).toContain('disabled:text-ink-faint');
    expect(btn.className).not.toContain('disabled:bg-transparent');
  });

  it('disabled blocks onClick', () => {
    const onClick = vi.fn();
    render(
      <IconButton
        aria-label="Sync"
        icon={<svg data-testid="icon" />}
        onClick={onClick}
        disabled
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Sync' }));
    expect(onClick).not.toHaveBeenCalled();
  });
});
