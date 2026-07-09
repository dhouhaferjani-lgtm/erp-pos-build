import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

import { QuickActions } from './QuickActions';

function renderQA(overrides: Partial<Parameters<typeof QuickActions>[0]> = {}) {
  return render(
    <QuickActions
      onDiscount={vi.fn()}
      onHold={vi.fn()}
      onRecall={vi.fn()}
      hasItems
      {...overrides}
    />,
  );
}

describe('QuickActions', () => {
  it('renders exactly the three sale quick actions (no Returns — that is a separate flow)', () => {
    renderQA();
    expect(screen.getByText('quickActions.discount')).toBeInTheDocument();
    expect(screen.getByText('quickActions.hold')).toBeInTheDocument();
    expect(screen.getByText('quickActions.recall')).toBeInTheDocument();
    // Returns is NOT a quick action (mock §5.1 = 3 actions; Returns lives in its own flow).
    expect(screen.queryByText('receiptLocator.entryButton')).toBeNull();
  });

  it('disables Discount and Hold when the cart is empty, keeps Recall available', () => {
    renderQA({ hasItems: false });
    expect(screen.getByText('quickActions.discount').closest('button')).toBeDisabled();
    expect(screen.getByText('quickActions.hold').closest('button')).toBeDisabled();
    expect(screen.getByText('quickActions.recall').closest('button')).not.toBeDisabled();
  });

  it('shows a parked-sale count badge on Recall when recallCount > 0', () => {
    renderQA({ recallCount: 3 });
    const recallBtn = screen.getByText('quickActions.recall').closest('button');
    expect(recallBtn).toHaveTextContent('3');
  });

  it('omits the count badge when there are no parked sales', () => {
    renderQA({ recallCount: 0 });
    const recallBtn = screen.getByText('quickActions.recall').closest('button');
    expect(recallBtn?.textContent).not.toMatch(/\d/);
  });

  it('fires the action callbacks', () => {
    const onRecall = vi.fn();
    renderQA({ onRecall });
    fireEvent.click(screen.getByText('quickActions.recall'));
    expect(onRecall).toHaveBeenCalledOnce();
  });

  it('lets each action button shrink and truncate instead of clipping (Task 8: "Rappeler" clip fix)', () => {
    renderQA();
    const buttons = screen.getAllByRole('button');
    expect(buttons).toHaveLength(3);
    buttons.forEach((button) => {
      // min-w-0 on the flex-1 button lets it shrink below its content size
      // within the row; truncate ellipsizes the label instead of the row
      // hard-clipping it via overflow.
      expect(button.className).toContain('min-w-0');
      expect(button.className).toContain('truncate');
    });
  });

  it('does not rely on the row hard-clipping labels via overflow-x-auto', () => {
    const { container } = renderQA();
    const row = container.firstChild as HTMLElement;
    expect(row.className).not.toContain('overflow-x-auto');
  });
});
