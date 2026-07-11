import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ProductPaneHost, deriveProductPaneView } from '@/components/pos/ProductPaneHost';
import type { POSProduct } from '@/types/product';

const productA = { id: 'p1', name: 'Widget', sku: 'W1', sale_price: '9.990', stock_quantity: 5 } as POSProduct;
const productB = { id: 'p2', name: 'Gadget', sku: 'G1', sale_price: '5.000', stock_quantity: 3 } as POSProduct;

function renderHost(
  overrides: {
    detailProduct?: POSProduct | null;
    modifierProduct?: POSProduct | null;
    onCloseDetail?: () => void;
  } = {},
) {
  return render(
    <ProductPaneHost
      detailProduct={overrides.detailProduct ?? null}
      modifierProduct={overrides.modifierProduct ?? null}
      onCloseDetail={overrides.onCloseDetail ?? vi.fn()}
      renderDetail={(p) => <div data-testid="detail-pane-content">{p.name}</div>}
      renderCustomize={(p) => <div data-testid="customize-pane-content">{p.name}</div>}
    >
      <div data-testid="grid-content">grid</div>
    </ProductPaneHost>,
  );
}

describe('deriveProductPaneView', () => {
  it('is grid when both products are null', () => {
    expect(deriveProductPaneView(null, null)).toBe('grid');
  });
  it('is detail when only detailProduct is set', () => {
    expect(deriveProductPaneView(productA, null)).toBe('detail');
  });
  it('is customize when modifierProduct is set — and customize wins if both are set (defensive)', () => {
    expect(deriveProductPaneView(null, productB)).toBe('customize');
    expect(deriveProductPaneView(productA, productB)).toBe('customize');
  });
});

describe('ProductPaneHost', () => {
  it('shows the grid (flex, not hidden) when no pane product is set', () => {
    renderHost();
    expect(screen.getByTestId('grid-content')).toBeInTheDocument();
    expect(screen.getByTestId('product-pane-grid')).toHaveClass('flex');
    expect(screen.getByTestId('product-pane-grid')).not.toHaveClass('hidden');
    expect(screen.queryByTestId('product-pane-detail')).toBeNull();
    expect(screen.queryByTestId('product-pane-customize')).toBeNull();
  });

  it('keeps the grid MOUNTED but hidden while the detail pane is active (spec §1 grid preservation)', () => {
    renderHost({ detailProduct: productA });
    expect(screen.getByTestId('detail-pane-content')).toHaveTextContent('Widget');
    expect(screen.getByTestId('grid-content')).toBeInTheDocument(); // NOT unmounted
    expect(screen.getByTestId('product-pane-grid')).toHaveClass('hidden');
  });

  it('renders the customize pane from modifierProduct', () => {
    renderHost({ modifierProduct: productB });
    expect(screen.getByTestId('customize-pane-content')).toHaveTextContent('Gadget');
    expect(screen.queryByTestId('product-pane-detail')).toBeNull();
    expect(screen.getByTestId('product-pane-grid')).toHaveClass('hidden');
  });

  it('mounts NO fixed/inset overlay or modal semantics from the pane path (structural invariant, spec §5)', () => {
    const { container } = renderHost({ detailProduct: productA });
    expect(container.querySelector('.fixed')).toBeNull();
    expect(container.querySelector('[class*="inset-0"]')).toBeNull();
    expect(container.querySelector('[aria-modal]')).toBeNull();
  });

  it('Escape closes the detail pane', () => {
    const onCloseDetail = vi.fn();
    renderHost({ detailProduct: productA, onCloseDetail });
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCloseDetail).toHaveBeenCalledTimes(1);
  });

  it('Escape does NOT close the detail pane while a modal dialog is above it (Rev 2, U1)', () => {
    const onCloseDetail = vi.fn();
    renderHost({ detailProduct: productA, onCloseDetail });

    // Stub a stacked dialog (variant picker, held, customer search…) — every
    // Modal binds its own window Esc listener (Modal.tsx:31-39); that press
    // belongs to the dialog, not the pane.
    const dialogStub = document.createElement('div');
    dialogStub.setAttribute('role', 'dialog');
    dialogStub.setAttribute('aria-modal', 'true');
    document.body.appendChild(dialogStub);

    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCloseDetail).not.toHaveBeenCalled();

    dialogStub.remove();
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCloseDetail).toHaveBeenCalledTimes(1);
  });

  it('Escape does NOT close the customize pane (explicit confirm/cancel only, spec §4)', () => {
    const onCloseDetail = vi.fn();
    renderHost({ modifierProduct: productB, onCloseDetail });
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCloseDetail).not.toHaveBeenCalled();
  });

  it('Escape is inert in grid view', () => {
    const onCloseDetail = vi.fn();
    renderHost({ onCloseDetail });
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCloseDetail).not.toHaveBeenCalled();
  });
});
