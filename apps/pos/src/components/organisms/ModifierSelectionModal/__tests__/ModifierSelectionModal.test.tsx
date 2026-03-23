import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ModifierSelectionModal, type ModifierSelectionModalProps } from '../ModifierSelectionModal';
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

vi.mock('@/components/pos/Modal', () => ({
  Modal: ({
    isOpen,
    children,
    title,
  }: {
    isOpen: boolean;
    children: React.ReactNode;
    title: string;
  }) =>
    isOpen ? (
      <div data-testid="modal">
        <h2>{title}</h2>
        {children}
      </div>
    ) : null,
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

function renderModal(overrides: Partial<ModifierSelectionModalProps> = {}) {
  const defaults: ModifierSelectionModalProps = {
    isOpen: true,
    onClose: vi.fn(),
    product: productWithModifiers,
    onConfirm: vi.fn(),
  };
  return render(<ModifierSelectionModal {...defaults} {...overrides} />);
}

describe('ModifierSelectionModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders nothing when closed', () => {
    renderModal({ isOpen: false });
    expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
  });

  it('renders nothing when product is null', () => {
    renderModal({ product: null });
    expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
  });

  it('renders product name and base price', () => {
    renderModal();
    expect(screen.getByText('Classic Burger')).toBeInTheDocument();
    // Base price appears in header and total display
    expect(screen.getAllByText(/10\.00 EUR/).length).toBeGreaterThanOrEqual(1);
  });

  it('shows all modifier groups simultaneously', () => {
    renderModal();
    expect(screen.getByText('Toppings')).toBeInTheDocument();
    expect(screen.getByText('Size')).toBeInTheDocument();
    // All modifiers from all groups visible
    expect(screen.getByText('Extra Cheese')).toBeInTheDocument();
    expect(screen.getByText('Small')).toBeInTheDocument();
  });

  it('shows modifier options for the active group', () => {
    renderModal();
    // First group (Toppings) is active by default
    expect(screen.getByText('Extra Cheese')).toBeInTheDocument();
    expect(screen.getByText('Bacon')).toBeInTheDocument();
    expect(screen.getByText('Mushrooms')).toBeInTheDocument();
  });

  it('toggles modifier selection in multiple mode', () => {
    renderModal();

    // Click Extra Cheese
    fireEvent.click(screen.getByText('Extra Cheese'));

    // Total should now be 10 + 2 = 12
    expect(screen.getByText('12.00 EUR')).toBeInTheDocument();

    // Click again to deselect
    fireEvent.click(screen.getByText('Extra Cheese'));

    // Total should be back to 10
    expect(screen.getByText('10.00 EUR')).toBeInTheDocument();
  });

  it('respects max selections in multiple mode', () => {
    const limitedGroup = makeModifierGroup({
      ...toppingsGroup,
      max_selections: 1,
    });
    const product = makeProduct({
      ...productWithModifiers,
      modifier_groups: [limitedGroup],
    });

    renderModal({ product });

    fireEvent.click(screen.getByText('Extra Cheese'));
    fireEvent.click(screen.getByText('Bacon'));

    // Only one should be selected (max 1)
    // Total should be base + one modifier
    // Since max is 1, the second click should not add
    // Total = 10 + 2 (cheese) = 12 (bacon click was ignored)
    expect(screen.getByText('12.00 EUR')).toBeInTheDocument();
  });

  it('shows all modifiers from all groups without navigation', () => {
    renderModal();
    // Toppings group
    expect(screen.getByText('Extra Cheese')).toBeInTheDocument();
    expect(screen.getByText('Bacon')).toBeInTheDocument();
    expect(screen.getByText('Mushrooms')).toBeInTheDocument();
    // Size group
    expect(screen.getByText('Small')).toBeInTheDocument();
    expect(screen.getByText('Medium')).toBeInTheDocument();
    expect(screen.getByText('Large')).toBeInTheDocument();
  });

  it('disables confirm when required group is not satisfied', () => {
    // Size group requires min_selections: 1
    renderModal();

    const addButton = screen.getByText('modifiers.addToCart');
    expect(addButton.closest('button')).toBeDisabled();
  });

  it('enables confirm when all required groups are satisfied', () => {
    renderModal();
    // Size group is required — select Small (visible without tab switching)
    fireEvent.click(screen.getByText('Small'));
    const addButton = screen.getByText('modifiers.addToCart');
    expect(addButton.closest('button')).not.toBeDisabled();
  });

  it('calls onConfirm with selected modifiers', () => {
    const onConfirm = vi.fn();
    renderModal({ onConfirm });

    // Select a topping (all groups visible)
    fireEvent.click(screen.getByText('Bacon'));
    // Select a size (all groups visible)
    fireEvent.click(screen.getByText('Medium'));

    // Confirm
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
    renderModal();
    // Extra Cheese (+2.00 EUR) and Medium (+2.00 EUR) both appear — all groups visible
    expect(screen.getAllByText('+2.00 EUR').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('+3.00 EUR')).toBeInTheDocument();
    expect(screen.getByText('+1.50 EUR')).toBeInTheDocument();
  });

  it('shows required indicator message when validation fails', () => {
    renderModal();
    expect(screen.getByText('modifiers.required')).toBeInTheDocument();
  });
});
