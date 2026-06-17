import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { VariantPickerModal } from '../VariantPickerModal';
import { makeProduct } from '@/test/helpers';
import type { POSProductVariant } from '@/types/product';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      opts ? `${key}:${JSON.stringify(opts)}` : key,
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

const useProductVariantsMock = vi.fn();
vi.mock('@/hooks/useProductVariants', () => ({
  useProductVariants: (productId: string | null) => useProductVariantsMock(productId),
}));

function makeVariant(overrides: Partial<POSProductVariant> = {}): POSProductVariant {
  return {
    id: 'var-1',
    product_id: 'prod-1',
    variant_code: 'V1',
    sku: 'V1',
    barcode: null,
    name_suffix: ' — 39 / Black',
    is_default: false,
    is_active: true,
    display_order: 0,
    price_override: '14.50',
    image_url: null,
    stock_quantity: 7,
    ...overrides,
  };
}

describe('VariantPickerModal', () => {
  beforeEach(() => {
    useProductVariantsMock.mockReset();
  });

  it('renders nothing when no product is given', () => {
    useProductVariantsMock.mockReturnValue({ variants: [], isLoading: false, status: 'idle' });
    const { container } = render(
      <VariantPickerModal isOpen product={null} onClose={vi.fn()} onConfirm={vi.fn()} />,
    );
    expect(container.querySelector('[data-testid="modal"]')).toBeNull();
  });

  it('shows a loading state while fetching', () => {
    useProductVariantsMock.mockReturnValue({ variants: [], isLoading: true, status: 'loading' });
    render(
      <VariantPickerModal
        isOpen
        product={makeProduct({ id: 'prod-1', name: 'Shoe', has_variants: true })}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText('variants.loading')).toBeTruthy();
  });

  it('shows an error state when the fetch fails', () => {
    useProductVariantsMock.mockReturnValue({ variants: [], isLoading: false, status: 'error' });
    render(
      <VariantPickerModal
        isOpen
        product={makeProduct({ id: 'prod-1', name: 'Shoe', has_variants: true })}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText('variants.loadError')).toBeTruthy();
  });

  it('shows an offline-no-cache state when offline with no local data', () => {
    useProductVariantsMock.mockReturnValue({ variants: [], isLoading: false, status: 'offline-empty' });
    render(
      <VariantPickerModal
        isOpen
        product={makeProduct({ id: 'prod-1', name: 'Shoe', has_variants: true })}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText('variants.offlineNoCache')).toBeTruthy();
  });

  it('confirms with the selected variant', () => {
    const onConfirm = vi.fn();
    useProductVariantsMock.mockReturnValue({
      variants: [makeVariant({ id: 'var-1', name_suffix: ' — 39', stock_quantity: 7 })],
      isLoading: false,
      status: 'local',
    });

    render(
      <VariantPickerModal
        isOpen
        product={makeProduct({ id: 'prod-1', name: 'Shoe', sale_price: '10.00', has_variants: true })}
        onClose={vi.fn()}
        onConfirm={onConfirm}
      />,
    );

    // Pick the variant row
    fireEvent.click(screen.getByRole('button', { name: /— 39/ }));
    // Confirm
    fireEvent.click(screen.getByRole('button', { name: 'variants.addToCart' }));

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onConfirm.mock.calls[0]![0].id).toBe('var-1');
  });

  it('disables the confirm button until a variant is selected', () => {
    useProductVariantsMock.mockReturnValue({
      variants: [makeVariant({ id: 'var-1', name_suffix: ' — 39', stock_quantity: 7 })],
      isLoading: false,
      status: 'local',
    });

    render(
      <VariantPickerModal
        isOpen
        product={makeProduct({ id: 'prod-1', name: 'Shoe', has_variants: true })}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    const confirm = screen.getByRole('button', { name: 'variants.addToCart' }) as HTMLButtonElement;
    expect(confirm.disabled).toBe(true);
  });
});
