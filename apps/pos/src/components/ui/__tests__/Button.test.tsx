import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Button } from '../Button';

describe('Button atom', () => {
  it('renders its label and fires onClick', () => {
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Pay</Button>);
    const btn = screen.getByRole('button', { name: 'Pay' });
    fireEvent.click(btn);
    expect(onClick).toHaveBeenCalledOnce();
  });

  it('is disabled and does not fire onClick when loading', () => {
    const onClick = vi.fn();
    render(
      <Button loading onClick={onClick}>
        Pay
      </Button>,
    );
    const btn = screen.getByRole('button');
    expect(btn).toBeDisabled();
    expect(btn).toHaveAttribute('aria-busy', 'true');
    fireEvent.click(btn);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('communicates disabled via tokenized surface, not opacity', () => {
    render(<Button disabled>Pay</Button>);
    const btn = screen.getByRole('button');
    expect(btn).toBeDisabled();
    expect(btn.className).toContain('disabled:bg-surface-sunken');
    expect(btn.className).toContain('disabled:text-ink-faint');
  });

  it('applies the variant token classes', () => {
    render(
      <Button variant="confirm" size="lg">
        Charge
      </Button>,
    );
    const btn = screen.getByRole('button', { name: 'Charge' });
    expect(btn.className).toContain('bg-success');
    // lg size scale updated 40/48/64 (Task 3) — was min-h-14 (56px).
    expect(btn.className).toContain('min-h-[64px]');
  });

  it('truncate renders a REAL ellipsis: label in a min-w-0 truncate span, never a root-level truncate class', () => {
    const { getByRole } = render(
      <div className="flex min-w-0">
        <Button truncate>Rappeler la transaction</Button>
      </div>,
    );
    const btn = getByRole('button');
    // Still never wraps.
    expect(btn.className).toContain('whitespace-nowrap');
    // text-overflow: ellipsis does NOT apply to a flex container: putting
    // `truncate` on the button root produced a double-sided hard clip
    // (icon sliced left, label mid-glyph right, no ellipsis). The root must
    // NOT carry the `truncate` utility itself…
    expect(btn.classList.contains('truncate')).toBe(false);
    // …the LABEL span does: a shrinkable (min-w-0) flex item where
    // text-overflow:ellipsis actually works.
    const label = btn.querySelector('span.truncate');
    expect(label).not.toBeNull();
    expect(label).toHaveClass('min-w-0');
    expect(label).toHaveTextContent('Rappeler la transaction');
  });

  it('truncate keeps icon slots shrink-0 so only the label gives up width', () => {
    const { getByRole, getByTestId } = render(
      <Button
        truncate
        leftIcon={<svg data-testid="left-icon" />}
        rightIcon={<svg data-testid="right-icon" />}
      >
        Paiement en espèces
      </Button>,
    );
    const btn = getByRole('button', { name: 'Paiement en espèces' });
    // Accessible name is unaffected by the label span (span is transparent
    // to the accname computation).
    expect(btn).toBeInTheDocument();
    expect(getByTestId('left-icon').parentElement).toHaveClass('shrink-0');
    expect(getByTestId('right-icon').parentElement).toHaveClass('shrink-0');
  });

  it('without truncate, children render directly (no wrapper span imposed)', () => {
    const { getByRole } = render(<Button>Payer</Button>);
    const btn = getByRole('button', { name: 'Payer' });
    expect(btn.querySelector('span.truncate')).toBeNull();
  });

  it('size scale maps to ergonomic min-heights', () => {
    const { getByRole, rerender } = render(<Button size="md">A</Button>);
    expect(getByRole('button').className).toContain('min-h-[48px]');
    rerender(<Button size="lg">A</Button>);
    expect(getByRole('button').className).toContain('min-h-[64px]');
  });
});
