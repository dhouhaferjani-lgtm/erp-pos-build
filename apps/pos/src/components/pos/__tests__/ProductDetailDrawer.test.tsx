import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ProductDetailDrawer } from '@/components/pos/ProductDetailDrawer';
import type { POSProduct } from '@/types/product';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({ t: (k: string) => k }),
}));
vi.mock('@/lib/currency', () => ({ useCurrency: () => ({ format: (n: string | number) => `${n}` }) }));

const product = { id: 'p1', name: 'Widget', sku: 'W1', sale_price: '9.99', stock_quantity: 999 } as POSProduct;

describe('ProductDetailDrawer own-location stock', () => {
  it('renders available from locationStock slice, not legacy stock_quantity', () => {
    render(<ProductDetailDrawer isOpen product={product} onClose={() => {}}
      locationStock={{ available: '8.0000', incoming_transfer: '2.0000', incoming_po: '0.0000' }} />);
    expect(screen.queryByText('999')).toBeNull();
    expect(screen.getByTestId('drawer-stock-row')).toHaveTextContent('8');
  });

  it('renders no stock chrome when slice is null (exempt)', () => {
    render(<ProductDetailDrawer isOpen product={product} onClose={() => {}} locationStock={null} />);
    expect(screen.queryByTestId('drawer-stock-row')).toBeNull();
  });
});
