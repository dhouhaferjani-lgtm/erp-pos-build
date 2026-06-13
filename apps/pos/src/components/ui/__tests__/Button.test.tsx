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
    expect(btn.className).toContain('min-h-14');
  });
});
