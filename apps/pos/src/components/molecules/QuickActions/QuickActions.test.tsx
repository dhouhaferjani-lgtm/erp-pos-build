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

  it('lets each action button shrink, and ellipsizes only the label span, instead of clipping (Task 8 review fix: truncate must target the label, not the flex row)', () => {
    renderQA();
    const buttons = screen.getAllByRole('button');
    expect(buttons).toHaveLength(3);
    buttons.forEach((button) => {
      // min-w-0 on the flex-1 button lets it shrink below its content size
      // within the row.
      expect(button.className).toContain('min-w-0');

      // The ellipsis must live on a dedicated label span, NOT on the button
      // itself: text-overflow:ellipsis on a flex container with element
      // children (icon, label, count badge) is unreliable — it can hard-clip
      // with no ellipsis, or shrink the icon/badge instead of the label.
      const label = button.querySelector('span.truncate');
      expect(label).not.toBeNull();
      expect(label).toHaveClass('min-w-0');
      expect(label).toHaveClass('truncate');

      // The icon must never compete for space with the label — it must be
      // shrink-0 so only the label gives up room.
      const icon = button.querySelector('svg');
      expect(icon).not.toBeNull();
      expect(icon).toHaveClass('shrink-0');
    });

    // The count badge (Recall, when recallCount > 0) must also be shrink-0.
    const recallBtn = screen.getByText('quickActions.recall').closest('button');
    const recallLabel = recallBtn?.querySelector('span.truncate');
    expect(recallLabel?.textContent).toBe('quickActions.recall');
  });

  it('makes the count badge shrink-0 so it never competes with the label for space', () => {
    renderQA({ recallCount: 3 });
    const recallBtn = screen.getByText('quickActions.recall').closest('button') as HTMLElement;
    const badge = recallBtn.querySelector('span.tabular-nums');
    expect(badge).not.toBeNull();
    expect(badge).toHaveClass('shrink-0');
  });

  it('does not rely on the row hard-clipping labels via overflow-x-auto', () => {
    const { container } = renderQA();
    const row = container.firstChild as HTMLElement;
    expect(row.className).not.toContain('overflow-x-auto');
  });
});
