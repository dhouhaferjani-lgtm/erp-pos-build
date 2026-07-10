import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ModifierComposerSheet, type ModifierComposerSheetProps } from '../ModifierComposerSheet';
import { makeProduct, makeModifierGroup, makeModifier } from '@/test/helpers';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts) return `${key}:${JSON.stringify(opts)}`;
      return key;
    },
  }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    decimals: 2,
    format: (amount: number | string) => {
      const num = typeof amount === 'string' ? parseFloat(amount) : amount;
      return `${num.toFixed(2)} EUR`;
    },
  }),
}));

const toppingsGroup = makeModifierGroup({
  id: 'grp-1',
  name: 'Toppings',
  selection_type: 'multiple',
  min_selections: 0,
  max_selections: 3,
  is_required: false,
  modifiers: [
    makeModifier({ id: 'mod-1', name: 'Extra Cheese', price_adjustment: '2.00', position: 1 }),
    makeModifier({ id: 'mod-2', name: 'Bacon', price_adjustment: '3.00', position: 2 }),
    makeModifier({ id: 'mod-3', name: 'Mushrooms', price_adjustment: '1.50', position: 3 }),
  ],
});

const sizeGroup = makeModifierGroup({
  id: 'grp-2',
  name: 'Size',
  selection_type: 'single',
  min_selections: 1,
  max_selections: 1,
  is_required: true,
  modifiers: [
    makeModifier({ id: 'mod-s', name: 'Small', price_adjustment: '0.00', position: 1 }),
    makeModifier({ id: 'mod-m', name: 'Medium', price_adjustment: '2.00', position: 2 }),
    makeModifier({ id: 'mod-l', name: 'Large', price_adjustment: '4.00', position: 3 }),
  ],
});

const productWithModifiers = makeProduct({
  id: 'burger-1',
  name: 'Classic Burger',
  sale_price: '10.00',
  modifier_groups: [toppingsGroup, sizeGroup],
});

function renderSheet(overrides: Partial<ModifierComposerSheetProps> = {}) {
  const defaults: ModifierComposerSheetProps = {
    product: productWithModifiers,
    onClose: vi.fn(),
    onConfirm: vi.fn(),
  };
  return render(<ModifierComposerSheet {...defaults} {...overrides} />);
}

describe('ModifierComposerSheet (pane-hosted composer — parity with the retired ModifierSelectionModal)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders as a non-modal region with its own header (title + close)', () => {
    const onClose = vi.fn();
    renderSheet({ onClose });
    const sheet = screen.getByTestId('modifier-composer-sheet');
    expect(sheet).toHaveAttribute('role', 'region');
    expect(sheet).not.toHaveAttribute('aria-modal');
    expect(sheet).toHaveClass('h-full');
    expect(sheet).toHaveClass('w-full');
    expect(sheet.className).not.toContain('fixed');
    expect(screen.getByText('modifiers.customize')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'modifiers.cancel' }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('renders product name and base price', () => {
    renderSheet();
    expect(screen.getByText('Classic Burger')).toBeInTheDocument();
    expect(screen.getAllByText(/10\.00 EUR/).length).toBeGreaterThanOrEqual(1);
  });

  it('shows all modifier groups and their options simultaneously', () => {
    renderSheet();
    expect(screen.getByText('Toppings')).toBeInTheDocument();
    expect(screen.getByText('Size')).toBeInTheDocument();
    expect(screen.getByText('Extra Cheese')).toBeInTheDocument();
    expect(screen.getByText('Bacon')).toBeInTheDocument();
    expect(screen.getByText('Mushrooms')).toBeInTheDocument();
    expect(screen.getByText('Small')).toBeInTheDocument();
    expect(screen.getByText('Medium')).toBeInTheDocument();
    expect(screen.getByText('Large')).toBeInTheDocument();
  });

  it('toggles modifier selection in multiple mode (total updates)', () => {
    renderSheet();
    fireEvent.click(screen.getByText('Extra Cheese'));
    expect(screen.getByText('12.00 EUR')).toBeInTheDocument();
    fireEvent.click(screen.getByText('Extra Cheese'));
    expect(screen.getByText('10.00 EUR')).toBeInTheDocument();
  });

  it('respects max selections in multiple mode', () => {
    const limitedGroup = makeModifierGroup({ ...toppingsGroup, max_selections: 1 });
    const product = makeProduct({ ...productWithModifiers, modifier_groups: [limitedGroup] });
    renderSheet({ product });
    fireEvent.click(screen.getByText('Extra Cheese'));
    fireEvent.click(screen.getByText('Bacon'));
    // max 1 → the Bacon click was ignored: total = 10 + 2 (cheese)
    expect(screen.getByText('12.00 EUR')).toBeInTheDocument();
  });

  it('disables confirm when a required group is not satisfied', () => {
    renderSheet();
    const addButton = screen.getByText('modifiers.addToCart');
    expect(addButton.closest('button')).toBeDisabled();
    expect(screen.getByText('modifiers.required')).toBeInTheDocument();
  });

  it('enables confirm when all required groups are satisfied', () => {
    renderSheet();
    fireEvent.click(screen.getByText('Small'));
    expect(screen.getByText('modifiers.addToCart').closest('button')).not.toBeDisabled();
  });

  it('calls onConfirm with the selected modifiers', () => {
    const onConfirm = vi.fn();
    renderSheet({ onConfirm });
    fireEvent.click(screen.getByText('Bacon'));
    fireEvent.click(screen.getByText('Medium'));
    fireEvent.click(screen.getByText('modifiers.addToCart'));
    expect(onConfirm).toHaveBeenCalledOnce();
    const selectedModifiers = onConfirm.mock.calls[0]![0];
    expect(selectedModifiers).toHaveLength(2);
    expect(selectedModifiers).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ modifier_id: 'mod-2', name: 'Bacon' }),
        expect.objectContaining({ modifier_id: 'mod-m', name: 'Medium' }),
      ]),
    );
  });

  it('shows price adjustments for modifiers', () => {
    renderSheet();
    expect(screen.getAllByText('+2.00 EUR').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('+3.00 EUR')).toBeInTheDocument();
    expect(screen.getByText('+1.50 EUR')).toBeInTheDocument();
  });

  it('resets selections to defaults when the product changes in place', () => {
    const { rerender } = renderSheet();
    fireEvent.click(screen.getByText('Extra Cheese'));
    expect(screen.getByText('12.00 EUR')).toBeInTheDocument();
    const otherProduct = makeProduct({
      id: 'pizza-1',
      name: 'Pizza',
      sale_price: '10.00',
      modifier_groups: [toppingsGroup, sizeGroup],
    });
    rerender(
      <ModifierComposerSheet product={otherProduct} onClose={vi.fn()} onConfirm={vi.fn()} />,
    );
    // Fresh defaults: no toppings selected, back to base price.
    expect(screen.getByText('10.00 EUR')).toBeInTheDocument();
  });
});
