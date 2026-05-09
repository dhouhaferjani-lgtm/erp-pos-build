/**
 * T2.1 Step B — BarcodeChooserModal component test.
 *
 * Covers B.10 of the kickoff (modal mount + pick callback + dismiss),
 * scoped to the modal in isolation rather than a full HomePage harness
 * mount per the Codex round-1 B1 finding's "extract the resolver into
 * a smaller testable helper" allowance.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { I18nextProvider } from 'react-i18next';
import i18n from '@/lib/i18n';
import { BarcodeChooserModal } from '../BarcodeChooserModal';
import type { POSProduct } from '@/types/product';

function makeProduct(over: Partial<POSProduct>): POSProduct {
  return {
    id: 'p-default',
    name: 'Default product',
    sku: 'DEFAULT-SKU',
    barcode: '0000000000',
    sale_price: '10.00',
    stock_quantity: 100,
    category: 'Test',
    ...over,
  } as POSProduct;
}

function renderModal(overrides: Partial<React.ComponentProps<typeof BarcodeChooserModal>> = {}) {
  const props = {
    isOpen: true,
    scannedCode: '555',
    candidates: [
      makeProduct({ id: 'pa', name: 'Product A', barcode: '555', sku: 'A-1' }),
      makeProduct({ id: 'pb', name: 'Product B', barcode: '555', sku: 'B-1' }),
    ],
    onPick: vi.fn(),
    onDismiss: vi.fn(),
    ...overrides,
  };
  render(
    <I18nextProvider i18n={i18n}>
      <BarcodeChooserModal {...props} />
    </I18nextProvider>,
  );
  return props;
}

describe('BarcodeChooserModal — T2.1 Step B', () => {
  beforeEach(() => {
    cleanup();
  });

  it('B.10: mounts with candidate list and calls onPick when a row is selected', () => {
    const props = renderModal();

    // Both candidates present in the chooser list.
    const list = screen.getByTestId('barcode-chooser-list');
    expect(list).toBeInTheDocument();
    expect(screen.getByTestId('barcode-chooser-row-pa')).toBeInTheDocument();
    expect(screen.getByTestId('barcode-chooser-row-pb')).toBeInTheDocument();

    // Pick the second candidate.
    fireEvent.click(screen.getByTestId('barcode-chooser-row-pb'));
    expect(props.onPick).toHaveBeenCalledTimes(1);
    expect(props.onPick).toHaveBeenCalledWith(props.candidates[1]);
  });

  it('B.10b: calls onDismiss on cancel-button click', () => {
    const props = renderModal();
    // The footer cancel button has the cancel translation key.
    const cancelButtons = screen.getAllByRole('button', { name: /cancel|annuler/i });
    expect(cancelButtons.length).toBeGreaterThan(0);
    fireEvent.click(cancelButtons[cancelButtons.length - 1]!);
    expect(props.onDismiss).toHaveBeenCalledTimes(1);
  });

  it('B.10c: returns null when isOpen=false (does NOT mount)', () => {
    renderModal({ isOpen: false });
    expect(screen.queryByTestId('barcode-chooser-list')).not.toBeInTheDocument();
  });

  it('B.11: chooser-modal i18n keys resolve in en + fr', async () => {
    const KEYS = [
      'barcodeChooser.title',
      'barcodeChooser.subtitle',
      'barcodeChooser.cancel',
      'barcodeChooser.dismiss',
      'barcodeChooser.stock',
      'barcode.lookingUp',
    ];
    const originalLng = i18n.language;
    try {
      for (const lng of ['en', 'fr']) {
        await i18n.changeLanguage(lng);
        for (const key of KEYS) {
          const value = i18n.t(key, { ns: 'pos', code: '555', count: 5, name: 'X' });
          expect(typeof value).toBe('string');
          expect(value).not.toBe('');
          expect(value).not.toBe(key);
          expect(value).not.toMatch(/^barcodeChooser\./);
        }
      }
    } finally {
      await i18n.changeLanguage(originalLng);
    }
  });
});
